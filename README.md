# Laravel Resilient Redis

[![Latest Version on Packagist](https://img.shields.io/packagist/v/publicala/laravel-resilient-redis.svg)](https://packagist.org/packages/publicala/laravel-resilient-redis)
[![CI](https://github.com/publicala/laravel-resilient-redis/actions/workflows/ci.yml/badge.svg)](https://github.com/publicala/laravel-resilient-redis/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/publicala/laravel-resilient-redis.svg)](LICENSE)

A managed Redis or Valkey drops connections now and then: a pooled socket goes stale, a TLS handshake gets reset, a failover moves the primary. Laravel retries a read that loses its connection mid-command (on 13.28 and later), but it never retries the **connect** itself, so the request, job or Horizon worker that happened to open the connection fails.

This package retries a phpredis connect that fails fast and transiently, and gives Laravel 11 and 12 the lost-connection recovery that Laravel 13 ships. Install it and every phpredis connection uses it. No code changes.

```bash
composer require publicala/laravel-resilient-redis
```

## What it does

### Retries a connect that fails fast

Every phpredis connect goes through the package: the first one, and each reconnect after a connection loses the server. A failed connect gets up to 2 more attempts, after a random wait of 100–200 ms, then 100–400 ms, when the failure is one that ends within milliseconds and clears by itself:

| Failure | Typical cause |
| --- | --- |
| `read error on connection`, `Connection lost`, `went away` during AUTH or SELECT | The server dropped a pooled (persistent) socket |
| `SSL: Connection reset by peer`, `SSL: Broken pipe` | A TLS frontend reset the handshake |
| `Connection refused`, a missing Unix socket | The server is restarting |
| `Temporary failure in name resolution` | The resolver failed for now |

In production, every connect that failed on a pooled socket the server had dropped succeeded on its second attempt, 110–170 ms later.

The package **never retries** a failure that a retry cannot fix, or one that a retry would make worse:

- **A timeout.** A connect or TLS handshake that timed out means the server or the network is overloaded. Another attempt doubles the wait and adds a handshake to the pile.
- **A stall.** A failure that took a second or more (a server that accepts the socket and never answers) would only stall again. A DNS failure is the exception: the resolver takes its own timeout to fail, so it gets a retry at any duration.
- **A configuration error.** Wrong credentials (`WRONGPASS`, `NOAUTH`), a certificate that does not verify, a server that does not speak TLS, a host name that does not exist.
- **Anything else.** A failure the package cannot name is rethrown as is.

No attempt starts more than 6 seconds after the first one began, so a resolver timeout gets one retry and a run of slow failures stops.

### Gives every connection a connect timeout

A connection with no `timeout` gets one of 2 seconds. Without it, a connect to an unreachable host waits for PHP's `default_socket_timeout`, 60 seconds by default, and holds the worker that long. A `timeout` you set, `0` included, is kept.

### Recovers a lost connection on Laravel 11 and 12

Laravel 13.28 and later rebuild a phpredis client that loses its connection mid-command and run a read once more. On earlier versions the package does the same, more conservatively:

- **Reads only.** A read (`GET`, `HGETALL`, `ZRANGE` and the like) runs once more on the rebuilt client. A write (`SET`, `INCR`, `EVAL`, a queue push) is never replayed, because the server may have run it before the reply was lost. The client is still rebuilt, so the next command works.
- **Never inside a WATCH, a MULTI or a pipeline.** The watch dies with the old socket, and a queued command would run outside its batch, so the error is rethrown after the rebuild.
- **The rebuilt client keeps its state.** The prefix, serializer and compression a caller set at runtime, and a database chosen with `SELECT`, carry over. `withoutSerializationOrCompression()` stays raw across a rebuild.
- **The original error wins.** When the rebuild itself fails, the command's own error is rethrown, since for a write it is the one that says the server may have applied it.

TLS resets that phpredis reports as a PHP warning (`Redis::get(): SSL: Connection reset by peer`) count as a lost connection, as they do on Laravel 13.31 and later.

On Laravel 13.28 and later the package leaves this to the framework.

### Reports each recovery

Each recovered connect dispatches `Publicala\ResilientRedis\Events\ConnectRecovered`, and each recovered read dispatches `Publicala\ResilientRedis\Events\CommandRecovered`. By default the package also logs one warning per recovery:

```text
redis.connect.recovered {"host":"tls://cache.example.com","port":6379,"database":0,"persistent":true,"attempts":2,"milliseconds":143,"reason":"read_error"}
redis.command.recovered {"connection":"cache","command":"get","milliseconds":12,"reason":"connection_reset"}
```

The events carry a reason code, never the exception text, arguments or credentials. A failing listener is reported and never changes the outcome of the Redis call.

## Configuration

The defaults need no configuration. To tune the connect retry, set these keys on a connection in `config/database.php`:

```php
'redis' => [
    'client' => 'phpredis',

    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        // ...
        'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
        'connect_retries' => 2,          // attempts after the first one, 0 turns the retry off
        'connect_retry_window' => 6.0,   // seconds after the first attempt within which a retry may start
    ],
],
```

Laravel merges a connection's keys with `redis.options` and then with the connection's own `options`, in that order, so a key in `redis.options` applies to every connection and overrides the connection's top-level value. To override it for one connection, set it in that connection's `options`.

The package's own switches live in `config/resilient-redis.php`. Each one reads an environment variable, so you rarely need to publish the file:

| Key | Environment variable | Default | Effect |
| --- | --- | --- | --- |
| `enabled` | `RESILIENT_REDIS_ENABLED` | `true` | When false, phpredis uses Laravel's stock connector, as if the package were not installed |
| `recover_lost_connections` | `RESILIENT_REDIS_RECOVER_LOST_CONNECTIONS` | `true` | The lost-connection recovery on Laravel versions before 13.28 |
| `log_recoveries` | `RESILIENT_REDIS_LOG_RECOVERIES` | `true` | One warning per recovery |

```bash
php artisan vendor:publish --tag=resilient-redis-config
```

A changed switch takes effect once the config cache is rebuilt and long-running workers (queue, Horizon, Octane) restart.

### Recommended connection settings

- **`timeout`**: keep it short (2 seconds). It bounds the TCP connect and the TLS handshake. It does not bound name resolution or the replies to AUTH and SELECT.
- **`read_timeout`**: set one (for example 5 seconds) when a request has a hard deadline, such as Octane's `max_execution_time`, and the connection runs no blocking commands (`BLPOP` with a timeout, a queue with `block_for`). Without it, a reply that never comes waits for `default_socket_timeout`.
- **`persistent`**: reusing sockets across requests saves a TLS handshake per request. A pooled socket the server dropped is the failure this package retries best.

## Limits

- **phpredis only.** Predis connections are left alone.
- **Redis Cluster** connections use Laravel's stock path.
- **Outside the recovery:** `scan` and its variants, `subscribe`, calls made on `client()` directly, and a pipeline or transaction opened without a callback (it returns the raw client). `executeRaw()` rebuilds the client but is never replayed.
- **Laravel 13.28 to 13.32** recover reads but not pipelines and transactions, and 13.28 to 13.30 do not treat a TLS reset warning as a lost connection. Upgrade to 13.33 or later for both.
- **phpredis retries on its own.** It reconnects an idle socket it finds closed before sending a command (`max_retries`, 10 by default). A `max_retries` you configure is applied after the connect, which Laravel 11 does not do.
- **A failed `SELECT` or `AUTH` that phpredis reports as `false` instead of an exception** still goes unnoticed, as it does in Laravel.

## Compatibility

| | Supported |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 11, 12, 13 |
| phpredis | 6.0 to 6.3 |

## Testing

The suite drives real phpredis clients against a scripted RESP server it starts itself, and against a real Valkey or Redis on port 6390:

```bash
docker run --rm -d -p 6390:6379 valkey/valkey:8.1
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE). The lost-connection recovery adapts code from the Laravel framework, also MIT.
