<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Events;

/**
 * A phpredis connect that failed on its last attempt. The connect's own error is rethrown.
 */
final readonly class ConnectFailed
{
    /**
     * @param  int  $milliseconds  From the start of the first attempt to the end of the last one.
     * @param  int  $lastAttemptMilliseconds  How long the last attempt took, which a timeout reaches.
     */
    public function __construct(
        public string $host,
        public int $port,
        public ?int $database,
        public bool $persistent,
        public int $attempts,
        public int $milliseconds,
        public int $lastAttemptMilliseconds,
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
