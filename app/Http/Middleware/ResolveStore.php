<?php

namespace App\Http\Middleware;

use App\Models\StoreConfig;
use App\Services\TiendaUrlService;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the store from the URL and injects it into the request context.
 *
 * Works in both path mode (/store/{slug}) and subdomain mode ({slug}.domain.com).
 * If the store is not found or is inactive, returns a 404.
 */
class ResolveStore
{
    public function __construct(
        private readonly TiendaUrlService $urlService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $slug = $this->urlService->resolveSlugFromRequest();

        if (!$slug) {
            abort(404);
        }

        $storeConfig = StoreConfig::with('subscription')
            ->where('slug', $slug)
            ->first();

        if (!$storeConfig) {
            // Try custom domain
            $host = $request->getHost();
            $storeConfig = StoreConfig::where('custom_domain', $host)
                ->orWhere('custom_domain', 'LIKE', $host . ':%')
                ->first();
        }

        if (!$storeConfig) {
            abort(404);
        }

        if (!$storeConfig->is_active) {
            abort(404, 'Store is currently inactive.');
        }

        // Inject resolved store into the container for the rest of the request
        app()->instance('resolvedStore', $storeConfig);
        app()->instance(StoreConfig::class, $storeConfig);

        // Share with Inertia for public pages
        \Inertia\Inertia::share('store', [
            'name' => $storeConfig->store_name,
            'description' => $storeConfig->description,
            'tagline' => $storeConfig->tagline,
            'logo_url' => $storeConfig->logo_url,
            'primary_color' => $storeConfig->primary_color ? $this->ensureHash($storeConfig->primary_color) : null,
            'secondary_color' => $storeConfig->secondary_color ? $this->ensureHash($storeConfig->secondary_color) : null,
            'welcome_message' => $storeConfig->welcome_message,
            'footer_note' => $storeConfig->footer_note,
            'accepts_pickup' => $storeConfig->accepts_pickup,
            'accepts_delivery' => $storeConfig->accepts_delivery,
            'allow_out_of_stock_purchases' => $storeConfig->allow_out_of_stock_purchases,
            'out_of_stock_extra_minutes' => $storeConfig->out_of_stock_extra_minutes,
            'whatsapp_number' => $storeConfig->whatsapp_number,
            'delivery_fee' => $storeConfig->delivery_fee,
            'free_shipping_minimum' => $storeConfig->free_shipping_minimum,
            'theme_mode' => $storeConfig->theme_mode ?? 'light',
            'banners' => $storeConfig->banners,
            'terms_policy' => $storeConfig->terms_policy,
            'payment_mp_enabled' => $storeConfig->payment_mp_enabled ?? false,
            'payment_cash_enabled' => $storeConfig->payment_cash_enabled ?? true,
            'cash_instructions' => $storeConfig->cash_instructions ?? '',
            'mp_test_mode' => app()->environment('local'),
            'mp_account_info' => $storeConfig->mpAccountInfo(),
        ]);

        return $next($request);
    }

    private function ensureHash(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '#')) {
            return $value;
        }

        return '#' . $value;
    }
}
