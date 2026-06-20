# Security Checklist — CVE Pattern Re-Verification

LogLens's security model is informed by the log-viewer category's
CVE history. This checklist maps each known attack pattern to the LogLens
mitigation **and the test that proves it**, so the posture can be
independently re-verified before each release.

| # | Attack pattern | Real-world reference | Mitigation | Proving test |
|---|----------------|----------------------|------------|--------------|
| 1 | **Path traversal** — `../../etc/passwd`, absolute paths, encoded dot-dot in a file parameter | CVE-2018-8947, CVE-2021-24966, CVE-2023-41877 | Every file parameter resolves through `realpath()` and must be contained in a configured root (`PathGuard`); non-canonical / escaping paths 404 | `SecurityUnitTest::test_path_guard_rejects_traversal`, `SecurityTest::test_traversal_returns_404` |
| 2 | **Symlink escape** — a symlink inside the root pointing outside it | category pattern | `realpath()` follows the link; the resolved target is re-checked against the root | `PathGuard::resolve` containment (unit) |
| 3 | **Production exposure / no auth** — viewer reachable unauthenticated in production | opcodesio default-allow history | Production **default-deny** `viewLogLens` gate; allowed only in `local`/`testing` until the host defines the gate | `SecurityTest::test_production_default_deny` |
| 4 | **Auth divergence between route classes** — web authorized, API/SSE not (null user) | opcodesio #366 | One `Authorize` middleware applied **identically** to web, API and SSE routes | `SecurityTest::test_production_default_deny` (API), middleware shared in `LogLensServiceProvider::registerRoutes` |
| 5 | **CSRF on destructive actions** — forged cross-site delete/clear | category pattern | Destructive endpoints are POST/DELETE only and run under the host's `web` (CSRF) middleware group; `read_only` + kill switches force-deny | `SecurityTest::test_read_only_blocks_delete`, `FileManagementTest` |
| 6 | **Stored XSS** — `<script>` from a logged User-Agent/header rendered as HTML | CVE-2021-24761 (and similar) | All content HTML-escaped first; the server returns pre-escaped `tokens`, never raw HTML | `SecurityTest::test_xss_payload_renders_inert`, `SecurityUnitTest::test_escapes_html` |
| 7 | **ANSI escape injection** — OSC 8 hyperlinks / clipboard / cursor sequences | Dozzle 2026 cluster | Only an SGR **color** safelist is translated; OSC/CSI/cursor/clipboard sequences are stripped | `SecurityUnitTest::test_strips_ansi_osc_hyperlinks_keeps_sgr` |
| 8 | **Secret leakage** — tokens/keys/passwords shown in the UI or API | operational risk | Display-time redaction **on by default** across UI/API/export/copy (Bearer/JWT, APP_KEY, AWS, Stripe, GitHub, password fields, optional email/card) | `SecurityTest::test_redaction_applied_in_api`, `SecurityUnitTest::test_redacts_*` |
| 9 | **Signed-URL replay** — a download link reused by another user | identity-binding gap | Download URLs are `temporarySignedRoute`s bound to **user id + canonical path**; both signature and identity re-verified at fetch | `DownloadController::fetch` identity check; `FileManagementTest::test_signed_download_url_is_user_bound` |
| 10 | **SSE auth via query-string token** (CSWSH) | Dozzle CSWSH CVE | Live tail authenticates via session cookie + the same gate; **no** query-string auth tokens; origin enforced by `web` middleware | tail routes share `Authorize` middleware |
| 11 | **Index/cache management left anonymous** | opcodesio gap | Index build/rebuild endpoints run under the same `Authorize` stack and are rate-limited | `IndexController` routes under the guarded group |
| 12 | **`unsafe-eval` / inline-script CSP break** | modern hardening | Vue **runtime-only** build, boot data delivered as inert JSON (`<script type="application/json">`), no inline executable script | `web-ui` build (CSP assertion in frontend build) |
| 13 | **Resource exhaustion** — tail/search flood | DoS | Per-action rate limiters (`loglens-search`/`tail`/`index`) + optional IP allowlist | `LogLensServiceProvider::registerRateLimiters`, `IpAllowlist` |
| 14 | **Encoding-based 500s** — malformed UTF-8 crashing the renderer | robustness | `JSON_INVALID_UTF8_SUBSTITUTE` everywhere; substitution never throws | `ParsingTest::test_utf8_resilience_does_not_throw`, `BrowsingApiTest::test_unicode_file_never_500s` |

## Pre-release verification procedure

1. Run the full suite on every CI leg: `vendor/bin/phpunit` (Laravel 8–13 × PHP 8.0–8.5, ubuntu + windows).
2. Run the Octane leg: `vendor/bin/phpunit --group octane` (no cross-request state leak).
3. Manually re-confirm rows 1, 6, 7, 9, 10 against a fresh production-mode install.
4. Confirm the default config ships redaction **enabled**, `read_only` **false**, and no `viewLogLens` gate (so default-deny holds in production).

A support claim without a green CI leg should not ship.
