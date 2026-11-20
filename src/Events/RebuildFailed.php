<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Events;

/**
 * A command that lost its connection, after which the client could not be rebuilt. The command's own error is rethrown.
 */
final readonly class RebuildFailed
{
    public function __construct(
        public string $connection,
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
