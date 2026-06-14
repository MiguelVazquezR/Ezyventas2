<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                $isOwner = !$user->roles()->exists();
                $subscription = $user->branch->subscription;

                $isSubscriptionActive = (bool)$subscription->currentVersion();
                $availableModuleNames = $isSubscriptionActive ? $subscription->getAvailableModuleNames() : [];

                $permissions = $isOwner
                    ? Permission::query()
                        ->whereIn('module', $availableModuleNames)
                        ->orWhere('module', 'Sistema')
                        ->pluck('name')
                    : ($isSubscriptionActive ? $user->getAllPermissions()->pluck('name') : collect([]));

                return [
                    'user' => $user,
                    'permissions' => $permissions,
                    'is_subscription_owner' => $isOwner,
                    'subscription' => ['commercial_name' => $subscription->commercial_name],
                    'subscriptionWarning' => $subscription->getWarningData(),
                    'current_branch' => $user->branch,
                    'preferences' => $user->getPreferences(),
                    'available_branches' => function () use ($user, $subscription) {
                        if ($user->id === 1) {
                            return Subscription::query()
                                ->whereHas('branches')
                                ->with(['branches:id,name,subscription_id'])
                                ->get(['id', 'commercial_name'])
                                ->map(fn($sub) => [
                                    'subscription_name' => $sub->commercial_name,
                                    'branches' => $sub->branches
                                ]);
                        }
                        return $subscription->branches()->get(['id', 'name']);
                    },
                ];
            },
            
            // Evaluamos notificaciones delegadas al modelo User de forma perezosa (lazy evaluation)
            'notifications' => fn() => $request->user() ? $request->user()->getGlobalNotifications() : null,

            // Notificaciones del sistema de referidos (para badge en topbar)
            'referralNotifications' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                return [
                    'pending_rewards_count' => $user->referralUsagesAsReferrer()
                        ->where('reward_status', 'pending')
                        ->count(),
                    'unseen_referrals_count' => $user->getUnseenReferralsCount(),
                ];
            },

            'flash' => fn() => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'print_data' => $request->session()->get('print_data'),
                'show_payment_modal' => $request->session()->get('show_payment_modal'),
            ],

            // Reutilizamos toda la lógica de cajas de los modelos, dejándolo super limpio
            'activeSession' => fn() => $request->user()?->getActiveCashRegisterSession(),
            'joinableSessions' => fn() => $request->user()?->getJoinableCashRegisterSessions() ?? [],
            'availableCashRegisters' => fn() => $request->user()?->getAvailableCashRegisters() ?? []
        ]);
    }
}