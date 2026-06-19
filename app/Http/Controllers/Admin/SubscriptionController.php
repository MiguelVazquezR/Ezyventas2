<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\PlanItem;
use App\Models\SubscriptionVersion;
use App\Models\SettingDefinition;
use App\Http\Requests\Admin\UpdateSubscriptionVersionRequest;
use App\Http\Requests\Admin\UpdateVersionItemsRequest;
use App\Http\Requests\Admin\StoreVersionWithPaymentRequest;
use App\Actions\Admin\Subscriptions\UpdateSubscriptionVersionAction;
use App\Actions\Admin\Subscriptions\UpdateVersionItemsAction;
use App\Actions\Admin\Subscriptions\CreateVersionWithPaymentAction;
use App\Actions\Admin\Subscriptions\DeleteVersionAction;
use App\Actions\Admin\Subscriptions\UpdateEntitySettingsAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    /**
     * Muestra la lista paginada de todos los suscriptores del SaaS.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'sortField', 'sortOrder', 'status']);

        $subscriptions = Subscription::query()
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('commercial_name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                if ($status === 'expirado') {
                    $query->having('latest_version_end_date', '<', now()->startOfDay());
                } elseif ($status === 'activo') {
                    $query->having('latest_version_end_date', '>=', now()->startOfDay());
                } else {
                    // suspendido o sin versiones
                    $query->havingNull('latest_version_end_date');
                }
            })
            ->when($filters['sortField'] ?? null, function ($query, $sortField) use ($filters) {
                $query->orderBy($sortField, $filters['sortOrder'] === 'asc' ? 'asc' : 'desc');
            }, function ($query) {
                $query->latest('id');
            })
            ->paginate($request->input('rows', 20))
            ->withQueryString();

        return Inertia::render('Admin/Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => $filters,
        ]);
    }

    /**
     * Muestra los detalles completos de un suscriptor específico.
     */
    public function show($id)
    {
        // Buscamos explícitamente por ID para evitar el conflicto con el getRouteKeyName() ('slug')
        $subscription = Subscription::findOrFail($id);

        // 1. Cargar relaciones vitales y contadores de uso
        $subscription->load([
            'branches',
            'versions' => fn($query) => $query->with(['items', 'payments'])->latest('id'),
            'media'
        ])->loadCount([
            'branches',
            'users',
            'bankAccounts',
            'products',
            'cashRegisters',
            'printTemplates',
            'services',
        ]);

        // 2. Procesar versiones usando el helper existente en el modelo
        $subscription->versions = $subscription->getVersionsWithComparison();

        // 3. Obtener el catálogo maestro de ítems
        $planItems = PlanItem::where('is_active', true)->get();

        // 4. Identificar la versión activa actualmente
        $currentVersion = $subscription->versions->first(function ($v) {
            $start = Carbon::parse($v->start_date);
            $end = Carbon::parse($v->end_date)->startOfDay();
            return $start->lte(now()) && $end->gte(now()->startOfDay());
        }) ?? $subscription->versions->first();

        // 5. Mapeo dinámico de usos de recursos actuales
        $usages = [
            'limit_branches' => $subscription->branches_count,
            'limit_users' => $subscription->users_count,
            'limit_products' => $subscription->products_count,
            'limit_services' => $subscription->services_count,
            'limit_cash_registers' => $subscription->cash_registers_count,
            'limit_print_templates' => $subscription->print_templates_count,
        ];

        // Fallbacks visuales si un límite no trae un ícono definido en su columna 'meta'
        $defaultIcons = [
            'limit_branches' => 'pi pi-building',
            'limit_users' => 'pi pi-users',
            'limit_products' => 'pi pi-barcode',
            'limit_services' => 'pi pi-wrench',
            'limit_cash_registers' => 'pi pi-inbox',
            'limit_print_templates' => 'pi pi-palette',
        ];

        // 6. Construir los límites dinámicos
        $dynamicLimits = $planItems->where('type', 'limit')->map(function ($item) use ($currentVersion, $usages, $defaultIcons) {
            $versionItem = $currentVersion ? $currentVersion->items->where('item_key', $item->key)->first() : null;

            // Verificamos si existe un icono en el JSON meta del plan, de lo contrario usamos el predeterminado
            $meta = is_string($item->meta) ? json_decode($item->meta, true) : $item->meta;
            $icon = $meta['icon'] ?? ($defaultIcons[$item->key] ?? 'pi pi-chart-pie');

            return [
                'key' => $item->key,
                'label' => $item->name,
                'icon' => $icon,
                'usage' => $usages[$item->key] ?? 0,
                'limit' => $versionItem ? $versionItem->quantity : 0,
            ];
        })->values();

        // 7. Construir los módulos dinámicos
        $dynamicModules = $planItems->where('type', 'module')->map(function ($item) use ($currentVersion) {
            $meta = is_string($item->meta) ? json_decode($item->meta, true) : $item->meta;

            return [
                'key' => $item->key,
                'label' => $item->name,
                'icon' => $meta['icon'] ?? 'pi pi-box',
                'is_active' => $currentVersion ? $currentVersion->items->where('item_key', $item->key)->isNotEmpty() : false,
            ];
        })->values();

        // 8. Construir los datos de configuraciones (settings)
        $settingsData = $this->buildSettingsData($subscription);

        // 9. Calcular valor del plan y descuento por referidos activos
        $planValue = $subscription->getCurrentMonthlyCost();
        $referrerActiveDiscountPct = $subscription->getReferrerActiveDiscountPct();

        return Inertia::render('Admin/Subscriptions/Show', [
            'subscription' => $subscription,
            'planItems' => $planItems,
            'dynamicLimits' => $dynamicLimits,
            'dynamicModules' => $dynamicModules,
            'subscriptionStatus' => $subscription->getStatusData(),
            'fiscalDocumentUrl' => $subscription->getFirstMediaUrl('fiscal-documents') ?: null,
            'settingsData' => $settingsData,
            'planValue' => $planValue,
            'referrerActiveDiscountPct' => (float) $referrerActiveDiscountPct,
            'subscriptionCost' => (float) $planValue,
        ]);
    }

    /**
     * Actualiza manualmente las fechas y límites de una versión específica.
     */
    public function updateVersion(SubscriptionVersion $version, UpdateSubscriptionVersionRequest $request, UpdateSubscriptionVersionAction $action)
    {
        // Delegamos la validación al Request y la lógica de negocio al Action
        $action->execute($version, $request->validated());

        return redirect()->back()->with('success', 'La vigencia y los recursos del plan han sido actualizados exitosamente.');
    }

    /**
     * Actualiza los items (módulos y límites) de una versión específica.
     */
    public function updateVersionItems(
        SubscriptionVersion $version,
        UpdateVersionItemsRequest $request,
        UpdateVersionItemsAction $action,
    ) {
        $action->execute($version, $request->validated());

        return redirect()->back()->with('success', 'Los items de la versión han sido actualizados exitosamente.');
    }

    /**
     * Crea una nueva versión con su pago asociado para una suscripción.
     */
    public function storeVersion(
        Subscription $subscription,
        StoreVersionWithPaymentRequest $request,
        CreateVersionWithPaymentAction $action,
    ) {
        $action->execute($subscription, $request->validated());

        return redirect()->back()->with('success', 'La nueva versión y el pago han sido registrados exitosamente.');
    }

    /**
     * Elimina una versión junto con sus pagos e items asociados.
     */
    public function destroyVersion(
        SubscriptionVersion $version,
        DeleteVersionAction $action,
    ) {
        $action->execute($version);

        return redirect()->back()->with('success', 'La versión y sus pagos asociados han sido eliminados correctamente.');
    }

    /**
     * Actualiza las configuraciones de una entidad específica (suscripción, sucursal o usuario).
     */
    public function updateSettings(Request $request, UpdateEntitySettingsAction $action)
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'in:subscription,branch,user'],
            'entity_id'   => ['required', 'integer'],
            'settings'    => ['required', 'array'],
        ]);

        $entity = match ($validated['entity_type']) {
            'subscription' => Subscription::findOrFail($validated['entity_id']),
            'branch'       => \App\Models\Branch::findOrFail($validated['entity_id']),
            'user'         => \App\Models\User::findOrFail($validated['entity_id']),
        };

        $action->execute($entity, $validated['settings']);

        return redirect()->back()->with('success', 'Configuraciones actualizadas exitosamente.');
    }

    /**
     * Construye los datos de configuraciones para la vista de detalle.
     */
    private function buildSettingsData(Subscription $subscription): array
    {
        $definitions = SettingDefinition::orderBy('name')->get();

        // Cargar sucursales con sus usuarios y settings (reutiliza la relación ya cargada)
        $branches = $subscription->branches->load(['users' => fn($q) => $q->with('settings'), 'settings']);

        $subscriptionSettings = $subscription->settings()->pluck('value', 'setting_definition_id');

        // Construir la lista plana de entidades
        $entities = [];

        // 1. La suscripción misma
        $entities[] = $this->formatSettingsEntity(
            'subscription',
            $subscription->id,
            $subscription->commercial_name . ' (Suscripción)',
            $subscriptionSettings
        );

        // 2. Cada sucursal
        foreach ($branches as $branch) {
            $branchSettings = $branch->settings->pluck('value', 'setting_definition_id');
            $entities[] = $this->formatSettingsEntity(
                'branch',
                $branch->id,
                $branch->name . ' (Sucursal)',
                $branchSettings
            );

            // 3. Cada usuario de la sucursal
            foreach ($branch->users as $user) {
                $userSettings = $user->settings->pluck('value', 'setting_definition_id');
                $entities[] = $this->formatSettingsEntity(
                    'user',
                    $user->id,
                    $user->name . ' (Usuario)',
                    $userSettings
                );
            }
        }

        // Agrupar definiciones por módulo con valores resueltos para cada entidad
        $definitionsByModule = [];
        foreach ($definitions as $definition) {
            $resolvedValue = $subscriptionSettings->get($definition->id) ?? $definition->default_value;

            if (in_array($definition->type, ['select', 'list']) && is_string($resolvedValue)) {
                $decoded = json_decode($resolvedValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $resolvedValue = $decoded;
                }
            }

            $definitionsByModule[$definition->module][] = [
                'id'            => $definition->id,
                'name'          => $definition->name,
                'key'           => $definition->key,
                'description'   => $definition->description,
                'type'          => $definition->type,
                'level'         => $definition->level,
                'default_value' => in_array($definition->type, ['select', 'list']) && is_string($definition->default_value)
                    ? json_decode($definition->default_value, true)
                    : $definition->default_value,
            ];
        }

        return [
            'definitions_by_module' => $definitionsByModule,
            'entities'              => $entities,
        ];
    }

    /**
     * Formatea una entidad para el frontend.
     */
    private function formatSettingsEntity(string $type, int $id, string $name, $values): array
    {
        return [
            'type'   => $type,
            'id'     => $id,
            'name'   => $name,
            'values' => $values,
        ];
    }
}
