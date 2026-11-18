<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Publicala\ResilientRedis\Events\CommandRecovered;
use Publicala\ResilientRedis\Events\RebuildFailed;
use Redis;
use Throwable;

/**
 * Recovers from a lost connection on every Laravel version, in place of
 * Laravel's own recovery from 13.28: the client is rebuilt through the
 * connector, and a read runs once more on the new one. Adapted from
 * Laravel 13's PhpRedisConnection (MIT License, Copyright (c) Taylor
 * Otwell), replaying reads only.
 */
final class ResilientPhpRedisConnection extends PhpRedisConnection
{
    /**
     * The commands that read without changing anything, so running one twice returns what running it once would.
     *
     * @var list<string>
     */
    private const array RETRYABLE_READS = [
        'bitcount', 'bitpos', 'dbsize', 'dump', 'echo', 'exists', 'expiretime', 'geodist', 'geohash', 'geopos',
        'georadius_ro', 'georadiusbymember_ro', 'geosearch', 'get', 'getbit', 'getrange', 'hexists', 'hget', 'hgetall',
        'hkeys', 'hlen', 'hmget', 'hrandfield', 'hstrlen', 'hvals', 'keys', 'lastsave', 'lcs', 'lindex', 'llen', 'lpos',
        'lrange', 'mget', 'object', 'pexpiretime', 'ping', 'pttl', 'randomkey', 'scard', 'sdiff', 'sinter',
        'sintercard', 'sismember', 'smembers', 'smismember', 'sort_ro', 'srandmember', 'strlen', 'sunion', 'time',
        'ttl', 'type', 'xinfo', 'xlen', 'xpending', 'xrange', 'xrevrange', 'zcard', 'zcount', 'zdiff', 'zinter',
        'zintercard', 'zlexcount', 'zmscore', 'zrandmember', 'zrange', 'zrangebylex', 'zrangebyscore', 'zrank',
        'zrevrange', 'zrevrangebylex', 'zrevrangebyscore', 'zrevrank', 'zscore', 'zunion',
    ];

    /**
     * The client options a caller may change at runtime, which a rebuilt client must keep.
     *
     * @var list<int>
     */
    private const array RUNTIME_OPTIONS = [
        Redis::OPT_PREFIX,
        Redis::OPT_SERIALIZER,
        Redis::OPT_COMPRESSION,
        Redis::OPT_COMPRESSION_LEVEL,
        Redis::OPT_READ_TIMEOUT,
    ];

    /**
     * How long a socket goes without a reply before a read that waits out its timeout on it is replayed. A NAT or a load
     * balancer drops an idle connection without closing it, while a socket that answered a moment ago meets a stalling server.
     */
    private const float IDLE_SOCKET_SECONDS = 5.0;

    /**
     * Whether the server holds a WATCH for this client, which a rebuilt client would not.
     */
    private bool $watching = false;

    /**
     * The database phpredis reported after the last successful command, which a rebuilt client must select too.
     */
    private ?int $selectedDatabase = null;

    /**
     * Whether the open MULTI or pipeline queued a WATCH, which may have reached the server whatever the batch's outcome.
     */
    private bool $watchQueued = false;

    /**
     * Whether a pipeline handed phpredis's client to a caller since the server last cleared every WATCH, so a WATCH may be open
     * that never passed through the connection.
     */
    private bool $watchMayBeOpen = false;

    /**
     * Whether a raw MULTI opened a transaction that phpredis does not know about, so commands only queue.
     */
    private bool $inRawTransaction = false;

    /**
     * Whether the open batch queued a raw MULTI (true) or a raw EXEC or DISCARD (false) last, which settles the raw transaction once it runs.
     */
    private ?bool $queuedRawTransaction = null;

    /**
     * Whether the open batch queued a raw SELECT, which may change the database without phpredis knowing.
     */
    private bool $rawSelectQueued = false;

    /**
     * When the server last answered on the current client, as a microtime.
     */
    private float $lastReplyAt;

    /**
     * @param  Redis  $client
     * @param  array<string, mixed>  $config
     * @param  (Closure(object): void)|null  $report
     */
    public function __construct($client, ?callable $connector, array $config, private readonly ?Closure $report = null)
    {
        parent::__construct($client, $connector, $config);

        $this->lastReplyAt = microtime(true);
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function command($method, array $parameters = []): mixed
    {
        $retries = in_array(mb_strtolower($method), self::RETRYABLE_READS, true) ? 1 : 0;
        $firstAttemptAt = microtime(true);
        $reason = null;

        while (true) {
            // A command sent inside a MULTI or a pipeline only queues, and a rebuilt client would run it outside the batch.
            $mode = $this->client->getMode();
            $batched = $mode !== Redis::ATOMIC || $this->inRawTransaction;
            $startedAt = microtime(true);

            try {
                $result = CommandForwarder::forward($this->client, $method, $parameters);
            } catch (Throwable $e) {
                // A read that waited out its timeout on a socket that answered a moment ago met a server that is stalling,
                // and running it again would double the wait. Measured before the listeners and the rebuild, whose own time
                // says nothing about the server.
                $stalled = (microtime(true) - $startedAt) * 1000 >= ConnectRetryPolicy::FAST_FAILURE_MILLISECONDS
                    && $startedAt - $this->lastReplyAt < self::IDLE_SOCKET_SECONDS;

                $this->dispatchCommandFailed($method, $parameters, $e);

                // The server ends a transaction on EXEC and DISCARD whatever they answer, an error reply phpredis throws included.
                if (mb_strtolower($method) === 'rawcommand' && $mode === Redis::ATOMIC && $this->endsRawTransaction($parameters)) {
                    // Whether a queued SELECT ran is unknown, and asking would make phpredis reconnect a failed client.
                    $this->rawSelectQueued = false;
                    $this->inRawTransaction = false;
                    $this->settleBatch(closesWatch: true);
                }

                $reason = $this->recoverFrom($e);

                throw_if($batched || $stalled || $retries-- === 0, $e);

                continue;
            }

            // A command a pipeline queued got no reply yet.
            if ($mode !== Redis::PIPELINE) {
                $this->lastReplyAt = microtime(true);
            }

            $this->trackSessionState(mb_strtolower($method), $parameters, $result, $mode);

            // Dispatched outside the catch, so a listener that throws cannot run the command again.
            $this->events?->dispatch(new CommandExecuted(
                $method, $this->parseParametersForEvent($parameters), round((microtime(true) - $startedAt) * 1000, 2), $this,
            ));

            if ($reason !== null && $this->report instanceof Closure) {
                ($this->report)(new CommandRecovered(
                    connection: (string) $this->getName(),
                    command: mb_strtolower($method),
                    milliseconds: (int) round((microtime(true) - $firstAttemptAt) * 1000),
                    reason: $reason,
                ));
            }

            return $result;
        }
    }

    /**
     * @return Redis|array<int, mixed>|false
     */
    public function pipeline(?callable $callback = null): Redis|array|false
    {
        $pipeline = $this->openBatch(fn (): Redis|false => $this->client()->pipeline(), transaction: false);

        // The caller gets phpredis's client, through the callback or as the return value, and may send a WATCH on it.
        $this->watchMayBeOpen = $this->watchMayBeOpen || $pipeline !== false;

        return $callback === null || $pipeline === false ? $pipeline : $this->executeBatch($pipeline, $callback, transaction: false);
    }

    /**
     * @return Redis|array<int, mixed>|false
     */
    public function transaction(?callable $callback = null): Redis|array|false
    {
        $transaction = $this->openBatch(fn (): Redis|false => $this->client()->multi(), transaction: true);

        return $callback === null || $transaction === false ? $transaction : $this->executeBatch($transaction, $callback, transaction: true);
    }

    public function disconnect(): void
    {
        parent::disconnect();

        // Closing the socket ended any WATCH and transaction it held.
        $this->resetSessionState();
    }

    /**
     * Run the callback with serialization and compression off, restoring them on whichever client is current when it returns.
     */
    public function withoutSerializationOrCompression(callable $callback): mixed
    {
        $serializer = $this->client->getOption(Redis::OPT_SERIALIZER);
        $compression = $this->client->getOption(Redis::OPT_COMPRESSION);

        $this->client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        $this->client->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);

        try {
            return $callback();
        } finally {
            $this->client->setOption(Redis::OPT_SERIALIZER, $serializer);
            $this->client->setOption(Redis::OPT_COMPRESSION, $compression);
        }
    }

    /**
     * The runtime options this phpredis has. phpredis declares OPT_PACK_IGNORE_NUMBERS, which changes how the serializer stores a number, from 6.2.
     *
     * @return list<int>
     */
    private function runtimeOptions(): array
    {
        $packIgnoreNumbers = Redis::class.'::OPT_PACK_IGNORE_NUMBERS';

        return defined($packIgnoreNumbers) ? [...self::RUNTIME_OPTIONS, constant($packIgnoreNumbers)] : self::RUNTIME_OPTIONS;
    }

    /**
     * Rebuild the client when the failure was a lost connection, and return why, so the caller may retry.
     *
     * A failure while a WATCH is open is rethrown after the rebuild: the
     * watch died with the old socket, and a transaction on the new one
     * would run without the check it depends on.
     *
     * @throws Throwable
     */
    private function recoverFrom(Throwable $e, bool $dependsOnWatch = false): string
    {
        $reason = Failure::lostConnectionReason($e);

        throw_if($reason === null, $e);

        $watched = $this->watching || ($dependsOnWatch && $this->watchMayBeOpen) || Failure::isLostTransactionState($e);

        $this->replaceClient($e);

        throw_if($watched, $e);

        return $reason;
    }

    /**
     * Open a pipeline or a MULTI, which sends nothing that could take effect, once more on a rebuilt client if the connection was lost.
     * A MULTI is not opened again while a WATCH may be open, since the transaction depends on it.
     *
     * @param  Closure(): (Redis|false)  $open
     *
     * @throws Throwable
     */
    private function openBatch(Closure $open, bool $transaction): Redis|false
    {
        try {
            return $open();
        } catch (Throwable $throwable) {
            $this->recoverFrom($throwable, dependsOnWatch: $transaction);
        }

        try {
            return $open();
        } catch (Throwable $throwable) {
            $this->recoverFrom($throwable);

            throw $throwable;
        }
    }

    /**
     * Run the callback against the batch and execute it, never twice.
     *
     * @return Redis|array<int, mixed>|false
     *
     * @throws Throwable
     */
    private function executeBatch(Redis $batch, callable $callback, bool $transaction): Redis|array|false
    {
        try {
            $callback($batch);

            // A rebuild inside the callback lost the batch with its socket, and phpredis would reconnect it only to fail EXEC.
            if ($batch !== $this->client) {
                return false;
            }

            $result = $batch->exec();
            $this->lastReplyAt = microtime(true);
        } catch (Throwable $throwable) {
            // A rebuild inside the callback already reset the session state, which now belongs to the new client.
            if ($batch === $this->client) {
                // Whether a queued raw SELECT ran is unknown, and asking would make phpredis reconnect a failed client.
                $this->rawSelectQueued = false;
                $this->settleBatch(closesWatch: false);
            }

            // phpredis reconnects a failed client to send DISCARD, so a client whose socket is gone is left alone.
            if ($batch->isConnected() && $batch->getMode() !== Redis::ATOMIC) {
                // Inside a pipeline, DISCARD only drops what the client queued, so a WATCH the server holds stays open.
                $sendsDiscard = $batch->getMode() === Redis::MULTI;
                $discarded = rescue(fn (): Redis|bool => $batch->discard(), false, false) === true;

                if ($discarded && $transaction && $sendsDiscard && $batch === $this->client) {
                    $this->watching = false;
                }
            }

            // The callback may already have rebuilt the client through command().
            if ($batch === $this->client && Failure::lostConnectionReason($throwable) !== null) {
                $this->replaceClient($throwable);
            }

            throw $throwable;
        }

        // A transaction inside a pipeline only queued its EXEC. The pipeline settles the batch once it runs.
        if ($result instanceof Redis) {
            return $result;
        }

        if ($batch === $this->client) {
            $this->settleBatch(closesWatch: $transaction && is_array($result));
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    private function trackSessionState(string $method, array $parameters, mixed $result, int $mode): void
    {
        if ($method === 'rawcommand') {
            $mode === Redis::ATOMIC ? $this->trackRawCommand($parameters, $result) : $this->trackQueuedRawCommand($parameters);
        }

        $this->rememberDatabase();

        if ($method === 'exec' || $method === 'discard') {
            $confirmed = $method === 'exec' ? is_array($result) : $result === true;

            $this->settleBatch(closesWatch: $mode === Redis::MULTI && $confirmed);

            return;
        }

        if ($result === false || ! in_array($method, ['watch', 'unwatch'], true)) {
            return;
        }

        // A batched UNWATCH is never trusted to close the watch: its own reply is not known until EXEC, and may be an error.
        if ($mode !== Redis::ATOMIC) {
            $this->watchQueued = $this->watchQueued || $method === 'watch';

            return;
        }

        $this->watching = $method === 'watch';
    }

    /**
     * Follow a raw command that changes the session the way its phpredis method would.
     *
     * @param  array<int, mixed>  $parameters
     */
    private function trackRawCommand(array $parameters, mixed $result): void
    {
        $command = mb_strtolower((string) ($parameters[0] ?? ''));

        // The server ends the transaction and every WATCH on EXEC and DISCARD, whatever they answer.
        if ($this->endsRawTransaction($parameters)) {
            $this->inRawTransaction = false;
            $this->settleBatch(closesWatch: true);

            return;
        }

        // The server refuses what it cannot run or queue with false.
        if ($result === false) {
            return;
        }

        // Inside a raw transaction a command only queues, and a SELECT takes effect once the raw EXEC runs.
        if ($this->inRawTransaction) {
            $this->rawSelectQueued = $this->rawSelectQueued || $command === 'select';

            return;
        }

        if (in_array($command, ['watch', 'unwatch'], true)) {
            $this->watching = $command === 'watch';
        }

        if ($command === 'multi') {
            $this->inRawTransaction = true;
        }

        // phpredis does not see a raw SELECT, so it keeps reporting the old database and reconnects to it.
        // Selecting through phpredis again puts the database in its own state.
        if ($command === 'select' && is_numeric($parameters[1] ?? null)) {
            rescue(fn (): bool => $this->client->select((int) $parameters[1]), false, false);
        }
    }

    /**
     * Note a raw command a pipeline or a MULTI queued, whose effect is known only once the batch runs.
     *
     * @param  array<int, mixed>  $parameters
     */
    private function trackQueuedRawCommand(array $parameters): void
    {
        $command = mb_strtolower((string) ($parameters[0] ?? ''));

        $this->watchQueued = $this->watchQueued || $command === 'watch';
        $this->rawSelectQueued = $this->rawSelectQueued || $command === 'select';

        if (in_array($command, ['multi', 'exec', 'discard'], true)) {
            $this->queuedRawTransaction = $command === 'multi';
        }
    }

    /**
     * Whether a raw command ends the raw transaction the connection has open.
     *
     * @param  array<int, mixed>  $parameters
     */
    private function endsRawTransaction(array $parameters): bool
    {
        return $this->inRawTransaction && in_array(mb_strtolower((string) ($parameters[0] ?? '')), ['exec', 'discard'], true);
    }

    /**
     * Ask the server which database the connection is on, after a batch queued a raw SELECT phpredis did not see,
     * and select it through phpredis, which puts it in phpredis's own state. A server older than CLIENT INFO keeps the old one.
     */
    private function followQueuedRawSelect(): void
    {
        // A transaction inside a pipeline settles while the pipeline still queues, and the pipeline settles after it.
        // A raw transaction would queue the question, so the raw EXEC or DISCARD that ends it follows the SELECT.
        if ($this->client->getMode() !== Redis::ATOMIC || $this->inRawTransaction) {
            return;
        }

        $this->rawSelectQueued = false;

        $info = rescue(fn (): mixed => $this->client->rawCommand('CLIENT', 'INFO'), null, false);

        if (is_string($info) && preg_match('/\bdb=(\d+)/', $info, $match) === 1 && (int) $match[1] !== $this->client->getDbNum()) {
            rescue(fn (): bool => $this->client->select((int) $match[1]), false, false);
        }
    }

    /**
     * Settle the WATCH state once a batch ends.
     *
     * A WATCH the batch queued counts as open whatever the outcome, since
     * a failed pipeline may have sent it before the failing command. Only
     * a MULTI the server confirmed it ran or discarded closes the watch.
     */
    private function settleBatch(bool $closesWatch): void
    {
        $this->watching = ($this->watching || $this->watchQueued) && ! $closesWatch;
        $this->watchQueued = false;
        $this->watchMayBeOpen = $this->watchMayBeOpen && ! $closesWatch;

        if ($this->queuedRawTransaction !== null) {
            $this->inRawTransaction = $this->queuedRawTransaction;
            $this->queuedRawTransaction = null;
        }

        if ($this->rawSelectQueued) {
            $this->followQueuedRawSelect();
        }

        $this->rememberDatabase();
    }

    /**
     * Keep the database phpredis tracks, which follows a SELECT only once the server accepted it, batched or not.
     */
    private function rememberDatabase(): void
    {
        // phpredis answers false instead of a number while the client has no socket.
        if ($this->client->isConnected()) {
            $this->selectedDatabase = $this->client->getDbNum();
        }
    }

    /**
     * Replace the client with a freshly connected one that keeps the runtime options and database of the old one.
     *
     * When the rebuild fails, the command's own failure is rethrown:
     * for a write it is the one that says the server may have run
     * the command before the reply was lost. The failed rebuild is
     * reported as an event, which a listener that uses Redis cannot
     * trigger again.
     *
     * @throws Throwable
     */
    private function replaceClient(Throwable $cause): void
    {
        throw_if($this->connector === null, $cause);

        $previous = $this->client;
        $options = collect($this->runtimeOptions())
            ->mapWithKeys(fn (int $option): array => [$option => $previous->getOption($option)])
            // A read timeout of zero means none was set, which leaves PHP's default_socket_timeout on the socket. Setting zero would turn it off.
            ->reject(fn (mixed $value, int $option): bool => $option === Redis::OPT_READ_TIMEOUT && (float) $value <= 0);

        // A client that still holds its socket (a READONLY reply, a reply stream out of step) is closed: phpredis returns the
        // socket of a persistent client it destroys to the pool. It reconnects a failed client just to close it, so one is left alone.
        // With phpredis pooling off, PHP shares one persistent stream between the clients that name the same persistent_id, so
        // that stream stays open for the others, and the rebuild takes a socket of its own instead of the same stream back.
        $sharesStream = $previous->isConnected() && (bool) ($this->config['persistent'] ?? false) && ! ResilientPhpRedisConnector::poolsPersistentSockets();

        if ($previous->isConnected() && ! $sharesStream) {
            rescue(fn (): bool => $previous->close(), null, false);
        }

        $this->resetSessionState();

        try {
            $client = call_user_func($this->connector, $sharesStream ? ['persistent' => false] : []);

            $options->each(fn (mixed $value, int $option): bool => $client->getOption($option) === $value || $client->setOption($option, $value));

            $database = $this->selectedDatabase;

            throw_if($database !== null && $client->getDbNum() !== $database && $client->select($database) !== true, $cause);
        } catch (Throwable) {
            if ($this->report instanceof Closure) {
                ($this->report)(new RebuildFailed(connection: (string) $this->getName(), reason: (string) Failure::lostConnectionReason($cause)));
            }

            throw $cause;
        }

        $this->client = $client;
        $this->lastReplyAt = microtime(true);
    }

    /**
     * Forget the WATCH and transaction state of a socket that is gone.
     */
    private function resetSessionState(): void
    {
        $this->watching = false;
        $this->watchQueued = false;
        $this->watchMayBeOpen = false;
        $this->inRawTransaction = false;
        $this->queuedRawTransaction = null;
        $this->rawSelectQueued = false;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    private function dispatchCommandFailed(string $method, array $parameters, Throwable $e): void
    {
        if (class_exists(CommandFailed::class)) {
            $this->events?->dispatch(new CommandFailed($method, $this->parseParametersForEvent($parameters), $e, $this));
        }
    }
}
