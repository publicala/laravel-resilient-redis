<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Listeners;

use Illuminate\Support\Facades\Log;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\Events\ConnectRecovered;

/**
 * Logs one warning per recovery, so a flaky network shows up before it fails a request.
 */
final class LogRecovery
{
    public function handle(ConnectRecovered|CommandRecovered $event): void
    {
        Log::warning(
            $event instanceof ConnectRecovered ? 'redis.connect.recovered' : 'redis.command.recovered',
            $event->context(),
        );
    }
}
