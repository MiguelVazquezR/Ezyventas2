/**
 * Persistent cache of the downloaded vector gallery (Phase 4).
 *
 * The gallery can be several megabytes (see SmartCheckoutGalleryService), so
 * downloading it again on every activation of the mode is wasted bandwidth and
 * wasted time at the counter. This module keeps the last payload next to its
 * `ETag`, which is what lets the session revalidate with `If-None-Match` and
 * reuse the cached copy when the server answers `304 Not Modified`.
 *
 * ## Design notes
 *
 * - **The storage is injected as a driver.** Nothing here touches
 *   `localStorage`, `IndexedDB` or the DOM, so the whole thing runs in Node with
 *   `createMemoryDriver()` (idbDriver.js) and with a fake driver in the tests.
 *   The browser driver lives in `idbDriver.js`.
 * - **The gallery is an optimisation, never a requirement.** Every method is
 *   `async` and degrades instead of throwing: a broken driver, a full quota, a
 *   corrupted entry or Safari's private mode must never break the POS. `read()`
 *   returns `null`, `write()`/`clear()` return `false` and `prune()` returns `0`.
 * - **`maxBytes` is measured on the JSON that is actually stored** (the entry is
 *   serialised before the size check), so the quota check matches reality.
 * - **`maxAgeDays` is enforced on read** (`savedAt + maxAgeDays`). A stale copy
 *   is the same as no copy: the session falls back to a normal download.
 *
 * The entry is stored as a JSON string (exact size accounting); `read()` also
 * tolerates a driver that hands back the object itself.
 */

/** Prefix of every key this module owns: also what `prune()` is allowed to touch. */
export const GALLERY_CACHE_PREFIX = 'gallery:v1';

const DEFAULT_MAX_BYTES = 32 * 1024 * 1024;
const DEFAULT_MAX_AGE_DAYS = 30;
const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Stable key of one cached gallery.
 *
 * The three coordinates are enough to reuse a copy: the gallery is scoped by
 * subscription on the server, `branchId` is kept for future per-branch galleries
 * and `modelVersion` cannot be mixed (one output width per version). Missing
 * components become `-` instead of the literal `undefined`/`null`, so
 * `galleryCacheKey({})` is a valid, stable key.
 *
 * @param {{subscriptionId?: number|string|null, branchId?: number|string|null, modelVersion?: string|null}} [reference]
 * @returns {string}
 */
export function galleryCacheKey(reference) {
    const source = reference && typeof reference === 'object' ? reference : {};

    return `${GALLERY_CACHE_PREFIX}:${keyPart(source.subscriptionId)}:${keyPart(source.branchId)}:${keyPart(source.modelVersion)}`;
}

/**
 * Builds the cache around a storage driver.
 *
 * @param {{get: Function, set: Function, remove: Function, keys?: Function}} driver
 * @param {{maxBytes?: number, maxAgeDays?: number, now?: () => number}} [options]
 * @returns {{read: Function, write: Function, clear: Function, prune: Function}}
 */
export function createGalleryCache(driver, options = {}) {
    const source = options && typeof options === 'object' ? options : {};
    const store = driver && typeof driver === 'object' ? driver : null;

    const maxBytes = positiveNumber(source.maxBytes, DEFAULT_MAX_BYTES);
    const maxAgeDays = nonNegativeNumber(source.maxAgeDays, DEFAULT_MAX_AGE_DAYS);
    const now = typeof source.now === 'function' ? source.now : () => Date.now();
    const maxAgeMs = maxAgeDays * DAY_MS;

    const currentTime = () => {
        try {
            const value = Number(now());
            return Number.isFinite(value) ? value : Date.now();
        } catch {
            return Date.now();
        }
    };

    const can = (method) => store !== null && typeof store[method] === 'function';

    /**
     * Cache entry of a reference, or null when it does not exist, cannot be
     * parsed, does not have the expected shape or is older than `maxAgeDays`.
     *
     * @param {{subscriptionId?: any, branchId?: any, modelVersion?: any}} reference
     * @returns {Promise<{etag: string|null, payload: object, savedAt: number, galleryVersion: string|null, modelVersion: string|null, dim: number}|null>}
     */
    async function read(reference) {
        try {
            if (!can('get')) return null;

            const key = galleryCacheKey(reference);
            const raw = await store.get(key);

            if (raw === null || raw === undefined) return null;

            const entry = parseEntry(raw, key);

            if (entry === null) return null;
            if (currentTime() - entry.savedAt > maxAgeMs) return null;

            return entry;
        } catch {
            return null;
        }
    }

    /**
     * Stores `payload` with its ETag.
     *
     * @param {{subscriptionId?: any, branchId?: any, modelVersion?: any}} reference
     * @param {object|null} payload the gallery JSON exactly as the API returned it
     * @param {string|null} etag the `ETag` response header
     * @returns {Promise<boolean>} false when it was not stored (null payload, too
     *   big, or a driver failure such as the quota of private mode)
     */
    async function write(reference, payload, etag) {
        try {
            if (!isPlainObject(payload)) return false;
            if (!can('set')) return false;

            const key = galleryCacheKey(reference);
            const entry = {
                key,
                etag: toNullableString(etag),
                payload,
                savedAt: currentTime(),
                galleryVersion: toNullableString(payload.gallery_version),
                modelVersion: toNullableString(payload.model_version),
                dim: finiteNumber(payload.dim, 0),
            };

            const serialized = JSON.stringify(entry);

            if (byteLength(serialized) > maxBytes) return false;

            await store.set(key, serialized);

            return true;
        } catch {
            return false;
        }
    }

    /**
     * Removes the entry of one reference.
     *
     * @param {{subscriptionId?: any, branchId?: any, modelVersion?: any}} reference
     * @returns {Promise<boolean>} true when the driver removed it without throwing
     */
    async function clear(reference) {
        try {
            if (!can('remove')) return false;

            await store.remove(galleryCacheKey(reference));

            return true;
        } catch {
            return false;
        }
    }

    /**
     * Deletes the entries older than `maxAgeDays` using `driver.keys()`.
     *
     * Only keys under `GALLERY_CACHE_PREFIX` are considered, so a shared object
     * store is safe. A key whose content cannot be parsed has no provable age and
     * is left alone: `prune()` only removes what it can prove is expired; the
     * corrupted entry is already a miss for `read()`. Without `keys()` there is
     * nothing to enumerate and the method returns 0.
     *
     * @returns {Promise<number>} how many entries were removed
     */
    async function prune() {
        try {
            if (!can('keys')) return 0;

            const keys = await store.keys();

            if (!Array.isArray(keys)) return 0;

            let removed = 0;

            for (const key of keys) {
                if (typeof key !== 'string' || !key.startsWith(`${GALLERY_CACHE_PREFIX}:`)) continue;

                let raw;
                try {
                    raw = await store.get(key);
                } catch {
                    continue;
                }

                if (raw === null || raw === undefined) continue;

                const entry = parseEntry(raw, key);

                if (entry === null || currentTime() - entry.savedAt <= maxAgeMs) continue;

                try {
                    await store.remove(key);
                    removed += 1;
                } catch {
                    /* a driver that cannot remove one key must not abort the sweep */
                }
            }

            return removed;
        } catch {
            return 0;
        }
    }

    return { read, write, clear, prune };
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

/** Turns one coordinate of the key into a non-empty, `undefined`-free token. */
function keyPart(value) {
    if (value === null || value === undefined) return '-';

    const text = String(value).trim();

    return text === '' ? '-' : text;
}

/** Parses and validates a stored entry; null when the shape is unexpected. */
function parseEntry(raw, expectedKey) {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;

    if (!isPlainObject(parsed)) return null;
    if (!isPlainObject(parsed.payload)) return null;
    if (expectedKey !== undefined && typeof parsed.key === 'string' && parsed.key !== expectedKey) return null;

    const savedAt = Number(parsed.savedAt);

    if (!Number.isFinite(savedAt) || savedAt <= 0) return null;

    const dim = Number(parsed.dim);

    if (!Number.isFinite(dim) || dim < 0) return null;

    return {
        etag: toNullableString(parsed.etag),
        payload: parsed.payload,
        savedAt,
        galleryVersion: toNullableString(parsed.galleryVersion ?? parsed.payload.gallery_version),
        modelVersion: toNullableString(parsed.modelVersion ?? parsed.payload.model_version),
        dim,
    };
}

function isPlainObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function toNullableString(value) {
    if (value === null || value === undefined) return null;

    return String(value);
}

function finiteNumber(value, fallback) {
    const number = Number(value);

    return Number.isFinite(number) ? number : fallback;
}

function positiveNumber(value, fallback) {
    const number = Number(value);

    return Number.isFinite(number) && number > 0 ? number : fallback;
}

function nonNegativeNumber(value, fallback) {
    const number = Number(value);

    return Number.isFinite(number) && number >= 0 ? number : fallback;
}

/** UTF-8 byte length of the serialised entry (what counts against `maxBytes`). */
function byteLength(text) {
    if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(text).length;

    return text.length * 2;
}
