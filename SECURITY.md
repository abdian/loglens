# Security Policy

LogLens reads and exposes application log files through the browser, so security
is a first-class concern. Thank you for helping keep it and its users safe.

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |
| < 1.0   | ❌        |

Security fixes are released for the latest `1.x` minor.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately through GitHub's built-in advisory flow:

1. Go to <https://github.com/abdian/loglens/security/advisories/new>.
2. Describe the issue, the affected version(s), and reproduction steps.
3. Include a proof of concept if you have one.

You will normally get an initial acknowledgement within a few days. Once a fix is
ready, a patched release is published and the advisory is disclosed, crediting
you unless you ask to stay anonymous.

## Scope

In scope:

- Path traversal / sandbox escape outside the configured log roots.
- Authorization bypass of the `viewLogLens` gate or per-action gates.
- Secret-redaction bypass that leaks credentials to the browser.
- Stored or reflected XSS from rendered log content.
- Signed-download replay or user-binding bypass.

Out of scope:

- Issues that require an attacker who already has a valid `viewLogLens`
  authorization and is acting within their granted permissions.
- Denial of service from deliberately pointing LogLens at pathological files
  outside the documented limits.
- Vulnerabilities in Laravel, PHP, or third-party dependencies themselves
  (report those upstream).

## Hardening guide

See [docs/security.md](docs/security.md) for the full production hardening
checklist, including the gate definition, IP allowlisting, rate limits, and
the redaction configuration.
