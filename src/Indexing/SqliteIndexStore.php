<?php

namespace LogLens\Indexing;

use LogLens\Contracts\IndexStore;
use LogLens\Support\FileIdentity;
use PDO;

/**
 * Primary SQLite sidecar index.
 *
 * One DB file per log file. WAL + synchronous=NORMAL + batched transactions.
 * FTS5/trigram are feature-detected once at creation and recorded in meta so
 * the search ladder knows the available tier. ~51 B/entry.
 */
class SqliteIndexStore implements IndexStore
{
    public const SCHEMA_VERSION = 1;

    private PDO $pdo;

    private string $path;

    private bool $inBatch = false;

    private ?\PDOStatement $insertEntry = null;

    private ?\PDOStatement $insertFts = null;

    private array $caps = [];

    private bool $hasFts = false;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    private string $directory;

    public function open(FileIdentity $identity): void
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $this->path = $this->directory . DIRECTORY_SEPARATOR . $identity->key() . '.lidx';

        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA temp_store=MEMORY');
        $this->pdo->exec('PRAGMA mmap_size=268435456');
        $this->pdo->exec('PRAGMA cache_size=-32000'); // 32 MB page cache (faster bulk FTS)
        // WAL permits a single writer; without a busy_timeout a second connection
        // (a concurrent indexing slice, a queued job, or a checkpoint racing a
        // read) fails immediately with "database is locked". Wait briefly instead.
        $this->pdo->exec('PRAGMA busy_timeout=5000');

        $this->migrate();
        $this->detectCapabilities();

        $this->setMeta('identity', $identity->toArray());
        $this->setMeta('schema_version', self::SCHEMA_VERSION);
        $this->touch();
    }

    public function path(): string
    {
        return $this->path;
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS entries (
            seq INTEGER PRIMARY KEY,
            offset INTEGER NOT NULL,
            length INTEGER NOT NULL,
            ts INTEGER,
            level INTEGER NOT NULL DEFAULT 1,
            fp_app INTEGER,
            fp_sys INTEGER,
            title TEXT,
            deleted INTEGER NOT NULL DEFAULT 0
        )');
        // Existing indexes predate the soft-delete column — backfill it.
        $cols = $this->pdo->query('PRAGMA table_info(entries)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (! in_array('deleted', $cols, true)) {
            $this->pdo->exec('ALTER TABLE entries ADD COLUMN deleted INTEGER NOT NULL DEFAULT 0');
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_entries_ts ON entries(ts)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_entries_level ON entries(level)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_entries_fp ON entries(fp_app)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_entries_deleted ON entries(deleted)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS stats (
            bucket_hour INTEGER NOT NULL,
            level INTEGER NOT NULL,
            count INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket_hour, level)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS groups (
            fp INTEGER PRIMARY KEY,
            kind INTEGER NOT NULL DEFAULT 0,
            count INTEGER NOT NULL DEFAULT 0,
            first_ts INTEGER,
            last_ts INTEGER,
            sample_seq INTEGER,
            level INTEGER,
            title TEXT
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS meta (
            key TEXT PRIMARY KEY,
            value TEXT
        )');
    }

    private function detectCapabilities(): void
    {
        $sqliteVersion = $this->pdo->query('SELECT sqlite_version()')->fetchColumn();

        // The fts table is created exactly once and NEVER dropped on reopen —
        // dropping it here would wipe the populated index every time the search
        // engine reopens the store.
        if ($this->tableExists('fts')) {
            $caps = $this->getMeta('capabilities');
            if (is_array($caps)) {
                $this->caps = $caps;
                $this->hasFts = ! empty($caps['fts5']);

                return;
            }
        }

        $fts5 = false;
        $trigram = false;
        // Probe FTS5 + trigram on throwaway tables so detection never touches
        // the real fts table.
        try {
            $this->pdo->exec('CREATE VIRTUAL TABLE temp.fts5_probe USING fts5(x)');
            $this->pdo->exec('DROP TABLE temp.fts5_probe');
            $fts5 = true;
            try {
                $this->pdo->exec('CREATE VIRTUAL TABLE temp.fts5_trigram_probe USING fts5(x, tokenize=trigram)');
                $this->pdo->exec('DROP TABLE temp.fts5_trigram_probe');
                $trigram = true;
            } catch (\Throwable $e) {
                $trigram = false;
            }
        } catch (\Throwable $e) {
            $fts5 = false;
        }

        // Contentless (content='') so message text isn't duplicated — we re-read
        // it from the file by offset. detail stays at the default (full): the
        // trigram tokenizer needs token positions for substring/phrase queries.
        if ($fts5) {
            $tokenizer = $trigram ? 'trigram' : 'unicode61';
            try {
                $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS fts USING fts5(text, content=\"\", tokenize=\"$tokenizer\")");
            } catch (\Throwable $e) {
                $fts5 = false;
            }
        }

        $this->hasFts = $fts5;
        $this->caps = [
            'sqlite_version' => $sqliteVersion,
            'fts5' => $fts5,
            'trigram' => $trigram,
            'tokenizer' => $trigram ? 'trigram' : ($fts5 ? 'unicode61' : null),
            'tier' => $trigram ? 'fts5_trigram' : ($fts5 ? 'fts5_unicode61' : 'like'),
        ];
        $this->setMeta('capabilities', $this->caps);
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name = :n");
        $stmt->execute([':n' => $name]);

        return $stmt->fetchColumn() !== false;
    }

    public function capabilities(): array
    {
        return $this->caps ?: ($this->getMeta('capabilities') ?? []);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function beginBatch(): void
    {
        if ($this->inBatch) {
            return;
        }
        $this->pdo->beginTransaction();
        $this->inBatch = true;
        $this->insertEntry ??= $this->pdo->prepare(
            'INSERT OR REPLACE INTO entries (seq, offset, length, ts, level, fp_app, fp_sys, title)
             VALUES (:seq, :offset, :length, :ts, :level, :fp_app, :fp_sys, :title)'
        );
        if ($this->hasFts) {
            // Plain INSERT — seqs are append-only (reindex calls reset first),
            // and contentless FTS5 has no simple REPLACE/DELETE.
            $this->insertFts ??= $this->pdo->prepare('INSERT INTO fts (rowid, text) VALUES (:rowid, :text)');
        }
    }

    public function append(IndexedEntry $entry): void
    {
        if (! $this->inBatch) {
            $this->beginBatch();
        }
        $this->insertEntry->execute([
            ':seq' => $entry->seq,
            ':offset' => $entry->offset,
            ':length' => $entry->length,
            ':ts' => $entry->timestamp,
            ':level' => $entry->level,
            ':fp_app' => $entry->fpApp,
            ':fp_sys' => $entry->fpSys,
            ':title' => $entry->title,
        ]);

        if ($this->hasFts && $entry->searchText !== null && $entry->searchText !== '') {
            $this->insertFts->execute([':rowid' => $entry->seq, ':text' => $entry->searchText]);
        }

        // Accumulate stats in memory and flush once per batch — a per-entry
        // upsert exec() was the dominant indexing cost (throughput bottleneck).
        if ($entry->timestamp !== null) {
            $bucket = intdiv($entry->timestamp, 3600) * 3600;
            $this->statsAccum[$bucket][$entry->level] = ($this->statsAccum[$bucket][$entry->level] ?? 0) + 1;
        }
    }

    /** @var array<int,array<int,int>> bucket => level => count, flushed per batch */
    private array $statsAccum = [];

    private function flushStats(): void
    {
        if (empty($this->statsAccum)) {
            return;
        }
        $upsert = $this->pdo->prepare('INSERT INTO stats (bucket_hour, level, count) VALUES (:b, :l, :c)
            ON CONFLICT(bucket_hour, level) DO UPDATE SET count = count + :c2');
        foreach ($this->statsAccum as $bucket => $levels) {
            foreach ($levels as $level => $count) {
                $upsert->execute([':b' => $bucket, ':l' => $level, ':c' => $count, ':c2' => $count]);
            }
        }
        $this->statsAccum = [];
    }

    public function commitBatch(): void
    {
        if ($this->inBatch) {
            $this->flushStats();
            $this->pdo->commit();
            $this->inBatch = false;
        }
    }

    /** Roll up fingerprint groups from entries after a (re)index pass. */
    public function rebuildGroups(): void
    {
        $this->pdo->exec('DELETE FROM groups');
        // kind is derived from fp_sys, which the fingerprint engine sets only for
        // exceptions (KIND_EXCEPTION=1); plain message groups have a NULL fp_sys
        // (KIND_MESSAGE=2). Hard-coding kind=1 here mislabelled every recurring
        // message as an exception.
        $this->pdo->exec("INSERT INTO groups (fp, kind, count, first_ts, last_ts, sample_seq, level, title)
            SELECT fp_app, CASE WHEN MAX(fp_sys) IS NOT NULL THEN 1 ELSE 2 END, COUNT(*), MIN(ts), MAX(ts), MIN(seq), MAX(level),
                   (SELECT title FROM entries e2 WHERE e2.fp_app = e.fp_app AND e2.deleted = 0 ORDER BY seq LIMIT 1)
            FROM entries e WHERE fp_app IS NOT NULL AND deleted = 0 GROUP BY fp_app");
    }

    public function lastIndexedOffset(): int
    {
        return (int) ($this->getMeta('last_offset') ?? 0);
    }

    public function setLastIndexedOffset(int $offset): void
    {
        $this->setMeta('last_offset', $offset);
    }

    public function count(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function maxSeq(): int
    {
        return (int) ($this->pdo->query('SELECT COALESCE(MAX(seq),0) FROM entries')->fetchColumn());
    }

    public function page(?int $cursorSeq, int $limit, string $direction, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $cmp = $direction === 'newer' ? '>' : '<';
        $order = $direction === 'newer' ? 'ASC' : 'DESC';
        if ($cursorSeq !== null) {
            $where[] = "seq $cmp :cursor";
            $params[':cursor'] = $cursorSeq;
        }

        $sql = 'SELECT * FROM entries';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY seq $order LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($direction === 'newer') {
            // Keep newest-first ordering for the client regardless of direction.
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    public function seekTimestamp(int $ts): ?int
    {
        $stmt = $this->pdo->prepare('SELECT seq FROM entries WHERE ts >= :ts AND deleted = 0 ORDER BY ts ASC, seq ASC LIMIT 1');
        $stmt->execute([':ts' => $ts]);
        $seq = $stmt->fetchColumn();

        return $seq === false ? null : (int) $seq;
    }

    public function find(int $seq): ?IndexedEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM entries WHERE seq = :seq AND deleted = 0');
        $stmt->execute([':seq' => $seq]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function stats(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (! empty($filters['after'])) {
            $where[] = 'bucket_hour >= :after';
            $params[':after'] = intdiv((int) $filters['after'], 3600) * 3600;
        }
        if (! empty($filters['before'])) {
            $where[] = 'bucket_hour <= :before';
            $params[':before'] = intdiv((int) $filters['before'], 3600) * 3600;
        }
        $sql = 'SELECT bucket_hour, level, count FROM stats';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY bucket_hour ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $buckets = [];
        $levelTotals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = (int) $r['bucket_hour'];
            $lvl = (int) $r['level'];
            $c = (int) $r['count'];
            $buckets[$b][$lvl] = ($buckets[$b][$lvl] ?? 0) + $c;
            $levelTotals[$lvl] = ($levelTotals[$lvl] ?? 0) + $c;
        }

        return ['buckets' => $buckets, 'levels' => $levelTotals];
    }

    public function groups(string $sort = 'last_ts', string $dir = 'desc', int $limit = 100, ?int $newSince = null): array
    {
        $allowed = ['last_ts', 'first_ts', 'count'];
        $sort = in_array($sort, $allowed, true) ? $sort : 'last_ts';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $sql = 'SELECT * FROM groups';
        $params = [];
        if ($newSince !== null) {
            $sql .= ' WHERE first_ts >= :since';
            $params[':since'] = $newSince;
        }
        $sql .= " ORDER BY $sort $dir LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Occurrence counts for a fingerprint group across N time buckets. */
    public function groupSparkline(int $fp, int $buckets = 24): array
    {
        $range = $this->pdo->prepare('SELECT MIN(ts) mn, MAX(ts) mx FROM entries WHERE fp_app = :fp AND ts IS NOT NULL AND deleted = 0');
        $range->execute([':fp' => $fp]);
        $r = $range->fetch(PDO::FETCH_ASSOC);
        if (! $r || $r['mn'] === null) {
            return array_fill(0, $buckets, 0);
        }
        $min = (int) $r['mn'];
        $max = (int) $r['mx'];
        $span = max(1, $max - $min);
        $width = max(1, intdiv($span, $buckets));

        // Bucket in SQL (≤ $buckets+1 rows) rather than pulling every timestamp
        // for the group into PHP — a hot fingerprint can have millions of rows.
        $stmt = $this->pdo->prepare(
            'SELECT MIN(CAST((ts - :min) / :width AS INTEGER), :last) AS b, COUNT(*) AS c
             FROM entries WHERE fp_app = :fp AND ts IS NOT NULL AND deleted = 0
             GROUP BY b'
        );
        $stmt->bindValue(':min', $min, PDO::PARAM_INT);
        $stmt->bindValue(':width', $width, PDO::PARAM_INT);
        $stmt->bindValue(':last', $buckets - 1, PDO::PARAM_INT);
        $stmt->bindValue(':fp', $fp, PDO::PARAM_INT);
        $stmt->execute();
        $out = array_fill(0, $buckets, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $b = max(0, min($buckets - 1, (int) $row['b']));
            $out[$b] += (int) $row['c'];
        }

        return $out;
    }

    /** Count of groups whose first_ts is at-or-after a reference time. */
    public function newGroupsSince(int $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM groups WHERE first_ts >= :s');
        $stmt->execute([':s' => $since]);

        return (int) $stmt->fetchColumn();
    }

    public function reset(): void
    {
        $this->pdo->exec('DELETE FROM entries');
        $this->pdo->exec('DELETE FROM stats');
        $this->pdo->exec('DELETE FROM groups');
        if ($this->hasFts && $this->tableExists('fts')) {
            // Contentless FTS5 can't be cleared with DELETE; drop + recreate
            // with the same tokenizer recorded in capabilities.
            $tokenizer = $this->caps['tokenizer'] ?? ($this->getMeta('capabilities')['tokenizer'] ?? 'unicode61');
            try {
                $this->insertFts = null;
                $this->pdo->exec('DROP TABLE fts');
                $this->pdo->exec("CREATE VIRTUAL TABLE fts USING fts5(text, content=\"\", tokenize=\"$tokenizer\")");
            } catch (\Throwable $e) {
            }
        }
        $this->setMeta('last_offset', 0);
    }

    public function supportsSoftDelete(): bool
    {
        return true;
    }

    /**
     * Mark a single live entry deleted and keep the pre-aggregated stats/groups
     * tables (which never re-scan entries) consistent with the live set.
     */
    public function softDelete(int $seq): bool
    {
        $row = $this->pdo->prepare('SELECT ts, level, fp_app FROM entries WHERE seq = :seq AND deleted = 0');
        $row->execute([':seq' => $seq]);
        $entry = $row->fetch(PDO::FETCH_ASSOC);
        if ($entry === false) {
            return false;
        }

        $update = $this->pdo->prepare('UPDATE entries SET deleted = 1 WHERE seq = :seq AND deleted = 0');
        $update->execute([':seq' => $seq]);
        if ($update->rowCount() === 0) {
            return false;
        }

        if ($entry['ts'] !== null) {
            $bucket = intdiv((int) $entry['ts'], 3600) * 3600;
            $dec = $this->pdo->prepare('UPDATE stats SET count = count - 1
                WHERE bucket_hour = :b AND level = :l AND count > 0');
            $dec->execute([':b' => $bucket, ':l' => (int) $entry['level']]);
            $this->pdo->prepare('DELETE FROM stats WHERE count <= 0')->execute();
        }
        if ($entry['fp_app'] !== null) {
            $dec = $this->pdo->prepare('UPDATE groups SET count = count - 1 WHERE fp = :fp AND count > 0');
            $dec->execute([':fp' => (int) $entry['fp_app']]);
            $this->pdo->prepare('DELETE FROM groups WHERE count <= 0')->execute();
        }

        return true;
    }

    public function setMeta(string $key, $value): void
    {
        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:k, :v)');
        $stmt->execute([':k' => $key, ':v' => is_scalar($value) ? (string) $value : json_encode($value)]);
    }

    public function getMeta(string $key, $default = null)
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return $default;
        }
        $decoded = json_decode((string) $val, true);

        return $decoded !== null || $val === 'null' ? $decoded : $val;
    }

    public function touch(): void
    {
        $this->setMeta('last_viewed', time());
    }

    public function close(): void
    {
        if ($this->inBatch) {
            $this->commitBatch();
        }
        $this->insertEntry = null;
        $this->insertFts = null;
        // PDO closes on dtor; null the handle to release the file.
        unset($this->pdo);
    }

    public function driver(): string
    {
        return 'sqlite';
    }

    private function buildWhere(array $filters): array
    {
        // Soft-deleted entries are excluded from every keyset/page read.
        $where = ['deleted = 0'];
        $params = [];

        if (! empty($filters['levels'])) {
            $in = [];
            foreach (array_values($filters['levels']) as $i => $lvl) {
                $key = ":lvl$i";
                $in[] = $key;
                $params[$key] = (int) $lvl;
            }
            $where[] = 'level IN (' . implode(',', $in) . ')';
        }
        if (! empty($filters['after'])) {
            $where[] = 'ts >= :after';
            $params[':after'] = (int) $filters['after'];
        }
        if (! empty($filters['before'])) {
            $where[] = 'ts <= :before';
            $params[':before'] = (int) $filters['before'];
        }
        if (isset($filters['group']) && $filters['group'] !== null) {
            $where[] = 'fp_app = :group';
            $params[':group'] = (int) $filters['group'];
        }
        if (! empty($filters['seqs'])) {
            $in = [];
            foreach (array_values($filters['seqs']) as $i => $s) {
                $key = ":sq$i";
                $in[] = $key;
                $params[$key] = (int) $s;
            }
            $where[] = 'seq IN (' . implode(',', $in) . ')';
        }

        return [$where, $params];
    }

    private function hydrate(array $row): IndexedEntry
    {
        return new IndexedEntry(
            seq: (int) $row['seq'],
            offset: (int) $row['offset'],
            length: (int) $row['length'],
            timestamp: $row['ts'] !== null ? (int) $row['ts'] : null,
            level: (int) $row['level'],
            fpApp: $row['fp_app'] !== null ? (int) $row['fp_app'] : null,
            fpSys: $row['fp_sys'] !== null ? (int) $row['fp_sys'] : null,
            title: $row['title'] ?? null
        );
    }
}
