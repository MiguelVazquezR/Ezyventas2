/**
 * Smart checkout session ("Modo Caja Inteligente").
 *
 * Orchestrates the recognition engine, the cashier decisions and the metrics of
 * the current sale. The engine and this module are only imported when the
 * cashier turns the mode on, so a POS with the mode off never downloads them.
 *
 * Nothing here writes to the cart by itself: every product goes through the
 * callbacks the POS page passes in (`onAdd`, `onWeigh`), which end up calling the
 * same `addToCart` the product cards use.
 *
 * Phase 4 adds the learning loop: every decision of the cashier can leave a
 * sample in the tenant's vector gallery (they are the only source of truth for
 * "this is what that product looks like on this counter"), and the catalog can be
 * seeded in batches of 50 with resume. The gallery itself is cached in the
 * terminal with its `ETag`, so turning the mode on again does not download
 * megabytes that did not change.
 */
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { mergeCameraDetections } from '@/Composables/smartCheckout/recognition/confidence.js';
import { buildSampleRequest, estimateQuality } from '@/Composables/smartCheckout/learning/samplePayload.js';
import { createGalleryCache } from '@/Composables/smartCheckout/learning/galleryCache.js';
import { createIndexedDbDriver } from '@/Composables/smartCheckout/learning/idbDriver.js';
import { createGallerySeeder } from '@/Composables/smartCheckout/learning/seedGallery.js';

const TERMINAL_KEY_PREFIX = 'smartpos_checkout';

/** Server-side limit of the gallery rotation (one product never keeps more). */
const MAX_SAMPLES_PER_PRODUCT = 10;

/**
 * How long after the last sample the gallery is re-downloaded. Seeding sends
 * dozens of samples in a row: coalescing them into one refresh is the difference
 * between one request and fifty.
 */
const GALLERY_REFRESH_DEBOUNCE_MS = 4000;

/**
 * A buffered frame older than this is not used to crop a sample: the object
 * probably moved (or the sale moved on) and a wrong crop is worse than no sample.
 */
const FRAME_MAX_AGE_MS = 8000;

export function smartCheckoutStorageKey(userId, branchId) {
    return `${TERMINAL_KEY_PREFIX}_${userId ?? 'guest'}_${branchId ?? 'none'}`;
}

export function readTerminalMode(userId, branchId) {
    try {
        const raw = localStorage.getItem(smartCheckoutStorageKey(userId, branchId));
        return raw ? JSON.parse(raw) : { active: false };
    } catch {
        return { active: false };
    }
}

export function writeTerminalMode(userId, branchId, state) {
    try {
        localStorage.setItem(smartCheckoutStorageKey(userId, branchId), JSON.stringify(state));
    } catch {
        /* storage disabled: the session keeps working in memory */
    }
}

/** Stable key for a detection, so repeated analyses do not duplicate work. */
function detectionKey(detection, index = 0) {
    const candidateId = detection?.candidates?.[0]?.productId ?? 'none';
    return `${detection?.cameraId ?? 'cam'}-${candidateId}-${index}`;
}

export function useSmartCheckout(options = {}) {
    const settings = ref(options.settings || null);
    const products = ref(options.products || []);
    const cameras = computed(() => settings.value?.cameras?.length ? settings.value.cameras : [{ id: 'cam-1', label: 'Cámara 1', mode: 'zone' }]);

    const active = ref(false);
    const status = ref('idle');
    const error = ref(null);
    const boxes = ref([]);
    const latency = ref(0);
    const modelVersion = ref(settings.value?.model_version || 'mock');
    // Which engine is actually running, and how the gallery sync went.
    const engineKind = ref('mock');
    const galleryVersion = ref(null);
    const galleryCount = ref(0);
    /**
     * Model version of the published embeddings (`index.json`), which is the one
     * samples must use: the settings default is "mock" on a fresh install, and a
     * sample tagged with the wrong version is silently useless.
     */
    const galleryModelVersion = ref(null);
    const camerasOnline = ref([]);
    // Why there is no real image (no camera, permission denied, no HTTPS...).
    const cameraWarnings = ref([]);
    const hasRealCamera = ref(false);

    const pendingYellow = ref([]);
    const pendingRed = ref([]);
    const handled = new Set();

    /**
     * Products the mode put in the cart, with the detection that produced them.
     * Used to recognise the cashier's correction when that line is removed.
     */
    const addedByMode = new Map();

    /** Samples currently in the gallery, per product (client-side cap of 10). */
    const sampleCounts = new Map();

    /** Phase 4: state of the learning loop, read by the panel. */
    const learning = reactive({
        seeding: false,
        pendingTotal: null,
        processed: 0,
        saved: 0,
        skipped: 0,
        failed: 0,
        current: null,
        samplesSent: 0,
        lastError: null,
        galleryFromCache: false,
        lastProgressAt: null,
    });

    const counters = reactive({ detected: 0, auto_accepted: 0, confirmed: 0, corrected: 0, unknown: 0 });
    const metrics = ref(null);
    const sessionId = ref(null);
    const startedAt = ref(null);
    const elapsedSeconds = ref(0);

    let engine = null;
    let flushTimer = null;
    let clockTimer = null;
    let flushInFlight = false;
    let galleryRefreshTimer = null;
    let galleryRefreshInFlight = null;
    let galleryCache = null;
    let seeder = null;
    const pendingTotals = { objects: 0, auto_accepted: 0, confirmed: 0, corrected: 0, unknown: 0, latency: 0, samples: 0 };

    const productById = (id) => products.value.find((product) => Number(product.id) === Number(id)) || null;

    const activeBoxes = computed(() => boxes.value);

    function statusLabel() {
        const labels = {
            idle: 'Apagado',
            loading: 'Cargando motor',
            ready: 'Listo',
            paused: 'En pausa',
            error: 'Error',
        };
        return labels[status.value] || status.value;
    }

    function pushYellow(detection, product) {
        const key = `${detection.cameraId}-${detection.candidates?.[0]?.productId}-y`;
        if (pendingYellow.value.some((row) => row.key === key)) return;
        pendingYellow.value = pendingYellow.value.concat({
            key,
            detection,
            product,
            requiresWeigh: Boolean(product?.is_bulk) && ['kg', 'kgs', 'g', 'gr', 'grs'].includes(String(product?.measure_unit || '').trim().toLowerCase()),
        });
    }

    function pushRed(detection, index) {
        const key = `${detection.cameraId}-red-${index}`;
        if (pendingRed.value.some((row) => row.key === key)) return;
        pendingRed.value = pendingRed.value.concat({ key, detection, barcode: detection.barcode || null });
        counters.unknown += 1;
        pendingTotals.unknown += 1;
    }

    function handleDetection(detections) {
        if (!Array.isArray(detections)) return;

        // Multi-camera rules of the spec live in confidence.js: "zone" cameras add
        // up, "overlap" cameras take the maximum and flag a discrepancy as yellow.
        const merged = mergeCameraDetections(detections, cameras.value, {
            thresholds: settings.value?.thresholds,
        });
        const list = merged.detections;

        boxes.value = list;
        counters.detected += list.length;
        pendingTotals.objects += list.length;

        const seen = new Set();

        list.forEach((detection, index) => {
            const key = detectionKey(detection, index);
            seen.add(key);
            const product = productById(detection.candidates?.[0]?.productId);
            const quantity = Number(detection.quantity) > 0 ? Number(detection.quantity) : 1;

            if (detection.status === 'green' && product) {
                if (handled.has(key)) return;
                handled.add(key);
                if (settings.value?.auto_accept === false) {
                    pushYellow(detection, product);
                    return;
                }
                counters.auto_accepted += 1;
                pendingTotals.auto_accepted += 1;
                markAddedByMode(product, detection);
                options.onAdd?.(product, { detection, source: 'auto', quantity });
                return;
            }

            if (detection.status === 'yellow') {
                if (product) pushYellow(detection, product);
                else pushRed(detection, index);
                return;
            }

            if (detection.status === 'red') {
                pushRed(detection, index);
            }
        });

        // A product that is no longer on the counter can be detected again.
        Array.from(handled).forEach((key) => {
            if (!seen.has(key)) handled.delete(key);
        });
    }

    async function refreshMetrics() {
        try {
            const { data } = await axios.get(route('pos.smart-checkout.metrics.today'));
            metrics.value = data.today || data;
        } catch {
            metrics.value = null;
        }
    }

    async function flushEvents({ force = false } = {}) {
        if (!force && flushInFlight) return;
        if (!sessionId.value) return;
        if (!pendingTotals.objects && !pendingTotals.auto_accepted && !pendingTotals.confirmed
            && !pendingTotals.corrected && !pendingTotals.unknown) return;

        const payload = {
            session_id: sessionId.value,
            event_type: 'analysis',
            objects: Math.min(255, pendingTotals.objects),
            auto_accepted: Math.min(255, pendingTotals.auto_accepted),
            confirmed: Math.min(255, pendingTotals.confirmed),
            corrected: Math.min(255, pendingTotals.corrected),
            unknown: Math.min(255, pendingTotals.unknown),
            latency_ms: latency.value ? Math.round(latency.value) : null,
            model_version: modelVersion.value,
        };

        pendingTotals.objects = 0;
        pendingTotals.auto_accepted = 0;
        pendingTotals.confirmed = 0;
        pendingTotals.corrected = 0;
        pendingTotals.unknown = 0;

        flushInFlight = true;
        try {
            await axios.post(route('pos.smart-checkout.events.store'), payload);
            await refreshMetrics();
        } catch (e) {
            // Metrics are best effort: never block the sale because of them.
            if (import.meta.env?.DEV) console.debug('smart-checkout: no se pudo registrar la métrica', e?.message);
        } finally {
            flushInFlight = false;
        }
    }

    async function buildEngine() {
        const recognition = await import('@/Composables/smartCheckout/recognition/index.js');

        // Real engine when this deployment has models published under /models.
        const onnxAvailable = await hasPublishedModels();
        if (onnxAvailable) {
            const gallery = await fetchGallery();
            const engine = await recognition.createOnnxRecognitionEngine({
                products: products.value,
                cameras: cameras.value,
                thresholds: settings.value?.thresholds,
                strategy: settings.value?.strategy || 'diff',
                gallery,
                modelBaseUrl: '/models',
                intervalMs: 1500,
            });
            engineKind.value = 'onnx';
            return engine;
        }

        engineKind.value = 'mock';
        return recognition.createRecognitionEngine('mock', {
            products: products.value,
            cameras: cameras.value,
            thresholds: settings.value?.thresholds,
            autoAccept: settings.value?.auto_accept,
            modelVersion: settings.value?.model_version || 'mock-1.0',
        });
    }

    /** `/models/index.json` describes both models; absence means "not deployed yet". */
    async function hasPublishedModels() {
        try {
            const response = await fetch('/models/index.json', { credentials: 'same-origin', cache: 'no-store' });
            return response.ok;
        } catch {
            return false;
        }
    }

    /** Version of the model that produced the published embeddings. */
    async function resolveGalleryModelVersion() {
        try {
            const response = await fetch('/models/index.json', { credentials: 'same-origin' });
            if (!response.ok) return settings.value?.model_version || null;
            const index = await response.json();
            return index?.gallery_model_version || settings.value?.model_version || null;
        } catch {
            return settings.value?.model_version || null;
        }
    }

    /** Persistent cache of the gallery, created on first use (IndexedDB). */
    function galleryCacheInstance() {
        if (!galleryCache) galleryCache = createGalleryCache(createIndexedDbDriver());
        return galleryCache;
    }

    /**
     * Cache key of this terminal. The gallery belongs to the subscription, but the
     * POS page only receives the branch and the user; two cashiers of the same
     * business keep separate copies, which is correct (just not deduplicated).
     */
    function galleryCacheContext(modelVersionValue) {
        return {
            subscriptionId: options.subscriptionId ?? options.userId ?? null,
            branchId: options.branchId ?? null,
            modelVersion: modelVersionValue || null,
        };
    }

    function applyGalleryMeta(payload) {
        if (!payload) return;
        galleryVersion.value = payload.gallery_version || null;
        galleryCount.value = Array.isArray(payload.items) ? payload.items.length : 0;
        if (payload.model_version) galleryModelVersion.value = payload.model_version;

        sampleCounts.clear();
        for (const item of payload.items || []) {
            const id = Number(item.product_id);
            if (!Number.isFinite(id)) continue;
            sampleCounts.set(id, (sampleCounts.get(id) || 0) + 1);
        }
    }

    /**
     * Downloads the tenant gallery (all pages) and keeps it searchable in memory.
     *
     * Phase 4 makes it incremental: the first page of the previous download is
     * revalidated with `If-None-Match`, and a `304` means nothing changed —the
     * `ETag` includes the `gallery_version`, which moves whenever a sample is
     * added, deleted or rotated— so the cached copy is reused without downloading
     * a single megabyte. A network failure falls back to the cache as well: the
     * mode keeps recognising with yesterday's gallery instead of nothing.
     *
     * @param {{force?: boolean}} [options]
     */
    async function fetchGallery({ force = false } = {}) {
        const galleryModelVersionValue = await resolveGalleryModelVersion();
        const cacheContext = galleryCacheContext(galleryModelVersionValue);
        const cache = galleryCacheInstance();
        const cached = force ? null : await cache.read(cacheContext);
        const isOk = (status) => (status >= 200 && status < 300) || status === 304;

        try {
            // The gallery paginates by cursor (~4 MiB per page). Each page carries
            // its own base64 blob with page-relative offsets, so the pages are
            // decoded to bytes and stitched together before decoding the vectors.
            const { base64ToBytes, bytesToBase64 } = await import('@/Composables/smartCheckout/inference/tensorUtils.js');

            let cursor = null;
            let first = null;
            let firstEtag = null;
            const chunks = [];
            const items = [];
            let byteLength = 0;

            // The gallery is stored per model version: ask for the version that
            // generated the published embeddings (index.json), not the settings
            // default (which is "mock" on a fresh install).
            const galleryModelVersionForRequest = galleryModelVersionValue;

            do {
                const response = await axios.get(route('pos.smart-checkout.gallery'), {
                    params: {
                        ...(galleryModelVersionForRequest ? { model_version: galleryModelVersionForRequest } : {}),
                        ...(cursor ? { cursor } : {}),
                    },
                    headers: cursor === null && cached?.etag ? { 'If-None-Match': cached.etag } : {},
                    validateStatus: isOk,
                });

                if (response.status === 304 && cached?.payload) {
                    // Nothing changed on the server since the last download.
                    applyGalleryMeta(cached.payload);
                    learning.galleryFromCache = true;
                    return cached.payload;
                }

                const data = response.data;

                if (!first) {
                    first = data;
                    firstEtag = response.headers?.etag ?? response.headers?.ETag ?? null;
                }
                const bytes = data?.vectors ? base64ToBytes(data.vectors) : new Uint8Array(0);

                for (const item of data?.items || []) {
                    items.push({ ...item, offset: byteLength + (Number(item.offset) || 0) });
                }

                if (bytes.length) chunks.push(bytes);
                byteLength += bytes.length;
                cursor = data?.next_cursor || null;
            } while (cursor && items.length < 4000);

            if (!first) return null;

            const merged = new Uint8Array(byteLength);
            let position = 0;
            for (const chunk of chunks) {
                merged.set(chunk, position);
                position += chunk.length;
            }

            const payload = {
                gallery_version: first.gallery_version,
                model_version: first.model_version,
                dim: first.dim,
                items,
                vectors: bytesToBase64(merged),
            };

            applyGalleryMeta(payload);
            learning.galleryFromCache = false;

            // Storing is best effort: a full disk or a private window leaves the
            // mode working, it only has to download everything next time.
            void cache.write(cacheContext, payload, firstEtag);

            return payload;
        } catch (error) {
            if (cached?.payload) {
                applyGalleryMeta(cached.payload);
                learning.galleryFromCache = true;
                return cached.payload;
            }
            if (import.meta.env?.DEV) console.debug('smart-checkout: no se pudo leer la galería', error?.message);
            return null;
        }
    }

    /** Wires the engine events (both engines share the same contract). */
    function wireEngine(instance) {
        instance.onDetection?.((detections) => handleDetection(detections));
        instance.onStatus?.((payload) => {
            status.value = payload?.status || status.value;
            if (Array.isArray(payload?.cameras)) camerasOnline.value = payload.cameras;
            if (payload?.galleryCount !== undefined) galleryCount.value = payload.galleryCount;
            if (Array.isArray(payload?.warnings)) cameraWarnings.value = payload.warnings;
            if (payload?.hasRealCamera !== undefined) hasRealCamera.value = Boolean(payload.hasRealCamera);
        });
        instance.onError?.((payload) => {
            error.value = payload?.message || 'El motor de reconocimiento reportó un error.';
            status.value = 'error';
        });
        camerasOnline.value = instance.cameraList || camerasOnline.value;
    }

    async function calibrate() {
        if (!engine?.calibrate) {
            error.value = 'La calibración solo está disponible con cámara real.';
            return false;
        }
        const ok = await engine.calibrate(cameras.value[0]?.id);
        if (ok) toastCalibration();
        return ok;
    }

    function toastCalibration() {
        options.onCalibrated?.();
    }

    /** Mounts the preview of the first camera into a <video> element. */
    function attachPreview(element) {
        if (!engine?.attachPreview) return false;
        return engine.attachPreview(cameras.value[0]?.id, element);
    }

    async function refreshGallery({ force = false } = {}) {
        const gallery = await fetchGallery({ force });
        if (gallery) engine?.setGallery?.(gallery);
        return gallery;
    }

    /* --------------------------------------------------- aprendizaje (Fase 4) */

    /**
     * Coalesces the gallery re-download after one or more new samples.
     *
     * Seeding sends dozens of samples in a row; without this every one of them
     * would trigger a multi-megabyte refresh. The engine keeps recognising with
     * the previous copy meanwhile.
     */
    function scheduleGalleryRefresh({ immediate = false } = {}) {
        if (galleryRefreshTimer) clearTimeout(galleryRefreshTimer);

        const run = () => {
            galleryRefreshTimer = null;
            if (galleryRefreshInFlight) return;
            galleryRefreshInFlight = refreshGallery()
                .catch(() => null)
                .finally(() => {
                    galleryRefreshInFlight = null;
                });
        };

        if (immediate) {
            run();
            return;
        }

        galleryRefreshTimer = setTimeout(run, GALLERY_REFRESH_DEBOUNCE_MS);
    }

    /** Samples the gallery currently holds for a product. */
    function sampleCountFor(productId) {
        const id = Number(productId);
        return Number.isFinite(id) ? sampleCounts.get(id) || 0 : 0;
    }

    /**
     * 224x224 JPEG data URI of a bitmap, the size and MIME the API accepts.
     * Returns null when there is no DOM (the sample is then sent without a crop).
     */
    function jpegCropFromBitmap(bitmap, size = 224) {
        if (!bitmap || typeof document === 'undefined') return null;

        try {
            const width = Number(bitmap.width) || 0;
            const height = Number(bitmap.height) || 0;
            if (!width || !height) return null;

            const side = Math.max(1, Math.min(width, height));
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const context = canvas.getContext('2d');
            context.drawImage(
                bitmap,
                Math.round((width - side) / 2), Math.round((height - side) / 2), side, side,
                0, 0, size, size,
            );

            return canvas.toDataURL('image/jpeg', 0.8);
        } catch {
            return null;
        }
    }

    /**
     * Stores one sample in the tenant gallery from what the camera saw.
     *
     * Buffered frame → crop of the detection box → DINOv2 embedding in the worker
     * → `POST /samples` → coalesced gallery refresh.
     *
     * Every step can fail without consequence: the caller has already put the
     * product in the cart, so "this sample was not stored" is the whole cost. It
     * never throws.
     *
     * @param {{productId: number, source: string, detection?: object|null, box?: number[]|null, quality?: number|null}} params
     * @returns {Promise<{ok: boolean, reason?: string, sample?: object, retryAfterMs?: number}>}
     */
    async function persistSample({ productId, source, detection = null, box = null, quality = null }) {
        const id = Number(productId);
        if (!Number.isFinite(id) || id <= 0) return { ok: false, reason: 'invalid_product' };

        // The simulated engine has neither pixels nor an ONNX worker.
        if (!engine || engineKind.value !== 'onnx' || typeof engine.addSample !== 'function') {
            return { ok: false, reason: 'engine_without_pixels' };
        }

        const modelVersionForSample = galleryModelVersion.value;
        if (!modelVersionForSample || modelVersionForSample === 'mock') {
            return { ok: false, reason: 'no_model_version' };
        }

        // Client-side cap of ten per product. A correction is the exception: it is
        // the most valuable sample of the module and the server rotates the
        // weakest one on purpose.
        if (source !== 'cashier_correction' && sampleCountFor(id) >= MAX_SAMPLES_PER_PRODUCT) {
            return { ok: false, reason: 'limit_reached' };
        }

        const boxToCrop = detection?.box || box || null;
        const cameraId = detection?.cameraId ?? null;
        const frame = engine.frameInfo?.(cameraId) || null;

        // A stale frame means the product already moved: a wrong crop would poison
        // the gallery, so nothing is stored.
        if (!boxToCrop || !frame || Number(frame.ageMs) > FRAME_MAX_AGE_MS) {
            return { ok: false, reason: 'no_recent_frame' };
        }

        const bitmap = await engine.captureCrop(cameraId, boxToCrop);
        if (!bitmap) return { ok: false, reason: 'no_crop' };

        // `addSample()` transfers the bitmap to the worker, so the crop is taken
        // from it first.
        const crop = settings.value?.save_crops === false ? null : jpegCropFromBitmap(bitmap);
        const sampleQuality = quality ?? estimateQuality({
            box: boxToCrop,
            frameWidth: frame.width,
            frameHeight: frame.height,
        });

        try {
            const result = await engine.addSample(id, bitmap, source);
            if (!result?.vector) {
                learning.lastError = 'El motor no devolvió el vector de la muestra.';
                return { ok: false, reason: 'no_vector' };
            }

            const built = buildSampleRequest({
                productId: id,
                modelVersion: modelVersionForSample,
                dim: result.dim,
                vector: result.vector,
                source,
                quality: sampleQuality,
                crop,
            });

            if (!built.ok) {
                learning.lastError = Object.values(built.errors || {})[0] || 'La muestra no es válida.';
                return { ok: false, reason: 'invalid_payload', errors: built.errors };
            }

            const { data } = await axios.post(route('pos.smart-checkout.samples.store'), built.value);

            sampleCounts.set(id, sampleCountFor(id) + 1);
            learning.samplesSent += 1;
            learning.lastError = null;
            pendingTotals.samples += 1;
            scheduleGalleryRefresh();

            return { ok: true, sample: data };
        } catch (error) {
            const status = error?.response?.status;
            learning.lastError = error?.response?.data?.message
                || error?.message
                || 'No se pudo guardar la muestra.';

            if (import.meta.env?.DEV) console.debug('smart-checkout: muestra no guardada', learning.lastError);

            if (status === 429) {
                const retryAfter = Number(error?.response?.headers?.['retry-after']) || 0;
                return { ok: false, reason: 'throttled', retryAfterMs: retryAfter > 0 ? retryAfter * 1000 : 60000 };
            }

            return { ok: false, reason: 'request_failed' };
        }
    }

    /** Remembers that the mode is what put that product in the cart. */
    function markAddedByMode(product, detection) {
        const id = Number(product?.id ?? 0);
        if (!id) return;
        addedByMode.set(id, { detection: detection || null, at: Date.now() });
    }

    /**
     * The cashier removed a line the mode had added: that is a correction.
     *
     * The POS page calls this from its own `removeCartItem`, which is the only
     * place that knows a line left the cart. Closing a sale replaces the whole
     * array instead of removing items, so a sale never reports here.
     *
     * @returns {boolean} whether the removal was a correction of the mode
     */
    function reportCartRemoval(product) {
        if (!active.value) return false;

        const id = Number(product?.id ?? product?.product_id ?? 0);
        if (!id) return false;

        const entry = addedByMode.get(id);
        if (!entry) return false;

        addedByMode.delete(id);
        counters.corrected += 1;
        pendingTotals.corrected += 1;

        // The crop of the detection that was wrong is exactly what the gallery
        // needs to learn from.
        void persistSample({ productId: id, source: 'cashier_correction', detection: entry.detection });

        return true;
    }

    /* ------------------------------------------------- sembrado del catálogo */

    /** Loads an image of the catalog as an `ImageBitmap` (same origin, public disk). */
    async function downloadImageBitmap(url) {
        if (typeof createImageBitmap !== 'function') return null;

        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) return null;
            return await createImageBitmap(await response.blob());
        } catch {
            // A CDN without CORS, an image that moved or an offline terminal: the
            // product is skipped and the rest of the batch keeps going.
            return null;
        }
    }

    /**
     * Embeds and stores one catalog image as a `catalog_image` sample.
     * The `{ok, skipped, retryAfterMs}` contract is what `seedGallery.js` expects.
     */
    async function seedOne(item, image) {
        if (!engine || engineKind.value !== 'onnx' || typeof engine.addSample !== 'function') {
            return { ok: false, skipped: 'engine_without_pixels' };
        }

        const modelVersionForSample = galleryModelVersion.value;
        if (!modelVersionForSample || modelVersionForSample === 'mock') {
            return { ok: false, skipped: 'no_model_version' };
        }

        const productId = Number(item?.product_id);
        if (!Number.isFinite(productId) || productId <= 0) return { ok: false, skipped: 'invalid_product' };

        if (sampleCountFor(productId) >= MAX_SAMPLES_PER_PRODUCT) {
            return { ok: false, skipped: 'limit_reached' };
        }

        const bitmap = await downloadImageBitmap(image?.url);
        if (!bitmap) return { ok: false, skipped: 'image_unavailable' };

        const crop = settings.value?.save_crops === false ? null : jpegCropFromBitmap(bitmap);

        try {
            const result = await engine.addSample(productId, bitmap, 'catalog_image');
            if (!result?.vector) return { ok: false, skipped: 'no_vector' };

            const built = buildSampleRequest({
                productId,
                modelVersion: modelVersionForSample,
                dim: result.dim,
                vector: result.vector,
                source: 'catalog_image',
                // Nothing is known about the quality of a product photo.
                quality: null,
                crop,
            });

            if (!built.ok) {
                learning.lastError = Object.values(built.errors || {})[0] || 'La muestra del catálogo no es válida.';
                return { ok: false, skipped: 'invalid_payload' };
            }

            await axios.post(route('pos.smart-checkout.samples.store'), built.value);

            sampleCounts.set(productId, sampleCountFor(productId) + 1);
            learning.samplesSent += 1;
            pendingTotals.samples += 1;

            return { ok: true };
        } catch (error) {
            const status = error?.response?.status;
            learning.lastError = error?.response?.data?.message || error?.message || 'No se pudo guardar la muestra del catálogo.';

            if (status === 429) {
                const retryAfter = Number(error?.response?.headers?.['retry-after']) || 0;
                return { ok: false, retryAfterMs: retryAfter > 0 ? retryAfter * 1000 : 60000 };
            }

            return { ok: false, message: learning.lastError };
        }
    }

    function applySeedProgress(progress) {
        if (!progress) return;

        if (progress.processed !== undefined) learning.processed = progress.processed;
        if (progress.saved !== undefined) learning.saved = progress.saved;
        if (progress.skipped !== undefined) learning.skipped = progress.skipped;
        if (progress.failed !== undefined) learning.failed = progress.failed;
        if (progress.totalPending !== undefined && progress.totalPending !== null) learning.pendingTotal = progress.totalPending;
        if (progress.current !== undefined) learning.current = progress.current;

        learning.lastProgressAt = progress.at || Date.now();

        if (progress.phase === 'waiting') {
            learning.lastError = `El servidor pidió esperar ${Math.max(1, Math.round((Number(progress.retryInMs) || 0) / 1000))} s.`;
        }
        if (progress.phase === 'done') learning.current = null;
    }

    function seedReasonMessage(reason) {
        const messages = {
            fetch_failed: 'No se pudo leer el catálogo. Revisa la conexión e inténtalo otra vez.',
            busy: 'Ya hay un sembrado en curso.',
            budget: 'Se alcanzó el máximo de páginas por corrida; vuelve a lanzarlo para continuar.',
        };
        return messages[reason] || null;
    }

    /** Lazily built: the cursor key includes the resolved model version. */
    function seederInstance() {
        if (seeder) return seeder;

        seeder = createGallerySeeder({
            fetchCandidates: async ({ afterId, limit, filter }) => {
                const { data } = await axios.get(route('pos.smart-checkout.gallery.candidates'), {
                    params: {
                        limit,
                        filter,
                        ...(afterId ? { after_id: afterId } : {}),
                        ...(galleryModelVersion.value ? { model_version: galleryModelVersion.value } : {}),
                    },
                });

                return data;
            },
            saveSample: seedOne,
            storage: typeof localStorage !== 'undefined' ? localStorage : null,
            context: {
                subscriptionId: options.subscriptionId ?? options.userId ?? null,
                branchId: options.branchId ?? null,
                modelVersion: galleryModelVersion.value || null,
            },
            onProgress: applySeedProgress,
            batchSize: 50,
        });

        return seeder;
    }

    /**
     * How many products of the catalog still have no samples (badge of the panel).
     * It needs the published model version, which is also cached here.
     */
    async function refreshPendingCount() {
        try {
            if (!galleryModelVersion.value) galleryModelVersion.value = await resolveGalleryModelVersion();

            const { data } = await axios.get(route('pos.smart-checkout.gallery.candidates'), {
                params: {
                    limit: 1,
                    filter: 'pending',
                    ...(galleryModelVersion.value ? { model_version: galleryModelVersion.value } : {}),
                },
            });

            learning.pendingTotal = Number.isFinite(Number(data?.total_pending)) ? Number(data.total_pending) : null;
        } catch {
            learning.pendingTotal = null;
        }

        return learning.pendingTotal;
    }

    /**
     * Seeds the catalog gallery in batches of 50 with resume.
     *
     * @param {{resume?: boolean}} [options]
     */
    async function startSeeding({ resume = true } = {}) {
        if (learning.seeding) return null;

        if (engineKind.value !== 'onnx') {
            learning.lastError = 'El sembrado necesita el motor real: publica los modelos en /models y vuelve a activar el modo.';
            return null;
        }

        if (!galleryModelVersion.value) galleryModelVersion.value = await resolveGalleryModelVersion();
        if (!galleryModelVersion.value || galleryModelVersion.value === 'mock') {
            learning.lastError = 'No hay una versión de modelo publicada para etiquetar las muestras.';
            return null;
        }

        learning.seeding = true;
        learning.processed = 0;
        learning.saved = 0;
        learning.skipped = 0;
        learning.failed = 0;
        learning.current = null;
        learning.lastError = null;

        try {
            const result = await seederInstance().run({ resume });

            if (result?.reason && result.reason !== 'cancelled') {
                learning.lastError = seedReasonMessage(result.reason) || learning.lastError;
            }

            return result;
        } catch (error) {
            learning.lastError = error?.message || 'El sembrado se detuvo por un error.';
            return null;
        } finally {
            learning.seeding = false;
            learning.current = null;
            scheduleGalleryRefresh({ immediate: true });
        }
    }

    function cancelSeeding() {
        seeder?.cancel?.();
    }

    /** Samples stored for one product (management view of the panel). */
    async function loadProductSamples(productId) {
        const { data } = await axios.get(route('pos.smart-checkout.gallery.product', { product: Number(productId) }));
        return data;
    }

    /** Deletes one sample of the tenant and refreshes the engine gallery. */
    async function deleteSample(sampleId) {
        await axios.delete(route('pos.smart-checkout.samples.destroy', { sample: Number(sampleId) }));
        // The exact per-product counts come back with the refresh below.
        scheduleGalleryRefresh({ immediate: true });
        return true;
    }

    async function activate() {
        if (active.value) return true;
        error.value = null;
        status.value = 'loading';
        try {
            engine = await buildEngine();
            wireEngine(engine);
            await engine.init({ galleryVersion: galleryVersion.value || 'mock' });
            sessionId.value = crypto?.randomUUID?.() || `s-${Date.now()}-${Math.round(Math.random() * 1e6)}`;
            startedAt.value = Date.now();
            counters.detected = 0;
            counters.auto_accepted = 0;
            counters.confirmed = 0;
            counters.corrected = 0;
            counters.unknown = 0;
            pendingYellow.value = [];
            pendingRed.value = [];
            handled.clear();
            addedByMode.clear();
            engine.start?.();
            active.value = true;
            status.value = 'ready';
            writeTerminalMode(options.userId, options.branchId, { active: true });
            clockTimer = setInterval(() => {
                if (startedAt.value) elapsedSeconds.value = Math.floor((Date.now() - startedAt.value) / 1000);
            }, 1000);
            flushTimer = setInterval(() => void flushEvents(), 5000);
            void refreshMetrics();
            return true;
        } catch (e) {
            error.value = e?.message || 'No se pudo iniciar el Modo Caja Inteligente.';
            status.value = 'error';
            engine = null;
            return false;
        }
    }

    async function deactivate() {
        if (flushTimer) clearInterval(flushTimer);
        if (clockTimer) clearInterval(clockTimer);
        if (galleryRefreshTimer) clearTimeout(galleryRefreshTimer);
        flushTimer = null;
        clockTimer = null;
        galleryRefreshTimer = null;
        await flushEvents({ force: true });
        try {
            engine?.stop?.();
            engine?.dispose?.();
        } catch {
            /* already disposed */
        }
        engine = null;
        active.value = false;
        status.value = 'idle';
        boxes.value = [];
        pendingYellow.value = [];
        pendingRed.value = [];
        handled.clear();
        addedByMode.clear();
        writeTerminalMode(options.userId, options.branchId, { active: false });
    }

    async function toggle() {
        return active.value ? (await deactivate(), false) : await activate();
    }

    function pause() {
        engine?.pause?.();
        status.value = 'paused';
    }

    function resume() {
        engine?.resume?.();
        status.value = 'ready';
    }

    /**
     * Cashier confirmed a candidate (yellow).
     *
     * If the chosen product is not the top candidate the cashier has *corrected*
     * the recognition: that is a `corrected` of the day and the crop of the
     * detection is the best sample the module can get ("this is what the counter
     * looks like when it is this other product").
     */
    function confirm(row, product) {
        if (!row) return;
        const chosen = product || row.product;

        const topCandidateId = Number(row.detection?.candidates?.[0]?.productId ?? 0);
        const chosenId = Number(chosen?.id ?? 0);
        const isCorrection = chosenId > 0 && topCandidateId > 0 && chosenId !== topCandidateId;

        pendingYellow.value = pendingYellow.value.filter((item) => item.key !== row.key);

        if (isCorrection) {
            counters.corrected += 1;
            pendingTotals.corrected += 1;
            void persistSample({ productId: chosenId, source: 'cashier_correction', detection: row.detection });
        } else {
            counters.confirmed += 1;
            pendingTotals.confirmed += 1;
        }

        const quantity = Number(row.detection?.quantity) > 0 ? Number(row.detection.quantity) : 1;
        if (chosen) {
            markAddedByMode(chosen, row.detection);
            options.onAdd?.(chosen, { detection: row.detection, source: 'confirmed', quantity });
        }
    }

    /** Cashier rejected every candidate: it becomes an unknown product. */
    function reject(row) {
        pendingYellow.value = pendingYellow.value.filter((item) => item.key !== row.key);
        pendingRed.value = pendingRed.value.concat({ key: `${row.key}-unknown`, detection: row.detection });
        counters.unknown += 1;
        pendingTotals.unknown += 1;
    }

    /**
     * Unknown product resolved by barcode, search or quick registration.
     *
     * The cashier just told us what that thing on the counter is, so the crop of
     * the detection is stored as a sample (`barcode_scan` / `quick_register`) and
     * the gallery of the engine is refreshed in the background.
     */
    function resolveUnknown(row, product, { source = 'barcode_scan', barcode = null } = {}) {
        pendingRed.value = pendingRed.value.filter((item) => item.key !== row.key);
        if (!product) return;

        const productId = Number(product.id);
        const detection = row?.detection || null;
        const acceptedSource = source === 'quick_register' ? 'quick_register' : 'barcode_scan';

        // `engine.addSample()` on the mock engine has no vector to give: the
        // session decides, `persistSample` checks the engine kind and counts.
        void persistSample({ productId, source: acceptedSource, detection });

        markAddedByMode(product, detection);
        options.onAdd?.(product, { source, barcode });
    }

    function dismissUnknown(row) {
        pendingRed.value = pendingRed.value.filter((item) => item.key !== row.key);
    }

    /** Demo helpers of this phase (no real camera yet). */
    function forceDetection(kind) {
        try {
            engine?.forceDetection?.(kind);
        } catch {
            /* engine without demo hooks */
        }
    }

    /** Called by the page once the sale has been registered. */
    async function onSaleClosed(saleId) {
        if (!active.value || !sessionId.value) return;
        const duration = startedAt.value ? Math.max(1, Math.round((Date.now() - startedAt.value) / 1000)) : null;
        await flushEvents({ force: true });
        try {
            await axios.post(route('pos.smart-checkout.events.store'), {
                session_id: sessionId.value,
                event_type: 'sale',
                sale_id: saleId || null,
                duration_seconds: duration,
                model_version: modelVersion.value,
                objects: 0,
                auto_accepted: Math.min(255, counters.auto_accepted),
                confirmed: Math.min(255, counters.confirmed),
                corrected: Math.min(255, counters.corrected),
                unknown: Math.min(255, counters.unknown),
            });
        } catch {
            /* best effort */
        }
        await refreshMetrics();
        counters.detected = 0;
        counters.auto_accepted = 0;
        counters.confirmed = 0;
        counters.corrected = 0;
        counters.unknown = 0;
        // The sale is closed: its lines are no longer "added by the mode".
        addedByMode.clear();
        elapsedSeconds.value = 0;
        startedAt.value = Date.now();
    }

    function setProducts(list) {
        products.value = list || [];
        engine?.setProducts?.(products.value);
    }

    function setSettings(next) {
        settings.value = next || null;
        if (engine && next) engine.setThresholds?.(next.thresholds);
    }

    // Cleanup is owned by the page (onBeforeUnmount → deactivate) on purpose:
    // this module stays framework-agnostic so it can be reasoned about alone.

    return {
        // state
        settings,
        cameras,
        active,
        status,
        error,
        boxes: activeBoxes,
        latency,
        modelVersion,
        engineKind,
        galleryVersion,
        galleryCount,
        galleryModelVersion,
        camerasOnline,
        cameraWarnings,
        hasRealCamera,
        counters,
        metrics,
        sessionId,
        startedAt,
        elapsedSeconds,
        pendingYellow,
        pendingRed,
        // actions
        activate,
        deactivate,
        toggle,
        pause,
        resume,
        confirm,
        reject,
        resolveUnknown,
        dismissUnknown,
        forceDetection,
        onSaleClosed,
        refreshMetrics,
        flushEvents,
        setProducts,
        setSettings,
        statusLabel,
        // camera / models (Phase 3)
        calibrate,
        attachPreview,
        refreshGallery,
        // learning (Phase 4)
        learning,
        persistSample,
        reportCartRemoval,
        startSeeding,
        cancelSeeding,
        refreshPendingCount,
        loadProductSamples,
        deleteSample,
        sampleCountFor,
    };
}
