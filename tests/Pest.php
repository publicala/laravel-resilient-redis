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
