<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When false, phpredis connections use Laravel's stock connector and
    | connection, exactly as if the package were not installed. Values
    | like "off" and "no" disable it too.
    |
    */

    'enabled' => filter_var(env('RESILIENT_REDIS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Recover Lost Connections
    |--------------------------------------------------------------------------
    |
    | On Laravel versions that do not recover a lost connection themselves
    | (before 13.28), rebuild the client after a command loses the server
    | and run a read once more. Newer versions keep their own handling.
    |
    */

    'recover_lost_connections' => filter_var(env('RESILIENT_REDIS_RECOVER_LOST_CONNECTIONS', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Log Recoveries
    |--------------------------------------------------------------------------
    |
    | Log a warning each time a connect or a read recovers. Listen for the
    | ConnectRecovered and CommandRecovered events to report them yourself.
    |
    */

    'log_recoveries' => filter_var(env('RESILIENT_REDIS_LOG_RECOVERIES', true), FILTER_VALIDATE_BOOLEAN),

];
