# Changelog

All notable changes to `publicala/laravel-resilient-redis`.

The top `## ` header is always the most recent version (`## vX.Y.Z`, exact), and that header is the source of truth the release workflow reads. Adding a new top block to this file is what cuts a release.

## v3.1.1

### Fixed

- **A raw `SELECT` carries over a rebuild.** phpredis does not see a `SELECT` sent through `executeRaw()`, so it kept reporting the old database, and both the package's rebuild and phpredis's own reconnect returned to it: a replayed read answered from the wrong database. The connection now selects the same database through phpredis after a raw `SELECT` succeeds.
- **A transaction is not opened again without the WATCH it may depend on.** A pipeline callback receives phpredis's own client, so a `WATCH` sent there never passed through the connection, and a transaction whose `MULTI` lost the connection was opened again on a rebuilt client without it, overwriting a value another client had changed. After a pipeline, such a transaction now fails until a transaction or a rebuild has ended every watch. A `WATCH`, `UNWATCH`, `MULTI`, `EXEC` or `DISCARD` sent through `executeRaw()` is tracked like its phpredis method, so a read queued in a raw transaction or sent under a raw watch is not replayed.
- **A temporary DNS failure is recognized on musl.** musl (Alpine) reports `EAI_AGAIN` as `Try again`, not as glibc's `Temporary failure in name resolution`, so the connect retry gave up on it. The text counts only at the end of a `getaddrinfo` message.
- **More reads are replayed.** The range reads (`ZREVRANGE`, `ZRANGEBYSCORE`, `ZREVRANGEBYSCORE`, `ZRANGEBYLEX`, `ZREVRANGEBYLEX`), the samples (`ZRANDMEMBER`, `HRANDFIELD`), the set algebra that stores nothing (`ZDIFF`, `ZINTER`, `ZUNION`, `ZINTERCARD`, `SINTERCARD`), `LCS`, `OBJECT`, `EXPIRETIME`, `PEXPIRETIME`, the read-only `GEORADIUS` and `SORT` variants, `ECHO` and `LASTSAVE` now run once more after a lost connection, like the reads already listed.
- **`Event::fake()` sees recovery events.** The events were dispatched through the dispatcher resolved when the first Redis connection was set up, so a dispatcher swapped in later, as `Event::fake()` does, never received them. The dispatcher is now resolved for each event.
- **A server error reply is never read as a lost connection.** phpredis throws an error reply with the server's own text for any code but `ERR`, so a module or a script whose error mentioned `socket`, `went away` or `Connection lost` rebuilt the client and replayed the read. A `RedisException` that starts with an all-caps error code is now left alone, except `READONLY`.
- **A rebuild leaves a shared persistent stream open.** With `redis.pconnect.pooling_enabled` off, PHP shares one persistent stream between the clients that name the same `persistent_id`. A rebuild after a `READONLY` reply or a protocol error closed that stream under the other clients, and a persistent rebuild would have taken the same stream back. The stream now stays open, and the rebuild connects on a socket of its own.

## v3.0.3

### Changed

- **PHPStan below 2.3.6 in the development toolchain.** PHPStan 2.3.6 removed `AnalyserResult::getCollectedData()`, which `pest-plugin-type-coverage` 5.1.0 calls, so the type coverage step errors before it measures. The constraint is a development dependency, so applications that install the package resolve nothing new. Lift it once the plugin supports 2.3.6.

## v3.0.2

### Fixed

- **A transaction inside a pipeline runs.** Laravel's Redis queue pushes a bulk of jobs that way (`Queue::bulk()`, so every `Bus::batch()`), and phpredis returns the client from that inner `EXEC`, which threw a `TypeError`.
- **A transaction that fails inside a pipeline keeps the WATCH open.** Its `DISCARD` only drops what the client queued and never reaches the server, so a later read is not replayed.

## v3.0.1

### Fixed

- **A command accepts an int where phpredis types a string**, as it does on Laravel's own connection. The package called phpredis from a file with strict types, so Horizon trimming failed jobs (`zremrangebyscore` with an int score) threw a `TypeError` on every supervisor loop.

## v3.0.0

### Changed

- **The package's own lost-connection recovery runs on every Laravel version**, in place of Laravel's own from 13.28. It replays reads only, so on 13.28 and later a plain `SET` is no longer replayed and `command_retries` is not used while `recover_lost_connections` is on. Turn the switch off to keep Laravel's recovery. The reads it runs again are now reported on 13.28 and later too.

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
