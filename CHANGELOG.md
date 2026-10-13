# Changelog

All notable changes to `publicala/laravel-resilient-redis`.

The top `## ` header is always the most recent version (`## vX.Y.Z`, exact), and that header is the source of truth the release workflow reads. Adding a new top block to this file is what cuts a release.

## v3.0.0

### Changed

- **The package's own lost-connection recovery runs on every Laravel version**, in place of Laravel's own from 13.28. It replays reads only, so on 13.28 and later a plain `SET` is no longer replayed and `command_retries` is not used while `recover_lost_connections` is on. Turn the switch off to keep Laravel's recovery. Every recovered command is now reported, on every version.

### Added

- **A protocol error counts as a lost connection.** phpredis raises `protocol error, got 'X' as reply type byte` when a reply stream goes out of step, typically after the connection broke mid-reply, and leaves the socket open. The client is rebuilt and a read runs once more. A connect that fails this way is not retried.

## v2.0.0

### Changed

- **Requires Laravel 11.48, 12.64 or 13.23 and later, and phpredis 6.0.1 and later.** The package supports the versions publica.la runs, so earlier releases of each are dropped. Valkey 8.1.2 and 9.0.0 are the supported servers.

## v1.0.0

### Added

- **Connect retry for phpredis.** Every connect and reconnect retries a failure that ends fast and clears by itself (a dropped pooled socket, a reset TLS handshake, a refused connection, a temporary DNS failure) up to 2 more times, with a jittered wait, while the attempt starts within 6 seconds of the first. Timeouts, stalls of a second or more, and configuration errors are never retried. `connect_retries` and `connect_retry_window` tune it per connection.
- **A 2-second connect timeout** for a connection that sets none.
- **Lost-connection recovery for Laravel before 13.28.** A command that loses its connection rebuilds the client, and a read runs once more on it. Writes are never replayed, nothing is replayed inside a WATCH, a MULTI or a pipeline, and the rebuilt client keeps its runtime prefix, serializer, compression and the database phpredis last confirmed. A transaction or pipeline whose client was rebuilt inside its callback returns false instead of reconnecting the lost client.
- **`ConnectRecovered` and `CommandRecovered` events**, with one warning logged per recovery. A listener or a reporter that fails never fails the Redis call that recovered.
- **`resilient-redis.enabled`, `recover_lost_connections` and `log_recoveries` switches**, each read from an environment variable.

## Versioning

Semver, on `## vX.Y.Z` exactly (no suffixes, no `[Unreleased]`).

- **Patch (`vX.Y.Z+1`)**: bug fixes, internal refactors, docs, dev-tooling tweaks. No behavioural change for consumers.
- **Minor (`vX.Y+1.0`)**: additive features (new config keys with safe defaults, new events). No breaking change.
- **Major (`vX+1.0.0`)**: breaking changes: removed or renamed public API or config keys, a changed default that could surprise consumers, dropped Laravel, PHP or phpredis versions.
