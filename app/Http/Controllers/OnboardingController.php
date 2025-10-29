<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Mail\WelcomeEmail;
use Illuminate\Support\Facades\Mail;

class OnboardingController extends Controller
{
    /**
     * Muestra la página de configuración inicial.
     */
    public function show()
    {
        $user = Auth::user();
        $subscription = $user->subscription()->with([
            // Cargar sucursales y cuentas (con sus sucursales asignadas)
            'branches',
            'bankAccounts.branches:id,name',
            'versions' => fn($q) => $q->latest()->first(),
            'versions.items'
        ])->first();

        // Obtener límites actuales
        $limits = $subscription->versions->first()->items
            ->where('item_type', 'limit')
            ->keyBy('item_key');

        return Inertia::render('Onboarding/Setup', [
            'subscription' => $subscription,
            'currentLimits' => $limits,
        ]);
    }

    /**
     * Guarda el Paso 1: Información de Negocio y Sucursales.
     */
    public function storeStep1(Request $request)
    {
        $user = Auth::user();
        $subscription = $user->subscription;

        $validated = $request->validate([
            'subscription.business_name' => 'nullable|string|max:35', // Razón Social (RFC en México)
            'subscription.commercial_name' => 'required|string|max:255',

            'branches' => 'required|array|min:1',
            'branches.*.id' => 'nullable', // Puede ser int o string temporal
            'branches.*.name' => 'required|string|max:255',
            'branches.*.contact_phone' => 'nullable|string|max:20',
            'branches.*.contact_email' => 'nullable|email|max:255',
            'branches.*.is_main' => 'required|boolean',
            'branches.*.address' => 'nullable|string|max:500',

            // --- VALIDACIÓN DE HORARIOS MEJORADA ---
            'branches.*.operating_hours' => 'nullable|array|size:7',
            'branches.*.operating_hours.*.day' => 'required|string',
            'branches.*.operating_hours.*.open' => 'required|boolean',
            'branches.*.operating_hours.*.from' => 'nullable|date_format:H:i',
            'branches.*.operating_hours.*.to' => 'nullable|date_format:H:i',
        ]);

        DB::transaction(function () use ($subscription, $validated, $user) {

            // 1. Actualizar datos de la Suscripción
            $subscription->update([
                'commercial_name' => $validated['subscription']['commercial_name'],
                'business_name' => $validated['subscription']['business_name'],
            ]);

            $mainBranchFound = false;
            $existingIds = [];
            $firstBranchId = null; // Para asignar al usuario si su branch_id es null

            // 2. Actualizar o crear Sucursales
            foreach ($validated['branches'] as $branchData) {
                // Si la ID es temporal (ej. 'temp_0'), se tratará como 'null'
                $branchId = (isset($branchData['id']) && !is_numeric($branchData['id']))
                    ? null
                    : ($branchData['id'] ?? null);

                if ($branchData['is_main']) {
                    $mainBranchFound = true;
                }

                $branchModel = Branch::updateOrCreate(
                    [
                        'id' => $branchId,
                        'subscription_id' => $subscription->id
                    ],
                    $branchData
                );
                $existingIds[] = $branchModel->id;

                if (!$firstBranchId) {
                    $firstBranchId = $branchModel->id;
                }
            }

            // Si no se marcó ninguna como principal, forzar la primera
            if (!$mainBranchFound && count($existingIds) > 0) {
                Branch::find($existingIds[0])->update(['is_main' => true]);
            }

            // Asegurar que el usuario esté asignado a una sucursal (la primera por defecto si no tenía)
            if ($firstBranchId && is_null($user->branch_id)) {
                $user->branch_id = $firstBranchId;
                $user->save();
            }

            // Opcional: eliminar sucursales que el usuario pudo haber borrado de la lista
            $subscription->branches()->whereNotIn('id', $existingIds)->delete();
        });

        // Usamos back() con 'preserve_state' => false para forzar la recarga de props
        return redirect()->back()->with('success', 'Información guardada.');
    }

    /**
     * Guarda el Paso 2: Límites de Recursos.
     */
    public function storeStep2(Request $request)
    {
        $validated = $request->validate([
            'limits.limit_users' => 'required|integer|min:1',
            'limits.limit_cash_registers' => 'required|integer|min:1',
            'limits.limit_products' => 'required|integer|min:1',
            'limits.limit_print_templates' => 'required|integer|min:1',
        ]);

        $user = Auth::user();
        $version = $user->subscription->versions()->latest()->first();

        DB::transaction(function () use ($validated, $version) {
            foreach ($validated['limits'] as $key => $quantity) {
                $version->items()->where('item_key', $key)->update(['quantity' => $quantity]);
            }
        });

        return redirect()->back();
    }

    /**
     * Guarda el Paso 3: Cuentas Bancarias.
     */
    public function storeStep3(Request $request)
    {
        $user = Auth::user();
        $subscription = $user->subscription;

        $validated = $request->validate([
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.bank_name' => 'required|string|max:100',
            'bank_accounts.*.owner_name' => 'required|string|max:255',
            'bank_accounts.*.balance' => 'required|numeric|min:0',
            'bank_accounts.*.account_name' => 'required|string|max:100',
            'bank_accounts.*.account_number' => 'nullable|string|max:50',
            'bank_accounts.*.clabe' => 'nullable|string|max:18',
            'bank_accounts.*.branch_ids' => 'nullable|array', // IDs de sucursales a las que se asigna
        ]);

        DB::transaction(function () use ($subscription, $validated) {

            // Sincronizar cuentas: Eliminar las que ya no están en la lista
            $existingIds = [];
            if (!empty($validated['bank_accounts'])) {
                foreach ($validated['bank_accounts'] as $accountData) {
                    $account = BankAccount::updateOrCreate(
                        [
                            'id' => $accountData['id'] ?? null,
                            'subscription_id' => $subscription->id,
                        ],
                        [
                            'bank_name' => $accountData['bank_name'],
                            'owner_name' => $accountData['owner_name'],
                            'balance' => $accountData['balance'],
                            'account_name' => $accountData['account_name'],
                            'account_number' => $accountData['account_number'],
                            'clabe' => $accountData['clabe'],
                        ]
                    );

                    // Sincronizar sucursales
                    if (!empty($accountData['branch_ids'])) {
                        $account->branches()->sync($accountData['branch_ids']);
                    } else {
                        $account->branches()->detach();
                    }
                    $existingIds[] = $account->id;
                }
            }
            // Eliminar cuentas que el usuario borró de la UI
            $subscription->bankAccounts()->whereNotIn('id', $existingIds)->delete();
        });
    }

    /**
     * Marca el onboarding como completado y redirige al dashboard.
     */
    public function finish(Request $request)
    {
        $this->storeStep3($request);
        $user = Auth::user();
        $user->subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // Enviar email de bienvenida
        try {
            Mail::to($user->email)->send(new WelcomeEmail($user));
        } catch (\Exception $e) {
            // Si el email falla (ej. Mailgun no configurado), no revertir la transacción.
            // Solo registrar el error.
            \Illuminate\Support\Facades\Log::error("Error al enviar email de bienvenida: " . $e->getMessage());
        }

        return redirect()->route('dashboard')->with('success', '¡Configuración completada! Te damos la bienvenida.');
    }
}
