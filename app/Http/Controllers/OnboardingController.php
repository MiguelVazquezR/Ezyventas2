<?php

namespace App\Http\Controllers;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Mail\WelcomeEmail;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    /**
     * Muestra la página de configuración inicial.
     */
    public function show()
    {
        $user = Auth::user();
        $subscription = $user->subscription()->with([
            // Cargar sucursales y la versión activa con sus items
            'branches',
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

        // The AI Agent module is always active while its plan item stays free;
        // once it has a price it behaves like any other add-on module.
        $aiModuleItem = $availableModules->firstWhere('key', 'module_ai_agent');
        if ($aiModuleItem && (float) $aiModuleItem->monthly_price <= 0 && !in_array('module_ai_agent', $activeModuleKeys)) {
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
     * Finaliza el onboarding: guarda límites y módulos, marca el proceso como
     * completado y redirige al dashboard.
     */
    public function finish(Request $request)
    {
        $validated = $request->validate([
            'limits.limit_users'          => 'required|integer|min:1',
            'limits.limit_cash_registers' => 'required|integer|min:1',
            'limits.limit_products'       => 'required|integer|min:1',
            'limits.limit_print_templates' => 'required|integer|min:1',
            'modules'                     => 'required|array|min:1',
            'modules.*'                   => 'string|exists:plan_items,key',
        ]);

        $this->savePlanSettings($validated['limits'], $validated['modules']);

        return $this->completeOnboarding(
            Auth::user(),
            '¡Configuración completada! Te damos la bienvenida.'
        );
    }

    /**
     * Sincroniza los límites de recursos y los módulos de la versión activa.
     */
    private function savePlanSettings(array $limits, array $modules): void
    {
        $version = Auth::user()->subscription->versions()->latest()->first();

        DB::transaction(function () use ($limits, $modules, $version) {
            // 1. Actualizar límites
            foreach ($limits as $key => $quantity) {
                $version->items()->where('item_key', $key)->update(['quantity' => $quantity]);
            }

            // 2. Sincronizar módulos
            $selectedModules = $modules;
            $allModuleItems = PlanItem::where('type', PlanItemType::MODULE)->get()->keyBy('key');

            // The AI Agent module stays active on its own while it is free;
            // once it has a price it is opt-in like the rest of the add-ons.
            $aiModuleItem = $allModuleItems->get('module_ai_agent');
            if ($aiModuleItem && (float) $aiModuleItem->monthly_price <= 0 && !in_array('module_ai_agent', $selectedModules)) {
                $selectedModules[] = 'module_ai_agent';
            }

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
    }

    /**
     * Activates the AI Agent module on the current version while it stays free.
     */
    private function activateAiAgentIfFree(Subscription $subscription): void
    {
        $aiModuleItem = PlanItem::where('type', PlanItemType::MODULE)
            ->where('key', 'module_ai_agent')
            ->first();

        if (!$aiModuleItem || (float) $aiModuleItem->monthly_price > 0) {
            return;
        }

        $version = $subscription->versions()->latest()->first();

        $version->items()->updateOrCreate(
            ['item_key' => 'module_ai_agent'],
            [
                'item_type'      => 'module',
                'name'           => $aiModuleItem->name,
                'quantity'       => 1,
                'unit_price'     => $aiModuleItem->monthly_price,
                'billing_period' => BillingPeriod::MONTHLY,
            ]
        );
    }

    /**
     * Omite la configuración inicial y entra directo al dashboard.
     *
     * Conserva los valores por defecto creados en el registro (plan básico,
     * sucursal "Principal", módulos y límites), activa el agente de IA si
     * sigue siendo gratis y sólo actualiza el nombre comercial si el usuario
     * lo editó en la pantalla de bienvenida.
     */
    public function skip(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'commercial_name' => ['nullable', 'string', 'max:255'],
        ]);

        $commercialName = trim((string) ($validated['commercial_name'] ?? ''));

        if ($commercialName !== '') {
            $user->subscription->update([
                'commercial_name' => $commercialName,
            ]);
        }

        // Keep the AI agent active while it remains free — both the welcome
        // screen and the wizard present it as included during the trial.
        $this->activateAiAgentIfFree($user->subscription);

        return $this->completeOnboarding(
            $user,
            '¡Bienvenido! Tu negocio está listo para empezar a vender.'
        );
    }

    /**
     * Marca el onboarding como completado y envía el email de bienvenida.
     */
    private function completeOnboarding(User $user, string $successMessage)
    {
        $user->subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // Enviar email de bienvenida
        try {
            Mail::to($user->email)->send(new WelcomeEmail($user));
        } catch (\Exception $e) {
            // Si el email falla (ej. Mailgun no configurado), no revertir la transacción.
            // Solo registrar el error.
            Log::error("Error al enviar email de bienvenida: " . $e->getMessage());
        }

        return redirect()->route('dashboard')->with('success', $successMessage);
    }
}