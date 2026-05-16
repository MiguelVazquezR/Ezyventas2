<?php

namespace App\Actions\Subscription;

use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Mail\PaymentStatusNotification;
use App\Models\Expense;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RejectSubscriptionPaymentAction
{
    /**
     * Rechaza un pago de suscripción, elimina gastos pendientes y
     * restaura la fecha de finalización si se trataba de un Upgrade fallido.
     */
    public function execute(SubscriptionPayment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $subscription = $payment->subscriptionVersion->subscription;
            $details = $payment->payment_details ?? [];

            // 1. Marcar el pago como rechazado y guardar el motivo
            $payment->update([
                'status' => SubscriptionPaymentStatus::REJECTED,
                'payment_details' => array_merge($details, ['rejection_reason' => $reason])
            ]);

            // 2. Restaurar la fecha original si fue un Upgrade
            // (Para que el usuario no pierda el acceso al plan que ya tenía pagado)
            if (!empty($details['is_upgrade']) && !empty($details['original_end_date'])) {
                $previousVersion = $subscription->versions()
                    ->where('id', '!=', $payment->subscription_version_id)
                    ->latest('id')
                    ->first();

                if ($previousVersion) {
                    $previousVersion->update([
                        'end_date' => Carbon::parse($details['original_end_date'])
                    ]);
                }
            }

            // 3. Eliminar el gasto pendiente asociado
            $pendingExpense = Expense::where('status', ExpenseStatus::PENDING)
                ->where('amount', $payment->amount)
                ->where('description', 'like', 'Pago de suscripción%')
                ->whereHas('branch.subscription', fn($q) => $q->where('id', $subscription->id))
                ->latest('created_at')
                ->first();

            if ($pendingExpense) {
                $pendingExpense->delete();
            }
        });

        // 4. Notificar al cliente
        try {
            $subscription = $payment->subscriptionVersion->subscription;
            $subscriptionEmail = $subscription->contact_email; 

            if ($subscriptionEmail && app()->environment('production')) {
                Mail::to($subscriptionEmail)->send(new PaymentStatusNotification(
                    $payment,
                    'rejected',
                    $subscription->commercial_name,
                    $reason
                ));
            }
        } catch (\Exception $e) {
            Log::error("Fallo al enviar correo de rechazo al cliente: " . $e->getMessage());
        }
    }
}