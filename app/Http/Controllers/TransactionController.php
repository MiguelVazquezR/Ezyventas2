<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\ProcessLayawayExchange;
use App\Actions\Transactions\ProcessProductExchange;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Transaction;
use App\Services\TransactionPaymentService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller implements HasMiddleware
{
    public function __construct(protected TransactionPaymentService $transactionPaymentService) {}

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
            ->with(['customer:id,name', 'user:id,name', 'payments.bankAccount', 'items'])
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
        $validated = $request->validate(['created_at' => 'required|date']);
        $transaction->update(['created_at' => $validated['created_at']]);
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
            $this->transactionPaymentService->applyPaymentToTransaction($transaction, $validated, $validated['cash_register_session_id']);
            return redirect()->back()->with('success', 'Abono registrado con éxito.');
        } catch (\Exception $e) {
            Log::error("Error al registrar abono en transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Cancela o Reembolsa una transacción delegando movimientos a los Modelos.
     */
    public function cancel(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'action' => 'required|in:refund,penalty',
            'refund_method' => 'required_if:action,refund|in:cash,balance,transfer',
            'bank_account_id' => 'required_if:refund_method,transfer|exists:bank_accounts,id',
        ]);

        $transaction->loadMissing(['payments', 'customer', 'items.itemable']);
        $totalPaid = $transaction->payments->sum('amount');
        $isLayaway = in_array($transaction->status, [TransactionStatus::ON_LAYAWAY]);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
            return redirect()->back()->with(['error' => 'La transacción ya se encuentra cancelada o reembolsada.']);
        }

        $action = $request->input('action', 'penalty');
        $refundMethod = $request->input('refund_method');
        $bankAccountId = $request->input('bank_account_id');

        if ($totalPaid > 0 && $action === 'refund') {
            if ($refundMethod === 'balance' && !$transaction->customer_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['refund_method' => 'Se requiere un cliente asignado para abonar a saldo.']);
            }
            if ($refundMethod === 'cash') {
                $activeSession = \Illuminate\Support\Facades\Auth::user()->cashRegisterSessions()
                    ->where('status', \App\Enums\CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', function ($query) {
                        $query->where('branch_id', \Illuminate\Support\Facades\Auth::user()->branch_id);
                    })
                    ->first();
                if (!$activeSession) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['refund_method' => 'Se requiere una sesión de caja activa para devolver efectivo.']);
                }
            }
        }

        try {
            DB::transaction(function () use ($request, $transaction, $totalPaid, $action, $refundMethod, $bankAccountId, $isLayaway) {
                // A. REFACTOR: Devolver Stock delegando a los modelos
                $this->returnStock($transaction);

                // B. REFACTOR: Manejar Deuda y Saldos mediante métodos semánticos
                if ($transaction->customer_id) {
                    $customer = $transaction->customer;
                    $pendingDebt = $transaction->total - $totalPaid;

                    if ($totalPaid > 0) {
                        if ($action === 'penalty') {
                            if ($pendingDebt > 0.01) {
                                $customer->cancelDebt($pendingDebt, $transaction->id, 'Cancelación de ' . ($isLayaway ? 'apartado' : 'venta') . ' #' . $transaction->folio . ' (Penalización). Se retienen $' . number_format($totalPaid, 2));
                            }
                        } else {
                            if ($refundMethod === 'balance') {
                                // Reembolsamos la totalidad (pago devuelto + deuda perdonada)
                                $customer->addRefund($transaction->total, $transaction->id, 'Reembolso a saldo por cancelación de ' . ($isLayaway ? 'apartado' : 'venta') . ' #' . $transaction->folio);
                            } else {
                                // Cash o Transfer: Se devuelve el dinero fuera del sistema, pero sí perdonamos la deuda remanente.
                                if ($pendingDebt > 0.01) {
                                    $customer->cancelDebt($pendingDebt, $transaction->id, "Cancelación de " . ($isLayaway ? 'apartado' : 'venta') . " #{$transaction->folio}. Reembolso entregado por fuera.");
                                }
                            }
                        }
                    } else {
                        // No pagó nada. Solo perdonamos la deuda total.
                        if ($transaction->total > 0.01) {
                            $customer->cancelDebt($transaction->total, $transaction->id, 'Cancelación de ' . ($isLayaway ? 'apartado' : 'venta') . ' #' . $transaction->folio);
                        }
                    }
                }

                // C. Manejar Salida de Efectivo o Banco
                if ($action === 'refund' && $totalPaid > 0) {
                    if ($refundMethod === 'cash') {
                        // Filtramos para obtener estrictamente la sesión abierta en la sucursal actual
                        $activeSession = \Illuminate\Support\Facades\Auth::user()->cashRegisterSessions()
                            ->where('status', \App\Enums\CashRegisterSessionStatus::OPEN)
                            ->whereHas('cashRegister', function ($query) {
                                $query->where('branch_id', \Illuminate\Support\Facades\Auth::user()->branch_id);
                            })
                            ->first();

                        if ($activeSession) {
                            $activeSession->cashMovements()->create([
                                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                                'type' => \App\Enums\SessionCashMovementType::OUTFLOW,
                                'amount' => $totalPaid,
                                'description' => "Devolución venta #{$transaction->folio}. Devolución de efectivo por cancelación.",
                            ]);
                        }
                    } elseif ($refundMethod === 'transfer') {
                        $bankAccount = \App\Models\BankAccount::find($bankAccountId);
                        if ($bankAccount) {
                            // Descontar saldo real de la cuenta
                            $bankAccount->decrement('balance', $totalPaid);

                            // Crear el Pago Negativo
                            $transaction->payments()->create([
                                'amount' => -$totalPaid,
                                'payment_method' => \App\Enums\PaymentMethod::TRANSFER->value,
                                'status' => \App\Enums\PaymentStatus::COMPLETED->value,
                                'bank_account_id' => $bankAccount->id,
                                'notes' => 'Reembolso por cancelación de venta.',
                            ]);
                        }
                    }
                }

                // D. Actualizar Estatus Transacción
                $newStatus = ($action === 'refund') ? TransactionStatus::REFUNDED : TransactionStatus::CANCELLED;
                $transaction->update(['status' => $newStatus]);

                // E. Cancelar Cotización (si aplica)
                if ($transaction->transactionable_type === \App\Models\Quote::class && $transaction->transactionable_id) {
                    $transaction->transactionable->update(['status' => \App\Enums\QuoteStatus::CANCELLED]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Error al cancelar transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error inesperado al cancelar.']);
        }

        $msg = 'Transacción cancelada correctamente.';
        if ($action === 'refund') {
            if ($refundMethod === 'balance') $msg = 'Transacción reembolsada al saldo del cliente.';
            elseif ($refundMethod === 'transfer') $msg = 'Transacción reembolsada por transferencia bancaria.';
            else $msg = 'Transacción reembolsada en efectivo.';
        } elseif ($totalPaid > 0) {
            $msg = 'Transacción cancelada con penalización (dinero retenido).';
        }

        return redirect()->back()->with('success', $msg);
    }

    public function destroy(Transaction $transaction)
    {
        try {
            DB::transaction(function () use ($transaction) {
                if (!in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
                    $this->returnStock($transaction);
                }
            });
            $transaction->delete();

            return redirect()->back()->with('success', 'Venta eliminada permanentemente y saldos ajustados.');
        } catch (\Exception $e) {
            Log::error("Error al eliminar transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error al intentar eliminar la venta.']);
        }
    }

    public function refund(Request $request, Transaction $transaction)
    {
        $request->merge(['action' => 'refund']);
        return $this->cancel($request, $transaction);
    }

    public function updatePayment(Request $request, Transaction $transaction, Payment $payment)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($transaction, $payment, $validated) {
            if ($validated['payment_method'] === PaymentMethod::CASH->value) {
                $validated['bank_account_id'] = null;
            }

            $payment->update([
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'bank_account_id' => $validated['bank_account_id'],
                'notes' => $validated['notes'],
            ]);

            $totalPaid = $transaction->payments()->where('status', \App\Enums\PaymentStatus::COMPLETED)->sum('amount');

            if ($totalPaid >= $transaction->total) {
                if ($transaction->status !== TransactionStatus::COMPLETED) {
                    $transaction->update(['status' => TransactionStatus::COMPLETED]);
                }
            } else {
                if ($transaction->status === TransactionStatus::COMPLETED) {
                    $transaction->update(['status' => TransactionStatus::PENDING]);
                }
            }
        });

        return back()->with('success', 'Pago actualizado correctamente.');
    }

    /**
     * Elimina un pago y revierte sus efectos delegando a los Modelos.
     */
    public function destroyPayment(Request $request, Transaction $transaction, Payment $payment)
    {
        if ($payment->transaction_id !== $transaction->id) abort(403);

        try {
            DB::transaction(function () use ($transaction, $payment) {
                // 1. Revertir saldo de banco
                if ($payment->bank_account_id) {
                    $bankAccount = BankAccount::find($payment->bank_account_id);
                    if ($bankAccount) {
                        $bankAccount->decrement('balance', $payment->amount);
                    }
                }

                // 2. REFACTOR: Revertir saldo a favor usando Modelos
                if ($payment->payment_method->value === 'saldo' && $transaction->customer_id) {
                    $customer = $transaction->customer;
                    if ($customer) {
                        $customer->addRefund($payment->amount, $transaction->id, "Reversión por eliminación de pago en venta #{$transaction->folio}");
                    }
                }

                // 3. Revertir movimiento de caja si es efectivo
                if ($payment->payment_method->value === 'efectivo' && $payment->cash_register_session_id) {
                    $session = CashRegisterSession::find($payment->cash_register_session_id);
                    if ($session) {
                        $session->cashMovements()
                            ->where('amount', $payment->amount)
                            ->where('type', \App\Enums\SessionCashMovementType::INFLOW)
                            ->where('description', 'like', "%{$transaction->folio}%")
                            ->delete();
                    }
                }

                // 4. Eliminar el pago
                $payment->delete();

                // 5. Actualizar estatus de la transacción si ya no está liquidada
                $totalPaid = $transaction->payments()->sum('amount');
                $total = $transaction->total ?? ($transaction->subtotal - $transaction->total_discount + $transaction->total_tax);

                if ($totalPaid < $total && $transaction->status === TransactionStatus::COMPLETED) {
                    $newStatus = $transaction->layaway_expiration_date ? TransactionStatus::ON_LAYAWAY : TransactionStatus::PENDING;
                    $transaction->update(['status' => $newStatus]);
                }
            });

            return redirect()->back()->with('success', 'Pago eliminado correctamente.');
        } catch (\Exception $e) {
            Log::error("Error al eliminar pago: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error al eliminar el pago.']);
        }
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
            $data = $validated;
            $data['customer_id'] = $validated['customerId'];
            $transaction = $this->transactionPaymentService->handleNewOrder(Auth::user(), $data);

            return redirect()->back()->with('success', "Pedido #{$transaction->folio} creado correctamente.");
        } catch (\Exception $e) {
            Log::error("Error creando pedido: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Restaura el inventario iterando delegando al polimorfismo del Modelo.
     */
    private function returnStock(Transaction $transaction)
    {
        $branchId = $transaction->branch_id;
        $user = Auth::user() ?? $transaction->user;

        foreach ($transaction->items as $item) {
            if ($itemable = $item->itemable) {
                $isReservation = in_array($transaction->status, [TransactionStatus::ON_LAYAWAY, TransactionStatus::TO_DELIVER]);

                if ($isReservation) {
                    $itemable->releaseLayawayStock($branchId, $item->quantity, $user, "Liberación de reserva por cancelación {$transaction->folio}");
                } else {
                    $itemable->restock($branchId, $item->quantity, $user, "Retorno de stock por cancelación/reembolso {$transaction->folio}");
                }
            }
        }
    }
}
