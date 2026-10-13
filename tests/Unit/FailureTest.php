<?php

declare(strict_types=1);

use Publicala\ResilientRedis\Failure;

it('names a connect failure that ends fast and may be retried', function (Throwable $e, string $reason): void {
    expect(Failure::retryableConnectReason($e))->toBe($reason);
})->with([
    'a pooled socket the server dropped' => [new RedisException('read error on connection to tls://cache.example.com:6379'), 'read_error'],
    'a socket error on read' => [new RedisException('socket error on read socket'), 'read_error'],
    'a server that went away' => [new RedisException('Redis server tls://cache.example.com:6379 went away'), 'server_went_away'],
    'a lost connection' => [new RedisException('Connection lost'), 'connection_lost'],
    'a TLS handshake reset' => [new ErrorException('Redis::pconnect(): SSL: Connection reset by peer'), 'connection_reset'],
    'a TLS handshake on a closed pipe' => [new ErrorException('Redis::connect(): SSL: Broken pipe'), 'broken_pipe'],
    'a refused connection' => [new RedisException('Connection refused'), 'connection_refused'],
    'a missing Unix socket' => [new RedisException('No such file or directory'), 'socket_missing'],
    'a resolver that failed for now' => [new RedisException('php_network_getaddresses: getaddrinfo for cache.example.com failed: Temporary failure in name resolution'), Failure::TEMPORARY_DNS],
]);

it('never retries a connect failure that is slow, deterministic or not from phpredis', function (Throwable $e): void {
    expect(Failure::retryableConnectReason($e))->toBeNull();
})->with([
    'a TCP connect timeout' => [new RedisException('Connection timed out')],
    'a TLS handshake timeout' => [new ErrorException('Redis::pconnect(): SSL: Handshake timed out')],
    'an OS read timeout' => [new RedisException('Operation timed out')],
    'a wrong password' => [new RedisException('WRONGPASS invalid username-password pair or user is disabled.')],
    'a missing password' => [new RedisException('NOAUTH Authentication required.')],
    'an untrusted certificate' => [new ErrorException("Redis::connect(): SSL operation failed with code 1. OpenSSL Error messages:\nerror:0A000086:SSL routines::certificate verify failed")],
    'a server that does not speak TLS' => [new ErrorException("Redis::connect(): SSL operation failed with code 1. OpenSSL Error messages:\nerror:0A00010B:SSL routines::wrong version number")],
    'a host that does not resolve' => [new RedisException('php_network_getaddresses: getaddrinfo for cache.example.com failed: Name or service not known')],
    'a server loading its dataset' => [new RedisException('LOADING Valkey is loading the dataset in memory')],
    'a reply stream out of step' => [new RedisException("protocol error, got 'X' as reply type byte\n")],
    'a warning from outside phpredis' => [new ErrorException('fsockopen(): Connection reset by peer')],
    'an exception from outside phpredis' => [new RuntimeException('Connection refused')],
]);

it('names a command failure that means a lost connection', function (Throwable $e, string $reason): void {
    expect(Failure::lostConnectionReason($e))->toBe($reason);
})->with([
    'a lost connection' => [new RedisException('Connection lost'), 'connection_lost'],
    'a lost connection holding a WATCH' => [new RedisException('Connection lost and socket is in MULTI/watching mode'), 'connection_lost'],
    'a TLS reset mid-command' => [new ErrorException('Redis::lLen(): SSL: Connection reset by peer'), 'connection_reset'],
    'a reply that never came' => [new RedisException('read error on connection to cache.example.com:6379'), 'read_error'],
    'a reply cut short' => [new RedisException('Error while reading line from the server'), 'read_error'],
    'a server that went away' => [new RedisException('Redis server went away'), 'server_went_away'],
    'a primary demoted to replica' => [new RedisException("READONLY You can't write against a read only replica."), 'readonly_replica'],
    'a socket error' => [new RedisException('socket error on read socket'), 'socket_error'],
    'a reply stream out of step' => [new RedisException("protocol error, got 'X' as reply type byte\n"), 'protocol_error'],
    'a reply stream out of step, from the multi-bulk reader' => [new RedisException("protocol error, got 'X' as reply-type byte\n"), 'protocol_error'],
]);

it('leaves a command failure that is not a lost connection alone', function (Throwable $e): void {
    expect(Failure::lostConnectionReason($e))->toBeNull();
})->with([
    'a server loading its dataset' => [new RedisException('LOADING Valkey is loading the dataset in memory')],
    'a wrong type' => [new RedisException('WRONGTYPE Operation against a key holding the wrong kind of value')],
    'a listener error that mentions a socket' => [new ErrorException('socket error in an application listener')],
    'an exception from outside phpredis' => [new RuntimeException('Connection lost')],
]);

it('recognizes the failure phpredis raises instead of reconnecting a socket that holds a WATCH', function (): void {
    expect(Failure::isLostTransactionState(new RedisException('Connection lost and socket is in MULTI/watching mode')))->toBeTrue()
        ->and(Failure::isLostTransactionState(new RedisException('Connection lost')))->toBeFalse();
});
