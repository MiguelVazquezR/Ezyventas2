/**
 * Phase 4 learning helpers: the persistent gallery cache (with an injected
 * storage driver), its IndexedDB / in-memory drivers, the sample payload builder
 * for `POST /samples` and the crop geometry used to feed the embedder.
 *
 * Everything is pure JavaScript: no browser, no canvas, no real IndexedDB. The
 * suite covers the degradation paths (quota, corrupted entries, expired entries)
 * because a cache must never be able to break the POS.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
  GALLERY_CACHE_PREFIX,
  createGalleryCache,
  galleryCacheKey,
} from '../../../resources/js/Composables/smartCheckout/learning/galleryCache.js';
import {
  createIndexedDbDriver,
  createMemoryDriver,
} from '../../../resources/js/Composables/smartCheckout/learning/idbDriver.js';
import {
  SAMPLE_SOURCES,
  buildSampleRequest,
  estimateQuality,
  sizeOfBase64,
} from '../../../resources/js/Composables/smartCheckout/learning/samplePayload.js';
import { cropBoxToSquare } from '../../../resources/js/Composables/smartCheckout/learning/cropUtils.js';
import { base64ToFloat32, float32ToBase64 } from '../../../resources/js/Composables/smartCheckout/inference/tensorUtils.js';

const DAY_MS = 24 * 60 * 60 * 1000;

/** A gallery payload with the same shape `GET /gallery` returns. */
function galleryPayload(overrides = {}) {
    return {
        gallery_version: '2-9-1764218400',
        model_version: 'mock',
        dim: 384,
        count: 0,
        total: 0,
        items: [],
        vectors: '',
        next_cursor: null,
        ...overrides,
    };
}

/* ------------------------------------------------------------- galleryCache */

test('galleryCacheKey is stable and scoped by tenant, branch and model version', () => {
    const reference = { subscriptionId: 7, branchId: 3, modelVersion: 'dinov2-small-onnx-2026-11' };

    assert.equal(galleryCacheKey(reference), galleryCacheKey({ ...reference }));
    assert.ok(galleryCacheKey(reference).startsWith(`${GALLERY_CACHE_PREFIX}:`));

    assert.notEqual(galleryCacheKey(reference), galleryCacheKey({ ...reference, subscriptionId: 8 }));
    assert.notEqual(galleryCacheKey(reference), galleryCacheKey({ ...reference, branchId: 4 }));
    assert.notEqual(galleryCacheKey(reference), galleryCacheKey({ ...reference, modelVersion: 'mock' }));

    // null/undefined/empty coordinates must not leak the literal "undefined".
    const empty = galleryCacheKey({ subscriptionId: null, branchId: undefined, modelVersion: '' });

    assert.ok(!empty.includes('undefined'));
    assert.ok(!empty.includes('null'));
    assert.equal(empty, galleryCacheKey({}));
    assert.equal(galleryCacheKey(null), galleryCacheKey({}));
});

test('gallery cache round trip keeps the payload, the etag and the metadata', async () => {
    const driver = createMemoryDriver();
    const cache = createGalleryCache(driver);
    const reference = { subscriptionId: 1, branchId: 2, modelVersion: 'mock' };
    const payload = galleryPayload();

    assert.equal(await cache.read(reference), null);

    assert.equal(await cache.write(reference, payload, '"etag-1"'), true);

    const entry = await cache.read(reference);

    assert.ok(entry);
    assert.deepEqual(entry.payload, payload);
    assert.equal(entry.etag, '"etag-1"');
    assert.equal(entry.galleryVersion, payload.gallery_version);
    assert.equal(entry.modelVersion, payload.model_version);
    assert.equal(entry.dim, payload.dim);
    assert.equal(typeof entry.savedAt, 'number');

    assert.equal(await cache.clear(reference), true);
    assert.equal(await cache.read(reference), null);
});

test('gallery cache refuses oversized payloads and null, and never throws', async () => {
    const driver = createMemoryDriver();
    const cache = createGalleryCache(driver, { maxBytes: 512 });
    const reference = { subscriptionId: 1, branchId: 2, modelVersion: 'mock' };

    const big = galleryPayload({ vectors: 'A'.repeat(4096) });

    assert.equal(await cache.write(reference, big, '"etag"'), false);
    assert.equal(await cache.read(reference), null);

    assert.equal(await cache.write(reference, null, '"etag"'), false);
    assert.equal(await cache.write(reference, 'not-an-object', '"etag"'), false);

    // A tiny payload still fits.
    assert.equal(await cache.write(reference, galleryPayload(), '"etag"'), true);
});

test('gallery cache degrades to false/null when the driver fails (quota, private mode)', async () => {
    const quotaDriver = {
        async get() {
            throw Object.assign(new Error('QuotaExceededError'), { name: 'QuotaExceededError' });
        },
        async set() {
            throw Object.assign(new Error('QuotaExceededError'), { name: 'QuotaExceededError' });
        },
        async remove() {
            throw new Error('QuotaExceededError');
        },
        async keys() {
            throw new Error('QuotaExceededError');
        },
    };

    const cache = createGalleryCache(quotaDriver);
    const reference = { subscriptionId: 1, branchId: 2, modelVersion: 'mock' };

    assert.equal(await cache.write(reference, galleryPayload(), '"etag"'), false);
    assert.equal(await cache.read(reference), null);
    assert.equal(await cache.clear(reference), false);
    assert.equal(await cache.prune(), 0);

    // A driver without keys() cannot be pruned, but must not throw either.
    const noKeysDriver = { async get() { return null; }, async set() {}, async remove() {} };

    assert.equal(await createGalleryCache(noKeysDriver).prune(), 0);
});

test('gallery cache returns null for corrupted entries', async () => {
    const driver = createMemoryDriver();
    const cache = createGalleryCache(driver);
    const reference = { subscriptionId: 1, branchId: 2, modelVersion: 'corrupt' };
    const key = galleryCacheKey(reference);

    await driver.set(key, '{"gallery_version": '); // invalid JSON
    assert.equal(await cache.read(reference), null);

    await driver.set(key, JSON.stringify({ savedAt: Date.now(), payload: 'not-an-object' }));
    assert.equal(await cache.read(reference), null);

    await driver.set(key, JSON.stringify({ savedAt: 0, payload: galleryPayload() }));
    assert.equal(await cache.read(reference), null);

    await driver.set(key, JSON.stringify({ savedAt: 'ayer', payload: galleryPayload() }));
    assert.equal(await cache.read(reference), null);
});

test('gallery cache ignores entries older than maxAgeDays', async () => {
    const now = 1_700_000_000_000;
    const driver = createMemoryDriver();
    const reference = { subscriptionId: 1, branchId: 2, modelVersion: 'mock' };
    const writer = createGalleryCache(driver, { maxAgeDays: 30, now: () => now });

    assert.equal(await writer.write(reference, galleryPayload(), '"etag"'), true);
    assert.ok(await writer.read(reference));

    const later = createGalleryCache(driver, { maxAgeDays: 30, now: () => now + 31 * DAY_MS });

    assert.equal(await later.read(reference), null);

    // Just inside the window it is still usable.
    const withinWindow = createGalleryCache(driver, { maxAgeDays: 30, now: () => now + 29 * DAY_MS });

    assert.ok(await withinWindow.read(reference));
});

test('gallery cache prune removes only expired entries and counts them', async () => {
    const now = 1_700_000_000_000;
    const driver = createMemoryDriver();
    const fresh = { subscriptionId: 1, branchId: 1, modelVersion: 'fresh' };
    const stale = { subscriptionId: 1, branchId: 1, modelVersion: 'stale' };

    const current = createGalleryCache(driver, { maxAgeDays: 10, now: () => now });
    const past = createGalleryCache(driver, { maxAgeDays: 10, now: () => now - 11 * DAY_MS });

    assert.equal(await current.write(fresh, galleryPayload(), '"fresh"'), true);
    assert.equal(await past.write(stale, galleryPayload(), '"stale"'), true);

    // Whoever shares the object store must not be touched.
    await driver.set('another-module:key', 'keep me');

    assert.equal(await current.prune(), 1);
    assert.ok(await current.read(fresh));
    assert.equal(await current.read(stale), null);
    assert.equal(await driver.get('another-module:key'), 'keep me');
});

/* ----------------------------------------------------------------- drivers */

test('createMemoryDriver follows the driver contract', async () => {
    const driver = createMemoryDriver();

    assert.equal(await driver.get('missing'), null);
    await driver.set('a', { value: 1 });
    assert.deepEqual(await driver.get('a'), { value: 1 });
    assert.deepEqual(await driver.keys(), ['a']);
    await driver.remove('a');
    assert.equal(await driver.get('a'), null);
    assert.deepEqual(await driver.keys(), []);
});

test('createIndexedDbDriver falls back to memory when IndexedDB is unavailable', async () => {
    const driver = createIndexedDbDriver({ indexedDB: null });

    await driver.set('k', 'v');
    assert.equal(await driver.get('k'), 'v');
    assert.deepEqual(await driver.keys(), ['k']);
    await driver.remove('k');
    assert.equal(await driver.get('k'), null);
});

test('createIndexedDbDriver degrades to memory when opening the database fails', async () => {
    const failingIndexedDB = {
        open() {
            const request = {};
            // Safari private mode / disabled storage: the open request errors.
            setTimeout(() => {
                request.error = Object.assign(new Error('SecurityError'), { name: 'SecurityError' });
                request.onerror?.();
            }, 0);
            return request;
        },
    };

    const driver = createIndexedDbDriver({ indexedDB: failingIndexedDB, dbName: 'ezyventas-smartpos-test' });

    await driver.set('k', { a: 1 });
    assert.deepEqual(await driver.get('k'), { a: 1 });
    assert.deepEqual(await driver.keys(), ['k']);
    await driver.remove('k');
    assert.equal(await driver.get('k'), null);
});

/* ------------------------------------------------------------ samplePayload */

test('SAMPLE_SOURCES matches SmartPosProductSample::SOURCES exactly', () => {
    assert.deepEqual(SAMPLE_SOURCES, ['catalog_image', 'barcode_scan', 'quick_register', 'cashier_correction']);
    assert.equal(new Set(SAMPLE_SOURCES).size, SAMPLE_SOURCES.length);
});

test('buildSampleRequest packs a Float32Array as little-endian float32 base64', () => {
    const dim = 384;
    const vector = Float32Array.from({ length: dim }, (_, index) => Math.sin(index) * 0.05);

    const result = buildSampleRequest({
        productId: 5,
        modelVersion: 'mock',
        dim,
        vector,
        source: 'catalog_image',
    });

    assert.equal(result.ok, true);
    assert.equal(result.value.product_id, 5);
    assert.equal(result.value.model_version, 'mock');
    assert.equal(result.value.dim, dim);
    assert.equal(result.value.source, 'catalog_image');
    assert.equal(result.value.quality, null);
    assert.equal(result.value.crop, null);

    const decoded = base64ToFloat32(result.value.vector);

    assert.equal(decoded.length, dim);
    for (let index = 0; index < dim; index += 1) {
        assert.equal(decoded[index], vector[index]);
    }
});

test('buildSampleRequest accepts a base64 vector, a data-URI crop and a quality', () => {
    const dim = 128;
    const vector = Float32Array.from({ length: dim }, (_, index) => (index % 7) - 3);
    const cropBytes = Buffer.from('fake-jpeg-bytes');
    const dataUri = `data:image/jpeg;base64,${cropBytes.toString('base64')}`;

    const result = buildSampleRequest({
        productId: '8',
        modelVersion: 'dinov2-small-onnx-2026-11',
        dim,
        vector: float32ToBase64(vector),
        source: 'cashier_correction',
        quality: 0.82,
        crop: dataUri,
    });

    assert.equal(result.ok, true);
    assert.equal(result.value.product_id, 8);
    assert.equal(result.value.vector, float32ToBase64(vector));
    assert.equal(result.value.crop, cropBytes.toString('base64'));
    assert.equal(result.value.quality, 0.82);
    assert.equal(sizeOfBase64(result.value.crop), cropBytes.length);
});

test('buildSampleRequest reports every invalid field without throwing', () => {
    const dim = 128;
    const vector = () => Float32Array.from({ length: dim }, () => 0.1);
    const base = { productId: 1, modelVersion: 'mock', dim, vector: vector(), source: 'barcode_scan' };

    const incoherentDim = buildSampleRequest({ ...base, dim: 256 });

    assert.equal(incoherentDim.ok, false);
    assert.match(incoherentDim.errors.vector, /256/);

    const unknownSource = buildSampleRequest({ ...base, source: 'telepatia' });

    assert.equal(unknownSource.ok, false);
    assert.match(unknownSource.errors.source, /barcode_scan/);

    assert.ok(buildSampleRequest({ ...base, quality: 1.4 }).errors.quality);
    assert.ok(buildSampleRequest({ ...base, quality: -0.1 }).errors.quality);

    const bigCrop = buildSampleRequest({ ...base, crop: Buffer.alloc(80 * 1024 + 1).toString('base64') });

    assert.ok(bigCrop.errors.crop);
    assert.match(bigCrop.errors.crop, /80 KB/);

    const emptyModel = buildSampleRequest({ ...base, modelVersion: '   ' });

    assert.ok(emptyModel.errors.model_version);

    const longModel = buildSampleRequest({ ...base, modelVersion: 'x'.repeat(65) });

    assert.ok(longModel.errors.model_version);

    const withNaN = vector();
    withNaN[3] = Number.NaN;

    const nonFinite = buildSampleRequest({ ...base, vector: withNaN });

    assert.ok(nonFinite.errors.vector);
    assert.match(nonFinite.errors.vector, /componente 4/);

    assert.ok(buildSampleRequest({ ...base, dim: 64 }).errors.dim);
    assert.ok(buildSampleRequest({ ...base, vector: 42 }).errors.vector);
    assert.ok(buildSampleRequest({ ...base, vector: 'no soy base64!' }).errors.vector);
    assert.ok(buildSampleRequest({ ...base, crop: 'data:image/jpeg;base64' }).errors.crop);
    assert.equal(buildSampleRequest(undefined).ok, false);
});

test('sizeOfBase64 measures padded, unpadded and data-URI payloads', () => {
    assert.equal(sizeOfBase64(Buffer.from('hola').toString('base64')), 4);
    assert.equal(sizeOfBase64('aG9sYQ'), 4); // unpadded
    assert.equal(sizeOfBase64('data:image/jpeg;base64,aG9sYQ=='), 4);
    assert.equal(sizeOfBase64(''), 0);
    assert.equal(sizeOfBase64('no soy base64!'), 0);
    assert.equal(sizeOfBase64(null), 0);
});

/* ---------------------------------------------------------- estimateQuality */

test('estimateQuality rates a normal box above a tiny or a clipped one', () => {
    const frame = { frameWidth: 1280, frameHeight: 720 };

    const normal = estimateQuality({ box: [0.35, 0.3, 0.3, 0.4], ...frame });
    const tiny = estimateQuality({ box: [0.5, 0.5, 0.02, 0.02], ...frame });
    const clipped = estimateQuality({ box: [-0.15, 0.3, 0.3, 0.3], ...frame });

    for (const value of [normal, tiny, clipped]) {
        assert.equal(typeof value, 'number');
        assert.ok(value >= 0 && value <= 1, `calidad fuera de rango: ${value}`);
    }

    assert.ok(normal > 0.9, `caja normal demasiado baja: ${normal}`);
    assert.ok(tiny < normal, `caja diminuta (${tiny}) debería puntuar menos que la normal (${normal})`);
    assert.ok(clipped < normal, `caja cortada (${clipped}) debería puntuar menos que la normal (${normal})`);
});

test('estimateQuality incorporates sharpness with a moderate weight', () => {
    const frame = { frameWidth: 640, frameHeight: 480 };
    const box = [0.3, 0.3, 0.4, 0.4];

    const blurred = estimateQuality({ box, ...frame, sharpness: 0 });
    const sharp = estimateQuality({ box, ...frame, sharpness: 1 });

    assert.ok(sharp > blurred);
    assert.ok(sharp <= 1);

    // A non-finite sharpness is ignored, not propagated: the size/border-only
    // score is 1 for this box.
    assert.equal(
        estimateQuality({ box, ...frame, sharpness: Number.NaN }),
        estimateQuality({ box, ...frame }),
    );
});

test('estimateQuality falls back to 0.5 on unusable input and 0 on degenerate boxes', () => {
    const frame = { frameWidth: 640, frameHeight: 480 };

    assert.equal(estimateQuality(), 0.5);
    assert.equal(estimateQuality({}), 0.5);
    assert.equal(estimateQuality({ box: [0, 0, 0.1], ...frame }), 0.5);
    assert.equal(estimateQuality({ box: [0, 0, 0.1, Number.NaN], ...frame }), 0.5);
    assert.equal(estimateQuality({ box: [0, 0, 0.1, 0.1], frameWidth: 0, frameHeight: 480 }), 0.5);
    assert.equal(estimateQuality({ box: 'grande', ...frame }), 0.5);

    assert.equal(estimateQuality({ box: [0, 0, 0, 0], ...frame }), 0);
    assert.equal(estimateQuality({ box: [1.2, 1.2, 0.1, 0.1], ...frame }), 0);
});

/* ------------------------------------------------------------ cropBoxToSquare */

test('cropBoxToSquare returns a centred square inside the frame with the default margin', () => {
    const crop = cropBoxToSquare([0.4, 0.4, 0.2, 0.1], 1000, 500);

    assert.ok(crop);
    assert.equal(crop.sw, crop.sh);
    // 200 px wide with the default 8 % margin on each side → 232 px.
    assert.equal(crop.sw, 232);
    assert.ok(crop.sx >= 0 && crop.sy >= 0);
    assert.ok(crop.sx + crop.sw <= 1000);
    assert.ok(crop.sy + crop.sh <= 500);
    // Centred on the box centre (500, 225), clamped to the frame.
    assert.ok(Math.abs(crop.sx + crop.sw / 2 - 500) <= 1);
    assert.ok(Math.abs(crop.sy + crop.sh / 2 - 225) <= 1);
});

test('cropBoxToSquare applies the configured margin and clamps at the frame edges', () => {
    const box = [0.4, 0.4, 0.1, 0.1];
    const withoutMargin = cropBoxToSquare(box, 800, 600, { margin: 0 });
    const withMargin = cropBoxToSquare(box, 800, 600, { margin: 0.25 });

    assert.ok(withMargin.sw > withoutMargin.sw);
    // A bare number is also accepted as the margin.
    assert.equal(cropBoxToSquare(box, 800, 600, 0.25).sw, withMargin.sw);

    const atTheEdge = cropBoxToSquare([0, 0, 0.5, 0.5], 640, 480, { margin: 0.5 });

    assert.ok(atTheEdge);
    assert.equal(atTheEdge.sw, atTheEdge.sh);
    assert.ok(atTheEdge.sx >= 0 && atTheEdge.sy >= 0);
    assert.ok(atTheEdge.sx + atTheEdge.sw <= 640);
    assert.ok(atTheEdge.sy + atTheEdge.sh <= 480);
});

test('cropBoxToSquare returns null for invalid, degenerate or out-of-frame boxes', () => {
    assert.equal(cropBoxToSquare([0.1, 0.1, 0, 0.2], 640, 480), null);
    assert.equal(cropBoxToSquare([0.1, 0.1, 0.2], 640, 480), null);
    assert.equal(cropBoxToSquare([0.1, 0.1, Number.NaN, 0.2], 640, 480), null);
    assert.equal(cropBoxToSquare([0.1, 0.1, 0.2, 0.2], 0, 480), null);
    assert.equal(cropBoxToSquare(null, 640, 480), null);
    assert.equal(cropBoxToSquare([1.5, 1.5, 0.1, 0.1], 640, 480), null);
});
