<?php

namespace App\Services\Account;

use App\Enums\InvoiceStatus;
use App\Enums\PlanItemType;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use Carbon\Carbon;

/**
 * Read model of the subscription screen (owners only).
 *
 * The numbers come from the same sources the web screen uses, so the phone shows
 * the same plan, usage and payment history.
 */
class SubscriptionReadService
{
    /**
     * Which usage counter corresponds to each contracted limit.
     *
     * @var array<string, string>
     */
    private const LIMIT_USAGE_MAP = [
        'limit_branches' => 'branches',
        'limit_users' => 'users',
        'limit_products' => 'products',
        'limit_bank_accounts' => 'bank_accounts',
        'limit_cash_registers' => 'cash_registers',
        'limit_print_templates' => 'print_templates',
        'limit_services' => 'services',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(Subscription $subscription): array
    {
        $subscription->loadCount([
            'branches',
            'users',
            'bankAccounts',
            'products',
            'cashRegisters',
            'printTemplates',
            'services',
        ]);

        $latestVersion = $subscription->versions()->latest('id')->first();
        $usage = $this->usagePayload($subscription);

        return [
            'subscription' => $this->subscriptionPayload($subscription),
            'plan' => $this->planPayload($subscription->currentVersion() ?? $latestVersion, $usage),
            'usage' => $usage,
            'status_data' => $this->statusPayload($latestVersion),
            'pending_payment' => $this->paymentPayload(
                $this->latestPaymentWithStatus($subscription, SubscriptionPaymentStatus::PENDING)
            ),
            'last_rejected_payment' => $this->paymentPayload(
                $this->latestPaymentWithStatus($subscription, SubscriptionPaymentStatus::REJECTED)
            ),
            'fiscal_document_url' => $subscription->getFirstMediaUrl('fiscal-documents') ?: null,
            'history' => $this->historyPayload($subscription),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(Subscription $subscription): array
    {
        $address = $subscription->address;

        return [
            'id' => $subscription->id,
            'commercial_name' => $subscription->commercial_name,
            'business_name' => $subscription->business_name,
            'status' => $subscription->status instanceof \BackedEnum ? $subscription->status->value : $subscription->status,
            'tax_id' => $subscription->tax_id,
            'contact_phone' => $subscription->contact_phone,
            'contact_email' => $subscription->contact_email,
            'address' => ['text' => is_array($address) ? ($address['text'] ?? null) : $address],
            'slug' => $subscription->slug,
        ];
    }

    /**
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    private function planPayload(?SubscriptionVersion $version, array $usage): array
    {
        $items = $version?->items ?? collect();

        $modules = $items
            ->where('item_type', PlanItemType::MODULE->value)
            ->map(fn ($item) => [
                'key' => $item->item_key,
                'name' => $item->name,
                'active' => true,
            ])
            ->values();

        $limits = $items
            ->where('item_type', PlanItemType::LIMIT->value)
            ->map(function ($item) use ($usage) {
                $usageKey = self::LIMIT_USAGE_MAP[$item->item_key] ?? null;

                return [
                    'key' => $item->item_key,
                    'name' => $item->name,
                    'limit' => (int) $item->quantity,
                    'used' => $usageKey ? ($usage[$usageKey] ?? 0) : null,
                ];
            })
            ->values();

        return [
            'modules' => $modules->all(),
            'limits' => $limits->all(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function usagePayload(Subscription $subscription): array
    {
        return [
            'branches' => (int) $subscription->branches_count,
            'users' => (int) $subscription->users_count,
            'bank_accounts' => (int) $subscription->bank_accounts_count,
            'products' => (int) $subscription->products_count,
            'cash_registers' => (int) $subscription->cash_registers_count,
            'print_templates' => (int) $subscription->print_templates_count,
            'services' => (int) $subscription->services_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(?SubscriptionVersion $latestVersion): array
    {
        $expiresAt = $latestVersion?->end_date;
        $isExpired = $expiresAt !== null && $expiresAt->isPast();
        $daysLeft = $expiresAt !== null
            ? max(0, (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false))
            : null;
        $expiringSoon = !$isExpired && $daysLeft !== null && $daysLeft <= 7;

        return [
            'label' => $isExpired ? 'Expirada' : ($expiringSoon ? 'Por vencer' : 'Activa'),
            'expires_at' => $expiresAt?->toIso8601String(),
            'days_left' => $daysLeft,
            'is_expired' => $isExpired,
            'warning' => match (true) {
                $isExpired => 'Tu suscripción expiró. Renuévala para seguir operando.',
                $expiringSoon => "Tu suscripción vence en {$daysLeft} día(s). Renuévala para no perder acceso.",
                default => null,
            },
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentPayload(?SubscriptionPayment $payment): ?array
    {
        if (!$payment) {
            return null;
        }

        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
            'payment_method' => $payment->payment_method,
            'folio' => $payment->payment_details['folio'] ?? null,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    private function latestPaymentWithStatus(Subscription $subscription, SubscriptionPaymentStatus $status): ?SubscriptionPayment
    {
        return SubscriptionPayment::whereHas(
            'subscriptionVersion',
            fn ($query) => $query->where('subscription_id', $subscription->id)
        )
            ->where('status', $status->value)
            ->latest('id')
            ->first();
    }

    /**
     * Payment history, newest first, with the version it belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyPayload(Subscription $subscription): array
    {
        return $subscription->versions()
            ->with('payments')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (SubscriptionVersion $version, int $index) {
                $payment = $version->payments->sortByDesc('id')->first();
                $paidAt = $payment?->payment_details['paid_at'] ?? null;

                return [
                    'version' => $index + 1,
                    'created_at' => $version->created_at?->toIso8601String(),
                    'total' => $payment ? number_format((float) $payment->amount, 2, '.', '') : null,
                    'payment' => $payment ? [
                        'folio' => $payment->payment_details['folio'] ?? null,
                        'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
                        'paid_at' => $paidAt
                            ? Carbon::parse($paidAt)->toIso8601String()
                            : $payment->updated_at?->toIso8601String(),
                        'can_request_invoice' => $payment->status === SubscriptionPaymentStatus::APPROVED
                            && $payment->invoice_status === InvoiceStatus::NOT_REQUESTED,
                    ] : null,
                ];
            })
            ->reverse()
            ->values()
            ->all();
    }
}
