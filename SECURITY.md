# Security Policy

## Supported versions

Security fixes are released against the latest `3.x` minor version. Upgrade to it to stay covered — under Semantic Versioning a minor release never breaks a `3.x` integration.

| Version | Supported |
|---|---|
| `3.x` (latest minor) | Yes |
| `3.x` (older minor) | No — upgrade to the latest `3.x` |
| `2.x` | No — end of life since `3.0.0` |
| `1.x` | No — end of life since `2.0.0`; see [Upgrading from 1.x](https://docs.pushery.com/webhooks-for-laravel/guides/upgrading-from-1x) |
| `0.x` | No — end of life since `1.0.0` |

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Report them privately through GitHub's [private vulnerability reporting](https://github.com/pushery/webhooks-for-laravel/security/advisories/new) (the "Report a vulnerability" button on the repository's Security tab). Include:

- a description of the vulnerability and its impact,
- the steps to reproduce it,
- the affected version(s),
- and, if possible, a suggested fix.

You can expect an acknowledgment within **3 business days** and an assessment of the report, including a remediation timeline, within **10 business days**. We will keep you informed throughout and credit you in the release notes once a fix ships, unless you prefer to remain anonymous.

## Dependency updates

This package declares version ranges, not a lock file: the versions of its dependencies in your application come from your own `composer.lock`. Keep them current with `composer update`, and run `composer audit` to check them against the known advisories.

This repository is a read-only mirror of the released tree. Releases arrive as tags, and it carries no update pull requests.
