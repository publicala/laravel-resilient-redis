<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Publicala\ResilientRedis\Failure;
use Publicala\ResilientRedis\Tests\TestCase;

pest()
    ->extend(TestCase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Sleep::fake();
    })
    ->in(__DIR__);

// phpredis reports a socket the server closed as "read error on connection" or as
// "Connection lost", depending on whether it notices before or after it writes.
expect()->extend('toThrowLostConnection', fn () => $this->toThrow(
    fn (RedisException $e) => expect(Failure::lostConnectionReason($e))->not->toBeNull(),
));

/**
 * The exception as if the named calls had raised it, innermost first, which is how a failure tells the pooled-socket liveness check apart from AUTH or SELECT.
 * A frame is a phpredis method (`pconnect`) or a method of another class (`App\Cache\Pool::pconnect`), and no frames leave an empty trace.
 *
 * @template T of Throwable
 *
 * @param  T  $e
 * @return T
 */
function raisedBy(Throwable $e, string ...$frames): Throwable
{
    (new ReflectionProperty($e instanceof Exception ? Exception::class : Error::class, 'trace'))->setValue($e, array_map(
        function (string $frame): array {
            [$class, $function] = str_contains($frame, '::') ? explode('::', $frame, 2) : [Redis::class, $frame];

            return ['class' => $class, 'type' => '->', 'function' => $function];
        },
        $frames,
    ));

    return $e;
}
