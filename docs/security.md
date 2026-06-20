# Security Hardening Guide

LogLens is designed with a secure-by-default posture informed by the CVE history of the log-viewer category (CVE-2018-8947, CVE-2021-24966, CVE-2021-24761, CVE-2023-41877) and by implementation bugs in existing tools. This guide explains each protection layer and how to configure it correctly.

---

## Production default-deny gate

In any non-local environment (`APP_ENV` is not `local` or `testing`), **all LogLens routes return 403** until your application defines the `viewLogLens` gate. There is no way to accidentally expose logs in production through omission.

To grant access to specific users, define the gate in your `AppServiceProvider` or `AuthServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

// In AppServiceProvider::boot():

Gate::define('viewLogLens', function ($user) {
    return in_array($user->email, [
        'alice@example.com',
        'bob@example.com',
    ]);
});
```

To grant access by role (using Spatie Permissions or a similar package):

```php
Gate::define('viewLogLens', function ($user) {
    return $user->hasRole('developer') || $user->hasRole('admin');
});
```

To grant access to all authenticated users (not recommended for production):

```php
Gate::define('viewLogLens', function ($user) {
    return $user !== null;
});
```

The `viewLogLens` gate is evaluated before any LogLens logic runs — on web routes, API routes, and SSE/streaming routes — using the same middleware stack. There is no divergence where API routes bypass authentication (this was the root cause of opcodesio issue #366).

---

## Per-action gates

Six per-action gates guard destructive and export operations:

| Gate | Action |
|---|---|
| `downloadLogFile` | Downloading a single file |
| `downloadLogFolder` | Downloading a folder as a zip archive |
| `deleteLogFile` | Deleting a log file |
| `deleteLogFolder` | Deleting all files in a folder |
| `clearLogFile` | Truncating a file (clear contents, preserve inode) |
| `pruneLogFiles` | Running the retention prune operation |

These names are identical to the opcodesio/log-viewer gate names to simplify migration.

When a host app has not defined a per-action gate, the default policy allows the action (the `viewLogLens` gate has already been passed by this point). To restrict specific actions:

```php
Gate::define('deleteLogFile', function ($user) {
    return $user->hasRole('admin');
});

Gate::define('downloadLogFile', function ($user) {
    return $user->hasRole('developer') || $user->hasRole('admin');
});
```

Index management endpoints (triggering index builds or rebuilds) are also gated through the same `viewLogLens` gate — they are never accessible anonymously.

---

## Config kill switches and read-only mode

Config kill switches unconditionally deny specific capabilities regardless of gate results. They are useful for locked-down environments:

```php
// config/loglens.php
'security' => [
    'allow_download' => false,  // deny all download operations
    'allow_delete'   => false,  // deny delete and prune
    'allow_clear'    => false,  // deny file truncation
],
```

For a fully read-only deployment (browsing and search only, no mutations):

```php
'read_only' => env('LOGLENS_READ_ONLY', false),
```

```
LOGLENS_READ_ONLY=true
```

When `read_only` is `true`, all destructive endpoints return 403 even if a gate returns `true`.

---

## Path canonicalization

Every file and folder parameter in a request is resolved through `realpath()` and verified to reside inside one of the configured `roots`. Paths that fail this check — through directory traversal (`../`), symlink escape, or absolute references outside the root — return 404 without performing any filesystem operation.

This protection would have prevented:
- **CVE-2018-8947** (path traversal in an early log viewer)
- **CVE-2021-24966** and **CVE-2021-24761** (file read via traversal in log viewers)
- **CVE-2023-41877** (directory traversal in a log viewer)

You do not need to configure this — it is enforced by `PathGuard` on every request. The protection requires only that `roots` is set to the correct directories (the default `storage_path('logs')` is appropriate for most Laravel applications).

---

## Display-time redaction

Secret redaction is **on by default**. The following patterns are applied to all log content before it is sent to the browser or returned in API responses:

- `Authorization: Bearer <token>` headers
- `APP_KEY=` values
- AWS access keys (`AKIA...`) and secret keys
- Stripe publishable and secret keys
- JSON Web Tokens (`eyJ...`)
- Password fields (`"password":"..."`, `password=...`)
- Payment card numbers (configurable, on by default)

Matched values are replaced with `[redacted]` in the UI, in API responses, in exported downloads, and in copy actions.

To add application-specific patterns:

```php
'security' => [
    'redaction' => [
        'enabled'  => true,
        'patterns' => [
            '/\bsk_live_[A-Za-z0-9]{24,}\b/',
            '/\bBearer [A-Za-z0-9._~+\/-]+=*\b/',
        ],
    ],
],
```

To disable redaction (development only — understand what this exposes before using it):

```php
'security' => [
    'redaction' => [
        'enabled' => false,
    ],
],
```

---

## Safe rendering of untrusted content

Log content is attacker-controlled: it comes from user agents, request bodies, exception messages, and third-party libraries. LogLens renders it defensively:

1. **HTML escaping**: all log text is HTML-escaped before rendering. A `<script>alert(1)</script>` in a User-Agent header renders as inert text.

2. **ANSI safelist**: ANSI/VT100 sequences are processed through an allowlist of SGR color codes only. Cursor movement, hyperlink (OSC 8), clipboard, and mouse sequences are stripped. This prevents ANSI escape injection attacks (an active CVE class in 2026 terminal emulators and web-based log viewers).

3. **URL scheme checking**: URLs found in log text are only linkified if their scheme is `http` or `https`. `javascript:`, `data:`, and other schemes are rendered as plain text.

Log content is never interpolated into an executable context (no `eval`, no `new Function`, no `v-html` with unescaped content). The frontend build is strict-CSP compatible — it does not require `unsafe-eval` or `unsafe-inline`.

---

## CSRF protection

All destructive endpoints (delete, clear, prune, index rebuild) accept only non-GET HTTP verbs and require a valid Laravel CSRF token. A forged cross-site request without a matching CSRF token is rejected by the `web` middleware group before LogLens authorization runs.

---

## Audit events

Every significant action dispatches an audit event that your application can listen to and persist. The event classes are in the `LogLens\Events` namespace:

| Event class | Dispatched when |
|---|---|
| `LogLens\Events\LogFileViewed` | A file is opened in the browser |
| `LogLens\Events\LogFileDownloaded` | A file download is completed |
| `LogLens\Events\LogFileDeleted` | A file is deleted |
| `LogLens\Events\LogFileCleared` | A file's contents are truncated |
| `LogLens\Events\LogFilesPruned` | Files are removed by the prune operation |

Each event carries: `userId` (nullable), `path` (canonical absolute path), `ip` (nullable), and `time` (Unix timestamp).

To listen and persist audit events:

```php
// In EventServiceProvider::$listen:
\LogLens\Events\LogFileDeleted::class => [
    \App\Listeners\AuditLogLensAction::class,
],
\LogLens\Events\LogFileDownloaded::class => [
    \App\Listeners\AuditLogLensAction::class,
],
```

```php
// app/Listeners/AuditLogLensAction.php
namespace App\Listeners;

use LogLens\Events\AuditEvent;

class AuditLogLensAction
{
    public function handle(AuditEvent $event): void
    {
        \App\Models\AuditLog::create($event->toArray());
    }
}
```

---

## Signed, identity-bound download URLs

Download URLs are generated as Laravel temporary signed routes. Each URL is bound to:

- The **authenticated user's ID** at the time of signing
- The **canonical file path** (resolved via `realpath()`)
- A short TTL (default: 300 seconds, configurable via `security.download_ttl`)

When the URL is fetched, both the signature and the user identity are re-verified. A valid signed URL opened by a different authenticated user is rejected. This prevents a user from sharing a download link with another user who should not have access to the file.

---

## Rate limiting

Three groups of endpoints are rate-limited, keyed by authenticated user ID (falling back to IP address):

| Group | Default limit | Configurable via |
|---|---|---|
| Search | 120 req/min | `security.rate_limits.search` |
| Live tail (SSE + polling) | 60 req/min | `security.rate_limits.tail` |
| Index management | 30 req/min | `security.rate_limits.index` |

To adjust limits:

```php
'security' => [
    'rate_limits' => [
        'search' => 60,   // stricter search limit
        'tail'   => 30,
        'index'  => 10,
    ],
],
```

---

## IP allowlist

To restrict all LogLens access to specific networks, configure an IP allowlist:

```php
'security' => [
    'ip_allowlist' => [
        '10.0.0.0/8',       // internal network
        '192.168.1.100',    // specific host
        '203.0.113.0/24',   // office network
    ],
],
```

The allowlist is checked before authorization. Requests from IPs not in the list receive a 403 response. An empty array (the default) disables the allowlist.

---

## SSE authentication

The live-tail SSE endpoint authenticates via the session cookie (the `web` middleware group must be in `route.middleware`). Authentication tokens are never passed as query string parameters — the browser's `EventSource` API cannot send custom headers, but the session cookie is sent automatically. This avoids the cross-site WebSocket hijacking vulnerability pattern that affected Dozzle's cluster feature.

---

## Strict-CSP compatibility

The LogLens SPA is built as a Vue 3 runtime-only bundle (no `unsafe-eval`, no `new Function`). It does not use `v-html` on untrusted content, inline event handlers, or inline styles. The build is compatible with a `Content-Security-Policy` of:

```
Content-Security-Policy: script-src 'self'; style-src 'self'; img-src 'self' data:;
```

No `unsafe-eval` or `unsafe-inline` directives are required in your application's CSP for LogLens to function.
