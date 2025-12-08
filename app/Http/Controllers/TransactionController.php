<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\SessionCashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\QuoteStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Services\TransactionPaymentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class TransactionController extends Controller implements HasMiddleware
{
    public function __construct(protected TransactionPaymentService $transactionPaymentService) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:transactions.access', only: ['index']),
            new Middleware('can:transactions.see_details', only: ['show']),
            new Middleware('can:transactions.cancel', only: ['cancel']),
            new Middleware('can:transactions.refund', only: ['refund']),
            new Middleware('can:transactions.add_payment', only: ['addPayment']),
            new Middleware('can:transactions.exchange', only: ['exchange']), // Nuevo permiso recomendado
        ];
    }

    public function index(Request $request): Response
    {
        // ... (código existente del index)
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = Transaction::query()
            ->leftJoin('customers', 'transactions.customer_id', '=', 'customers.id')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.channel', '!=', TransactionChannel::BALANCE_PAYMENT)
            ->with(['customer:id,name', 'user:id,name', 'payments'])
            ->select('transactions.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('transactions.folio', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('customers.name', 'LIKE', "%{$searchTerm}%");
            });
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

        return Inertia::render('Transaction/Index', [
            'transactions' => $transactions,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'availableTemplates' => $availableTemplates,
        ]);
    }

    public function show(Transaction $transaction): Response
    {
        // ... (código existente del show)
        $transaction->load([
            'customer:id,name,balance,credit_limit',
            'user:id,name',
            'branch:id,name',
            'items.itemable',
            'payments.bankAccount',
        ]);

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

    /**
     * Procesa un cambio de productos.
     */
    public function exchange(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'returned_items' => 'required|array|min:1',
            'returned_items.*.item_id' => 'required|exists:transactions_items,id',
            'returned_items.*.quantity' => 'required|integer|min:1',
            
            // Datos para la nueva venta
            'new_items' => 'required|array|min:1',
            'new_items.*.id' => 'required|exists:products,id',
            'new_items.*.quantity' => 'required|numeric|min:1',
            'new_items.*.unit_price' => 'required|numeric|min:0',
            
            // Validaciones para campos opcionales
            'new_items.*.description' => 'required|string', 
            'new_items.*.discount' => 'nullable|numeric',
            'new_items.*.product_attribute_id' => 'nullable', 

            'subtotal' => 'required|numeric',
            'total_discount' => 'numeric',
            
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|string',
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string',
            
            'notes' => 'nullable|string|max:255',

            'new_customer_id' => 'nullable|exists:customers,id',
            'exchange_refund_type' => 'nullable|in:balance,cash',
        ]);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
            return redirect()->back()->with(['error' => 'No se pueden realizar cambios en transacciones canceladas o reembolsadas.']);
        }

        try {
            $newTransaction = $this->transactionPaymentService->handleProductExchange(
                Auth::user(),
                $transaction,
                $validated
            );

            return redirect()->route('transactions.show', $newTransaction->id)
                ->with('success', 'Cambio realizado con éxito. Nueva venta #' . $newTransaction->folio);

        } catch (\Exception $e) {
            Log::error("Error al procesar cambio en transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function addPayment(Request $request, Transaction $transaction)
    {
        // 1. Validaciones básicas
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|string',
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string|max:255',
            'use_balance' => 'boolean',
        ]);

        // 2. Validar estado de la transacción
        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
            return redirect()->back()->with(['error' => 'No se pueden agregar pagos a transacciones canceladas o reembolsadas.']);
        }

        try {
            // 3. Procesar el pago usando el servicio
            $this->transactionPaymentService->applyPaymentToTransaction(
                $transaction,
                $validated,
                $validated['cash_register_session_id']
            );

            return redirect()->back()->with('success', 'Abono registrado con éxito.');
        } catch (\Exception $e) {
            Log::error("Error al registrar abono en transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function cancel(Transaction $transaction)
    {
        $transaction->loadMissing('payments');
        $totalPaid = $transaction->payments->sum('amount');

        if ($totalPaid > 0) {
            return redirect()->back()->with(['error' => 'No se puede cancelar una venta con pagos registrados. Debe generar una devolución.']);
        }

        if (!in_array($transaction->status, [TransactionStatus::PENDING, TransactionStatus::COMPLETED])) {
            return redirect()->back()->with(['error' => 'Solo se pueden cancelar ventas pendientes o completadas (sin pagos).']);
        }

        try {
            DB::transaction(function () use ($transaction) {
                $originalStatus = $transaction->status;

                if (in_array($originalStatus, [TransactionStatus::COMPLETED, TransactionStatus::PENDING])) {
                    $this->returnStock($transaction);
                }

                if ($transaction->customer_id && in_array($originalStatus, [TransactionStatus::COMPLETED, TransactionStatus::PENDING])) {
                    $originalChargeMovement = $transaction->customerBalanceMovements()
                        ->where('type', CustomerBalanceMovementType::CREDIT_SALE)
                        ->first();

                    if ($originalChargeMovement) {
                        $customer = Customer::findOrFail($transaction->customer_id);
                        $amountToCredit = $originalChargeMovement->amount;

                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT,
                            'amount' => abs($amountToCredit),
                            'balance_after' => $customer->balance + abs($amountToCredit),
                            'notes' => 'Ajuste por cancelación de venta #' . $transaction->folio,
                        ]);

                        $customer->increment('balance', abs($amountToCredit));
                    }
                }

                $transaction->update(['status' => TransactionStatus::CANCELLED]);

                $transaction->loadMissing('transactionable');
                if ($transaction->transactionable instanceof Quote) {
                    $transaction->transactionable->update([
                        'status' => QuoteStatus::CANCELLED
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Error al cancelar transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error inesperado al cancelar la venta.']);
        }

        return redirect()->back()->with('success', 'Venta cancelada con éxito.');
    }

    public function refund(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'refund_method' => ['required', 'in:balance,cash'],
        ]);
        $refundMethod = $validated['refund_method'];

        $transaction->loadMissing('payments');
        $totalPaid = $transaction->payments->sum('amount');

        $canRefund = (
            $transaction->status === TransactionStatus::COMPLETED ||
            ($transaction->status === TransactionStatus::PENDING && $totalPaid > 0) ||
            $transaction->status === TransactionStatus::ON_LAYAWAY
        ) && !in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED]);


        if (!$canRefund) {
            return redirect()->back()->with(['error' => 'Esta venta no cumple las condiciones para ser reembolsada.']);
        }

        if ($totalPaid <= 0) {
            return redirect()->back()->with(['error' => 'No hay pagos registrados para reembolsar en esta venta.']);
        }

        if ($refundMethod === 'balance' && !$transaction->customer_id) {
            return redirect()->back()->with(['error' => 'No se puede abonar a saldo si la venta no tiene un cliente asociado.']);
        }

        $user = Auth::user();

        try {
            DB::transaction(function () use ($transaction, $refundMethod, $totalPaid, $user) {
                $this->returnStock($transaction);

                $amountToRefund = $totalPaid;

                if ($refundMethod === 'balance') {
                    $customer = Customer::findOrFail($transaction->customer_id);

                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::REFUND_CREDIT,
                        'amount' => $amountToRefund,
                        'balance_after' => $customer->balance + $amountToRefund,
                        'notes' => 'Reembolso (a saldo) de venta #' . $transaction->folio,
                    ]);
                    $customer->increment('balance', $amountToRefund);
                } elseif ($refundMethod === 'cash') {
                    $activeSession = $user->cashRegisterSessions()
                        ->where('status', CashRegisterSessionStatus::OPEN)
                        ->first();

                    if (!$activeSession) {
                        throw new LogicException('No tienes una sesión de caja activa para registrar el retiro de efectivo.');
                    }

                    $activeSession->cashMovements()->create([
                        'user_id' => $user->id,
                        'type' => SessionCashMovementType::OUTFLOW,
                        'amount' => $amountToRefund,
                        'description' => "Reembolso en efectivo de venta #" . $transaction->folio,
                        'notes' => 'Devolución en efectivo de venta #' . $transaction->folio,
                    ]);
                }

                $transaction->update(['status' => TransactionStatus::REFUNDED]);

                $transaction->loadMissing('transactionable');
                if ($transaction->transactionable instanceof Quote) {
                    $transaction->transactionable->update([
                        'status' => QuoteStatus::CANCELLED
                    ]);
                }
            });
        } catch (LogicException $e) {
            Log::warning("Intento de reembolso sin sesión activa/cliente por usuario {$user->id} para transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error("Error al reembolsar transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error inesperado al generar la devolución.']);
        }

        return redirect()->back()->with('success', 'Devolución generada con éxito.');
    }

    /**
     * Endpoint ligero para buscar productos (Nombre o SKU) para el modal de intercambio.
     * Retorna JSON simplificado.
     */
    public function searchProducts(Request $request)
    {
        $query = $request->input('query');
        if (!$query) return response()->json([]);

        $user = Auth::user();
        $products = Product::where('branch_id', $user->branch_id)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'sku', 'selling_price', 'current_stock']) // Solo campos necesarios
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'selling_price' => (float) $p->selling_price,
                    // Si tienes variantes, este es un lugar para expandir lógica,
                    // por ahora retornamos productos simples o el padre.
                ];
            });

        return response()->json($products);
    }

    private function returnStock(Transaction $transaction)
    {
        foreach ($transaction->items as $item) {
            if ($item->itemable instanceof Product || $item->itemable instanceof \App\Models\ProductAttribute) {
                if (is_numeric($item->quantity)) {
                    $item->itemable->increment('current_stock', $item->quantity);
                } else {
                    Log::warning("Skipping stock increment for item {$item->id} in transaction {$transaction->id} due to non-numeric quantity: " . $item->quantity);
                }
            }
        }
    }
}
