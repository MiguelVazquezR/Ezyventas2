/**
 * Vector gallery: the tenant's product embeddings, downloaded once and searched
 * in memory with a dot product (the vectors are L2-normalised, so it is also the
 * cosine similarity).
 *
 * The API returns the vectors as one base64 blob of little-endian float32 plus an
 * index of offsets, which keeps the payload small enough for 35k vectors.
 */
import { base64ToFloat32, dotProduct, normalizeL2, roundTo } from './tensorUtils.js';

/**
 * @param {{dim: number, model_version?: string, gallery_version?: string, items: Array<{product_id: number, offset: number, length: number, source?: string}>, vectors: string}} payload
 * @returns {{dim: number, modelVersion: string|null, galleryVersion: string|null, items: Array<{productId: number, vector: Float32Array, source: string|null}>}}
 */
export function decodeGallery(payload) {
    const dim = Number(payload?.dim) || 0;
    const items = Array.isArray(payload?.items) ? payload.items : [];
    const blob = typeof payload?.vectors === 'string' && payload.vectors.length ? base64ToFloat32(payload.vectors) : new Float32Array(0);

    const decoded = items.map((item) => {
        const offset = Number(item.offset) || 0;
        const length = Number(item.length) || dim;
        const slice = blob.subarray(offset, offset + length);
        return {
            productId: Number(item.product_id),
            vector: slice.length === dim ? slice : normalizeL2(slice),
            source: item.source ?? null,
        };
    });

    return {
        dim,
        modelVersion: payload?.model_version ?? null,
        galleryVersion: payload?.gallery_version ?? null,
        items: decoded.filter((item) => Number.isFinite(item.productId) && item.vector.length > 0),
    };
}

/**
 * Best-scoring products for a query vector.
 *
 * @param {{items: Array<{productId: number, vector: Float32Array}>}} gallery
 * @param {Float32Array|number[]} query
 * @param {{topK?: number, minScore?: number}} [options]
 * @returns {Array<{productId: number, score: number}>} best first
 */
export function searchGallery(gallery, query, options = {}) {
    const topK = Math.max(1, Number(options.topK) || 3);
    const minScore = Number(options.minScore) || 0;
    const items = gallery?.items || [];
    if (!query || !query.length || !items.length) return [];

    const best = new Map();

    for (const item of items) {
        if (item.vector.length !== query.length) continue;
        const score = dotProduct(item.vector, query);
        const previous = best.get(item.productId);
        if (previous === undefined || score > previous) best.set(item.productId, score);
    }

    return Array.from(best.entries())
        .map(([productId, score]) => ({ productId, score: roundTo(score, 4) }))
        .filter((entry) => entry.score >= minScore)
        .sort((a, b) => b.score - a.score)
        .slice(0, topK);
}

/** Merges a new gallery payload into the previous one (incremental sync). */
export function mergeGallery(previous, next) {
    if (!previous) return next;
    if (!next) return previous;
    if (previous.galleryVersion === next.galleryVersion && previous.dim === next.dim) return previous;
    return next;
}
