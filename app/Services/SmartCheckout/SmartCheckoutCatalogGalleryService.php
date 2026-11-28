<?php

namespace App\Services\SmartCheckout;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\SmartPosProductSample;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Constructor de galería desde el catálogo (Fase 4 de "Modo Caja Inteligente").
 *
 * Responde dos preguntas que la UI del modo necesita para sembrar la galería de
 * vectores **antes** de tener cámara:
 *
 * 1. `candidatesFor()` — qué productos de la sucursal del cajero se pueden
 *    sembrar y con qué imágenes, junto con cuántas muestras tiene ya cada uno.
 *    El navegador descarga cada imagen, calcula su embedding con DINOv2 y llama
 *    al `POST /samples` que ya existe (Fase 3): este servicio **no** escribe
 *    muestras, sólo las cuenta.
 * 2. `samplesFor()` — las muestras ya guardadas de un producto, para que la UI
 *    las liste y permita borrarlas con el `DELETE /samples/{id}` existente.
 *
 * Reglas de aislamiento (descubrimiento §2): la suscripción y la sucursal salen
 * **siempre** del usuario autenticado, nunca del payload, y cada consulta se
 * acota a mano porque el modelo no tiene scope global.
 *
 * ## Decisiones tomadas aquí
 *
 * - **Una imagen por producto sin variantes** (la principal) y **una por foto de
 *   variante emparejada** cuando el producto tiene variantes y fotos de variante.
 *   El emparejamiento es el mismo de `ProductCatalogService::variantCombinations()`:
 *   `variant_key === "{atributo}_{valor}"` o `variant_option === valor`. Un
 *   producto con variantes pero sin fotos de variante cae a la principal, así
 *   ningún producto seleccionable se queda sin imagen.
 * - **`samples` se cuenta por `model_version`** (el de los ajustes del negocio si
 *   el cliente no pide otro) y por suscripción, porque un vector calculado con
 *   otro modelo no sirve para reconocer con el actual.
 * - **`total_pending` se resuelve con una sola consulta agregada** (muestras con
 *   `GROUP BY product_id` en un `LEFT JOIN` y la regla de imágenes como expresión
 *   SQL), nunca en bucle por producto. La expresión cuenta las fotos de variante
 *   cuando el producto tiene variantes y esas fotos llevan `variant_key` /
 *   `variant_option` (el caso normal, el que escribe `CreateProduct`); para el
 *   producto con variantes cuyas fotos de variante no emparejan con ninguna
 *   variante la UI cuenta 1 (la principal) y esta consulta puede contar N. Es una
 *   divergencia sólo posible con datos dañados o renombrados a mano; el `pending`
 *   de cada elemento del listado siempre es el exacto.
 * - **La paginación es keyset por `id`** (`after_id`, orden ascendente): el
 *   catálogo cambia mientras la UI siembra y un `offset` se saltaría o repetiría
 *   productos.
 */
class SmartCheckoutCatalogGalleryService
{
    /** Colección de Spatie con las imágenes generales del producto. */
    public const GENERAL_COLLECTION = 'product-general-images';

    /** Colección de Spatie con las imágenes de cada variante. */
    public const VARIANT_COLLECTION = 'product-variant-images';

    /** Productos por página cuando el cliente no pide otra cosa. */
    public const DEFAULT_LIMIT = 50;

    /** Tope de productos por página (lo que el navegador puede sembrar de una vez). */
    public const MAX_LIMIT = 50;

    /** Sólo los productos a los que les falta alguna muestra. */
    public const FILTER_PENDING = 'pending';

    /** Todos los productos seleccionables, con su `pending` calculado. */
    public const FILTER_ALL = 'all';

    /** Valores aceptados por `filter`. */
    public const FILTERS = [self::FILTER_PENDING, self::FILTER_ALL];

    /** Tipo de imagen de un producto sin variantes. */
    public const KIND_GENERAL = 'general';

    /** Tipo de imagen de una variante concreta. */
    public const KIND_VARIANT = 'variant';

    public function __construct(
        private readonly SmartPosSettingsService $settings,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | CANDIDATOS (LECTURA PAGINADA)
    |--------------------------------------------------------------------------
    */

    /**
     * Productos de la sucursal del usuario que se pueden sembrar en la galería.
     *
     * ```json
     * {
     *   "items": [
     *     {
     *       "product_id": 5,
     *       "name": "Coca-Cola 600 ml",
     *       "sku": "COC-600",
     *       "is_bulk": false,
     *       "images": [
     *         {"url": "https://ezy.test/storage/12/coca.jpg", "kind": "general", "label": null, "variant_id": null},
     *         {"url": "https://ezy.test/storage/18/rojo.jpg", "kind": "variant", "label": "Rojo / M", "variant_id": 9}
     *       ],
     *       "samples": 2,
     *       "pending": 0
     *     }
     *   ],
     *   "count": 1,
     *   "next_cursor": 5,
     *   "has_more": false,
     *   "model_version": "dinov2-small-onnx-2026-11",
     *   "total_pending": 37
     * }
     * ```
     *
     * `after_id` es el `next_cursor` de la página anterior (keyset); `limit` se
     * pide al servidor con una fila de más para saber si hay página siguiente sin
     * equivocarse cuando el último lote cabe exacto.
     *
     * @return array<string, mixed>
     */
    public function candidatesFor(
        User $user,
        ?string $modelVersion = null,
        ?int $afterId = null,
        int $limit = self::DEFAULT_LIMIT,
        string $filter = self::FILTER_PENDING,
    ): array {
        $subscriptionId = $this->subscriptionIdFor($user);
        $branchId = $this->branchIdFor($user);
        $modelVersion = $this->resolveModelVersion($subscriptionId, $modelVersion);

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $query = $this->candidatesQuery($branchId);

        if ($filter === self::FILTER_PENDING) {
            $this->wherePending($query, $subscriptionId, $modelVersion);
        }

        if ($afterId !== null) {
            $query->where('products.id', '>', $afterId);
        }

        $rows = $query
            ->with(['media', 'productAttributes'])
            ->orderBy('products.id')
            // Una fila de más: es la única forma fiable de saber si hay página
            // siguiente cuando el último lote cabe exacto en el límite.
            ->limit($limit + 1)
            ->get(['products.id', 'products.name', 'products.sku', 'products.is_bulk']);

        $hasMore = $rows->count() > $limit;

        $page = $rows->take($limit)->values();

        // Una sola consulta de recuentos para toda la página (nada de N+1).
        $samples = $this->sampleCounts(
            $subscriptionId,
            $modelVersion,
            $page->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        $items = [];

        foreach ($page as $product) {
            $images = $this->imagesFor($product);

            // Defensivo: sin ninguna imagen no hay nada que sembrar, así que el
            // producto no puede ser candidato (el filtro de la consulta ya exige
            // medios reales en alguna de las dos colecciones).
            if ($images === []) {
                continue;
            }

            $samplesForProduct = (int) ($samples[(int) $product->id] ?? 0);

            $pending = max(0, count($images) - $samplesForProduct);

            // `filter=pending` se resuelve en SQL (ver wherePending()); si la
            // expresión SQL y el recuento exacto de imágenes discrepasen —fotos de
            // variante con propiedades pero sin variante a la que emparejar—, el
            // elemento se descarta aquí para que el contrato "sólo productos con
            // pending > 0" se cumpla siempre. La paginación keyset no se rompe:
            // sigue avanzando por id aunque una página salga más corta.
            if ($filter === self::FILTER_PENDING && $pending === 0) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku !== null ? (string) $product->sku : null,
                'is_bulk' => (bool) $product->is_bulk,
                'images' => $images,
                'samples' => $samplesForProduct,
                'pending' => $pending,
            ];
        }

        $lastId = $page->isEmpty() ? null : (int) $page->last()->id;

        return [
            'items' => $items,
            'count' => count($items),
            'next_cursor' => $hasMore && $lastId !== null ? $lastId : null,
            'has_more' => $hasMore,
            'model_version' => $modelVersion,
            'total_pending' => $this->totalPendingFor($branchId, $subscriptionId, $modelVersion),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MUESTRAS DE UN PRODUCTO
    |--------------------------------------------------------------------------
    */

    /**
     * Muestras guardadas de un producto de la sucursal del usuario.
     *
     * ```json
     * {
     *   "product_id": 5,
     *   "name": "Coca-Cola 600 ml",
     *   "sku": "COC-600",
     *   "count": 2,
     *   "samples": [
     *     {"id": 42, "source": "catalog_image", "quality": 0.91, "dim": 384,
     *      "model_version": "dinov2-small-onnx-2026-11", "has_crop": true,
     *      "created_at": "2026-11-27T18:05:00+00:00"}
     *   ]
     * }
     * ```
     *
     * Devuelve `null` cuando el producto no pertenece a la sucursal del usuario,
     * para que el controlador conteste 404 (nunca 403: no debe confirmar que un
     * producto ajeno existe).
     *
     * @return array<string, mixed>|null
     */
    public function samplesFor(User $user, Product $product, ?string $modelVersion = null): ?array
    {
        $subscriptionId = $this->subscriptionIdFor($user);
        $branchId = $this->branchIdFor($user);

        if (! $this->productBelongsToBranch((int) $product->id, $branchId)) {
            return null;
        }

        $query = SmartPosProductSample::query()
            ->forSubscription($subscriptionId)
            ->where('product_id', (int) $product->id);

        $requested = trim((string) $modelVersion);

        if ($requested !== '') {
            $query->forModelVersion($requested);
        }

        $samples = $query
            ->orderByDesc('created_at')
            // Las muestras de un mismo segundo deben salir en un orden estable.
            ->orderByDesc('id')
            ->get(['id', 'source', 'quality', 'dim', 'model_version', 'crop_path', 'created_at']);

        $items = $samples->map(static fn (SmartPosProductSample $sample): array => [
            'id' => (int) $sample->id,
            'source' => (string) $sample->source,
            'quality' => $sample->quality !== null ? (float) $sample->quality : null,
            'dim' => (int) $sample->dim,
            'model_version' => (string) $sample->model_version,
            'has_crop' => $sample->crop_path !== null,
            'created_at' => $sample->created_at?->toIso8601String(),
        ])->values()->all();

        return [
            'product_id' => (int) $product->id,
            'name' => (string) $product->name,
            'sku' => $product->sku !== null ? (string) $product->sku : null,
            'count' => count($items),
            'samples' => $items,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | IMÁGENES POR PRODUCTO
    |--------------------------------------------------------------------------
    */

    /**
     * Imágenes con las que se siembra la galería para un producto.
     *
     * - Con variantes **y** fotos de variante emparejables: una entrada por foto
     *   de variante, sin la imagen general.
     * - Sin variantes, o con variantes pero sin fotos de variante: exactamente la
     *   primera imagen general.
     * - Defensivo: un producto con variantes cuyas fotos de variante no emparejan
     *   con ninguna y que no tiene foto general recibe su primera foto de variante
     *   (con `label` nulo) para no quedar sin imagen.
     *
     * Las URLs se deduplican dentro del producto.
     *
     * @return array<int, array{url: string, kind: string, label: string|null, variant_id: int|null}>
     */
    public function imagesFor(Product $product): array
    {
        $variants = $product->productAttributes;
        $variantMedia = $product->getMedia(self::VARIANT_COLLECTION);

        if ($variants->isNotEmpty() && $variantMedia->isNotEmpty()) {
            $images = $this->variantImages($variants, $variantMedia);

            if ($images !== []) {
                return $images;
            }
        }

        $main = $product->getMedia(self::GENERAL_COLLECTION)->first();

        if ($main !== null) {
            return [[
                'url' => (string) $main->getUrl(),
                'kind' => self::KIND_GENERAL,
                'label' => null,
                'variant_id' => null,
            ]];
        }

        $fallback = $variantMedia->first();

        if ($fallback !== null) {
            return [[
                'url' => (string) $fallback->getUrl(),
                'kind' => self::KIND_VARIANT,
                'label' => null,
                'variant_id' => null,
            ]];
        }

        return [];
    }

    /**
     * Una entrada por foto de variante emparejada, deduplicada por URL.
     *
     * @param  Collection<int, ProductAttribute>  $variants
     * @param  Collection<int, Media>  $media
     * @return array<int, array{url: string, kind: string, label: string|null, variant_id: int|null}>
     */
    private function variantImages(Collection $variants, Collection $media): array
    {
        $images = [];
        $seen = [];

        foreach ($media as $item) {
            $variant = $this->matchVariant($variants, $item);

            if ($variant === null) {
                continue;
            }

            $url = (string) $item->getUrl();

            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $images[] = [
                'url' => $url,
                'kind' => self::KIND_VARIANT,
                'label' => implode(' / ', array_values((array) $variant->attributes)),
                'variant_id' => (int) $variant->id,
            ];
        }

        return $images;
    }

    /**
     * Variante a la que pertenece una foto, con el mismo criterio que
     * `ProductCatalogService::variantCombinations()`.
     *
     * @param  Collection<int, ProductAttribute>  $variants
     */
    private function matchVariant(Collection $variants, Media $media): ?ProductAttribute
    {
        $key = $media->getCustomProperty('variant_key');
        $option = $media->getCustomProperty('variant_option');

        if ($key === null && $option === null) {
            return null;
        }

        foreach ($variants as $variant) {
            foreach ((array) $variant->attributes as $attribute => $value) {
                if ($key !== null && $key === $attribute.'_'.$value) {
                    return $variant;
                }

                if ($option !== null && $option === $value) {
                    return $variant;
                }
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAS
    |--------------------------------------------------------------------------
    */

    /**
     * Productos seleccionables de la sucursal: en su catálogo (`branch_product`),
     * visibles en el POS (`show_in_pos`) y con algún medio real en alguna de las
     * dos colecciones. El placeholder de `ProductCatalogService::payload()` no
     * cuenta porque no es un medio.
     */
    private function candidatesQuery(int $branchId): Builder
    {
        return Product::query()
            ->whereHas('branches', fn (Builder $query) => $query->where('branches.id', $branchId))
            ->where('show_in_pos', true)
            ->whereHas('media', fn (Builder $query) => $query->whereIn('collection_name', [
                self::GENERAL_COLLECTION,
                self::VARIANT_COLLECTION,
            ]));
    }

    /**
     * Acota la consulta a los productos con `pending > 0`, con las muestras
     * agrupadas por producto en un `LEFT JOIN` (una sola consulta agregada).
     */
    private function wherePending(Builder $query, int $subscriptionId, string $modelVersion): void
    {
        $sampleTotals = SmartPosProductSample::query()
            ->where('subscription_id', $subscriptionId)
            ->where('model_version', $modelVersion)
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as total');

        $query
            ->leftJoinSub($sampleTotals, 'sample_totals', fn ($join) => $join->on('sample_totals.product_id', '=', 'products.id'))
            ->whereRaw(
                $this->imagesCountExpression().' > COALESCE(sample_totals.total, 0)',
                $this->imagesCountBindings(),
            );
    }

    /**
     * Número de productos con `pending > 0` en toda la sucursal (contador de la
     * UI): **una** consulta agregada, nunca un recuento por producto.
     */
    private function totalPendingFor(int $branchId, int $subscriptionId, string $modelVersion): int
    {
        $query = $this->candidatesQuery($branchId);

        $this->wherePending($query, $subscriptionId, $modelVersion);

        return (int) $query->count();
    }

    /**
     * Recuento de muestras por producto de la página, en una sola consulta.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int> product_id => muestras
     */
    private function sampleCounts(int $subscriptionId, string $modelVersion, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return SmartPosProductSample::query()
            ->forSubscription($subscriptionId)
            ->forModelVersion($modelVersion)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as total')
            ->toBase()
            ->pluck('total', 'product_id')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Expresión SQL con el número de imágenes con las que se sembraría un
     * producto, equivalente a la regla de `imagesFor()`:
     *
     * - con variantes y fotos de variante emparejables → una por foto,
     * - en cualquier otro caso → 1 (la principal).
     *
     * "Emparejable" se aproxima exigiendo que la foto de variante lleve
     * `variant_key` o `variant_option` en sus propiedades (lo que escribe
     * `CreateProduct`); las fotos de variante sin esas propiedades no cuentan.
     * `CAST(... AS CHAR)` funciona igual en SQLite (pruebas) y en MySQL.
     */
    private function imagesCountExpression(): string
    {
        $variantImages = '(SELECT COUNT(*) FROM media vm'
            .' WHERE vm.model_id = products.id'
            .' AND vm.model_type = ?'
            .' AND vm.collection_name = ?'
            .' AND (CAST(vm.custom_properties AS CHAR) LIKE ? OR CAST(vm.custom_properties AS CHAR) LIKE ?))';

        $hasVariants = '(SELECT 1 FROM product_attributes pa WHERE pa.product_id = products.id)';

        // GREATEST no existe en SQLite y MAX(a, b) de dos argumentos no existe en
        // MySQL: cada motor usa su forma escalar.
        $atLeastOne = DB::connection()->getDriverName() === 'sqlite'
            ? 'MAX('.$variantImages.', 1)'
            : 'GREATEST('.$variantImages.', 1)';

        return 'CASE WHEN EXISTS '.$hasVariants.' THEN '.$atLeastOne.' ELSE 1 END';
    }

    /**
     * Enlaces de la expresión de `imagesCountExpression()`, en orden.
     *
     * @return array<int, string>
     */
    private function imagesCountBindings(): array
    {
        return [
            Product::class,
            self::VARIANT_COLLECTION,
            '%variant_key%',
            '%variant_option%',
        ];
    }

    /**
     * Producto vinculado a la sucursal: el mismo criterio que usa el catálogo del
     * POS (`branch_product`).
     */
    private function productBelongsToBranch(int $productId, int $branchId): bool
    {
        return Product::query()
            ->whereKey($productId)
            ->whereHas('branches', fn (Builder $query) => $query->where('branches.id', $branchId))
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Versión del modelo con la que se cuenta la galería: la pedida o la de los
     * ajustes del negocio (nunca la de otro tenant).
     */
    private function resolveModelVersion(int $subscriptionId, ?string $modelVersion): string
    {
        $requested = trim((string) $modelVersion);

        if ($requested !== '') {
            return $requested;
        }

        $configured = $this->settings->smartCheckoutSettingsForSubscription($subscriptionId)['model_version'] ?? null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : SmartPosSettingsService::DEFAULT_MODEL_VERSION;
    }

    /**
     * Suscripción del usuario autenticado: siempre desde el usuario.
     */
    private function subscriptionIdFor(User $user): int
    {
        $subscriptionId = (int) ($user->branch?->subscription_id ?? 0);

        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('El usuario no tiene una suscripción válida para construir la galería de Caja Inteligente.');
        }

        return $subscriptionId;
    }

    /**
     * Sucursal del usuario autenticado: siempre desde el usuario.
     */
    private function branchIdFor(User $user): int
    {
        $branchId = (int) ($user->branch_id ?? 0);

        if ($branchId <= 0) {
            throw new InvalidArgumentException('El usuario no tiene una sucursal válida para construir la galería de Caja Inteligente.');
        }

        return $branchId;
    }
}
