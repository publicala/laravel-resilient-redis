<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use ErrorException;
use Illuminate\Support\Str;
use RedisException;
use Throwable;

/**
 * Names the phpredis failures this package recovers from.
 *
 * phpredis throws a RedisException for most socket failures, and reports
 * TLS and stream failures as PHP warnings named after the call that
 * raised them ("Redis::pconnect(): SSL: ..."), which Laravel's error
 * handler turns into an ErrorException.
 */
final class Failure
{
    public const string TEMPORARY_DNS = 'dns_temporary_failure';

    /**
     * Connect failures that end within milliseconds when a pooled socket or the server is briefly gone.
     *
     * @var array<string, string>
     */
    private const array FAST_CONNECT_FAILURES = [
        'read error on connection' => 'read_error',
        'socket error on read socket' => 'read_error',
        'went away' => 'server_went_away',
        'Connection lost' => 'connection_lost',
        'Connection reset by peer' => 'connection_reset',
        'Broken pipe' => 'broken_pipe',
        'Connection refused' => 'connection_refused',
        'No such file or directory' => 'socket_missing',
    ];

    /**
     * The command failures Laravel 13 treats as a lost connection, and a protocol
     * error, which leaves the client reading a reply stream out of step.
     *
     * @var array<string, string>
     */
    private const array LOST_CONNECTION = [
        'Connection lost' => 'connection_lost',
        'Connection reset by peer' => 'connection_reset',
        'read error on connection' => 'read_error',
        'Error while reading' => 'read_error',
        'went away' => 'server_went_away',
        'READONLY' => 'readonly_replica',
        'socket' => 'socket_error',
        'protocol error' => 'protocol_error',
    ];

    /**
     * The reason a failed connect may be retried, or null when it may not.
     *
     * A timed-out connect or TLS handshake is never retried: the server or
     * the network is struggling, and another attempt doubles the wait.
     */
    public static function retryableConnectReason(Throwable $e): ?string
    {
        if (! self::isFromPhpRedis($e) || Str::contains($e->getMessage(), 'timed out')) {
            return null;
        }

        if (Str::contains($e->getMessage(), 'Temporary failure in name resolution')) {
            return self::TEMPORARY_DNS;
        }

        return self::reasonFor($e, self::FAST_CONNECT_FAILURES);
    }

    /**
     * The reason a command failed on a lost connection, or null when the connection is intact.
     */
    public static function lostConnectionReason(Throwable $e): ?string
    {
        if (! self::isFromPhpRedis($e)) {
            return null;
        }

        return self::reasonFor($e, self::LOST_CONNECTION);
    }

    /**
     * phpredis refuses to reconnect a socket that holds a MULTI or a WATCH, because the new one would hold neither.
     */
    public static function isLostTransactionState(Throwable $e): bool
    {
        return Str::contains($e->getMessage(), 'MULTI/watching mode');
    }

    private static function isFromPhpRedis(Throwable $e): bool
    {
        return $e instanceof RedisException
            || ($e instanceof ErrorException && Str::startsWith($e->getMessage(), 'Redis::'));
    }

    /**
     * @param  array<string, string>  $reasons
     */
    private static function reasonFor(Throwable $e, array $reasons): ?string
    {
        return collect($reasons)->first(
            fn (string $reason, string $message): bool => Str::contains($e->getMessage(), $message),
        );
    }
}
