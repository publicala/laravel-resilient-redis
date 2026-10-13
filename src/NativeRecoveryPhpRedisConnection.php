<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Redis;
use Throwable;

/**
 * Laravel's own lost-connection recovery (13.33 and later), extended: a
 * protocol error counts as a lost connection, a lost client is closed
 * before it is replaced, and each command that recovers is reported.
 */
final class NativeRecoveryPhpRedisConnection extends PhpRedisConnection
{
    use ReleasesLostClients;

    /**
     * The last failure Laravel's retry loop classified as a lost connection, kept for the report.
     */
    private ?Throwable $lostConnection = null;

    /**
     * @param  Redis  $client
     * @param  array<string, mixed>  $config
     * @param  (Closure(object): void)|null  $report
     */
    public function __construct($client, ?callable $connector, array $config, private readonly ?Closure $report = null)
    {
        parent::__construct($client, $connector, $config);
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function command($method, array $parameters = []): mixed
    {
        $this->lostConnection = null;
        $startedAt = microtime(true);

        $result = parent::command($method, $parameters);

        $this->reportRecoveryOf($method, $startedAt);

        return $result;
    }

    protected function causedByLostConnection(Throwable $e): bool
    {
        if (Failure::lostConnectionReason($e) === null && ! parent::causedByLostConnection($e)) {
            return false;
        }

        $this->lostConnection = $e;

        return true;
    }

    protected function rebuildClient(): void
    {
        if ($this->connector !== null) {
            $this->releaseLostClient($this->client);
        }

        parent::rebuildClient();
    }

    /**
     * Report the command that just succeeded, when it did so after a lost connection.
     */
    private function reportRecoveryOf(string $method, float $startedAt): void
    {
        if (! $this->lostConnection instanceof Throwable || ! $this->report instanceof Closure) {
            return;
        }

        ($this->report)(new CommandRecovered(
            connection: (string) $this->getName(),
            command: mb_strtolower($method),
            milliseconds: (int) round((microtime(true) - $startedAt) * 1000),
            reason: Failure::lostConnectionReason($this->lostConnection) ?? 'connection_lost',
        ));
    }
}
