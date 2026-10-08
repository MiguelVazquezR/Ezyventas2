<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Single source of truth for the "can this user operate?" rules that the
 * mobile API shares with the web middleware stack.
 *
 * - A deactivated user can never authenticate.
 * - An employee (user with roles) cannot work while the subscription has no
 *   active version (expired or suspended) — same rule as CheckSubscriptionStatus.
 * - The subscription owner is always allowed in, so they can renew their plan;
 *   their permissions are limited by the contracted modules instead.
 */
class SubscriptionAccessGuard
{
    public function isActive(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function hasActiveSubscription(User $user): bool
    {
        return (bool) $user->branch?->subscription?->currentVersion();
    }

    public function isSubscriptionOwner(User $user): bool
    {
        return !$user->roles()->exists();
    }

    /**
     * Aborts with an HTTP exception when the user must not operate.
     */
    public function ensureUserCanOperate(User $user): void
    {
        if (!$this->isActive($user)) {
            abort(403, 'Tu usuario está desactivado. Contacta al administrador.');
        }

        if ($this->isSubscriptionOwner($user) || $this->hasActiveSubscription($user)) {
            return;
        }

        abort(403, 'La suscripción de este negocio ha expirado.');
    }
}
