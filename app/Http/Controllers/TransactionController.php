<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\SessionCashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Product;
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
            // --- INICIO: CORRECCIÓN - Cargar pagos ---
            ->with(['customer:id,name', 'user:id,name', 'payments']) // <-- Añadido 'payments'
            // --- FIN: CORRECCIÓN ---
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

        // Cargar explícitamente los pagos si no se cargaron ya (esto es redundante ahora con el with(), pero seguro)
        // $transactions->load('payments');

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
            'payments.bankAccount', // Asegúrate que 'payments' ya está aquí
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

                // Solo reponer stock si estaba completada (pendiente no consumió stock)
                if ($originalStatus === TransactionStatus::COMPLETED) {
                    $this->returnStock($transaction);
                }

                // Ajustar saldo solo si fue a crédito (movimiento original existe)
                if ($transaction->customer_id && in_array($originalStatus, [TransactionStatus::COMPLETED, TransactionStatus::PENDING])) {
                    $originalChargeMovement = $transaction->customerBalanceMovements()
                        ->where('type', CustomerBalanceMovementType::CREDIT_SALE)
                        ->first();

                    if ($originalChargeMovement) {
                        $customer = Customer::findOrFail($transaction->customer_id);
                        $amountToCredit = $originalChargeMovement->amount; // Monto original del cargo

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

        // Cargar pagos para calcular el monto a devolver y verificar si se puede reembolsar
        $transaction->loadMissing('payments');
        $totalPaid = $transaction->payments->sum('amount');

        // Reglas para poder reembolsar:
        // 1. Debe estar 'completado' O ('pendiente' Y tener pagos)
        // 2. No debe estar ya 'cancelado' o 'reembolsado'
        $canRefund = (
            $transaction->status === TransactionStatus::COMPLETED ||
            ($transaction->status === TransactionStatus::PENDING && $totalPaid > 0)
        ) && !in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED]);


        if (!$canRefund) {
            return redirect()->back()->with(['error' => 'Esta venta no cumple las condiciones para ser reembolsada.']);
        }

        // Si no se ha pagado nada, no hay nada que reembolsar
        if ($totalPaid <= 0) {
            return redirect()->back()->with(['error' => 'No hay pagos registrados para reembolsar en esta venta.']);
        }

        if ($refundMethod === 'balance' && !$transaction->customer_id) {
            return redirect()->back()->with(['error' => 'No se puede abonar a saldo si la venta no tiene un cliente asociado.']);
        }

        try {
            DB::transaction(function () use ($transaction, $refundMethod, $totalPaid) { // <-- Pasamos $totalPaid a la transacción
                // 1. Reponer el stock SIEMPRE que se reembolse (ya sea completada o pendiente con pago)
                $this->returnStock($transaction);

                $user = Auth::user();

                // --- INICIO: CORRECCIÓN - Usar $totalPaid ---
                $amountToRefund = $totalPaid; // El monto a devolver es lo que se pagó
                // --- FIN: CORRECCIÓN ---


                // 2. Procesar según el método de reembolso elegido
                if ($refundMethod === 'balance') {
                    $customer = Customer::findOrFail($transaction->customer_id);

                    // Siempre se crea un movimiento positivo (a favor) por el monto pagado
                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::REFUND_CREDIT, // O BALANCE_REFUND si lo prefieres
                        'amount' => $amountToRefund, // Monto pagado, siempre positivo
                        'balance_after' => $customer->balance + $amountToRefund,
                        'notes' => 'Reembolso (a saldo) de venta #' . $transaction->folio,
                    ]);
                    $customer->increment('balance', $amountToRefund); // Aumenta el saldo a favor

                } elseif ($refundMethod === 'cash') {
                    $activeSession = $user->cashRegisterSessions()
                        ->where('status', CashRegisterSessionStatus::OPEN)
                        ->first();

                    if (!$activeSession) {
                        throw new LogicException('No tienes una sesión de caja activa para registrar el retiro de efectivo.');
                    }

                    // Crear el movimiento de egreso por el monto pagado
                    $activeSession->cashMovements()->create([
                        'user_id' => $user->id,
                        'type' => SessionCashMovementType::OUTFLOW,
                        'amount' => $amountToRefund, // Monto pagado
                        'description' => "Reembolso en efectivo de venta #" . $transaction->folio, // Asumiendo que no tienes 'description'
                        'notes' => 'Devolución en efectivo de venta #' . $transaction->folio,
                    ]);
                }

                // 3. Actualizar el estado de la transacción a REEMBOLSADA
                $transaction->update(['status' => TransactionStatus::REFUNDED]);
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

    private function returnStock(Transaction $transaction)
    {
        // No es necesario cargar items aquí si ya se cargan antes o se asume que están cargados
        // $transaction->loadMissing('items.itemable');
        foreach ($transaction->items as $item) {
            if ($item->itemable instanceof Product) {
                // Asegurarse de que quantity sea numérico antes de incrementar
                if (is_numeric($item->quantity)) {
                    $item->itemable->increment('current_stock', $item->quantity);
                } else {
                    Log::warning("Skipping stock increment for item {$item->id} in transaction {$transaction->id} due to non-numeric quantity: " . $item->quantity);
                }
            }
        }
    }
}