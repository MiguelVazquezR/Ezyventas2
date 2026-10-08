<?php

namespace App\Services\Transactions;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\CashRegisterSession;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancellation and refund of a sale.
 *
 * Extracted from the web controller so the web and the phone cancel under the
 * same rules: stock comes back (or the reservation is released), the customer
 * debt is reversed and, when the money is handed back, the till or the bank
 * account is reconciled.
 */
class TransactionCancellationService
{
    /**
     * Cancels the sale and returns the message for the user.
     *
     * @param  string  $action  refund (money back) | penalty (money withheld)
     * @param  string|null  $refundMethod  cash | balance | transfer
     */
    public function cancel(
        Transaction $transaction,
        string $action,
        ?string $refundMethod,
        ?int $bankAccountId,
        User $user,
    ): string {
        $transaction->loadMissing(['payments', 'customer', 'items.itemable']);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED], true)) {
            throw ValidationException::withMessages([
                'transaction' => 'La venta ya se encuentra cancelada o reembolsada.',
            ]);
        }

        $totalPaid = (float) $transaction->payments->sum('amount');
        $isLayaway = $transaction->status === TransactionStatus::ON_LAYAWAY;
        $label = $isLayaway ? 'apartado' : 'venta';
        $refundsMoney = $action === 'refund' && $totalPaid > 0;
        $activeSession = null;

        if ($refundsMoney && $refundMethod === 'balance' && !$transaction->customer_id) {
            throw ValidationException::withMessages([
                'refund_method' => 'Se requiere un cliente asignado para abonar a saldo.',
            ]);
        }

        if ($refundsMoney && $refundMethod === 'cash') {
            $activeSession = $this->openSessionOfUser($user);

            if (!$activeSession) {
                throw ValidationException::withMessages([
                    'refund_method' => 'Se requiere una sesión de caja activa para devolver efectivo.',
                ]);
            }
        }

        DB::transaction(function () use ($transaction, $totalPaid, $action, $refundMethod, $bankAccountId, $label, $user, $activeSession) {
            $this->returnStock($transaction, $user);

            $this->reverseCustomerDebt($transaction, $totalPaid, $action, $refundMethod, $label);

            $this->settleRefund($transaction, $totalPaid, $action, $refundMethod, $bankAccountId, $user, $activeSession);

            $transaction->update([
                'status' => $action === 'refund' ? TransactionStatus::REFUNDED : TransactionStatus::CANCELLED,
            ]);

            // A quote cancelled from the phone must also leave the pipeline.
            if ($transaction->transactionable_type === Quote::class && $transaction->transactionable_id) {
                $transaction->transactionable->update(['status' => QuoteStatus::CANCELLED]);
            }
        });

        return $this->resultMessage($action, $refundMethod, $totalPaid);
    }

    /**
     * Gives the stock back to the branch: sales are restocked, layaways and
     * orders only release the reservation they were holding.
     */
    public function returnStock(Transaction $transaction, ?User $user = null): void
    {
        $user ??= $transaction->user;
        $isReservation = in_array($transaction->status, [TransactionStatus::ON_LAYAWAY, TransactionStatus::TO_DELIVER], true);

        foreach ($transaction->items as $item) {
            if (!$itemable = $item->itemable) {
                continue;
            }

            if ($isReservation) {
                $releaseLabel = $transaction->status === TransactionStatus::ON_LAYAWAY
                    ? "Apartado cancelado #{$transaction->folio} — liberación de reserva"
                    : "Pedido cancelado #{$transaction->folio} — liberación de reserva";

                $itemable->releaseLayawayStock($transaction->branch_id, $item->quantity, $user, $releaseLabel, ['transaction_id' => $transaction->id]);
            } else {
                $itemable->restock($transaction->branch_id, $item->quantity, $user, "Venta cancelada #{$transaction->folio} — retorno de stock", ['transaction_id' => $transaction->id]);
            }
        }
    }

    /**
     * Reverses what the customer owed: the pending debt is forgiven and, when
     * the money goes back to the balance, the customer is credited.
     */
    private function reverseCustomerDebt(
        Transaction $transaction,
        float $totalPaid,
        string $action,
        ?string $refundMethod,
        string $label,
    ): void {
        if (!$transaction->customer_id) {
            return;
        }

        $customer = $transaction->customer;
        $pendingDebt = (float) $transaction->total - $totalPaid;

        if ($totalPaid <= 0) {
            // Nothing was paid: only the debt is forgiven.
            if ((float) $transaction->total > 0.01) {
                $customer->cancelDebt($transaction->total, $transaction->id, "Cancelación de {$label} #{$transaction->folio}");
            }

            return;
        }

        if ($action === 'penalty') {
            if ($pendingDebt > 0.01) {
                $customer->cancelDebt(
                    $pendingDebt,
                    $transaction->id,
                    "Cancelación de {$label} #{$transaction->folio} (Penalización). Se retienen $" . number_format($totalPaid, 2)
                );
            }

            return;
        }

        if ($refundMethod === 'balance') {
            // Full refund: money returned plus forgiven debt.
            $customer->addRefund($transaction->total, $transaction->id, "Reembolso a saldo por cancelación de {$label} #{$transaction->folio}");

            return;
        }

        // Cash or transfer: the money leaves the system, the debt is forgiven.
        if ($pendingDebt > 0.01) {
            $customer->cancelDebt(
                $pendingDebt,
                $transaction->id,
                "Cancelación de {$label} #{$transaction->folio}. Reembolso entregado por fuera."
            );
        }
    }

    /**
     * Money leaving the till (cash movement) or the bank account (negative
     * payment so the statement reconciles).
     */
    private function settleRefund(
        Transaction $transaction,
        float $totalPaid,
        string $action,
        ?string $refundMethod,
        ?int $bankAccountId,
        User $user,
        ?CashRegisterSession $activeSession,
    ): void {
        if ($action !== 'refund' || $totalPaid <= 0) {
            return;
        }

        if ($refundMethod === 'cash' && $activeSession) {
            $activeSession->cashMovements()->create([
                'user_id' => $user->id,
                'type' => SessionCashMovementType::OUTFLOW,
                'amount' => $totalPaid,
                'description' => "Devolución venta #{$transaction->folio}. Devolución de efectivo por cancelación.",
            ]);

            return;
        }

        if ($refundMethod !== 'transfer') {
            return;
        }

        $bankAccount = BankAccount::find($bankAccountId);

        if (!$bankAccount) {
            return;
        }

        $bankAccount->decrement('balance', $totalPaid);

        $transaction->payments()->create([
            'amount' => -$totalPaid,
            'payment_method' => PaymentMethod::TRANSFER->value,
            'status' => PaymentStatus::COMPLETED->value,
            'bank_account_id' => $bankAccount->id,
            'notes' => 'Reembolso por cancelación de venta.',
            // The payments table requires a date; without it the refund crashed.
            'payment_date' => now(),
        ]);
    }

    private function openSessionOfUser(User $user): ?CashRegisterSession
    {
        return $user->cashRegisterSessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->whereHas('cashRegister', fn ($query) => $query->where('branch_id', $user->branch_id))
            ->first();
    }

    private function resultMessage(string $action, ?string $refundMethod, float $totalPaid): string
    {
        if ($action === 'refund') {
            return match ($refundMethod) {
                'balance' => 'Transacción reembolsada al saldo del cliente.',
                'transfer' => 'Transacción reembolsada por transferencia bancaria.',
                default => 'Transacción reembolsada en efectivo.',
            };
        }

        return $totalPaid > 0
            ? 'Transacción cancelada con penalización (dinero retenido).'
            : 'Transacción cancelada correctamente.';
    }
}
