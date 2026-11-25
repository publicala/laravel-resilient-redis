<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Publicala\ResilientRedis\ResilientPhpRedisConnector;
use Redis as PhpRedis;

beforeEach(function (): void {
    config(['cache.default' => 'redis', 'cache.stores.redis.connection' => 'default', 'cache.stores.redis.lock_connection' => 'default']);
    Redis::connection()->flushdb();
});

it('runs commands, pipelines, transactions and scripts against a real server', function (): void {
    $redis = Redis::connection();

    $redis->set('greeting', 'hello');

    $piped = $redis->pipeline(fn ($pipeline) => $pipeline->get('greeting'));
    $transacted = $redis->transaction(fn ($transaction) => $transaction->incr('visits'));

    expect($redis->get('greeting'))->toBe('hello')
        ->and($redis->mget(['greeting', 'missing']))->toBe(['hello', null])
        ->and($piped)->toBe(['hello'])
        ->and($transacted)->toBe([1])
        ->and($redis->eval('return redis.call("get", KEYS[1])', 1, 'greeting'))->toBe('hello');
});

it('runs a transaction inside a pipeline, as the Redis queue does to push a bulk of jobs', function (): void {
    $redis = Redis::connection();

    $results = $redis->pipeline(fn () => $redis->transaction(function ($transaction): void {
        $transaction->incr('visits');
        $transaction->set('greeting', 'hello');
    }));

    Queue::connection('redis')->bulk(['SendInvoice', 'SendReceipt'], ['account' => 'Acme Books']);

    expect($results)->toBe([[1, true]])
        ->and($redis->get('greeting'))->toBe('hello')
        ->and(Queue::connection('redis')->size())->toBe(2);
});

it('answers a WATCH a pipeline sends with true, which tells the connection the watch may still be open', function (bool $literal): void {
    $redis = Redis::connection();
    $redis->client()->setOption(PhpRedis::OPT_REPLY_LITERAL, $literal);

    $replies = $redis->pipeline(function (PhpRedis $pipeline): void {
        $pipeline->watch('balance');
        $pipeline->rawCommand('WATCH', 'balance');
    });

    expect($replies)->toBe([true, true]);
})->with([
    'status replies as booleans' => false,
    'status replies as text' => true,
]);

it('passes an int where phpredis types a string, as Laravel does', function (): void {
    $redis = Redis::connection();
    $redis->zadd('failed_jobs', -200, 'old', -50, 'recent');

    expect($redis->zremrangebyscore('failed_jobs', -100, '+inf'))->toBe(1)
        ->and($redis->zcount('failed_jobs', -300, 0))->toBe(1)
        ->and($redis->zrangebyscore('failed_jobs', -300, 0))->toBe(['old']);
});

it('serves the cache store and its locks', function (): void {
    Cache::put('profile', ['name' => 'Acme Books'], 60);

    $lock = Cache::lock('import', 10);

    expect(Cache::get('profile'))->toBe(['name' => 'Acme Books'])
        ->and($lock->get())->toBeTrue()
        ->and(Cache::lock('import', 10)->get())->toBeFalse()
        ->and($lock->release())->toBeTrue();
});

it('keeps reading after the server kills the connection', function (): void {
    $redis = Redis::connection();
    $redis->set('greeting', 'hello');

    $id = $redis->executeRaw(['CLIENT', 'ID']);
    Redis::connection('admin')->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]);

    expect($redis->get('greeting'))->toBe('hello')
        ->and($redis->executeRaw(['CLIENT', 'ID']))->not->toBe($id);
});

it('reuses one pooled socket across persistent connections', function (): void {
    $first = Redis::connection('pooled')->executeRaw(['CLIENT', 'ID']);
    Redis::purge('pooled');
    $second = Redis::connection('pooled')->executeRaw(['CLIENT', 'ID']);

    expect($second)->toBe($first);
});

it('keeps the database a raw SELECT chose after the server kills the connection', function (): void {
    $redis = Redis::connection();
    $redis->set('greeting', 'from database 0');
    $redis->executeRaw(['SELECT', '3']);
    $redis->set('greeting', 'from database 3');

    $id = $redis->executeRaw(['CLIENT', 'ID']);
    Redis::connection('admin')->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]);

    try {
        expect($redis->get('greeting'))->toBe('from database 3');
    } finally {
        $redis->select(3);
        $redis->flushdb();
    }
});

it('keeps the database a raw SELECT in a pipeline chose after the server kills the connection', function (): void {
    $redis = Redis::connection();
    $redis->set('greeting', 'from database 0');
    $redis->pipeline(function () use ($redis): void {
        $redis->executeRaw(['SELECT', '3']);
    });
    $redis->set('greeting', 'from database 3');

    $id = $redis->executeRaw(['CLIENT', 'ID']);
    Redis::connection('admin')->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]);

    try {
        expect($redis->get('greeting'))->toBe('from database 3');
    } finally {
        $redis->select(3);
        $redis->flushdb();
    }
});

it('keeps the database a raw SELECT inside a raw transaction chose after the server kills the connection', function (): void {
    $redis = Redis::connection();
    $redis->set('greeting', 'from database 0');
    $redis->executeRaw(['MULTI']);
    $redis->executeRaw(['SELECT', '3']);
    $redis->executeRaw(['EXEC']);
    $redis->set('greeting', 'from database 3');

    $id = $redis->executeRaw(['CLIENT', 'ID']);
    Redis::connection('admin')->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]);

    try {
        expect($redis->get('greeting'))->toBe('from database 3');
    } finally {
        $redis->select(3);
        $redis->flushdb();
    }
});

it('waits for the raw EXEC before asking which database a raw SELECT a pipeline queued before a raw MULTI chose', function (): void {
    $redis = Redis::connection();
    $redis->set('greeting', 'from database 0');
    $redis->pipeline(function () use ($redis): void {
        $redis->executeRaw(['SELECT', '3']);
        $redis->executeRaw(['MULTI']);
    });
    $redis->executeRaw(['PING']);

    try {
        // CLIENT INFO sent inside the transaction would have queued, and EXEC would answer one reply more.
        expect($redis->executeRaw(['EXEC']))->toBe([true]);

        $redis->set('greeting', 'from database 3');
        $id = $redis->executeRaw(['CLIENT', 'ID']);
        Redis::connection('admin')->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]);

        expect($redis->get('greeting'))->toBe('from database 3');
    } finally {
        $redis->select(3);
        $redis->flushdb();
    }
});

it('gives a pooled socket it reuses the read timeout the connection sets, or PHP default_socket_timeout when it sets none', function (array $readTimeout): void {
    $connector = new ResilientPhpRedisConnector(recoverLostConnections: true);
    $config = [...config('database.redis.default'), 'persistent' => true, 'persistent_id' => Str::random(), ...$readTimeout];
    $defaultSocketTimeout = ini_set('default_socket_timeout', '3');

    try {
        // The first connection returns its socket to the pool when it is destroyed at the end of the statement.
        $connector->connect($config, [])->ping();

        // A blocking pop holds the reply for two seconds, longer than the second a pooled socket's liveness check waits.
        expect($connector->connect($config, [])->blpop(['missing'], 2))->toBeNull();
    } finally {
        ini_set('default_socket_timeout', (string) $defaultSocketTimeout);
    }
})->with([
    'a read timeout' => [['read_timeout' => 3.0]],
    'no read timeout' => [[]],
]);

it('keeps an unlimited read timeout on a pooled socket it reuses', function (): void {
    $connector = new ResilientPhpRedisConnector(recoverLostConnections: true);
    $config = [...config('database.redis.default'), 'persistent' => true, 'persistent_id' => Str::random(), 'read_timeout' => -1];
    $defaultSocketTimeout = ini_set('default_socket_timeout', '1');

    try {
        $connector->connect($config, [])->ping();

        // A blocking pop holds the reply for two seconds, longer than PHP's default_socket_timeout here.
        expect($connector->connect($config, [])->blpop(['missing'], 2))->toBeNull();
    } finally {
        ini_set('default_socket_timeout', (string) $defaultSocketTimeout);
    }
});
