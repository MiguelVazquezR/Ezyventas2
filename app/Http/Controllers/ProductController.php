<?php

namespace App\Http\Controllers;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Branch;
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
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

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

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $subscription = $user->branch->subscription;

        $productsCount = $subscription->products_count;
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_products')->first() : null;
        $limitProducts = $limitItem ? $limitItem->quantity : -1; 
        
        $productLimitReached = $limitProducts !== -1 && $productsCount >= $limitProducts;

        // Consultamos los productos vinculados a esta sucursal mediante la tabla pivot
        $query = Product::query()
            ->with([
                'category', 
                'brand', 
                'media', 
                'branches',
                'productAttributes.branches'
            ])
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            });

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('sku', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        
        if ($sortField === 'category.name') {
            $query->join('categories', 'products.category_id', '=', 'categories.id')
                  ->orderBy('categories.name', $sortOrder)
                  ->select('products.*');
        } elseif (in_array($sortField, ['current_stock', 'min_stock', 'max_stock', 'location'])) {
            // Unimos la tabla pivot explícitamente para permitir ordenamiento de inventario
            $query->join('branch_product', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_product.product_id')
                     ->where('branch_product.branch_id', '=', $branchId);
            })
            ->orderBy('branch_product.' . $sortField, $sortOrder)
            ->select('products.*');
        } else {
            // Prefijamos con 'products.' para evitar ambigüedades en la consulta
            $query->orderBy('products.' . $sortField, $sortOrder);
            $query->select('products.*');
        }

        $products = $query->paginate($request->input('rows', 20))->withQueryString();

        // Mapeo inteligente
        $products->getCollection()->transform(function ($product) use ($branchId) {
            $branchPivot = $product->branches->where('id', $branchId)->first()?->pivot;
            
            $product->current_stock = $branchPivot ? $branchPivot->current_stock : 0;
            $product->reserved_stock = $branchPivot ? $branchPivot->reserved_stock : 0;
            $product->min_stock = $branchPivot ? $branchPivot->min_stock : null;
            $product->max_stock = $branchPivot ? $branchPivot->max_stock : null;
            $product->location = $branchPivot ? $branchPivot->location : null;
            $product->price_modifier = $branchPivot ? $branchPivot->price_modifier : 0;

            if ($product->productAttributes) {
                $product->productAttributes->transform(function ($variant) use ($branchId) {
                    $vPivot = $variant->branches->where('id', $branchId)->first()?->pivot;
                    
                    $variant->current_stock = $vPivot ? $vPivot->current_stock : 0;
                    $variant->reserved_stock = $vPivot ? $vPivot->reserved_stock : 0;
                    $variant->price_modifier = $vPivot ? $vPivot->price_modifier : 0;
                    
                    // Asignamos el location y remapeamos el SKU
                    $variant->location = $vPivot ? $vPivot->location : null;
                    $variant->sku = $variant->sku_suffix;
                    return $variant;
                });
            }
            return $product;
        });

        // --- Recolección de propiedades adicionales necesarias para Vue ---
        $availableTemplates = $user->branch->printTemplates()
            ->where('type', TemplateType::LABEL)
            ->whereIn('context_type', [TemplateContextType::PRODUCT, TemplateContextType::GENERAL])
            ->get();

        $stockByCategory = Category::query()
            ->where('type', 'product')
            ->where('subscription_id', $subscription->id)
            ->select('categories.*')
            // Subconsulta 1: Stock de productos simples
            ->selectRaw('(
                SELECT COALESCE(SUM(bp.current_stock), 0)
                FROM products p
                JOIN branch_product bp ON bp.product_id = p.id
                WHERE p.category_id = categories.id
                AND bp.branch_id = ?
            ) as simple_stock', [$branchId])
            // Subconsulta 2: Stock de variantes de productos
            ->selectRaw('(
                SELECT COALESCE(SUM(bpa.current_stock), 0)
                FROM products p
                JOIN product_attributes pa ON pa.product_id = p.id
                JOIN branch_product_attribute bpa ON bpa.product_attribute_id = pa.id
                WHERE p.category_id = categories.id
                AND bpa.branch_id = ?
            ) as variant_stock', [$branchId, $branchId])
            ->get()
            ->map(function($cat) {
                // Sumamos ambos para el total de la categoría
                $cat->products_sum_current_stock = (float)$cat->simple_stock + (float)$cat->variant_stock;
                return $cat;
            })
            ->filter(fn($category) => $category->products_sum_current_stock > 0)
            ->sortByDesc('products_sum_current_stock')
            ->values();

        return Inertia::render('Product/Index', [
            'products' => $products,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'productLimit' => (int) $limitProducts,
            'productUsage' => (int) $productsCount,
            'productLimitReached' => $productLimitReached,
            'availableTemplates' => $availableTemplates,
            'stockByCategory' => $stockByCategory,
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $subscription = $user->branch->subscription;
        
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_products')->first() : null;
        $limitProducts = $limitItem ? $limitItem->quantity : 50;
        
        $productLimitReached = $limitProducts !== -1 && $subscription->products_count >= $limitProducts;

        return Inertia::render('Product/Create', [
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'product')->get(),
            'brands' => Brand::where('subscription_id', $subscriptionId)->get(),
            'providers' => Provider::where('subscription_id', $subscriptionId)->get(),
            'attributeDefinitions' => AttributeDefinition::with('options')->where('subscription_id', $subscriptionId)->get(),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'current_branch_id' => $user->branch_id,
            'productLimitReached' => $productLimitReached,
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_products')->first() : null;
        $limitProducts = $limitItem ? $limitItem->quantity : 50;

        $newItemsCount = 1 + (($validated['product_type'] === 'variant' && !empty($validated['variants_matrix'])) ? count($validated['variants_matrix']) : 0);

        if ($limitProducts !== -1 && ($subscription->products_count + $newItemsCount) > $limitProducts) {
            return redirect()->back()->with('error', 'Excedes tu límite de productos. Mejora tu suscripción.');
        }

        DB::transaction(function () use ($validated, $user, $request) {
            $productData = collect($validated)->except([
                'product_type',
                'variants_matrix',
                'general_images',
                'variant_images',
                'branch_ids',
                'current_stock',
                'min_stock',
                'max_stock',
                'location'
            ])->toArray();

            $productData['branch_id'] = $user->branch_id;
            $productData['slug'] = Str::slug($validated['name'] . '-' . uniqid());

            $product = Product::create($productData);

            $branchesToSync = $request->input('branch_ids', [$user->branch_id]);
            $syncData = [];
            foreach ($branchesToSync as $bId) {
                $syncData[$bId] = [
                    'current_stock' => ($bId == $user->branch_id && $validated['product_type'] === 'simple') ? ($validated['current_stock'] ?? 0) : 0,
                    'reserved_stock' => 0,
                    'min_stock' => $validated['min_stock'] ?? null,
                    'max_stock' => $validated['max_stock'] ?? null,
                    'location' => ($bId == $user->branch_id) ? ($validated['location'] ?? null) : null,
                    'price_modifier' => 0,
                ];
            }
            $product->branches()->sync($syncData);

            if ($validated['product_type'] === 'variant' && !empty($validated['variants_matrix'])) {
                foreach ($validated['variants_matrix'] as $variantData) {
                    $variant = $product->productAttributes()->create([
                        'attributes' => $variantData['attributes'],
                        'sku_suffix' => $variantData['sku'] ?? null, // CORREGIDO: usar sku_suffix
                        'selling_price_modifier' => $variantData['selling_price_modifier'] ?? 0,
                    ]);

                    $variantSyncData = [];
                    foreach ($branchesToSync as $bId) {
                        $variantSyncData[$bId] = [
                            'current_stock' => ($bId == $user->branch_id) ? ($variantData['current_stock'] ?? 0) : 0,
                            'reserved_stock' => 0,
                            'price_modifier' => 0,
                            'location' => ($bId == $user->branch_id) ? ($variantData['location'] ?? null) : null, // NUEVO: Location por variante
                        ];
                    }
                    $variant->branches()->sync($variantSyncData);
                }
            }

            if ($request->hasFile('general_images')) {
                foreach ($request->file('general_images') as $file) {
                    $mediaItem = $product->addMedia($file)->toMediaCollection('product-general-images');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }

            if ($request->hasFile('variant_images')) {
                foreach ($request->file('variant_images') as $key => $file) {
                    $mediaItem = $product->addMedia($file)
                        ->withCustomProperties(['variant_key' => $key])
                        ->toMediaCollection('product-variant-images');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Producto creado con éxito.');
    }

    public function show(Product $product): Response
    {
        $user = Auth::user();

        $product->load([
            'category',
            'brand',
            'provider',
            'media',
            'activities.causer',
            'branches',
            'productAttributes.branches' => function ($q) use ($user) {
                $q->where('branches.id', $user->branch_id);
            }
        ]);

        $branchPivot = $product->branches->where('id', $user->branch_id)->first()?->pivot;

        $product->current_stock = $branchPivot ? $branchPivot->current_stock : 0;
        $product->reserved_stock = $branchPivot ? $branchPivot->reserved_stock : 0;
        $product->available_stock = max(0, $product->current_stock - $product->reserved_stock);
        $product->min_stock = $branchPivot ? $branchPivot->min_stock : null;
        $product->max_stock = $branchPivot ? $branchPivot->max_stock : null;
        $product->location = $branchPivot ? $branchPivot->location : null;

        if ($product->productAttributes) {
            $product->productAttributes->transform(function ($variant) {
                $vPivot = $variant->branches->first()?->pivot;
                $variant->current_stock = $vPivot ? $vPivot->current_stock : 0;
                $variant->reserved_stock = $vPivot ? $vPivot->reserved_stock : 0;
                $variant->available_stock = max(0, $variant->current_stock - $variant->reserved_stock);
                $variant->location = $vPivot ? $vPivot->location : null;
                $variant->sku = $variant->sku_suffix;
                return $variant;
            });
        }

        $promotions = $product->promotions;
        $translations = config('log_translations.Product', []);

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

        $formattedLayaways = $product->transactionItems()->whereHas('transaction', function ($q) {
            $q->where('status', TransactionStatus::PENDING);
        })->get()->map(function ($item) {
            return [
                'id' => $item->transaction->id,
                'folio' => $item->transaction->folio,
                'customer_name' => $item->transaction->customer->name ?? 'Cliente Eliminado',
                'customer_id' => $item->transaction->customer_id,
                'quantity' => $item->quantity,
                'description' => $item->description,
                'date' => $item->transaction->created_at->toDateTimeString(),
                'layaway_expiration_date' => $item->transaction->layaway_expiration_date,
            ];
        });

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

    public function edit(Product $product): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $product->load(['media', 'branches:id', 'productAttributes.branches']);

        $branchPivot = $product->branches->where('id', $user->branch_id)->first()?->pivot;

        $product->current_stock = $branchPivot ? $branchPivot->current_stock : 0;
        $product->min_stock = $branchPivot ? $branchPivot->min_stock : null;
        $product->max_stock = $branchPivot ? $branchPivot->max_stock : null;
        $product->location = $branchPivot ? $branchPivot->location : null;

        if ($product->productAttributes) {
            $product->productAttributes->transform(function ($variant) use ($user) {
                $vPivot = $variant->branches->where('id', $user->branch_id)->first()?->pivot;
                $variant->current_stock = $vPivot ? $vPivot->current_stock : 0;
                $variant->location = $vPivot ? $vPivot->location : null;
                $variant->sku = $variant->sku_suffix;
                return $variant;
            });
        }

        return Inertia::render('Product/Edit', [
            'product' => $product,
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'product')->get(),
            'brands' => Brand::where('subscription_id', $subscriptionId)->get(),
            'providers' => Provider::where('subscription_id', $subscriptionId)->get(),
            'attributeDefinitions' => AttributeDefinition::with('options')->where('subscription_id', $subscriptionId)->get(),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $validated = $request->validated();
        $user = Auth::user();

        $subscription = $user->branch->subscription;
        $currentVersion = $subscription->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_products')->first() : null;
        $limitProducts = $limitItem ? $limitItem->quantity : 50;

        if ($validated['product_type'] === 'variant' && !empty($validated['variants_matrix'])) {
            $newVariantsCount = collect($validated['variants_matrix'])->filter(fn($v) => empty($v['id']))->count();
            if ($newVariantsCount > 0 && $limitProducts !== -1 && ($subscription->products_count + $newVariantsCount) > $limitProducts) {
                return redirect()->back()->with('error', 'No puedes agregar estas variantes porque excedes el límite de productos de tu plan.');
            }
        }

        DB::transaction(function () use ($validated, $user, $product, $request) {
            $productData = collect($validated)->except([
                'product_type',
                'variants_matrix',
                'general_images',
                'variant_images',
                'deleted_media_ids',
                'branch_ids',
                'current_stock',
                'min_stock',
                'max_stock',
                'location'
            ])->toArray();

            if ($product->name !== $validated['name']) {
                $productData['slug'] = Str::slug($validated['name'] . '-' . uniqid());
            }

            $product->update($productData);

            $branchesToSync = $request->input('branch_ids', [$user->branch_id]);
            $existingBranches = $product->branches->keyBy('id');
            $syncData = [];

            foreach ($branchesToSync as $bId) {
                if ($existingBranches->has($bId)) {
                    $syncData[$bId] = [
                        'current_stock' => ($bId == $user->branch_id && $validated['product_type'] === 'simple' && isset($validated['current_stock'])) ? $validated['current_stock'] : $existingBranches[$bId]->pivot->current_stock,
                        'min_stock' => ($bId == $user->branch_id) ? ($validated['min_stock'] ?? null) : $existingBranches[$bId]->pivot->min_stock,
                        'max_stock' => ($bId == $user->branch_id) ? ($validated['max_stock'] ?? null) : $existingBranches[$bId]->pivot->max_stock,
                        'location' => ($bId == $user->branch_id) ? ($validated['location'] ?? null) : $existingBranches[$bId]->pivot->location,
                        'price_modifier' => $existingBranches[$bId]->pivot->price_modifier,
                    ];
                } else {
                    $syncData[$bId] = [
                        'current_stock' => 0,
                        'reserved_stock' => 0,
                        'min_stock' => null,
                        'max_stock' => null,
                        'location' => null,
                        'price_modifier' => 0,
                    ];
                }
            }
            $product->branches()->sync($syncData);

            if ($validated['product_type'] === 'variant' && !empty($validated['variants_matrix'])) {
                $existingVariantIds = [];

                foreach ($validated['variants_matrix'] as $variantData) {
                    if (isset($variantData['id']) && $variantData['id']) {
                        $variant = $product->productAttributes()->find($variantData['id']);
                        if ($variant) {
                            $variant->update([
                                'attributes' => $variantData['attributes'],
                                'sku_suffix' => $variantData['sku'] ?? null,
                                'selling_price_modifier' => $variantData['selling_price_modifier'] ?? 0,
                            ]);
                            $existingVariantIds[] = $variant->id;

                            $vExistingBranches = $variant->branches->keyBy('id');
                            $vSyncData = [];
                            foreach ($branchesToSync as $bId) {
                                if ($vExistingBranches->has($bId)) {
                                    $vSyncData[$bId] = [
                                        'current_stock' => ($bId == $user->branch_id && isset($variantData['current_stock']))
                                            ? $variantData['current_stock']
                                            : $vExistingBranches[$bId]->pivot->current_stock,
                                        'location' => ($bId == $user->branch_id && isset($variantData['location']))
                                            ? $variantData['location']
                                            : $vExistingBranches[$bId]->pivot->location,
                                        'price_modifier' => $vExistingBranches[$bId]->pivot->price_modifier,
                                    ];
                                } else {
                                    $vSyncData[$bId] = [
                                        'current_stock' => 0,
                                        'reserved_stock' => 0,
                                        'location' => null,
                                        'price_modifier' => 0,
                                    ];
                                }
                            }
                            $variant->branches()->sync($vSyncData);
                        }
                    } else {
                        $newVariant = $product->productAttributes()->create([
                            'attributes' => $variantData['attributes'],
                            'sku_suffix' => $variantData['sku'] ?? null,
                            'selling_price_modifier' => $variantData['selling_price_modifier'] ?? 0,
                        ]);
                        $existingVariantIds[] = $newVariant->id;

                        $vSyncData = [];
                        foreach ($branchesToSync as $bId) {
                            $vSyncData[$bId] = [
                                'current_stock' => ($bId == $user->branch_id) ? ($variantData['current_stock'] ?? 0) : 0,
                                'reserved_stock' => 0,
                                'location' => ($bId == $user->branch_id) ? ($variantData['location'] ?? null) : null,
                                'price_modifier' => 0,
                            ];
                        }
                        $newVariant->branches()->sync($vSyncData);
                    }
                }
                $product->productAttributes()->whereNotIn('id', $existingVariantIds)->delete();
            } else {
                $product->productAttributes()->delete();
            }

            if (!empty($validated['deleted_media_ids'])) {
                $product->media()->whereIn('id', $validated['deleted_media_ids'])->delete();
            }

            if ($request->hasFile('general_images')) {
                foreach ($request->file('general_images') as $file) {
                    $mediaItem = $product->addMedia($file)->toMediaCollection('product-general-images');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }

            if ($request->hasFile('variant_images')) {
                foreach ($request->file('variant_images') as $key => $file) {
                    $existingMedia = $product->getMedia('product-variant-images')->filter(function ($media) use ($key) {
                        return $media->getCustomProperty('variant_key') === $key;
                    });
                    foreach ($existingMedia as $media) {
                        $media->delete();
                    }

                    $mediaItem = $product->addMedia($file)
                        ->withCustomProperties(['variant_key' => $key])
                        ->toMediaCollection('product-variant-images');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Producto actualizado con éxito.');
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Producto eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:products,id',
        ]);
        Product::whereIn('id', $validated['ids'])->delete();
        return redirect()->route('products.index')->with('success', 'Productos seleccionados eliminados con éxito.');
    }
}
