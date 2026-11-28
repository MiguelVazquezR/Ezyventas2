<?php

namespace App\Http\Controllers\SmartCheckout;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmartCheckout\StoreProductSampleRequest;
use App\Models\SmartPosProductSample;
use App\Models\User;
use App\Services\SmartCheckout\SmartCheckoutGalleryService;
use App\Services\SmartCheckout\SmartPosSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Vector gallery of the "Modo Caja Inteligente" (Phase 3).
 *
 * JSON endpoints:
 *  - `GET    /pos/smart-checkout/gallery`         the tenant's vectors, as one
 *                                                 base64 batch with an `ETag`,
 *  - `POST   /pos/smart-checkout/samples`         one sample (throttled),
 *  - `DELETE /pos/smart-checkout/samples/{sample}` one sample of the tenant.
 *
 * Same gate as the rest of the module: the route only requires `pos.access` (the
 * feature has no permissions of its own, decisiones.md) and the **business
 * preference** `smartpos_settings.smart_checkout_enabled` is re-validated here on
 * every request. While it is off all three endpoints answer 403 with the same
 * message as the Phase 2 controller; the ON/OFF of the cashier's `localStorage`
 * authorises nothing. The tenant and the branch always come from the
 * authenticated user.
 *
 * The gallery endpoint is the only one with caching: it answers `304 Not Modified`
 * with no body when the `ETag` still matches the stored one.
 */
class GalleryController extends Controller
{
    /** Message of the 403 while the business has the mode disabled (Phase 2 contract). */
    private const DISABLED_MESSAGE = 'El Modo Caja Inteligente no está habilitado para este negocio.';

    /** Sent on every gallery response: the browser may store it but must revalidate. */
    private const CACHE_CONTROL = 'private, no-cache';

    public function __construct(
        private readonly SmartCheckoutGalleryService $gallery,
        private readonly SmartPosSettingsService $smartPosSettings,
    ) {}

    /**
     * GET /pos/smart-checkout/gallery?model_version=mock&cursor=42
     *
     * ```json
     * {
     *   "gallery_version": "2-7-1764218400",
     *   "model_version": "mock",
     *   "dim": 384,
     *   "count": 2,
     *   "total": 2,
     *   "items": [
     *     {"product_id": 5, "offset": 0, "length": 1536,
     *      "source": "catalog_image", "model_version": "mock", "quality": 0.91},
     *     {"product_id": 8, "offset": 1536, "length": 1536,
     *      "source": "barcode_scan", "model_version": "mock", "quality": null}
     *   ],
     *   "vectors": "AAAAAAAA…",
     *   "next_cursor": null
     * }
     * ```
     *
     * `ETag` is a fingerprint of the tenant plus the exact body, so it changes when
     * a sample is added, deleted or re-uploaded, and it can never be shared between
     * two businesses. `If-None-Match` is compared weakly (RFC 7232) and answered
     * with `304` and no body.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        $validated = $request->validate([
            'model_version' => ['nullable', 'string', 'max:64'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ], [
            'model_version.string' => 'La versión del modelo debe ser texto.',
            'model_version.max' => 'La versión del modelo no puede superar 64 caracteres.',
            'cursor.integer' => 'El cursor de la galería debe ser un identificador numérico.',
            'cursor.min' => 'El cursor de la galería debe ser un identificador válido.',
        ], [
            'model_version' => 'versión del modelo',
            'cursor' => 'cursor de la galería',
        ]);

        $payload = $this->gallery->galleryFor(
            $user,
            $validated['model_version'] ?? null,
            isset($validated['cursor']) ? (int) $validated['cursor'] : null,
        );

        $etag = $this->etagFor((int) $user->branch->subscription_id, $payload);

        if ($this->matchesIfNoneMatch($request->headers->get('If-None-Match'), $etag)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => self::CACHE_CONTROL,
            ]);
        }

        return response()
            ->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', self::CACHE_CONTROL);
    }

    /**
     * POST /pos/smart-checkout/samples
     *
     * `201 Created`:
     *
     * ```json
     * {
     *   "id": 42,
     *   "product_id": 5,
     *   "source": "cashier_correction",
     *   "samples_for_product": 3,
     *   "gallery_version": "13-42-1764218405"
     * }
     * ```
     *
     * `422` with Spanish messages when the payload is not usable (wrong
     * dimension, wrong length, non-finite component, unknown source, product of
     * another branch, crop that is not a 224x224 JPEG…).
     */
    public function store(StoreProductSampleRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        try {
            $result = $this->gallery->storeSample($user, $request->validated());
        } catch (ValidationException $exception) {
            // The service rejects what only the bytes can tell (vector length,
            // finite range, L2 norm, product of the branch, crop MIME). The web
            // routes are not under /api/*, so the JSON 422 is built by hand, the
            // same way SmartCheckoutController::storeEvent() does it.
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'id' => $result['sample']->id,
            'product_id' => (int) $result['sample']->product_id,
            'source' => (string) $result['sample']->source,
            'samples_for_product' => $result['samples_for_product'],
            'gallery_version' => $result['gallery_version'],
        ], 201);
    }

    /**
     * DELETE /pos/smart-checkout/samples/{sample}
     *
     * `204 No Content` when the sample of the tenant was deleted. A sample of
     * another business is answered with `404` (never `403`): the endpoint must not
     * confirm that a foreign id exists. In production EnsureSubscriptionScope
     * already answers 403 before this method runs; this is the second line of
     * defence.
     */
    public function destroy(Request $request, SmartPosProductSample $sample): JsonResponse|Response
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        if (! $this->gallery->deleteSample($user, $sample)) {
            return response()->json([
                'message' => 'La muestra indicada no existe en la galería de tu negocio.',
            ], 404);
        }

        return response()->noContent();
    }

    /**
     * ETag of one gallery page: the tenant plus a fingerprint of the whole body.
     *
     * Every field of the payload takes part in the fingerprint (the scalars as
     * they are, `vectors` through an md5 of the buffer and `items` through an md5
     * of their JSON), so the validator can never claim that a page is unchanged
     * when a byte differs — including the page boundaries of the paginated
     * gallery. It is deliberately **not** `md5(json_encode($payload))`: that would
     * encode the 4 MB body twice per request just to hash it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function etagFor(int $subscriptionId, array $payload): string
    {
        $fingerprint = implode('|', [
            $subscriptionId,
            (string) $payload['gallery_version'],
            (string) $payload['model_version'],
            (string) $payload['dim'],
            (string) $payload['count'],
            (string) $payload['total'],
            $payload['next_cursor'] === null ? 'null' : (string) $payload['next_cursor'],
            md5((string) $payload['vectors']),
            md5((string) json_encode($payload['items'])),
        ]);

        return '"'.md5($fingerprint).'"';
    }

    /**
     * Weak comparison of If-None-Match (RFC 7232 §3.2), as browsers send it after
     * a revalidation.
     */
    private function matchesIfNoneMatch(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        $candidates = explode(',', (string) preg_replace('/\s+/', '', $header));

        foreach ($candidates as $candidate) {
            if ($candidate === '*' || $candidate === $etag || str_replace('W/', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
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
