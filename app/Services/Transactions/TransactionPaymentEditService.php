<?php

namespace App\Services\Transactions;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Editing and deleting a payment of a sale.
 *
 * Both operations must keep the books straight, so they reverse the previous
 * effect before applying the new one: the bank account balance, the customer
 * balance and the cash movement of the drawer.
 *
 * Shared by the web (TransactionController) and the mobile app.
 */
class TransactionPaymentEditService
{
    /**
     * Replaces the amount, method, account and notes of a payment.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Transaction $transaction, Payment $payment, array $data): Payment
    {
        $this->ensureBelongsToTransaction($transaction, $payment);

        DB::transaction(function () use ($transaction, $payment, $data) {
            $method = (string) $data['payment_method'];
            $bankAccountId = $method === PaymentMethod::CASH->value
                ? null
                : ($data['bank_account_id'] ?? null);

            $oldAmount = (float) $payment->amount;
            $oldMethod = $this->methodValue($payment);
            $oldBankAccountId = $payment->bank_account_id;

            // 1. Reverse the bank effect of the previous payment, otherwise the
            //    balance stays off (inflated or reduced).
            if ($oldBankAccountId && $this->movesMoney($oldMethod)) {
                BankAccount::find($oldBankAccountId)?->decrement('balance', $oldAmount);
            }

            $payment->update([
                'amount' => $data['amount'],
                'payment_method' => $method,
                'bank_account_id' => $bankAccountId,
                'notes' => $data['notes'] ?? null,
            ]);

            // 2. Apply the bank effect of the new payment.
            if ($bankAccountId && $this->movesMoney($method)) {
                BankAccount::find($bankAccountId)?->increment('balance', (float) $data['amount']);
            }

            $this->syncTransactionStatus($transaction);
        });

        return $payment->refresh();
    }

    /**
     * Removes the payment and reverts everything it caused.
     */
    public function delete(Transaction $transaction, Payment $payment): void
    {
        $this->ensureBelongsToTransaction($transaction, $payment);

        DB::transaction(function () use ($transaction, $payment) {
            $method = $this->methodValue($payment);

            // 1. Bank account back to its previous balance.
            if ($payment->bank_account_id) {
                BankAccount::find($payment->bank_account_id)?->decrement('balance', (float) $payment->amount);
            }

            // 2. Balance used as payment: the customer gets it back.
            if ($method === PaymentMethod::BALANCE->value && $transaction->customer_id) {
                $transaction->customer?->addRefund(
                    (float) $payment->amount,
                    $transaction->id,
                    "Reversión por eliminación de pago en venta #{$transaction->folio}"
                );
            }

            // 3. Cash of the drawer: remove the income movement of this sale.
            if ($method === PaymentMethod::CASH->value && $payment->cash_register_session_id) {
                CashRegisterSession::find($payment->cash_register_session_id)?->cashMovements()
                    ->where('amount', $payment->amount)
                    ->where('type', SessionCashMovementType::INFLOW)
                    ->where('description', 'like', "%{$transaction->folio}%")
                    ->delete();
            }

            $payment->delete();

            // 4. The sale is no longer settled: back to pending (or layaway).
            $totalPaid = (float) $transaction->payments()->sum('amount');

            if ($totalPaid < $this->transactionTotal($transaction) && $transaction->status === TransactionStatus::COMPLETED) {
                $transaction->update([
                    'status' => $transaction->layaway_expiration_date
                        ? TransactionStatus::ON_LAYAWAY
                        : TransactionStatus::PENDING,
                ]);
            }
        });
    }

    private function ensureBelongsToTransaction(Transaction $transaction, Payment $payment): void
    {
        if ((int) $payment->transaction_id !== (int) $transaction->id) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }
    }

    private function methodValue(Payment $payment): string
    {
        return $payment->payment_method instanceof PaymentMethod
            ? $payment->payment_method->value
            : (string) $payment->payment_method;
    }

    private function movesMoney(string $method): bool
    {
        return in_array($method, [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value], true);
    }

    private function transactionTotal(Transaction $transaction): float
    {
        return (float) ($transaction->total
            ?? ($transaction->subtotal - $transaction->total_discount + $transaction->total_tax));
    }

    /**
     * Completed payments decide whether the sale is settled or pending again.
     */
    private function syncTransactionStatus(Transaction $transaction): void
    {
        $totalPaid = (float) $transaction->payments()
            ->where('status', PaymentStatus::COMPLETED)
            ->sum('amount');

        if ($totalPaid >= $this->transactionTotal($transaction)) {
            if ($transaction->status !== TransactionStatus::COMPLETED) {
                $transaction->update(['status' => TransactionStatus::COMPLETED]);
            }

            return;
        }

        if ($transaction->status === TransactionStatus::COMPLETED) {
            $transaction->update([
                'status' => $transaction->layaway_expiration_date
                    ? TransactionStatus::ON_LAYAWAY
                    : TransactionStatus::PENDING,
            ]);
        }
    }
}
