<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Tests;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;
use Publicala\ResilientRedis\ResilientRedisServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ResilientRedisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $server = [
            'host' => '127.0.0.1',
            'port' => (int) (getenv('REDIS_TEST_PORT') ?: 6390),
            'database' => 0,
            'timeout' => 1.0,
        ];

        $app->make(Repository::class)->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => 'resilient_redis_test:'],
            'default' => $server,
            'admin' => $server,
            'pooled' => [...$server, 'persistent' => true, 'persistent_id' => 'acme'],
        ]);
    }
}
