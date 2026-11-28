/**
 * Pure inference helpers: tensors, detector post-processing, frame stability,
 * the empty-counter proposer and the vector gallery.
 *
 * Nothing here touches the DOM or ONNX Runtime, which is exactly why these tests
 * can run in Node while the browser path is exercised manually.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
  DINO_MEAN,
  DINO_STD,
  base64ToFloat32,
  bytesToBase64,
  cosineSimilarity,
  dotProduct,
  float32ToBase64,
  normalizeL2,
  rgbaToNchwFloat32,
  roundTo,
} from '../../../resources/js/Composables/smartCheckout/inference/tensorUtils.js';
import {
  clampUnitBox,
  iou,
  nonMaxSuppression,
  postprocessDetections,
  sigmoid,
} from '../../../resources/js/Composables/smartCheckout/inference/detectorPostprocess.js';
import {
  SIGNATURE_HEIGHT,
  SIGNATURE_WIDTH,
  createStabilityTracker,
  grayscaleSignature,
  signatureDistance,
} from '../../../resources/js/Composables/smartCheckout/inference/frameStability.js';
import {
  captureEmptyCounterReference,
  connectedComponents,
  differenceMask,
  dilateMask,
  proposeFromDifference,
} from '../../../resources/js/Composables/smartCheckout/inference/diffProposer.js';
import { decodeGallery, mergeGallery, searchGallery } from '../../../resources/js/Composables/smartCheckout/inference/gallery.js';

/* ------------------------------------------------------------------ tensors */

test('rgbaToNchwFloat32 lays out channels in NCHW with ImageNet normalisation', () => {
    // 1x1 pixel: pure red.
    const rgba = new Uint8ClampedArray([255, 0, 0, 255]);
    const tensor = rgbaToNchwFloat32(rgba, 1, 1);

    assert.equal(tensor.length, 3);
    assert.ok(Math.abs(tensor[0] - (1 - DINO_MEAN[0]) / DINO_STD[0]) < 1e-6);
    assert.ok(Math.abs(tensor[1] - (0 - DINO_MEAN[1]) / DINO_STD[1]) < 1e-6);
    assert.ok(Math.abs(tensor[2] - (0 - DINO_MEAN[2]) / DINO_STD[2]) < 1e-6);
});

test('rgbaToNchwFloat32 keeps rows contiguous per channel (2x1 image)', () => {
    // Pixel 0 = red, pixel 1 = green.
    const rgba = new Uint8ClampedArray([255, 0, 0, 255, 0, 255, 0, 255]);
    const tensor = rgbaToNchwFloat32(rgba, 2, 1);

    assert.equal(tensor.length, 6);

    const norm = (value, channel) => (value - DINO_MEAN[channel]) / DINO_STD[channel];
    // Layout: [R0, R1, G0, G1, B0, B1].
    assert.ok(Math.abs(tensor[0] - norm(1, 0)) < 1e-6);
    assert.ok(Math.abs(tensor[1] - norm(0, 0)) < 1e-6);
    assert.ok(Math.abs(tensor[2] - norm(0, 1)) < 1e-6);
    assert.ok(Math.abs(tensor[3] - norm(1, 1)) < 1e-6);
    assert.ok(Math.abs(tensor[4] - norm(0, 2)) < 1e-6);
    assert.ok(Math.abs(tensor[5] - norm(0, 2)) < 1e-6);
});

test('normalizeL2 returns a unit vector and tolerates zeros', () => {
    const unit = normalizeL2(Float32Array.from([3, 4]));
    assert.ok(Math.abs(unit[0] - 0.6) < 1e-6);
    assert.ok(Math.abs(unit[1] - 0.8) < 1e-6);
    assert.equal(dotProduct(unit, unit).toFixed(6), '1.000000');

    const zero = normalizeL2(Float32Array.from([0, 0]));
    assert.deepEqual(Array.from(zero), [0, 0]);
});

test('cosineSimilarity matches the dot product for normalised vectors', () => {
    const a = normalizeL2([1, 2, 3]);
    const b = normalizeL2([3, 2, 1]);
    assert.ok(Math.abs(cosineSimilarity(a, b) - dotProduct(a, b)) < 1e-6);
    assert.equal(roundTo(cosineSimilarity(a, a), 3), 1);
});

test('float32 base64 round trip is bit exact', () => {
    const vector = Float32Array.from([0.1, -0.25, 1.5, 1e-7, 0.3333333]);
    const decoded = base64ToFloat32(float32ToBase64(vector));

    assert.equal(decoded.length, vector.length);
    for (let i = 0; i < vector.length; i += 1) {
        assert.equal(Object.is(decoded[i], vector[i]), true);
    }
    // Padding/length sanity: 5 floats = 20 bytes.
    assert.equal(bytesToBase64(new Uint8Array(vector.buffer)).length > 0, true);
});

/* --------------------------------------------------------------- detector */

test('sigmoid is 0.5 at zero and saturates', () => {
    assert.equal(sigmoid(0), 0.5);
    assert.ok(sigmoid(20) > 0.999);
    assert.ok(sigmoid(-20) < 0.001);
});

test('iou of identical boxes is 1 and of disjoint boxes is 0', () => {
    assert.equal(iou([0, 0, 1, 1], [0, 0, 1, 1]), 1);
    assert.equal(iou([0, 0, 0.5, 0.5], [0.6, 0.6, 0.2, 0.2]), 0);
});

test('nonMaxSuppression keeps the best box of an overlapping cluster', () => {
    const kept = nonMaxSuppression([
        { box: [0, 0, 0.5, 0.5], score: 0.6 },
        { box: [0.02, 0.02, 0.5, 0.5], score: 0.9 },
        { box: [0.7, 0.7, 0.2, 0.2], score: 0.3 },
    ], 0.5);

    assert.equal(kept.length, 2);
    assert.equal(kept[0].score, 0.9);
    assert.equal(kept[1].score, 0.3);
});

test('postprocessDetections turns raw logits and cxcywh boxes into proposals', () => {
    // 2 queries, 2 classes: the second query duplicates the first box with a
    // slightly lower score, so NMS must drop it.
    const logits = Float32Array.from([10, 0, 9, 0]);
    const boxes = Float32Array.from([0.5, 0.5, 0.2, 0.2, 0.5, 0.5, 0.2, 0.2]);

    const proposals = postprocessDetections({ logits, boxes, numClasses: 2, scoreThreshold: 0.15, iouThreshold: 0.5 });

    assert.equal(proposals.length, 1);
    const [x, y, w, h] = proposals[0].box;
    assert.ok(Math.abs(x - 0.4) < 1e-3);
    assert.ok(Math.abs(y - 0.4) < 1e-3);
    assert.ok(Math.abs(w - 0.2) < 1e-3);
    assert.ok(Math.abs(h - 0.2) < 1e-3);
    assert.ok(proposals[0].score > 0.99);
});

test('postprocessDetections filters by score and ignores degenerate boxes', () => {
    const lowScore = postprocessDetections({
        logits: Float32Array.from([-5, -6]),
        boxes: Float32Array.from([0.5, 0.5, 0.2, 0.2]),
        numClasses: 2,
    });
    assert.equal(lowScore.length, 0);

    const zeroWidth = postprocessDetections({
        logits: Float32Array.from([10, 0]),
        boxes: Float32Array.from([0.5, 0.5, 0, 0.2]),
        numClasses: 2,
    });
    assert.equal(zeroWidth.length, 0);
});

test('clampUnitBox clips boxes to the image', () => {
    assert.deepEqual(clampUnitBox([-0.1, -0.1, 0.3, 0.3]), [0, 0, 0.2, 0.2]);
    assert.deepEqual(clampUnitBox([0.9, 0.9, 0.5, 0.5]), [0.9, 0.9, 0.1, 0.1]);
});

/* -------------------------------------------------------------- stability */

function frameOf(width, height, luma) {
    const rgba = new Uint8ClampedArray(width * height * 4);
    for (let i = 0; i < width * height; i += 1) {
        rgba[i * 4] = luma;
        rgba[i * 4 + 1] = luma;
        rgba[i * 4 + 2] = luma;
        rgba[i * 4 + 3] = 255;
    }
    return rgba;
}

test('grayscaleSignature averages each cell of the thumbnail', () => {
    const signature = grayscaleSignature(frameOf(64, 36, 128), 64, 36);
    assert.equal(signature.length, SIGNATURE_WIDTH * SIGNATURE_HEIGHT);
    signature.forEach((value) => assert.ok(Math.abs(value - 128 / 255) < 1e-6));
});

test('signatureDistance is 0 for identical frames and grows with the change', () => {
    const a = grayscaleSignature(frameOf(64, 36, 100), 64, 36);
    const b = grayscaleSignature(frameOf(64, 36, 100), 64, 36);
    const c = grayscaleSignature(frameOf(64, 36, 220), 64, 36);

    assert.equal(signatureDistance(a, b), 0);
    assert.ok(signatureDistance(a, c) > 0.3);
});

test('stability tracker needs consecutive similar frames', () => {
    const tracker = createStabilityTracker({ threshold: 0.02, samples: 3 });
    const still = grayscaleSignature(frameOf(64, 36, 120), 64, 36);
    const moved = grayscaleSignature(frameOf(64, 36, 200), 64, 36);

    assert.equal(tracker.push(still).stable, false);
    assert.equal(tracker.push(still).stable, false);
    assert.equal(tracker.push(still).stable, true);
    assert.equal(tracker.push(moved).stable, false);
    tracker.reset();
    assert.equal(tracker.push(still).stable, false);
});

/* ------------------------------------------------------- empty counter diff */

test('mask, dilation and connected components find one blob', () => {
    const gridWidth = 32;
    const gridHeight = 18;
    const reference = new Float32Array(gridWidth * gridHeight).fill(0.5);
    const current = new Float32Array(reference);
    for (let y = 5; y < 9; y += 1) {
        for (let x = 10; x < 14; x += 1) current[y * gridWidth + x] = 0.9;
    }

    const mask = differenceMask(reference, current, 0.08);
    assert.equal(mask.filter((value) => value === 1).length, 16);

    const dilated = dilateMask(mask, gridWidth, gridHeight);
    assert.ok(dilated.filter((value) => value === 1).length > 16);

    const components = connectedComponents(dilated, gridWidth, gridHeight);
    assert.equal(components.length, 1);
    assert.ok(components[0].cells >= 16);
});

test('proposeFromDifference returns a normalised box around the new object', () => {
    const reference = captureEmptyCounterReference(frameOf(64, 36, 90), 64, 36);
    const current = captureEmptyCounterReference(frameOf(64, 36, 90), 64, 36);
    for (let y = 10; y < 16; y += 1) {
        for (let x = 20; x < 28; x += 1) current.signature[y * reference.gridWidth + x] = 0.95;
    }

    const proposals = proposeFromDifference({ reference, current });

    assert.equal(proposals.length, 1);
    const [x, y, w, h] = proposals[0].box;
    assert.ok(x > 0.4 && x < 0.75, `x inesperado: ${x}`);
    assert.ok(y > 0.3 && y < 0.75, `y inesperado: ${y}`);
    assert.ok(w > 0.1 && w < 0.6, `w inesperado: ${w}`);
    assert.ok(h > 0.1 && h < 0.7, `h inesperado: ${h}`);
});

test('proposeFromDifference returns nothing when the counter is unchanged', () => {
    const reference = captureEmptyCounterReference(frameOf(64, 36, 90), 64, 36);
    const current = captureEmptyCounterReference(frameOf(64, 36, 90), 64, 36);
    assert.deepEqual(proposeFromDifference({ reference, current }), []);
});

test('proposeFromDifference can ignore tiny blobs', () => {
    const reference = captureEmptyCounterReference(frameOf(64, 36, 90), 64, 36);
    const current = captureEmptyCounterReference(frameOf(64, 36, 90), 64, 36);
    current.signature[0] = 1; // a single changed cell

    assert.equal(proposeFromDifference({ reference, current, minAreaRatio: 0.01 }).length, 0);
    assert.equal(proposeFromDifference({ reference, current, minAreaRatio: 0.0005, dilate: false }).length, 1);
});

/* ----------------------------------------------------------------- gallery */

test('decodeGallery rebuilds Float32Array slices from the packed blob', () => {
    const first = normalizeL2([1, 0]).map((value) => Math.fround(value));
    const second = normalizeL2([0, 1]).map((value) => Math.fround(value));
    const flat = new Float32Array([...first, ...second]);

    const gallery = decodeGallery({
        dim: 2,
        model_version: 'test',
        gallery_version: 'v1',
        items: [
            { product_id: 10, offset: 0, length: 2 },
            { product_id: 20, offset: 2, length: 2 },
        ],
        vectors: float32ToBase64(flat),
    });

    assert.equal(gallery.dim, 2);
    assert.equal(gallery.items.length, 2);
    assert.equal(gallery.items[1].productId, 20);
    assert.ok(Math.abs(gallery.items[1].vector[1] - 1) < 1e-6);
});

test('searchGallery returns the best product per id, sorted and capped', () => {
    const gallery = decodeGallery({
        dim: 2,
        items: [
            { product_id: 10, offset: 0, length: 2 },
            { product_id: 10, offset: 2, length: 2 },
            { product_id: 20, offset: 4, length: 2 },
        ],
        vectors: float32ToBase64(Float32Array.from([
            ...normalizeL2([1, 0]),
            ...normalizeL2([0.9, 0.1]),
            ...normalizeL2([0, 1]),
        ])),
    });

    const results = searchGallery(gallery, normalizeL2([1, 0]), { topK: 3 });

    assert.equal(results.length, 2);
    assert.equal(results[0].productId, 10);
    assert.ok(results[0].score > 0.99);
    assert.equal(results[1].productId, 20);
});

test('searchGallery handles an empty gallery and minScore', () => {
    assert.deepEqual(searchGallery({ items: [] }, normalizeL2([1, 0])), []);

    const gallery = decodeGallery({
        dim: 2,
        items: [{ product_id: 10, offset: 0, length: 2 }],
        vectors: float32ToBase64(Float32Array.from(normalizeL2([1, 0]))),
    });

    assert.equal(searchGallery(gallery, normalizeL2([1, 0]), { minScore: 1.1 }).length, 0);
});

test('mergeGallery keeps the previous copy when the version did not change', () => {
    const a = { galleryVersion: 'v1', dim: 384, items: [] };
    const b = { galleryVersion: 'v1', dim: 384, items: [] };
    const c = { galleryVersion: 'v2', dim: 384, items: [] };

    assert.equal(mergeGallery(a, b), a);
    assert.equal(mergeGallery(a, c), c);
    assert.equal(mergeGallery(null, a), a);
});
