/**
 * `EmptyCounterDiffProposer`: the first object-proposal strategy of the spec.
 *
 * It compares the current frame against a reference photo of the empty counter
 * (same camera, stable lighting) and returns the regions that changed. Very
 * light on CPU, and it usually works well with a top-down camera, which is the
 * realistic setup for a shop counter.
 *
 * Works on the small grayscale signature (32x18 by default): a changed cell is a
 * "pixel" of a 32x18 mask, connected components give the blobs, and each blob
 * becomes a normalised box. Pure JS so the comparison can be evaluated in Node
 * against the synthetic scenes.
 */
import { grayscaleSignature, SIGNATURE_HEIGHT, SIGNATURE_WIDTH } from './frameStability.js';
import { roundTo } from './tensorUtils.js';

/**
 * Builds the reference signature of the empty counter.
 *
 * @param {Uint8ClampedArray|Uint8Array} rgba
 * @param {number} width
 * @param {number} height
 * @param {{gridWidth?: number, gridHeight?: number}} [options]
 * @returns {{signature: Float32Array, gridWidth: number, gridHeight: number, capturedAt: number}}
 */
export function captureEmptyCounterReference(rgba, width, height, options = {}) {
    const gridWidth = options.gridWidth || SIGNATURE_WIDTH;
    const gridHeight = options.gridHeight || SIGNATURE_HEIGHT;
    return {
        signature: grayscaleSignature(rgba, width, height, { gridWidth, gridHeight }),
        gridWidth,
        gridHeight,
        capturedAt: Date.now(),
    };
}

/**
 * Boolean mask of the cells that differ from the empty counter.
 *
 * @param {Float32Array} reference
 * @param {Float32Array} current
 * @param {number} threshold 0..1 (mean absolute luminance difference)
 */
export function differenceMask(reference, current, threshold = 0.08) {
    const length = Math.min(reference?.length || 0, current?.length || 0);
    const mask = new Uint8Array(length);
    for (let i = 0; i < length; i += 1) {
        mask[i] = Math.abs(current[i] - reference[i]) >= threshold ? 1 : 0;
    }
    return mask;
}

/** Grows the mask by one cell in the four directions, to join broken blobs. */
export function dilateMask(mask, width, height) {
    const out = new Uint8Array(mask.length);
    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
            const index = y * width + x;
            if (!mask[index]) continue;
            for (let dy = -1; dy <= 1; dy += 1) {
                for (let dx = -1; dx <= 1; dx += 1) {
                    const ny = y + dy;
                    const nx = x + dx;
                    if (ny < 0 || ny >= height || nx < 0 || nx >= width) continue;
                    out[ny * width + nx] = 1;
                }
            }
        }
    }
    return out;
}

/**
 * Connected components (4-neighbour) of a mask.
 *
 * @returns {Array<{x: number, y: number, width: number, height: number, cells: number}>}
 */
export function connectedComponents(mask, width, height) {
    const seen = new Uint8Array(mask.length);
    const components = [];
    const stack = [];

    for (let start = 0; start < mask.length; start += 1) {
        if (!mask[start] || seen[start]) continue;

        let minX = width;
        let minY = height;
        let maxX = -1;
        let maxY = -1;
        let cells = 0;

        stack.push(start);
        seen[start] = 1;

        while (stack.length) {
            const index = stack.pop();
            const x = index % width;
            const y = Math.floor(index / width);
            cells += 1;
            if (x < minX) minX = x;
            if (y < minY) minY = y;
            if (x > maxX) maxX = x;
            if (y > maxY) maxY = y;

            const neighbours = [
                x > 0 ? index - 1 : -1,
                x < width - 1 ? index + 1 : -1,
                y > 0 ? index - width : -1,
                y < height - 1 ? index + width : -1,
            ];

            for (const neighbour of neighbours) {
                if (neighbour < 0 || seen[neighbour] || !mask[neighbour]) continue;
                seen[neighbour] = 1;
                stack.push(neighbour);
            }
        }

        components.push({ x: minX, y: minY, width: maxX - minX + 1, height: maxY - minY + 1, cells });
    }

    return components;
}

/**
 * Runs the strategy: reference vs current frame → normalised boxes.
 *
 * @param {object} input
 * @param {{signature: Float32Array, gridWidth: number, gridHeight: number}} input.reference
 * @param {Float32Array|{signature: Float32Array}} input.current  signature of the frame
 * @param {number} [input.threshold=0.08]
 * @param {number} [input.minAreaRatio=0.004]  minimum blob area relative to the frame
 * @param {number} [input.maxBoxes=10]
 * @param {boolean} [input.dilate=true]
 * @returns {Array<{box: [number, number, number, number], score: number, cells: number}>}
 */
export function proposeFromDifference(input) {
    const reference = input?.reference;
    const currentSignature = input?.current?.signature || input?.current;
    if (!reference?.signature || !currentSignature) return [];

    const gridWidth = reference.gridWidth || SIGNATURE_WIDTH;
    const gridHeight = reference.gridHeight || SIGNATURE_HEIGHT;
    const threshold = Number(input.threshold) > 0 ? Number(input.threshold) : 0.08;
    const minAreaRatio = Number(input.minAreaRatio) > 0 ? Number(input.minAreaRatio) : 0.004;
    const maxBoxes = Math.max(1, Number(input.maxBoxes) || 10);

    const mask = differenceMask(reference.signature, currentSignature, threshold);
    const working = input.dilate === false ? mask : dilateMask(mask, gridWidth, gridHeight);
    const components = connectedComponents(working, gridWidth, gridHeight);

    const minCells = Math.max(1, Math.round(minAreaRatio * gridWidth * gridHeight));

    return components
        .filter((component) => component.cells >= minCells)
        .sort((a, b) => b.cells - a.cells)
        .slice(0, maxBoxes)
        .map((component) => {
            const x = component.x / gridWidth;
            const y = component.y / gridHeight;
            const w = component.width / gridWidth;
            const h = component.height / gridHeight;
            // Confidence grows with the size of the changed area (cosmetic: the
            // identification score comes from the embedding search).
            const score = Math.min(1, 0.5 + component.cells / (gridWidth * gridHeight));
            return {
                box: [roundTo(x, 4), roundTo(y, 4), roundTo(w, 4), roundTo(h, 4)],
                score: roundTo(score, 4),
                cells: component.cells,
            };
        });
}
