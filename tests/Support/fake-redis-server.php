<?php

declare(strict_types=1);

/*
 * A scripted RESP server for exercising real phpredis clients against failure modes
 * a healthy Redis never produces on demand. Each accepted connection takes the next
 * step of the plan, and the last step repeats for every connection after it.
 *
 * Usage: php fake-redis-server.php --plan=<json> --log=<path> [--tls-cert=<pem>]
 *
 * Steps:
 *   serve                 answer every command
 *   serve:N               answer N commands, then close the socket (FIN)
 *   serve-then-reset:N    answer N commands, then reset the socket (RST) when the next one arrives
 *   serve-then-drop:N     answer N commands, then reset the idle socket before the next one is sent
 *   reset                 reset (RST) right after accepting
 *   close-on-first-read   wait for the first bytes (a TLS ClientHello or a command), then close (FIN)
 *   reset-on-first-read   wait for the first bytes, then reset
 *   reply-garbage         answer the first bytes with a plain-text line (breaks a TLS handshake)
 *   stall                 accept and never answer
 *   error:MESSAGE         answer every command with "-MESSAGE"
 *   error-after:N:MESSAGE answer N commands, then "-MESSAGE" to every later one
 *
 * With --tls-cert, a step that answers commands first completes a TLS handshake.
 * The bound "host:port" is printed on stdout. Every event is appended to --log.
 */

$options = getopt('', ['plan:', 'log:', 'tls-cert::']);
$plan = json_decode($options['plan'], true, flags: JSON_THROW_ON_ERROR);
$log = $options['log'];
$tlsCert = $options['tls-cert'] ?? null;

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if ($server === false) {
    fwrite(STDERR, "Unable to listen: {$errorMessage}\n");
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
fflush(STDOUT);

$record = function (string $event) use ($log): void {
    file_put_contents($log, $event."\n", FILE_APPEND | LOCK_EX);
};

$resetSocket = function ($stream): void {
    $socket = socket_import_stream($stream);

    if ($socket !== false && $socket instanceof Socket) {
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        socket_close($socket);
    }

    // Closing the imported socket closes the stream too.
    if (is_resource($stream)) {
        fclose($stream);
    }
};

/** @var array<int, array{stream: resource, step: string, buffer: string, answered: int, stored: array<string, string>}> $clients */
$clients = [];
$accepted = 0;
$store = [];

while (true) {
    $read = [$server, ...array_column($clients, 'stream')];
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, 1) === false) {
        exit(1);
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            $connection = stream_socket_accept($server, 0);

            if ($connection === false) {
                continue;
            }

            $step = $plan[min($accepted, count($plan) - 1)];
            $accepted++;
            $record("accept {$accepted} {$step}");

            if ($step === 'reset') {
                $resetSocket($connection);

                continue;
            }

            $answersCommands = str_starts_with($step, 'serve') || str_starts_with($step, 'error');

            if ($tlsCert !== null && $answersCommands) {
                stream_context_set_option($connection, 'ssl', 'local_cert', $tlsCert);
                stream_context_set_option($connection, 'ssl', 'verify_peer', false);

                if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                    $record("handshake-failed {$accepted}");
                    @fclose($connection);

                    continue;
                }
            }

            $clients[(int) $connection] = ['stream' => $connection, 'step' => $step, 'buffer' => '', 'answered' => 0, 'queued' => null];

            continue;
        }

        $client = &$clients[(int) $stream];
        $chunk = @fread($stream, 65536);

        if ($chunk === false || $chunk === '') {
            if (feof($stream)) {
                @fclose($stream);
                unset($clients[(int) $stream]);
            }

            continue;
        }

        if ($client['step'] === 'stall') {
            continue;
        }

        // Closing only after the client wrote keeps phpredis from noticing the
        // close before it sends, where it reconnects on its own (max_retries).
        if ($client['step'] === 'close-on-first-read') {
            fclose($stream);
            unset($clients[(int) $stream]);

            continue;
        }

        if ($client['step'] === 'reset-on-first-read') {
            $resetSocket($stream);
            unset($clients[(int) $stream]);

            continue;
        }

        if ($client['step'] === 'reply-garbage') {
            fwrite($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
            fclose($stream);
            unset($clients[(int) $stream]);

            continue;
        }

        $client['buffer'] .= $chunk;

        while (($command = parseCommand($client['buffer'])) !== null) {
            [$arguments, $client['buffer']] = $command;
            $name = mb_strtoupper($arguments[0]);
            $record('command '.implode(' ', array_map(fn (string $argument): string|false => str_contains($argument, ' ') ? json_encode($argument) : $argument, $arguments)));

            [$kind, $limit, $message] = array_pad(explode(':', $client['step'], 3), 3, null);

            if ($kind === 'error' && $limit !== null && $message === null) {
                [$message, $limit] = [$limit, 0];
            }

            if ($limit !== null && $kind !== 'serve-then-drop' && $client['answered'] >= (int) $limit) {
                if ($kind === 'serve') {
                    fclose($stream);
                    unset($clients[(int) $stream]);

                    continue 2;
                }

                if ($kind === 'serve-then-reset') {
                    $resetSocket($stream);
                    unset($clients[(int) $stream]);

                    continue 2;
                }

                fwrite($stream, "-{$message}\r\n");
                $client['answered']++;

                continue;
            }

            fwrite($stream, respond($client, $name, array_slice($arguments, 1), $store));
            $client['answered']++;

            if ($kind === 'serve-then-drop' && $client['answered'] >= (int) $limit) {
                // Let the reply reach the client before the reset discards the send buffer.
                usleep(50_000);
                $resetSocket($stream);
                $record('dropped');
                unset($clients[(int) $stream]);

                continue 2;
            }
        }

        unset($client);
    }
}

/**
 * Pull one complete RESP array command off the buffer.
 *
 * @return array{0: list<string>, 1: string}|null
 */
function parseCommand(string $buffer): ?array
{
    if ($buffer === '' || $buffer[0] !== '*') {
        return null;
    }

    $offset = 0;
    $count = (int) mb_substr($buffer, 1, ($end = mb_strpos($buffer, "\r\n")) - 1);

    if ($end === false) {
        return null;
    }

    $offset = $end + 2;
    $arguments = [];

    for ($i = 0; $i < $count; $i++) {
        $end = mb_strpos($buffer, "\r\n", $offset);

        if ($end === false) {
            return null;
        }

        $length = (int) mb_substr($buffer, $offset + 1, $end - $offset - 1);

        if (mb_strlen($buffer) < $end + 2 + $length + 2) {
            return null;
        }

        $arguments[] = mb_substr($buffer, $end + 2, $length);
        $offset = $end + 2 + $length + 2;
    }

    return [$arguments, mb_substr($buffer, $offset)];
}

/**
 * Answer a command, queueing it instead while the client holds a MULTI open.
 *
 * @param  array{queued: list<array{0: string, 1: list<string>}>|null}  $client
 * @param  list<string>  $arguments
 * @param  array<string, string>  $store
 */
function respond(array &$client, string $name, array $arguments, array &$store): string
{
    if ($name === 'MULTI') {
        $client['queued'] = [];

        return "+OK\r\n";
    }

    if ($name === 'DISCARD') {
        $client['queued'] = null;

        return "+OK\r\n";
    }

    if ($name === 'EXEC') {
        $queued = $client['queued'] ?? [];
        $client['queued'] = null;

        return '*'.count($queued)."\r\n".implode('', array_map(
            function (array $command) use (&$store): string {
                return reply($command[0], $command[1], $store);
            },
            $queued,
        ));
    }

    if ($client['queued'] !== null) {
        $client['queued'][] = [$name, $arguments];

        return "+QUEUED\r\n";
    }

    return reply($name, $arguments, $store);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $store
 */
function reply(string $name, array $arguments, array &$store): string
{
    return match ($name) {
        'PING' => "+PONG\r\n",
        'SELECT' => (int) $arguments[0] > 15 ? "-ERR DB index is out of range\r\n" : "+OK\r\n",
        'GET' => isset($store[$arguments[0]])
            ? '$'.mb_strlen($store[$arguments[0]])."\r\n{$store[$arguments[0]]}\r\n"
            : "\$-1\r\n",
        'SET' => (function () use ($arguments, &$store): string {
            $store[$arguments[0]] = $arguments[1];

            return "+OK\r\n";
        })(),
        'DEL' => (function () use ($arguments, &$store): string {
            $deleted = count(array_intersect_key($store, array_flip($arguments)));
            $store = array_diff_key($store, array_flip($arguments));

            return ":{$deleted}\r\n";
        })(),
        'INCR' => (function () use ($arguments, &$store): string {
            $store[$arguments[0]] = (string) ((int) ($store[$arguments[0]] ?? 0) + 1);

            return ":{$store[$arguments[0]]}\r\n";
        })(),
        default => "+OK\r\n",
    };
}
