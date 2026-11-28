/**
 * Tensor helpers shared by the browser engine and the Node tests.
 *
 * Everything here is pure: no DOM, no ONNX Runtime, no Web APIs. That keeps the
 * tricky arithmetic (channel order, normalisation, base64 packing, similarity)
 * testable outside a browser.
 */

/** ImageNet statistics used by DINOv2 (must match the model card). */
export const DINO_MEAN = Object.freeze([0.485, 0.456, 0.406]);
export const DINO_STD = Object.freeze([0.229, 0.224, 0.225]);

/**
 * Converts a canvas `ImageData` (RGBA, row-major) into the NCHW float32 tensor
 * the models expect: `[1, 3, height, width]` with per-channel normalisation.
 *
 * @param {Uint8ClampedArray|Uint8Array} rgba
 * @param {number} width
 * @param {number} height
 * @param {{mean?: number[], std?: number[]}} [options]
 * @returns {Float32Array} length = 3 * width * height
 */
export function rgbaToNchwFloat32(rgba, width, height, options = {}) {
    const mean = options.mean || DINO_MEAN;
    const std = options.std || DINO_STD;
    const pixels = width * height;
    const out = new Float32Array(pixels * 3);

    for (let i = 0; i < pixels; i += 1) {
        const r = rgba[i * 4] / 255;
        const g = rgba[i * 4 + 1] / 255;
        const b = rgba[i * 4 + 2] / 255;

        out[i] = (r - mean[0]) / std[0];
        out[pixels + i] = (g - mean[1]) / std[1];
        out[pixels * 2 + i] = (b - mean[2]) / std[2];
    }

    return out;
}

/**
 * L2 normalisation. The ONNX graphs return the raw CLS embedding; normalising
 * here keeps the exported model identical for fp32 and INT8 variants.
 *
 * @param {Float32Array|number[]} vector
 * @returns {Float32Array}
 */
export function normalizeL2(vector) {
    const out = Float32Array.from(vector);
    let sum = 0;
    for (let i = 0; i < out.length; i += 1) sum += out[i] * out[i];
    const norm = Math.sqrt(sum);
    if (!Number.isFinite(norm) || norm === 0) return out;
    for (let i = 0; i < out.length; i += 1) out[i] /= norm;
    return out;
}

/** Dot product; with L2-normalised vectors this is the cosine similarity. */
export function dotProduct(a, b) {
    const length = Math.min(a.length, b.length);
    let sum = 0;
    for (let i = 0; i < length; i += 1) sum += a[i] * b[i];
    return sum;
}

export function cosineSimilarity(a, b) {
    let dot = 0;
    let normA = 0;
    let normB = 0;
    const length = Math.min(a.length, b.length);
    for (let i = 0; i < length; i += 1) {
        dot += a[i] * b[i];
        normA += a[i] * a[i];
        normB += b[i] * b[i];
    }
    const denominator = Math.sqrt(normA) * Math.sqrt(normB);
    return denominator === 0 ? 0 : dot / denominator;
}

/** float32 → base64 (little-endian), the transport format of the samples API. */
export function float32ToBase64(vector) {
    const bytes = new Uint8Array(Float32Array.from(vector).buffer);
    return bytesToBase64(bytes);
}

/** base64 → Float32Array (little-endian). */
export function base64ToFloat32(base64) {
    const binary = base64ToBytes(base64);
    return new Float32Array(binary.buffer, binary.byteOffset, binary.byteLength / 4);
}

export function bytesToBase64(bytes) {
    if (typeof Buffer !== 'undefined') return Buffer.from(bytes).toString('base64');
    let binary = '';
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }
    return btoa(binary);
}

export function base64ToBytes(base64) {
    if (typeof Buffer !== 'undefined') {
        const buffer = Buffer.from(base64, 'base64');
        return new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
    }
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes;
}

/** Rounds to `decimals` places (used for scores sent to the UI). */
export function roundTo(value, decimals = 3) {
    const factor = 10 ** decimals;
    return Math.round((Number(value) || 0) * factor) / factor;
}
