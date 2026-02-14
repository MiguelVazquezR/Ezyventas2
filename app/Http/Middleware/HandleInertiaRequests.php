<?php

namespace App\Http\Middleware;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\TransactionStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Transaction;
use App\Models\Subscription;
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
                    // --- MODIFICACIÓN SUPER ADMIN (ID 1) ---
                    'available_branches' => function () use ($user, $subscription) {
                        if ($user->id === 1) {
                            return Subscription::query()
                                ->whereHas('branches')
                                ->with(['branches:id,name,subscription_id'])
                                ->get(['id', 'commercial_name'])
                                ->map(function ($sub) {
                                    return [
                                        'subscription_name' => $sub->commercial_name,
                                        'branches' => $sub->branches
                                    ];
                                });
                        }
                        return $subscription->branches()->get(['id', 'name']);
                    },
                ];
            },
            
            // --- NUEVO: Notificaciones Globales ---
            'notifications' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                if ($user->roles()->exists() && !$user->can('transactions.access')) {
                    return [
                        'expiring_debts' => 0,
                        'upcoming_deliveries' => 0,
                        'total' => 0
                    ];
                }

                $branchId = $user->branch_id;
                
                // ACTUALIZADO: Busca Apartados (ON_LAYAWAY) Y Créditos (PENDING) por vencer
                $expiringDebts = Transaction::where('branch_id', $branchId)
                    ->whereIn('status', [TransactionStatus::ON_LAYAWAY, TransactionStatus::PENDING])
                    ->whereNotNull('layaway_expiration_date')
                    ->whereDate('layaway_expiration_date', '<=', now()->addDays(3))
                    ->count();

                $upcomingDeliveries = Transaction::where('branch_id', $branchId)
                    ->where('status', TransactionStatus::TO_DELIVER)
                    ->whereNotNull('delivery_date')
                    ->whereDate('delivery_date', '<=', now()->addDays(3))
                    ->count();

                return [
                    'expiring_debts' => $expiringDebts, // Cambiado de expiring_layaways a expiring_debts
                    'upcoming_deliveries' => $upcomingDeliveries,
                    'total' => $expiringDebts + $upcomingDeliveries
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

                // 1. Verificamos si EL USUARIO ACTUAL ya tiene sesión (para no dejarle abrir 2)
                $userHasSession = $user->cashRegisterSessions()->where('status', CashRegisterSessionStatus::OPEN)->exists();

                // 2. CORRECCIÓN: Eliminamos la verificación de si la sucursal tiene sesiones.
                // Lo único que importa es que el usuario NO tenga sesión y la caja NO esté en uso.

                if (!$userHasSession) {
                    return CashRegister::where('branch_id', $user->branch_id)
                        ->where('is_active', true)
                        ->where('in_use', false) // Solo cajas disponibles
                        ->get(['id', 'name']);
                }

                return [];
            }
        ]);
    }
}