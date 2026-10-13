<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\NativeRecoveryPhpRedisConnection;
use Publicala\ResilientRedis\ResilientPhpRedisConnector;
use Publicala\ResilientRedis\Tests\Support\FakeRedisServer;

/**
 * Laravel's extended recovering connection to the server, named "cache", that records what it reports.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<object>  $reported
 */
function nativeRecoveryConnection(FakeRedisServer $server, array &$reported = [], array $overrides = []): NativeRecoveryPhpRedisConnection
{
    $connection = (new ResilientPhpRedisConnector(
        recoverLostConnections: true,
        report: function (object $event) use (&$reported): void {
            $reported[] = $event;
        },
    ))->connect($server->config($overrides), []);

    assert($connection instanceof NativeRecoveryPhpRedisConnection);

    $connection->setName('cache');

    return $connection;
}

function laravelLacksLostConnectionHooks(): bool
{
    return ! ResilientPhpRedisConnector::frameworkHasLostConnectionHooks();
}

it('runs a read once more after a protocol error, closes the socket that went out of step, and reports the recovery', function (bool $persistent): void {
    $server = FakeRedisServer::start(['garbage-after:2', 'serve']);
    $reported = [];
    $connection = nativeRecoveryConnection($server, $reported, ['persistent' => $persistent, 'persistent_id' => Str::random()]);
    $connection->set('greeting', 'hello');

    expect($connection->get('greeting'))->toBe('hello')
        ->and($server->commands())->toBe(['SELECT 0', 'SET greeting hello', 'GET greeting', 'SELECT 0', 'GET greeting'])
        ->and($server->hungUp(1))->toBeTrue()
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(CommandRecovered::class)
        ->and($reported[0]->context())->toMatchArray(['connection' => 'cache', 'command' => 'get'])
        ->and($reported[0]->reason)->toBe('protocol_error');
})->with([
    'connect' => false,
    'pconnect' => true,
])->skip(laravelLacksLostConnectionHooks(...), 'Laravel before 13.33 recovers through the package connection.');

it('reports a recovery from a lost connection Laravel already recognizes', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $reported = [];
    $connection = nativeRecoveryConnection($server, $reported);
    $connection->set('greeting', 'hello');

    expect($connection->get('greeting'))->toBe('hello')
        ->and($server->acceptedConnections())->toBe(2)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->reason)->toBeIn(['read_error', 'connection_lost']);
})->skip(laravelLacksLostConnectionHooks(...), 'Laravel before 13.33 recovers through the package connection.');

it('rebuilds the client but reports nothing when Laravel does not run the command again', function (): void {
    $server = FakeRedisServer::start(['garbage-after:1', 'serve']);
    $reported = [];
    $connection = nativeRecoveryConnection($server, $reported);

    expect(fn (): Redis|int|false => $connection->incr('attempts'))->toThrow(RedisException::class, 'protocol error');

    expect($server->commands())->toBe(['SELECT 0', 'INCR attempts', 'SELECT 0'])
        ->and($connection->ping())->toBeTrue()
        ->and($reported)->toBeEmpty();
})->skip(laravelLacksLostConnectionHooks(...), 'Laravel before 13.33 recovers through the package connection.');

it('leaves a failure that is not a lost connection to the caller', function (): void {
    $server = FakeRedisServer::start(['error-after:1:LOADING Valkey is loading the dataset in memory']);
    $reported = [];
    $connection = nativeRecoveryConnection($server, $reported);

    expect(fn () => $connection->get('greeting'))->toThrow(RedisException::class, 'LOADING');

    expect($server->acceptedConnections())->toBe(1)
        ->and($reported)->toBeEmpty();
})->skip(laravelLacksLostConnectionHooks(...), 'Laravel before 13.33 recovers through the package connection.');
