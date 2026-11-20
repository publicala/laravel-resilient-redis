<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Listeners;

use Illuminate\Support\Facades\Log;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\Events\ConnectFailed;
use Publicala\ResilientRedis\Events\ConnectRecovered;
use Publicala\ResilientRedis\Events\RebuildFailed;

/**
 * Logs one warning per recovery, so a flaky network shows up before it fails a request, and one per connect or rebuild that failed.
 */
final class LogRecovery
{
    public function handle(ConnectRecovered|ConnectFailed|CommandRecovered|RebuildFailed $event): void
    {
        Log::warning(match (true) {
            $event instanceof ConnectRecovered => 'redis.connect.recovered',
            $event instanceof ConnectFailed => 'redis.connect.failed',
            $event instanceof CommandRecovered => 'redis.command.recovered',
            $event instanceof RebuildFailed => 'redis.rebuild.failed',
        }, $event->context());
    }
}
