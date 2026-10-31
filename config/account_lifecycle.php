<?php

/*
|--------------------------------------------------------------------------
| Account lifecycle
|--------------------------------------------------------------------------
|
| Rules for the automatic hibernation of accounts nobody signs in to.
|
| Default timeline for an inactive account:
|   day 83 → warning email ("tu información se eliminará en 7 días")
|   day 90 → business data is purged
|
| Consumed by App\Services\Lifecycle\AccountLifecycleService and the jobs in
| App\Jobs\Lifecycle. The jobs only run when "enabled" is true; by default it
| is enabled in production only, so local/staging data is never wiped.
|
*/

return [

    'inactivity' => [
        'enabled' => filter_var(
            env('ACCOUNT_LIFECYCLE_ENABLED', env('APP_ENV', 'production') === 'production'),
            FILTER_VALIDATE_BOOL,
        ),

        // Days without any sign-in before the business data is purged.
        'purge_after_days' => (int) env('ACCOUNT_INACTIVITY_PURGE_DAYS', 90),

        // How many days before the purge the warning email is sent.
        'warning_before_days' => (int) env('ACCOUNT_INACTIVITY_WARNING_DAYS', 7),

        // Models that own media files are deleted one by one so MediaLibrary
        // removes the files from disk; this controls the chunk size.
        'chunk_size' => 100,
    ],

];
