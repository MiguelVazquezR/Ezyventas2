/**
 * Geometry of a crop for the learning phase (Phase 4).
 *
 * The recognition engines of this module describe proposals as **normalised**
 * boxes `[x, y, w, h]` (0..1). The embedder, on the other hand, expects a square
 * region of pixels: DINOv2 resizes the short side and centre-crops 224x224, so
 * feeding it a stretched rectangle would change the aspect ratio of the product
 * and damage the embedding. `cropBoxToSquare()` is that conversion and it is
 * pure, so it can be tested without a canvas.
 *
 * The margin (8 % by default) keeps a ring of context around the detection. The
 * square is then shifted — never stretched — to stay inside the frame, which is
 * why the result is still exactly square (`sw === sh`).
 */

/** Default margin, applied on each side as a fraction of the square side. */
export const CROP_DEFAULT_MARGIN = 0.08;

/**
 * Converts a normalised box into a square crop in pixels of the frame.
 *
 * The box must overlap the frame; a box entirely outside returns null, and so
 * does an invalid or degenerate one (`w <= 0`, `h <= 0`, non-finite values, bad
 * frame size). Partial boxes are kept, not rejected: the crop grows from the
 * centre and is clamped to the frame, so the cashier still gets a usable image
 * of a product that touches the edge.
 *
 * @param {number[]} box normalised `[x, y, w, h]`
 * @param {number} frameWidth frame width in pixels
 * @param {number} frameHeight frame height in pixels
 * @param {{margin?: number}|number} [options] `margin` 0..1; a bare number is
 *   also accepted as the margin, for callers that prefer the short form
 * @returns {{sx: number, sy: number, sw: number, sh: number}|null}
 */
export function cropBoxToSquare(box, frameWidth, frameHeight, options = {}) {
    const width = Number(frameWidth);
    const height = Number(frameHeight);

    if (!Number.isFinite(width) || !Number.isFinite(height) || width < 1 || height < 1) return null;

    if (!Array.isArray(box) || box.length < 4) return null;

    const values = box.slice(0, 4).map((value) => Number(value));

    if (values.some((value) => !Number.isFinite(value))) return null;

    const [x, y, boxWidth, boxHeight] = values;

    if (boxWidth <= 0 || boxHeight <= 0) return null;

    // The visible part of the box decides whether there is anything to crop.
    const visibleLeft = Math.max(0, Math.min(1, x));
    const visibleTop = Math.max(0, Math.min(1, y));
    const visibleRight = Math.max(0, Math.min(1, x + boxWidth));
    const visibleBottom = Math.max(0, Math.min(1, y + boxHeight));

    if (visibleRight <= visibleLeft || visibleBottom <= visibleTop) return null;

    const marginOption = typeof options === 'number' ? options : options?.margin;
    const margin = Number.isFinite(Number(marginOption)) && marginOption !== null && marginOption !== undefined
        ? Math.min(1, Math.max(0, Number(marginOption)))
        : CROP_DEFAULT_MARGIN;

    // Square side in pixels: the largest side of the box plus the margin on each
    // side. It can never be larger than the smallest frame dimension, otherwise
    // it could not fit. It is rounded (not truncated) so an integer margin cannot
    // shave a pixel off the requested square because of the floating point.
    const boxWidthPx = boxWidth * width;
    const boxHeightPx = boxHeight * height;
    const side = Math.min(
        Math.round(Math.max(boxWidthPx, boxHeightPx) * (1 + 2 * margin)),
        Math.floor(Math.min(width, height)),
    );

    if (side < 1) return null;

    // Centre of the original box, in pixels.
    const centreX = (x + boxWidth / 2) * width;
    const centreY = (y + boxHeight / 2) * height;

    const sx = clamp(Math.round(centreX - side / 2), 0, width - side);
    const sy = clamp(Math.round(centreY - side / 2), 0, height - side);

    return { sx, sy, sw: side, sh: side };
}

function clamp(value, minimum, maximum) {
    if (maximum < minimum) return minimum;

    return Math.min(maximum, Math.max(minimum, value));
}
