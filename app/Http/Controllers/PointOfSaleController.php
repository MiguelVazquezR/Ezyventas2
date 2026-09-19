<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\CustomerBalanceMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Catalog\ProductCatalogService;
use App\Services\TransactionPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Laravel\Jetstream\Agent;

class PointOfSaleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:pos.access', only: ['index', 'searchCustomers', 'checkEntity', 'getOnlineOrders', 'updateOnlineOrderStatus']),
            new Middleware('can:pos.create_sale', only: ['checkout']),
        ];
    }

    public function __construct(
        protected TransactionPaymentService $transactionPaymentService,
        private readonly ProductCatalogService $productCatalog,
    ) {}

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $isOwner = !$user->roles()->exists();

        $activeSession = $user->cashRegisterSessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $branchId))
            ->with([
                'cashRegister:id,name',
                'users:id,name',
                'opener:id,name',
                'transactions' => fn($q) => $q->with([
                    'customer:id,name',
                    'user:id,name'
                ])->latest(),
                'cashMovements' => fn($q) => $q->with([
                    'user:id,name'
                ])->latest(),
                'payments.transaction' => function ($query) {
                    $query->with(['customer:id,name', 'user:id,name']);
                },
            ])
            ->first();

        $joinableSessions = [];
        $availableCashRegisters = [];
        $userBankAccounts = null;

        if (!$activeSession) {
            $joinableSessions = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $branchId))
                ->with('cashRegister:id,name', 'opener:id,name')
                ->get();

            $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
                ->where('is_active', true)
                ->where('in_use', false)
                ->select('id', 'name')
                ->get();

            if ($isOwner) {
                $userBankAccounts = Auth::user()->branch->bankAccounts()->get();
            } else {
                $userBankAccounts = $user->bankAccounts()->get();
            }
        }

        if ($activeSession) {
            $paymentTotals = $activeSession->payments
                ->where('status', 'completado')
                ->groupBy('payment_method.value')
                ->map->sum('amount');

            $activeSession->totals = [
                'cash' => $paymentTotals['efectivo'] ?? 0,
                'card' => $paymentTotals['tarjeta'] ?? 0,
                'transfer' => $paymentTotals['transferencia'] ?? 0,
                'balance' => $paymentTotals['saldo'] ?? 0,
            ];
        }

        $search = $request->input('search');
        $categoryId = $request->input('category');
        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::POS, TemplateContextType::GENERAL])
            ->get();

        $props = [
            'products' => $this->getProductsData($search, $categoryId),
            'categories' => Category::getPosCategories($user->branch->subscription_id, $branchId),
            'customers' => $this->getCustomersData(),
            'defaultCustomer' => $this->getDefaultCustomerData(),
            'filters' => $request->only(['search', 'category']),
            'activePromotions' => $this->productCatalog->activePromotions($user->branch->subscription_id),
            'activeSession' => $activeSession,
            'joinableSessions' => $joinableSessions,
            'availableCashRegisters' => $availableCashRegisters,
            'availableTemplates' => $availableTemplates,
            'userBankAccounts' => $userBankAccounts,
            'hasOnlineStore' => in_array('Tienda en lÃ­nea', $user->branch->subscription->getAvailableModuleNames()),
        ];

        $agent = new Agent();
        $view = ($agent->isMobile() || $agent->isTablet()) ? 'POS/IndexMobile' : 'POS/Index';

        return Inertia::render($view, $props);
    }

    public function searchCustomers(Request $request)
    {
        $query = $request->input('query');
        $branchId = Auth::user()->branch_id;

        if (!$query) {
            return response()->json([]);
        }

        $customers = Customer::where('branch_id', $branchId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%");
            })
            ->limit(20)
            ->select('id', 'name', 'phone', 'balance', 'credit_limit')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'balance' => (float) $c->balance,
                'credit_limit' => (float) $c->credit_limit,
                'available_credit' => (float) $c->available_credit,
            ]);

        return response()->json($customers);
    }

    public function checkEntity(Request $request)
    {
        $query = trim($request->input('query'));
        if (!$query) return response()->json(null);

        $branchId = Auth::user()->branch_id;

        $transaction = Transaction::where('branch_id', $branchId)
            ->where('folio', $query)
            ->first(['id', 'folio']);

        if ($transaction) {
            return response()->json([
                'found' => true,
                'type' => 'transaction',
                'id' => $transaction->id,
                'label' => "Venta Folio: {$transaction->folio}",
                'message' => "Â¿Deseas ver los detalles de la venta {$transaction->folio}?"
            ]);
        }

        $serviceOrder = ServiceOrder::where('branch_id', $branchId)
            ->where('folio', $query)
            ->first(['id', 'folio']);

        if ($serviceOrder) {
            return response()->json([
                'found' => true,
                'type' => 'service_order',
                'id' => $serviceOrder->id,
                'label' => "Orden de Servicio: {$serviceOrder->folio}",
                'message' => "Â¿Ir a detalles de la Orden de Servicio {$serviceOrder->folio}?"
            ]);
        }

        $customer = Customer::where('branch_id', $branchId)
            ->where(function ($q) use ($query) {
                $q->where('phone', $query)
                    ->orWhere('name', 'like', $query);
            })
            ->first(['id', 'name', 'phone']);

        if ($customer) {
            return response()->json([
                'found' => true,
                'type' => 'customer',
                'id' => $customer->id,
                'label' => "Cliente: {$customer->name}",
                'message' => "Se encontrÃ³ al cliente {$customer->name}. Â¿Ir a detalles?"
            ]);
        }

        return response()->json(['found' => false]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'cartItems' => 'required|array|min:1',
            'cartItems.*.id' => 'required|exists:products,id',
            'cartItems.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'cartItems.*.quantity' => 'required|numeric|min:1',
            'cartItems.*.unit_price' => 'required|numeric|min:0',
            'cartItems.*.description' => 'required|string',
            'cartItems.*.discount' => 'required|numeric',
            'cartItems.*.discount_reason' => 'nullable|string|max:255',
            'customerId' => 'nullable|exists:customers,id',
            'guest_name' => 'nullable|string|max:255', // NUEVO: Para modo comandas/comida
            'subtotal' => 'required|numeric',
            'total_discount' => 'nullable|numeric',
            'total' => 'required|numeric',
            'payments' => 'sometimes|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string|max:255',
            'use_balance' => 'required|boolean',
            'layaway_expiration_date' => 'nullable|date',
        ]);

        $user = Auth::user();
        $customer = $validated['customerId'] ? Customer::find($validated['customerId']) : null;

        try {
            $transaction = $this->transactionPaymentService->handleNewSale(
                $validated,
                $user,
                $customer,
                TransactionStatus::PENDING,
                CustomerBalanceMovementType::CREDIT_SALE
            );

            return redirect()->route('pos.index')
                ->with('success', 'Venta registrada con Ã©xito. Folio: ' . $transaction->folio)
                ->with('print_data', ['type' => 'pos', 'id' => $transaction->id]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar la venta: ' . $e->getMessage());
        }
    }

    public function createLayaway(Request $request)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'cartItems' => 'required|array|min:1',
            'cartItems.*.id' => 'required|exists:products,id',
            'cartItems.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'cartItems.*.quantity' => 'required|numeric|min:1',
            'cartItems.*.unit_price' => 'required|numeric|min:0',
            'cartItems.*.description' => 'required|string',
            'cartItems.*.discount' => 'required|numeric',
            'cartItems.*.discount_reason' => 'nullable|string|max:255',
            'customerId' => 'nullable|exists:customers,id',
            'guest_name' => 'nullable|string|max:255', // NUEVO: Por si acaso se usa en apartados sin cliente
            'subtotal' => 'required|numeric',
            'total_discount' => 'nullable|numeric',
            'total' => 'required|numeric',
            'payments' => 'sometimes|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string|max:255',
            'use_balance' => 'required|boolean',
            'layaway_expiration_date' => 'required|date|after:today',
        ]);

        $user = Auth::user();
        // Nota: Para un apartado lo ideal es tener un customer registrado, 
        // pero lo dejamos igual por compatibilidad de cÃ³digo.
        $customer = $validated['customerId'] ? Customer::find($validated['customerId']) : null;

        try {
            $transaction = $this->transactionPaymentService->handleNewSale(
                $validated,
                $user,
                $customer,
                TransactionStatus::ON_LAYAWAY,
                CustomerBalanceMovementType::LAYAWAY_DEBT
            );

            return redirect()->route('pos.index')
                ->with('success', 'Apartado registrado con Ã©xito. Folio: ' . $transaction->folio)
                ->with('print_data', ['type' => 'pos', 'id' => $transaction->id]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el apartado: ' . $e->getMessage());
        }
    }

    private function getProductsData($search = null, $categoryId = null)
    {
        $branchId = Auth::user()->branch_id;

        $paginatedProducts = $this->productCatalog
            ->queryForBranch($branchId, ['search' => $search, 'category_id' => $categoryId])
            ->cursorPaginate(20)
            ->withQueryString();

        $paginatedProducts->through(
            fn (Product $product) => $this->productCatalog->payload($product, $branchId)
        );

        return $paginatedProducts;
    }

    private function getCustomersData()
    {
        $branchId = Auth::user()->branch_id;
        return Customer::where('branch_id', $branchId)
            ->limit(20)
            ->select('id', 'name', 'phone', 'balance', 'credit_limit')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'balance' => (float) $c->balance,
                'credit_limit' => (float) $c->credit_limit,
                'available_credit' => (float) $c->available_credit,
            ]);
    }

    private function getDefaultCustomerData()
    {
        return ['id' => null, 'name' => 'PÃºblico en General', 'phone' => '', 'balance' => 0.0, 'credit_limit' => 0.0, 'available_credit' => 0.0];
    }

    /**
     * Fetch online store orders for the POS modal (JSON).
     */
    public function getOnlineOrders(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $statusFilter = $request->input('status');

        $query = Order::with(['items', 'storeConfig:id,store_name'])
            ->where('subscription_id', $subscriptionId)
            ->when($statusFilter, fn($q) => $q->where('status', $statusFilter))
            ->latest();

        $orders = $query->paginate(15)->withQueryString();

        // Count by status for tabs (raw query returns stdClass, not models)
        $counts = Order::where('subscription_id', $subscriptionId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return response()->json([
            'orders' => $orders->through(fn(Order $order) => [
                'id' => $order->id,
                'order_number' => $order->formatted_order_number,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'delivery_type' => $order->delivery_type,
                'payment_method' => $order->payment_method,
                'status' => [
                    'value' => $order->status->value,
                    'label' => $order->status->label(),
                    'color' => $order->status->color(),
                ],
                'all_statuses' => collect(OrderStatus::cases())
                    ->reject(fn(OrderStatus $s) => $s === $order->status)
                    ->map(fn(OrderStatus $s) => [
                        'value' => $s->value,
                        'label' => $s->label(),
                    ])->values(),
                'total' => (float) $order->total,
                'items_count' => $order->items->count(),
                'items' => $order->items->map(fn($item) => [
                    'product_name' => $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ]),
                'order_detail_url' => route('online-store.orders.show', $order->id),
                'created_at' => $order->created_at->toISOString(),
                'whats_app_link' => $order->whats_app_link,
            ]),
            'counts' => $counts,
            'statuses' => collect(OrderStatus::cases())->map(fn(OrderStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
        ]);
    }

    /**
     * Update an online store order status from the POS modal (JSON).
     */
    public function updateOnlineOrderStatus(Request $request, Order $order): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if ($order->subscription_id !== $user->branch->subscription_id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = OrderStatus::from($validated['status']);
        $oldStatus = $order->status;

        $order->update(['status' => $newStatus]);

        // Restore stock when cancelling an order
        if ($newStatus === OrderStatus::Cancelled) {
            $this->restoreOrderStock($order);
        }

        $order->logStatusChange($oldStatus, $newStatus, $validated['note'] ?? null, $user->id);

        return response()->json([
            'message' => "Pedido actualizado a '{$newStatus->label()}'.",
            'status' => [
                'value' => $newStatus->value,
                'label' => $newStatus->label(),
                'color' => $newStatus->color(),
            ],
            'all_statuses' => collect(OrderStatus::cases())
                ->reject(fn(OrderStatus $s) => $s === $newStatus)
                ->map(fn(OrderStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                ])->values(),
        ]);
    }

    /**
     * Restore stock for all items in a cancelled order.
     */
    private function restoreOrderStock(Order $order): void
    {
        $order->loadMissing('items');

        $branch = \App\Models\Branch::where('subscription_id', $order->subscription_id)->first();
        if (!$branch) return;

        foreach ($order->items as $orderItem) {
            $product = \App\Models\Product::find($orderItem->product_id);
            if ($product) {
                $product->restock(
                    $branch->id,
                    $orderItem->quantity,
                    null,
                    "ReposiciÃ³n por cancelaciÃ³n de pedido en lÃ­nea #{$order->formatted_order_number}"
                );
            }
        }

        // Cancel the linked transaction if exists
        $transaction = $order->saleTransaction;
        if ($transaction && !in_array($transaction->status->value, ['cancelado', 'reembolsado'])) {
            $transaction->update(['status' => \App\Enums\TransactionStatus::CANCELLED]);
        }
    }
}
