<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\ResilientPhpRedisConnection;
use Publicala\ResilientRedis\ResilientPhpRedisConnector;
use Publicala\ResilientRedis\Tests\Support\FakeRedisServer;

/**
 * A recovering connection to the server, named "cache", that records what it reports.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<object>  $reported
 */
function recoveringConnection(FakeRedisServer $server, array &$reported = [], array $overrides = []): ResilientPhpRedisConnection
{
    $connection = (new ResilientPhpRedisConnector(
        recoverLostConnections: true,
        report: function (object $event) use (&$reported): void {
            $reported[] = $event;
        },
    ))->connect($server->config($overrides), []);

    $connection->setName('cache');
    $connection->setEventDispatcher(resolve(Dispatcher::class));

    return $connection;
}

it('runs a read once more on a rebuilt client when the server drops the connection before replying', function (bool $persistent): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $reported = [];
    $connection = recoveringConnection($server, $reported, ['persistent' => $persistent, 'persistent_id' => Str::random()]);
    $connection->set('greeting', 'hello');

    expect($connection->get('greeting'))->toBe('hello')
        ->and($server->acceptedConnections())->toBe(2)
        ->and($server->commands())->toBe(['SELECT 0', 'SET greeting hello', 'GET greeting', 'SELECT 0', 'GET greeting'])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(CommandRecovered::class)
        ->and($reported[0]->context())->toMatchArray(['connection' => 'cache', 'command' => 'get'])
        ->and($reported[0]->reason)->toBeIn(['read_error', 'connection_lost']);
})->with([
    'connect' => false,
    'pconnect' => true,
]);

it('rebuilds the client but never replays a write, because the server may already have run it', function (Closure $write, string $sent): void {
    $server = FakeRedisServer::start(['serve:1', 'serve']);
    $reported = [];
    $connection = recoveringConnection($server, $reported);

    expect(fn () => $write($connection))->toThrow(RedisException::class);

    expect($server->commands())->toBe(['SELECT 0', $sent, 'SELECT 0'])
        ->and($connection->ping())->toBeTrue()
        ->and($reported)->toBeEmpty();
})->with([
    'SET' => [fn (ResilientPhpRedisConnection $redis) => $redis->set('job', 'payload'), 'SET job payload'],
    'MSET' => [fn (ResilientPhpRedisConnection $redis): Redis|bool => $redis->mset(['job' => 'payload']), 'MSET job payload'],
    'HMSET' => [fn (ResilientPhpRedisConnection $redis) => $redis->hmset('job', ['id' => '1']), 'HMSET job id 1'],
    'INCR' => [fn (ResilientPhpRedisConnection $redis): Redis|int|false => $redis->incr('attempts'), 'INCR attempts'],
    'RPUSH' => [fn (ResilientPhpRedisConnection $redis): Redis|int|false => $redis->rpush('queue', 'job'), 'RPUSH queue job'],
    'EVAL' => [fn (ResilientPhpRedisConnection $redis) => $redis->eval('return 1', 0), 'EVAL "return 1" 0'],
]);

it('rebuilds the client again and gives up when the replayed read loses its connection too', function (): void {
    $server = FakeRedisServer::start(['serve:1', 'serve:1', 'serve']);
    $reported = [];
    $connection = recoveringConnection($server, $reported);

    expect(fn () => $connection->get('greeting'))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'GET greeting', 'SELECT 0', 'GET greeting', 'SELECT 0'])
        ->and($connection->ping())->toBeTrue()
        ->and($reported)->toBeEmpty();
});

it('leaves the client alone when the command failed for another reason', function (): void {
    $server = FakeRedisServer::start(['error-after:1:LOADING Valkey is loading the dataset in memory']);
    $connection = recoveringConnection($server);

    expect(fn () => $connection->get('greeting'))->toThrow(RedisException::class, 'LOADING');

    expect($server->acceptedConnections())->toBe(1);
});

it('rethrows the command failure when the rebuild fails, since a lost write reply matters more than the reconnect', function (): void {
    $server = FakeRedisServer::start(['serve:1']);
    $client = new Redis;
    $client->connect('127.0.0.1', $server->port, 1.0);
    $client->select(0);

    $connection = new ResilientPhpRedisConnection($client, fn () => throw new RuntimeException('Valkey is unreachable.'), []);

    expect(fn () => $connection->set('job', 'payload'))->toThrowLostConnection();
});

it('rethrows a lost connection as is when no connector can rebuild the client', function (): void {
    $server = FakeRedisServer::start(['serve:1']);
    $client = new Redis;
    $client->connect('127.0.0.1', $server->port, 1.0);
    $client->select(0);

    $connection = new ResilientPhpRedisConnection($client, null, []);

    expect(fn () => $connection->get('greeting'))->toThrowLostConnection();

    expect($connection->client())->toBe($client)
        ->and($server->commands())->toBe(['SELECT 0', 'GET greeting']);
});

it('never replays a read while a WATCH is open, because the watch died with the old socket', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $connection = recoveringConnection($server);
    $connection->watch('balance');

    expect(fn () => $connection->get('balance'))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'GET balance', 'SELECT 0']);
});

it('stops a watched transaction that loses its connection before MULTI, without running the callback', function (): void {
    $server = FakeRedisServer::start(['serve-then-drop:2', 'serve']);
    $connection = recoveringConnection($server);
    $connection->watch('balance');
    Sleep::usleep(150_000);
    $ran = false;
    $callback = function () use (&$ran): void {
        $ran = true;
    };

    expect(fn (): Redis|array|false => $connection->transaction($callback))->toThrow(RedisException::class);

    expect($ran)->toBeFalse()
        ->and($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'SELECT 0']);
});

it('runs a transaction that is not watching once more after MULTI loses its connection', function (): void {
    $server = FakeRedisServer::start(['serve:1', 'serve']);
    $connection = recoveringConnection($server);

    $results = $connection->transaction(fn (Redis $transaction): Redis|string|bool => $transaction->set('job', 'payload'));

    expect($results)->toBe([true])
        ->and($server->acceptedConnections())->toBe(2)
        ->and($server->commands())->toBe(['SELECT 0', 'MULTI', 'SELECT 0', 'MULTI', 'SET job payload', 'EXEC']);
});

it('gives up on a transaction whose MULTI loses the connection twice', function (): void {
    $server = FakeRedisServer::start(['serve:1', 'serve:1', 'serve']);
    $connection = recoveringConnection($server);

    expect(fn (): Redis|array|false => $connection->transaction(fn (Redis $transaction): Redis|string|bool => $transaction->set('job', 'payload')))
        ->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'MULTI', 'SELECT 0', 'MULTI', 'SELECT 0'])
        ->and($connection->ping())->toBeTrue();
});

it('discards and rebuilds a pipeline that loses its connection, without running its callback again', function (): void {
    $server = FakeRedisServer::start(['serve:1', 'serve']);
    $connection = recoveringConnection($server);
    $runs = 0;
    $callback = function (Redis $pipeline) use (&$runs): void {
        $runs++;
        $pipeline->get('greeting');
    };

    expect(fn (): Redis|array|false => $connection->pipeline($callback))->toThrow(RedisException::class);

    expect($runs)->toBe(1)
        ->and($connection->ping())->toBeTrue()
        ->and($server->commands())->toBe(['SELECT 0', 'GET greeting', 'SELECT 0', 'PING']);
});

it('does not replay a read that a transaction queued through the connection', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $connection = recoveringConnection($server);

    expect(fn (): Redis|array|false => $connection->transaction(function () use ($connection): void {
        $connection->get('greeting');
    }))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'MULTI', 'GET greeting', 'SELECT 0'])
        ->and($connection->ping())->toBeTrue()
        ->and($server->acceptedConnections())->toBe(2);
});

it('returns the pipeline and the transaction themselves when given no callback', function (): void {
    $server = FakeRedisServer::start(['serve']);
    $connection = recoveringConnection($server);

    expect($connection->pipeline())->toBe($connection->client());
    $connection->client()->exec();
    expect($connection->transaction())->toBe($connection->client());
    $connection->client()->discard();
});

it('returns false when phpredis cannot open the batch', function (): void {
    $client = Mockery::mock(Redis::class);
    $client->shouldReceive('pipeline')->andReturnFalse();
    $client->shouldReceive('multi')->andReturnFalse();
    $connection = new ResilientPhpRedisConnection($client, null, []);

    expect($connection->pipeline(fn (): null => null))->toBeFalse()
        ->and($connection->transaction(fn (): null => null))->toBeFalse();
});

it('keeps the raw read raw when the rebuild happens inside withoutSerializationOrCompression', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $connection = recoveringConnection($server, overrides: ['serializer' => Redis::SERIALIZER_PHP]);
    $connection->withoutSerializationOrCompression(fn () => $connection->set('token', 'plain'));

    $value = $connection->withoutSerializationOrCompression(fn () => $connection->get('token'));

    expect($value)->toBe('plain')
        ->and($server->acceptedConnections())->toBe(2)
        ->and($connection->client()->getOption(Redis::OPT_SERIALIZER))->toBe(Redis::SERIALIZER_PHP);
});

it('keeps the prefix a caller set on the client when it rebuilds it', function (): void {
    $server = FakeRedisServer::start(['serve:1', 'serve']);
    $connection = recoveringConnection($server);
    $connection->client()->setOption(Redis::OPT_PREFIX, 'tenant:');

    $connection->get('greeting');

    expect($server->commands())->toBe(['SELECT 0', 'GET tenant:greeting', 'SELECT 0', 'GET tenant:greeting']);
});

it('selects again the database a caller switched to', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $connection = recoveringConnection($server);
    $connection->select(3);

    $connection->get('greeting');

    expect($server->commands())->toBe(['SELECT 0', 'SELECT 3', 'GET greeting', 'SELECT 0', 'SELECT 3', 'GET greeting']);
});

it('ignores a SELECT the server refused', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $connection = recoveringConnection($server);

    expect($connection->select(99))->toBeFalse();

    $connection->get('greeting');

    expect($server->commands())->toBe(['SELECT 0', 'SELECT 99', 'GET greeting', 'SELECT 0', 'GET greeting']);
});

it('rethrows the read failure when the rebuilt client cannot select the database again', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'error-after:1:ERR DB index is out of range']);
    $connection = recoveringConnection($server);
    $connection->select(3);

    expect(fn () => $connection->get('greeting'))->toThrowLostConnection();
});

it('forgets a SELECT queued in a transaction that never ran', function (): void {
    $server = FakeRedisServer::start(['serve:4', 'serve']);
    $connection = recoveringConnection($server);
    $callback = function () use ($connection): void {
        $connection->select(3);

        throw new RuntimeException('The order is no longer valid.');
    };

    expect(fn (): Redis|array|false => $connection->transaction($callback))->toThrow(RuntimeException::class, 'The order is no longer valid.');

    $connection->get('greeting');

    expect($server->commands())->toBe(['SELECT 0', 'MULTI', 'SELECT 3', 'DISCARD', 'GET greeting', 'SELECT 0', 'GET greeting']);
});

it('selects again the database a transaction switched to once it ran', function (): void {
    $server = FakeRedisServer::start(['serve:4', 'serve']);
    $connection = recoveringConnection($server);
    $callback = function () use ($connection): void {
        $connection->select(3);
    };

    $connection->transaction($callback);
    $connection->get('greeting');

    expect($server->commands())->toBe(['SELECT 0', 'MULTI', 'SELECT 3', 'EXEC', 'GET greeting', 'SELECT 0', 'SELECT 3', 'GET greeting']);
});

it('keeps the watch across a pipeline, so a transaction whose MULTI loses the connection does not run unwatched', function (): void {
    $server = FakeRedisServer::start(['serve:3', 'serve']);
    $connection = recoveringConnection($server);
    $connection->watch('balance');
    $connection->pipeline(fn (Redis $pipeline): Redis|false => $pipeline->get('greeting'));

    $ran = false;
    $callback = function () use (&$ran): void {
        $ran = true;
    };

    expect(fn (): Redis|array|false => $connection->transaction($callback))->toThrowLostConnection();

    expect($ran)->toBeFalse()
        ->and($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'GET greeting', 'MULTI', 'SELECT 0']);
});

it('clears the watch once a transaction runs, so a later read is replayed again', function (): void {
    $server = FakeRedisServer::start(['serve:5', 'serve']);
    $connection = recoveringConnection($server);
    $connection->watch('balance');
    $connection->transaction(fn (Redis $transaction): Redis|bool => $transaction->set('balance', '10'));

    expect($connection->get('balance'))->toBe('10')
        ->and($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'MULTI', 'SET balance 10', 'EXEC', 'GET balance', 'SELECT 0', 'GET balance']);
});

it('tracks a MULTI sent through the connection one command at a time', function (string $end, array $commands): void {
    $server = FakeRedisServer::start(['serve:4', 'serve']);
    $connection = recoveringConnection($server);
    $connection->multi();
    $connection->select(3);
    $connection->{$end}();

    $connection->get('greeting');

    expect($server->commands())->toBe($commands);
})->with([
    'run with EXEC' => ['exec', ['SELECT 0', 'MULTI', 'SELECT 3', 'EXEC', 'GET greeting', 'SELECT 0', 'SELECT 3', 'GET greeting']],
    'dropped with DISCARD' => ['discard', ['SELECT 0', 'MULTI', 'SELECT 3', 'DISCARD', 'GET greeting', 'SELECT 0', 'GET greeting']],
]);

it('selects again the database the server accepted when a transaction also queued one it refused', function (): void {
    $server = FakeRedisServer::start(['serve:5', 'serve']);
    $connection = recoveringConnection($server);
    $callback = function () use ($connection): void {
        $connection->select(3);
        $connection->select(99);
    };

    expect($connection->transaction($callback))->toBe([true, false]);

    $connection->get('greeting');

    expect(array_slice($server->commands(), -3))->toBe(['SELECT 0', 'SELECT 3', 'GET greeting']);
});

it('drops a transaction whose client was rebuilt inside its callback, keeping the watch the caller then opened', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve:2', 'serve']);
    $connection = recoveringConnection($server);
    $callback = function () use ($connection): void {
        try {
            $connection->get('greeting');
        } catch (RedisException) {
            // The rebuilt client carries on, and the old transaction is gone with its socket.
        }

        $connection->watch('balance');
    };

    expect($connection->transaction($callback))->toBeFalse()
        ->and($server->acceptedConnections())->toBe(2);

    expect(fn () => $connection->get('balance'))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'MULTI', 'GET greeting', 'SELECT 0', 'WATCH balance', 'GET balance', 'SELECT 0']);
});

it('counts a WATCH a pipeline sent through the connection once the pipeline runs', function (): void {
    $server = FakeRedisServer::start(['serve:2', 'serve']);
    $connection = recoveringConnection($server);
    $callback = function () use ($connection): void {
        $connection->watch('balance');
    };

    $connection->pipeline($callback);

    expect(fn () => $connection->get('balance'))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'GET balance', 'SELECT 0']);
});

it('treats a WATCH sent by a pipeline that failed as open', function (): void {
    $server = FakeRedisServer::start(['serve-rejecting:3:SET', 'serve']);
    $connection = recoveringConnection($server);
    $callback = function (Redis $pipeline) use ($connection): void {
        $connection->watch('balance');
        $pipeline->set('balance', '1');
    };

    expect(fn (): Redis|array|false => $connection->pipeline($callback))->toThrow(RedisException::class, 'NOPERM');
    expect(fn () => $connection->get('balance'))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'SET balance 1', 'GET balance', 'SELECT 0']);
});

it('keeps the watch open when a pipeline queued an UNWATCH the server refused', function (): void {
    $server = FakeRedisServer::start(['serve-unknown:3:UNWATCH', 'serve']);
    $connection = recoveringConnection($server);
    $connection->watch('balance');

    $callback = function () use ($connection): void {
        $connection->unwatch();
    };

    expect($connection->pipeline($callback))->toBe([false]);
    expect(fn () => $connection->get('balance'))->toThrowLostConnection();

    expect($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'UNWATCH', 'GET balance', 'SELECT 0']);
});

it('clears the watch after UNWATCH, so a later read is replayed again', function (): void {
    $server = FakeRedisServer::start(['serve:3', 'serve']);
    $connection = recoveringConnection($server);
    $connection->watch('balance');
    $connection->unwatch();

    expect($connection->get('balance'))->toBeNull()
        ->and($server->commands())->toBe(['SELECT 0', 'WATCH balance', 'UNWATCH', 'GET balance', 'SELECT 0', 'GET balance']);
});

it('dispatches each failed attempt and the successful one, outside the retry', function (): void {
    $server = FakeRedisServer::start(['serve:1', 'serve']);
    $connection = recoveringConnection($server);
    $executed = [];
    $failed = [];
    resolve(Dispatcher::class)->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$executed): void {
        $executed[] = $event->command;
    });
    resolve(Dispatcher::class)->listen(CommandFailed::class, function (CommandFailed $event) use (&$failed): void {
        $failed[] = $event->command;
    });

    $connection->get('greeting');

    expect($executed)->toBe(['get'])
        ->and($failed)->toBe(class_exists(CommandFailed::class) ? ['get'] : []);
});

it('does not run a command again when a listener of its success throws a lost-connection error', function (): void {
    $server = FakeRedisServer::start(['serve']);
    $connection = recoveringConnection($server);
    resolve(Dispatcher::class)->listen(CommandExecuted::class, function (): void {
        throw new RedisException('socket error in a listener');
    });

    expect(fn () => $connection->get('greeting'))->toThrow(RedisException::class, 'socket error in a listener');

    expect($server->commands())->toBe(['SELECT 0', 'GET greeting']);
});
