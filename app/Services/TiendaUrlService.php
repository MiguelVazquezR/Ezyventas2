<?php

namespace App\Services;

use App\Models\StoreConfig;

/**
 * Centralized service for generating store URLs.
 * 
 * Abstracts the URL format (path vs subdomain) so that switching
 * between hosting environments only requires changing TIENDA_URL_MODE.
 */
class TiendaUrlService
{
    /**
     * Get the full public URL for a store.
     */
    public function storeUrl(StoreConfig $storeConfig): string
    {
        $slug = $storeConfig->slug;

        if ($this->isSubdomainMode()) {
            $baseDomain = config('app.store_base_domain', config('app.domain'));
            return "https://{$slug}.{$baseDomain}";
        }

        // Path mode (default for shared hosting)
        return url("/store/{$slug}");
    }

    /**
     * Get the URL for a specific product in a store.
     */
    public function productUrl(StoreConfig $storeConfig, int $productId): string
    {
        return $this->storeUrl($storeConfig) . "/product/{$productId}";
    }

    /**
     * Get the URL for the store's cart/order page.
     */
    public function cartUrl(StoreConfig $storeConfig): string
    {
        return $this->storeUrl($storeConfig) . '/cart';
    }

    /**
     * Get the URL for order confirmation.
     */
    public function orderConfirmationUrl(StoreConfig $storeConfig, int $orderId): string
    {
        return $this->storeUrl($storeConfig) . "/order/{$orderId}/confirmed";
    }

    /**
     * Determine if the app is running in subdomain mode.
     */
    public function isSubdomainMode(): bool
    {
        return config('app.store_url_mode', 'path') === 'subdomain';
    }

    /**
     * Extract the store slug from the current request based on the active mode.
     * Returns null if no slug can be resolved.
     */
    public function resolveSlugFromRequest(): ?string
    {
        $request = request();

        // Path mode: slug is a route parameter
        $slug = $request->route('slug');
        if ($slug) {
            return $slug;
        }

        // Subdomain mode: extract from hostname
        if ($this->isSubdomainMode()) {
            $host = $request->getHost();
            $baseDomain = config('app.store_base_domain', config('app.domain'));

            if ($host !== $baseDomain && str_ends_with($host, '.' . $baseDomain)) {
                return str_replace('.' . $baseDomain, '', $host);
            }
        }

        return null;
    }
}
