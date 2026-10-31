<?php

namespace App\Http\Controllers;

use App\Enums\PlanItemType;
use App\Mail\WelcomeEmail;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\User;
use App\Services\Subscription\SubscriptionPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanService $planService,
    ) {
    }

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

        // The active version drives the modules shown in the wizard.
        $currentVersion = $subscription->versions->first();

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

        // Modules that cost nothing (AI agent included) are always active: they
        // are part of the free plan, not add-ons the user can remove.
        $activeModuleKeys = array_values(array_unique(array_merge(
            $activeModuleKeys,
            $this->planService->freeModuleKeys(),
        )));

        // Items de tipo límite disponibles en el sistema (para precios, descripciones, etc.)
        $availableLimits = PlanItem::where('type', PlanItemType::LIMIT)
            ->where('is_active', true)
            ->get()
            ->values();

        return Inertia::render('Onboarding/Setup', [
            'subscription'     => $subscription,
            'planLimits'       => $this->planService->fixedLimits(),
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
     * Finalizes the onboarding: the free plan limits are fixed by the plan
     * itself, so only the selected modules are stored before completing.
     */
    public function finish(Request $request)
    {
        $validated = $request->validate([
            'modules'  => 'required|array|min:1',
            'modules.*' => 'string|exists:plan_items,key',
        ]);

        $this->savePlanSettings($validated['modules']);

        return $this->completeOnboarding(
            Auth::user(),
            '¡Configuración completada! Te damos la bienvenida.'
        );
    }

    /**
     * Applies the fixed plan limits and the selected modules to the active
     * version of the subscription.
     */
    private function savePlanSettings(array $modules): void
    {
        $subscription = Auth::user()->subscription;

        DB::transaction(function () use ($subscription, $modules) {
            $this->planService->applyFixedLimits($subscription);
            $this->planService->syncModules($subscription, $modules);
        });
    }

    /**
     * Skips the initial setup and goes straight to the dashboard.
     *
     * Keeps the defaults created at registration (free plan limits, the
     * "Principal" branch, modules and free add-ons) and only updates the
     * commercial name when the user edited it on the welcome screen.
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

        // Free modules (AI agent included) stay active: they are part of the
        // free plan, not trial features.
        $this->planService->refreshModules($user->subscription);

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