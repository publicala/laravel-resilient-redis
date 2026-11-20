<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use RedisException;

/**
 * A transaction refused because the client was rebuilt while a WATCH it may depend on was open. The watch died with
 * the old socket, and the transaction would run without the check. Send the WATCH again and retry.
 */
final class LostWatchException extends RedisException
{
    public function __construct()
    {
        parent::__construct('The client was rebuilt while a WATCH may have been open, so the transaction would run without it. Send the WATCH again.');
    }
}
