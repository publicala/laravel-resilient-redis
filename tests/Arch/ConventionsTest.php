<?php

declare(strict_types=1);

arch('source classes are final')
    ->expect('Publicala\ResilientRedis')
    ->classes()
    ->toBeFinal();

arch('no debug helpers in source')
    ->expect('Publicala\ResilientRedis')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'ds']);

arch('source declares strict types')
    ->expect('Publicala\ResilientRedis')
    ->toUseStrictTypes();

arch('namespaced test support declares strict types')
    ->expect('Publicala\ResilientRedis\Tests')
    ->toUseStrictTypes();
