# laravel-resilient-redis

A public Composer package: phpredis connect retry for Laravel 11 to 13, plus the lost-connection recovery Laravel 13.28 ships, backported to earlier versions. The README is the contract. Every behavior it states has a test.

## Public repository

Everything here is readable outside the company. Never name a customer, an internal service codename, an internal host or a private repository, in code, tests, docs, commits or PR text. Use these placeholders:

| Use | Placeholder |
| --- | --- |
| Hosts | `cache.example.com`, `127.0.0.1` |
| Connections | `default`, `cache`, `queue` |
| Tenant or customer | `Acme`, `Acme Books` |

## Rules the code depends on

- `Failure` names every failure the package acts on. A failure it cannot name is rethrown, never retried.
- A write is never replayed. Only the reads in `ResilientPhpRedisConnection::RETRYABLE_READS` run twice.
- Version-dependent behavior is detected with reflection on the installed Laravel classes, never with a version string.
- Defaults live in code. The config file only feeds them, because a cached config may predate a key.

## Pointers

- Tests: see `tests/CLAUDE.md`.
- Releases are CHANGELOG-driven: a new top `## vX.Y.Z` block on `main` cuts the tag and the GitHub release, and Packagist picks up the tag.
