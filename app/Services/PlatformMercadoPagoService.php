<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles Mercado Pago Checkout Pro payments for platform subscription billing.
 *
 * Uses the platform's own Mercado Pago account (not per-subscriber OAuth).
 * This is separate from MercadoPagoService which handles the online store module
 * where each subscriber connects their own MP account.
 */
class PlatformMercadoPagoService
{
    /**
     * Get the access token for the platform's MP account.
     * In local/dev, uses the test access token. In production, uses the platform token.
     */
    public function getAccessToken(): string
    {
        if ($this->isSandbox()) {
            return config('services.mercadopago.test_access_token') ?? '';
        }
        return config('services.mercadopago.platform_token') ?? '';
    }

    /**
     * Whether Mercado Pago is running in sandbox/test mode.
     * Controlled by MP_ENV in .env, independent of APP_ENV.
     */
    public function isSandbox(): bool
    {
        return config('services.mercadopago.env', 'sandbox') === 'sandbox';
    }

    /**
     * Create a Checkout Pro preference for a subscription payment.
     *
     * @param array $orderData {
     *     items: array,
     *     payer_email: string,
     *     subscription_payment_id: int,
     *     description: string,
     *     success_url: string,
     *     failure_url: string,
     *     pending_url: string,
     *     webhook_url?: string,
     * }
     * @return array MP preference data including init_point
     */
    public function createPreference(array $orderData): array
    {
        $accessToken = $this->getAccessToken();

        if (empty($accessToken)) {
            throw new \RuntimeException('Mercado Pago platform token is not configured.');
        }

        $items = array_map(function ($item) {
            return [
                'id'          => (string) $item['id'],
                'title'       => mb_substr($item['title'], 0, 256),
                'description' => mb_substr($item['description'] ?? '', 0, 256),
                'quantity'    => (int) $item['quantity'],
                'unit_price'  => (float) $item['unit_price'],
                'currency_id' => 'MXN',
            ];
        }, $orderData['items']);

        $payload = [
            'items'                => $items,
            'payer'                => [
                'email' => $orderData['payer_email'],
            ],
            'back_urls'            => [
                'success' => $orderData['success_url'],
                'failure' => $orderData['failure_url'],
                'pending' => $orderData['pending_url'],
            ],
            'auto_return'          => 'approved',
            'external_reference'   => (string) $orderData['subscription_payment_id'],
            'statement_descriptor' => mb_substr($orderData['description'] ?? 'Suscripcion EzyVentas', 0, 20),
        ];

        if (!empty($orderData['webhook_url'])) {
            $payload['notification_url'] = $orderData['webhook_url'];
        }

        $response = Http::withToken($accessToken)
            ->post('https://api.mercadopago.com/checkout/preferences', $payload);

        if (!$response->successful()) {
            Log::error('MP Platform preference creation failed', [
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('No se pudo crear la preferencia de pago en Mercado Pago.');
        }

        return $response->json();
    }

    /**
     * Get payment details by MP payment ID.
     */
    public function getPayment(string $paymentId): array
    {
        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

        if (!$response->successful()) {
            Log::error('MP Platform getPayment failed', [
                'payment_id' => $paymentId,
                'body'       => $response->body(),
            ]);
            throw new \RuntimeException('No se pudo consultar el pago en Mercado Pago.');
        }

        return $response->json();
    }
}
