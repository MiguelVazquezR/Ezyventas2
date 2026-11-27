<?php

namespace App\Listeners\Auth;

use App\Models\User;
use App\Services\Lifecycle\AccountLifecycleService;
use Illuminate\Auth\Events\Login;

/**
 * Keeps users.last_login_at current. The inactivity purge relies on it, so it
 * must run for every authentication path (Fortify, Google OAuth, ...).
 */
class RecordUserLastLogin
{
    public function __construct(private readonly AccountLifecycleService $lifecycleService) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->lifecycleService->markUserActivity($event->user);
    }
}
