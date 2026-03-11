<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Category;
use App\Models\Service;
use App\Models\Branch;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;
use App\Traits\OptimizeMediaLocal;

class ServiceController extends Controller implements HasMiddleware
{
    use OptimizeMediaLocal;

    public static function middleware(): array
    {
        return [
            new Middleware('can:services.catalog.access', only: ['index']),
            new Middleware('can:services.catalog.create', only: ['create', 'store']),
            new Middleware('can:services.catalog.see_details', only: ['show']),
            new Middleware('can:services.catalog.edit', only: ['edit', 'update']),
            new Middleware('can:services.catalog.delete', only: ['destroy', 'batchDestroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $subscription = $user->branch->subscription;

        $servicesCount = $subscription->services_count;
        
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_services')->first() : null;
        
        $limitServices = $limitItem ? $limitItem->quantity : 100; 
        
        $serviceLimitReached = $limitServices !== -1 && $servicesCount >= $limitServices;

        $query = Service::query()
            ->join('categories', 'services.category_id', '=', 'categories.id')
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            })
            // OPTIMIZACIÓN: Solo traemos los campos estrictamente necesarios de las variantes
            // Esto reduce drásticamente el peso del JSON cuando hay miles de variantes
            ->with([
                'category:id,name', 
                'variants:id,service_id,name,price,duration_estimate', 
                'branches:id,name'
            ])
            ->select('services.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where('services.name', 'LIKE', "%{$searchTerm}%");
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        $sortColumn = $sortField === 'category.name' ? 'categories.name' : $sortField;
        $query->orderBy($sortColumn, $sortOrder);

        $services = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Service/Index', [
            'services' => $services,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'serviceLimitReached' => $serviceLimitReached, 
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $subscription = $user->branch->subscription;
        
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_services')->first() : null;
        $limitServices = $limitItem ? $limitItem->quantity : 100;
        
        $serviceLimitReached = $limitServices !== -1 && $subscription->services_count >= $limitServices;

        return Inertia::render('Service/Create', [
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'service')->get(['id', 'name']),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'current_branch_id' => $user->branch_id,
            'serviceLimitReached' => $serviceLimitReached, 
        ]);
    }

    public function store(StoreServiceRequest $request)
    {
        $validatedData = $request->validated();
        $user = Auth::user();

        $subscription = $user->branch->subscription;
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_services')->first() : null;
        $limitServices = $limitItem ? $limitItem->quantity : 100;
        
        $newItemsCount = 1 + (!empty($validatedData['variants']) ? count($validatedData['variants']) : 0);

        if ($limitServices !== -1 && ($subscription->services_count + $newItemsCount) > $limitServices) {
            return redirect()->back()->with('error', 'Esta acción excede tu límite de servicios. Mejora tu suscripción.');
        }

        $baseSlug = Str::slug($validatedData['name']);
        $slug = $baseSlug;
        $counter = 1;
        
        $subscriptionId = $user->branch->subscription_id;
        while (Service::whereHas('branch', function ($q) use ($subscriptionId) {
            $q->where('subscription_id', $subscriptionId);
        })->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $serviceData = collect($validatedData)->except(['has_variants', 'variants', 'image', 'branch_ids'])->toArray();
        $serviceData['branch_id'] = $user->branch_id; 
        $serviceData['slug'] = $slug;

        if (!empty($validatedData['has_variants'])) {
            $serviceData['base_price'] = 0;
            $serviceData['duration_estimate'] = null;
        }

        $service = Service::create($serviceData);

        if (!empty($validatedData['branch_ids'])) {
            $service->branches()->sync($validatedData['branch_ids']);
        } else {
            $service->branches()->sync([$user->branch_id]);
        }

        if (!empty($validatedData['has_variants']) && !empty($validatedData['variants'])) {
            // OPTIMIZACIÓN: Si son muchas variantes, insertarlas en bloque
            $variantsToInsert = [];
            foreach ($validatedData['variants'] as $variant) {
                $variantsToInsert[] = [
                    'service_id' => $service->id,
                    'name' => $variant['name'],
                    'price' => $variant['price'],
                    'duration_estimate' => $variant['duration_estimate'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if (count($variantsToInsert) > 0) {
                \App\Models\ServiceVariant::insert($variantsToInsert);
            }
        }

        if ($request->hasFile('image')) {
            $mediaItem = $service->addMediaFromRequest('image')->toMediaCollection('service-image');
            $this->optimizeMediaLocal($mediaItem);
        }

        return redirect()->route('services.index')->with('success', 'Servicio creado con éxito.');
    }

    public function edit(Service $service): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        
        $service->load(['media', 'variants', 'branches:id']); 
        
        return Inertia::render('Service/Edit', [
            'service' => $service,
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'service')->get(['id', 'name']),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        $validatedData = $request->validated();
        $user = Auth::user();

        $subscription = $user->branch->subscription;
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_services')->first() : null;
        $limitServices = $limitItem ? $limitItem->quantity : 100;

        if (!empty($validatedData['has_variants']) && !empty($validatedData['variants'])) {
            $newVariantsCount = collect($validatedData['variants'])->filter(fn($v) => empty($v['id']))->count();
            if ($newVariantsCount > 0 && $limitServices !== -1 && ($subscription->services_count + $newVariantsCount) > $limitServices) {
                return redirect()->back()->with('error', 'No puedes agregar estas variantes porque excedes el límite de servicios de tu plan.');
            }
        }

        if ($service->name !== $validatedData['name']) {
            $baseSlug = Str::slug($validatedData['name']);
            $slug = $baseSlug;
            $counter = 1;
            
            $subscriptionId = $user->branch->subscription_id;
            while (Service::whereHas('branch', function ($q) use ($subscriptionId) {
                $q->where('subscription_id', $subscriptionId);
            })->where('slug', $slug)->where('id', '!=', $service->id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validatedData['slug'] = $slug;
        }

        $serviceData = collect($validatedData)->except(['has_variants', 'variants', 'image', 'branch_ids'])->toArray();

        if (!empty($validatedData['has_variants'])) {
            $serviceData['base_price'] = 0;
            $serviceData['duration_estimate'] = null;
        }

        $service->update($serviceData);

        if (!empty($validatedData['branch_ids'])) {
            $service->branches()->sync($validatedData['branch_ids']);
        }

        if (!empty($validatedData['has_variants']) && !empty($validatedData['variants'])) {
            $existingVariantIds = [];
            $newVariantsToInsert = [];
            
            foreach ($validatedData['variants'] as $variantData) {
                if (isset($variantData['id']) && $variantData['id']) {
                    $variant = $service->variants()->find($variantData['id']);
                    if ($variant) {
                        $variant->update([
                            'name' => $variantData['name'],
                            'price' => $variantData['price'],
                            'duration_estimate' => $variantData['duration_estimate'] ?? null,
                        ]);
                        $existingVariantIds[] = $variant->id;
                    }
                } else {
                    $newVariantsToInsert[] = [
                        'service_id' => $service->id,
                        'name' => $variantData['name'],
                        'price' => $variantData['price'],
                        'duration_estimate' => $variantData['duration_estimate'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (count($newVariantsToInsert) > 0) {
                \App\Models\ServiceVariant::insert($newVariantsToInsert);
                // Obtenemos los IDs de los recién insertados para no borrarlos
                $newInsertedIds = $service->variants()->where('created_at', '>=', now()->subSeconds(5))->pluck('id')->toArray();
                $existingVariantIds = array_merge($existingVariantIds, $newInsertedIds);
            }

            // Eliminar las que ya no están en el array
            if(count($existingVariantIds) > 0) {
                $service->variants()->whereNotIn('id', $existingVariantIds)->delete();
            } else {
                $service->variants()->delete();
            }
            
        } else {
            $service->variants()->delete();
        }

        if ($request->hasFile('image')) {
            $service->clearMediaCollection('service-image');
            $mediaItem = $service->addMediaFromRequest('image')->toMediaCollection('service-image');
            $this->optimizeMediaLocal($mediaItem);
        }

        return redirect()->route('services.index')->with('success', 'Servicio actualizado con éxito.');
    }

    public function show(Request $request, Service $service, ActivityLogService $activityLogService): Response
    {
        $service->load(['category', 'media', 'variants', 'branches']);

        // Usamos el servicio
        $formattedActivities = $activityLogService->getFormattedActivities($service, $request, 'Service');

        return Inertia::render('Service/Show', [
            'service' => $service,
            'activities' => $formattedActivities,
        ]);
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return redirect()->route('services.index')->with('success', 'Servicio eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Service::whereIn('id', $request->input('ids'))->delete();
        return redirect()->route('services.index')->with('success', 'Servicios seleccionados eliminados.');
    }
}