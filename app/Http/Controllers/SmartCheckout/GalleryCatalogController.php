<?php

namespace App\Http\Controllers\SmartCheckout;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\SmartCheckout\SmartCheckoutCatalogGalleryService;
use App\Services\SmartCheckout\SmartPosSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Galería desde el catálogo (Fase 4 de "Modo Caja Inteligente").
 *
 * Endpoints JSON:
 *  - `GET /pos/smart-checkout/gallery/candidates`      productos de la sucursal
 *    con sus imágenes, paginados por keyset, para sembrar la galería de vectores,
 *  - `GET /pos/smart-checkout/gallery/products/{product}` las muestras ya
 *    guardadas de un producto (la UI las lista y las borra con el
 *    `DELETE /samples/{id}` de la Fase 3).
 *
 * Mismo gate que el resto del módulo: la ruta sólo exige `pos.access` (el módulo
 * no tiene permisos propios, decisiones.md) y la **preferencia del negocio**
 * `smartpos_settings.smart_checkout_enabled` se revalida aquí en cada petición.
 * Con el modo apagado los dos endpoints contestan 403 con el mismo mensaje que el
 * resto de controladores del módulo. La suscripción y la sucursal salen siempre
 * del usuario autenticado, nunca de la query.
 *
 * Un producto de otra sucursal se contesta con 404 (nunca 403): el endpoint no
 * debe confirmar que existe un producto ajeno.
 */
class GalleryCatalogController extends Controller
{
    /** Mensaje del 403 mientras el negocio tiene el modo apagado (contrato de la Fase 2). */
    private const DISABLED_MESSAGE = 'El Modo Caja Inteligente no está habilitado para este negocio.';

    /** Mensaje del 404 de un producto que no es de la sucursal del cajero. */
    public const PRODUCT_NOT_FOUND_MESSAGE = 'El producto indicado no existe en la sucursal del cajero.';

    public function __construct(
        private readonly SmartCheckoutCatalogGalleryService $catalogGallery,
        private readonly SmartPosSettingsService $smartPosSettings,
    ) {}

    /**
     * GET /pos/smart-checkout/gallery/candidates?model_version=…&after_id=42&limit=50&filter=pending
     *
     * Ver `SmartCheckoutCatalogGalleryService::candidatesFor()` para el contrato
     * completo de la respuesta.
     */
    public function candidates(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        try {
            $validated = $request->validate([
                'model_version' => ['nullable', 'string', 'max:64'],
                'after_id' => ['nullable', 'integer', 'min:1'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:'.SmartCheckoutCatalogGalleryService::MAX_LIMIT],
                'filter' => ['nullable', Rule::in(SmartCheckoutCatalogGalleryService::FILTERS)],
            ], [
                'model_version.string' => 'La versión del modelo debe ser texto.',
                'model_version.max' => 'La versión del modelo no puede superar 64 caracteres.',
                'after_id.integer' => 'El cursor de productos debe ser un identificador numérico.',
                'after_id.min' => 'El cursor de productos debe ser un identificador válido.',
                'limit.integer' => 'El número de productos por página debe ser un número entero.',
                'limit.min' => 'El número de productos por página debe ser al menos 1.',
                'limit.max' => 'El número de productos por página no puede superar '.SmartCheckoutCatalogGalleryService::MAX_LIMIT.'.',
                'filter.in' => 'El filtro debe ser «pending» o «all».',
            ], [
                'model_version' => 'versión del modelo',
                'after_id' => 'cursor de productos',
                'limit' => 'límite de productos',
                'filter' => 'filtro',
            ]);
        } catch (ValidationException $exception) {
            // Las rutas del módulo no viven bajo /api/*, y el manejador de
            // excepciones del proyecto sólo devuelve JSON ahí (ApiExceptionRenderer),
            // así que el 422 se construye a mano igual que en
            // GalleryController::store() y SmartCheckoutController::storeEvent().
            return $this->validationFailed($exception);
        }

        return response()->json($this->catalogGallery->candidatesFor(
            $user,
            $validated['model_version'] ?? null,
            isset($validated['after_id']) ? (int) $validated['after_id'] : null,
            isset($validated['limit'])
                ? (int) $validated['limit']
                : SmartCheckoutCatalogGalleryService::DEFAULT_LIMIT,
            (string) ($validated['filter'] ?? SmartCheckoutCatalogGalleryService::FILTER_PENDING),
        ));
    }

    /**
     * GET /pos/smart-checkout/gallery/products/{product}?model_version=…
     *
     * Ver `SmartCheckoutCatalogGalleryService::samplesFor()` para el contrato
     * completo de la respuesta.
     */
    public function productSamples(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyWhenDisabled($user)) {
            return $denied;
        }

        try {
            $validated = $request->validate([
                'model_version' => ['nullable', 'string', 'max:64'],
            ], [
                'model_version.string' => 'La versión del modelo debe ser texto.',
                'model_version.max' => 'La versión del modelo no puede superar 64 caracteres.',
            ], [
                'model_version' => 'versión del modelo',
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        }

        $payload = $this->catalogGallery->samplesFor(
            $user,
            $product,
            $validated['model_version'] ?? null,
        );

        if ($payload === null) {
            return response()->json([
                'message' => self::PRODUCT_NOT_FOUND_MESSAGE,
            ], 404);
        }

        return response()->json($payload);
    }

    /**
     * 403 cuando el negocio no tiene el modo habilitado, null en caso contrario.
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

    /**
     * 422 JSON con los mensajes en español, como el resto del módulo.
     */
    private function validationFailed(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => collect($exception->errors())->flatten()->first(),
            'errors' => $exception->errors(),
        ], 422);
    }
}
