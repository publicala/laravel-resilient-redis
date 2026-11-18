<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Dispatches recovery events without letting a listener change the outcome
 * of the Redis call that recovered. A listener that fails is reported, a
 * report that fails is dropped, and a listener that recovers Redis
 * itself does not dispatch again. The dispatcher is resolved for each
 * event, so one swapped in later (Event::fake()) receives it.
 */
final class RecoveryEvents
{
    private bool $dispatching = false;

    public function __construct(private readonly Container $container) {}

    public function dispatch(object $event): void
    {
        if ($this->dispatching) {
            return;
        }

        $this->dispatching = true;

        try {
            $this->container->make(Dispatcher::class)->dispatch($event);
        } catch (Throwable $throwable) {
            rescue(fn () => report($throwable), report: false);
        } finally {
            $this->dispatching = false;
        }
    }
}
