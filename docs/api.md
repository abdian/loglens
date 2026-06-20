# JSON API Reference

All LogLens functionality is available through a versioned JSON API under `{prefix}/api/`. The SPA itself consumes this API — there are no privileged internal paths. Third-party clients, headless dashboards, and CLI tools can consume the same endpoints.

When `api_only` is `true` in config, the UI and asset routes return 404 while this API remains fully operational.

## Authorization

The identical middleware stack (session auth, `viewLogLens` gate, IP allowlist, and per-action gates) protects every API endpoint. Unauthenticated requests in production return a structured 403 JSON response — never an HTML error page:

```json
{
    "error": {
        "code": "UNAUTHORIZED",
        "message": "Access denied."
    }
}
```

All destructive endpoints additionally require a valid CSRF token (sent as the `X-XSRF-TOKEN` header or `_token` field — the `web` middleware group handles this automatically for browser clients).

## Pagination contract

All list endpoints use **keyset/cursor-based pagination**. Responses include `next` and `prev` cursor strings. Pass a cursor as `?cursor=<value>` to retrieve the adjacent page. Cursors are opaque; do not parse or construct them.

Cursors remain valid as the underlying file grows. You will not skip or duplicate entries by paginating while new entries are being appended.

Numeric `OFFSET`-based pagination is not available by design — at 2 million entries, an offset lookup takes ~53 ms compared to ~0.1 ms for a keyset lookup.

## `indexState`

Every response that serves file-scoped data includes an `indexState` field:

| Value | Meaning |
|---|---|
| `"none"` | No index has been built yet. Data is served via tail-first reads. |
| `"building"` | Index is being built. The `indexPercent` field contains 0–99. |
| `"ready"` | Index is complete. All features (full-text search, histogram, jump-to-date) are available. |

Clients should implement progressive enhancement: render immediately, and enable index-dependent features when `indexState` becomes `"ready"`.

---

## Endpoints

All paths below are relative to `{prefix}/api/` (default: `/loglens/api/`).

---

### Diagnostics

#### `GET /diagnostics`

Returns the LogLens runtime state: package version, active index store (SQLite or binary), SQLite version and capabilities (FTS5, trigram), active search execution tier, tail transport mode (SSE or polling-only), redaction state, and configured feature flags.

---

### Saved searches

Saved searches are stored in LogLens's own storage (`index.directory`), not in your application's database.

#### `GET /saved-searches`

List all saved searches.

**Response:**
```json
[
    {
        "id": "abc123",
        "name": "Payment errors",
        "query": "level:error \"payment failed\"",
        "file": "laravel.log",
        "created_at": 1718275200
    }
]
```

#### `POST /saved-searches`

Save a new search. Body: `name`, `query`, optional `file`.

#### `DELETE /saved-searches/{id}`

Delete a saved search by ID.

---

### File discovery

#### `GET /files`

List all discovered log files.

**Query params:**
- `sort` — field to sort by: `name`, `size`, `mtime` (default from config)
- `direction` — `asc` or `desc`

**Response:**
```json
{
    "files": [
        {
            "id": "a1b2c3",
            "name": "laravel.log",
            "path": "/var/www/html/storage/logs/laravel.log",
            "size": 536870912,
            "sizeHuman": "512 MB",
            "mtime": 1718275200,
            "indexState": "ready",
            "indexPercent": 100
        }
    ]
}
```

#### `POST /files/batch`

Apply a batch operation (delete or clear) to multiple files. Body: `action` (`delete` or `clear`), `files` (array of file IDs).

**Response:**
```json
{
    "succeeded": ["a1b2c3"],
    "skipped_unauthorized": [],
    "failed_locked": [],
    "failed_permission": ["d4e5f6"]
}
```

No silent failures — every file is accounted for.

---

### Per-file browsing

All per-file endpoints use a `{file}` path parameter that is a file ID (from the `/files` listing).

#### `GET /files/{file}/open`

Open a file and receive its first page of entries (newest first) plus metadata. This is the entry point for the viewer.

**Response includes:** `indexState`, `indexPercent`, `entries` (array), `next`/`prev` cursors, `levels` (counts from aggregates), `parser` (detected format).

#### `GET /files/{file}/entries`

Paginate entries in a file.

**Query params:**
- `cursor` — opaque cursor from a previous response
- `direction` — `newer` or `older` (default: `older`, i.e., toward older entries)
- `level` — comma-separated level names to filter: `error,critical`
- `after` — ISO-8601 timestamp or relative shorthand (same syntax as the query language)
- `before` — ISO-8601 timestamp or relative shorthand

**Response includes:** `indexState`, `entries`, `next`, `prev`.

#### `GET /files/{file}/entries/{seq}`

Fetch a single entry by its sequential index number.

#### `GET /files/{file}/expand`

Fetch the full text of a truncated entry.

**Query params:** `offset`, `length` (byte position and length from the entry metadata).

#### `GET /files/{file}/jump`

Jump to entries at a specific timestamp.

**Query params:** `ts` — Unix timestamp or ISO-8601 string.

**Response:** An entries page starting at the first entry at or after the given timestamp.

#### `GET /files/{file}/permalink/{seq}`

Resolve a permalink. Returns the page number and cursor needed to navigate to entry `{seq}`, plus the entry itself.

#### `GET /files/{file}/context/{seq}`

Fetch surrounding entries for an entry, unfiltered (the "view in context" action).

**Query params:** `before` (default 10), `after` (default 10) — how many surrounding entries to return.

#### `GET /files/{file}/levels`

Fetch per-level entry counts for the file from pre-aggregated statistics.

**Response:**
```json
{
    "indexState": "ready",
    "levels": {
        "debug": 42100,
        "info": 93211,
        "warning": 8831,
        "error": 1204,
        "critical": 0
    }
}
```

#### `GET /files/{file}/mail/{seq}`

Render a logged MIME message (from `MAIL_MAILER=log`) as a structured preview.

**Response:** `subject`, `from`, `to`, `html` (sanitized), `text`, `attachments`.

---

### Search

#### `GET /files/{file}/search`

Search entries using the query language.

Rate-limited (default: 120 req/min).

**Query params:**
- `q` — the query string (full query language)
- `cursor` — pagination cursor
- `limit` — max results per page (default from `search.default_limit`)
- `case_sensitive` — `true` or `false`

**Response includes:** `indexState`, `entries` with `highlights` (server-computed match offsets), `next`, `prev`, `executionTier` (the active search backend).

---

### Error grouping (Issues)

#### `GET /files/{file}/groups`

List error groups (fingerprint clusters) for the file.

**Query params:**
- `sort` — `last_seen`, `first_seen`, `count` (default: `last_seen`)
- `direction` — `asc` or `desc`
- `cursor` — pagination cursor

**Response:**
```json
{
    "indexState": "ready",
    "groups": [
        {
            "fp": "1a2b3c4d5e6f7890",
            "title": "Connection refused (tcp://127.0.0.1:<num>)",
            "level": "error",
            "count": 1432,
            "firstSeen": 1718100000,
            "lastSeen": 1718275200,
            "sampleSeq": 148911
        }
    ],
    "next": null
}
```

#### `GET /files/{file}/groups/new`

List groups whose first occurrence is after a given timestamp. Used for "new errors since deploy" diffing.

**Query params:** `since` — Unix timestamp or ISO-8601 string.

#### `GET /files/{file}/groups/{fp}/sparkline`

Fetch sparkline data (hourly occurrence counts) for a specific group fingerprint.

---

### Analytics

#### `GET /files/{file}/histogram`

Fetch the level-stacked volume histogram for the file.

**Query params:**
- `after`, `before` — time range (same syntax as query language)
- `granularity` — `hour`, `day` (auto-selected by range if omitted)

**Response:**
```json
{
    "indexState": "ready",
    "buckets": [
        {
            "ts": 1718272800,
            "debug": 0,
            "info": 412,
            "warning": 23,
            "error": 7,
            "critical": 0
        }
    ]
}
```

#### `GET /files/{file}/sparkline`

Fetch a compact sparkline (hourly error/warning counts) for use in the file list.

---

### Index management

These endpoints trigger indexing operations server-side. All are gated through the `viewLogLens` gate and rate-limited (default: 30 req/min for the build/rebuild endpoints).

#### `GET /files/{file}/index`

Get the current index state for a file.

**Response:** `state` (`none`, `building`, `ready`), `percent`, `entries`, `driver`, `capabilities`.

#### `POST /files/{file}/index`

Trigger an incremental index build (index only new bytes since the last indexed offset). Returns immediately with the current state; indexing proceeds asynchronously.

#### `POST /files/{file}/index/rebuild`

Force a full index rebuild from scratch. Use this when you suspect index corruption.

---

### Downloads

#### `GET /files/{file}/download`

Generate a signed download URL for the file.

**Query params:**
- `last_mb` — optional; if set, only the last N megabytes of the file are included in the download.

**Response:**
```json
{
    "url": "/loglens/api/download/a1b2c3?signature=...&expires=...",
    "filename": "laravel.log",
    "size": 536870912,
    "ttl": 300
}
```

#### `GET /download/{file}` (signed)

Fetch a previously signed download. The URL must be valid (not expired) and the requesting user must match the user who signed it.

#### `GET /download` (zip)

Download multiple files as a streaming zip archive.

**Query params:** `files[]` — array of file IDs.

**Response:** A streaming `application/zip` response. Files the user is not authorized to download are excluded and enumerated in the `X-Skipped-Files` response header.

---

### File operations

#### `GET /files/{file}/writability`

Pre-flight check for clear and delete operations. Reports whether the file is writable (for truncation) and whether the containing directory is writable (for deletion), with owner mismatch diagnostics when relevant.

#### `POST /files/{file}/clear`

Clear the file's contents using `ftruncate`. Preserves the inode so long-running writers continue appending to the same file. Requires the `clearLogFile` gate and `security.allow_clear = true`.

#### `DELETE /files/{file}`

Delete the file and remove its index entry. Writes a tombstone to prevent stale index resurrection if a same-named file is later created. Requires the `deleteLogFile` gate and `security.allow_delete = true`.

---

### Live tail

#### `GET /tail/info`

Returns the recommended tail transport for the current server environment: `sse` or `polling`. Returns `polling` when an Octane/Swoole runtime is detected, or when server configuration suggests SSE will be buffered.

**Response:**
```json
{
    "transport": "sse",
    "reason": null
}
```

#### `GET /tail/stream` (rate-limited)

SSE endpoint. Returns a `text/event-stream` response streaming new log entries.

**Query params:**
- `files[]` — one or more file IDs to tail. Multi-file tailing multiplexes over the single stream using per-file SSE event names.
- `query` — optional query-language filter; only matching entries are streamed.
- `cursor` — resume cursor from a previous session (`pathHash:inode:offset` format). Can be the browser's `Last-Event-ID`.

**Event types:**
- `entry` — a new log entry (payload: JSON entry object)
- `rotation` — the file was rotated; the stream continues with the new file
- `ping` — heartbeat comment (`: ping`)
- `end` — window expiring cleanly; the browser should reconnect

#### `GET /tail/poll` (rate-limited)

Polling fallback. Returns only bytes/entries appended since the supplied cursor.

**Query params:** `cursor`, `files[]`, `query`.

**Response:** `entries`, `cursor` (updated for the next poll), `etag`. Returns HTTP 304 (empty body) when no new entries are available. The `ETag` is derived from file size, mtime, and identity — the client can use it for conditional requests.
