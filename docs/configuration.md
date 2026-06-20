# Configuration Reference

Publish the config file to see all options with inline comments:

```bash
php artisan vendor:publish --tag=loglens-config
```

The file lands at `config/loglens.php`. Every key has a sensible default; you only need to set what differs from the defaults. Below is a complete reference of every key.

---

## Log discovery

### `roots`

```php
'roots' => [
    storage_path('logs'),
],
```

An array of absolute directory paths that define the **security boundary** for file access. Every file parameter in a web or API request is resolved via `realpath()` and must fall inside one of these roots. Paths that escape (via traversal, symlinks, or absolute references) return 404.

You can add additional roots to expose log files from other locations:

```php
'roots' => [
    storage_path('logs'),
    '/var/log/nginx',
],
```

### `include`

```php
'include' => [
    '*.log',
    '**/*.log',
    '*.log.gz',
    '**/*.log.gz',
],
```

Glob patterns relative to each root that are included in file discovery. The `**` glob matches any number of directory levels.

### `exclude`

```php
'exclude' => [
    '**/*.lock',
],
```

Glob patterns relative to each root that are excluded from discovery, evaluated after `include`. Use this to suppress lock files, temp files, or subdirectories you do not want to expose.

### `sort`

```php
'sort' => ['by' => 'mtime', 'direction' => 'desc'],
```

Default sort order for the file list. `by` accepts `name`, `size`, or `mtime`. `direction` accepts `asc` or `desc`. The most recently modified file is listed first by default.

---

## Routing

### `route.prefix`

```php
'route' => [
    'prefix' => 'loglens',
    // ...
],
```

The URL prefix for all LogLens routes. With the default `loglens`, the UI is at `/loglens` and the API at `/loglens/api/...`.

### `route.domain`

```php
'route' => [
    'domain' => null,
    // ...
],
```

Optional domain constraint. Set this to host LogLens on a dedicated subdomain:

```php
'domain' => 'logs.example.com',
```

When set, the `prefix` is still applied within that domain.

### `route.middleware`

```php
'route' => [
    'middleware' => ['web'],
    // ...
],
```

Middleware applied to all LogLens routes, in addition to LogLens's own `IpAllowlist` and `Authorize` middleware which are always appended. The `web` middleware group is required for session-based authentication and CSRF protection.

To require authentication via your app's auth middleware before LogLens's gate:

```php
'middleware' => ['web', 'auth'],
```

### `route.asset_url`

```php
'route' => [
    'asset_url' => null,
    // ...
],
```

Base URL for serving the pre-built frontend assets. When `null`, assets are served from the same origin through a package route with version-fingerprinted URLs.

Set this to a CDN base URL to serve assets from a CDN:

```php
'asset_url' => 'https://cdn.example.com',
```

### `route.csp`

```php
'route' => [
    // 'csp' => null,
    // ...
],
```

Content-Security-Policy header sent with the SPA shell. The shell is designed to be strict-CSP-safe (an inert JSON boot block and an external module script — no inline scripts), so a strict same-origin default is applied when this key is omitted. Set it to a custom policy string when serving assets from an off-origin CDN (widen `script-src`/`style-src` to the CDN host), or to `null`/`''` to disable the header entirely. The always-safe `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` headers are sent regardless.

---

## Operating modes

### `api_only`

```php
'api_only' => env('LOGLENS_API_ONLY', false),
```

When `true`, UI routes and asset routes return 404 while the JSON API under `{prefix}/api/...` remains fully functional. Use this when you want to consume the API from a custom frontend or a monitoring dashboard without exposing the standard UI.

### `read_only`

```php
'read_only' => env('LOGLENS_READ_ONLY', false),
```

When `true`, all destructive endpoints (delete, clear, prune) return 403 regardless of gate results or config kill-switches. This is a server-side safeguard suitable for staging environments where you want to inspect production logs without any risk of modification.

---

## Index engine

### `index.directory`

```php
'index' => [
    'directory' => storage_path('loglens'),
    // ...
],
```

The directory where per-file SQLite sidecar indexes are stored. Must be on **local disk** — see [installation.md](installation.md) for the NFS/EFS warning. Safe to delete at any time; indexes are rebuilt on demand.

### `index.size_budget`

```php
'index' => [
    'size_budget' => '1G',
    // ...
],
```

Global size limit for the index directory. Accepts an integer (bytes) or a human-readable string (`'512M'`, `'2G'`). When total index size exceeds this budget, indexes for the least-recently-viewed files are pruned first. The pruned files continue to work via tail-first rendering and rebuild their index on next access.

### `index.force_binary`

```php
'index' => [
    'force_binary' => false,
    // ...
],
```

Force the packed binary fallback store even when `pdo_sqlite` is available. This is useful for testing the degraded tier in development or CI.

### `index.in_request_budget_ms`

```php
'index' => [
    'in_request_budget_ms' => 200,
    // ...
],
```

Per-request time budget in milliseconds for the in-request indexing slicer. This is the last-resort strategy when no queue worker or scheduler is available. Indexing resumes from where it stopped on the next request.

### `index.batch_size`

```php
'index' => [
    'batch_size' => 2000,
    // ...
],
```

Number of entries written per SQLite transaction during indexing. Larger values improve throughput at the cost of memory per batch.

### `index.segment_threshold` and `index.segment_size`

```php
'index' => [
    'segment_threshold' => 256 * 1024 * 1024,  // 256 MB
    'segment_size'       => 64 * 1024 * 1024,   // 64 MB
],
```

Files larger than `segment_threshold` bytes are split into parallel index jobs when a queue worker is available. Each segment covers `segment_size` bytes. Segments are indexed concurrently into per-segment stores and merged on completion. The merged index is equivalent to a single-pass index.

---

## Parsing

### `parsing.formats`

```php
'parsing' => [
    'formats' => [
        'laravel',
        'json',
        'horizon_old',
        'http_access',
        'apache_error',
        'nginx_error',
        'php_fpm',
        'postgres',
        'redis',
        'supervisor',
    ],
    // ...
],
```

Ordered list of built-in parser IDs tried during auto-detection. The parser with the highest confidence score for the file's content wins. Remove entries you do not need to slightly speed up auto-detection on large fleets. (Horizon writes through Laravel's logger, so the `laravel` parser already covers it.)

### `parsing.timezone`

```php
'parsing' => [
    'timezone' => null,
    // ...
],
```

Source timezone for **offset-less** log timestamps — e.g. Laravel's default `[2026-06-13 14:30:00]`, which carries no UTC offset and is written in the app's configured zone. `null` uses `config('app.timezone')`, then falls back to UTC. Timestamps that already carry an explicit offset (or a trailing `Z`) are always resolved exactly and ignore this setting. Correct interpretation here keeps the time-range filter, histogram and live-tail windows aligned with the wall-clock times in the logs.

### `parsing.custom`

```php
'parsing' => [
    'custom' => [],
    // ...
],
```

Declarative custom log formats. Each entry is a name-keyed array with a `pattern` (named-capture PCRE with groups `datetime`, `level`, and `message`) and an optional `levels` map for normalizing level strings:

```php
'custom' => [
    'myapp' => [
        'pattern' => '/^(?P<datetime>\d{4}-\d{2}-\d{2}[ T][\d:]+)\s+(?P<level>\w+)\s+(?P<message>.*)/',
        'levels' => [
            'WARN'  => 'warning',
            'ERR'   => 'error',
            'CRIT'  => 'critical',
        ],
    ],
],
```

No PHP class is required.

### `parsing.parsers`

```php
'parsing' => [
    'parsers' => [],
    // ...
],
```

Class-based custom parsers. Each entry is a fully-qualified class name that implements `LogLens\Contracts\Parser`. These are tried alongside and after the built-in parsers during auto-detection.

### `parsing.opcodes_parsers`

```php
'parsing' => [
    'opcodes_parsers' => [],
    // ...
],
```

FQCNs of existing opcodesio/log-viewer `Log` subclasses to adapt for migration. The `OpcodesAdapter` wraps them so they participate in LogLens's parser pipeline without any modification to the class. See [migration-from-opcodesio.md](migration-from-opcodesio.md) for the migration workflow.

### `parsing.max_display_bytes`

```php
'parsing' => [
    'max_display_bytes' => 64 * 1024,  // 64 KB
],
```

Per-entry display truncation limit in bytes. Entries larger than this are shown truncated with an "expand" affordance that fetches the full text on demand by byte offset and length. This prevents huge JSON blobs or long stack traces from overwhelming the UI.

---

## Search

### `search.case_sensitive`

```php
'search' => [
    'case_sensitive' => false,
    // ...
],
```

Default case sensitivity for search queries. When `false` (the default), matching is case-insensitive and Unicode-correct (Cyrillic, Persian, and other scripts are handled correctly). Individual queries can override this with an explicit modifier.

### `search.pcre_scan_cap`

```php
'search' => [
    'pcre_scan_cap' => 256 * 1024 * 1024,  // 256 MB
],
```

Maximum byte range scanned during a streamed PCRE-tier search. This is the fallback tier used when an index is unavailable. It prevents runaway CPU usage on unindexed files.

### `search.default_limit`

```php
'search' => [
    'default_limit' => 100,
],
```

Default maximum number of results returned per search request. Can be overridden per request via the `limit` query parameter.

---

## Live tail

### `tail.window_seconds`

```php
'tail' => [
    'window_seconds' => 45,
    // ...
],
```

SSE connection window duration in seconds. After this time the server closes the stream cleanly and the browser's EventSource auto-reconnects with a `Last-Event-ID` resume cursor. The default of 45 seconds is intentionally below the common 60-second proxy/fastcgi read timeout.

### `tail.heartbeat_seconds`

```php
'tail' => [
    'heartbeat_seconds' => 15,
    // ...
],
```

Interval between SSE heartbeat comments (`: ping`). These keep the connection alive through load balancers that close idle connections and allow the client watchdog to detect a buffering middlebox.

### `tail.read_cap_bytes`

```php
'tail' => [
    'read_cap_bytes' => 512 * 1024,  // 512 KB
],
```

Maximum bytes read from the log file per tail engine tick. Caps per-tick CPU use when a large backlog accumulates.

### `tail.poll_active_ms` and `tail.poll_idle_ms`

```php
'tail' => [
    'poll_active_ms' => 2000,
    'poll_idle_ms'   => 10000,
],
```

Polling intervals (milliseconds) used by the client when SSE is unavailable or has been switched off. `poll_active_ms` is used when new entries have been seen recently; the client backs off to `poll_idle_ms` when the file is idle, reducing server load.

---

## Error grouping / fingerprints

### `fingerprint.deploy_stability`

```php
'fingerprint' => [
    'deploy_stability' => false,
    // ...
],
```

When `true`, line numbers are excluded from stack frames when computing fingerprints. This prevents a deploy that shifts line numbers from splitting an existing error group into a new one. Changing this setting triggers a reindex of affected files.

### `fingerprint.rules`

```php
'fingerprint' => [
    'rules' => [
        'Illuminate\\Database\\QueryException' => 'class_sql',
    ],
    // ...
],
```

Per-exception-class fingerprint strategy overrides. Available strategies:

| Strategy | Fingerprint inputs |
|---|---|
| `class` | Exception class only |
| `class_message` | Exception class + normalized message template |
| `class_frame` | Exception class + top application frame (default for most exceptions) |
| `class_sql` | Exception class + normalized SQL (for QueryException) |

`QueryException` is pre-seeded with `class_sql` so queries with different bound values group together under a normalized SQL template.

### `fingerprint.vendor_markers`

```php
'fingerprint' => [
    'vendor_markers' => ['/vendor/', '\\vendor\\'],
],
```

Path substrings that identify a stack frame as "vendor" (non-application) code. The top-most non-vendor frame is used for the primary fingerprint hash. Frames that are all vendor code fall back to the secondary (throw-site) hash.

---

## Security

### `security.allow_download`, `security.allow_delete`, `security.allow_clear`

```php
'security' => [
    'allow_download' => true,
    'allow_delete'   => true,
    'allow_clear'    => true,
    // ...
],
```

Config-level kill switches that override gate results to deny. Set any of these to `false` to disable the corresponding capability server-wide, regardless of what a gate definition returns. These are useful when deploying to environments where you want certain operations unconditionally disabled without modifying gate logic.

### `security.redaction.enabled`

```php
'security' => [
    'redaction' => [
        'enabled' => true,
        // ...
    ],
],
```

Controls display-time secret redaction. Enabled by default. When enabled, matched patterns are replaced with the configured `marker` value in all UI output, API responses, and exported content.

Disabling redaction is an opt-out that should only be used in development environments where you intentionally need to see raw credential values in logs.

### `security.redaction.patterns`

```php
'security' => [
    'redaction' => [
        'patterns' => [],
        // ...
    ],
],
```

Additional user-supplied PCRE patterns. Each match in log content (whole match) is replaced with the marker. These are applied in addition to the built-in patterns:

```php
'patterns' => [
    '/\bsk_live_[A-Za-z0-9]{24,}\b/',     // additional Stripe live key format
    '/\bToken token=[A-Za-z0-9+\/=]{20,}/', // custom token format
],
```

### `security.redaction.emails` and `security.redaction.cards`

```php
'security' => [
    'redaction' => [
        'emails' => false,
        'cards'  => true,
    ],
],
```

Toggle optional built-in pattern groups. Email redaction is off by default because email addresses frequently appear legitimately in logs (e.g., logged authentication events). Card number redaction is on by default.

### `security.redaction.marker`

```php
'security' => [
    'redaction' => [
        'marker' => '[redacted]',
    ],
],
```

The replacement string for redacted values.

### `security.ip_allowlist`

```php
'security' => [
    'ip_allowlist' => [],
],
```

Optional IP allowlist. Accepts exact IP addresses or CIDR ranges. When non-empty, requests from IPs not in the list are rejected before authorization is checked. An empty array disables the allowlist.

```php
'ip_allowlist' => ['192.168.1.0/24', '10.0.0.5'],
```

### `security.rate_limits`

```php
'security' => [
    'rate_limits' => [
        'search' => 120,
        'tail'   => 60,
        'index'  => 30,
    ],
],
```

Per-minute request limits for the throttled route groups. Limits are keyed by authenticated user ID when available, falling back to IP address. These apply to the search, live-tail, and index-management endpoint groups respectively.

### `security.download_ttl`

```php
'security' => [
    'download_ttl' => 300,
],
```

Lifetime in seconds of signed download URLs. After this time the URL is invalid and the client must request a new one. Download URLs are also bound to the requesting user — replaying a valid URL as a different user is rejected.

---

## Editor deep links

### `editor.default`

```php
'editor' => [
    'default' => 'phpstorm',
    // ...
],
```

The editor used when opening a stack frame via an editor deep link. Accepted values: `phpstorm`, `vscode`, `vscode-insiders`, `cursor`, `sublime`, `idea`.

### `editor.path_mapping`

```php
'editor' => [
    'path_mapping' => [],
],
```

Remote-to-local path rewrites for editor links. This is necessary when your application runs in Docker, a VM, or any environment where file paths in stack traces differ from paths on your local machine:

```php
'path_mapping' => [
    '/var/www/html' => 'C:\\www\\myapp',
],
```

Multiple mappings are applied in order; the first match wins.

---

## Localization

### `locale.default`

```php
'locale' => [
    'default' => null,
    // ...
],
```

The UI locale. When `null`, the locale is auto-detected from `app()->getLocale()`. Falls back to `en` if the detected locale is not in `available`.

### `locale.available`

```php
'locale' => [
    'available' => ['en', 'fa'],
],
```

The set of locales that have translations shipped with LogLens. `en` (English) and `fa` (Persian/Farsi) are included. Persian activates full RTL layout via CSS logical properties.

---

## Theme

### `theme`

```php
'theme' => 'dark',
```

Default UI theme. Accepted values: `dark`, `light`, `system`. Users can override this within the UI and the choice is persisted per browser. `dark` is the default.
