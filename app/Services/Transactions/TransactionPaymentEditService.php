<?php

namespace App\Services\Transactions;

use App\Enums\CustomerBalanceMovementType;
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

            // 2. Reverse the effect the previous payment left in the customer:
            //    an abono settled debt and a payment with balance consumed
            //    credit, so reconciling only the bank keeps a wrong balance.
            $this->revertCustomerBalance(
                $transaction,
                $oldMethod,
                $oldAmount,
                "Reversión por edición de pago en venta #{$transaction->folio}"
            );

            $payment->update([
                'amount' => $data['amount'],
                'payment_method' => $method,
                'bank_account_id' => $bankAccountId,
                'notes' => $data['notes'] ?? null,
            ]);

            // 3. Apply the bank effect of the new payment.
            if ($bankAccountId && $this->movesMoney($method)) {
                BankAccount::find($bankAccountId)?->increment('balance', (float) $data['amount']);
            }

            // 4. And the effect of the new payment on the customer balance.
            $this->applyCustomerBalance(
                $transaction,
                $method,
                (float) $data['amount'],
                "Pago actualizado en venta #{$transaction->folio}"
            );

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

            // 2. Customer balance: an abono took debt away (payDebt) and a
            //    payment with balance consumed credit (useBalance). Both come
            //    back with their symmetric movement, once and only once.
            $this->revertCustomerBalance(
                $transaction,
                $method,
                (float) $payment->amount,
                "Reversión por eliminación de pago en venta #{$transaction->folio}"
            );

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

    /**
     * Removes from `customers.balance` the effect the payment wrote.
     *
     * A payment of an abono settled debt with `payDebt` and one made with the
     * available balance consumed credit with `useBalance`; reverting each one
     * with its symmetric movement keeps the balance exactly as it was before
     * the payment, without duplicating the reversal.
     */
    private function revertCustomerBalance(Transaction $transaction, string $method, float $amount, string $notes): void
    {
        $customer = $transaction->customer;

        // Refund records are negative on purpose: nothing was settled with them.
        if (!$customer || $amount <= 0) {
            return;
        }

        if ($method === PaymentMethod::BALANCE->value) {
            $customer->addRefund($amount, $transaction->id, $notes);

            return;
        }

        $customer->addDebt($amount, $this->debtTypeFor($transaction), $transaction->id, $notes);
    }

    /**
     * Writes in the customer the effect the (already saved) payment would have
     * written when it was created.
     */
    private function applyCustomerBalance(Transaction $transaction, string $method, float $amount, string $notes): void
    {
        $customer = $transaction->customer;

        if (!$customer || $amount <= 0) {
            return;
        }

        if ($method === PaymentMethod::BALANCE->value) {
            $customer->useBalance($amount, $transaction->id, $notes);

            return;
        }

        $customer->payDebt($amount, $transaction->id, $notes);
    }

    /**
     * Debt type of the sale, so the reversal lands in the same bucket the sale
     * used when it posted the debt (layaway or credit sale).
     */
    private function debtTypeFor(Transaction $transaction): CustomerBalanceMovementType
    {
        return $transaction->layaway_expiration_date
            ? CustomerBalanceMovementType::LAYAWAY_DEBT
            : CustomerBalanceMovementType::CREDIT_SALE;
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
