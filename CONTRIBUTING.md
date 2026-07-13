# Contributing

Thanks for taking the time. Feedback, bug reports and feature ideas are genuinely
welcome — here is how this repository works, so you know exactly where your
contribution lands.

## How this repository works

This repository is a **published mirror**. The package is developed in a private
upstream repository, and every release replaces this tree wholesale with the
sanitized public build (source, config, migrations, routes, views, translations and
these documents — the development harness stays upstream).

Two consequences worth knowing before you start:

- **Pull requests cannot be merged here.** The next release would overwrite them.
  That is a property of the mirror, not a judgement on the change.
- **Issues are the real channel** — and they are read. A bug report or a feature
  request here is what drives the upstream backlog.

## Reporting a bug

Open an issue with the bug template and include:

- the package version, the PHP version and the Laravel version,
- which layers are switched on (`server`, `platform`, `client`, `dashboard`) and any
  non-default `config/webhooks.php` values that matter,
- what you expected, what happened, and the smallest reproduction you can manage —
  a failing snippet beats a description,
- the full exception and stack trace, if there was one.

**Never report a security vulnerability in a public issue.** Use the private channel
described in [SECURITY.md](SECURITY.md).

## Proposing a feature

Open an issue with the feature template and describe the problem before the
solution: what you are trying to do, why the current API cannot do it, and what you
would expect the smallest version of the feature to look like. Because every layer of
this package is config-gated, it helps to say which layer the feature belongs to.

## Sending a patch

If you already have the fix, include it in the issue — a diff, a patch, or a link to
a branch on your fork. It is applied upstream with the same review as any internal
change, and you are credited in the [changelog](CHANGELOG.md) entry that ships it. If
you open a pull request instead, it is read as a patch and then closed with a link to
the release that carries the change; nothing is lost, it simply cannot land through
this repository's git history.

## The bar a change has to clear

Every change — internal or contributed — passes the same gates upstream before it can
ship. They are listed here so you know what the code you are reading is held to, and
what a patch will be measured against:

| Gate | Standard |
|---|---|
| Code style | Laravel Pint, zero diffs. |
| Refactoring | Rector, dry-run clean. |
| Static analysis | Larastan at `max`, no errors, no baseline. |
| Types | 100% type coverage. |
| Tests | [Pest](https://pestphp.com) against a real PostgreSQL, 100% line coverage, plus mutation testing and a real-browser end-to-end suite. |
| Public surface | Every user-visible string translated (English and German), the public tree free of internal references, the `CHANGELOG.md` updated. |

A behavior change without a test does not ship, and a config key that nothing reads
does not ship either — so a patch that adds one should wire it and prove it.

### What those gates run against

The harness itself stays upstream (this tree carries the package, not the test suite),
but the environment it needs is worth knowing before you write a patch — it is what your
change will be measured in, and it is what a fork has to stand up:

- **PHP 8.4+** with `ext-curl`, `ext-json` and `ext-sodium`.
- **A real PostgreSQL 16+ server.** There is no SQLite fallback: the delivery log uses
  `jsonb`, GIN and partial indexes, declarative range partitioning and a materialized
  view, and every migration refuses outright to run on another driver. The suite connects
  to `127.0.0.1:5432`, database `webhooks_for_laravel_test`, user `postgres`, no password —
  each of which is overridable with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and
  `DB_PASSWORD`.
- **Node 22+ and a Chromium** (`npx playwright install chromium`) for the real-browser
  end-to-end suite, which drives the dashboard and the self-service portal for real.

## License

By contributing you agree that your contribution is licensed under the MIT License,
the same as the rest of the package.
