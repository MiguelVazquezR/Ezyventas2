<?php

namespace App\Services\SmartCheckout;

use App\Models\Product;
use App\Models\SmartPosProductSample;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Vector gallery of the "Modo Caja Inteligente" (Phase 3).
 *
 * The gallery is what lets the browser recognise a product **in memory**: it
 * downloads the embeddings of the tenant once, keeps them in a `Float32Array`
 * and searches by cosine similarity without another round trip per frame.
 *
 * Three responsibilities, all tenant-safe:
 *
 * 1. **Store** one sample: the vector is validated (dimension, exact length,
 *    finite components, L2 norm), normalised when needed and packed as
 *    little-endian float32. The ten samples per (product, model_version) limit is
 *    enforced by rotating the weakest one out, in the same transaction.
 * 2. **Read** the gallery as one binary batch: the buffers of the page are
 *    concatenated, `items[]` carries the byte offset/length of each one and the
 *    whole thing travels base64-encoded next to a derived `gallery_version`.
 * 3. **Delete** one sample, only within the tenant, together with its crop.
 *
 * Isolation rules (discovery §2): the subscription and the branch always come
 * from the authenticated user, never from the payload, and **every** query is
 * scoped by hand — the model is not protected by a global scope.
 *
 * ## Decisions taken here (the specification left them open)
 *
 * - **`gallery_version` is derived, never stored**: `COUNT(*)` + `MAX(id)` +
 *   `MAX(updated_at)` of the tenant's samples (see galleryVersionFor()). It
 *   changes on every insert and on every delete without a second write, and it
 *   cannot go stale. `MAX(id)` is added to `COUNT(*)` + `MAX(updated_at)` because
 *   inserting one sample and deleting another inside the same second leaves both
 *   the count and the timestamp untouched; the id does not.
 * - **The gallery is scoped by subscription, not by branch.** The vectors
 *   describe a model shared by the whole business and the specification asks for
 *   "los embeddings del tenant"; `galleryVersionFor()` is per subscription for the
 *   same reason. The branch of each sample is still stored for traceability and
 *   for the ownership check of a new sample. If a chain ever needs to recognise
 *   only what its own terminal sells, adding `->where('branch_id', ...)` to the
 *   gallery query is the whole change.
 * - **One dimension per model version.** A model has one output width, so mixing
 *   dims inside the same `model_version` would produce a `Float32Array` the
 *   browser cannot rebuild. Writes are rejected (422) when the dimension does not
 *   match the one already stored for that model version.
 * - **The 4 MiB page cap is measured on the base64 payload**, because that is what
 *   actually travels in the body (and what the terminal parses). With `dim = 384`
 *   a page holds 2 048 samples; `cursor` is the id of the last sample served. An
 *   installation with a small PHP `memory_limit` can lower the cap with
 *   `config('smartpos.gallery.max_page_bytes')`; there is no other configuration
 *   key in the gallery.
 * - **The crop is an optimisation, never a requirement**: if the business has
 *   `save_crops` off, an incoming crop is ignored (not written, not stored) and
 *   the sample keeps working. The switch is read from
 *   `smartpos_settings.smart_checkout_settings` — the JSON the POS preferences
 *   panel writes — and not from the homonymous `save_crops` column, which is the
 *   Phase 1 default and would be a second source of truth nobody edits. Retention
 *   is applied to the crop files only (purgeExpiredCrops()), never to the vectors.
 * - **The sample belongs to the user's branch, not just to the tenant**: the
 *   product must be attached to the branch in `branch_product` (the same
 *   criterion the POS catalog uses), because a vector of a product that terminal
 *   cannot sell would never match anything.
 */
class SmartCheckoutGalleryService
{
    /** Output width of the embedding model when the client does not say otherwise. */
    public const DIM_DEFAULT = 384;

    /** Smallest accepted width (the browser allocates `dim * 4` bytes per vector). */
    public const DIM_MIN = 128;

    /** Largest accepted width. */
    public const DIM_MAX = 1024;

    /** Samples kept per (tenant, product, model_version). */
    public const MAX_SAMPLES_PER_PRODUCT = 10;

    /** Model version used while the tenant has no sample and no preference. */
    public const DEFAULT_MODEL_VERSION = 'mock';

    /**
     * Accepted deviation of the L2 norm before the service normalises the vector
     * itself.
     */
    public const L2_TOLERANCE = 0.01;

    /**
     * Largest absolute component accepted. float32 tops out around 3.4e38, so
     * anything above 1e30 cannot be packed faithfully and is rejected instead of
     * being stored as an infinite value.
     */
    public const FLOAT32_MAX_ABS = 1.0e30;

    /**
     * Bytes of `vectors` (base64, as it travels) a single gallery page may carry.
     * Keeps the response around 4 MB so a terminal never buffers more than that.
     */
    public const MAX_PAGE_BYTES = 4 * 1024 * 1024;

    /** Largest crop accepted, in bytes (80 KB). */
    public const CROP_MAX_BYTES = 81920;

    /**
     * Largest `crop` string accepted, in characters: the base64 of CROP_MAX_BYTES
     * (4 × ⌈81920 / 3⌉ = 109 228) plus room for a `data:image/jpeg;base64,` prefix.
     * The FormRequest rejects anything longer before the body is decoded.
     */
    public const CROP_MAX_ENCODED_LENGTH = 109260;

    /** Largest crop side, in pixels. */
    public const CROP_MAX_SIDE = 224;

    /** Disk that holds the crops (`storage/app/private`). */
    public const CROP_DISK = 'local';

    /** Directory of the crops, relative to the private disk root. */
    public const CROP_DIRECTORY = 'smartpos/crops';

    /**
     * True once a read found the table missing. Keeps a single request from
     * hammering the database (and the log) with the same failing query.
     */
    private bool $tableUnavailable = false;

    public function __construct(
        private readonly SmartPosSettingsService $settings,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | GALERÍA (LECTURA)
    |--------------------------------------------------------------------------
    */

    /**
     * The gallery of the user's tenant, ready for the browser.
     *
     * The payload is:
     *
     * ```json
     * {
     *   "gallery_version": "10-42-1764218400",
     *   "model_version": "mock",
     *   "dim": 384,
     *   "count": 2,
     *   "total": 2,
     *   "items": [
     *     {"product_id": 5, "offset": 0, "length": 1536,
     *      "source": "catalog_image", "model_version": "mock", "quality": 0.91}
     *   ],
     *   "vectors": "<base64 del buffer concatenado, float32 little-endian>",
     *   "next_cursor": null
     * }
     * ```
     *
     * `offset` and `length` are **bytes** inside the binary buffer, so the browser
     * rebuilds each vector with `new Float32Array(bytes.slice(offset, offset +
     * length).buffer)` (1536 bytes / 4 = 384 floats).
     *
     * `count` is how many samples this page carries; `total` is how many the
     * tenant has for that model version, so the client knows when it has
     * downloaded everything even when `next_cursor` is not null. `next_cursor` is
     * the id of the last sample of the page (null on the last page).
     *
     * `model_version` is optional: when the client does not send it, the newest
     * model version of the tenant is served (falling back to the configured one
     * and then to `mock`). The response always says which one was served.
     *
     * `cursor` is the id of the last sample of the previous page. It is not
     * signed: every query is scoped by the tenant of the authenticated user, so a
     * forged cursor can only return an empty or partial page of the caller's own
     * gallery, never another business's rows.
     *
     * @return array<string, mixed>
     */
    public function galleryFor(User $user, ?string $modelVersion = null, ?int $cursor = null): array
    {
        $subscriptionId = $this->subscriptionIdFor($user);

        $modelVersion = $this->resolveModelVersion($subscriptionId, $modelVersion);

        if ($this->tableUnavailable) {
            return $this->emptyGallery($modelVersion);
        }

        try {
            $galleryVersion = $this->galleryVersionFor($subscriptionId);
            $dim = $this->dimFor($subscriptionId, $modelVersion);

            $scoped = SmartPosProductSample::query()
                ->forSubscription($subscriptionId)
                ->forModelVersion($modelVersion);

            $total = (clone $scoped)->count();

            $page = (clone $scoped);

            if ($cursor !== null) {
                $page->where('id', '>', $cursor);
            }

            $rows = $page->orderBy('id')
                ->limit($this->samplesPerPage($dim))
                // Plain rows, not models: a page can carry 2 048 samples of 1 536
                // bytes and wrapping each one in an Eloquent model would multiply
                // the memory of the request for nothing.
                ->toBase()
                ->get(['id', 'product_id', 'model_version', 'source', 'quality', 'dim', 'embedding']);
        } catch (QueryException $exception) {
            // Pending migration: the POS keeps working with an empty gallery
            // instead of throwing "table not found" (same degradation rule as
            // SmartPosSettingsService::forSubscription()).
            $this->tableUnavailable = true;

            Log::warning('No se pudo leer la galería de vectores de Caja Inteligente; se devuelve una galería vacía.', [
                'subscription_id' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);

            return $this->emptyGallery($modelVersion);
        }

        $buffer = '';
        $items = [];
        $bytesPerVector = $dim * 4;

        foreach ($rows as $row) {
            $embedding = $row->embedding ?? '';

            // Defensive: a row whose width does not match the model cannot be
            // concatenated (the browser would read floats from the wrong offsets).
            // The write path rejects that mix, so this only happens with rows
            // written by an older version.
            if (! is_string($embedding) || strlen($embedding) !== $bytesPerVector) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $row->product_id,
                'offset' => strlen($buffer),
                'length' => $bytesPerVector,
                'source' => (string) $row->source,
                'model_version' => (string) $row->model_version,
                'quality' => $row->quality !== null ? (float) $row->quality : null,
            ];

            $buffer .= $embedding;
        }

        $lastId = $rows->isEmpty() ? null : (int) $rows->last()->id;

        $hasMore = $lastId !== null
            && (clone $scoped)->where('id', '>', $lastId)->exists();

        return [
            'gallery_version' => $galleryVersion,
            'model_version' => $modelVersion,
            'dim' => $dim,
            'count' => count($items),
            'total' => $total,
            'items' => $items,
            'vectors' => base64_encode($buffer),
            'next_cursor' => $hasMore ? $lastId : null,
        ];
    }

    /**
     * Derived version of the tenant's gallery (see the class docblock).
     *
     * Format: `{count}-{max_id}-{unix timestamp of max(updated_at)}`, e.g.
     * `10-42-1764218400`; `0-0-0` when the tenant has no samples.
     */
    public function galleryVersionFor(int $subscriptionId): string
    {
        if ($subscriptionId <= 0) {
            return '0-0-0';
        }

        try {
            $row = SmartPosProductSample::query()
                ->forSubscription($subscriptionId)
                ->selectRaw('COUNT(*) as total, MAX(id) as last_id, MAX(updated_at) as last_updated')
                ->first();
        } catch (QueryException $exception) {
            $this->tableUnavailable = true;

            Log::warning('No se pudo calcular la versión de la galería de Caja Inteligente.', [
                'subscription_id' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);

            return '0-0-0';
        }

        $total = (int) ($row->total ?? 0);
        $lastId = (int) ($row->last_id ?? 0);
        $updatedAt = $row->last_updated ?? null;

        $timestamp = $updatedAt !== null ? Carbon::parse($updatedAt)->getTimestamp() : 0;

        return sprintf('%d-%d-%d', $total, $lastId, $timestamp);
    }

    /*
    |--------------------------------------------------------------------------
    | MUESTRAS (ESCRITURA)
    |--------------------------------------------------------------------------
    */

    /**
     * Stores one sample of a product.
     *
     * Contract of the validated payload (StoreProductSampleRequest):
     *
     * ```json
     * {
     *   "product_id": 5,
     *   "model_version": "mock",
     *   "dim": 384,
     *   "vector": [0.01, -0.02, 0.03, "… 384 valores …"],
     *   "source": "catalog_image",
     *   "quality": 0.91,
     *   "crop": "<base64 JPEG>"
     * }
     * ```
     *
     * `vector` is either the array of numbers or the base64 of the binary buffer.
     *
     * @param  array<string, mixed>  $data
     * @return array{sample: SmartPosProductSample, samples_for_product: int, gallery_version: string}
     *
     * @throws ValidationException when the payload is not usable (422 with Spanish messages).
     */
    public function storeSample(User $user, array $data): array
    {
        $subscriptionId = $this->subscriptionIdFor($user);
        $branchId = $this->branchIdFor($user);

        $productId = (int) ($data['product_id'] ?? 0);
        $modelVersion = trim((string) ($data['model_version'] ?? ''));
        $dim = (int) ($data['dim'] ?? self::DIM_DEFAULT);

        if ($productId <= 0) {
            throw ValidationException::withMessages([
                'product_id' => 'El producto es obligatorio.',
            ]);
        }

        if ($modelVersion === '') {
            throw ValidationException::withMessages([
                'model_version' => 'La versión del modelo es obligatoria.',
            ]);
        }

        if ($dim < self::DIM_MIN || $dim > self::DIM_MAX) {
            throw ValidationException::withMessages([
                'dim' => 'La dimensión del vector debe estar entre '.self::DIM_MIN.' y '.self::DIM_MAX.'.',
            ]);
        }

        // The tenant never comes from the payload: a terminal can only write
        // samples of the branch of the cashier that is authenticated.
        unset($data['subscription_id'], $data['branch_id']);

        $this->assertProductBelongsToBranch($productId, $branchId);
        $this->assertDimMatchesGallery($subscriptionId, $modelVersion, $dim);

        $values = $this->normalizeVector($this->decodeVector($data['vector'] ?? null, $dim));
        $embedding = $this->packVector($values);

        $saveCrops = (bool) ($this->settings->smartCheckoutSettingsForSubscription($subscriptionId)['save_crops'] ?? true);

        // The crop is written before the transaction and removed again when the
        // insert fails: a file outside the transaction cannot be rolled back.
        $cropPath = ($saveCrops && isset($data['crop']) && $data['crop'] !== null && $data['crop'] !== '')
            ? $this->writeCrop($subscriptionId, $productId, $this->decodeCrop((string) $data['crop']))
            : null;

        try {
            [$sample, $samplesForProduct, $evictedCrop] = DB::transaction(function () use (
                $subscriptionId,
                $branchId,
                $productId,
                $modelVersion,
                $dim,
                $embedding,
                $cropPath,
                $data,
            ): array {
                $evictedCrop = $this->rotateIfFull($subscriptionId, $productId, $modelVersion);

                $sample = SmartPosProductSample::create([
                    'subscription_id' => $subscriptionId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'model_version' => $modelVersion,
                    'embedding' => $embedding,
                    'dim' => $dim,
                    'source' => (string) $data['source'],
                    'crop_path' => $cropPath,
                    'quality' => isset($data['quality']) && $data['quality'] !== null
                        ? (float) $data['quality']
                        : null,
                ]);

                $samplesForProduct = SmartPosProductSample::query()
                    ->forSubscription($subscriptionId)
                    ->where('product_id', $productId)
                    ->forModelVersion($modelVersion)
                    ->count();

                return [$sample, $samplesForProduct, $evictedCrop];
            });
        } catch (\Throwable $exception) {
            if ($cropPath !== null) {
                $this->deleteCropFile($cropPath);
            }

            throw $exception;
        }

        // Best effort, outside the transaction: the database is already the
        // source of truth and a leftover file is harmless.
        if ($evictedCrop !== null) {
            $this->deleteCropFile($evictedCrop);
        }

        if ($cropPath !== null) {
            $this->purgeExpiredCrops($subscriptionId);
        }

        return [
            'sample' => $sample,
            'samples_for_product' => $samplesForProduct,
            'gallery_version' => $this->galleryVersionFor($subscriptionId),
        ];
    }

    /**
     * Deletes one sample of the tenant and its crop.
     *
     * Returns false when the sample belongs to another subscription; the caller
     * answers 404 in that case so the endpoint never confirms the existence of a
     * foreign row. (In production EnsureSubscriptionScope already rejects it with
     * 403 before the controller runs; this is the second line of defence and the
     * one the tests exercise, because they disable that middleware.)
     */
    public function deleteSample(User $user, SmartPosProductSample $sample): bool
    {
        $subscriptionId = $this->subscriptionIdFor($user);

        if ((int) $sample->subscription_id !== $subscriptionId) {
            return false;
        }

        $cropPath = $sample->crop_path;

        $sample->delete();

        if ($cropPath !== null) {
            $this->deleteCropFile((string) $cropPath);
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | VECTOR (DECODIFICACIÓN, NORMALIZACIÓN, EMPAQUETADO)
    |--------------------------------------------------------------------------
    */

    /**
     * Turns the payload vector into a list of floats of exactly $dim components.
     *
     * Accepts `number[]` or the base64 of the little-endian float32 buffer.
     *
     * @return array<int, float>
     *
     * @throws ValidationException
     */
    public function decodeVector(mixed $raw, int $dim): array
    {
        $expectedBytes = $dim * 4;

        if (is_string($raw)) {
            // Whitespace/newlines are tolerated: some clients wrap the base64.
            $encoded = preg_replace('/\s+/', '', $raw) ?? $raw;
            $decoded = $encoded !== '' ? base64_decode($encoded, true) : false;

            if ($decoded === false || $decoded === '') {
                throw ValidationException::withMessages([
                    'vector' => 'El vector no es un base64 válido ni una lista de números.',
                ]);
            }

            if (strlen($decoded) !== $expectedBytes) {
                throw ValidationException::withMessages([
                    'vector' => 'El vector debe ocupar exactamente '.$expectedBytes.' bytes ('.$dim.' valores float32); se recibieron '.strlen($decoded).'.',
                ]);
            }

            $values = array_values(unpack('g*', $decoded) ?: []);
        } elseif (is_array($raw)) {
            $values = array_values($raw);

            if (count($values) !== $dim) {
                throw ValidationException::withMessages([
                    'vector' => 'El vector debe contener exactamente '.$dim.' valores; se recibieron '.count($values).'.',
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'vector' => 'El vector es obligatorio: envía la lista de números o su base64.',
            ]);
        }

        foreach ($values as $index => $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > self::FLOAT32_MAX_ABS) {
                throw ValidationException::withMessages([
                    'vector' => 'El componente '.($index + 1).' del vector no es un número finito válido.',
                ]);
            }
        }

        return array_map(static fn ($value): float => (float) $value, $values);
    }

    /**
     * L2-normalises the vector.
     *
     * A vector whose norm is already within L2_TOLERANCE of 1 is stored untouched
     * (bit for bit, which keeps a repeated upload idempotent); anything else is
     * divided by its norm, so the browser can always use the dot product as the
     * cosine similarity.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     *
     * @throws ValidationException when the vector is zero or its norm overflows.
     */
    public function normalizeVector(array $values): array
    {
        $sumOfSquares = 0.0;

        foreach ($values as $value) {
            $sumOfSquares += $value * $value;
        }

        $norm = sqrt($sumOfSquares);

        // A zero vector has no direction and a norm of INF means the components
        // are too large to be normalised in double precision.
        if (! is_finite($norm) || $norm <= 0.0) {
            throw ValidationException::withMessages([
                'vector' => 'El vector no puede ser el vector cero ni contener valores fuera de rango.',
            ]);
        }

        if (abs($norm - 1.0) <= self::L2_TOLERANCE) {
            return $values;
        }

        return array_map(static fn (float $value): float => $value / $norm, $values);
    }

    /**
     * Packs the floats as little-endian float32, the layout `Float32Array` reads.
     *
     * @param  array<int, float>  $values
     */
    public function packVector(array $values): string
    {
        return pack('g*', ...$values);
    }

    /**
     * Decodes and validates the crop of a sample.
     *
     * The MIME type is sniffed from the bytes (`getimagesizefromstring`), never
     * trusted from the payload, and the image must be a JPEG of at most
     * 224x224 px and 80 KB, which is what the recognition model expects.
     *
     * @throws ValidationException
     */
    public function decodeCrop(string $encoded): string
    {
        $encoded = trim($encoded);

        // `canvas.toDataURL()` returns a data URI; accepting it saves the client
        // from stripping the prefix (and from getting it wrong).
        if (str_starts_with($encoded, 'data:')) {
            $comma = strpos($encoded, ',');

            if ($comma === false) {
                throw ValidationException::withMessages([
                    'crop' => 'El recorte no es una imagen JPEG en base64 válida.',
                ]);
            }

            $encoded = substr($encoded, $comma + 1);
        }

        $encoded = preg_replace('/\s+/', '', $encoded) ?? $encoded;
        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages([
                'crop' => 'El recorte no es una imagen JPEG en base64 válida.',
            ]);
        }

        if (strlen($bytes) > self::CROP_MAX_BYTES) {
            throw ValidationException::withMessages([
                'crop' => 'El recorte no puede superar '.intdiv(self::CROP_MAX_BYTES, 1024).' KB.',
            ]);
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ($info['mime'] ?? null) !== 'image/jpeg') {
            throw ValidationException::withMessages([
                'crop' => 'El recorte debe ser una imagen JPEG.',
            ]);
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width <= 0 || $height <= 0 || $width > self::CROP_MAX_SIDE || $height > self::CROP_MAX_SIDE) {
            throw ValidationException::withMessages([
                'crop' => 'El recorte no puede superar '.self::CROP_MAX_SIDE.'x'.self::CROP_MAX_SIDE.' px (recibido '.$width.'x'.$height.').',
            ]);
        }

        return $bytes;
    }

    /*
    |--------------------------------------------------------------------------
    | RECORTES Y RETENCIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * Writes the crop of a sample on the private disk and returns its **relative**
     * path (`smartpos/crops/{subscription_id}/{product_id}/{uuid}.jpg`).
     */
    public function writeCrop(int $subscriptionId, int $productId, string $bytes): string
    {
        $path = self::CROP_DIRECTORY.'/'.$subscriptionId.'/'.$productId.'/'.Str::uuid()->toString().'.jpg';

        if (! Storage::disk(self::CROP_DISK)->put($path, $bytes)) {
            throw new RuntimeException('No se pudo guardar el recorte de la muestra en el disco privado.');
        }

        return $path;
    }

    /**
     * Deletes the crops of the tenant older than `smartpos_settings.retention_days`.
     *
     * Only the **file** is removed and `crop_path` is nulled: the vector is kept
     * forever in this phase (the specification forbids deleting vectors by age).
     * The nulling uses the query builder on purpose, so `updated_at` — and with it
     * the derived gallery_version the browsers cache — does not change.
     *
     * The purge runs when a new crop is stored (which is when the directory can
     * grow) and from `smartpos:purge` on the daily schedule (Phase 5), so a tenant
     * that stopped seeding does not keep its old crops forever.
     *
     * @param  int|null  $retentionDays  overrides the tenant setting when positive
     *                                   (`smartpos:purge --days`); the criterion still
     *                                   lives here, callers only move the deadline
     * @return int number of crops purged
     */
    public function purgeExpiredCrops(int $subscriptionId, ?int $retentionDays = null): int
    {
        if ($subscriptionId <= 0) {
            return 0;
        }

        if ($retentionDays === null || $retentionDays <= 0) {
            $settings = $this->settings->forSubscription($subscriptionId);
            $retentionDays = (int) ($settings->retention_days ?? 0);
        }

        if ($retentionDays <= 0) {
            $retentionDays = SmartPosSettingsService::DEFAULT_RETENTION_DAYS;
        }

        $threshold = now()->subDays($retentionDays);

        try {
            $expired = SmartPosProductSample::query()
                ->forSubscription($subscriptionId)
                ->whereNotNull('crop_path')
                ->where('updated_at', '<', $threshold)
                ->get(['id', 'crop_path']);
        } catch (QueryException $exception) {
            $this->tableUnavailable = true;

            return 0;
        }

        $purged = 0;

        foreach ($expired as $sample) {
            if ($sample->crop_path === null) {
                continue;
            }

            $this->deleteCropFile((string) $sample->crop_path);

            // `toBase()` writes without touching the timestamps (and therefore
            // without changing the gallery_version).
            SmartPosProductSample::query()->whereKey($sample->id)->toBase()->update(['crop_path' => null]);

            $purged++;
        }

        return $purged;
    }

    /**
     * Removes a crop file. Never throws: a missing file must not fail a request.
     */
    private function deleteCropFile(string $path): void
    {
        try {
            Storage::disk(self::CROP_DISK)->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo borrar el recorte de una muestra de Caja Inteligente.', [
                'crop_path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LÍMITE Y ROTACIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * Frees one slot when the product already holds MAX_SAMPLES_PER_PRODUCT
     * samples of this model version, and returns the crop of the evicted sample
     * (so the caller can delete the file after the commit).
     *
     * **Rotation criterion**, in this order:
     *
     * 1. If any stored sample of the product has a `quality`, the one with the
     *    **lowest quality** is evicted (the panel can therefore push a weak
     *    sample out by uploading a better one); ties are broken by the oldest,
     *    i.e. the smallest id.
     * 2. If no sample has a quality (the usual case today), the **oldest** is
     *    evicted — smallest id, not `created_at`: ids are monotonic and unique up
     *    to the microsecond, and two samples of the same second must still have a
     *    deterministic order.
     *
     * The incoming sample is always stored: the rule is "keep the ten newest or
     * the ten best", never "drop the new one", because a correction made at the
     * counter must survive the next recognition.
     *
     * Must run inside the caller's transaction.
     */
    private function rotateIfFull(int $subscriptionId, int $productId, string $modelVersion): ?string
    {
        $samples = SmartPosProductSample::query()
            ->forSubscription($subscriptionId)
            ->where('product_id', $productId)
            ->forModelVersion($modelVersion)
            ->get(['id', 'quality', 'crop_path']);

        if ($samples->count() < self::MAX_SAMPLES_PER_PRODUCT) {
            return null;
        }

        $evicted = $this->evictionCandidate($samples);

        SmartPosProductSample::query()->whereKey($evicted->id)->delete();

        return $evicted->crop_path !== null ? (string) $evicted->crop_path : null;
    }

    /**
     * The sample that leaves the gallery when the product is full.
     *
     * @param  Collection<int, SmartPosProductSample>  $samples
     */
    private function evictionCandidate(Collection $samples): SmartPosProductSample
    {
        $withQuality = $samples->filter(
            static fn (SmartPosProductSample $sample): bool => $sample->quality !== null,
        );

        if ($withQuality->isNotEmpty()) {
            return $withQuality
                ->sortBy([
                    ['quality', 'asc'],
                    ['id', 'asc'],
                ])
                ->first();
        }

        return $samples->sortBy('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDACIONES DE PROPIEDAD Y CONSISTENCIA
    |--------------------------------------------------------------------------
    */

    /**
     * A vector can only be registered for a product the cashier's branch sells.
     *
     * The criterion is the one the POS catalog uses (`branch_product`), not
     * `products.branch_id`: a product that is not in the branch catalog cannot be
     * detected at that terminal, so its vector would be dead weight.
     *
     * @throws ValidationException
     */
    private function assertProductBelongsToBranch(int $productId, int $branchId): void
    {
        $belongs = Product::query()
            ->whereKey($productId)
            ->whereHas('branches', fn (Builder $query) => $query->where('branches.id', $branchId))
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'product_id' => 'El producto indicado no pertenece a la sucursal del cajero.',
            ]);
        }
    }

    /**
     * A model version has one output width: the first sample fixes it and the rest
     * must match, otherwise the concatenated buffer would be unusable.
     *
     * @throws ValidationException
     */
    private function assertDimMatchesGallery(int $subscriptionId, string $modelVersion, int $dim): void
    {
        $storedDim = SmartPosProductSample::query()
            ->forSubscription($subscriptionId)
            ->forModelVersion($modelVersion)
            ->orderBy('id')
            ->value('dim');

        if ($storedDim === null || (int) $storedDim === $dim) {
            return;
        }

        throw ValidationException::withMessages([
            'dim' => 'La galería de la versión «'.$modelVersion.'» usa vectores de '.(int) $storedDim.' dimensiones; no se pueden mezclar dimensiones.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS DE LECTURA
    |--------------------------------------------------------------------------
    */

    /**
     * Samples that fit in one page, given the width of the vectors.
     *
     * The cap is applied to the base64 payload (what travels) and not to the
     * binary buffer: 1536 bytes → 2 048 base64 chars → 2 048 samples per 4 MiB
     * page. The limit can be lowered per installation with
     * `config('smartpos.gallery.max_page_bytes')`; that is the only knob of the
     * gallery and it exists for hosts with a small PHP `memory_limit`, because a
     * page is built in memory before it is sent.
     */
    public function samplesPerPage(int $dim): int
    {
        $bytesPerVector = max(1, $dim * 4);
        $base64PerVector = 4 * (int) ceil($bytesPerVector / 3);

        $maxPageBytes = (int) config('smartpos.gallery.max_page_bytes', self::MAX_PAGE_BYTES);

        if ($maxPageBytes <= 0) {
            $maxPageBytes = self::MAX_PAGE_BYTES;
        }

        return max(1, intdiv($maxPageBytes, $base64PerVector));
    }

    /**
     * The model version to serve when the client does not choose one: the newest
     * one the tenant has samples for, then the configured one, then `mock`.
     */
    private function resolveModelVersion(int $subscriptionId, ?string $modelVersion): string
    {
        $requested = trim((string) $modelVersion);

        if ($requested !== '') {
            return $requested;
        }

        if (! $this->tableUnavailable) {
            try {
                $newest = SmartPosProductSample::query()
                    ->forSubscription($subscriptionId)
                    ->orderByDesc('id')
                    ->value('model_version');
            } catch (QueryException $exception) {
                $this->tableUnavailable = true;
                $newest = null;
            }

            if (is_string($newest) && $newest !== '') {
                return $newest;
            }
        }

        $configured = $this->settings->smartCheckoutSettingsForSubscription($subscriptionId)['model_version'] ?? null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::DEFAULT_MODEL_VERSION;
    }

    /**
     * Width of the vectors of a model version (DIM_DEFAULT while it has none).
     */
    private function dimFor(int $subscriptionId, string $modelVersion): int
    {
        $dim = SmartPosProductSample::query()
            ->forSubscription($subscriptionId)
            ->forModelVersion($modelVersion)
            ->orderByDesc('id')
            ->value('dim');

        return $dim !== null ? (int) $dim : self::DIM_DEFAULT;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGallery(string $modelVersion): array
    {
        return [
            'gallery_version' => '0-0-0',
            'model_version' => $modelVersion,
            'dim' => self::DIM_DEFAULT,
            'count' => 0,
            'total' => 0,
            'items' => [],
            'vectors' => '',
            'next_cursor' => null,
        ];
    }

    /**
     * Tenant of the authenticated user: always resolved from the user, never from
     * the request (discovery §2).
     */
    private function subscriptionIdFor(User $user): int
    {
        $subscriptionId = (int) ($user->branch?->subscription_id ?? 0);

        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('El usuario no tiene una suscripción válida para operar la galería de Caja Inteligente.');
        }

        return $subscriptionId;
    }

    /**
     * Branch of the authenticated user.
     */
    private function branchIdFor(User $user): int
    {
        $branchId = (int) ($user->branch_id ?? 0);

        if ($branchId <= 0) {
            throw new InvalidArgumentException('El usuario no tiene una sucursal válida para registrar muestras de Caja Inteligente.');
        }

        return $branchId;
    }
}
