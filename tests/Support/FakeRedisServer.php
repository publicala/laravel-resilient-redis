<?php

declare(strict_types=1);

namespace Publicala\ResilientRedis\Tests\Support;

use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A scripted RESP server in a child process, so a real phpredis client
 * can fail the way a production Redis fails. See fake-redis-server.php
 * for the steps a plan may hold.
 */
final class FakeRedisServer
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        public readonly int $port,
        private readonly string $log,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param  list<string>  $plan
     */
    public static function start(array $plan, bool $tls = false): self
    {
        $log = sys_get_temp_dir().'/fake-redis-'.Str::random(12).'.log';
        touch($log);

        $command = [PHP_BINARY, __DIR__.'/fake-redis-server.php', '--plan='.json_encode($plan, JSON_THROW_ON_ERROR), "--log={$log}"];

        if ($tls) {
            $command[] = '--tls-cert='.self::certificate();
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        throw_unless(is_resource($process), RuntimeException::class, 'Unable to start the fake Redis server.');

        $address = str_replace(["\r", "\n"], '', (string) fgets($pipes[1]));

        if (! str_contains($address, ':')) {
            throw new RuntimeException('The fake Redis server did not report its address: '.stream_get_contents($pipes[2]));
        }

        return new self($process, (int) Str::afterLast($address, ':'), $log);
    }

    /**
     * The PEM bundle (certificate and key) the TLS steps present.
     */
    public static function certificate(): string
    {
        $path = sys_get_temp_dir().'/fake-redis-server-'.PHP_VERSION_ID.'.pem';

        if (file_exists($path)) {
            return $path;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => '127.0.0.1'], $key), null, $key, 30);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($path, $certificatePem.$keyPem);

        return $path;
    }

    /**
     * Connection config for this server, as a Laravel Redis connection defines it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function config(array $overrides = [], bool $tls = false): array
    {
        return [
            'host' => $tls ? 'tls://127.0.0.1' : '127.0.0.1',
            'port' => $this->port,
            'database' => 0,
            'timeout' => 1.0,
            ...($tls ? ['context' => ['stream' => ['verify_peer' => false, 'verify_peer_name' => false]]] : []),
            ...$overrides,
        ];
    }

    public function acceptedConnections(): int
    {
        return count($this->events('accept'));
    }

    /**
     * The commands the server received, each as "NAME arg1 arg2".
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_map(fn (string $event): string => Str::after($event, 'command '), $this->events('command'));
    }

    /**
     * Whether the client closed the given connection (1 for the first one accepted), waiting up to a second for the server to notice.
     */
    public function hungUp(int $connection): bool
    {
        $deadline = microtime(true) + 1.0;

        do {
            if (in_array("hangup {$connection}", $this->events('hangup'), true)) {
                return true;
            }

            Sleep::usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        @unlink($this->log);
    }

    /**
     * @return list<string>
     */
    private function events(string $kind): array
    {
        clearstatcache(true, $this->log);

        return array_values(array_filter(
            explode("\n", (string) @file_get_contents($this->log)),
            fn (string $event): bool => str_starts_with($event, $kind.' '),
        ));
    }
}
