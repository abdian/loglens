# CLI Commands

LogLens provides five Artisan commands that share the same index format, query language, and discovery logic as the web UI. They are available immediately after installation with no additional configuration.

---

## `loglens:index`

Build or update the sidecar index for all discovered log files (or a specific subset).

```bash
php artisan loglens:index
```

```bash
php artisan loglens:index [file...] [--watch] [--prune-orphans] [--interval=2]
```

### Arguments

| Argument | Description |
|---|---|
| `file` | Optional. One or more file IDs or file names to index. When omitted, all discovered files are indexed. |

### Options

| Option | Description |
|---|---|
| `--watch` | Continuously index appended bytes. After the initial pass completes, the command polls for new content at the configured interval and indexes incrementally. Press Ctrl+C to stop. |
| `--prune-orphans` | Drop index files for log files that no longer exist. Run this after deleting log files outside of LogLens to reclaim index disk space. |
| `--interval=2` | Poll interval in seconds when `--watch` is active. Default: 2 seconds. |

### Output

For each file indexed, the command prints the file name, index state, entry count, and active store driver:

```
  laravel.log — ready, 148923 entries (sqlite)
  horizon.log — ready, 42100 entries (sqlite)
```

After indexing, a budget enforcement pass prunes the least-recently-viewed indexes if the total index size exceeds `index.size_budget`.

### Use cases

**Deploy hook — pre-build indexes before the first user request:**

```bash
php artisan loglens:index
```

Add this to your deploy script (after `artisan config:cache`) to ensure all log files have a complete index when your application comes back online. The web UI will show `indexState: ready` immediately.

**Supervisor-managed continuous indexer:**

```ini
[program:loglens-index]
command=php /var/www/html/artisan loglens:index --watch --interval=5
autostart=true
autorestart=true
redirect_stderr=true
```

**Index a single file:**

```bash
php artisan loglens:index laravel.log
```

**Remove orphan indexes after log rotation cleanup:**

```bash
php artisan loglens:index --prune-orphans
```

---

## `loglens:search`

Search indexed log files using the full query language.

```bash
php artisan loglens:search "<query>" [--file=] [--limit=100] [--json] [--case-sensitive]
```

### Arguments

| Argument | Description |
|---|---|
| `query` | Required. The search query. Use the full query language described in [query-language.md](query-language.md). Wrap in quotes to prevent shell interpretation. |

### Options

| Option | Description |
|---|---|
| `--file=` | Restrict the search to a specific file ID or name. Can be specified multiple times for multiple files. When omitted, all discovered files are searched. |
| `--limit=100` | Maximum number of results returned per file. Default: 100. |
| `--json` | Output results as a JSON array instead of the default table format. |
| `--case-sensitive` | Enable case-sensitive matching for this search. Overrides the `search.case_sensitive` config default. |

### Output (plain)

Each match is printed as:

```
[2026-06-13 14:22:01] laravel.log.ERROR: Connection refused (tcp://127.0.0.1:6379) …
[2026-06-13 14:22:05] laravel.log.ERROR: Connection refused (tcp://127.0.0.1:6379) …
3 match(es).
```

### Output (JSON)

With `--json`:

```json
[
    {
        "file": "laravel.log",
        "seq": 148911,
        "datetime": "2026-06-13 14:22:01",
        "level": "error",
        "message": "Connection refused (tcp://127.0.0.1:6379)"
    }
]
```

### Query error reporting

If the query is syntactically invalid, the command prints the error position and exits with a failure code:

```
Query error at position 14: Unexpected token ")"
```

### Examples

```bash
# Errors in the last hour
php artisan loglens:search "level:error after:-1h"

# Payment failures in the last 24 hours, JSON output
php artisan loglens:search '"payment failed" after:-24h' --json

# Specific file, limited results
php artisan loglens:search "level:>=warning" --file=laravel.log --limit=20

# CI integration — exit non-zero if any critical errors today
php artisan loglens:search "level:critical after:-24h" --json | jq 'length > 0'
```

---

## `loglens:tail`

Stream new log entries to the terminal in real time, using the same rotation-aware pure-PHP tail engine as the browser UI. No PCNTL required; works on Windows.

```bash
php artisan loglens:tail [file] [--query=] [--interval=1]
```

### Arguments

| Argument | Description |
|---|---|
| `file` | Optional. File ID or name to tail. When omitted, the most recently modified discovered file is tailed. |

### Options

| Option | Description |
|---|---|
| `--query=` | Filter streamed entries using the query language. Only matching entries are printed. |
| `--interval=1` | Poll interval in seconds. Default: 1 second. |

### Behavior

- The command starts from the current end of the file and prints new entries as they are appended.
- Rotation is detected automatically: when the file is replaced (inode change, disappearance) or truncated (size regression), the command re-resolves the file by name and continues from the new file's beginning.
- When tailing the daily driver (`laravel-YYYY-MM-DD.log`), midnight rollover to the new date's file is followed automatically.
- Press Ctrl+C to stop.

### Output format

```
[14:22:01] ERROR: Connection refused (tcp://127.0.0.1:6379)
[14:22:05] ERROR: Connection refused (tcp://127.0.0.1:6379)
-- file rotated --
[00:00:01] INFO: Application started
```

### Examples

```bash
# Tail the most recently modified log file
php artisan loglens:tail

# Tail a specific file
php artisan loglens:tail laravel.log

# Tail only errors and above
php artisan loglens:tail --query="level:>=error"

# Tail with channel filter
php artisan loglens:tail --query="level:error -channel:horizon"

# Tail on Windows with a 2-second interval
php artisan loglens:tail --interval=2
```

---

## `loglens:stats`

Print a summary of all discovered log files: entry counts, level distribution, date span, file size, and index state.

```bash
php artisan loglens:stats [--json]
```

### Options

| Option | Description |
|---|---|
| `--json` | Output as a JSON array. |

### Behavior

Stats are read from pre-aggregated index metadata — no raw file scanning occurs for indexed files. For files with no index, a quick indexing pass is run first.

### Output (table)

```
+------------------+---------+--------+----------+--------+----------------------------+-------+
| File             | Entries | Errors | Warnings | Size   | Date span                  | Index |
+------------------+---------+--------+----------+--------+----------------------------+-------+
| laravel.log      | 148923  | 1204   | 8831     | 512 MB | 2026-05-01 -> 2026-06-13   | ready |
| horizon.log      | 42100   | 23     | 140      | 87 MB  | 2026-06-01 -> 2026-06-13   | ready |
| laravel-2026-... | 32041   | 0      | 220      | 64 MB  | 2026-06-12 -> 2026-06-12   | ready |
+------------------+---------+--------+----------+--------+----------------------------+-------+
```

### Output (JSON)

```json
[
    {
        "file": "laravel.log",
        "entries": 148923,
        "errors": 1204,
        "warnings": 8831,
        "size": "512 MB",
        "span": "2026-05-01 -> 2026-06-13",
        "index": "ready"
    }
]
```

---

## `loglens:prune`

Remove or compress old log files based on age and/or total size. Prune decisions are read from index metadata — no raw file scanning required.

```bash
php artisan loglens:prune [--days=] [--max-total-size=] [--keep-min=] [--compress] [--dry-run]
```

### Options

| Option | Description |
|---|---|
| `--days=N` | Remove (or compress) files whose last entry is older than N days. |
| `--max-total-size=X` | After age-based pruning, remove oldest files until total log size is under X. Accepts human-readable sizes: `10G`, `2G`, `512M`. |
| `--keep-min=N` | Always keep at least the N newest files **per folder**, regardless of age or size — a survivor floor so a quiet-but-active log is never wiped. Default `0`. |
| `--compress` | Gzip files instead of deleting them. Compressed `.gz` files remain readable by LogLens. |
| `--dry-run` | Print what would be removed or compressed without making any changes. |

At least one of `--days` or `--max-total-size` must be specified.

### Behavior

1. Files are ordered by age (oldest first) using the last-indexed entry timestamp.
2. If `--days` is set, all files older than N days are marked for action.
3. If `--max-total-size` is set, remaining oldest files are also marked for action until the total size falls below the threshold.
4. Marked files are either deleted (default) or compressed to `.gz` (with `--compress`).
5. Deleted files have their index entry removed and a tombstone written.
6. Each pruned file emits a `LogLens\Events\LogFilesPruned` audit event.
7. The `pruneLogFiles` gate is checked before any action.

### Output

```
  Deleted: laravel-2026-04-01.log
  Deleted: laravel-2026-04-02.log
  Compressed: laravel-2026-05-01.log
3 file(s): 2 deleted, 1 compressed.
```

With `--dry-run`:

```
  would delete: laravel-2026-04-01.log
  would delete: laravel-2026-04-02.log
  would compress: laravel-2026-05-01.log
[dry-run] 3 file(s): 2 deleted, 1 compressed.
```

### Scheduler integration

Register `loglens:prune` in your `app/Console/Kernel.php` (Laravel 8–10) or `routes/console.php` (Laravel 11+):

```php
// Laravel 8–10: app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('loglens:prune --days=30 --max-total-size=5G')
             ->daily()
             ->runInBackground()
             ->withoutOverlapping();
}
```

```php
// Laravel 11+: routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('loglens:prune --days=30 --max-total-size=5G')
    ->daily()
    ->runInBackground()
    ->withoutOverlapping();
```

**Compress old files, delete very old files:**

```php
// Keep last 7 days uncompressed, compress up to 90 days, delete beyond that
Schedule::command('loglens:prune --days=7 --compress')->daily();
Schedule::command('loglens:prune --days=90')->daily();
```

---

## `loglens:tick`

Drives the indexing coordinator's **scheduler tier**. Register it in the host
scheduler and LogLens gains a background indexing path even when no queue worker
is running: each tick marks the scheduler alive (so new files are enqueued for it
instead of being sliced inside web requests) and advances any pending files by a
bounded budget, resuming them on later ticks until fully indexed.

```bash
php artisan loglens:tick [--budget=4000]
```

### Options

| Option | Description |
|---|---|
| `--budget=N` | Per-file indexing budget in milliseconds for each tick. Default `4000`. |

### Scheduling

```php
use Illuminate\Support\Facades\Schedule; // Laravel 11+

Schedule::command('loglens:tick')->everyMinute()->withoutOverlapping();
```

This is optional. With a live queue worker LogLens already offloads indexing to
chunked jobs; without one it falls back to bounded in-request slices. Registering
`loglens:tick` simply gives the work a dedicated home on the scheduler. Indexing
is serialized per file by an advisory lock, so the tick, a queue worker and a web
request can never index the same file concurrently.
