<?php

namespace App\Actions\Billing;

use App\Enums\StampPaymentMethod;
use App\Enums\StampPurchaseStatus;
use App\Models\Billing\StampPurchase;
use App\Services\Billing\StampPurchaseService;
use App\Services\PlatformMercadoPagoService;

/**
 * CreateStampPurchaseAction
 *
 * Creates a stamp purchase record and, for Mercado Pago payments,
 * generates a checkout preference.
 */
class CreateStampPurchaseAction
{
    public function __construct(
        private readonly StampPurchaseService $stampPurchaseService,
        private readonly PlatformMercadoPagoService $mercadoPagoService,
    ) {}

    /**
     * Execute the stamp purchase creation.
     *
     * @return array{purchase: StampPurchase, mp_preference: array|null}
     */
    public function execute(array $data): array
    {
        // Recalculate price server-side — never trust client price
        $pricing = $this->stampPurchaseService->calculatePrice($data['stamp_quantity']);

        // Determine if this Mercado Pago purchase exceeds the review threshold
        $threshold = $this->getLargePurchaseThreshold();
        $reviewReason = null;

        if ($data['payment_method'] === 'mercadopago') {
            // Check master account balance before allowing the purchase.
            $balanceCheck = $this->stampPurchaseService->checkMasterBalance($data['stamp_quantity']);

            if (! $balanceCheck['sufficient']) {
                throw new \RuntimeException(
                    "En este momento no podemos procesar esta cantidad de timbres. "
                    . "Tu cuenta maestra tiene {$balanceCheck['stampsBalance']} timbres disponibles. "
                    . "Intenta con una cantidad menor o contacta a soporte."
                );
            }

            // If quantity meets or exceeds the threshold, flag for manual review
            if ($data['stamp_quantity'] >= $threshold) {
                $reviewReason = 'large_quantity';
            }
        } else {
            // Bank transfers always require review
            $reviewReason = 'bank_transfer';
        }

        $purchaseData = [
            'fiscal_profile_id'    => $data['fiscal_profile_id'],
            'requested_by_user_id' => $data['requested_by_user_id'],
            'stamp_quantity'       => $data['stamp_quantity'],
            'unit_price'           => $pricing['unit_price'],
            'amount_total'         => $pricing['amount_total'],
            'pricing_tier_id'      => $pricing['pricing_tier_id'],
            'payment_method'       => $data['payment_method'],
            'status'               => $reviewReason
                ? StampPurchaseStatus::AWAITING_REVIEW
                : StampPurchaseStatus::PENDING,
            'review_reason'        => $reviewReason,
            'proof_file_path'      => $data['proof_file_path'] ?? null,
            'proof_uploaded_at'    => $data['proof_file_path'] ? now() : null,
        ];

        $purchase = $this->stampPurchaseService->createPurchase($purchaseData);

        $mpPreference = null;

        if ($data['payment_method'] === 'mercadopago') {
            $mpOrderData = [
                'id'            => (string) $purchase->id,
                'type'          => 'stamp_purchase',
                'title'         => "Compra de {$purchase->stamp_quantity} timbres",
                'quantity'      => 1,
                'unit_price'    => (float) $purchase->amount_total,
                'description'   => "Compra de timbres para perfil fiscal",
            ];

            $mpPreference = $this->mercadoPagoService->createPreference($mpOrderData);
            $purchase->update(['mp_preference_id' => $mpPreference['id'] ?? null]);
        }

        return [
            'purchase'      => $purchase,
            'mp_preference' => $mpPreference,
        ];
    }

    /**
     * Get the configured threshold for large purchase review.
     */
    private function getLargePurchaseThreshold(): int
    {
        $definition = \App\Models\SettingDefinition::where('key', 'stamp_large_purchase_threshold')->first();

        return (int) ($definition?->default_value ?? 1000);
    }
}
