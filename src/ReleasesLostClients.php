<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Redis;

/**
 * Closes a client that lost its connection before it is replaced.
 */
trait ReleasesLostClients
{
    /**
     * A client that still holds its socket (a READONLY reply after a failover, a reply
     * stream out of step) is closed, since phpredis returns the socket of a persistent
     * client it destroys to the pool, where the next connect would reuse it.
     * phpredis reconnects a failed client just to close it, so one without a
     * socket is left alone.
     */
    private function releaseLostClient(Redis $client): void
    {
        if ($client->isConnected()) {
            rescue(fn (): bool => $client->close(), null, false);
        }
    }
}
