<?php

declare(strict_types=1);

use Publicala\ResilientRedis\ConnectRetryPolicy;
use Publicala\ResilientRedis\Failure;

it('allows two retries within six seconds when the connection sets nothing', function (): void {
    $policy = ConnectRetryPolicy::fromConfig([]);

    expect($policy->retries)->toBe(2)
        ->and($policy->windowMilliseconds)->toBe(6000.0);
});

it('reads the retries and the window from the connection config', function (): void {
    $policy = ConnectRetryPolicy::fromConfig(['connect_retries' => '4', 'connect_retry_window' => '2.5']);

    expect($policy->retries)->toBe(4)
        ->and($policy->windowMilliseconds)->toBe(2500.0);
});

it('treats a negative setting as zero', function (): void {
    $policy = ConnectRetryPolicy::fromConfig(['connect_retries' => -1, 'connect_retry_window' => -3]);

    expect($policy->retries)->toBe(0)
        ->and($policy->windowMilliseconds)->toBe(0.0);
});

it('retries a fast transient failure while retries remain', function (): void {
    $policy = new ConnectRetryPolicy(retries: 2, windowMilliseconds: 6000);
    $reset = new ErrorException('Redis::pconnect(): SSL: Connection reset by peer');

    expect($policy->retryReason(1, $reset, 40))->toBe('connection_reset')
        ->and($policy->retryReason(2, $reset, 40))->toBe('connection_reset')
        ->and($policy->retryReason(3, $reset, 40))->toBeNull();
});

it('does not retry a transient failure that took a second or more, because it stalled', function (): void {
    $policy = new ConnectRetryPolicy(retries: 2, windowMilliseconds: 6000);
    $stall = new RedisException('read error on connection to cache.example.com:6379');

    expect($policy->retryReason(1, $stall, 999.9))->toBe('read_error')
        ->and($policy->retryReason(1, $stall, 1000))->toBeNull();
});

it('retries a temporary DNS failure that took the resolver timeout', function (): void {
    $policy = new ConnectRetryPolicy(retries: 2, windowMilliseconds: 6000);
    $dns = new RedisException('php_network_getaddresses: getaddrinfo for cache.example.com failed: Temporary failure in name resolution');

    expect($policy->retryReason(1, $dns, 5000))->toBe(Failure::TEMPORARY_DNS);
});

it('never retries a failure it cannot name', function (): void {
    $policy = new ConnectRetryPolicy(retries: 2, windowMilliseconds: 6000);

    expect($policy->retryReason(1, new RedisException('WRONGPASS invalid username-password pair'), 5))->toBeNull();
});

it('starts an attempt only before the window closes', function (): void {
    $policy = new ConnectRetryPolicy(retries: 2, windowMilliseconds: 6000);

    expect($policy->allowsAttemptAt(5999.9))->toBeTrue()
        ->and($policy->allowsAttemptAt(6000))->toBeFalse();
});

it('waits a random time within a window that doubles per attempt and stops at one second', function (int $attempt, int $minimum, int $maximum): void {
    $policy = new ConnectRetryPolicy(retries: 5, windowMilliseconds: 60000);

    $delays = collect(range(1, 200))->map(fn (): int => $policy->backoffMilliseconds($attempt));

    expect($delays->min())->toBeGreaterThanOrEqual($minimum)
        ->and($delays->max())->toBeLessThanOrEqual($maximum)
        ->and($delays->unique()->count())->toBeGreaterThan(1);
})->with([
    'after the first attempt' => [1, 100, 200],
    'after the second attempt' => [2, 100, 400],
    'after the fourth attempt' => [4, 100, 1000],
]);
