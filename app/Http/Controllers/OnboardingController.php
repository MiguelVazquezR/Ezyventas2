<?php

namespace App\Http\Controllers;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\PlanItem;
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

        // Obtener límites actuales con fallback seguro
        $currentVersion = $subscription->versions->first();
        $limits = $currentVersion?->items
            ?->where('item_type', 'limit')
            ?->keyBy('item_key') ?? collect();

        // Módulos disponibles en el sistema
        $availableModules = PlanItem::where('type', PlanItemType::MODULE)
            ->where('is_active', true)
            ->get()
            ->values();

        // Módulos actualmente activos en la versión (si ya existen)
        $activeModuleKeys = $currentVersion?->items
            ?->where('item_type', 'module')
            ?->pluck('item_key')
            ?->toArray() ?? [];

        // Si no hay módulos activos aún (primera carga), por defecto todos activos
        if (empty($activeModuleKeys)) {
            $activeModuleKeys = $availableModules->pluck('key')->toArray();
        }

        // Ensure AI Agent module is always active
        if (!in_array('module_ai_agent', $activeModuleKeys)) {
            $activeModuleKeys[] = 'module_ai_agent';
        }

        // Items de tipo límite disponibles en el sistema (para precios, descripciones, etc.)
        $availableLimits = PlanItem::where('type', PlanItemType::LIMIT)
            ->where('is_active', true)
            ->get()
            ->values();

        return Inertia::render('Onboarding/Setup', [
            'subscription'     => $subscription,
            'currentLimits'    => $limits,
            'availableModules' => $availableModules,
            'availableLimits'  => $availableLimits,
            'activeModuleKeys' => $activeModuleKeys,
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
            // --- NUEVOS CAMPOS ---
            'subscription.contact_phone' => 'nullable|string|max:20',
            'subscription.address' => 'nullable|string|max:500', // Validamos como string simple

            'branches' => 'required|array|min:1',
            'branches.*.id' => 'nullable', // Puede ser int o string temporal
            'branches.*.name' => 'required|string|max:255',
            'branches.*.contact_phone' => 'nullable|string|max:20',
            'branches.*.contact_email' => 'nullable|email|max:255',
            'branches.*.is_main' => 'required|boolean',
            'branches.*.address' => 'nullable|string|max:600',

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
                'contact_phone' => $validated['subscription']['contact_phone'],
                // Guardamos la dirección como array para respetar el cast del Modelo
                'address' => $validated['subscription']['address'] 
                    ? ['text' => $validated['subscription']['address']] 
                    : null,
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
     * Guarda el Paso 2: Límites de Recursos y Módulos.
     */
    public function storeStep2(Request $request)
    {
        $validated = $request->validate([
            'limits.limit_users'          => 'required|integer|min:1',
            'limits.limit_cash_registers' => 'required|integer|min:1',
            'limits.limit_products'       => 'required|integer|min:1',
            'limits.limit_print_templates' => 'required|integer|min:1',
            'modules'                     => 'required|array|min:1',
            'modules.*'                   => 'string|exists:plan_items,key',
        ]);

        $user = Auth::user();
        $version = $user->subscription->versions()->latest()->first();

        DB::transaction(function () use ($validated, $version) {
            // 1. Actualizar límites
            foreach ($validated['limits'] as $key => $quantity) {
                $version->items()->where('item_key', $key)->update(['quantity' => $quantity]);
            }

            // 2. Sincronizar módulos
            $selectedModules = $validated['modules'];

            // Ensure AI Agent module is always active
            if (!in_array('module_ai_agent', $selectedModules)) {
                $selectedModules[] = 'module_ai_agent';
            }

            $allModuleItems = PlanItem::where('type', PlanItemType::MODULE)->get()->keyBy('key');

            foreach ($allModuleItems as $moduleKey => $planItem) {
                if (in_array($moduleKey, $selectedModules)) {
                    $version->items()->updateOrCreate(
                        ['item_key' => $moduleKey],
                        [
                            'item_type'      => 'module',
                            'name'           => $planItem->name,
                            'quantity'       => 1,
                            'unit_price'     => $planItem->monthly_price,
                            'billing_period' => BillingPeriod::MONTHLY,
                        ]
                    );
                } else {
                    $version->items()->where('item_key', $moduleKey)->delete();
                }
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