<?php

namespace App\Services;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use Illuminate\Support\Carbon;

/**
 * Builds a billing snapshot of a subscription: contracted plan, expiration
 * date, pending amounts and recent payment history.
 *
 * All data is derived from the subscription instance server-side, so callers
 * never pass tenant identifiers.
 */
class SubscriptionStatusService
{
    private const HISTORY_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Subscription $subscription): array
    {
        $version = $this->latestVersion($subscription);
        $breakdown = $this->planBreakdown($version);
        $expiration = $this->expirationData($version);

        return [
            'subscription_id' => $subscription->id,
            'business_name' => $subscription->business_name,
            'status' => $this->statusFromExpiration($version, $expiration),
            'plan' => [
                'billing_period' => $breakdown['billing_period']?->value,
                'monthly_cost' => $this->monthlyCost($breakdown),
                'modules' => collect($breakdown['items'])
                    ->where('type', PlanItemType::MODULE->value)
                    ->pluck('name')
                    ->values()
                    ->all(),
                'items' => $breakdown['items'],
            ],
            'expiration' => $expiration,
            'pending_payment' => $this->pendingPaymentData($subscription),
            'last_rejected_payment' => $this->rejectedPaymentData($subscription),
            'renewal_estimate' => $this->renewalData($subscription, $breakdown),
            'payment_history' => $this->paymentHistory($subscription),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function latestVersion(Subscription $subscription): ?SubscriptionVersion
    {
        return $subscription->versions()->with('items')->latest('id')->first();
    }

    /**
     * Flatten the version items into billable rows plus their period subtotal.
     *
     * @return array{billing_period: ?BillingPeriod, items: array<int, array<string, mixed>>, subtotal: float}
     */
    private function planBreakdown(?SubscriptionVersion $version): array
    {
        if (! $version) {
            return ['billing_period' => null, 'items' => [], 'subtotal' => 0.0];
        }

        $planItems = PlanItem::whereIn('key', $version->items->pluck('item_key'))
            ->get()
            ->keyBy('key');

        $items = [];
        $subtotal = 0.0;

        foreach ($version->items as $item) {
            $packageSize = max((int) data_get($planItems->get($item->item_key), 'meta.quantity', 1), 1);
            $pricePerUnit = (float) $item->unit_price / $packageSize;
            $lineTotal = $pricePerUnit * (int) $item->quantity;

            $items[] = [
                'key' => $item->item_key,
                'name' => $item->name,
                'type' => $item->item_type,
                'quantity' => (int) $item->quantity,
                'package_size' => $packageSize,
                'price_per_unit' => round($pricePerUnit, 4),
                'subtotal' => round($lineTotal, 2),
            ];

            $subtotal += $lineTotal;
        }

        return [
            'billing_period' => $version->items->first()?->billing_period ?? BillingPeriod::ANNUALLY,
            'items' => $items,
            'subtotal' => round($subtotal, 2),
        ];
    }

    /**
     * @param  array{billing_period: ?BillingPeriod, items: array<int, array<string, mixed>>, subtotal: float}  $breakdown
     */
    private function monthlyCost(array $breakdown): float
    {
        $months = $breakdown['billing_period'] === BillingPeriod::ANNUALLY ? 12 : 1;

        return round($breakdown['subtotal'] / $months, 2);
    }

    /**
     * Expiration data mirrors Subscription::getWarningData(): the subscription
     * is still valid on its end date and expires the day after.
     *
     * @return array<string, mixed>
     */
    private function expirationData(?SubscriptionVersion $version): array
    {
        if (! $version) {
            return [
                'end_date' => null,
                'days_remaining' => null,
                'is_expired' => true,
                'expires_today' => false,
                'message' => 'No hay una versión de suscripción registrada.',
            ];
        }

        $endDate = Carbon::parse($version->end_date)->startOfDay();
        $daysRemaining = (int) now()->startOfDay()->diffInDays($endDate, false);
        $formattedDate = $endDate->translatedFormat('d \d\e F \d\e Y');

        $message = match (true) {
            $daysRemaining < 0 => "La suscripción expiró el {$formattedDate}.",
            $daysRemaining === 0 => "La suscripción vence hoy ({$formattedDate}).",
            $daysRemaining <= 5 => 'La suscripción vence en ' . $daysRemaining . ($daysRemaining === 1 ? ' día' : ' días') . " ({$formattedDate}).",
            default => "La suscripción está vigente hasta el {$formattedDate}.",
        };

        return [
            'end_date' => $endDate->toDateString(),
            'days_remaining' => $daysRemaining,
            'is_expired' => $daysRemaining < 0,
            'expires_today' => $daysRemaining === 0,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $expiration
     */
    private function statusFromExpiration(?SubscriptionVersion $version, array $expiration): string
    {
        if (! $version) {
            return 'suspendido';
        }

        return $expiration['is_expired'] ? 'expirado' : 'activo';
    }

    /**
     * Pending transfer payments awaiting admin approval (mirrors the customer UI).
     *
     * @return array<string, mixed>|null
     */
    private function pendingPaymentData(Subscription $subscription): ?array
    {
        $payment = $subscription->payments()
            ->where('subscription_payments.status', SubscriptionPaymentStatus::PENDING)
            ->where('subscription_payments.payment_method', '!=', 'mercadopago')
            ->orderByDesc('subscription_payments.id')
            ->first();

        return $payment ? $this->paymentSummary($payment) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rejectedPaymentData(Subscription $subscription): ?array
    {
        $payment = $subscription->payments()
            ->where('subscription_payments.status', SubscriptionPaymentStatus::REJECTED)
            ->orderByDesc('subscription_payments.id')
            ->first();

        return $payment ? $this->paymentSummary($payment) : null;
    }

    /**
     * Cost of renewing the current plan for one billing period, including the
     * discounts that the renewal flow would apply.
     *
     * @param  array{billing_period: ?BillingPeriod, items: array<int, array<string, mixed>>, subtotal: float}  $breakdown
     * @return array<string, mixed>
     */
    private function renewalData(Subscription $subscription, array $breakdown): array
    {
        $subtotal = $breakdown['subtotal'];

        // The ongoing referrer discount only applies to monthly billing
        // (mirrors ProcessSubscriptionPaymentAction::calculateDiscounts()).
        $referrerDiscountPct = $breakdown['billing_period'] === BillingPeriod::MONTHLY
            ? round($subscription->getReferrerActiveDiscountPct(), 2)
            : 0.0;

        $pendingReferral = $subscription->pendingRegistrationReferral();
        $referralCodeDiscountPct = round((float) ($pendingReferral?->referred_discount_pct ?? 0), 2);

        $discountAmount = round($subtotal * (($referrerDiscountPct + $referralCodeDiscountPct) / 100), 2);

        return [
            'billing_period' => $breakdown['billing_period']?->value,
            'subtotal' => $subtotal,
            'referrer_discount_pct' => $referrerDiscountPct,
            'referral_code_discount_pct' => $referralCodeDiscountPct,
            'discount_amount' => $discountAmount,
            'total' => round(max($subtotal - $discountAmount, 0), 2),
            'note' => 'Estimación calculada con los precios vigentes del plan y los descuentos activos; el monto final se confirma al generar la renovación.',
        ];
    }

    /**
     * Latest subscription payments made to EzyVentas, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentHistory(Subscription $subscription): array
    {
        return $subscription->payments()
            ->with('subscriptionVersion.items')
            ->orderByDesc('subscription_payments.id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (SubscriptionPayment $payment): array {
                $version = $payment->subscriptionVersion;

                return $this->paymentSummary($payment) + [
                    'covered_period' => $version ? [
                        'start_date' => $version->start_date?->toDateString(),
                        'end_date' => $version->end_date?->toDateString(),
                    ] : null,
                    'covered_plan' => $version
                        ? $version->items
                            ->where('item_type', PlanItemType::MODULE->value)
                            ->pluck('name')
                            ->values()
                            ->all()
                        : [],
                    'invoice_status' => $payment->invoice_status?->value,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentSummary(SubscriptionPayment $payment): array
    {
        $discountAmount = $payment->referral_discount_amount;

        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'original_amount' => round((float) $payment->amount + (float) ($discountAmount ?? 0), 2),
            'referral_discount_pct' => $payment->referral_discount_pct !== null ? (float) $payment->referral_discount_pct : null,
            'referral_discount_amount' => $discountAmount !== null ? (float) $discountAmount : null,
            'payment_method' => $payment->payment_method,
            'status' => $payment->status?->value,
            'created_at' => $payment->created_at?->toIso8601String(),
            'rejection_reason' => data_get($payment->payment_details, 'rejection_reason'),
        ];
    }
}
