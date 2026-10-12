<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Throwable;

/**
 * Decides whether a failed connect gets another attempt, and when.
 */
final readonly class ConnectRetryPolicy
{
    public const int DEFAULT_RETRIES = 2;

    public const float DEFAULT_WINDOW_SECONDS = 6.0;

    /**
     * Fast transient failures end within milliseconds, even on a saturated host. One
     * that took longer is a stall, which another attempt would only repeat.
     */
    public const float FAST_FAILURE_MILLISECONDS = 1000.0;

    private const int BACKOFF_BASE_MILLISECONDS = 100;

    private const int BACKOFF_CAP_MILLISECONDS = 1000;

    public function __construct(
        public int $retries,
        public float $windowMilliseconds,
    ) {}

    /**
     * Read the policy from the merged connection config, where redis.options and a connection's own options override its top-level keys.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            retries: max(0, (int) ($config['connect_retries'] ?? self::DEFAULT_RETRIES)),
            windowMilliseconds: max(0.0, (float) ($config['connect_retry_window'] ?? self::DEFAULT_WINDOW_SECONDS)) * 1000,
        );
    }

    /**
     * The reason to retry a failed attempt, or null when the failure is final.
     */
    public function retryReason(int $attempt, Throwable $e, float $attemptMilliseconds): ?string
    {
        if ($attempt > $this->retries) {
            return null;
        }

        $reason = Failure::retryableConnectReason($e);

        // A resolver that times out takes its own timeout to fail, so a DNS failure is retried at any duration.
        if ($reason === Failure::TEMPORARY_DNS || $attemptMilliseconds < self::FAST_FAILURE_MILLISECONDS) {
            return $reason;
        }

        return null;
    }

    /**
     * A random wait within an exponentially growing window, so workers that lost the server together do not reconnect in lockstep.
     */
    public function backoffMilliseconds(int $attempt): int
    {
        return random_int(
            self::BACKOFF_BASE_MILLISECONDS,
            min(self::BACKOFF_CAP_MILLISECONDS, self::BACKOFF_BASE_MILLISECONDS * 2 ** $attempt),
        );
    }

    /**
     * Whether an attempt may start this many milliseconds after the first one began.
     */
    public function allowsAttemptAt(float $elapsedMilliseconds): bool
    {
        return $elapsedMilliseconds < $this->windowMilliseconds;
    }
}
