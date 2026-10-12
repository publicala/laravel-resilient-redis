<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Events;

/**
 * A phpredis connect that failed and then succeeded on a later attempt.
 */
final readonly class ConnectRecovered
{
    public function __construct(
        public string $host,
        public int $port,
        public ?int $database,
        public bool $persistent,
        public int $attempts,
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
