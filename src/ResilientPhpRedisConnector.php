<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;
use Publicala\ResilientRedis\Events\ConnectRecovered;
use Redis;
use ReflectionClass;
use Throwable;

/**
 * A phpredis connector that retries a connect which fails fast, the way a
 * dropped pooled socket or a reset TLS handshake does. createClient()
 * makes every connect: the first one, and each reconnect after a
 * connection loses the server.
 */
final class ResilientPhpRedisConnector extends PhpRedisConnector
{
    /**
     * The connect timeout, in seconds, for a connection that sets none. Without
     * one a connect to an unreachable host waits for default_socket_timeout.
     */
    public const float DEFAULT_TIMEOUT = 2.0;

    /**
     * The client the attempt in progress connects, which Laravel's createClient() does not hand back when it throws.
     */
    private ?Redis $connecting = null;

    /**
     * @param  bool  $recoverLostConnections  Return connections that rebuild a lost client and retry reads, in place of Laravel's own recovery.
     * @param  (Closure(object): void)|null  $report
     * @param  (Closure(): float)|null  $clock  Milliseconds on a monotonic clock.
     */
    public function __construct(
        private readonly bool $recoverLostConnections = false,
        private readonly ?Closure $report = null,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Whether this Laravel version rebuilds a lost connection and retries reads on its own (13.28 and later),
     * which the package's recovery replaces while it is on.
     */
    public static function frameworkRecoversLostConnections(): bool
    {
        return (new ReflectionClass(PhpRedisConnection::class))->hasMethod('isRetryable');
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     */
    public function connect(array $config, array $options): PhpRedisConnection
    {
        if (! $this->recoverLostConnections) {
            return parent::connect($config, $options);
        }

        $formattedOptions = Arr::pull($config, 'options', []);

        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }

        $connector = fn (): Redis => $this->createClient(array_merge($config, $options, $formattedOptions));

        return new ResilientPhpRedisConnection($connector(), $connector, $config, $this->report);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createClient(array $config): Redis
    {
        $config['timeout'] ??= self::DEFAULT_TIMEOUT;
        $policy = ConnectRetryPolicy::fromConfig($config);
        $firstAttemptAt = $this->now();
        $lastFailure = null;
        $lastReason = null;
        $attemptConfig = $config;

        for ($attempt = 1; ; $attempt++) {
            $attemptStartedAt = $this->now();

            // A sleep can overrun its delay, so every retry checks the window at the moment it starts.
            if ($lastFailure instanceof Throwable) {
                throw_unless($policy->allowsAttemptAt($attemptStartedAt - $firstAttemptAt), $lastFailure);
            }

            try {
                $client = parent::createClient($attemptConfig);
            } catch (Throwable $e) {
                $failedAfter = $this->now() - $attemptStartedAt;
                $this->closeFailedClient();

                $reason = $policy->retryReason($attempt, $e, $failedAfter);
                $delay = $reason === null ? null : $policy->backoffMilliseconds($attempt);

                throw_if($delay === null || ! $policy->allowsAttemptAt($this->now() - $firstAttemptAt + $delay), $e);

                Sleep::for($delay)->milliseconds();

                $lastFailure = $e;
                $lastReason = $reason;

                // The pool hands out its oldest socket first, and a server that dropped one silently usually dropped
                // the others with it, so the retry opens a socket of its own. It closes when the client is destroyed.
                if ($reason === Failure::STALE_POOLED_SOCKET) {
                    $attemptConfig['persistent'] = false;
                }

                continue;
            } finally {
                // The connector outlives its clients, and holding one would keep a pooled socket from its pool.
                $this->connecting = null;
            }

            $this->reapplyNativeRetryOptions($client, $config);

            if ($lastReason !== null && $this->report instanceof Closure) {
                ($this->report)(new ConnectRecovered(
                    host: (string) $config['host'],
                    port: (int) $config['port'],
                    database: isset($config['database']) ? (int) $config['database'] : null,
                    persistent: (bool) ($config['persistent'] ?? false),
                    attempts: $attempt,
                    milliseconds: (int) round($this->now() - $firstAttemptAt),
                    reason: $lastReason,
                ));
            }

            return $client;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function establishConnection(mixed $client, array $config): void
    {
        $this->connecting = $client;

        parent::establishConnection($client, $config);
    }

    /**
     * Close the socket a failed attempt holds. A pooled socket that fails its liveness check makes phpredis
     * open a replacement inside the same pconnect(), and a persistent client returns its socket to the pool
     * when it is destroyed, so leaving it open would add a socket to the pool on every recovery.
     * phpredis reconnects a failed client just to close it, so one without a socket is left alone.
     */
    private function closeFailedClient(): void
    {
        $client = $this->connecting;

        if ($client instanceof Redis && $client->isConnected()) {
            rescue(fn (): bool => $client->close(), null, false);
        }
    }

    /**
     * Laravel 11 sets these before connecting, and phpredis 6 resets them when it opens the socket.
     *
     * @param  array<string, mixed>  $config
     */
    private function reapplyNativeRetryOptions(Redis $client, array $config): void
    {
        $options = [
            'max_retries' => Redis::OPT_MAX_RETRIES,
            'backoff_algorithm' => Redis::OPT_BACKOFF_ALGORITHM,
            'backoff_base' => Redis::OPT_BACKOFF_BASE,
            'backoff_cap' => Redis::OPT_BACKOFF_CAP,
        ];

        foreach (Arr::only($config, array_keys($options)) as $key => $value) {
            // Laravel 11 passes the configured algorithm through as is.
            if ($key === 'backoff_algorithm' && (new ReflectionClass(parent::class))->hasMethod('parseBackoffAlgorithm')) {
                $value = $this->parseBackoffAlgorithm($value);
            }

            $client->setOption($options[$key], $value);
        }
    }

    private function now(): float
    {
        return $this->clock instanceof Closure ? ($this->clock)() : hrtime(true) / 1e6;
    }
}
