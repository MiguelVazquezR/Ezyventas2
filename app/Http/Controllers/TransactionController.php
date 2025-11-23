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
    public static function middleware(): array
    {
        return [
            new Middleware('can:transactions.access', only: ['index']),
            new Middleware('can:transactions.see_details', only: ['show']),
            new Middleware('can:transactions.cancel', only: ['cancel']),
            new Middleware('can:transactions.refund', only: ['refund']),
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
        $transaction->load([
            'customer:id,name,balance',
            'user:id,name',
            'branch:id,name',
            'items.itemable',
            'payments.bankAccount',
        ]);

        $availableTemplates = Auth::user()->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('Transaction/Show', [
            'transaction' => $transaction,
            'availableTemplates' => $availableTemplates,
        ]);
    }

    public function cancel(Transaction $transaction)
    {
        // Cargar pagos para verificar si existen antes de cancelar
        $transaction->loadMissing('payments');
        $totalPaid = $transaction->payments->sum('amount');

        // Aplicar la regla: solo cancelar si no hay pagos
        if ($totalPaid > 0) {
            return redirect()->back()->with(['error' => 'No se puede cancelar una venta con pagos registrados. Debe generar una devolución.']);
        }

        if (!in_array($transaction->status, [TransactionStatus::PENDING, TransactionStatus::COMPLETED])) {
            return redirect()->back()->with(['error' => 'Solo se pueden cancelar ventas pendientes o completadas (sin pagos).']);
        }


        try {
            DB::transaction(function () use ($transaction) {
                $originalStatus = $transaction->status;

                // CORRECCIÓN: Reponer stock si estaba completada O PENDIENTE.
                if (in_array($originalStatus, [TransactionStatus::COMPLETED, TransactionStatus::PENDING])) {
                    $this->returnStock($transaction);
                }

                // Ajustar saldo solo si fue a crédito (movimiento original existe)
                if ($transaction->customer_id && in_array($originalStatus, [TransactionStatus::COMPLETED, TransactionStatus::PENDING])) {
                    $originalChargeMovement = $transaction->customerBalanceMovements()
                        ->where('type', CustomerBalanceMovementType::CREDIT_SALE)
                        ->first();

                    if ($originalChargeMovement) {
                        $customer = Customer::findOrFail($transaction->customer_id);
                        $amountToCredit = $originalChargeMovement->amount; // Monto original del cargo (negativo)

                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT,
                            'amount' => abs($amountToCredit), // Reversión positiva
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

        // CORRECCIÓN: Definir $user aquí para que esté disponible en el catch
        $user = Auth::user();

        try {
            // CORRECCIÓN: Pasar $user al closure con 'use'
            DB::transaction(function () use ($transaction, $refundMethod, $totalPaid, $user) { 
                // 1. Reponer el stock SIEMPRE que se reembolse
                $this->returnStock($transaction);

                $amountToRefund = $totalPaid; 

                // 2. Procesar según el método de reembolso elegido
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

                // 3. Actualizar el estado de la transacción a REEMBOLSADA
                $transaction->update(['status' => TransactionStatus::REFUNDED]);

                $transaction->loadMissing('transactionable');
                if ($transaction->transactionable instanceof Quote) {
                    $transaction->transactionable->update([
                        'status' => QuoteStatus::CANCELLED 
                    ]);
                }
            });
        } catch (LogicException $e) {
            // CORRECCIÓN: Ahora $user está definido y se puede acceder a $user->id
            Log::warning("Intento de reembolso sin sesión activa/cliente por usuario {$user->id} para transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error("Error al reembolsar transacción {$transaction->id}: " . $e->getMessage());
            return redirect()->back()->with(['error' => 'Ocurrió un error inesperado al generar la devolución.']);
        }

        return redirect()->back()->with('success', 'Devolución generada con éxito.');
    }

    private function returnStock(Transaction $transaction)
    {
        foreach ($transaction->items as $item) {
            // Verificamos si el item es un Producto (Simple) o una Variante
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