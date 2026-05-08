<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\PlanItem;
use App\Models\SubscriptionVersion;
use App\Http\Requests\Admin\UpdateSubscriptionVersionRequest;
use App\Actions\Admin\Subscriptions\UpdateSubscriptionVersionAction;
use Illuminate\Http\Request;
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
                $query->where('status', $status);
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
            'branches', 'users', 'bankAccounts', 'products', 'cashRegisters', 'printTemplates', 'services',
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

        // 8. Renderizar la vista pasando la información orquestada
        return Inertia::render('Admin/Subscriptions/Show', [
            'subscription' => $subscription,
            'planItems' => $planItems, // Aún se pasa para el modal de edición
            'dynamicLimits' => $dynamicLimits,
            'dynamicModules' => $dynamicModules,
            'subscriptionStatus' => $subscription->getStatusData(),
            'fiscalDocumentUrl' => $subscription->getFirstMediaUrl('fiscal-documents') ?: null,
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
}