/**
 * Frame stability ("la imagen se estabiliza"):
 *
 * The engine must not analyse a frame while the cashier is still placing
 * products. A frame is stable when the difference against the previous frames
 * stays under a threshold for N consecutive samples.
 *
 * The signature is a small 32x18 grayscale thumbnail, so comparing frames costs
 * a few hundred operations instead of touching a 1280x720 image. Pure JS: tested
 * in Node with synthetic frames.
 */

/** Default size of the thumbnail used to compare frames. */
export const SIGNATURE_WIDTH = 32;
export const SIGNATURE_HEIGHT = 18;

/**
 * Box-averaged luminance of an RGBA frame, normalised to 0..1.
 *
 * @param {Uint8ClampedArray|Uint8Array} rgba
 * @param {number} width
 * @param {number} height
 * @param {{gridWidth?: number, gridHeight?: number}} [options]
 * @returns {Float32Array}
 */
export function grayscaleSignature(rgba, width, height, options = {}) {
    const gridWidth = options.gridWidth || SIGNATURE_WIDTH;
    const gridHeight = options.gridHeight || SIGNATURE_HEIGHT;

    if (!rgba || width <= 0 || height <= 0) return new Float32Array(gridWidth * gridHeight);

    const out = new Float32Array(gridWidth * gridHeight);
    const counts = new Uint32Array(gridWidth * gridHeight);

    for (let y = 0; y < height; y += 1) {
        const cellY = Math.min(gridHeight - 1, Math.floor((y * gridHeight) / height));
        for (let x = 0; x < width; x += 1) {
            const cellX = Math.min(gridWidth - 1, Math.floor((x * gridWidth) / width));
            const index = (y * width + x) * 4;
            // Rec. 709 luma: cheap and close to human perception.
            const luma = (0.2126 * rgba[index] + 0.7152 * rgba[index + 1] + 0.0722 * rgba[index + 2]) / 255;
            const cell = cellY * gridWidth + cellX;
            out[cell] += luma;
            counts[cell] += 1;
        }
    }

    for (let i = 0; i < out.length; i += 1) {
        if (counts[i] > 0) out[i] /= counts[i];
    }

    return out;
}

/** Mean absolute difference between two signatures (0 = identical). */
export function signatureDistance(a, b) {
    if (!a || !b || a.length !== b.length || a.length === 0) return 1;
    let sum = 0;
    for (let i = 0; i < a.length; i += 1) sum += Math.abs(a[i] - b[i]);
    return sum / a.length;
}

/**
 * Sliding-window stability tracker.
 *
 * @param {{threshold?: number, samples?: number}} [options]
 * @returns {{push: (signature: Float32Array) => {stable: boolean, distance: number, samples: number}, reset: () => void, isStable: () => boolean}}
 */
export function createStabilityTracker(options = {}) {
    const threshold = Number(options.threshold) > 0 ? Number(options.threshold) : 0.02;
    const samples = Math.max(2, Number(options.samples) || 3);

    let previous = null;
    let stableCount = 0;
    let lastDistance = 1;

    return {
        push(signature) {
            if (!signature || signature.length === 0) {
                return { stable: false, distance: 1, samples: 0 };
            }

            if (previous) {
                lastDistance = signatureDistance(previous, signature);
                if (lastDistance <= threshold) stableCount += 1;
                else stableCount = 0;
            }

            previous = signature;
            const needed = samples - 1; // the first frame only opens the window
            const stable = stableCount >= needed;

            return { stable, distance: lastDistance, samples: stableCount };
        },
        reset() {
            previous = null;
            stableCount = 0;
            lastDistance = 1;
        },
        isStable() {
            return stableCount >= samples - 1;
        },
    };
}
