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
use App\Models\SubscriptionPayment;
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
     * Devuelve el SubscriptionPayment creado para que el controller pueda redirigir a MP si es necesario.
     */
    public function execute(Request $request, Subscription $subscription, array $validated, $allPlanItems): SubscriptionPayment
    {
        if ($validated['payment_method'] === 'transferencia') {
            return $this->handleTransferPayment($request, $subscription, $validated, $allPlanItems);
        }

        if ($validated['payment_method'] === 'mercadopago') {
            return $this->handleMercadoPagoPayment($request, $subscription, $validated, $allPlanItems);
        }

        throw new \InvalidArgumentException('Método de pago no soportado.');
    }

    /**
     * Calcula descuentos de referido y referidor.
     * Devuelve el monto final, los porcentajes y los datos de referido.
     */
    private function calculateDiscounts(Subscription $subscription, array $validated): array
    {
        $amount = (float) $validated['total_amount'];
        $referralDiscountPct = null;
        $referralDiscountAmount = null;
        $referralData = $this->resolveReferralData($subscription, $validated, $amount);

        if ($referralData) {
            $amount = $referralData['final_amount'];
            $referralDiscountPct = $referralData['discount_pct'];
            $referralDiscountAmount = $referralData['discount_amount'];
        }

        $referrerActivePct = $validated['billing_period'] === 'mensual'
            ? $subscription->getReferrerActiveDiscountPct()
            : 0;

        if ($referrerActivePct > 0) {
            $discountFromReferrer = round($amount * ($referrerActivePct / 100), 2);
            $amount -= $discountFromReferrer;
            if ($referralDiscountPct) {
                $referralDiscountPct = $referralDiscountPct + $referrerActivePct;
                $referralDiscountAmount = round($referralDiscountAmount + $discountFromReferrer, 2);
            } else {
                $referralDiscountPct = $referrerActivePct;
                $referralDiscountAmount = $discountFromReferrer;
            }
        }

        return [
            'amount'                  => $amount,
            'referralDiscountPct'     => $referralDiscountPct,
            'referralDiscountAmount'  => $referralDiscountAmount,
            'referralData'            => $referralData,
        ];
    }

    /**
     * Determina qué código de referido aplica a este pago:
     *   1) El cupón guardado automáticamente durante el registro (estado
     *      'trial'/'expired', sin pago aún) -> se aplica sin pedirlo de nuevo.
     *   2) El código escrito manualmente en la pantalla de pago (comportamiento
     *      original para quien no lo capturó al registrarse).
     *
     * @return array{referral_code: ReferralCode, discount_pct: float, discount_amount: float, final_amount: float, settings: ?ReferralSettings, referral_usage: ?ReferralUsage}|null
     */
    private function resolveReferralData(Subscription $subscription, array $validated, float $amount): ?array
    {
        // 1) Cupón guardado en el registro (el suscriptor ya viene referido).
        $pending = $subscription->pendingRegistrationReferral();

        if ($pending && $pending->referralCode) {
            $discountPct = (float) ($pending->referred_discount_pct ?? 0);
            $discountAmount = round($amount * ($discountPct / 100), 2);

            return [
                'referral_code'    => $pending->referralCode,
                'discount_pct'     => $discountPct,
                'discount_amount'  => $discountAmount,
                'final_amount'     => round($amount - $discountAmount, 2),
                'settings'         => null,
                'referral_usage'   => $pending,
            ];
        }

        // 2) Código escrito a mano (no había cupón guardado en el registro).
        if (!empty($validated['referral_code'])) {
            try {
                $referralData = app(ApplyReferralDiscountAction::class)->execute(
                    $validated['referral_code'],
                    $subscription,
                    $amount
                );

                return $referralData + ['referral_usage' => null];
            } catch (\Exception $e) {
                Log::warning("Código de referido no aplicado: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Crea (o reutiliza) la SubscriptionVersion, inserta los items y registra ReferralUsage.
     * Devuelve [newVersion, startDate, endDate].
     */
    private function createVersionWithItems(
        Subscription $subscription,
        array $validated,
        $allPlanItems,
        BillingPeriod $billingPeriod
    ): array {
        $mode = $validated['mode'];

        // 1. Resolver Versión Base
        $latestVersion = $subscription->versions()->latest('id')->first();
        $baseVersion = $latestVersion;

        if ($latestVersion) {
            $lastPayment = $latestVersion->payments()->latest('id')->first();
            if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
                $baseVersion = $subscription->versions()->where('id', '!=', $latestVersion->id)->latest('id')->first();
            }
        }

        // 2. Calcular Fechas
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

        // 3.5 Finalizar versión anterior si es upgrade
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
                'item_key'               => $planItem->key,
                'item_type'              => $planItem->type,
                'name'                   => $planItem->name,
                'quantity'               => $item['quantity'],
                'unit_price'             => $billingPeriod === BillingPeriod::ANNUALLY ? $planItem->monthly_price * 10 : $planItem->monthly_price,
                'billing_period'         => $billingPeriod,
                'created_at'             => now(),
                'updated_at'             => now(),
            ];
        }
        DB::table('subscription_items')->insert($subscriptionItems);

        return [$newVersion, $startDate, $endDate];
    }

    /**
     * Crea el pago y registra ReferralUsage si aplica.
     */
    private function createPaymentAndReferral(
        SubscriptionVersion $newVersion,
        Subscription $subscription,
        array $validated,
        $allPlanItems,
        float $amount,
        ?float $referralDiscountPct,
        ?float $referralDiscountAmount,
        ?array $referralData,
        string $paymentMethod,
        Carbon $endDate
    ): SubscriptionPayment {
        $mode = $validated['mode'];

        $paymentDetails = [];
        if ($mode === 'upgrade') {
            $paymentDetails['is_upgrade'] = true;
            $paymentDetails['original_end_date'] = $endDate->toIso8601String();
        }

        $payment = $newVersion->payments()->create([
            'amount'                  => $amount,
            'payment_method'          => $paymentMethod,
            'status'                  => SubscriptionPaymentStatus::PENDING,
            'invoice_status'          => InvoiceStatus::NOT_REQUESTED,
            'payment_details'         => $paymentDetails,
            'referral_discount_pct'   => $referralDiscountPct,
            'referral_discount_amount'=> $referralDiscountAmount,
        ]);

        // Registrar / confirmar ReferralUsage (crea si es nuevo, o convierte el
        // referido guardado en el registro de 'trial'/'expired' a 'pending').
        $this->persistReferralUsage($payment, $subscription, $validated, $allPlanItems, $referralData, $referralDiscountPct);

        return $payment;
    }

    /**
     * Registra o confirma el ReferralUsage vinculado a este pago.
     * - Si el suscriptor traía un cupón guardado en el registro (estado
     *   'trial'/'expired'), actualiza esa misma fila: la liga al pago y la pasa
     *   a 'pending' (el premio del referidor queda pendiente de aprobación).
     * - Si el código se escribió en la pantalla de pago, crea la fila como antes.
     */
    private function persistReferralUsage(
        SubscriptionPayment $payment,
        Subscription $subscription,
        array $validated,
        $allPlanItems,
        ?array $referralData,
        ?float $referralDiscountPct
    ): void {
        if (!$referralData) {
            return;
        }

        $referralCode = $referralData['referral_code'];
        $pendingUsage = $referralData['referral_usage'] ?? null;
        $settings = $referralData['settings'];

        $referrerRewardPct = $pendingUsage
            ? (float) $pendingUsage->referrer_reward_pct
            : (float) $settings->referrer_reward_pct;

        $referrerOngoingPct = $pendingUsage
            ? (float) $pendingUsage->referrer_ongoing_discount_pct
            : (float) $settings->referrer_ongoing_discount_pct;

        $monthlyBase = $this->calculateMonthlyBase($validated['items'], $allPlanItems);
        $rewardAmount = round($monthlyBase * ($referrerRewardPct / 100), 2);
        $referredDiscountPct = $referralData['discount_pct'] ?? $referralDiscountPct;

        if ($pendingUsage) {
            $pendingUsage->update([
                'subscription_payment_id'    => $payment->id,
                'reward_status'              => ReferralUsage::STATUS_PENDING,
                'referred_discount_pct'      => $referredDiscountPct,
                'referrer_reward_pct'        => $referrerRewardPct,
                'referrer_ongoing_discount_pct' => $referrerOngoingPct,
                'monthly_base_amount'        => $monthlyBase,
                'reward_amount'              => $rewardAmount,
            ]);

            return;
        }

        ReferralUsage::create([
            'referral_code_id'            => $referralCode->id,
            'referred_subscription_id'    => $subscription->id,
            'subscription_payment_id'     => $payment->id,
            'reward_status'               => ReferralUsage::STATUS_PENDING,
            'referred_discount_pct'       => $referredDiscountPct,
            'referrer_reward_pct'         => $referrerRewardPct,
            'referrer_ongoing_discount_pct' => $referrerOngoingPct,
            'monthly_base_amount'         => $monthlyBase,
            'reward_amount'               => $rewardAmount,
        ]);
    }

    private function handleTransferPayment(Request $request, Subscription $subscription, array $validated, $allPlanItems): SubscriptionPayment
    {
        $discounts = $this->calculateDiscounts($subscription, $validated);
        $amount = $discounts['amount'];
        $referralDiscountPct = $discounts['referralDiscountPct'];
        $referralDiscountAmount = $discounts['referralDiscountAmount'];
        $referralData = $discounts['referralData'];

        $payment = DB::transaction(function () use ($request, $subscription, $validated, $allPlanItems, $amount, $referralDiscountPct, $referralDiscountAmount, $referralData) {
            $billingPeriod = BillingPeriod::from($validated['billing_period']);
            $user = $request->user();

            [$newVersion, $startDate, $endDate] = $this->createVersionWithItems($subscription, $validated, $allPlanItems, $billingPeriod);

            $payment = $this->createPaymentAndReferral(
                $newVersion, $subscription, $validated, $allPlanItems,
                $amount, $referralDiscountPct, $referralDiscountAmount, $referralData,
                'transferencia', $endDate
            );

            // Adjuntar comprobante
            if ($request->hasFile('proof_of_payment')) {
                $payment->addMediaFromRequest('proof_of_payment')->toMediaCollection('proof_of_payment');
            }

            // Generar Gasto Opcional
            if (!empty($validated['bank_account_id']) && !empty($validated['expense_category_id'])) {
                Expense::create([
                    'folio'              => $validated['mode'] === 'upgrade' ? 'Pago de mejora de suscripción EzyVentas' : 'Pago de renovación de suscripción EzyVentas',
                    'user_id'            => $user->id,
                    'branch_id'          => $user->branch_id,
                    'amount'             => $amount,
                    'expense_category_id'=> $validated['expense_category_id'],
                    'expense_date'       => now(),
                    'status'             => ExpenseStatus::PENDING,
                    'description'        => 'Pago de suscripción ' . config('app.name'),
                    'payment_method'     => PaymentMethod::TRANSFER,
                    'bank_account_id'    => $validated['bank_account_id'],
                ]);
            }

            // Notificar al Admin
            $this->notifyAdmin($subscription, $payment);

            return $payment;
        });

        return $payment;
    }

    private function handleMercadoPagoPayment(Request $request, Subscription $subscription, array $validated, $allPlanItems): SubscriptionPayment
    {
        $discounts = $this->calculateDiscounts($subscription, $validated);
        $amount = $discounts['amount'];
        $referralDiscountPct = $discounts['referralDiscountPct'];
        $referralDiscountAmount = $discounts['referralDiscountAmount'];
        $referralData = $discounts['referralData'];

        // Calcular fechas pero NO crear versión aún — se crea al aprobarse el pago en MP.
        $billingPeriod = BillingPeriod::from($validated['billing_period']);
        $mode = $validated['mode'];
        $baseVersion = $this->resolveBaseVersion($subscription);
        [$startDate, $endDate] = $this->calculateSubscriptionDates($baseVersion, $mode, $billingPeriod);

        $payment = DB::transaction(function () use ($subscription, $validated, $allPlanItems, $amount, $referralDiscountPct, $referralDiscountAmount, $referralData, $billingPeriod, $mode, $startDate, $endDate, $baseVersion) {
            // Crear solo el registro de pago (sin versión).
            // La versión y sus items se crearán en ApproveSubscriptionPaymentAction al confirmar MP.
            $paymentDetails = [
                'mode'               => $mode,
                'billing_period'     => $billingPeriod->value,
                'items'              => $validated['items'],
                'referral_code'      => $validated['referral_code'] ?? null,
                'referral_data'      => $referralData,
                'start_date'         => $startDate->toIso8601String(),
                'end_date'           => $endDate->toIso8601String(),
                'original_amount'    => (float) $validated['total_amount'],
                'base_version_id'    => $baseVersion?->id,
            ];

            if ($mode === 'upgrade') {
                $paymentDetails['is_upgrade'] = true;
            }

            $payment = SubscriptionPayment::create([
                'subscription_version_id'   => null, // Se asigna al aprobar
                'amount'                    => $amount,
                'payment_method'            => 'mercadopago',
                'status'                    => SubscriptionPaymentStatus::PENDING,
                'invoice_status'            => InvoiceStatus::NOT_REQUESTED,
                'payment_details'           => $paymentDetails,
                'referral_discount_pct'     => $referralDiscountPct,
                'referral_discount_amount'  => $referralDiscountAmount,
            ]);

            // Registrar / confirmar ReferralUsage igual que en transferencia.
            $this->persistReferralUsage($payment, $subscription, $validated, $allPlanItems, $referralData, $referralDiscountPct);

            return $payment;
        });

        return $payment;
    }

    /**
     * Resuelve la versión base para calcular fechas, sin crearla.
     */
    private function resolveBaseVersion(Subscription $subscription): ?\App\Models\SubscriptionVersion
    {
        $latestVersion = $subscription->versions()->latest('id')->first();

        if ($latestVersion) {
            $lastPayment = $latestVersion->payments()->latest('id')->first();
            if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
                return $subscription->versions()->where('id', '!=', $latestVersion->id)->latest('id')->first();
            }
        }

        return $latestVersion;
    }

    private function notifyAdmin(Subscription $subscription, SubscriptionPayment $payment): void
    {
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