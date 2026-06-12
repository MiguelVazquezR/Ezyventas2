<?php

namespace App\Http\Controllers;

use App\Actions\Product\CreateProduct;
use App\Actions\Product\UpdateProduct;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Provider;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.access', only: ['index']),
            new Middleware('can:products.create', only: ['create', 'store']),
            new Middleware('can:products.see_details', only: ['show']),
            new Middleware('can:products.edit', only: ['edit', 'update', 'updatePriceFromPOS']),
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

        $productLimitReached = $subscription->hasReachedProductLimit();

        $query = Product::query()
            ->with([
                'category',
                'brand',
                'media',
                'branches',
                'productAttributes.branches',
                'components.componentable' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([
                        ProductAttribute::class => ['product']
                    ]);
                }
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
            $query->join('branch_product', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_product.product_id')
                    ->where('branch_product.branch_id', '=', $branchId);
            })
                ->orderBy('branch_product.' . $sortField, $sortOrder)
                ->select('products.*');
        } else {
            $query->orderBy('products.' . $sortField, $sortOrder);
            $query->select('products.*');
        }

        $products = $query->paginate($request->input('rows', 20))->withQueryString();

        // REFACTOR: Uso del modelo para inyectar stocks locales a los productos dinámicamente
        $products->getCollection()->transform(function ($product) use ($branchId) {
            return $product->loadStockForBranch($branchId);
        });

        $availableTemplates = $user->branch->printTemplates()
            ->where('type', TemplateType::LABEL)
            ->whereIn('context_type', [TemplateContextType::PRODUCT, TemplateContextType::GENERAL])
            ->get();

        $stockByCategory = Category::query()
            ->where('type', 'product')
            ->where('subscription_id', $subscription->id)
            ->select('categories.*')
            ->selectRaw('(
                SELECT COALESCE(SUM(bp.current_stock), 0)
                FROM products p
                JOIN branch_product bp ON bp.product_id = p.id
                WHERE p.category_id = categories.id
                AND bp.branch_id = ?
            ) as simple_stock', [$branchId])
            ->selectRaw('(
                SELECT COALESCE(SUM(bpa.current_stock), 0)
                FROM products p
                JOIN product_attributes pa ON pa.product_id = p.id
                JOIN branch_product_attribute bpa ON bpa.product_attribute_id = pa.id
                WHERE p.category_id = categories.id
                AND bpa.branch_id = ?
            ) as variant_stock', [$branchId])
            ->get()
            ->map(function ($cat) {
                $cat->products_sum_current_stock = (float)$cat->simple_stock + (float)$cat->variant_stock;
                return $cat;
            })
            ->filter(fn($category) => $category->products_sum_current_stock > 0)
            ->sortByDesc('products_sum_current_stock')
            ->values();

        $isOwner = !$user->roles()->exists();
        $userBankAccounts = $isOwner ? $user->branch->bankAccounts()->get() : $user->bankAccounts()->get();

        return Inertia::render('Product/Index', [
            'products' => $products,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'productLimit' => (int) $limitProducts,
            'productUsage' => (int) $productsCount,
            'productLimitReached' => $productLimitReached,
            'availableTemplates' => $availableTemplates,
            'stockByCategory' => $stockByCategory,
            'userBankAccounts' => $userBankAccounts,
        ]);
    }

    /**
     * Extrae la carga de catálogos comunes para Create y Edit.
     */
    private function getFormCatalogs(int $subscriptionId): array
    {
        return [
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'product')->get(),
            'brands' => Brand::where('subscription_id', $subscriptionId)->get(),
            'providers' => Provider::where('subscription_id', $subscriptionId)->get(),
            'attributeDefinitions' => AttributeDefinition::with('options')->where('subscription_id', $subscriptionId)->get(),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
        ];
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        return Inertia::render('Product/Create', array_merge(
            $this->getFormCatalogs($subscription->id),
            [
                'current_branch_id' => $user->branch_id,
                'productLimitReached' => $subscription->hasReachedProductLimit(),
            ]
        ));
    }

    public function store(StoreProductRequest $request, CreateProduct $createProduct)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $compositeItems = $validated['composite_items'] ?? [];

        $newItemsCount = 1 + (($validated['product_type'] === 'variant' && !empty($validated['variants_matrix'])) ? count($validated['variants_matrix']) : 0);

        if ($subscription->hasReachedProductLimit($newItemsCount)) {
            return redirect()->back()->with('error', 'Excedes tu límite de productos. Mejora tu suscripción.');
        }

        $productData = collect($validated)->except([
            'composite_items', 'general_images', 'variant_images', 'branch_ids'
        ])->toArray();

        $files = [
            'general_images' => $request->file('general_images'),
            'variant_images' => $request->file('variant_images')
        ];

        $createProduct->execute(
            $productData,
            $compositeItems,
            $request->input('branch_ids', [$user->branch_id]),
            $user,
            $files
        );

        return redirect()->route('products.index')->with('success', 'Producto creado con éxito.');
    }

    public function show(Request $request, Product $product, ActivityLogService $activityLogService): Response
    {
        $user = Auth::user();

        $product->load([
            'category', 'brand', 'provider', 'media', 'branches',
            'components.componentable' => fn ($m) => $m->morphWith([ProductAttribute::class => ['product']]),
            'productAttributes.branches' => fn ($q) => $q->where('branches.id', $user->branch_id)
        ]);

        $product->loadStockForBranch($user->branch_id);

        $promotions = $product->promotions->load(['rules.itemable', 'effects.itemable']);
        $formattedActivities = $activityLogService->getFormattedActivities($product, $request, 'Product');

        $formattedLayaways = $product->transactionItems()->whereHas('transaction', function ($q) {
            $q->whereIn('status', [\App\Enums\TransactionStatus::ON_LAYAWAY, \App\Enums\TransactionStatus::TO_DELIVER]);
        })->get()->map(function ($item) {
            return [
                'id' => $item->transaction->id,
                'transaction_id' => $item->transaction->id, 
                'transaction' => $item->transaction->id,    
                'folio' => $item->transaction->folio,
                'status' => $item->transaction->status instanceof \App\Enums\TransactionStatus ? $item->transaction->status->value : $item->transaction->status,
                'customer_name' => $item->transaction->customer->name ?? 'Público en general',
                'customer_id' => $item->transaction->customer_id,
                'quantity' => $item->quantity,
                'description' => $item->description,
                'date' => $item->transaction->created_at->toDateTimeString(),
                'layaway_expiration_date' => $item->transaction->layaway_expiration_date,
            ];
        });

        $availableTemplates = $user->branch->printTemplates()
            ->where('type', \App\Enums\TemplateType::LABEL)
            ->whereIn('context_type', [\App\Enums\TemplateContextType::PRODUCT, \App\Enums\TemplateContextType::GENERAL])
            ->get();

        $isOwner = !$user->roles()->exists();
        $userBankAccounts = $isOwner ? $user->branch->bankAccounts()->get() : $user->bankAccounts()->get();

        return Inertia::render('Product/Show', [
            'product' => $product,
            'promotions' => $promotions,
            'activities' => $formattedActivities,
            'availableTemplates' => $availableTemplates,
            'activeLayaways' => $formattedLayaways,
            'userBankAccounts' => $userBankAccounts, 
        ]);
    }

    public function edit(Product $product): Response
    {
        $user = Auth::user();

        $product->load([
            'media', 'branches:id', 'productAttributes.branches',
            'components.componentable' => fn ($m) => $m->morphWith([ProductAttribute::class => ['product']])
        ]);

        $product->loadStockForBranch($user->branch_id);
        $product->composite_items = $product->formatted_components;

        return Inertia::render('Product/Edit', array_merge(
            ['product' => $product],
            $this->getFormCatalogs($user->branch->subscription_id)
        ));
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $updateProduct)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $compositeItems = $validated['composite_items'] ?? [];

        if ($validated['product_type'] === 'variant' && !empty($validated['variants_matrix'])) {
            $newVariantsCount = collect($validated['variants_matrix'])->filter(fn($v) => empty($v['id']))->count();
            
            if ($newVariantsCount > 0 && $subscription->hasReachedProductLimit($newVariantsCount)) {
                return redirect()->back()->with('error', 'No puedes agregar estas variantes porque excedes el límite de productos de tu plan.');
            }
        }

        $productData = collect($validated)->except([
            'composite_items', 'deleted_media_ids', 'general_images', 'variant_images', 'branch_ids'
        ])->toArray();

        $files = [
            'general_images' => $request->file('general_images'),
            'variant_images' => $request->file('variant_images')
        ];

        $updateProduct->execute(
            $product,
            $productData,
            $compositeItems,
            $request->input('branch_ids', [$user->branch_id]),
            $user,
            $files,
            $validated['deleted_media_ids'] ?? []
        );

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

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.type' => 'required|in:product,variant',
            'items.*.id' => 'required|integer',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.sku' => 'nullable|string|max:100',
            'items.*.selling_price' => 'nullable|numeric|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.min_stock' => 'nullable|numeric|min:0',
            'items.*.max_stock' => 'nullable|numeric|min:0',
            'items.*.show_in_pos' => 'nullable|boolean',
            'items.*.show_online' => 'nullable|boolean',
            'items.*.is_featured' => 'nullable|boolean',
        ]);

        $branchId = Auth::user()->branch_id;

        DB::transaction(function () use ($validated, $branchId) {
            foreach ($validated['items'] as $item) {
                if ($item['type'] === 'product') {
                    $product = Product::find($item['id']);
                    if ($product) {
                        $product->update([
                            'name' => $item['name'] ?? $product->name,
                            'sku' => $item['sku'] ?? $product->sku,
                            'selling_price' => $item['selling_price'] ?? $product->selling_price,
                            'cost_price' => $item['cost_price'] ?? $product->cost_price,
                            'show_in_pos' => $item['show_in_pos'] ?? $product->show_in_pos,
                            'show_online' => $item['show_online'] ?? $product->show_online,
                            'is_featured' => $item['is_featured'] ?? $product->is_featured,
                        ]);

                        if (array_key_exists('min_stock', $item) || array_key_exists('max_stock', $item)) {
                            $product->branches()->syncWithoutDetaching([
                                $branchId => [
                                    'min_stock' => $item['min_stock'] ?? null,
                                    'max_stock' => $item['max_stock'] ?? null,
                                ]
                            ]);
                        }
                    }
                }
            }

            foreach ($validated['items'] as $item) {
                if ($item['type'] === 'variant') {
                    $variant = ProductAttribute::with('product')->find($item['id']);
                    
                    if ($variant) {
                        if (array_key_exists('selling_price', $item)) {
                            $variant->updatePriceFromTotal($item['selling_price']);
                        }

                        $variant->update(['sku_suffix' => $item['sku'] ?? $variant->sku_suffix]);
                        
                        if (array_key_exists('min_stock', $item) || array_key_exists('max_stock', $item)) {
                            $variant->branches()->syncWithoutDetaching([
                                $branchId => [
                                    'min_stock' => $item['min_stock'] ?? null,
                                    'max_stock' => $item['max_stock'] ?? null,
                                ]
                            ]);
                        }
                    }
                }
            }
        });

        return redirect()->back()->with('success', 'Productos actualizados masivamente con éxito.');
    }

    public function updatePriceFromPOS(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_attribute_id' => 'nullable|exists:product_attributes,id',
            'new_price' => 'required|numeric|min:0'
        ]);

        if ($request->product_attribute_id) {
            $variant = ProductAttribute::with('product')->findOrFail($request->product_attribute_id);
            $variant->updatePriceFromTotal($request->new_price);
        } else {
            $product = Product::findOrFail($request->product_id);
            $product->update(['selling_price' => $request->new_price]);
        }

        return response()->json(['success' => true]);
    }

    public function toggleOnline(Product $product): RedirectResponse
    {
        $product->update(['show_online' => !$product->show_online]);

        return back()->with('success', $product->show_online
            ? 'El producto ahora es visible en tu tienda en línea.'
            : 'El producto ahora está oculto de tu tienda en línea.');
    }

    public function toggleFeatured(Product $product): RedirectResponse
    {
        $product->update(['is_featured' => !$product->is_featured]);

        return back()->with('success', $product->is_featured
            ? 'El producto ahora es destacado en tu tienda en línea.'
            : 'El producto ya no es destacado.');
    }

    public function togglePos(Product $product): RedirectResponse
    {
        $product->update(['show_in_pos' => !$product->show_in_pos]);

        return back()->with('success', $product->show_in_pos
            ? 'El producto ahora es visible en el punto de venta.'
            : 'El producto ahora está oculto del punto de venta.');
    }
}