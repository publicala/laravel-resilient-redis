<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Publicala\ResilientRedis\Events\ConnectRecovered;
use Publicala\ResilientRedis\ResilientPhpRedisConnection;
use Publicala\ResilientRedis\ResilientPhpRedisConnector;
use Publicala\ResilientRedis\Tests\Support\FakeRedisServer;

/**
 * @param  list<object>  $reported
 */
function connectorReportingTo(array &$reported, ?Closure $clock = null, bool $recoverLostConnections = false): ResilientPhpRedisConnector
{
    return new ResilientPhpRedisConnector(
        recoverLostConnections: $recoverLostConnections,
        report: function (object $event) use (&$reported): void {
            $reported[] = $event;
        },
        clock: $clock,
    );
}

it('retries a connect whose pooled socket the server dropped, and reports the recovery', function (bool $persistent): void {
    $server = FakeRedisServer::start(['close', 'serve']);
    $reported = [];

    $connection = connectorReportingTo($reported)->connect($server->config(['persistent' => $persistent, 'persistent_id' => Str::random()]), []);

    expect($connection->ping())->toBeTrue()
        ->and($server->acceptedConnections())->toBe(2);
    Sleep::assertSleptTimes(1);
    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(ConnectRecovered::class)
        ->and($reported[0]->context())->toMatchArray([
            'host' => '127.0.0.1',
            'port' => $server->port,
            'database' => 0,
            'persistent' => $persistent,
            'attempts' => 2,
            'reason' => 'read_error',
        ]);
})->with([
    'connect' => false,
    'pconnect' => true,
]);

it('retries a TLS handshake that the server resets', function (): void {
    $server = FakeRedisServer::start(['reset-on-first-read', 'serve'], tls: true);
    $reported = [];

    $connection = connectorReportingTo($reported)->connect($server->config(tls: true), []);

    expect($connection->ping())->toBeTrue()
        ->and($server->acceptedConnections())->toBe(2)
        ->and($reported[0]->reason)->toBe('connection_reset');
});

it('gives up after three attempts with a jittered wait between them, and reports nothing', function (): void {
    $server = FakeRedisServer::start(['close']);
    $reported = [];
    $waits = [];
    Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$waits): void {
        $waits[] = $duration->totalMilliseconds;
    });

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(), []))
        ->toThrow(RedisException::class, 'read error on connection');

    expect($server->acceptedConnections())->toBe(3)
        ->and($waits)->toHaveCount(2)
        ->and($waits[0])->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(200)
        ->and($waits[1])->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(400)
        ->and($reported)->toBeEmpty();
});

it('makes the number of attempts the connection allows', function (int $retries, int $attempts): void {
    $server = FakeRedisServer::start(['close']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(['connect_retries' => $retries]), []))
        ->toThrow(RedisException::class);

    expect($server->acceptedConnections())->toBe($attempts);
})->with([
    'no retries' => [0, 1],
    'four retries' => [4, 5],
]);

it('takes the retries from redis.options over the connection itself', function (): void {
    $server = FakeRedisServer::start(['close']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(['connect_retries' => 4]), ['connect_retries' => 0]))
        ->toThrow(RedisException::class);

    expect($server->acceptedConnections())->toBe(1);
});

it('does not retry a TLS handshake that times out, because the server is overloaded', function (): void {
    $server = FakeRedisServer::start(['stall']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(['timeout' => 0.2], tls: true), []))
        ->toThrow(ErrorException::class, 'Handshake timed out');

    expect($server->acceptedConnections())->toBe(1);
    Sleep::assertNeverSlept();
});

it('does not retry a reply that stalled for a second', function (): void {
    $server = FakeRedisServer::start(['stall']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(['read_timeout' => 1.05]), []))
        ->toThrow(RedisException::class, 'read error on connection');

    expect($server->acceptedConnections())->toBe(1);
    Sleep::assertNeverSlept();
});

it('does not retry a server that rejects the credentials', function (): void {
    $server = FakeRedisServer::start(['error:WRONGPASS invalid username-password pair or user is disabled.']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(['password' => 'secret']), []))
        ->toThrow(RedisException::class, 'WRONGPASS');

    expect($server->acceptedConnections())->toBe(1);
});

it('does not retry a server that does not speak TLS', function (): void {
    $server = FakeRedisServer::start(['reply-garbage']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(tls: true), []))
        ->toThrow(ErrorException::class, 'wrong version number');

    expect($server->acceptedConnections())->toBe(1);
});

it('does not retry a failure from outside phpredis', function (): void {
    $server = FakeRedisServer::start(['serve']);
    $reported = [];

    expect(fn (): PhpRedisConnection => connectorReportingTo($reported)->connect($server->config(['backoff_algorithm' => 'sideways']), []))
        ->toThrow(InvalidArgumentException::class);

    Sleep::assertNeverSlept();
})->skip(fn (): bool => ! (new ReflectionClass(PhpRedisConnector::class))->hasMethod('parseBackoffAlgorithm'), 'Laravel 11 passes the algorithm to phpredis unchecked.');

it('starts no attempt once the retry window has closed during the wait', function (): void {
    $server = FakeRedisServer::start(['close']);
    $reported = [];
    $now = 0.0;
    Sleep::whenFakingSleep(function () use (&$now): void {
        $now += 6000;
    });

    $connector = connectorReportingTo($reported, clock: function () use (&$now): float {
        return $now;
    });

    expect(fn (): PhpRedisConnection => $connector->connect($server->config(), []))->toThrow(RedisException::class);

    expect($server->acceptedConnections())->toBe(1);
    Sleep::assertSleptTimes(1);
});

it('does not wait for an attempt that would start after the window closes', function (): void {
    $server = FakeRedisServer::start(['close']);
    $reported = [];
    $now = 0.0;
    Sleep::whenFakingSleep(function () use (&$now): void {
        $now += 5900;
    });

    $connector = connectorReportingTo($reported, clock: function () use (&$now): float {
        return $now;
    });

    expect(fn (): PhpRedisConnection => $connector->connect($server->config(), []))->toThrow(RedisException::class);

    expect($server->acceptedConnections())->toBe(2);
    Sleep::assertSleptTimes(1);
});

it('connects with the timeout the connection sets, or two seconds when it sets none', function (?float $timeout, float $effective): void {
    $server = FakeRedisServer::start(['serve']);
    $reported = [];
    $config = $server->config();
    unset($config['timeout']);

    $connection = connectorReportingTo($reported)->connect($timeout === null ? $config : [...$config, 'timeout' => $timeout], []);

    expect($connection->client()->getTimeout())->toBe($effective);
})->with([
    'no timeout' => [null, 2.0],
    'half a second' => [0.5, 0.5],
    'zero, which phpredis reads as default_socket_timeout' => [0.0, 0.0],
]);

it('treats a null timeout as none', function (): void {
    $server = FakeRedisServer::start(['serve']);
    $reported = [];

    $connection = connectorReportingTo($reported)->connect($server->config(['timeout' => null]), []);

    expect($connection->client()->getTimeout())->toBe(2.0);
});

it('keeps the phpredis retry options the connection configures', function (): void {
    $server = FakeRedisServer::start(['serve']);
    $reported = [];

    $client = connectorReportingTo($reported)->connect($server->config([
        'max_retries' => 0,
        'backoff_algorithm' => Redis::BACKOFF_ALGORITHM_CONSTANT,
        'backoff_base' => 25,
        'backoff_cap' => 50,
    ]), [])->client();

    expect($client->getOption(Redis::OPT_MAX_RETRIES))->toBe(0)
        ->and($client->getOption(Redis::OPT_BACKOFF_ALGORITHM))->toBe(Redis::BACKOFF_ALGORITHM_CONSTANT)
        ->and($client->getOption(Redis::OPT_BACKOFF_BASE))->toBe(25)
        ->and($client->getOption(Redis::OPT_BACKOFF_CAP))->toBe(50);
});

it('returns the stock connection unless asked to recover lost connections', function (bool $recover, string $class): void {
    $server = FakeRedisServer::start(['serve']);
    $reported = [];

    $connection = connectorReportingTo($reported, recoverLostConnections: $recover)->connect($server->config(['prefix' => 'acme:']), []);

    expect($connection)->toBeInstanceOf(PhpRedisConnection::class)
        ->and($connection::class)->toBe($class)
        ->and($connection->client()->getOption(Redis::OPT_PREFIX))->toBe('acme:');
})->with([
    'stock' => [false, PhpRedisConnection::class],
    'recovering' => [true, ResilientPhpRedisConnection::class],
]);

it('reports whether this Laravel version recovers lost connections on its own', function (): void {
    expect(ResilientPhpRedisConnector::frameworkRecoversLostConnections())
        ->toBe(method_exists(PhpRedisConnection::class, 'isRetryable'));
});
