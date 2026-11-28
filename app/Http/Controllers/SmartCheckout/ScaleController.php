<?php

namespace App\Http\Controllers\SmartCheckout;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmartCheckout\UpdatePreferencesRequest;
use App\Http\Requests\SmartCheckout\UpdateScaleSettingsRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductCatalogService;
use App\Services\SmartCheckout\SmartPosSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bulk scale of the POS ("Modo Caja Inteligente", Phase 1).
 *
 * JSON endpoints:
 *  - `GET  /pos/smart-checkout/scale/settings`            the stored configuration,
 *  - `PUT  /pos/smart-checkout/scale/settings`            its update,
 *  - `GET  /pos/smart-checkout/scale/weighable-products`  the products that can be weighed,
 *  - `PUT  /pos/smart-checkout/preferences`               the on/off switches of the module.
 *
 * The business preference (`smartpos_settings.scale_enabled`) is re-validated on
 * every request: the ON/OFF the cashier keeps in `localStorage` authorises
 * nothing on its own. When the flag is off the scale endpoints answer 403; the
 * preferences endpoint is the switch itself and therefore never checks the flag.
 */
class ScaleController extends Controller
{
    /** A scale panel never lists more than this many products. */
    private const MAX_WEIGHABLE_PRODUCTS = 50;

    public function __construct(
        private readonly SmartPosSettingsService $smartPosSettings,
        private readonly ProductCatalogService $productCatalog,
    ) {}

    /**
     * GET /pos/smart-checkout/scale/settings
     */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenScaleIsDisabled($user)) {
            return $denied;
        }

        return response()->json([
            'enabled' => true,
            'can_configure' => true,
            'settings' => $this->smartPosSettings->scaleSettingsFor($user),
        ]);
    }

    /**
     * PUT /pos/smart-checkout/scale/settings
     */
    public function updateSettings(UpdateScaleSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenScaleIsDisabled($user)) {
            return $denied;
        }

        // The subscription always comes from the authenticated user, never from
        // the payload: a tenant can only write its own row.
        $this->smartPosSettings->updateScale(
            (int) $user->branch->subscription_id,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Configuración de la báscula guardada.',
            'enabled' => true,
            'can_configure' => true,
            'settings' => $this->smartPosSettings->scaleSettingsFor($user),
        ]);
    }

    /**
     * PUT /pos/smart-checkout/preferences
     *
     * The on/off switches of the module (decision D2).
     *
     * This is the switch itself, so it is deliberately **not** gated by
     * `scale_enabled`: it must work both to enable and to disable.
     */
    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        // The subscription always comes from the authenticated user, never from
        // the payload: a tenant can only write its own row.
        $settings = $this->smartPosSettings->updateFlags(
            (int) $user->branch->subscription_id,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Preferencias actualizadas.',
            'scale_enabled' => (bool) $settings->scale_enabled,
            'smart_checkout_enabled' => (bool) $settings->smart_checkout_enabled,
        ]);
    }

    /**
     * GET /pos/smart-checkout/scale/weighable-products
     *
     * Products of the cashier's branch that can be sold by weight: bulk products
     * whose unit is a weight unit. Same payload as the POS catalog so the panel
     * can reuse the product card.
     */
    public function weighableProducts(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenScaleIsDisabled($user)) {
            return $denied;
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = (int) $user->branch_id;

        $products = $this->productCatalog
            ->queryForBranch($branchId, ['search' => $validated['search'] ?? null])
            ->where('is_bulk', true)
            // `measure_unit` is free text: normalise it in SQL (LOWER/TRIM work
            // on MySQL and on the SQLite used by the test suite) so the 50-row
            // limit is applied to the real set of weighable products.
            ->whereIn(DB::raw('LOWER(TRIM(measure_unit))'), SmartPosSettingsService::WEIGHT_UNITS)
            ->limit(self::MAX_WEIGHABLE_PRODUCTS)
            ->get();

        return response()->json([
            'data' => $products
                ->map(fn (Product $product) => $this->productCatalog->payload($product, $branchId))
                ->all(),
        ]);
    }

    /**
     * 403 response when the business has not enabled the scale, null otherwise.
     */
    private function denyWhenScaleIsDisabled(?User $user): ?JsonResponse
    {
        if ($this->smartPosSettings->scaleEnabledFor($user)) {
            return null;
        }

        return response()->json([
            'message' => 'La báscula no está habilitada para este negocio.',
        ], 403);
    }
}
