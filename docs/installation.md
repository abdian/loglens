# Installation

## Requirements

| Requirement | Minimum | Notes |
|---|---|---|
| PHP | 8.0 | Tested through 8.5 |
| Laravel | 8 | Tested through 13 |
| `pdo_sqlite` | Optional | Required for the SQLite index; a packed binary fallback is used when absent |
| `zlib` | Optional | Required for transparent `.gz` reading; `.gz` files are simply skipped when absent |

No other PHP extensions are required. LogLens explicitly does not require `pcntl`, Swoole, Redis, or any Symfony package.

## Install the package

```bash
composer require abdian/loglens
```

The service provider is auto-discovered via Laravel's package auto-discovery. No manual registration is needed.

## Zero-config first run

After installation, visit `/loglens` in a local or development environment. The viewer auto-discovers `storage/logs`, lists your log files, and renders the newest entries immediately — no configuration, no publish step, no npm commands.

In a **production** environment all routes return 403 by default until you define the `viewLogLens` gate. See [security.md](security.md) for how to do this correctly.

## Publishing the config file (optional)

The default configuration works for most applications. To customize it:

```bash
php artisan vendor:publish --tag=loglens-config
```

This copies `config/loglens.php` into your application's `config/` directory. The file is extensively commented — see [configuration.md](configuration.md) for a full reference of every key.

You do not need to publish assets. LogLens serves its pre-built, fingerprinted frontend from the vendor directory through a package route. There is no `@vite` or `@mix` directive; your application's build toolchain is not involved.

## Verifying the install

```bash
php artisan about
```

On Laravel 9.21+ this prints a LogLens section showing the package version, active index store (SQLite or binary fallback), and operating mode flags.

To pre-build indexes for all discovered log files before the first web request:

```bash
php artisan loglens:index
```

## Platform notes

### Laravel Octane (Swoole / RoadRunner)

LogLens is Octane-safe. All per-request services use container-scoped bindings (falling back to singletons on Laravel 8 where `scoped()` does not exist). There is no mutable static state.

Octane with Swoole buffers streaming HTTP responses, so SSE-based live tail is automatically switched to polling mode when an Octane/Swoole runtime is detected. The browser client makes this switch silently; no configuration is required.

### Windows

All functionality works on Windows without shell-outs to POSIX utilities. File identity uses NTFS inode data where available (PHP 7.4+). Locked-file behavior on Windows differs from Linux: a file being actively written cannot be deleted, and the clear operation (`ftruncate`) may fail if another process holds an exclusive lock. These failures are reported with their exact cause rather than a generic error.

### Shared hosting

LogLens requires only standard web-request execution — no background processes, no PCNTL, no shell access. The background indexing strategy ladder automatically falls back to time-sliced in-request indexing when no queue worker or scheduler is present. The index is built incrementally across requests.

If you cannot run a queue worker or scheduler, running `php artisan loglens:index` during your deploy is the most reliable way to have a complete index ready for the first user request.

### NFS / EFS / network-mounted storage

The index directory must be on **local disk**. SQLite databases on NFS or EFS are subject to corruption due to network-level lock semantics. If your `storage/` is mounted from a network file system, set `index.directory` in the config to a path on local storage:

```php
'index' => [
    'directory' => '/tmp/loglens-' . env('APP_NAME', 'app'),
    // ...
],
```

Note that ephemeral local storage means the index is rebuilt on each container restart — this is fine because LogLens always falls back to tail-first rendering before the index is ready.

## Uninstall

```bash
composer remove abdian/loglens
rm -rf storage/loglens
```

If you published the config file:

```bash
rm config/loglens.php
```

LogLens leaves no migrations to roll back, no published assets, no cache keys, and no database rows. After the two commands above the application retains no trace of the package.
