<?php

namespace App\Actions\Subscription;

use App\Actions\Referral\GenerateReferralCodeAction;
use App\Actions\Referral\ProcessReferralOnPaymentApprovedAction;
use App\Actions\Referral\UpdateReferrerOngoingDiscountAction;
use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\PaymentStatusNotification;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ApproveSubscriptionPaymentAction
{
    /**
     * Aprueba un pago de suscripción, activa el servicio y liquida los gastos.
     * NOTA: No altera las fechas, respeta las que el sistema calculó 
     * el día que el cliente solicitó la renovación/mejora.
     */
    public function execute(SubscriptionPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $version = $payment->subscriptionVersion;
            $subscription = $version->subscription;

            // 1. Actualizar el estado del pago y limpiar detalles temporales
            $payment->update([
                'status' => SubscriptionPaymentStatus::APPROVED,
                'payment_details' => null,
            ]);

            // 2. Activar la suscripción a nivel global
            $subscription->update([
                'status' => SubscriptionStatus::ACTIVE,
            ]);

            // 3. Buscar y liquidar el gasto contable asociado (si existe)
            $expense = Expense::where('status', ExpenseStatus::PENDING)
                ->where('amount', $payment->amount)
                ->where('description', 'like', 'Pago de suscripción%')
                ->whereHas('branch.subscription', fn($q) => $q->where('id', $subscription->id))
                ->latest('created_at')
                ->first();

            if ($expense) {
                $expense->update(['status' => ExpenseStatus::PAID]);

                if ($expense->bank_account_id) {
                    $bankAccount = BankAccount::lockForUpdate()->find($expense->bank_account_id);
                    if ($bankAccount) {
                        $bankAccount->decrement('balance', $expense->amount);
                    }
                }
            }
        });

        // 4. Procesar sistema de referidos (descuentos y premios)
        $subscription = $payment->subscriptionVersion->subscription;

        try {
            // Activar descuento continuo del referidor si este pago tiene referido
            app(ProcessReferralOnPaymentApprovedAction::class)->execute($payment);

            // Si este pago es una renovación de un referido, actualizar descuento del referidor
            $referredUsage = $subscription->referralUsageAsReferred;
            if ($referredUsage) {
                app(UpdateReferrerOngoingDiscountAction::class)->execute($subscription);
            }
        } catch (\Exception $e) {
            Log::error("Error procesando referidos en aprobación: " . $e->getMessage());
        }

        // 5. Generar código de referido para la suscripción activada
        try {
            app(GenerateReferralCodeAction::class)->execute($subscription);
        } catch (\Exception $e) {
            Log::error("Error generando código de referido: " . $e->getMessage());
        }

        // 6. Notificar al cliente
        try {
            $subscription = $payment->subscriptionVersion->subscription;
            $subscriptionEmail = $subscription->contact_email; 

            if ($subscriptionEmail && app()->environment('production')) {
                Mail::to($subscriptionEmail)->send(new PaymentStatusNotification(
                    $payment,
                    'approved',
                    $subscription->commercial_name,
                ));
            }
        } catch (\Exception $e) {
            Log::error("Fallo al enviar correo de aprobación al cliente: " . $e->getMessage());
        }
    }
}