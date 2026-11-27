<?php

namespace App\Services\Lifecycle;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "this account has not been used in a long time".
 *
 * Activity is measured with users.last_login_at: an account whose owner never
 * signs in is inactive even if the storefront keeps receiving orders. When no
 * user of the account has ever signed in, the subscription creation date is
 * used instead, so a brand new account is never considered inactive.
 */
class AccountLifecycleService
{
    /**
     * Store the sign-in of a user and reactivate the account if it was already
     * warned or purged (logging in is the way back).
     */
    public function markUserActivity(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $subscription = $this->subscriptionForUser($user);

        if ($subscription && ($subscription->inactivity_warned_at || $subscription->data_purged_at)) {
            $subscription->forceFill([
                'inactivity_warned_at' => null,
                'data_purged_at' => null,
            ])->saveQuietly();
        }
    }

    public function subscriptionForUser(User $user): ?Subscription
    {
        if (! $user->branch_id) {
            return null;
        }

        return Subscription::query()
            ->whereHas('branches', fn (Builder $query) => $query->whereKey($user->branch_id))
            ->first();
    }

    /**
     * Latest sign-in among every user of the subscription.
     *
     * The query may already bring the value as "tenant_last_login_at" (see
     * inactiveSubscriptions()), which avoids one query per account.
     */
    public function lastActivityAt(Subscription $subscription): Carbon
    {
        $lastLogin = $subscription->getAttribute('tenant_last_login_at')
            ?? User::query()
                ->join('branches', 'branches.id', '=', 'users.branch_id')
                ->where('branches.subscription_id', $subscription->id)
                ->max('users.last_login_at');

        return $lastLogin
            ? Carbon::parse($lastLogin)
            : Carbon::parse($subscription->created_at);
    }

    public function purgeDate(Subscription $subscription): Carbon
    {
        return $this->lastActivityAt($subscription)->copy()->addDays($this->purgeAfterDays());
    }

    public function daysUntilPurge(Subscription $subscription): int
    {
        return (int) now()->startOfDay()->diffInDays($this->purgeDate($subscription)->startOfDay(), false);
    }

    /**
     * Accounts inside the warning window that have not been warned yet. The
     * window is skipped when it is already too late (daysUntilPurge <= 0), so
     * a delayed run never promises "7 more days" to an account that is going
     * to be purged the same day.
     *
     * @return Collection<int, Subscription>
     */
    public function subscriptionsDueForWarning(): Collection
    {
        return $this->inactiveSubscriptions($this->warningThreshold())
            ->whereNull('inactivity_warned_at')
            ->get()
            ->filter(fn (Subscription $subscription): bool => $this->daysUntilPurge($subscription) > 0)
            ->values();
    }

    /**
     * Accounts whose last activity is older than the purge window.
     *
     * @return Collection<int, Subscription>
     */
    public function subscriptionsDueForPurge(): Collection
    {
        return $this->inactiveSubscriptions($this->purgeThreshold())->get();
    }

    /**
     * Main user of the account: the oldest user of its main branch (the owner
     * is created together with the business). Never deletes anything by itself.
     */
    public function resolvePrimaryUser(Subscription $subscription): ?User
    {
        $mainBranchId = $subscription->branches()
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->value('id');

        if ($mainBranchId) {
            $user = User::query()
                ->where('branch_id', $mainBranchId)
                ->orderBy('id')
                ->first();

            if ($user) {
                return $user;
            }
        }

        return User::query()
            ->whereIn('branch_id', $subscription->branches()->select('id'))
            ->orderBy('id')
            ->first();
    }

    public function purgeAfterDays(): int
    {
        return max(1, (int) config('account_lifecycle.inactivity.purge_after_days', 90));
    }

    public function warningBeforeDays(): int
    {
        return max(0, (int) config('account_lifecycle.inactivity.warning_before_days', 7));
    }

    private function warningThreshold(): Carbon
    {
        return now()->subDays($this->purgeAfterDays() - $this->warningBeforeDays());
    }

    private function purgeThreshold(): Carbon
    {
        return now()->subDays($this->purgeAfterDays());
    }

    /**
     * Accounts with data still alive whose last sign-in is older than the
     * threshold. Accounts without any sign-in fall back to their creation date.
     */
    private function inactiveSubscriptions(Carbon $threshold): Builder
    {
        $lastLogin = User::query()
            ->selectRaw('MAX(users.last_login_at)')
            ->join('branches', 'branches.id', '=', 'users.branch_id')
            ->whereColumn('branches.subscription_id', 'subscriptions.id');

        return Subscription::query()
            ->select('subscriptions.*')
            ->selectSub($lastLogin, 'tenant_last_login_at')
            ->whereNull('data_purged_at')
            ->whereRaw(
                'COALESCE((select max(users.last_login_at) from users inner join branches on branches.id = users.branch_id where branches.subscription_id = subscriptions.id), subscriptions.created_at) <= ?',
                [$threshold->toDateTimeString()],
            );
    }
}
