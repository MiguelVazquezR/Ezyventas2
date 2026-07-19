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

        $purchaseData = [
            'fiscal_profile_id'    => $data['fiscal_profile_id'],
            'requested_by_user_id' => $data['requested_by_user_id'],
            'stamp_quantity'       => $data['stamp_quantity'],
            'unit_price'           => $pricing['unit_price'],
            'amount_total'         => $pricing['amount_total'],
            'pricing_tier_id'      => $pricing['pricing_tier_id'],
            'payment_method'       => $data['payment_method'],
            'status'               => $data['payment_method'] === 'mercadopago'
                ? StampPurchaseStatus::PENDING
                : StampPurchaseStatus::AWAITING_REVIEW,
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
}
