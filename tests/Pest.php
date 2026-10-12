<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Publicala\ResilientRedis\Tests\TestCase;

pest()
    ->extend(TestCase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Sleep::fake();
    })
    ->in(__DIR__);
