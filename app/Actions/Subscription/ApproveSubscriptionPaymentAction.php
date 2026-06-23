<?php

namespace App\Actions\Subscription;

use App\Actions\Referral\GenerateReferralCodeAction;
use App\Actions\Referral\ProcessReferralOnPaymentApprovedAction;
use App\Actions\Referral\UpdateReferrerOngoingDiscountAction;
use App\Enums\BillingPeriod;
use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\PaymentStatusNotification;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\PlanItem;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use Illuminate\Support\Carbon;
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
            // 0. Si el pago no tiene versión (MercadoPago), crearla ahora
            $version = $payment->subscriptionVersion;
            if (!$version) {
                $version = $this->createVersionFromPaymentDetails($payment);
                $payment->update(['subscription_version_id' => $version->id]);
            }

            $subscription = $version->subscription;

            // 1. Actualizar el estado del pago (preservar datos de MP en payment_details)
            $currentDetails = $payment->payment_details ?? [];
            $currentDetails['approved_at'] = now()->toIso8601String();
            $payment->update([
                'status'          => SubscriptionPaymentStatus::APPROVED,
                'payment_details' => $currentDetails,
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

        // 5. Generar código de referido para el dueño de la suscripción activada
        try {
            $owner = $subscription->users()->whereDoesntHave('roles')->first();
            if ($owner) {
                app(GenerateReferralCodeAction::class)->execute($owner);
            }
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

    /**
     * Crea la SubscriptionVersion y sus items a partir de los datos guardados
     * en payment_details. Solo para pagos de MercadoPago donde la versión
     * se difiere hasta la confirmación del pago.
     */
    private function createVersionFromPaymentDetails(SubscriptionPayment $payment): SubscriptionVersion
    {
        $details = $payment->payment_details ?? [];
        $mode = $details['mode'] ?? 'renew';
        $billingPeriod = BillingPeriod::from($details['billing_period'] ?? 'anual');
        $items = $details['items'] ?? [];
        $startDate = Carbon::parse($details['start_date']);
        $endDate = Carbon::parse($details['end_date']);
        $baseVersionId = $details['base_version_id'] ?? null;

        // Obtener la suscripción desde la versión base almacenada en payment_details
        $baseVersion = $baseVersionId ? SubscriptionVersion::find($baseVersionId) : null;
        $subscription = $baseVersion?->subscription;

        if (!$subscription) {
            throw new \RuntimeException('No se pudo determinar la suscripción para crear la versión.');
        }

        // Resolver versión base
        $latestVersion = $subscription->versions()->latest('id')->first();
        $baseVersion = $latestVersion;

        if ($latestVersion) {
            $lastPayment = $latestVersion->payments()->latest('id')->first();
            if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
                $baseVersion = $subscription->versions()->where('id', '!=', $latestVersion->id)->latest('id')->first();
            }
        }

        // Reutilizar versión rechazada o crear nueva
        $newVersion = $subscription->versions()
            ->whereHas('payments', fn($q) => $q->where('status', SubscriptionPaymentStatus::REJECTED))
            ->whereDoesntHave('payments', fn($q) => $q->whereIn('status', [SubscriptionPaymentStatus::APPROVED, SubscriptionPaymentStatus::PENDING]))
            ->latest('id')
            ->first();

        if (!$newVersion) {
            $newVersion = $subscription->versions()->create([
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);
        } else {
            $newVersion->update([
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);
            $newVersion->items()->delete();
        }

        // Finalizar versión anterior si es upgrade
        if ($mode === 'upgrade' && $baseVersion && $baseVersion->id !== $newVersion->id) {
            if (Carbon::parse($baseVersion->end_date)->isFuture()) {
                $baseVersion->update(['end_date' => clone $startDate]);
            }
        }

        // Insertar items
        $allPlanItems = PlanItem::where('is_active', true)->get()->keyBy('key');
        $subscriptionItems = [];

        foreach ($items as $item) {
            $planItem = $allPlanItems->get($item['key']);
            if (!$planItem) continue;

            $subscriptionItems[] = [
                'subscription_version_id' => $newVersion->id,
                'item_key'                => $planItem->key,
                'item_type'               => $planItem->type,
                'name'                    => $planItem->name,
                'quantity'                => $item['quantity'],
                'unit_price'              => $billingPeriod === BillingPeriod::ANNUALLY ? $planItem->monthly_price * 10 : $planItem->monthly_price,
                'billing_period'          => $billingPeriod,
                'created_at'              => now(),
                'updated_at'              => now(),
            ];
        }

        DB::table('subscription_items')->insert($subscriptionItems);

        return $newVersion;
    }
}