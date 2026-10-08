<?php

namespace App\Services\Auth;

use App\Models\Branch;
use App\Models\User;
use App\Services\CashRegisters\CashRegisterSessionQueryService;
use Spatie\Permission\Models\Permission;

/**
 * Builds the access context that the mobile app needs right after logging in:
 * user profile, branch, subscription, module keys, effective permissions and
 * the current cash register session.
 *
 * It mirrors the information that the web SPA receives from
 * HandleInertiaRequests, so both clients hide and show the same features.
 */
class UserAccessContextService
{
    public function __construct(private readonly CashRegisterSessionQueryService $cashRegisterSessions) {}

    /**
     * Full context payload shared by the login, me and branch switch endpoints.
     */
    public function build(User $user): array
    {
        $user->loadMissing('branch.subscription');

        return [
            'user' => $this->userPayload($user),
            'module_keys' => $this->moduleKeys($user),
            'modules' => $this->moduleNames($user),
            'available_branches' => $this->availableBranches($user),
            'active_session' => $this->cashRegisterSessions->activeSession($user),
            'joinable_sessions' => $this->cashRegisterSessions->joinableSessions($user),
            'available_cash_registers' => $this->cashRegisterSessions->availableCashRegisters($user),
        ];
    }

    /**
     * Compact, mobile-friendly representation of the authenticated user.
     */
    public function userPayload(User $user): array
    {
        $user->loadMissing('branch.subscription');

        $subscription = $user->branch?->subscription;
        $status = $subscription?->status;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'profile_photo_url' => $user->profile_photo_url,
            'is_active' => (bool) $user->is_active,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'branch_id' => $user->branch_id,
            'branch' => $user->branch ? [
                'id' => $user->branch->id,
                'name' => $user->branch->name,
                'timezone' => $user->branch->timezone,
            ] : null,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'commercial_name' => $subscription->commercial_name,
                'status' => $status instanceof \BackedEnum ? $status->value : $status,
                'expires_at' => $subscription->currentVersion()?->end_date?->toIso8601String(),
            ] : null,
            'is_subscription_owner' => !$user->roles()->exists(),
            'permissions' => $this->permissions($user),
        ];
    }

    /**
     * Effective permissions of the user, limited to the modules included in
     * their subscription (same rules as the web SPA and the Gate::before hook).
     *
     * @return array<int, string>
     */
    public function permissions(User $user): array
    {
        $subscription = $user->branch?->subscription;
        $availableModuleNames = $subscription ? $subscription->getAvailableModuleNames() : [];

        // Subscription owner: every permission of the contracted modules plus the system ones.
        if (!$user->roles()->exists()) {
            return Permission::query()
                ->where(function ($query) use ($availableModuleNames) {
                    $query->whereIn('module', $availableModuleNames)
                        ->orWhere('module', 'Sistema');
                })
                ->pluck('name')
                ->all();
        }

        // Employee: only the assigned permissions that belong to active modules.
        if (!$subscription?->currentVersion()) {
            return [];
        }

        return $user->getAllPermissions()
            ->filter(fn ($permission) => in_array($permission->module, $availableModuleNames, true)
                || $permission->module === 'Sistema')
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function moduleKeys(User $user): array
    {
        return $user->branch?->subscription?->getActiveModuleKeys() ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function moduleNames(User $user): array
    {
        return $user->branch?->subscription?->getAvailableModuleNames() ?? [];
    }

    /**
     * Branches the user may switch to.
     *
     * Everybody sees the branches of their own subscription; the platform
     * support user (id 1) sees every branch grouped by subscription.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableBranches(User $user): array
    {
        if ($user->id === 1) {
            return Branch::with('subscription:id,commercial_name')
                ->orderBy('subscription_id')
                ->orderBy('name')
                ->get()
                ->groupBy('subscription_id')
                ->map(fn ($branches) => [
                    'subscription_name' => $branches->first()->subscription?->commercial_name,
                    'branches' => $branches->map(fn (Branch $branch) => [
                        'id' => $branch->id,
                        'name' => $branch->name,
                    ])->values()->all(),
                ])
                ->values()
                ->all();
        }

        return Branch::where('subscription_id', $user->branch?->subscription_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_current' => (int) $branch->id === (int) $user->branch_id,
            ])
            ->values()
            ->all();
    }
}
