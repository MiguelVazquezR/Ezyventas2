<?php

namespace App\Http\Controllers\SmartCheckout;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmartCheckout\StoreDetectionEventRequest;
use App\Http\Requests\SmartCheckout\UpdateSmartCheckoutSettingsRequest;
use App\Models\User;
use App\Services\SmartCheckout\SmartCheckoutAnalyticsService;
use App\Services\SmartCheckout\SmartPosSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Modo Caja Inteligente" of the POS (Phase 2).
 *
 * JSON endpoints:
 *  - `GET  /pos/smart-checkout/settings`       the stored configuration,
 *  - `PUT  /pos/smart-checkout/settings`       its update,
 *  - `POST /pos/smart-checkout/events`         one detection / sale metric event,
 *  - `GET  /pos/smart-checkout/metrics/today`  the aggregates of the day.
 *
 * The business preference (`smartpos_settings.smart_checkout_enabled`) is
 * re-validated on every request: the ON/OFF the cashier keeps in `localStorage`
 * authorises nothing on its own. When the flag is off every endpoint answers 403
 * with the same message. The switch itself is `PUT /preferences` (Phase 1) and is
 * the only route that does not check the flag.
 */
class SmartCheckoutController extends Controller
{
    /** Message of the 403 while the business has the mode disabled. */
    private const DISABLED_MESSAGE = 'El Modo Caja Inteligente no está habilitado para este negocio.';

    public function __construct(
        private readonly SmartPosSettingsService $smartPosSettings,
        private readonly SmartCheckoutAnalyticsService $analytics,
    ) {}

    /**
     * GET /pos/smart-checkout/settings
     */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        return response()->json([
            'enabled' => true,
            'settings' => $this->smartPosSettings->smartCheckoutSettingsFor($user),
        ]);
    }

    /**
     * PUT /pos/smart-checkout/settings
     */
    public function updateSettings(UpdateSmartCheckoutSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        // The subscription always comes from the authenticated user, never from
        // the payload: a tenant can only write its own row. Only the settings
        // JSON is written; the on/off switch is left untouched.
        $this->smartPosSettings->updateSmartCheckout(
            (int) $user->branch->subscription_id,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Configuración del Modo Caja Inteligente guardada.',
            'settings' => $this->smartPosSettings->smartCheckoutSettingsFor($user),
        ]);
    }

    /**
     * POST /pos/smart-checkout/events
     */
    public function storeEvent(StoreDetectionEventRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        try {
            $event = $this->analytics->recordEvent($user, $request->validated());
        } catch (ValidationException $exception) {
            // The service rejects a sale of another branch once it knows the
            // branch of the cashier. The web routes are not under /api/*, so the
            // exception is turned into the JSON contract by hand.
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'id' => $event->id,
            'event_type' => $event->event_type,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ], 201);
    }

    /**
     * GET /pos/smart-checkout/metrics/today
     */
    public function todayMetrics(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        return response()->json([
            'today' => $this->analytics->todayMetrics($user),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 403 response when the business has not enabled the mode, null otherwise.
     */
    private function denyWhenDisabled(?User $user): ?JsonResponse
    {
        if ($this->smartPosSettings->smartCheckoutEnabledFor($user)) {
            return null;
        }

        return response()->json([
            'message' => self::DISABLED_MESSAGE,
        ], 403);
    }
}
