# Query Language

LogLens provides a minimal, readable query language modeled on the intersection of LogQL, KQL, Datadog search, and GitHub code search. The same grammar is parsed and executed identically in:

- The web UI search bar
- The JSON API (`?q=` parameter on search endpoints)
- `php artisan loglens:search "<query>"`
- `php artisan loglens:tail --query="<query>"`
- Live-tail server-side filtering (only matching entries are streamed)

Queries are **literal by default** — no characters have special meaning unless the syntax explicitly requires it. You never need to escape a dot, parenthesis, or bracket in a bare search term.

---

## Bare terms — implicit AND

Space-separated terms are combined with implicit AND. An entry must contain all terms to match.

```
payment failed
```

Matches entries containing both the word `payment` and the word `failed` anywhere in the message (not necessarily adjacent).

```
timeout database connection
```

Matches entries containing all three words.

---

## Quoted phrases

Use double quotes to match an exact substring including spaces and special characters.

```
"payment failed"
```

Matches entries containing the exact string `payment failed`.

```
"Connection refused (127.0.0.1:6379)"
```

Matches entries containing that exact parenthesized string — no escaping needed.

---

## Field filters

Filter on specific parsed fields using `field:value` syntax.

### `level:`

```
level:error
level:warning
level:critical
level:debug
level:info
level:notice
level:alert
level:emergency
```

Match entries at a specific log level. Level names are case-insensitive.

### Level comparisons

```
level:>=warning
level:>info
level:<=error
level:<critical
```

Match entries at or above/below a threshold. The severity order is:
`debug < info < notice < warning < error < critical < alert < emergency`.

```
level:>=warning
```

Matches `warning`, `error`, `critical`, `alert`, and `emergency`.

### `channel:`

```
channel:horizon
channel:daily
channel:stack
```

Match entries from a specific Monolog channel.

### `env:`

```
env:production
env:staging
```

Match entries recorded in a specific Laravel environment (the `APP_ENV` portion of the log line).

### `file:`

```
file:laravel.log
file:horizon.log
```

Match entries from a specific file when searching across multiple files. The value is matched as a substring of the file name.

### Context field filters

Match against values inside the JSON context object appended to log entries:

```
context.user_id:42
context.order_id:8899
context.url:/api/payments
```

Context values are matched as literal substrings.

---

## Time filters

### `after:` and `before:`

Restrict results to a time range. Both accept absolute timestamps and relative shorthand.

**Absolute:**

```
after:2026-06-01
before:2026-06-30
after:2026-06-01T14:00:00
after:"2026-06-01 14:00:00"
```

**Relative (from now):**

```
after:-1h        # last 1 hour
after:-30m       # last 30 minutes
after:-2d        # last 2 days
after:-1w        # last 1 week
before:-6h       # entries older than 6 hours ago
```

Relative units: `m` (minutes), `h` (hours), `d` (days), `w` (weeks).

**Combined:**

```
after:2026-06-01 before:2026-06-15
after:-24h level:error
```

---

## Negation

Prefix a term or field filter with `-` or use the `NOT` keyword to exclude matches.

```
-channel:horizon
NOT level:debug
-"health check"
-context.url:/health
```

An entry must not satisfy the negated term to appear in results.

```
level:error -channel:horizon -"queue:work"
```

Errors not from the Horizon channel and not mentioning `queue:work`.

---

## OR and parentheses

Use `OR` (uppercase) to match either of two expressions. Parentheses control grouping.

```
level:error OR level:critical
```

```
(level:error OR level:critical) after:-1h
```

```
"payment failed" OR "order not found" OR "checkout error"
```

```
(context.user_id:42 OR context.user_id:43) level:error
```

AND (implicit) binds tighter than OR, so `a b OR c d` means `(a AND b) OR (c AND d)`.

---

## Prefix wildcards

Append `*` to a term to match any string that starts with that prefix.

```
TimeoutException*
App\\Http\\Controllers\\*
/api/v*
```

Prefix wildcards are supported on bare terms and quoted phrases. They are particularly useful when you know the beginning of an exception class name or URL.

---

## Opt-in regex

Wrap a pattern in `/` delimiters to use a regular expression. Regex is **opt-in** — you must use the `/pattern/` syntax explicitly.

```
/TimeoutException|ConnectionException/
/TimeoutException|ConnectionException/i
/\bpayment\b.*\bfailed\b/i
```

Supported flags after the closing `/`:

| Flag | Meaning |
|---|---|
| `i` | Case-insensitive matching |
| `s` | Dot matches newlines |
| `m` | `^` and `$` match line boundaries |

Regex terms are matched against the full raw entry text, not just the message field.

---

## Case sensitivity

By default, all matching is case-insensitive with full Unicode support (Cyrillic, Persian, Arabic, and other scripts are handled correctly). The global default is set in `config/loglens.php` via `search.case_sensitive`.

---

## Complete examples

**Errors from the last hour, not from Horizon:**

```
level:error after:-1h -channel:horizon
```

**Payment failures in the last 24 hours:**

```
"payment failed" OR "payment error" after:-24h level:>=error
```

**Database exceptions with a specific table:**

```
level:error "QueryException" context.sql:*orders*
```

**Slow requests (over 2000 ms) in a date range:**

```
"Slow query detected" after:2026-06-10 before:2026-06-12
```

**Specific user's errors:**

```
context.user_id:1042 level:>=warning after:-7d
```

**All critical and emergency entries this week:**

```
level:>=critical after:-1w
```

**Exception class prefix:**

```
Illuminate\\Database\\* level:error after:-2d
```

**Regex for two exception classes:**

```
/TimeoutException|ConnectionException/ level:error after:-1h
```

**New errors since a deploy (combine with the Issues view):**

```
level:error after:2026-06-11T18:00:00
```

**Tail errors in the terminal:**

```bash
php artisan loglens:tail --query="level:>=error"
php artisan loglens:tail --query="level:error -channel:horizon after:-1h"
```

**Search from the CLI with JSON output:**

```bash
php artisan loglens:search "level:error after:-24h timeout" --json
php artisan loglens:search '"payment failed" OR "checkout error"' --file=laravel.log --limit=50
```

---

## Error feedback

If a query has a syntax error, the parser reports the position and a human-readable message. The server never throws a 500 — you receive a structured error response:

```json
{
    "error": {
        "code": "QUERY_PARSE_ERROR",
        "message": "Unexpected token \")\" at position 14",
        "position": 14
    }
}
```

In the web UI, the search bar highlights the error position inline.

---

## Execution tiers

The query is compiled to the best available backend:

| Tier | Condition | Characteristics |
|---|---|---|
| FTS5 + trigram | SQLite ≥ 3.34 with trigram tokenizer | Fastest; indexed substring acceleration, 50–100× over raw scan |
| FTS5 unicode61 | FTS5 present, no trigram | Good for word/prefix terms; short terms fall back to LIKE |
| SQL LIKE | No FTS5 | Unicode-correct case folding; all features work, slower |
| Streamed PCRE | No index | Index-assisted timestamp/level pre-filtering; bounded by `pcre_scan_cap` |

The active tier is shown in the diagnostics panel (`/loglens/api/diagnostics`). Field filters (`level:`, `after:`, `before:`) are always compiled to indexed SQL predicates regardless of tier.
