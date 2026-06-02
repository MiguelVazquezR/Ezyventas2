<?php

namespace App\Http\Controllers\Store;

use App\Actions\Store\CreateStoreTransactionAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\MercadoPagoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class PublicStoreController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mpService,
    ) {}
    /**
     * Store home page — product catalog.
     */
    public function index(Request $request): Response
    {
        $storeConfig = app('resolvedStore');
        $subscriptionId = $storeConfig->subscription_id;

        $branchIds = \App\Models\Branch::where('subscription_id', $subscriptionId)->pluck('id');

        $query = Product::with('media', 'category')
            ->where('show_online', true)
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->addSelect([
                'current_stock' => \App\Models\BranchProduct::select('current_stock')
                    ->whereColumn('product_id', 'products.id')
                    ->whereIn('branch_id', $branchIds)
                    ->limit(1),
                'reserved_stock' => \App\Models\BranchProduct::select('reserved_stock')
                    ->whereColumn('product_id', 'products.id')
                    ->whereIn('branch_id', $branchIds)
                    ->limit(1),
            ])
            ->orderBy('store_sort_order')
            ->orderBy('name');

        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->where('name', 'LIKE', "%{$searchTerm}%");
        }

        if ($request->filled('category')) {
            $catId = $request->input('category');
            $query->where('category_id', $catId);
        }

        if ($request->filled('min_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('online_price', '>=', (float) $request->input('min_price'))
                  ->orWhere(function ($sq) use ($request) {
                      $sq->whereNull('online_price')
                         ->where('selling_price', '>=', (float) $request->input('min_price'));
                  });
            });
        }

        if ($request->filled('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('online_price', '<=', (float) $request->input('max_price'))
                  ->orWhere(function ($sq) use ($request) {
                      $sq->whereNull('online_price')
                         ->where('selling_price', '<=', (float) $request->input('max_price'));
                  });
            });
        }

        // Sort — reorder completely when sorting by price
        if ($request->filled('sort') && in_array($request->input('sort'), ['price_asc', 'price_desc'])) {
            $direction = $request->input('sort') === 'price_asc' ? 'ASC' : 'DESC';
            $query->reorder()->orderByRaw("COALESCE(online_price, selling_price) {$direction}");
        }

        $products = $query->paginate(24)->withQueryString();

        // Append computed stock to each product
        $products->getCollection()->transform(function ($product) {
            $current = (int) ($product->current_stock ?? 0);
            $reserved = (int) ($product->reserved_stock ?? 0);
            $product->available_stock = max(0, $current - $reserved);
            $product->is_out_of_stock = ($current - $reserved) <= 0;
            return $product;
        });

        // Featured products (is_featured = true, show_online = true)
        $featured = Product::with('media', 'category')
            ->where('show_online', true)
            ->where('is_featured', true)
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->orderBy('store_sort_order')
            ->orderBy('name')
            ->limit(8)
            ->get();

        // Get distinct categories from products in this subscription
        $categories = \App\Models\Category::whereHas('products', fn($q) => $q
                ->where('show_online', true)
                ->whereHas('branch', fn($sq) => $sq->where('subscription_id', $subscriptionId))
            )
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Store/Index', [
            'products' => $products,
            'featured' => $featured,
            'categories' => $categories,
            'filters' => $request->only(['search', 'category', 'min_price', 'max_price', 'sort']),
        ]);
    }

    /**
     * Product detail page.
     */
    public function show(Request $request, $slug, $product): Response
    {
        $storeConfig = app('resolvedStore');
        $subscriptionId = $storeConfig->subscription_id;

        $productModel = Product::where('id', $product)
            ->where('show_online', true)
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->addSelect([
                'current_stock' => \App\Models\BranchProduct::select('current_stock')
                    ->whereColumn('product_id', 'products.id')
                    ->whereIn('branch_id', $branchIds ?? \App\Models\Branch::where('subscription_id', $subscriptionId)->pluck('id'))
                    ->limit(1),
                'reserved_stock' => \App\Models\BranchProduct::select('reserved_stock')
                    ->whereColumn('product_id', 'products.id')
                    ->whereIn('branch_id', $branchIds ?? \App\Models\Branch::where('subscription_id', $subscriptionId)->pluck('id'))
                    ->limit(1),
            ])
            ->first();
        
        if (!$productModel) {
            abort(404);
        }

        $productModel->load('media');

        // Compute stock status
        $current = (int) ($productModel->current_stock ?? 0);
        $reserved = (int) ($productModel->reserved_stock ?? 0);
        $isOutOfStock = ($current - $reserved) <= 0;

        // Get all product images (up to 5)
        $images = $productModel->getMedia('product-general-images')->map(fn($m) => $m->getFullUrl())->values()->toArray();

        return Inertia::render('Store/Show', [
            'product' => [
                'id' => $productModel->id,
                'name' => $productModel->name,
                'description' => $productModel->description,
                'price' => $productModel->online_price ?? $productModel->selling_price,
                'category' => $productModel->category?->name,
                'image_url' => $images[0] ?? null,
                'images' => $images,
                'is_bulk' => $productModel->is_bulk,
                'measure_unit' => $productModel->measure_unit,
                'is_out_of_stock' => $isOutOfStock,
            ],
            'freeShippingMinimum' => $storeConfig->free_shipping_minimum,
            'allowOutOfStockPurchases' => $storeConfig->allow_out_of_stock_purchases,
            'outOfStockExtraMinutes' => $storeConfig->out_of_stock_extra_minutes,
        ]);
    }

    /**
     * Cart / order form page.
     */
    public function cart(): Response
    {
        return Inertia::render('Store/Cart');
    }

    /**
     * Store policies page.
     */
    public function policies(): Response
    {
        $storeConfig = app('resolvedStore');

        if (!$storeConfig->terms_policy) {
            abort(404);
        }

        return Inertia::render('Store/Policies', [
            'termsPolicy' => $storeConfig->terms_policy,
        ]);
    }

    /**
     * Place a new order.
     */
    public function placeOrder(Request $request): RedirectResponse
    {
        $storeConfig = app('resolvedStore');

        // Rate limiting: 5 orders per minute per IP
        $rateLimitKey = 'store-order:' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return back()->with('error', 'Demasiados intentos. Espera un momento.');
        }
        RateLimiter::hit($rateLimitKey, 60);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'numeric', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99.99'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'delivery_type' => ['required', 'in:pickup,delivery'],
            'delivery_address' => ['required_if:delivery_type,delivery', 'nullable', 'string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', 'in:mercadopago,cash'],
        ]);

        // Validate delivery type is enabled
        if ($validated['delivery_type'] === 'delivery' && !$storeConfig->accepts_delivery) {
            return back()->with('error', 'El envío a domicilio no está disponible en esta tienda.');
        }
        if ($validated['delivery_type'] === 'pickup' && !$storeConfig->accepts_pickup) {
            return back()->with('error', 'Recoger en tienda no está disponible en esta tienda.');
        }

        // Fetch products and validate they belong to this store
        $subscriptionId = $storeConfig->subscription_id;
        $productIds = collect($validated['items'])->pluck('product_id');
        $products = Product::whereIn('id', $productIds)
            ->where('show_online', true)
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            return back()->with('error', 'Algunos productos ya no están disponibles.');
        }

        // Calculate totals
        $subtotal = 0;
        $orderItems = [];

        foreach ($validated['items'] as $item) {
            $product = $products->get($item['product_id']);
            $price = $product->online_price ?? $product->selling_price;
            $lineSubtotal = $price * $item['quantity'];
            $subtotal += $lineSubtotal;

            $orderItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price' => $price,
                'quantity' => $item['quantity'],
                'subtotal' => $lineSubtotal,
            ];
        }

        $deliveryFee = $validated['delivery_type'] === 'delivery' ? ($storeConfig->delivery_fee ?? 0) : 0;
        $total = $subtotal + $deliveryFee;

        $order = DB::transaction(function () use ($storeConfig, $validated, $orderItems, $subtotal, $deliveryFee, $total) {
            $order = Order::create([
                'subscription_id' => $storeConfig->subscription_id,
                'store_config_id' => $storeConfig->id,
                'status'           => OrderStatus::Pending,
                'delivery_type'    => $validated['delivery_type'],
                'customer_name'    => $validated['customer_name'],
                'customer_phone'   => $validated['customer_phone'],
                'customer_email'   => $validated['customer_email'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'customer_notes'   => $validated['customer_notes'] ?? null,
                'subtotal'         => $subtotal,
                'delivery_fee'     => $deliveryFee,
                'total'            => $total,
                'payment_method'   => $validated['payment_method'],
            ]);

            $order->items()->createMany($orderItems);
            $order->logStatusChange(OrderStatus::Pending, OrderStatus::Pending, 'Pedido realizado por el cliente.');

            // Create linked Transaction for sales history & reports
            app(CreateStoreTransactionAction::class)->execute($order);

            return $order;
        });

        // If Mercado Pago, redirect to create the preference
        if ($validated['payment_method'] === 'mercadopago') {
            return redirect()->route('store.order.pay', [
                'slug'  => $storeConfig->slug,
                'order' => $order->id,
            ]);
        }

        // Cash payment — go straight to confirmation
        return redirect()->route('store.order.confirmed', [
            'slug'  => $storeConfig->slug,
            'order' => $order->id,
        ]);
    }

    /**
     * Create Mercado Pago preference and redirect to checkout.
     */
    public function pay($slug, Order $order): RedirectResponse
    {
        $storeConfig = app('resolvedStore');

        if ($order->subscription_id !== $storeConfig->subscription_id) {
            abort(404);
        }

        if ($order->payment_method !== 'mercadopago') {
            return redirect()->route('store.order.confirmed', ['slug' => $slug, 'order' => $order->id]);
        }

        // Test mode — simulate successful payment
        if (app()->environment('local')) {
            return redirect()->route('store.order.confirmed', ['slug' => $slug, 'order' => $order->id])
                ->with('info', 'Pago simulado — modo prueba. El pedido fue creado exitosamente.');
        }

        $order->load('items');

        try {
            $preference = $this->mpService->createPreference($storeConfig, [
                'items'         => $order->items->map(fn($i) => [
                    'product_id'   => $i->product_id,
                    'product_name' => $i->product_name,
                    'quantity'     => $i->quantity,
                    'unit_price'   => $i->unit_price,
                ])->toArray(),
                'shipping_cost' => $order->delivery_fee ?? 0,
                'order_id'      => $order->id,
                'success_url'   => route('store.order.payment.return', ['slug' => $slug, 'order' => $order->id, 'status' => 'success']),
                'failure_url'   => route('store.order.payment.return', ['slug' => $slug, 'order' => $order->id, 'status' => 'failure']),
                'pending_url'   => route('store.order.payment.return', ['slug' => $slug, 'order' => $order->id, 'status' => 'pending']),
            ]);

            return redirect()->away($preference['init_point']);
        } catch (\Exception $e) {
            Log::error('MP preference creation failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return redirect()->route('store.order.confirmed', ['slug' => $slug, 'order' => $order->id])
                ->with('error', 'No se pudo iniciar el pago con Mercado Pago. Intenta de nuevo.');
        }
    }

    /**
     * Handle Mercado Pago return after payment attempt.
     */
    public function paymentReturn($slug, Order $order, Request $request): RedirectResponse
    {
        $storeConfig = app('resolvedStore');

        if ($order->subscription_id !== $storeConfig->subscription_id) {
            abort(404);
        }

        $status = $request->query('status');
        $paymentId = $request->query('payment_id');

        if ($status === 'success' && $paymentId) {
            // Update the pending payment to completed
            $transaction = $order->transaction;
            if ($transaction) {
                $payment = $transaction->payments()
                    ->where('payment_method', 'card')
                    ->where('status', 'procesando')
                    ->first();

                if ($payment) {
                    $payment->update([
                        'status' => 'completado',
                        'notes'  => "Pago con Mercado Pago #{$paymentId} — pedido en línea #{$order->formatted_order_number}",
                    ]);
                }

                if ($transaction->fresh()->isFullyPaid()) {
                    $transaction->update(['status' => \App\Enums\TransactionStatus::COMPLETED]);
                }
            }
        }

        return redirect()->route('store.order.confirmed', ['slug' => $slug, 'order' => $order->id]);
    }

    /**
     * Order confirmation page.
     */
    public function confirmed($slug, Order $order): Response
    {
        $storeConfig = app('resolvedStore');

        if ($order->subscription_id !== $storeConfig->subscription_id) {
            abort(404);
        }

        $order->load('items');

        return Inertia::render('Store/Confirmed', [
            'order' => $order,
        ]);
    }
}
