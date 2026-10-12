<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Events;

/**
 * A read that lost its connection and succeeded on a rebuilt client.
 */
final readonly class CommandRecovered
{
    public function __construct(
        public string $connection,
        public string $command,
        public int $milliseconds,
        public string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return get_object_vars($this);
    }
}
