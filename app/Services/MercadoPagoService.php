<?php

namespace App\Services;

use App\Models\StoreConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles Mercado Pago OAuth flow and payment preference creation
 * for the SaaS multi-tenant online store.
 *
 * Each subscriber connects their own MP account via OAuth.
 * Payments go directly to their MP account — the platform never touches the money.
 */
class MercadoPagoService
{
    /**
     * Build the OAuth authorization URL for a subscriber to connect their MP account.
     */
    public function buildOAuthUrl(int $subscriptionId): string
    {
        $params = http_build_query([
            'client_id'     => config('services.mercadopago.client_id'),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'redirect_uri'  => config('services.mercadopago.redirect_uri'),
            'state'         => $subscriptionId,
        ]);

        return 'https://auth.mercadopago.com/authorization?' . $params;
    }

    /**
     * Exchange an OAuth authorization code for access/refresh tokens.
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post('https://api.mercadopago.com/oauth/token', [
            'client_id'     => config('services.mercadopago.client_id'),
            'client_secret' => config('services.mercadopago.client_secret'),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => config('services.mercadopago.redirect_uri'),
        ]);

        if (!$response->successful()) {
            Log::error('Mercado Pago OAuth token exchange failed', ['body' => $response->body()]);
            throw new \RuntimeException('Failed to exchange Mercado Pago authorization code.');
        }

        return $response->json();
    }

    /**
     * Create a payment preference for an online store order.
     * Returns the preference data including init_point (redirect URL).
     */
    public function createPreference(StoreConfig $storeConfig, array $orderData): array
    {
        $accessToken = $storeConfig->mp_access_token;

        if (!$accessToken) {
            throw new \RuntimeException('Mercado Pago is not connected for this store.');
        }

        $items = array_map(function ($item) {
            return [
                'id'          => (string) $item['product_id'],
                'title'       => mb_substr($item['product_name'], 0, 256),
                'quantity'    => (int) $item['quantity'],
                'unit_price'  => (float) $item['unit_price'],
                'currency_id' => 'MXN',
            ];
        }, $orderData['items']);

        // Add shipping as an extra item if applicable
        if (($orderData['shipping_cost'] ?? 0) > 0) {
            $items[] = [
                'id'          => 'shipping',
                'title'       => 'Costo de envío',
                'quantity'    => 1,
                'unit_price'  => (float) $orderData['shipping_cost'],
                'currency_id' => 'MXN',
            ];
        }

        $payload = [
            'items'               => $items,
            'back_urls'           => [
                'success' => $orderData['success_url'],
                'failure' => $orderData['failure_url'],
                'pending' => $orderData['pending_url'],
            ],
            'auto_return'         => 'approved',
            'external_reference'  => (string) $orderData['order_id'],
            'notification_url'    => $orderData['webhook_url'] ?? null,
            'statement_descriptor' => mb_substr($storeConfig->store_name ?? 'Tienda', 0, 20),
        ];

        $response = Http::withToken($accessToken)
            ->post('https://api.mercadopago.com/checkout/preferences', $payload);

        if (!$response->successful()) {
            Log::error('Mercado Pago preference creation failed', [
                'store_id' => $storeConfig->id,
                'body'     => $response->body(),
            ]);
            throw new \RuntimeException('Failed to create Mercado Pago payment preference.');
        }

        return $response->json();
    }
}
