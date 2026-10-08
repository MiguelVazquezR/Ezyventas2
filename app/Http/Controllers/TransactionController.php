<?php

namespace App\Http\Controllers;

use App\Actions\Pos\CreateStoreOrderAction;
use App\Actions\Transactions\ProcessLayawayExchange;
use App\Actions\Transactions\ProcessProductExchange;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Transaction;
use App\Services\TransactionPaymentService;
use App\Services\Transactions\TransactionCancellationService;
use App\Services\Transactions\TransactionPaymentEditService;
use App\Services\WhatsAppTicketService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller implements HasMiddleware
{
    public function __construct(
        protected TransactionPaymentService $transactionPaymentService,
        protected WhatsAppTicketService $whatsAppTicketService,
        protected CreateStoreOrderAction $createStoreOrderAction,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:transactions.access', only: ['index']),
            new Middleware('can:transactions.see_details', only: ['show', 'extendLayaway', 'rescheduleOrder']),
            new Middleware('can:transactions.cancel', only: ['cancel']),
            new Middleware('can:transactions.refund', only: ['refund']),
            new Middleware('can:transactions.add_payment', only: ['addPayment']),
            new Middleware('can:transactions.edit_payment', only: ['updatePayment']),
            new Middleware('can:transactions.exchange', only: ['exchange', 'exchangeLayaway']),
            new Middleware('can:transactions.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = Transaction::query()
            ->leftJoin('customers', 'transactions.customer_id', '=', 'customers.id')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.channel', '!=', TransactionChannel::BALANCE_PAYMENT)
            ->with(['customer:id,name', 'user:id,name', 'payments.bankAccount', 'items', 'invoice'])
            ->select('transactions.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('transactions.folio', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('customers.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('transactions.contact_info->name', 'LIKE', "%{$searchTerm}%");
            });
        }

        if ($request->has('status') && $request->input('status')) {
            $query->where('transactions.status', $request->input('status'));
        }

        if ($request->has('date_start') && $request->input('date_start')) {
            $query->whereDate('transactions.created_at', '>=', $request->input('date_start'));
        }

        if ($request->has('date_end') && $request->input('date_end')) {
            $query->whereDate('transactions.created_at', '<=', $request->input('date_end'));
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        $sortColumn = match ($sortField) {
            'customer.name' => 'customers.name',
            'user.name' => 'users.name',
            'total' => DB::raw('(transactions.subtotal - transactions.total_discount + transactions.total_tax)'),
            default => 'transactions.' . $sortField,
        };
        $query->orderBy($sortColumn, $sortOrder);

        $transactions = $query->paginate($request->input('rows', 20))->withQueryString();

        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        $isOwner = !$user->roles()->exists();
        $userBankAccounts = $isOwner
            ? $user->branch->bankAccounts()->get()
            : $user->bankAccounts()->get();

        $userBankAccounts->transform(function ($account) {
            $identifier = $account->account_number ?? $account->card_number;
            $lastDigits = $identifier ? ' (...' . substr($identifier, -4) . ')' : '';
            $account->name = "{$account->account_name} - {$account->bank_name}{$lastDigits}";
            return $account;
        });

        return Inertia::render('Transaction/Index', [
            'transactions' => $transactions,
            'filters' => $request->only(['search', 'sortField', 'sortOrder', 'status', 'date_start', 'date_end']),
            'availableTemplates' => $availableTemplates,
            'userBankAccounts' => $userBankAccounts,
        ]);
    }

    public function show(Request $request, Transaction $transaction)
    {
        $transaction->load([
            'customer:id,name,balance,credit_limit',
            'user:id,name',
            'branch:id,name',
            'invoice',
            'items.itemable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Product::class => [],
                    ProductAttribute::class => ['product'],
                ]);
            },
            'payments.bankAccount',
        ]);

        if ($request->wantsJson()) {
            $paid = $transaction->payments->sum('amount');
            $total = $transaction->total ?? ($transaction->subtotal - $transaction->total_discount + $transaction->total_tax);
            $balance = $total - $paid;

            $transaction->paid_amount = $paid;
            $transaction->pending_balance = $balance;
            $transaction->is_paid = $balance <= 0.01;

            return response()->json($transaction);
        }

        $user = Auth::user();
        $branchId = $user->branch_id;

        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        $availableCashRegisters = CashRegister::where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('in_use', false)
            ->get(['id', 'name']);

        $isOwner = !$user->roles()->exists();
        $userBankAccounts = $isOwner
            ? $user->branch->bankAccounts()->get()
            : $user->bankAccounts()->get();

        $userBankAccounts->transform(function ($account) {
            $identifier = $account->account_number ?? $account->card_number;
            $lastDigits = $identifier ? ' (...' . substr($identifier, -4) . ')' : '';
            $account->name = "{$account->account_name} - {$account->bank_name}{$lastDigits}";
            return $account;
        });

        $joinableSessions = null;

        $userHasActiveSession = $user->cashRegisterSessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->exists();

        if (!$userHasActiveSession) {
            $joinableSessions = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $branchId))
                ->with('cashRegister:id,name', 'opener:id,name')
                ->get();
        }

        return Inertia::render('Transaction/Show', [
            'transaction' => $transaction,
            'availableTemplates' => $availableTemplates,
            'availableCashRegisters' => $availableCashRegisters,
            'userBankAccounts' => $userBankAccounts,
            'joinableSessions' => $joinableSessions,
        ]);
    }

    public function exchange(Request $request, Transaction $transaction, ProcessProductExchange $exchangeAction)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'returned_items' => 'required|array|min:1',
            'returned_items.*.item_id' => 'required|exists:transactions_items,id',
            'returned_items.*.quantity' => 'required|integer|min:1',
            'new_items' => 'required|array|min:1',
            'new_items.*.id' => 'required|exists:products,id',
            'new_items.*.quantity' => 'required|numeric|min:1',
            'new_items.*.unit_price' => 'required|numeric|min:0',
            'new_items.*.description' => 'required|string',
            'new_items.*.discount' => 'nullable|numeric',
            'new_items.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'subtotal' => 'required|numeric',
            'total_discount' => 'numeric',
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|string',
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string',
            'debts_to_pay' => 'nullable|array',
            'debts_to_pay.*.id' => 'required|exists:transactions,id',
            'debts_to_pay.*.amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
            'new_customer_id' => 'nullable|exists:customers,id',
            'exchange_refund_type' => 'nullable|in:balance,cash',
            'use_credit_for_shortage' => 'boolean',
        ]);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
            return redirect()->back()->with(['error' => 'No se pueden realizar cambios en transacciones canceladas o reembolsadas.']);
        }

        if ($transaction->status === TransactionStatus::ON_LAYAWAY) {
            return redirect()->back()->with(['error' => 'Para apartados use la opción "Modificar Apartado".']);
        }

        try {
            // AQUI USAMOS LA NUEVA ACCIÓN DE DELEGACIÓN
            $newTransaction = $exchangeAction->execute(Auth::user(), $transaction, $validated);
            return redirect()->route('transactions.show', $newTransaction->id)->with('success', 'Cambio realizado con éxito. Nueva venta #' . $newTransaction->folio);
        } catch (\Exception $e) {
            Log::error("Error al procesar cambio en transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function exchangeLayaway(Request $request, Transaction $transaction, ProcessLayawayExchange $exchangeLayawayAction)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'returned_items' => 'required|array|min:1',
            'returned_items.*.item_id' => 'required|exists:transactions_items,id',
            'returned_items.*.quantity' => 'required|integer|min:1',
            'new_items' => 'required|array|min:1',
            'new_items.*.id' => 'required|exists:products,id',
            'new_items.*.quantity' => 'required|numeric|min:1',
            'new_items.*.unit_price' => 'required|numeric|min:0',
            'new_items.*.description' => 'required|string',
            'new_items.*.discount' => 'nullable|numeric',
            'new_items.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'subtotal' => 'required|numeric',
            'total_discount' => 'numeric',
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|string',
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string',
            'notes' => 'nullable|string|max:255',
            'new_customer_id' => 'nullable|exists:customers,id',
        ]);

        if ($transaction->status !== TransactionStatus::ON_LAYAWAY) {
            return redirect()->back()->with(['error' => 'Esta operación solo es válida para Apartados activos.']);
        }

        try {
            // AQUI USAMOS LA NUEVA ACCIÓN DE DELEGACIÓN
            $newTransaction = $exchangeLayawayAction->execute(Auth::user(), $transaction, $validated);
            return redirect()->route('transactions.show', $newTransaction->id)->with('success', 'Apartado modificado con éxito. Nuevo folio: #' . $newTransaction->folio);
        } catch (\Exception $e) {
            Log::error("Error al modificar apartado {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function extendLayaway(Request $request, Transaction $transaction)
    {
        $validated = $request->validate(['new_expiration_date' => 'required|date|after:today']);

        if (!in_array($transaction->status, [TransactionStatus::ON_LAYAWAY, TransactionStatus::PENDING])) {
            return back()->with(['error' => 'Solo se puede extender la fecha de apartados o créditos activos.']);
        }

        $transaction->update(['layaway_expiration_date' => $validated['new_expiration_date']]);
        return back()->with('success', 'Fecha de vencimiento actualizada correctamente.');
    }

    public function updateDate(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'created_at' => 'required|date',
            // Solo se envía cuando la venta tiene vencimiento (apartado/crédito)
            // y el usuario decide moverlo junto con la nueva fecha de la venta.
            'new_expiration_date' => 'nullable|date',
        ]);

        $data = ['created_at' => $validated['created_at']];

        if (!empty($validated['new_expiration_date'])) {
            $data['layaway_expiration_date'] = $validated['new_expiration_date'];
        }

        $transaction->update($data);

        return back()->with('success', 'Fecha de transacción actualizada correctamente.');
    }

    public function rescheduleOrder(Request $request, Transaction $transaction)
    {
        $validated = $request->validate(['new_delivery_date' => 'required|date']);
        $transaction->update(['delivery_date' => $validated['new_delivery_date']]);
        return back()->with('success', 'Fecha de entrega reprogramada correctamente.');
    }

    public function pendingDebts(Customer $customer)
    {
        $debts = $customer->transactions()
            ->whereIn('status', [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])
            ->orderBy('created_at', 'asc')
            ->get(['id', 'folio', 'subtotal', 'total_discount', 'total_tax', 'created_at']);

        $debtsWithPendingAmount = $debts->map(function ($txn) {
            $total = ($txn->subtotal - $txn->total_discount) + $txn->total_tax;
            $paid = $txn->payments()->sum('amount');

            return [
                'id' => $txn->id,
                'folio' => $txn->folio,
                'total' => $total,
                'pending_amount' => round($total - $paid, 2),
                'created_at' => $txn->created_at,
            ];
        })->filter(fn($d) => $d['pending_amount'] > 0.01)->values();

        return response()->json($debtsWithPendingAmount);
    }

    public function addPayment(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|string',
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string|max:255',
            'use_balance' => 'boolean',
        ]);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
            return redirect()->back()->with(['error' => 'No se pueden agregar pagos a transacciones canceladas o reembolsadas.']);
        }

        try {
            // Capturar el saldo pendiente ANTES de aplicar el abono (para el ticket).
            $previousDue = (float) $transaction->remaining_due;
            $customer = $transaction->customer;
            $usedBalance = (!empty($validated['use_balance']) && $customer)
                ? min((float) $customer->balance, $previousDue)
                : 0.0;

            $this->transactionPaymentService->applyPaymentToTransaction($transaction, $validated, $validated['cash_register_session_id']);

            $contactInfo = $transaction->contact_info;
            $contactPhone = is_array($contactInfo) && !empty($contactInfo['phone'])
                ? trim((string) $contactInfo['phone'])
                : null;

            // Si es un PEDIDO, el ticket es de pedido (estado + datos del pago),
            // separado del ticket de abono de las ventas normales.
            if ($transaction->isOrder()) {
                $payload = $this->whatsAppTicketService->buildOrderPaymentPayload(
                    $transaction,
                    $previousDue,
                    $validated['payments'] ?? [],
                    $usedBalance
                );

                return redirect()->back()
                    ->with('success', 'Abono registrado con éxito.')
                    ->with('print_data', [
                        'type' => 'order_payment',
                        'payload' => $payload,
                        'transaction_id' => $transaction->id,
                        'customer_phone' => $contactPhone ?: ($customer?->phone ?: null),
                        'customer_id' => $customer?->id ?: null,
                    ]);
            }

            $payload = $this->whatsAppTicketService->buildTransactionAbonoPayload(
                $transaction,
                $previousDue,
                $validated['payments'] ?? [],
                $usedBalance
            );

            return redirect()->back()
                ->with('success', 'Abono registrado con éxito.')
                ->with('print_data', [
                    'type' => 'abono',
                    'payload' => $payload,
                    'transaction_id' => $transaction->id,
                    'customer_phone' => $customer?->phone ?: null,
                    'customer_id' => $customer?->id ?: null,
                ]);
        } catch (\Exception $e) {
            Log::error("Error al registrar abono en transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Cancels or refunds a sale. The money, stock and debt movements live in
     * TransactionCancellationService, shared with the mobile app.
     */
    public function cancel(
        Request $request,
        Transaction $transaction,
        TransactionCancellationService $cancellationService,
    ) {
        $validated = $request->validate([
            'action' => 'required|in:refund,penalty',
            'refund_method' => 'required_if:action,refund|in:cash,balance,transfer',
            'bank_account_id' => 'required_if:refund_method,transfer|exists:bank_accounts,id',
        ]);

        try {
            $message = $cancellationService->cancel(
                $transaction,
                $validated['action'],
                $validated['refund_method'] ?? null,
                $validated['bank_account_id'] ?? null,
                Auth::user()
            );
        } catch (ValidationException $e) {
            // Business rules (balance without customer, no open session, ...).
            throw $e;
        } catch (\Exception $e) {
            Log::error("Error al cancelar la venta {$transaction->id}: " . $e->getMessage());

            return redirect()->back()->with(['error' => 'Ocurrió un error inesperado al cancelar.']);
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy(Transaction $transaction, TransactionCancellationService $cancellationService)
    {
        try {
            DB::transaction(function () use ($transaction, $cancellationService) {
                if (!in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
                    $cancellationService->returnStock($transaction, Auth::user());
                }
            });
            $transaction->delete();

            return redirect()->back()->with('success', 'Venta eliminada permanentemente y saldos ajustados.');
        } catch (\Exception $e) {
            Log::error("Error al eliminar transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error al intentar eliminar la venta.']);
        }
    }

    public function refund(Request $request, Transaction $transaction, TransactionCancellationService $cancellationService)
    {
        $request->merge(['action' => 'refund']);

        return $this->cancel($request, $transaction, $cancellationService);
    }

    public function updatePayment(
        Request $request,
        Transaction $transaction,
        Payment $payment,
        TransactionPaymentEditService $paymentEditor,
    ) {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string|max:255',
        ]);

        $paymentEditor->update($transaction, $payment, $validated);

        return back()->with('success', 'Pago actualizado correctamente.');
    }

    /**
     * Elimina un pago y revierte sus efectos (banco, saldo y caja).
     */
    public function destroyPayment(
        Request $request,
        Transaction $transaction,
        Payment $payment,
        TransactionPaymentEditService $paymentEditor,
    ) {
        try {
            $paymentEditor->delete($transaction, $payment);
        } catch (\Exception $e) {
            Log::error('Error al eliminar pago: ' . $e->getMessage());

            return redirect()->back()->with(['error' => 'Ocurrió un error al eliminar el pago.']);
        }

        return redirect()->back()->with('success', 'Pago eliminado correctamente.');
    }

    public function searchProducts(Request $request)
    {
        $query = $request->input('query');
        if (!$query) return response()->json([]);

        $user = Auth::user();
        $branchId = $user->branch_id;

        $products = Product::whereHas('branches', function ($q) use ($branchId) {
            $q->where('branches.id', $branchId);
        })
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            })
            ->with(['productAttributes.branches', 'branches'])
            ->limit(10)
            ->get(['id', 'name', 'sku', 'selling_price', 'description'])
            ->map(function ($p) use ($branchId) {
                $branchPivot = $p->branches->where('id', $branchId)->first()?->pivot;
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'selling_price' => (float) $p->selling_price,
                    'current_stock' => $branchPivot ? $branchPivot->current_stock : 0,
                    'description' => $p->description,
                    'variants' => $p->productAttributes->map(function ($variant) use ($branchId) {
                        $vPivot = $variant->branches->where('id', $branchId)->first()?->pivot;
                        return [
                            'id' => $variant->id,
                            'attributes' => $variant->attributes,
                            'sku_suffix' => $variant->sku_suffix,
                            'selling_price_modifier' => (float) $variant->selling_price_modifier,
                            'current_stock' => $vPivot ? $vPivot->current_stock : 0,
                        ];
                    }),
                ];
            });

        return response()->json($products);
    }

    public function storeOrder(Request $request)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'cartItems' => 'required|array|min:1',
            'cartItems.*.id' => 'required|exists:products,id',
            'cartItems.*.quantity' => 'required|numeric|min:0.01',
            'cartItems.*.unit_price' => 'required|numeric|min:0',
            'cartItems.*.description' => 'required|string',
            'cartItems.*.discount' => 'nullable|numeric',
            'cartItems.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'contact_info' => 'required|array',
            'contact_info.name' => 'required|string|min:2',
            'contact_info.phone' => 'nullable|string',
            'delivery_date' => 'required|date',
            'shipping_address' => 'nullable|string',
            'shipping_cost' => 'numeric|min:0',
            'customerId' => 'nullable|exists:customers,id',
            'subtotal' => 'required|numeric',
            'total_discount' => 'numeric',
            'notes' => 'nullable|string',
        ]);

        try {
            $transaction = $this->createStoreOrderAction->execute($validated, Auth::user());

            return redirect()->back()
                ->with('success', "Pedido #{$transaction->folio} creado correctamente.")
                ->with('print_data', ['type' => 'order', 'id' => $transaction->id]);
        } catch (\Exception $e) {
            Log::error("Error creando pedido: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Restaura el inventario iterando delegando al polimorfismo del Modelo.
     */
}
