<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\Events\ConnectRecovered;
use Publicala\ResilientRedis\Listeners\LogRecovery;

final class ResilientRedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/resilient-redis.php', 'resilient-redis');

        $this->app->singleton(RecoveryEvents::class);

        // Registered as the manager resolves, so the first connection any provider opens already uses the connector.
        $this->callAfterResolving('redis', function (RedisManager $redis): void {
            $config = $this->app->make(Repository::class);

            if (! $config->get('resilient-redis.enabled', true)) {
                return;
            }

            $recoverLostConnections = $config->get('resilient-redis.recover_lost_connections', true)
                && ! ResilientPhpRedisConnector::frameworkRecoversLostConnections();
            $events = $this->app->make(RecoveryEvents::class);

            $redis->extend('phpredis', fn (): ResilientPhpRedisConnector => new ResilientPhpRedisConnector(
                recoverLostConnections: $recoverLostConnections,
                report: $events->dispatch(...),
            ));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/resilient-redis.php' => config_path('resilient-redis.php'),
        ], 'resilient-redis-config');

        if ($this->app->make(Repository::class)->get('resilient-redis.log_recoveries', true)) {
            $this->app->make(Dispatcher::class)->listen([ConnectRecovered::class, CommandRecovered::class], LogRecovery::class);
        }
    }
}
