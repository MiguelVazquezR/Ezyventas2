<?php

namespace App\Actions\Subscription;

use App\Actions\Referral\ApplyReferralDiscountAction;
use App\Enums\BillingPeriod;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPaymentStatus;
use App\Mail\AdminNewPaymentNotification;
use App\Models\Expense;
use App\Models\ReferralUsage;
use App\Models\Subscription;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessSubscriptionPaymentAction
{
    /**
     * Orquesta el proceso de pago, actualización de versión y generación de gastos.
     */
    public function execute(Request $request, Subscription $subscription, array $validated, $allPlanItems): void
    {
        if ($validated['payment_method'] === 'transferencia') {
            $this->handleTransferPayment($request, $subscription, $validated, $allPlanItems);
        } else {
            // Lógica futura para Stripe, tarjeta de crédito, etc.
            // Aquí podrás reutilizar $this->calculateSubscriptionDates()
        }
    }

    private function handleTransferPayment(Request $request, Subscription $subscription, array $validated, $allPlanItems): void
    {
        // --- Calcular descuento por referido ANTES de crear la versión nueva ---
        // (ApplyReferralDiscountAction verifica versions()->count() <= 1,
        //  así que debe ejecutarse antes de que se cree la nueva versión)
        $amount = (float) $validated['total_amount'];
        $referralDiscountPct = null;
        $referralDiscountAmount = null;
        $referralData = null;

        if (!empty($validated['referral_code'])) {
            try {
                $applyReferral = app(ApplyReferralDiscountAction::class);
                $referralData = $applyReferral->execute(
                    $validated['referral_code'],
                    $subscription,
                    $amount
                );
                $amount = $referralData['final_amount'];
                $referralDiscountPct = $referralData['discount_pct'];
                $referralDiscountAmount = $referralData['discount_amount'];
            } catch (\Exception $e) {
                Log::warning("Código de referido no aplicado: " . $e->getMessage());
            }
        }

        // Aplicar descuento continuo por ser referidor (acumulativo: 10% por cada referido activo)
        $referrerActivePct = $subscription->referrer_discount_active
            ? $subscription->getReferrerActiveDiscountPct()
            : 0;

        if ($referrerActivePct > 0) {
            $discountFromReferrer = round($amount * ($referrerActivePct / 100), 2);
            $amount -= $discountFromReferrer;
            // Agregamos el descuento de referidor al referral_discount si ya existía
            if ($referralDiscountPct) {
                $referralDiscountPct = $referralDiscountPct + $referrerActivePct;
                $referralDiscountAmount = round($referralDiscountAmount + $discountFromReferrer, 2);
            } else {
                $referralDiscountPct = $referrerActivePct;
                $referralDiscountAmount = $discountFromReferrer;
            }
        }

        DB::transaction(function () use ($request, $subscription, $validated, $allPlanItems, $amount, $referralDiscountPct, $referralDiscountAmount, $referralData) {
            $billingPeriod = BillingPeriod::from($validated['billing_period']);
            $mode = $validated['mode'];
            $user = $request->user();

            // 1. Resolver Versión Base (detectar reintentos)
            $latestVersion = $subscription->versions()->latest('id')->first();
            $baseVersion = $latestVersion;

            if ($latestVersion) {
                $lastPayment = $latestVersion->payments()->latest('id')->first();
                if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
                    $baseVersion = $subscription->versions()->where('id', '!=', $latestVersion->id)->latest('id')->first();
                }
            }

            // 2. Calcular Fechas Estimadas (Aplicando la regla estricta de días y prorrateos)
            [$startDate, $endDate] = $this->calculateSubscriptionDates($baseVersion, $mode, $billingPeriod);

            // 3. Crear o Reutilizar Versión Nueva
            $newVersion = $subscription->versions()
                ->whereHas('payments', fn($q) => $q->where('status', SubscriptionPaymentStatus::REJECTED))
                ->whereDoesntHave('payments', fn($q) => $q->whereIn('status', [SubscriptionPaymentStatus::APPROVED, SubscriptionPaymentStatus::PENDING]))
                ->latest('id')
                ->first();

            if (!$newVersion) {
                $newVersion = $subscription->versions()->create(['start_date' => $startDate, 'end_date' => $endDate]);
            } else {
                $newVersion->update(['start_date' => $startDate, 'end_date' => $endDate]);
                $newVersion->items()->delete();
            }

            // NUEVO: 3.5. Finalizar anticipadamente la versión anterior si es una mejora (Upgrade)
            // Cortamos su fecha de finalización al día de hoy para que la nueva versión tome el control.
            if ($mode === 'upgrade' && $baseVersion && $baseVersion->id !== $newVersion->id) {
                if (Carbon::parse($baseVersion->end_date)->isFuture()) {
                    $baseVersion->update(['end_date' => clone $startDate]);
                }
            }

            // 4. Insertar Items
            $subscriptionItems = [];
            foreach ($validated['items'] as $item) {
                $planItem = $allPlanItems->get($item['key']);
                if (!$planItem) continue;

                $subscriptionItems[] = [
                    'subscription_version_id' => $newVersion->id,
                    'item_key' => $planItem->key,
                    'item_type' => $planItem->type,
                    'name' => $planItem->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $billingPeriod === BillingPeriod::ANNUALLY ? $planItem->monthly_price * 10 : $planItem->monthly_price,
                    'billing_period' => $billingPeriod,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('subscription_items')->insert($subscriptionItems);

            // 6. Crear el Pago y adjuntar comprobante
            $paymentDetails = [];
            if ($mode === 'upgrade') {
                $paymentDetails['is_upgrade'] = true;
                $paymentDetails['original_end_date'] = $endDate->toIso8601String();
            }

            $payment = $newVersion->payments()->create([
                'amount' => $amount,
                'payment_method' => $validated['payment_method'],
                'status' => SubscriptionPaymentStatus::PENDING,
                'invoice_status' => InvoiceStatus::NOT_REQUESTED,
                'payment_details' => $paymentDetails,
                'referral_discount_pct' => $referralDiscountPct,
                'referral_discount_amount' => $referralDiscountAmount,
            ]);

            if ($request->hasFile('proof_of_payment')) {
                $payment->addMediaFromRequest('proof_of_payment')->toMediaCollection('proof_of_payment');
            }

            // 6.5. Registrar ReferralUsage si se aplicó código de referido
            if ($referralData) {
                $settings = $referralData['settings'];
                $referralCode = $referralData['referral_code'];

                // Calcular mensualidad base (sin descuento) para el premio
                // Se calcula desde los monthly_price de los plan items, sin ajuste por periodo
                $monthlyBase = $this->calculateMonthlyBase($validated['items'], $allPlanItems);

                ReferralUsage::create([
                    'referral_code_id' => $referralCode->id,
                    'referred_subscription_id' => $subscription->id,
                    'subscription_payment_id' => $payment->id,
                    'reward_status' => 'pending',
                    'referred_discount_pct' => $referralDiscountPct,
                    'referrer_reward_pct' => $settings->referrer_reward_pct,
                    'referrer_ongoing_discount_pct' => $settings->referrer_ongoing_discount_pct,
                    'monthly_base_amount' => $monthlyBase,
                    'reward_amount' => round($monthlyBase * ((float) $settings->referrer_reward_pct / 100), 2),
                ]);
            }

            // 7. Generar Gasto Opcional
            if (!empty($validated['bank_account_id']) && !empty($validated['expense_category_id'])) {
                Expense::create([
                    'folio' => $mode === 'upgrade' ? 'Pago de mejora de suscripción EzyVentas' : 'Pago de renovación de suscripción EzyVentas',
                    'user_id' => $user->id,
                    'branch_id' => $user->branch_id,
                    'amount' => $amount,
                    'expense_category_id' => $validated['expense_category_id'],
                    'expense_date' => now(),
                    'status' => ExpenseStatus::PENDING,
                    'description' => 'Pago de suscripción ' . config('app.name'),
                    'payment_method' => PaymentMethod::TRANSFER,
                    'bank_account_id' => $validated['bank_account_id'],
                ]);
            }

            // 8. Notificar al Admin
            try {
                $adminUser = User::whereHas('branch', fn($q) => $q->where('subscription_id', 1))->select('email')->first();
                if ($adminUser && app()->environment('production')) {
                    Mail::to($adminUser->email)->send(new AdminNewPaymentNotification(
                        $subscription->commercial_name, 
                        (float) $payment->amount, 
                        route('admin.payments.show', $payment->id)
                    ));
                }
            } catch (\Exception $e) {
                Log::error("Fallo al enviar correo de notificación de pago: " . $e->getMessage());
            }
        });
    }

    /**
     * Calcula la mensualidad base a partir de los validated items usando
     * los monthly_price de los plan items, sin ajuste por billing_period.
     * Respeta el meta->quantity de cada item (precio por paquete de X unidades).
     * Esta es la "mensualidad normal" sobre la cual se calcula el premio al referidor.
     */
    private function calculateMonthlyBase(array $validatedItems, $allPlanItems): float
    {
        $total = 0;
        foreach ($validatedItems as $item) {
            $planItem = $allPlanItems->get($item['key']);
            if (!$planItem) continue;

            $packageSize = (int) (($planItem->meta['quantity'] ?? 1));
            $pricePerUnit = (float) $planItem->monthly_price / max($packageSize, 1);

            $total += $pricePerUnit * (int) $item['quantity'];
        }

        return round($total, 2);
    }

    /**
     * Calcula las fechas de la suscripción basado en las reglas de negocio estrictas.
     */
    private function calculateSubscriptionDates(?SubscriptionVersion $baseVersion, string $mode, BillingPeriod $billingPeriod): array
    {
        $now = now();
        $baseEndDate = $baseVersion ? Carbon::parse($baseVersion->end_date) : clone $now;
        $isExpired = $baseEndDate->isPast();

        // Regla: 30 días mensuales exactos o 365 anuales
        $daysToAdd = $billingPeriod === BillingPeriod::ANNUALLY ? 365 : 30;

        if ($isExpired) {
            // CASO 1: Expirada. 
            // Inicia hoy. Se suman los días desde HOY (no pierde días por haber estado inactiva)
            $startDate = clone $now;
            $endDate = $startDate->copy()->addDays($daysToAdd);
        } else {
            // CASO 2: Aún Activa. 
            if ($mode === 'upgrade') {
                // Mejora (Upgrade): Inicia HOY y TERMINA exactamente cuando iba a terminar la versión original.
                // Ya que el cliente solo está pagando el prorrateo por los días restantes.
                $startDate = clone $now;
                $endDate = clone $baseEndDate;
            } else {
                // Renovación (Renew) Temprana: Inicia justo cuando termine la versión actual.
                $startDate = clone $baseEndDate;
                // La fecha final se empuja desde su vencimiento original + 30/365.
                $endDate = $baseEndDate->copy()->addDays($daysToAdd);
            }
        }

        return [$startDate, $endDate];
    }
}