/**
 * Construction and validation of the `POST /pos/smart-checkout/samples` body
 * (Phase 4), plus the heuristics used to score a sample before it is sent.
 *
 * The contract lives on the server, not here: `StoreProductSampleRequest`
 * validates the shape and `SmartCheckoutGalleryService::storeSample()` the bytes
 * (exact length, finite components, L2 norm, JPEG sniffing, product of the
 * branch). This module mirrors the **client-side** version of those rules so a
 * sample that cannot be accepted is never uploaded (and the panel can explain
 * why without a round trip), but the server stays the source of truth.
 *
 * Everything is pure and never throws: the caller gets
 * `{ok: true, value}` or `{ok: false, errors}` with Spanish messages, the same
 * shape the panel already shows for 422 responses.
 */
import { float32ToBase64, roundTo } from '../inference/tensorUtils.js';

/** Accepted origins, in the exact order of `SmartPosProductSample::SOURCES`. */
export const SAMPLE_SOURCES = ['catalog_image', 'barcode_scan', 'quick_register', 'cashier_correction'];

/** Widths accepted by the gallery service (kept in sync with its PHP constants). */
const DIM_MIN = 128;
const DIM_MAX = 1024;

/** One model version cannot exceed this length in the FormRequest. */
const MODEL_VERSION_MAX_LENGTH = 64;

/** Largest crop accepted, in decoded bytes (the PHP `CROP_MAX_BYTES`). */
const CROP_MAX_BYTES = 80 * 1024;

/**
 * Largest absolute component accepted, mirroring the server: above 1e30 float32
 * cannot be packed faithfully and the sample would be rejected as infinite.
 */
const FLOAT32_MAX_ABS = 1.0e30;

/** A full box of 5 % of the frame earns the whole size score. */
const FULL_SIZE_AREA = 0.05;

/**
 * Builds and validates the body of one sample.
 *
 * `vector` accepts the array of numbers (or a `Float32Array`, what the worker
 * hands back) or its base64 representation; arrays are packed little-endian
 * float32 with `float32ToBase64()`, the transport the API expects. `crop` accepts
 * a raw base64 string or a `data:image/jpeg;base64,…` URI (the prefix is
 * stripped because `canvas.toDataURL()` includes it).
 *
 * @param {{
 *   productId?: number|string,
 *   modelVersion?: string,
 *   dim?: number|string,
 *   vector?: Float32Array|number[]|string,
 *   source?: string,
 *   quality?: number|null,
 *   crop?: string|null,
 * }} input
 * @returns {{ok: true, value: {product_id: number, model_version: string, dim: number, vector: string, source: string, quality: number|null, crop: string|null}}
 *   | {ok: false, errors: Record<string, string>}}
 */
export function buildSampleRequest(input) {
    const source = input && typeof input === 'object' ? input : {};
    const errors = {};

    // ------------------------------------------------------------ product_id
    const productId = Number(source.productId);

    if (!Number.isInteger(productId) || productId < 1) {
        errors.product_id = 'El producto debe ser un identificador válido (entero mayor o igual a 1).';
    }

    // --------------------------------------------------------- model_version
    const modelVersion = typeof source.modelVersion === 'string' ? source.modelVersion.trim() : '';

    if (modelVersion === '') {
        errors.model_version = 'La versión del modelo es obligatoria.';
    } else if (modelVersion.length > MODEL_VERSION_MAX_LENGTH) {
        errors.model_version = `La versión del modelo no puede superar ${MODEL_VERSION_MAX_LENGTH} caracteres.`;
    }

    // ------------------------------------------------------------------- dim
    const dim = Number(source.dim);
    const dimIsValid = Number.isInteger(dim) && dim >= DIM_MIN && dim <= DIM_MAX;

    if (!Number.isInteger(dim)) {
        errors.dim = 'La dimensión del vector debe ser un número entero.';
    } else if (dim < DIM_MIN || dim > DIM_MAX) {
        errors.dim = `La dimensión del vector debe estar entre ${DIM_MIN} y ${DIM_MAX}.`;
    }

    // ---------------------------------------------------------------- source
    const sampleSource = typeof source.source === 'string' ? source.source : '';

    if (!SAMPLE_SOURCES.includes(sampleSource)) {
        errors.source = `El origen de la muestra debe ser uno de: ${SAMPLE_SOURCES.join(', ')}.`;
    }

    // --------------------------------------------------------------- quality
    let quality = null;

    if (source.quality !== null && source.quality !== undefined) {
        const value = Number(source.quality);

        if (!Number.isFinite(value) || value < 0 || value > 1) {
            errors.quality = 'La calidad de la muestra debe estar entre 0 y 1.';
        } else {
            quality = value;
        }
    }

    // ------------------------------------------------------------------ crop
    let crop = null;

    if (source.crop !== null && source.crop !== undefined) {
        const normalized = normalizeBase64(source.crop);

        if (normalized === null) {
            errors.crop = 'El recorte debe enviarse como una cadena en base64 válida.';
        } else if (sizeOfBase64(normalized) > CROP_MAX_BYTES) {
            errors.crop = 'El recorte no puede superar 80 KB.';
        } else {
            crop = normalized;
        }
    }

    // ---------------------------------------------------------------- vector
    let vector = null;

    if (source.vector === null || source.vector === undefined) {
        errors.vector = 'El vector es obligatorio: envía la lista de números o su base64.';
    } else if (source.vector instanceof Float32Array || Array.isArray(source.vector)) {
        const values = Array.from(source.vector);
        let vectorError = null;

        if (dimIsValid && values.length !== dim) {
            vectorError = `El vector debe contener exactamente ${dim} valores; se recibieron ${values.length}.`;
        }

        if (vectorError === null) {
            for (let index = 0; index < values.length; index += 1) {
                const value = values[index];

                if (typeof value !== 'number' || !Number.isFinite(value) || Math.abs(value) > FLOAT32_MAX_ABS) {
                    vectorError = `El componente ${index + 1} del vector no es un número finito válido.`;
                    break;
                }
            }
        }

        if (vectorError !== null) {
            errors.vector = vectorError;
        } else {
            vector = float32ToBase64(Float32Array.from(values));
        }
    } else if (typeof source.vector === 'string') {
        const normalized = normalizeBase64(source.vector);

        if (normalized === null) {
            errors.vector = 'El vector no es un base64 válido ni una lista de números.';
        } else if (dimIsValid && sizeOfBase64(normalized) !== dim * 4) {
            errors.vector = `El vector debe ocupar exactamente ${dim * 4} bytes (${dim} valores float32); se recibieron ${sizeOfBase64(normalized)}.`;
        } else {
            vector = normalized;
        }
    } else {
        errors.vector = 'El vector debe ser una lista de números o su representación en base64.';
    }

    if (Object.keys(errors).length > 0) {
        return { ok: false, errors };
    }

    return {
        ok: true,
        value: {
            product_id: productId,
            model_version: modelVersion,
            dim,
            vector,
            source: sampleSource,
            quality,
            crop,
        },
    };
}

/**
 * Heuristic quality (0..1) of a crop, used to tell the gallery service which
 * sample of the ten per product to evict first.
 *
 * Formula (all terms are clamped to 0..1):
 *
 *   visibleArea  = area of the box ∩ frame, in normalised units
 *   boxArea      = w * h of the box
 *   sizeScore    = sqrt(visibleArea / 0.05)        → 5 % of the frame = 1;
 *                                                    tiny boxes fall off fast
 *   borderScore  = visibleArea / boxArea           → 1 when the box is inside,
 *                                                    lower when the frame cuts it
 *   sharpnessScore = sharpness (only when supplied)
 *
 *   without sharpness: 0.70 * sizeScore + 0.30 * borderScore
 *   with sharpness:    0.55 * sizeScore + 0.25 * borderScore + 0.20 * sharpness
 *
 * The sharpness weight is deliberately moderate: the value usually comes from a
 * cheap variance-of-Laplacian estimate and must not dominate a clear size or
 * border problem. Missing or non-finite inputs return 0.5 (an unknown sample is
 * "average", never "perfect"); a degenerate box (`w <= 0`, `h <= 0` or entirely
 * outside the frame) returns 0, because there is nothing to embed.
 *
 * @param {{box?: number[], frameWidth?: number, frameHeight?: number, sharpness?: number|null}} input
 * @returns {number} rounded to 3 decimals, always between 0 and 1
 */
export function estimateQuality(input) {
    const source = input && typeof input === 'object' ? input : {};
    const frameWidth = Number(source.frameWidth);
    const frameHeight = Number(source.frameHeight);
    const box = source.box;

    if (!Number.isFinite(frameWidth) || !Number.isFinite(frameHeight) || frameWidth <= 0 || frameHeight <= 0) {
        return 0.5;
    }

    if (!Array.isArray(box) || box.length < 4) return 0.5;

    const values = box.slice(0, 4).map((value) => Number(value));

    if (values.some((value) => !Number.isFinite(value))) return 0.5;

    const [x, y, width, height] = values;

    if (width <= 0 || height <= 0) return 0;

    const left = Math.max(0, x);
    const top = Math.max(0, y);
    const right = Math.min(1, x + width);
    const bottom = Math.min(1, y + height);

    const visibleWidth = Math.max(0, right - left);
    const visibleHeight = Math.max(0, bottom - top);
    const visibleArea = visibleWidth * visibleHeight;
    const boxArea = width * height;

    // Entirely outside the frame: no pixels to embed.
    if (visibleArea <= 0 || boxArea <= 0) return 0;

    const sizeScore = clamp01(Math.sqrt(visibleArea / FULL_SIZE_AREA));
    const borderScore = clamp01(visibleArea / boxArea);

    let sharpnessScore = null;

    if (source.sharpness !== null && source.sharpness !== undefined) {
        const value = Number(source.sharpness);

        if (Number.isFinite(value)) sharpnessScore = clamp01(value);
    }

    const quality = sharpnessScore === null
        ? 0.7 * sizeScore + 0.3 * borderScore
        : 0.55 * sizeScore + 0.25 * borderScore + 0.2 * sharpnessScore;

    return roundTo(clamp01(quality), 3);
}

/**
 * Decoded size of a base64 string, in bytes, without allocating the buffer.
 *
 * Used to enforce the 80 KB cap of the crop before the payload is built. A
 * `data:` URI is accepted (the part after the comma is measured) and padding is
 * optional, so it also works with the unpadded variant some encoders emit.
 *
 * @param {string} base64
 * @returns {number} 0 when the string is not a usable base64 payload
 */
export function sizeOfBase64(base64) {
    const normalized = normalizeBase64(base64);

    if (normalized === null) return 0;

    const padding = normalized.endsWith('==') ? 2 : normalized.endsWith('=') ? 1 : 0;
    const bytes = Math.floor((normalized.length * 3) / 4) - padding;

    return bytes > 0 ? bytes : 0;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

const BASE64_PATTERN = /^[A-Za-z0-9+/]+={0,2}$/;

/**
 * Strips a `data:` URI prefix, removes whitespace and pads the string to a
 * multiple of four. Returns null when it cannot be a base64 payload at all.
 */
function normalizeBase64(value) {
    if (typeof value !== 'string') return null;

    let body = value.trim();

    if (body === '') return null;

    if (body.startsWith('data:')) {
        const comma = body.indexOf(',');

        if (comma === -1) return null;

        body = body.slice(comma + 1);
    }

    body = body.replace(/\s+/g, '');

    if (body === '' || !BASE64_PATTERN.test(body)) return null;

    const remainder = body.length % 4;

    if (remainder === 1) return null;
    if (remainder === 2) return `${body}==`;
    if (remainder === 3) return `${body}=`;

    return body;
}

function clamp01(value) {
    if (!Number.isFinite(value)) return 0;

    return Math.min(1, Math.max(0, value));
}
