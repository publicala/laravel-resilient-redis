<?php

declare(strict_types=1);

use Publicala\ResilientRedis\CommandForwarder;

arch('source classes are final')
    ->expect('Publicala\ResilientRedis')
    ->classes()
    ->toBeFinal();

arch('no debug helpers in source')
    ->expect('Publicala\ResilientRedis')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'ds']);

arch('source declares strict types')
    ->expect('Publicala\ResilientRedis')
    ->toUseStrictTypes()
    ->ignoring(CommandForwarder::class);

arch('the command forwarder calls phpredis in coercive mode')
    ->expect(CommandForwarder::class)
    ->not->toUseStrictTypes();

arch('namespaced test support declares strict types')
    ->expect('Publicala\ResilientRedis\Tests')
    ->toUseStrictTypes();
