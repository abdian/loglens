# Changelog

All notable changes to LogLens are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-06-20

First public release.

### Added

- **Persistent on-disk index engine** — one SQLite sidecar per log file under
  `storage/loglens/`, recording byte offsets, timestamps, levels, fingerprint
  hashes and pre-aggregated per-hour/level counts. Indexing is incremental;
  rotation and truncation are detected via size, inode and a first-4 KB
  fingerprint. Falls back to a packed binary sidecar when `pdo_sqlite` is absent.
- **Instant first paint** — opening any file serves the newest page via backward
  chunked reads with no index required; the response reports
  `indexState: none|building|ready` so the UI progressively enables
  index-backed features.
- **Format support** — Laravel/Monolog LineFormatter (multi-line stack traces,
  dual JSON context tails from Laravel 11+), NDJSON/JsonFormatter, Horizon,
  Apache/Nginx access and error logs, PHP-FPM, PostgreSQL, Redis and Supervisor.
  Custom formats via named-capture PCRE in config, a class-based API, and an
  adapter for existing opcodesio `Log` subclasses. Transparent `.gz` reading.
- **FTS5 search with a query language** — bare terms, quoted phrases,
  `field:value` filters, level comparisons, absolute/relative time filters,
  negation, OR with parentheses, prefix wildcards and opt-in regex. One grammar
  drives the web UI, JSON API and CLI. Capability ladder degrades gracefully
  (FTS5+trigram → FTS5 unicode61 → SQL LIKE → index-assisted PCRE scan).
- **Live tail in the browser** — Server-Sent Events over a raw `StreamedResponse`
  with automatic reconnect, transparent fallback to offset polling behind
  buffering proxies or Octane/Swoole, and multi-file multiplexing.
- **Error grouping (Issues view)** — deterministic exception and message
  fingerprints, occurrence counts, first/last seen, sparklines, and a
  "new since" diff for post-deploy triage.
- **Analytics** — level-stacked volume histogram answered from pre-aggregated
  statistics; drag-to-zoom the entry list to a time window.
- **File management** — inode-preserving `Clear` (ftruncate under lock),
  tombstoned `Delete`, signed user-bound `Download` with streaming zip and
  partial "last N MB" downloads, and `Prune` retention by age and total size.
- **Vue 3 + Pinia + Tailwind SPA** served from the vendor directory — no publish
  step, no npm for users. Three-pane IDE layout, Flare-style stack traces with
  editor deep links, keyboard-first navigation, dark mode, and full RTL support
  for the Persian (`fa`) locale.
- **Versioned JSON API** under `{prefix}/api`, with an `api_only` mode that
  disables the UI while keeping the API operational.
- **CLI companion** — `loglens:index`, `loglens:search`, `loglens:tail`,
  `loglens:stats`, `loglens:prune` and `loglens:tick` (scheduler-tier indexing
  driver), sharing the index and query language.
- **Operational options** — a configurable source timezone for offset-less log
  timestamps (`parsing.timezone`), a self-managed Content-Security-Policy on the
  SPA shell (`route.csp`), a dedicated rate-limit bucket for the analytics
  endpoints, and a `loglens:prune --keep-min` survivor floor.

### Security

- **Production default-deny** — in any non-local environment every route returns
  403 until the `viewLogLens` gate is defined. The same authorization middleware
  protects web, API and SSE routes.
- **Path canonicalization guard** — every file parameter resolves through
  `realpath()` and must reside inside a configured root; symlink escapes are
  rejected.
- **Display-time secret redaction** (on by default) — Authorization headers,
  `APP_KEY`, AWS/Stripe/GitHub keys, JWTs and password-like fields are masked
  before content reaches the browser.
- **Safe rendering** — log content is HTML-escaped first and returned as
  structured tokens; only safelisted ANSI SGR sequences are translated and only
  `http`/`https` URLs are linkified.
- Per-action kill switches (`allow_download`, `allow_delete`, `allow_clear`) and
  a global `read_only` mode that override host gates toward deny.

[Unreleased]: https://github.com/abdian/loglens/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/abdian/loglens/releases/tag/v1.0.0
