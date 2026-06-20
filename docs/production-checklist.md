# Go-to-Production Checklist

Status of every step required to ship LogLens v1.0.0. Items marked ✅ were
executed and verified in this repository; items marked ⬜ are external actions
that must run on your infrastructure / accounts.

## 1. Code & quality — ✅ done

- ✅ Full implementation (backend + Vue 3 SPA + docs), 80 PHP source files.
- ✅ Test suite: **94 tests / 372 assertions green**.
- ✅ extensive pre-release hardening review; **all findings fixed** (incl. a
  critical indexer resume-duplication bug).
- ✅ CSP-safe production bundle (`public/build/app.js`, `app.css`) — 0 `eval`,
  0 `new Function`.

## 2. Compatibility — ✅ verified across the range

| Target | How verified |
|--------|--------------|
| Laravel 13.0.0 / PHP 8.4 / PHPUnit 12 | ✅ full suite (92) green |
| Laravel 12.62 / PHP 8.3 / PHPUnit 11 | ✅ full suite green |
| PHP 8.0.30 core engine | ✅ engine boots; hash falls back from xxh3; search ladder degrades to `fts5_unicode61`; all 80 files lint-clean on 8.0 |
| Laravel 8–11 legs | ⬜ defined in `.github/workflows/ci.yml`; not run locally (transient Laravel security advisories block default installs — see §6) |

## 3. Real install smoke — ✅ verified

In a fresh **Laravel 12** app via a `path` repository (`composer require abdian/loglens`):

- ✅ Package auto-discovered; `php artisan about` shows the LogLens panel.
- ✅ Routes registered; `loglens:index` / `:stats` / `:search` work.
- ✅ Over HTTP: `/loglens` shell (200, versioned asset), `/loglens/assets/1.0.0/app.js`
  (200, immutable), `/api/files`, `/open`, `/search` (fts5_trigram), `/groups`,
  `/levels`, `/histogram`, `/tail/poll`, `/diagnostics` — all 200.
- ✅ Two real bugs found here and fixed: UTF-8 BOM parsing; cache-resilient
  browsing when the host cache driver is broken.

## 4. Git — ✅ done (in this repo)

- ✅ `git init`, branch `main`, 3 commits, **tag `v1.0.0`**.

## 5. Browser/UI runtime check — ⬜ recommended before launch

The SPA builds clean and the shell + assets serve correctly, but a human should
click through once: open a file, scroll the virtualized list, run a search,
open the detail drawer + stack trace, toggle live tail, open the command
palette (Cmd/Ctrl+K), and switch theme / `fa` locale (RTL). Nothing here is
automatable in CI without a headless-browser leg.

## 6. ⚠️ Current Laravel security advisories (transient)

At the time of this build, composer's audit **blocks default installs of
Laravel 11.x and 13.x** due to open advisories (e.g. `PKSA-mdq4-51ck-6kdq`).
This is a Laravel-side issue, not LogLens. Before release:

1. `composer audit` your target app.
2. Install the **patched** Laravel release once published, or temporarily set
   `config.audit.block-insecure=false` for CI only (never ship that in a
   library's committed `composer.json`).

## 7. Publish — ⬜ your accounts

```bash
# Push the repo + tag to GitHub
git remote add origin git@github.com:<you>/loglens.git
git push -u origin main --tags

# Submit to Packagist (first release): https://packagist.org/packages/submit
#   then enable the GitHub webhook for auto-updates on new tags.

# CI: GitHub Actions runs .github/workflows/ci.yml on push — confirm every
# matrix leg (Laravel 8–13 × PHP 8.0–8.5, ubuntu+windows, Octane) is green
# before announcing support.
```

## 8. Install in your production app — ⬜

```bash
composer require abdian/loglens
```

Then, in a service provider (production is **default-deny** until you do this):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewLogLens', fn ($user = null) =>
    in_array(optional($user)->email, ['you@example.com'])
);
```

Production prerequisites for the host app:

- A **working cache driver** (`file`/`redis`/`array`/migrated `database`) — the
  rate-limited endpoints (`search`/`tail`/`index`) need it. Browsing itself
  degrades gracefully if the cache is down.
- For live tail behind Nginx/Apache, no extra config (SSE auto-degrades to
  polling). Under **Octane/Swoole**, tail is forced to polling automatically.
- Keep redaction **on** (default) and review `config/loglens.php` `security.*`.
- See `docs/deployment.md` for the container dual-channel (stderr + file) recipe
  and `docs/security-checklist.md` before exposing it.

## 9. Optional pre-launch

- ⬜ Run the benchmark on a real production-sized log: `php benchmarks/run.php --size=1G`.
- ⬜ Re-verify `docs/security-checklist.md` against a production-mode install.
- ⬜ Decide license/monetization (ships all-MIT today).
