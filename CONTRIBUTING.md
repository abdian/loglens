# Contributing to LogLens

Thanks for your interest in improving LogLens! This guide covers the local setup
and the expectations for a mergeable change.

## Getting started

```bash
git clone https://github.com/abdian/loglens.git
cd loglens
composer install
npm install        # only needed if you touch the frontend
```

## Running the tests

The PHP test suite runs against an in-memory environment via Orchestra Testbench:

```bash
composer test            # phpunit
composer test:coverage   # phpunit with text coverage
```

All tests must pass before a pull request is reviewed. CI exercises every
supported Laravel × PHP combination (Laravel 8–13, PHP 8.0–8.5) plus an Octane
(Swoole) smoke run and a PHP syntax lint — please keep changes green across the
matrix, not just on your local PHP version.

## Building the frontend

The compiled SPA is committed under `public/build/` so users get a zero-build
install. If you change anything in `resources/js` or `resources/css`, rebuild and
commit the result:

```bash
npm run dev      # watch mode while developing
npm run build    # production build → public/build/
```

## Coding guidelines

- Match the style of the surrounding code; there is no separate formatter step.
- Keep the package dependency-light: runtime code depends only on
  `illuminate/contracts`. Do not add new runtime dependencies without discussion.
- No mutable static state — the package must stay Octane-safe.
- Every cross-version (Laravel 8→13) API access must be guarded.
- New behaviour needs a test. Security-relevant changes need a test that proves
  the guard, not just the happy path.

## Reporting bugs and security issues

- **Bugs and feature requests:** open a
  [GitHub issue](https://github.com/abdian/loglens/issues).
- **Security vulnerabilities:** do **not** open a public issue — follow
  [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
