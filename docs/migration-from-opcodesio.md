# Migrating from opcodesio/log-viewer

LogLens was designed with a migration path from opcodesio/log-viewer in mind. Gate names are intentionally identical. Custom parsers can be adapted without code changes. Configuration keys follow a similar structure. This guide covers every step.

---

## Step 1: Install LogLens

```bash
composer require abdian/loglens
composer remove opcodesio/log-viewer
```

If you had published the opcodesio config file, remove it:

```bash
rm config/log-viewer.php
```

Visit `/loglens` in your browser to confirm the installation works before proceeding with customization.

---

## Step 2: Gate names are already compatible

LogLens uses the **identical gate names** as opcodesio/log-viewer for all per-action operations. If you have defined any of these gates in your application, they work with LogLens without any changes:

| Gate | opcodesio | LogLens | Compatible |
|---|---|---|---|
| View the log viewer | `viewLogViewer` | `viewLogLens` | **Different** — see below |
| Download a single file | `downloadLogFile` | `downloadLogFile` | Yes |
| Download a folder | `downloadLogFolder` | `downloadLogFolder` | Yes |
| Delete a file | `deleteLogFile` | `deleteLogFile` | Yes |
| Delete a folder | `deleteLogFolder` | `deleteLogFolder` | Yes |
| Clear a file's contents | `clearLogFile` | `clearLogFile` | Yes |
| Prune files | _(not in opcodesio)_ | `pruneLogFiles` | N/A |

The only gate name change is the top-level access gate: opcodesio uses `viewLogViewer`; LogLens uses `viewLogLens`. Update this one gate definition in your `AppServiceProvider` or `AuthServiceProvider`:

```php
// Before (opcodesio):
Gate::define('viewLogViewer', function ($user) {
    return in_array($user->email, ['alice@example.com']);
});

// After (LogLens):
Gate::define('viewLogLens', function ($user) {
    return in_array($user->email, ['alice@example.com']);
});
```

The logic of the gate does not change — only the name.

---

## Step 3: Config mapping

The following table maps opcodesio configuration keys to their LogLens equivalents:

| opcodesio key | LogLens key | Notes |
|---|---|---|
| `log_viewer.include_files` | `loglens.include` | Same glob syntax |
| `log_viewer.exclude_files` | `loglens.exclude` | Same glob syntax |
| `log_viewer.route.prefix` | `loglens.route.prefix` | Same |
| `log_viewer.route.middleware` | `loglens.route.middleware` | Same format |
| `log_viewer.auth.guards` | Not applicable | LogLens uses Laravel gates, not guards |
| `log_viewer.cache.driver` | Not applicable | LogLens does not use the application cache |
| `log_viewer.cache.lifetime` | Not applicable | LogLens indexes persist on disk |
| `log_viewer.api_only` | `loglens.api_only` | Same behavior |

Publish the LogLens config to customize it:

```bash
php artisan vendor:publish --tag=loglens-config
```

---

## Step 4: Adapting custom parsers (the OpcodesAdapter)

If you have a custom `Log` subclass for opcodesio/log-viewer, you can use it with LogLens without modifying the class. The `OpcodesAdapter` wraps opcodesio-style `Log` subclasses and makes them participate in the LogLens parser pipeline.

Register your existing class in `config/loglens.php`:

```php
'parsing' => [
    'opcodes_parsers' => [
        \App\Logs\MyCustomLogFormat::class,
        \App\Logs\AnotherCustomFormat::class,
    ],
],
```

The adapter calls your class's methods in the opcodesio signature, so no changes to the class are necessary. The class is tried during auto-detection alongside the built-in parsers.

If you want to write a native LogLens parser instead, implement `LogLens\Contracts\Parser` and register it under `parsing.parsers`. Or, for simple patterns, use the declarative config format in `parsing.custom` — no PHP class required at all:

```php
'parsing' => [
    'custom' => [
        'myformat' => [
            'pattern' => '/^(?P<datetime>\d{4}-\d{2}-\d{2} [\d:]+)\s+\[(?P<level>\w+)\]\s+(?P<message>.*)/',
            'levels'  => ['WARN' => 'warning', 'ERR' => 'error'],
        ],
    ],
],
```

---

## What is new and different

### Features that do not exist in opcodesio

These are net-new capabilities you gain after migration:

- **Persistent on-disk index** — no more cache eviction, UI hangs on large files, or zero-results after `cache:clear`. The index survives deployments and cache flushes.
- **Instant open of multi-GB files** — tail-first rendering delivers the newest entries before indexing completes.
- **Query language** — `level:error after:-1h "payment failed" -channel:horizon` in the search bar, in the API, and in CLI commands.
- **FTS5 full-text search** — index once, search many; warm queries on a 1 GB file return in under 200 ms on FTS-capable hosts.
- **Live tail in the browser** — SSE with automatic polling fallback; works on Windows and shared hosting without PCNTL.
- **Error grouping (Issues view)** — deterministic fingerprinting at index time; collapses thousands of identical exceptions into one actionable row with sparklines and "new since deploy" diffing.
- **Native NDJSON/JsonFormatter support** — structured JSON logs are auto-detected with no custom class required.
- **`.gz` file reading** — compressed rotated files are browsable and searchable without decompression.
- **`loglens:prune`** — automated retention management with age and size budgets, compression-to-gz, and dry-run mode.
- **Volume histogram** — level-stacked histogram answered from pre-aggregated stats, with brush-to-zoom.
- **RTL support** — Persian/Farsi locale with full RTL layout.
- **Correct file clear** — `ftruncate` preserves the inode so queue workers and Horizon continue appending without interruption (opcodesio's clear uses `unlink`, which orphans the inode for open file descriptors).

### Behavior differences to be aware of

**Index directory**: LogLens creates `storage/loglens/` on first use. This directory is safe to delete at any time and is not committed to source control. Add it to your `.gitignore`:

```
/storage/loglens/
```

**Cache is not used**: LogLens does not write to the Laravel application cache. Running `php artisan cache:clear` has no effect on LogLens state.

**Route prefix**: The default prefix is `loglens` (was `log-viewer` in opcodesio). Update any hardcoded links in your application or change the prefix in config.

**Clear semantics**: LogLens's "clear" action uses `ftruncate` (truncation without delete). The opcodesio "clear" action deletes and recreates the file. The LogLens approach is safer for applications with open file descriptors. If your application has logic that depends on the file being recreated rather than truncated, be aware of this difference.

**Batch operation reporting**: LogLens returns a structured result for every file in a batch operation — `{succeeded, skipped_unauthorized, failed_locked, failed_permission}`. There are no silent skips. If you have code that consumes the opcodesio batch response format, update it to handle the new structure.

**Signed download URLs**: LogLens download URLs are bound to the requesting user. A URL generated for one user cannot be used by another. If you generate download URLs programmatically and share them across users, adjust this workflow.
