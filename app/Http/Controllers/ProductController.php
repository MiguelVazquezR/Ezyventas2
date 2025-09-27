<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class ProductController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.access', only: ['index']),
            new Middleware('can:products.create', only: ['create', 'store']),
            new Middleware('can:products.see_details', only: ['show']),
            new Middleware('can:products.edit', only: ['edit', 'update']),
            new Middleware('can:products.delete', only: ['destroy', 'batchDestroy']),
        ];
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // SOLUCIÓN: La consulta ahora obtiene TODOS los productos de la sucursal del usuario,
        // sin filtrar por global_product_id.
        $query = Product::query()->where('branch_id', $user->branch_id)
            ->with('media');

        // La lógica de búsqueda global se mantiene
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sku', 'LIKE', "%{$searchTerm}%");
            });
        }

        // El ordenamiento se mantiene
        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $products = $query->paginate($request->input('rows', 20))
            ->withQueryString();

        return Inertia::render('Product/Index', [
            'products' => $products,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->subscription->id;

        // --- MARCAS ---
        $subscriberBrands = Brand::where('subscription_id', Auth::user()->branch->subscription_id)->get(['id', 'name']);
        $globalBrands = Brand::whereNull('subscription_id')->get(['id', 'name']);

        $formattedBrands = [
            ['label' => 'Mis Marcas', 'items' => $subscriberBrands],
            ['label' => 'Marcas del Catálogo', 'items' => $globalBrands],
        ];

        // --- DATOS ADICIONALES ---
        $categories = Category::where([
            'subscription_id' => $subscriptionId,
            'type' => 'product',
        ])->get(['id', 'name']);
        $providers = Provider::where('subscription_id', $subscriptionId)->get(['id', 'name']);
        $attributeDefinitions = AttributeDefinition::with('options')
            ->where('subscription_id', $subscriptionId)
            ->get();

        return Inertia::render('Product/Create', [
            'categories' => $categories,
            'brands' => $formattedBrands,
            'providers' => $providers,
            'attributeDefinitions' => $attributeDefinitions,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        DB::transaction(function () use ($request) {
            $user = Auth::user();
            $branch = $user->branch;
            $validatedData = $request->validated();

            // Generar un slug único
            $baseSlug = Str::slug($validatedData['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Product::where('branch_id', $branch->id)->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validatedData['slug'] = $slug;

            // Calcular stock total para productos con variantes
            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                $validatedData['current_stock'] = collect($variantsMatrix)->where('selected', true)->sum('current_stock');
            }

            // Crear el producto principal
            $productData = collect($validatedData)->except(['general_images', 'variant_images', 'variants_matrix'])->all();
            $product = Product::create(array_merge($productData, ['branch_id' => $branch->id]));

            // --- SOLUCIÓN AL PROBLEMA DE ARCHIVOS TEMPORALES ---
            // Usamos addMediaFromRequest para cada archivo, lo que es más seguro.

            // Procesar imágenes generales
            if ($request->hasFile('general_images')) {
                foreach (array_keys($request->file('general_images')) as $key) {
                    $product->addMediaFromRequest("general_images.{$key}")
                        ->toMediaCollection('product-general-images');
                }
            }

            // Procesar imágenes de variantes
            if ($request->hasFile('variant_images')) {
                foreach (array_keys($request->file('variant_images')) as $optionValue) {
                    $product->addMediaFromRequest("variant_images.{$optionValue}")
                        ->withCustomProperties(['variant_option' => $optionValue])
                        ->toMediaCollection('product-variant-images');
                }
            }

            // Gestionar la creación de atributos para las variantes
            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                foreach ($variantsMatrix as $combination) {
                    if (empty($combination['selected'])) continue;

                    $attributes = collect($combination)->except(['selected', 'sku_suffix', 'current_stock', 'min_stock', 'max_stock', 'selling_price', 'row_id'])->all();

                    $product->productAttributes()->create([
                        'attributes' => $attributes,
                        'sku_suffix' => $combination['sku_suffix'],
                        'current_stock' => $combination['current_stock'],
                        'min_stock' => $combination['min_stock'],
                        'max_stock' => $combination['max_stock'],
                        'selling_price_modifier' => $combination['selling_price'] - $product->selling_price,
                    ]);
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Producto creado con éxito.');
    }

    public function edit(Product $product): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        // Cargar el producto con sus relaciones
        $product->load('productAttributes', 'media');

        // Lógica para obtener marcas (propias y globales)
        $subscriberBrands = Brand::where('subscription_id', $subscriptionId)->get(['id', 'name']);
        $globalBrands = Brand::whereNull('subscription_id')
            ->whereHas('businessTypes', function ($query) use ($user) {
                $query->where('business_type_id', $user->branch->subscription->business_type_id);
            })->get(['id', 'name']);

        $formattedBrands = [
            ['label' => 'Mis Marcas', 'items' => $subscriberBrands],
            ['label' => 'Marcas del Catálogo', 'items' => $globalBrands],
        ];

        return Inertia::render('Product/Edit', [
            'product' => $product,
            'categories' => Category::where([
                'subscription_id' => $subscriptionId,
                'type' => 'product',
            ])->get(['id', 'name']),
            'brands' => $formattedBrands,
            'providers' => Provider::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'attributeDefinitions' => AttributeDefinition::with('options')->where('subscription_id', $subscriptionId)->get(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        DB::transaction(function () use ($request, $product) {
            $validatedData = $request->validated();

            // Lógica para slug y stock similar a la de store
            if ($product->name !== $validatedData['name']) {
                $baseSlug = Str::slug($validatedData['name']);
                $slug = $baseSlug;
                $counter = 1;
                while (Product::where('branch_id', $product->branch_id)->where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $validatedData['slug'] = $slug;
            }

            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                $validatedData['current_stock'] = collect($variantsMatrix)->where('selected', true)->sum('current_stock');
            }

            // Actualizar producto principal
            $productData = collect($validatedData)->except(['general_images', 'variant_images', 'variants_matrix', 'deleted_media_ids'])->all();
            $product->update($productData);

            // Eliminar imágenes marcadas
            if (!empty($validatedData['deleted_media_ids'])) {
                $product->media()->whereIn('id', $validatedData['deleted_media_ids'])->delete();
            }

            // Añadir nuevas imágenes
            if ($request->hasFile('general_images')) {
                foreach (array_keys($request->file('general_images')) as $key) {
                    $product->addMediaFromRequest("general_images.{$key}")->toMediaCollection('product-general-images');
                }
            }
            if ($request->hasFile('variant_images')) {
                foreach (array_keys($request->file('variant_images')) as $optionValue) {
                    $product->addMediaFromRequest("variant_images.{$optionValue}")->withCustomProperties(['variant_option' => $optionValue])->toMediaCollection('product-variant-images');
                }
            }

            // Sincronizar variantes
            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                $product->productAttributes()->delete(); // Simple: borrar y recrear
                foreach ($variantsMatrix as $combination) {
                    if (empty($combination['selected'])) continue;
                    $attributes = collect($combination)->except(['selected', 'sku_suffix', 'current_stock', 'min_stock', 'max_stock', 'selling_price', 'row_id'])->all();
                    $product->productAttributes()->create([
                        'attributes' => $attributes,
                        'sku_suffix' => $combination['sku_suffix'],
                        'current_stock' => $combination['current_stock'],
                        'min_stock' => $combination['min_stock'],
                        'max_stock' => $combination['max_stock'],
                        'selling_price_modifier' => $combination['selling_price'] - $product->selling_price,
                    ]);
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Producto actualizado con éxito.');
    }

    public function show(Product $product): Response
    {
        // Cargar relaciones y el historial de actividad
        $product->load([
            'category',
            'brand',
            'provider',
            'productAttributes',
            'media',
            'activities.causer' // Cargar actividades y el usuario que las causó
        ]);

        // Cargar TODAS las promociones asociadas a este producto (activas e inactivas)
        $promotions = Promotion::query()
            ->where(function ($query) use ($product) {
                $query->whereHas('rules', function ($subQuery) use ($product) {
                    $subQuery->where('itemable_type', Product::class)
                        ->where('itemable_id', $product->id);
                })->orWhereHas('effects', function ($subQuery) use ($product) {
                    $subQuery->where('itemable_type', Product::class)
                        ->where('itemable_id', $product->id);
                });
            })
            ->with(['rules.itemable', 'effects.itemable'])
            ->get();

        $translations = config('log-translations.Product');

        // Formatear el historial para el frontend
        $formattedActivities = $product->activities->map(function ($activity) use ($translations) {
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

        return Inertia::render('Product/Show', [
            'product' => $product,
            'promotions' => $promotions,
            'activities' => $formattedActivities,
        ]);
    }

    public function destroy(Product $product)
    {
        // Opcional: Autorización
        // $this->authorize('delete', $product);

        $product->delete();

        return redirect()->route('products.index')->with('success', 'Producto eliminado con éxito.');
    }

    /**
     * Elimina múltiples productos de la base de datos.
     */
    public function batchDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:products,id',
        ]);

        // Opcional: Autorización para asegurar que todos los IDs pertenecen al usuario
        // $products = Product::whereIn('id', $validated['ids'])->get();
        // $this->authorize('delete-multiple', $products);

        Product::whereIn('id', $validated['ids'])->delete();

        return redirect()->route('products.index')->with('success', 'Productos seleccionados eliminados con éxito.');
    }
}
