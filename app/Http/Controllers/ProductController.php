<?php

namespace App\Http\Controllers;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Promotion;
use App\Models\Provider;
use App\Models\TransactionItem;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductController extends Controller implements HasMiddleware
{
    use OptimizeMediaLocal;

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

    private function getProductLimitData()
    {
        $subscription = Auth::user()->branch->subscription;
        $currentVersion = $subscription->versions()->latest('start_date')->first();
        $limit = -1;
        if ($currentVersion) {
            $limitItem = $currentVersion->items()->where('item_key', 'limit_products')->first();
            if ($limitItem) {
                $limit = $limitItem->quantity;
            }
        }
        // Count products specifically for the current subscription
        $usage = Product::whereHas('branch.subscription', function ($q) use ($subscription) {
            $q->where('id', $subscription->id);
        })->count();
        return ['limit' => $limit, 'usage' => $usage];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $query = Product::query()->where('branch_id', $user->branch_id)->with('media');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sku', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $products = $query->paginate($request->input('rows', 20))->withQueryString();

        $availableTemplates = $user->branch->printTemplates()
            ->where('type', TemplateType::LABEL)
            ->whereIn('context_type', [TemplateContextType::PRODUCT, TemplateContextType::GENERAL])
            ->get();

        $limitData = $this->getProductLimitData();

        $stockByCategory = Category::query()
            ->where('type', 'product')
            ->where('subscription_id', $user->branch->subscription_id)
            ->withSum(['products' => function ($query) use ($user) {
                $query->where('branch_id', $user->branch_id);
            }], 'current_stock')
            ->get()
            ->filter(fn($category) => $category->products_sum_current_stock > 0)
            ->sortBy('name')
            ->values();

        return Inertia::render('Product/Index', [
            'products' => $products,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'productLimit' => $limitData['limit'],
            'productUsage' => $limitData['usage'],
            'availableTemplates' => $availableTemplates,
            'stockByCategory' => $stockByCategory,
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $limitData = $this->getProductLimitData();

        $globalBrands = Brand::whereNull('subscription_id')
            ->whereHas('businessTypes', function ($query) use ($user) {
                if ($user->branch && $user->branch->subscription) {
                    $query->where('business_type_id', $user->branch->subscription->business_type_id);
                }
            })->get(['id', 'name']);

        $subscriberBrands = Brand::where('subscription_id', $subscriptionId)->get(['id', 'name']);

        $formattedBrands = [
            ['label' => 'Mis Marcas', 'items' => $subscriberBrands],
            ['label' => 'Marcas del Catálogo', 'items' => $globalBrands],
        ];
        $categories = Category::where(['subscription_id' => $subscriptionId, 'type' => 'product'])->get(['id', 'name']);
        $providers = Provider::where('subscription_id', $subscriptionId)->get(['id', 'name']);
        $attributeDefinitions = AttributeDefinition::with('options')->where('subscription_id', $subscriptionId)->get();

        return Inertia::render('Product/Create', [
            'categories' => $categories,
            'brands' => $formattedBrands,
            'providers' => $providers,
            'attributeDefinitions' => $attributeDefinitions,
            'productLimit' => $limitData['limit'],
            'productUsage' => $limitData['usage'],
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        $limitData = $this->getProductLimitData();
        if ($limitData['limit'] !== -1 && $limitData['usage'] >= $limitData['limit']) {
            throw ValidationException::withMessages([
                'limit' => 'Has alcanzado el límite de productos de tu plan.'
            ]);
        }

        DB::transaction(function () use ($request) {
            $user = Auth::user();
            $branch = $user->branch;
            $validatedData = $request->validated();
            $baseSlug = Str::slug($validatedData['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Product::where('branch_id', $branch->id)->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validatedData['slug'] = $slug;

            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                $validatedData['current_stock'] = collect($variantsMatrix)->where('selected', true)->sum('current_stock');
            }
            $productData = collect($validatedData)->except(['general_images', 'variant_images', 'variants_matrix'])->all();
            $product = Product::create(array_merge($productData, ['branch_id' => $branch->id]));

            if ($request->hasFile('general_images')) {
                foreach (array_keys($request->file('general_images')) as $key) {
                    $mediaItem = $product->addMediaFromRequest("general_images.{$key}")
                        ->toMediaCollection('product-general-images');
                    // <-- 3. Usar método del Trait -->
                    $this->optimizeMediaLocal($mediaItem);
                }
            }
            if ($request->hasFile('variant_images')) {
                foreach (array_keys($request->file('variant_images')) as $optionValue) {
                    $mediaItem = $product->addMediaFromRequest("variant_images.{$optionValue}")
                        ->withCustomProperties(['variant_option' => $optionValue])
                        ->toMediaCollection('product-variant-images');
                    // <-- 3. Usar método del Trait -->
                    $this->optimizeMediaLocal($mediaItem);
                }
            }

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

        $product->load('productAttributes', 'media');

        $formattedAttributes = $product->productAttributes->mapWithKeys(function ($attr) use ($product) {
            $key = collect($attr->attributes)->sortKeys()->implode('-');
            return [$key => [
                'attributes' => $attr->attributes,
                'sku_suffix' => $attr->sku_suffix,
                'current_stock' => $attr->current_stock,
                'min_stock' => $attr->min_stock,
                'max_stock' => $attr->max_stock,
                'selling_price' => $product->selling_price + $attr->selling_price_modifier,
            ]];
        });

        $globalBrands = Brand::whereNull('subscription_id')
            ->whereHas('businessTypes', function ($query) use ($user) {
                if ($user->branch && $user->branch->subscription) {
                    $query->where('business_type_id', $user->branch->subscription->business_type_id);
                }
            })->get(['id', 'name']);

        $subscriberBrands = Brand::where('subscription_id', $subscriptionId)->get(['id', 'name']);

        $formattedBrands = [
            ['label' => 'Mis Marcas', 'items' => $subscriberBrands],
            ['label' => 'Marcas del Catálogo', 'items' => $globalBrands],
        ];

        return Inertia::render('Product/Edit', [
            'product' => $product->toArray() + ['formatted_attributes' => $formattedAttributes],
            'categories' => Category::where(['subscription_id' => $subscriptionId, 'type' => 'product'])->get(['id', 'name']),
            'brands' => $formattedBrands,
            'providers' => Provider::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'attributeDefinitions' => AttributeDefinition::with('options')->where('subscription_id', $subscriptionId)->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        DB::transaction(function () use ($request, $product) {
            $validatedData = $request->validated();

            if ($product->name !== $validatedData['name']) {
                $baseSlug = Str::slug($validatedData['name']);
                $slug = $baseSlug;
                $counter = 1;
                while (Product::where('branch_id', $product->branch_id)->where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $validatedData['slug'] = $slug;
            } else {
                unset($validatedData['slug']);
            }


            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                $validatedData['current_stock'] = collect($variantsMatrix)->where('selected', true)->sum('current_stock');
            }

            $productData = collect($validatedData)->except(['general_images', 'variant_images', 'variants_matrix', 'deleted_media_ids'])->all();
            $product->update($productData);

            if (!empty($validatedData['deleted_media_ids'])) {
                // Use Spatie's method to delete media by ID
                $product->media()->whereIn('id', $validatedData['deleted_media_ids'])->each(function (Media $media) {
                    $media->delete();
                });
            }

            if ($request->hasFile('general_images')) {
                foreach (array_keys($request->file('general_images')) as $key) {
                    $mediaItem = $product->addMediaFromRequest("general_images.{$key}")->toMediaCollection('product-general-images');
                    // <-- 3. Usar método del Trait -->
                    $this->optimizeMediaLocal($mediaItem);
                }
            }
            if ($request->hasFile('variant_images')) {
                foreach (array_keys($request->file('variant_images')) as $optionValue) {
                    $mediaItem = $product->addMediaFromRequest("variant_images.{$optionValue}")->withCustomProperties(['variant_option' => $optionValue])->toMediaCollection('product-variant-images');
                    // <-- 3. Usar método del Trait -->
                    $this->optimizeMediaLocal($mediaItem);
                }
            }

            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = $validatedData['variants_matrix'];
                $product->productAttributes()->delete();
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
            } elseif ($product->product_type === 'simple') {
                $product->productAttributes()->delete();
            }
        });

        return redirect()->route('products.index')->with('success', 'Producto actualizado con éxito.');
    }

   public function show(Product $product): Response
    {
        $product->load([
            'category',
            'brand',
            'provider',
            'productAttributes',
            'media',
            'activities.causer'
        ]);

        $promotions = Promotion::query()
            // Check if the product is directly involved in rules or effects
            ->where(function ($query) use ($product) {
                $query->whereHas('rules', fn($subQuery) => $subQuery->whereMorphedTo('itemable', $product))
                    ->orWhereHas('effects', fn($subQuery) => $subQuery->whereMorphedTo('itemable', $product));
            })
            // Also include promotions that apply globally or by category/brand if needed
            // ->orWhere(function ($query) use ($product) { ... add logic for broader promotion applicability ... })
            ->with(['rules.itemable', 'effects.itemable'])
            ->get();


        $translations = config('log_translations.Product', []);

        $formattedActivities = $product->activities->map(function ($activity) use ($translations) {
            $changes = ['before' => [], 'after' => []];
            $oldProps = $activity->properties->get('old', []);
            $newProps = $activity->properties->get('attributes', []);

            if (is_array($oldProps)) {
                foreach ($oldProps as $key => $value) {
                    $changes['before'][($translations[$key] ?? $key)] = $value;
                }
            }
            if (is_array($newProps)) {
                foreach ($newProps as $key => $value) {
                    if (!isset($oldProps[$key]) || $oldProps[$key] !== $value) {
                        $changes['after'][($translations[$key] ?? $key)] = $value;
                    }
                }
            }
            // Ensure 'before' only contains keys that actually changed or were removed
            $changes['before'] = array_intersect_key($changes['before'], $changes['after']);


            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                // Only include changes if there are actual differences
                'changes' => (object)(!empty($changes['before']) || !empty($changes['after']) ? $changes : []),
            ];
        })
        ->filter(fn($activity) => $activity['event'] !== 'updated' || !empty((array)$activity['changes'])) // Filter out 'updated' events with no changes shown
        ->values(); // <--- CORRECCIÓN AQUI: Reindexar para enviar un Array JSON y no un Objeto JSON

         // --- INICIO DE MODIFICACIÓN: Obtener Apartados Activos ---
        $productAndVariantIds = $product->productAttributes->pluck('id')->push($product->id);
        
        // Obtenemos los IDs de los itemables (Product y ProductAttribute)
        $itemableIds = [
            Product::class => $product->id,
            ProductAttribute::class => $product->productAttributes->pluck('id')->all(),
        ];

        $layawayItems = TransactionItem::query()
            ->where(function ($query) use ($itemableIds) {
                // Busca items que sean el producto principal
                $query->where('itemable_type', Product::class)
                      ->where('itemable_id', $itemableIds[Product::class]);
                
                // Busca items que sean variantes de este producto
                if (!empty($itemableIds[ProductAttribute::class])) {
                    $query->orWhere(function($q) use ($itemableIds) {
                        $q->where('itemable_type', ProductAttribute::class)
                          ->whereIn('itemable_id', $itemableIds[ProductAttribute::class]);
                    });
                }
            })
            ->whereHas('transaction', function ($q) {
                // Filtra solo por transacciones 'en_apartado'
                $q->where('status', TransactionStatus::ON_LAYAWAY);
            })
            ->with([
                // Cargamos la información necesaria
                'transaction:id,folio,customer_id,created_at,layaway_expiration_date', // <--- AGREGADO: layaway_expiration_date
                'transaction.customer:id,name'
            ])
            ->get();
        
        $formattedLayaways = $layawayItems->map(function($item) {
            return [
                'transaction_id' => $item->transaction->id,
                'folio' => $item->transaction->folio,
                'customer_name' => $item->transaction->customer?->name ?? 'Cliente Eliminado',
                'customer_id' => $item->transaction->customer_id,
                'quantity' => $item->quantity,
                'description' => $item->description, // Descripción del item (ej. "Playera (Roja, M)")
                'date' => $item->transaction->created_at->toDateTimeString(),
                'layaway_expiration_date' => $item->transaction->layaway_expiration_date, // <--- AGREGADO
            ];
        });
        // --- FIN DE MODIFICACIÓN ---

        $availableTemplates = Auth::user()->branch->printTemplates()
            ->where('type', TemplateType::LABEL)
            ->whereIn('context_type', [TemplateContextType::PRODUCT, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('Product/Show', [
            'product' => $product,
            'promotions' => $promotions,
            'activities' => $formattedActivities,
            'availableTemplates' => $availableTemplates,
            'activeLayaways' => $formattedLayaways,
        ]);
    }

    public function destroy(Product $product)
    {
        // Add authorization check if needed: $this->authorize('delete', $product);
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Producto eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:products,id',
        ]);
        // Add authorization check if needed: $this->authorize('delete-multiple', Product::class);
        Product::whereIn('id', $validated['ids'])->delete();
        return redirect()->route('products.index')->with('success', 'Productos seleccionados eliminados con éxito.');
    }
}