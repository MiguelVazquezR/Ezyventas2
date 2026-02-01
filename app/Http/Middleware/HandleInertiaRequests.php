<?php

namespace App\Http\Middleware;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\TransactionStatus; // <-- Importante
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Transaction; // <-- Importante
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;
use Tightenco\Ziggy\Ziggy;

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

                // --- PERMISOS POR EXPIRACIÓN ---
                $currentVersion = $subscription->currentVersion();
                $isSubscriptionActive = (bool)$currentVersion;
                $availableModuleNames = $isSubscriptionActive
                    ? $subscription->getAvailableModuleNames()
                    : [];

                $permissions = $isOwner
                    ? Permission::query()
                    ->whereIn('module', $availableModuleNames)
                    ->orWhere('module', 'Sistema')
                    ->pluck('name')
                    : ($isSubscriptionActive ? $user->getAllPermissions()->pluck('name') : []);

                // --- Lógica de Advertencia de Suscripción ---
                $subscriptionWarning = null;
                $currentVersionAll = $subscription->versions()->latest('id')->first();

                if ($currentVersionAll) {
                    $endDate = Carbon::parse($currentVersionAll->end_date)->startOfDay();
                    $today = Carbon::now()->startOfDay();
                    $daysRemaining = $today->diffInDays($endDate, false);
                    $warningThreshold = 5;

                    if ($daysRemaining < 0) {
                        $subscriptionWarning = [
                            'daysRemaining' => $daysRemaining,
                            'endDate' => $endDate->translatedFormat('d \d\e F \d\e\l Y'),
                            'message' => "La suscripción expiró el " . $endDate->translatedFormat('d \d\e F'),
                            'isExpired' => true
                        ];
                    } elseif ($daysRemaining <= $warningThreshold) {
                        $message = $daysRemaining == 0
                            ? "La suscripción vence hoy"
                            : "La suscripción vence en {$daysRemaining} " . ($daysRemaining === 1 ? 'día' : 'días');

                        $subscriptionWarning = [
                            'daysRemaining' => $daysRemaining,
                            'endDate' => $endDate->translatedFormat('d \d\e F \d\e\l Y'),
                            'message' => $message,
                            'isExpired' => false
                        ];
                    }
                }

                return [
                    'user' => $user,
                    'permissions' => $permissions,
                    'is_subscription_owner' => $isOwner,
                    'subscription' => ['commercial_name' => $subscription->commercial_name],
                    'subscriptionWarning' => $subscriptionWarning,
                    'current_branch' => $user->branch,
                    'available_branches' => $subscription->branches()->get(['id', 'name']),
                ];
            },
            
            // --- NUEVO: Notificaciones Globales ---
            'notifications' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                // Solo calculamos si el usuario tiene permiso de ver transacciones (o es dueño)
                if ($user->roles()->exists() && !$user->can('transactions.access')) {
                    return [
                        'expiring_layaways' => 0,
                        'upcoming_deliveries' => 0,
                        'total' => 0
                    ];
                }

                $branchId = $user->branch_id;
                
                // 1. Apartados por vencer (3 días)
                $expiringLayaways = Transaction::where('branch_id', $branchId)
                    ->where('status', TransactionStatus::ON_LAYAWAY)
                    ->whereNotNull('layaway_expiration_date')
                    ->whereDate('layaway_expiration_date', '<=', now()->addDays(3))
                    ->count();

                // 2. Próximas entregas (3 días)
                $upcomingDeliveries = Transaction::where('branch_id', $branchId)
                    ->where('status', TransactionStatus::TO_DELIVER)
                    ->whereNotNull('delivery_date')
                    ->whereDate('delivery_date', '<=', now()->addDays(3))
                    ->count();

                return [
                    'expiring_layaways' => $expiringLayaways,
                    'upcoming_deliveries' => $upcomingDeliveries,
                    'total' => $expiringLayaways + $upcomingDeliveries
                ];
            },
            // --- FIN Notificaciones ---

            'flash' => fn() => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'print_data' => $request->session()->get('print_data'),
                'show_payment_modal' => $request->session()->get('show_payment_modal'),
            ],

            'activeSession' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                return $user->cashRegisterSessions()
                    ->where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->first();
            },

            'joinableSessions' => function () use ($request) {
                $user = $request->user();
                if (!$user || $user->cashRegisterSessions()->where('status', CashRegisterSessionStatus::OPEN)->exists()) {
                    return [];
                }

                return CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->with('cashRegister:id,name', 'opener:id,name')
                    ->get();
            },

            'availableCashRegisters' => function () use ($request) {
                $user = $request->user();
                if (!$user) return [];

                $userHasSession = $user->cashRegisterSessions()->where('status', CashRegisterSessionStatus::OPEN)->exists();

                $branchHasAnySession = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->exists();

                if (!$userHasSession && !$branchHasAnySession) {
                    return CashRegister::where('branch_id', $user->branch_id)
                        ->where('is_active', true)
                        ->where('in_use', false)
                        ->get(['id', 'name']);
                }

                return [];
            }
        ]);
    }
}