/**
 * Post-processing of the RT-DETR output, done in plain JavaScript so it can be
 * unit tested without ONNX Runtime or a browser.
 *
 * The exported detector returns, per image:
 *  - `logits`     [1, 300, num_classes]  (raw, before sigmoid)
 *  - `pred_boxes` [1, 300, 4]            (cx, cy, w, h normalised to 0..1)
 *
 * Shop products are not the 80 COCO classes, so the class labels are ignored on
 * purpose: we keep the best score of each query and build "object proposals"
 * (the `OnnxDetectorProposer` strategy of the spec).
 */
import { roundTo } from './tensorUtils.js';

export function sigmoid(value) {
    if (value >= 0) return 1 / (1 + Math.exp(-value));
    const exp = Math.exp(value);
    return exp / (1 + exp);
}

/**
 * Intersection over union of two `[x, y, w, h]` boxes in the same coordinate space.
 */
export function iou(a, b) {
    const [ax, ay, aw, ah] = a;
    const [bx, by, bw, bh] = b;
    const x1 = Math.max(ax, bx);
    const y1 = Math.max(ay, by);
    const x2 = Math.min(ax + aw, bx + bw);
    const y2 = Math.min(ay + ah, by + bh);
    const intersection = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
    const union = aw * ah + bw * bh - intersection;
    return union <= 0 ? 0 : intersection / union;
}

/** Keeps the highest-scoring box of every cluster above `iouThreshold`. */
export function nonMaxSuppression(boxes, iouThreshold = 0.5) {
    const sorted = [...boxes].sort((a, b) => b.score - a.score);
    const kept = [];

    for (const candidate of sorted) {
        if (kept.some((box) => iou(box.box, candidate.box) > iouThreshold)) continue;
        kept.push(candidate);
    }

    return kept;
}

/**
 * Turns the raw detector tensors into object proposals.
 *
 * @param {object} input
 * @param {Float32Array|number[]} input.logits   flat [300 * numClasses]
 * @param {Float32Array|number[]} input.boxes    flat [300 * 4] (cx cy w h, normalised)
 * @param {number} [input.numClasses=80]
 * @param {number} [input.scoreThreshold=0.15]   low on purpose: proposals, not labels
 * @param {number} [input.iouThreshold=0.5]
 * @param {number} [input.maxBoxes=10]
 * @param {number} [input.topKPerQuery=1]
 * @returns {Array<{box: [number, number, number, number], score: number}>}
 */
export function postprocessDetections(input) {
    const {
        logits,
        boxes,
        numClasses = 80,
        scoreThreshold = 0.15,
        iouThreshold = 0.5,
        maxBoxes = 10,
        topKPerQuery = 1,
    } = input || {};

    if (!logits || !boxes || !numClasses) return [];

    const queries = Math.floor(boxes.length / 4);
    const proposals = [];

    for (let query = 0; query < queries; query += 1) {
        const offset = query * numClasses;
        // The best class of the query is enough: the label itself is discarded.
        let best = -Infinity;
        for (let c = 0; c < numClasses; c += 1) {
            const value = logits[offset + c];
            if (value > best) best = value;
        }

        const score = sigmoid(best);
        if (!Number.isFinite(score) || score < scoreThreshold) continue;

        const cx = boxes[query * 4];
        const cy = boxes[query * 4 + 1];
        const w = boxes[query * 4 + 2];
        const h = boxes[query * 4 + 3];

        if (![cx, cy, w, h].every((value) => Number.isFinite(value))) continue;
        if (w <= 0 || h <= 0) continue;

        proposals.push({
            box: clampUnitBox([cx - w / 2, cy - h / 2, w, h]),
            score: roundTo(score, 4),
        });
    }

    const kept = nonMaxSuppression(proposals, iouThreshold)
        .sort((a, b) => b.score - a.score)
        .slice(0, Math.max(1, maxBoxes * Math.max(1, topKPerQuery)));

    return kept;
}

/** Clips a normalised box to the image and drops degenerate ones. */
export function clampUnitBox(box) {
    const [x, y, w, h] = box;
    const x1 = Math.min(Math.max(x, 0), 1);
    const y1 = Math.min(Math.max(y, 0), 1);
    const x2 = Math.min(Math.max(x + w, 0), 1);
    const y2 = Math.min(Math.max(y + h, 0), 1);
    return [roundTo(x1, 4), roundTo(y1, 4), roundTo(Math.max(0, x2 - x1), 4), roundTo(Math.max(0, y2 - y1), 4)];
}
