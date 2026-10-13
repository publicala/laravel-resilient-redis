<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\Events\ConnectRecovered;
use Publicala\ResilientRedis\NativeRecoveryPhpRedisConnection;
use Publicala\ResilientRedis\RecoveryEvents;
use Publicala\ResilientRedis\ResilientPhpRedisConnection;
use Publicala\ResilientRedis\ResilientPhpRedisConnector;
use Publicala\ResilientRedis\ResilientRedisServiceProvider;

function phpRedisConnectorOf(RedisManager $redis): object
{
    return (fn (): object => $this->connector())->call($redis);
}

function recoveryOptionOf(ResilientPhpRedisConnector $connector): bool
{
    return (fn (): bool => $this->recoverLostConnections)->call($connector);
}

it('connects every phpredis connection through the resilient connector', function (): void {
    $connector = phpRedisConnectorOf(resolve(Factory::class));

    expect($connector)->toBeInstanceOf(ResilientPhpRedisConnector::class)
        ->and(recoveryOptionOf($connector))->toBeTrue();
});

it('opens connections that recover a lost connection on every Laravel version', function (): void {
    $connection = resolve(Factory::class)->connection();

    expect($connection::class)->toBe(ResilientPhpRedisConnector::frameworkHasLostConnectionHooks() ? NativeRecoveryPhpRedisConnection::class : ResilientPhpRedisConnection::class);
});

it('leaves lost connections to Laravel when the recovery is turned off', function (): void {
    config(['resilient-redis.recover_lost_connections' => false]);
    app()->forgetInstance('redis');

    expect(recoveryOptionOf(phpRedisConnectorOf(resolve(Factory::class))))->toBeFalse();
});

it('uses the stock connector when the package is turned off', function (): void {
    config(['resilient-redis.enabled' => false]);
    app()->forgetInstance('redis');

    expect(phpRedisConnectorOf(resolve(Factory::class))::class)->toBe(PhpRedisConnector::class);
});

it('reads its switches with defaults when the config file is missing from a cached config', function (): void {
    config(['resilient-redis' => null]);
    app()->forgetInstance('redis');

    expect(recoveryOptionOf(phpRedisConnectorOf(resolve(Factory::class))))->toBeTrue();
});

it('casts operator spellings of the switches', function (string $value, bool $enabled): void {
    putenv("RESILIENT_REDIS_ENABLED={$value}");

    try {
        expect((require __DIR__.'/../../config/resilient-redis.php')['enabled'])->toBe($enabled);
    } finally {
        putenv('RESILIENT_REDIS_ENABLED');
    }
})->with([
    'true' => ['true', true],
    'on' => ['on', true],
    'false' => ['false', false],
    'off' => ['off', false],
    'no' => ['no', false],
]);

it('publishes its config file', function (): void {
    $paths = ServiceProvider::pathsToPublish(ResilientRedisServiceProvider::class, 'resilient-redis-config');

    expect(array_values($paths))->toBe([config_path('resilient-redis.php')])
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/resilient-redis.php'));
});

it('logs one warning per recovered connect or read', function (object $event, string $message): void {
    Log::spy();

    resolve(RecoveryEvents::class)->dispatch($event);

    Log::shouldHaveReceived('warning')->once()->with($message, $event->context());
})->with([
    'a connect' => [new ConnectRecovered('127.0.0.1', 6379, 0, true, 2, 140, 'read_error'), 'redis.connect.recovered'],
    'a read' => [new CommandRecovered('cache', 'get', 12, 'connection_reset'), 'redis.command.recovered'],
]);

it('does not log recoveries when told not to', function (): void {
    Log::spy();
    config(['resilient-redis.log_recoveries' => false]);
    resolve(Dispatcher::class)->forget(ConnectRecovered::class);
    (new ResilientRedisServiceProvider(app()))->boot();

    resolve(RecoveryEvents::class)->dispatch(new ConnectRecovered('127.0.0.1', 6379, 0, false, 2, 140, 'read_error'));

    Log::shouldNotHaveReceived('warning');
});

it('reports a listener that fails instead of failing the Redis call that recovered', function (): void {
    $reported = [];
    resolve(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): bool {
        $reported[] = $e->getMessage();

        return false;
    });
    resolve(Dispatcher::class)->listen(ConnectRecovered::class, fn () => throw new RuntimeException('The metrics backend is down.'));

    resolve(RecoveryEvents::class)->dispatch(new ConnectRecovered('127.0.0.1', 6379, 0, false, 2, 140, 'read_error'));

    expect($reported)->toBe(['The metrics backend is down.']);
});

it('drops the report when reporting the failing listener fails too', function (): void {
    $listened = false;
    resolve(ExceptionHandler::class)->reportable(fn (Throwable $e) => throw new RuntimeException('The log sink is down.'));
    resolve(Dispatcher::class)->listen(ConnectRecovered::class, function () use (&$listened): never {
        $listened = true;

        throw new RuntimeException('The metrics backend is down.');
    });

    resolve(RecoveryEvents::class)->dispatch(new ConnectRecovered('127.0.0.1', 6379, 0, false, 2, 140, 'read_error'));

    expect($listened)->toBeTrue();
});

it('does not dispatch again from inside a listener', function (): void {
    $dispatched = 0;
    resolve(Dispatcher::class)->listen(CommandRecovered::class, function (CommandRecovered $event) use (&$dispatched): void {
        $dispatched++;
        resolve(RecoveryEvents::class)->dispatch($event);
    });

    resolve(RecoveryEvents::class)->dispatch(new CommandRecovered('cache', 'get', 12, 'connection_reset'));

    expect($dispatched)->toBe(1);
});
