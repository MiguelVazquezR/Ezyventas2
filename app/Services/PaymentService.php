<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\CashRegisterSession;
use App\Enums\PaymentStatus;
use App\Models\BankAccount;
use Exception;

class PaymentService
{
    /**
     * Procesa y registra un conjunto de pagos para una transacción dada.
     * Ya NO actualiza el estado de la transacción.
     *
     * @param Transaction $transaction La transacción a la que se asocian los pagos.
     * @param array $paymentsData Arreglo de datos de los pagos.
     * @param int|null $sessionId El ID de la sesión de caja activa donde se recibe el dinero.
     * @return void
     */
    public function processPayments(Transaction $transaction, array $paymentsData, ?int $sessionId): void
    {
        if ($sessionId) {
            $session = CashRegisterSession::find($sessionId);
            if (!$session || $session->status->value !== 'abierta') {
                throw new Exception('La sesión de caja no está activa. No se pueden registrar pagos.');
            }
        }

        foreach ($paymentsData as $paymentData) {
            $payment = $transaction->payments()->create([
                'amount' => (float) $paymentData['amount'],
                'payment_method' => $paymentData['method'],
                'payment_date' => now(),
                'status' => PaymentStatus::COMPLETED,
                'notes' => $paymentData['notes'] ?? null,
                'cash_register_session_id' => $sessionId,
                'bank_account_id' => $paymentData['bank_account_id'] ?? null,
            ]);

            if (in_array($paymentData['method'], ['tarjeta', 'transferencia']) && !empty($paymentData['bank_account_id'])) {
                $bankAccount = BankAccount::find($paymentData['bank_account_id']);
                if ($bankAccount) {
                    $bankAccount->increment('balance', $payment->amount);
                }
            }
        }
    }
}