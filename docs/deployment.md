# Deployment Guide

## What LogLens requires to work

LogLens reads **files on local disk**. It requires a log file to exist as an actual file accessible to the PHP process. Every feature — browsing, search, live tail, error grouping, analytics — operates on those files through the local filesystem.

This is a fundamental characteristic of the tool, not a limitation to be worked around. Understanding it is the key to a correct deployment.

---

## The container dual-channel recipe

The most common deployment scenario where LogLens needs careful setup is a containerized application that logs to stderr. Laravel's `LOG_CHANNEL=stderr` writes exclusively to the process stream, producing no file for LogLens to read.

The correct solution is to configure Laravel to write to **both** stderr and a file simultaneously using the `stack` channel. This is a one-line change to your logging configuration and environment:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver'   => 'stack',
        'channels' => ['daily', 'stderr'],
    ],

    'daily' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/laravel.log'),
        'level'  => env('LOG_LEVEL', 'debug'),
        'days'   => 14,
    ],

    'stderr' => [
        'driver'    => 'monolog',
        'handler'   => StreamHandler::class,
        'formatter' => env('LOG_STDERR_FORMATTER'),
        'with'      => ['stream' => 'php://stderr'],
        'level'     => env('LOG_LEVEL', 'debug'),
    ],
],
```

```
# .env (or container environment)
LOG_CHANNEL=stack
```

With this configuration:
- Your container orchestration platform (Docker, Kubernetes, ECS) collects logs from stderr as normal.
- LogLens reads from `storage/logs/laravel.log` (or daily-dated files) as normal.
- Both channels receive every log entry.

The `storage/logs` volume must be mounted to persistent (or at least ephemeral-local) storage so the file exists as long as the container is running. The LogLens index under `storage/loglens/` should be on the same volume.

---

## Ephemeral containers and index rebuild

When a container restarts and `storage/loglens/` is on ephemeral local storage, the index is lost. This is expected behavior — LogLens falls back to tail-first rendering immediately and rebuilds the index in the background.

To speed up the rebuild after container startup, add a `loglens:index` call to your container entrypoint:

```bash
#!/bin/bash
# entrypoint.sh
php artisan config:cache
php artisan loglens:index &    # background — doesn't block startup
exec php-fpm
```

The `&` runs indexing in the background so it does not delay your container's readiness probe.

For Kubernetes deployments, a post-start lifecycle hook is appropriate:

```yaml
lifecycle:
  postStart:
    exec:
      command: ["php", "artisan", "loglens:index"]
```

---

## Background indexing strategy

LogLens selects an indexing strategy automatically based on what is available in the environment. In order of preference:

### 1. Queue worker (best)

When a `queue:work` process is verifiably alive (not just when the queue driver is not `sync` — Laravel 11+'s default `database` driver will silently accumulate jobs with no worker), LogLens dispatches chunked, resumable index jobs. Large files are split into parallel byte-range segments for concurrent indexing.

This is the recommended strategy for production deployments with a queue worker. No configuration is required — LogLens detects the worker automatically via a heartbeat key.

### 2. Scheduler

When no queue worker is detected but the scheduler is running (`schedule:run` or `schedule:work`), indexing runs on the scheduler tick. This provides regular incremental indexing without a dedicated worker process.

Register the scheduler if you are not already running it:

```bash
# crontab -e
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

### 3. In-request slicing (fallback)

When neither a queue worker nor the scheduler is available, LogLens indexes incrementally during web requests, bounded by the `index.in_request_budget_ms` time budget (default: 200 ms). Indexing resumes from the last offset on each subsequent request.

This is the last-resort strategy. It works on shared hosting and minimal environments but means indexing completes more slowly on large files.

### 4. Manual: `loglens:index` (always available)

Running `php artisan loglens:index` during a deploy, in a Supervisor-managed watcher, or on a cron schedule is always available regardless of the above strategies. This is the most explicit and controllable option.

---

## Queue worker configuration

For large files or high-throughput applications, a dedicated queue worker improves indexing throughput significantly. The index jobs are dispatched to your application's default queue unless you configure a dedicated one:

```php
// config/queue.php — no change required; index jobs use the default queue
```

To use a dedicated queue for LogLens index jobs, you can override the queue in your `AppServiceProvider`:

```php
// This is not required; LogLens dispatches to the default queue by default.
// Override only if you need queue isolation.
```

Ensure your queue worker processes the queue where index jobs are dispatched:

```bash
php artisan queue:work --queue=default
```

---

## Out of scope in v1

The following deployment scenarios are not supported in v1. They are documented honestly here so you can make an informed decision.

### Laravel Cloud and pure-stderr deployments

Laravel Cloud writes logs to a platform-managed stream. There are no log files on disk accessible to the PHP process. LogLens cannot read these logs in v1 — it requires files. Use the dual-channel recipe above if you have any control over log destination.

This is not a gap unique to LogLens — no local OSS log viewer works in a pure-stderr environment. SaaS tools (Flare, Better Stack, Datadog) are the appropriate choice when log retention and search must work without local files.

### Multi-server index sharing

LogLens indexes are **per-instance and per-local-disk**. When you have multiple application servers, each server maintains its own independent index for the log files it can see. There is no cross-server index synchronization or merged cross-replica search in v1.

Each server's LogLens instance is fully functional for that server's logs. To search across all servers, you would need to either:
- Aggregate log files to a shared file system (note: the index must remain on local disk — see [installation.md](installation.md) for the NFS warning), or
- Use a log aggregation service.

Cross-replica search is explicitly unsolved in v1. It is architecturally planned (the `LogSource` interface was designed with remote sources in mind) but not implemented.

### S3, CloudWatch, and other remote sources

v1 ships only the `LocalFileSource` implementation of the `LogSource` contract. S3 ranged-GET sources, CloudWatch log groups, and SFTP sources are planned for future releases and will not require changes to the core indexing or search layer when implemented.

### Self-hosted multi-tenant deployments

LogLens is designed as a per-application viewer. Multi-tenant scenarios where a single LogLens instance serves logs from multiple separate applications are not supported.

---

## Octane-specific notes

### Swoole

LogLens detects a Swoole runtime and automatically switches the live-tail endpoint to polling-only mode. This is because Swoole buffers HTTP streaming responses, which breaks SSE. The browser client makes this switch transparently — no user configuration is required.

All other LogLens functionality (browsing, search, indexing, file management) works correctly under Swoole.

### RoadRunner

RoadRunner does not buffer streaming responses in the same way as Swoole. SSE-based live tail works correctly under RoadRunner. LogLens detects the runtime and does not force polling mode.

### Memory and state isolation

LogLens uses container-scoped bindings (`app->scoped()` on Laravel 9+, falling back to `singleton` on Laravel 8). All per-request state is isolated to the container scope. There is no mutable static state. Repeated requests under Octane produce correct, isolated responses.

---

## Log rotation and file management

When log rotation is managed externally (via `logrotate`, `newsyslog`, or a container log driver), LogLens detects rotation automatically on the next request or tail tick using a combination of file size regression, inode change, and a fingerprint of the file's first 4 KB.

The rotation detection correctly handles `copytruncate`-style rotation (where the file is copied and then truncated in place, preserving the inode) — a case that causes silent index corruption in other viewers.

When performing rotation yourself via LogLens's "delete" operation, the inode-keyed tombstone ensures a newly created same-named file gets a fresh index rather than being served with stale offsets.

The "clear" operation (truncate without delete) is the correct choice when you want to empty a log file while queue workers, Horizon, or other long-running processes hold the file open — it preserves the inode so those processes continue writing to the same file descriptor.
