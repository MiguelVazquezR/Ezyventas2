<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Category;
use App\Models\Service;
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

        // SOLUCIÓN: Usar JOIN para permitir el ordenamiento por columnas de tablas relacionadas
        $query = Service::query()
            ->join('categories', 'services.category_id', '=', 'categories.id')
            ->where('branch_id', $branchId)
            ->with('category:id,name', 'variants')
            // Seleccionar explícitamente las columnas de la tabla principal para evitar conflictos
            ->select('services.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            // Especificar la tabla en la columna 'name' para evitar ambigüedad
            $query->where('services.name', 'LIKE', "%{$searchTerm}%");
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        // Usar el nombre completo de la columna para el ordenamiento
        $sortColumn = $sortField === 'category.name' ? 'categories.name' : $sortField;
        $query->orderBy($sortColumn, $sortOrder);

        $services = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Service/Index', [
            'services' => $services,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return Inertia::render('Service/Create', [
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'service')->get(['id', 'name']),
        ]);
    }

    public function store(StoreServiceRequest $request)
    {
        $validatedData = $request->validated();
        $user = Auth::user();

        $baseSlug = Str::slug($validatedData['name']);
        $slug = $baseSlug;
        $counter = 1;
        // El slug debe ser único por sucursal
        while (Service::where('branch_id', $user->branch_id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        // Filtramos para no inyectar campos ajenos a la tabla 'services'
        $serviceData = collect($validatedData)->except(['has_variants', 'variants', 'image'])->toArray();
        $serviceData['branch_id'] = $user->branch_id;
        $serviceData['slug'] = $slug;

        // Si tiene variantes, nos aseguramos que el precio base del padre sea 0
        if (!empty($validatedData['has_variants'])) {
            $serviceData['base_price'] = 0;
            $serviceData['duration_estimate'] = null;
        }

        $service = Service::create($serviceData);

        // Guardar variantes (si aplica)
        if (!empty($validatedData['has_variants']) && !empty($validatedData['variants'])) {
            foreach ($validatedData['variants'] as $variant) {
                $service->variants()->create([
                    'name' => $variant['name'],
                    'price' => $variant['price'],
                    'duration_estimate' => $variant['duration_estimate'] ?? null,
                ]);
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
        $subscriptionId = Auth::user()->branch->subscription_id;
        
        // MODIFICACIÓN: Cargamos explícitamente las variantes para la vista de Edición
        $service->load(['media', 'variants']); 
        
        return Inertia::render('Service/Edit', [
            'service' => $service,
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'service')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        $validatedData = $request->validated();

        if ($service->name !== $validatedData['name']) {
            $baseSlug = Str::slug($validatedData['name']);
            $slug = $baseSlug;
            $counter = 1;
            // Asegurarse de que el nuevo slug sea único, excluyendo el servicio actual
            while (Service::where('branch_id', $service->branch_id)->where('slug', $slug)->where('id', '!=', $service->id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validatedData['slug'] = $slug; // Añadir el nuevo slug a los datos a actualizar
        }

        // Extraer info de la tabla base
        $serviceData = collect($validatedData)->except(['has_variants', 'variants', 'image'])->toArray();

        if (!empty($validatedData['has_variants'])) {
            $serviceData['base_price'] = 0;
            $serviceData['duration_estimate'] = null;
        }

        $service->update($serviceData);

        // Sincronizar Variantes (Crear, Actualizar o Eliminar)
        if (!empty($validatedData['has_variants']) && !empty($validatedData['variants'])) {
            $existingVariantIds = [];
            
            foreach ($validatedData['variants'] as $variantData) {
                if (isset($variantData['id']) && $variantData['id']) {
                    // Actualizar variante existente
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
                    // Crear variante nueva agregada durante la edición
                    $newVariant = $service->variants()->create([
                        'name' => $variantData['name'],
                        'price' => $variantData['price'],
                        'duration_estimate' => $variantData['duration_estimate'] ?? null,
                    ]);
                    $existingVariantIds[] = $newVariant->id;
                }
            }
            
            // Eliminar de la base de datos las variantes que el usuario haya borrado dándole al icono del "basurero"
            $service->variants()->whereNotIn('id', $existingVariantIds)->delete();
        } else {
            // Si apagaron el switch completamente, eliminamos todas las variantes atadas a este servicio
            $service->variants()->delete();
        }

        if ($request->hasFile('image')) {
            $service->clearMediaCollection('service-image');
            $mediaItem = $service->addMediaFromRequest('image')->toMediaCollection('service-image');
            $this->optimizeMediaLocal($mediaItem);
        }

        return redirect()->route('services.index')->with('success', 'Servicio actualizado con éxito.');
    }

    public function show(Service $service): Response
    {
        $service->load(['category', 'media', 'activities.causer', 'variants']);
        $translations = config('log_translations.Service', []);

        $formattedActivities = $service->activities->map(function ($activity) use ($translations) {
            $changes = ['before' => [], 'after' => []];
            if (isset($activity->properties['old'])) {
                foreach ($activity->properties['old'] as $key => $value) {
                    $changes['before'][($translations[$key] ?? $key)] = $value;
                }
            }
            if (isset($activity->properties['attributes'])) {
                foreach ($activity->properties['attributes'] as $key => $value) {
                    $changes['after'][($translations[$key] ?? $key)] = $value;
                }
            }
            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                'changes' => $changes,
            ];
        });

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
