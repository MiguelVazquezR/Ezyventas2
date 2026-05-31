<?php

namespace App\Http\Controllers\Store;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class PublicStoreController extends Controller
{
    /**
     * Store home page — product catalog.
     */
    public function index(Request $request): Response
    {
        $storeConfig = app('resolvedStore');
        $subscriptionId = $storeConfig->subscription_id;

        $query = Product::with('media', 'category')
            ->where('show_online', true)
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->orderBy('store_sort_order')
            ->orderBy('name');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where('name', 'LIKE', "%{$searchTerm}%");
        }

        if ($request->has('category')) {
            $catId = $request->input('category');
            $query->where('category_id', $catId);
        }

        $products = $query->paginate(24)->withQueryString();

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
            'categories' => $categories,
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    /**
     * Product detail page.
     */
    public function show(Request $request, $product): Response
    {
        $storeConfig = app('resolvedStore');
        $subscriptionId = $storeConfig->subscription_id;

        $productModel = Product::where('id', $product)
            ->where('show_online', true)
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->first();

        if (!$productModel) {
            abort(404);
        }

        $productModel->load('media');

        return Inertia::render('Store/Show', [
            'product' => [
                'id' => $productModel->id,
                'name' => $productModel->name,
                'description' => $productModel->description,
                'price' => $productModel->online_price ?? $productModel->selling_price,
                'category' => $productModel->category?->name,
                'image_url' => $productModel->getFirstMediaUrl('product-general-images') ?: null,
            ],
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
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'delivery_type' => ['required', 'in:pickup,delivery'],
            'delivery_address' => ['required_if:delivery_type,delivery', 'nullable', 'string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],
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
                'status' => OrderStatus::Pending,
                'delivery_type' => $validated['delivery_type'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'customer_notes' => $validated['customer_notes'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
            ]);

            $order->items()->createMany($orderItems);
            $order->logStatusChange(OrderStatus::Pending, OrderStatus::Pending, 'Pedido realizado por el cliente.');

            return $order;
        });

        return redirect()->route('store.order.confirmed', [
            'slug' => $storeConfig->slug,
            'order' => $order->id,
        ]);
    }

    /**
     * Order confirmation page.
     */
    public function confirmed(Order $order): Response
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
