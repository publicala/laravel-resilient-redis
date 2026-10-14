<?php

namespace Publicala\ResilientRedis;

use Redis;

/**
 * Sends a command to phpredis from a file without strict types.
 *
 * PHP checks each argument in the calling file's typing mode, and
 * Laravel's own connection calls phpredis in coercive mode, so the
 * callers pass an int where phpredis types a string.
 */
final class CommandForwarder
{
    /**
     * @param  array<int, mixed>  $parameters
     */
    public static function forward(Redis $client, string $method, array $parameters): mixed
    {
        return $client->{$method}(...$parameters);
    }
}
