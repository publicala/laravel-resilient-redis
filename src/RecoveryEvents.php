<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Dispatches recovery events without letting a listener change the outcome
 * of the Redis call that recovered. A listener that fails is reported,
 * and one that recovers Redis itself does not dispatch again.
 */
final class RecoveryEvents
{
    private bool $dispatching = false;

    public function __construct(private readonly Dispatcher $events) {}

    public function dispatch(object $event): void
    {
        if ($this->dispatching) {
            return;
        }

        $this->dispatching = true;

        try {
            rescue(fn () => $this->events->dispatch($event));
        } finally {
            $this->dispatching = false;
        }
    }
}
