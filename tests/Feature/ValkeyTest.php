<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

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
