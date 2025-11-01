<?php

namespace App\Http\Middleware;

use App\Enums\CashRegisterSessionStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
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
                $availableModuleNames = $subscription->getAvailableModuleNames();
                $permissions = $isOwner
                    ? Permission::query()
                        ->whereIn('module', $availableModuleNames)
                        ->orWhere('module', 'Sistema')
                        ->pluck('name')
                    : $user->getAllPermissions()->pluck('name');

                // --- INICIO: Lógica de Advertencia de Suscripción ---
                $subscriptionWarning = null;
                if ($isOwner) {
                    // MODIFICADO: Obtener la versión MÁS RECIENTE (activa o expirada)
                    // Quitamos el filtro de 'end_date' para encontrar también las expiradas.
                    $currentVersion = $subscription->versions()
                        ->latest('id')
                        ->first();

                    if ($currentVersion) {
                        $endDate = Carbon::parse($currentVersion->end_date)->startOfDay();
                        $today = Carbon::now()->startOfDay();

                        // Usamos diffInDays con 'false' para obtener números negativos si ya pasó
                        $daysRemaining = $today->diffInDays($endDate, false);
                        
                        $warningThreshold = 5; // Mostrar advertencia si faltan 5 días o menos

                        // CASO 1: Expirado ($daysRemaining es negativo)
                        if ($daysRemaining < 0) {
                            $subscriptionWarning = [
                                'daysRemaining' => $daysRemaining,
                                'endDate' => $endDate->translatedFormat('d \d\e F \d\e\l Y'),
                                'message' => "Tu suscripción expiró el " . $endDate->translatedFormat('d \d\e F'),
                                'isExpired' => true // Flag para el frontend
                            ];
                        } 
                        // CASO 2: Vence hoy (0) o en los próximos 5 días (1-5)
                        elseif ($daysRemaining <= $warningThreshold) { 
                            $message = $daysRemaining === 0
                                ? "Tu suscripción vence hoy"
                                : "Tu suscripción vence en {$daysRemaining} " . ($daysRemaining === 1 ? 'día' : 'días');
                            
                            $subscriptionWarning = [
                                'daysRemaining' => $daysRemaining,
                                'endDate' => $endDate->translatedFormat('d \d\e F \d\e\l Y'),
                                'message' => $message,
                                'isExpired' => false // Flag para el frontend
                            ];
                        }
                        // CASO 3: Faltan más de 5 días. $subscriptionWarning se queda en null.
                    }
                }
                // --- FIN: Lógica de Advertencia de Suscripción ---

                return [
                    'user' => $user,
                    'permissions' => $permissions,
                    'is_subscription_owner' => $isOwner,
                    'subscription' => ['commercial_name' => $subscription->commercial_name],
                    'subscriptionWarning' => $subscriptionWarning, // Actualizado
                    'current_branch' => $user->branch,
                    'available_branches' => $subscription->branches()->get(['id', 'name']),
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

            // CORREGIDO: Busca la sesión en la que el usuario es participante, no solo el que la abrió.
            'activeSession' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                return $user->cashRegisterSessions()
                    ->where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->first();
            },

            // AÑADIDO: Busca sesiones a las que el usuario se puede unir si no está en una.
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

            // CORREGIDO: Solo muestra cajas para ABRIR si el usuario no está en una sesión Y no hay otras a las que unirse.
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
