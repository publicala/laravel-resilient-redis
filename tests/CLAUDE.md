# tests/

Pest `it()` only. Every file declares `declare(strict_types=1)`.

## Two servers

- **`Support/FakeRedisServer`** starts `Support/fake-redis-server.php` in a child process: a scripted RESP server whose plan sets what each accepted connection does (`serve`, `serve:N`, `close`, `reset`, `stall`, `error:MESSAGE`, TLS with `tls: true`, ...; the script's header lists every step). Use it for any failure a healthy server cannot produce on demand. `commands()` and `acceptedConnections()` read its log.
- **A real Valkey or Redis on port 6390** (`REDIS_TEST_PORT` overrides it) backs `TestCase`'s connections, for behavior a script cannot fake. Locally: `docker run --rm -d -p 6390:6379 valkey/valkey:9.0.0`.

`fake-redis-server.php` runs without an autoloader and parses bytes, so Pint and Rector skip it. Keep it on PHP's byte functions.

## Traps

- An arrow function captures by value. A closure built inside `fn () => ...` that uses `&$var` binds to the copy, so build connectors and callbacks outside `expect(fn () => ...)`.
- phpredis pools persistent sockets by host and port, and fake servers reuse random ports. Give every persistent connection its own `persistent_id`.
- phpredis reconnects an idle socket it finds closed (`max_retries`, 10 by default), and `close()` on a failed client reconnects it first. Count accepted connections, not assumptions.
- `throw_unless()` and `throw_if()` evaluate their message arguments eagerly.
- `->not->toThrow(Throwable::class)` passes even when the callback throws: Pest reads an interface name as message text. To assert that nothing escapes, call the code directly and assert its effect.

## Running

```bash
composer test:unit      # the suite
composer test:types     # PHPStan level 8
composer test:lint      # Pint and Rector, check only
composer test           # everything, with 100% type and line coverage (needs PCOV or Xdebug)
```
