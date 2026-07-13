# Security Policy

## Supported versions

Security fixes are released against the latest `1.x` minor version. Upgrade to it to stay covered — under Semantic Versioning a minor release never breaks a `1.x` integration.

| Version | Supported |
|---|---|
| `1.x` (latest minor) | :white_check_mark: |
| `1.x` (older minor) | :x: — upgrade to the latest `1.x` |
| `0.x` | :x: — end of life since `1.0.0` |

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Report them privately through GitHub's [private vulnerability reporting](https://github.com/pushery/webhooks-for-laravel/security/advisories/new) (the "Report a vulnerability" button on the repository's Security tab). Include:

- a description of the vulnerability and its impact,
- the steps to reproduce it,
- the affected version(s),
- and, if possible, a suggested fix.

You can expect an acknowledgment within **3 business days** and an assessment of the report, including a remediation timeline, within **10 business days**. We will keep you informed throughout and credit you in the release notes once a fix ships, unless you prefer to remain anonymous.

## Dependency updates

Dependencies are kept current automatically: [Renovate](https://docs.renovatebot.com) opens the update pull requests, and GitHub's Dependabot **alerts** flag known advisories — which Renovate turns into prioritized security updates. Every update is reviewed before it is merged.
