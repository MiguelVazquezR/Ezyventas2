import test, { describe } from 'node:test';
import assert from 'node:assert/strict';

import {
  DEFAULT_THRESHOLDS,
  DETECTION_STATUSES,
  STATUS_ICONS,
  STATUS_LABELS,
  classifyCandidates,
  mergeCameraDetections,
  normalizeThresholds,
  statusIcon,
  statusLabel,
  topCandidates,
} from '../../../resources/js/Composables/smartCheckout/recognition/confidence.js';

const candidates = (...pairs) => pairs.map(([productId, score]) => ({ productId, score }));

describe('confidence thresholds', () => {
  test('exposes the documented defaults in a single frozen object', () => {
    assert.deepEqual(DEFAULT_THRESHOLDS, {
      green_min: 0.85,
      green_margin: 0.08,
      yellow_min: 0.65,
    });
    assert.equal(Object.isFrozen(DEFAULT_THRESHOLDS), true);
  });

  test('falls back to defaults when values are missing or invalid', () => {
    assert.deepEqual(normalizeThresholds(), DEFAULT_THRESHOLDS);
    assert.deepEqual(normalizeThresholds(null), DEFAULT_THRESHOLDS);
    assert.deepEqual(normalizeThresholds({
      green_min: 'abc',
      green_margin: Number.NaN,
      yellow_min: null,
    }), DEFAULT_THRESHOLDS);
    assert.deepEqual(normalizeThresholds({
      green_min: true,
      green_margin: {},
      yellow_min: [],
    }), DEFAULT_THRESHOLDS);
  });

  test('clamps valid values to 0..1', () => {
    assert.deepEqual(normalizeThresholds({
      green_min: 2,
      green_margin: -1,
      yellow_min: 0.4,
    }), {
      green_min: 1,
      green_margin: 0,
      yellow_min: 0.4,
    });
  });

  test('never lets yellow_min be greater than green_min', () => {
    const thresholds = normalizeThresholds({ green_min: 0.5, yellow_min: 0.9 });

    assert.equal(thresholds.green_min, 0.9);
    assert.equal(thresholds.yellow_min, 0.9);
    assert.ok(thresholds.yellow_min <= thresholds.green_min);
  });

  test('accepts numeric strings from serialized settings', () => {
    assert.deepEqual(normalizeThresholds({
      green_min: '0.9',
      green_margin: '0.1',
      yellow_min: '0.7',
    }), {
      green_min: 0.9,
      green_margin: 0.1,
      yellow_min: 0.7,
    });
  });
});

describe('classifyCandidates', () => {
  test('green by score and margin', () => {
    assert.equal(
      classifyCandidates(candidates([1, 0.95], [2, 0.8])),
      'green',
    );
  });

  test('green at the exact score and margin boundaries', () => {
    assert.equal(
      classifyCandidates(candidates([1, 0.85], [2, 0.77])),
      'green',
    );
  });

  test('green with a single confident candidate', () => {
    assert.equal(classifyCandidates(candidates([1, 0.9])), 'green');
  });

  test('green by a valid barcode even without candidates', () => {
    assert.equal(classifyCandidates([], { barcode: 'SKU-001' }), 'green');
    assert.equal(
      classifyCandidates(candidates([1, 0.2]), { barcode: '  SKU-001  ' }),
      'green',
    );
  });

  test('empty or non-string barcodes are ignored', () => {
    assert.equal(classifyCandidates(candidates([1, 0.2]), { barcode: '   ' }), 'red');
    assert.equal(classifyCandidates(candidates([1, 0.2]), { barcode: 12345 }), 'red');
    assert.equal(classifyCandidates(candidates([1, 0.2]), { barcode: null }), 'red');
  });

  test('yellow when the gap to the runner-up is below green_margin', () => {
    assert.equal(
      classifyCandidates(candidates([1, 0.92], [2, 0.86])),
      'yellow',
    );
  });

  test('yellow when the best score is only in the yellow range', () => {
    assert.equal(classifyCandidates(candidates([1, 0.72])), 'yellow');
    assert.equal(classifyCandidates(candidates([1, 0.65])), 'yellow');
  });

  test('red when the best score is below yellow_min', () => {
    assert.equal(classifyCandidates(candidates([1, 0.64])), 'red');
    assert.equal(classifyCandidates(candidates([1, 0.64], [2, 0.3])), 'red');
  });

  test('red without candidates', () => {
    assert.equal(classifyCandidates([]), 'red');
    assert.equal(classifyCandidates(null), 'red');
    assert.equal(classifyCandidates(undefined), 'red');
    assert.equal(classifyCandidates([{ productId: 'x', score: 0.9 }]), 'red');
  });

  test('honors custom thresholds', () => {
    const thresholds = { green_min: 0.95, green_margin: 0.02, yellow_min: 0.9 };

    assert.equal(
      classifyCandidates(candidates([1, 0.9], [2, 0.88]), { thresholds }),
      'yellow',
    );
    assert.equal(
      classifyCandidates(candidates([1, 0.96], [2, 0.94]), { thresholds }),
      'green',
    );
    assert.equal(
      classifyCandidates(candidates([1, 0.89]), { thresholds }),
      'red',
    );
  });

  test('invalid thresholds fall back to the defaults', () => {
    assert.equal(
      classifyCandidates(candidates([1, 0.9], [2, 0.7]), {
        thresholds: { green_min: 'nope', green_margin: null, yellow_min: undefined },
      }),
      'green',
    );
  });

  test('a camera discrepancy downgrades green to yellow', () => {
    assert.equal(
      classifyCandidates(candidates([1, 0.99], [2, 0.5]), {
        cameraDiscrepancy: true,
      }),
      'yellow',
    );
    assert.equal(
      classifyCandidates(candidates([1, 0.99], [2, 0.5]), {
        barcode: 'SKU-001',
        cameraDiscrepancy: true,
      }),
      'yellow',
    );
  });

  test('a camera discrepancy cannot rescue a red score', () => {
    assert.equal(
      classifyCandidates(candidates([1, 0.4]), { cameraDiscrepancy: true }),
      'red',
    );
  });
});

describe('topCandidates', () => {
  test('sorts descending, rounds to 3 decimals and caps at 3', () => {
    const result = topCandidates([
      { productId: 1, score: 0.8 },
      { productId: 2, score: 0.85674 },
      { productId: 3, score: 0.9 },
      { productId: 4, score: 0.7 },
    ]);

    assert.deepEqual(result, [
      { productId: 3, score: 0.9 },
      { productId: 2, score: 0.857 },
      { productId: 1, score: 0.8 },
    ]);
  });

  test('keeps the highest score of a duplicated product', () => {
    const result = topCandidates([
      { productId: 1, score: 0.7 },
      { productId: 1, score: 0.95 },
      { productId: 2, score: 0.9 },
    ]);

    assert.deepEqual(result, [
      { productId: 1, score: 0.95 },
      { productId: 2, score: 0.9 },
    ]);
  });

  test('breaks score ties by ascending productId', () => {
    const result = topCandidates([
      { productId: 9, score: 0.9 },
      { productId: 3, score: 0.9 },
      { productId: 7, score: 0.95 },
    ]);

    assert.deepEqual(result.map((candidate) => candidate.productId), [7, 3, 9]);
  });

  test('respects a custom limit and clamps invalid limits', () => {
    const list = candidates([1, 0.9], [2, 0.8], [3, 0.7], [4, 0.6]);

    assert.equal(topCandidates(list, 2).length, 2);
    assert.equal(topCandidates(list, 0).length, 1);
    assert.equal(topCandidates(list, 100).length, 4);
    assert.equal(topCandidates(list, '2').length, 2);
  });

  test('ignores invalid entries and clamps scores to 0..1', () => {
    const result = topCandidates([
      null,
      'nope',
      { productId: 'abc', score: 0.9 },
      { productId: -1, score: 0.9 },
      { productId: 1, score: '1.4' },
      { productId: 2, score: Number.NaN },
      { productId: 3, score: -0.2 },
    ]);

    assert.deepEqual(result, [
      { productId: 1, score: 1 },
      { productId: 3, score: 0 },
    ]);
  });

  test('does not mutate the input array', () => {
    const input = candidates([1, 0.8], [2, 0.9]);
    const snapshot = JSON.parse(JSON.stringify(input));

    topCandidates(input);

    assert.deepEqual(input, snapshot);
  });

  test('accepts snake_case product ids and non-array input', () => {
    assert.deepEqual(topCandidates([{ product_id: 5, score: 0.9 }]), [
      { productId: 5, score: 0.9 },
    ]);
    assert.deepEqual(topCandidates(null), []);
  });
});

describe('status labels and icons', () => {
  test('uses Spanish labels and PrimeIcons names', () => {
    assert.equal(statusLabel('green'), 'Producto identificado');
    assert.equal(statusLabel('yellow'), 'Verificar producto');
    assert.equal(statusLabel('red'), 'Producto no identificado');

    assert.equal(statusIcon('green'), 'pi-check-circle');
    assert.equal(statusIcon('yellow'), 'pi-question-circle');
    assert.equal(statusIcon('red'), 'pi-times-circle');
  });

  test('is case-insensitive and handles unknown statuses', () => {
    assert.equal(statusLabel('GREEN'), STATUS_LABELS.green);
    assert.equal(statusIcon('Yellow'), STATUS_ICONS.yellow);
    assert.equal(statusLabel('blue'), 'Estado desconocido');
    assert.equal(statusIcon(undefined), 'pi-info-circle');
  });

  test('exposes the three detection statuses in traffic-light order', () => {
    assert.deepEqual([...DETECTION_STATUSES], ['green', 'yellow', 'red']);
  });
});

const camera = (id, mode = 'zone') => ({ id, label: `Cámara ${id}`, mode });
const box = (x = 0.1, y = 0.1) => [x, y, 0.2, 0.2];

const detection = (cameraId, productId, score, extra = {}) => ({
  cameraId,
  box: box(),
  candidates: [{ productId, score }],
  status: 'green',
  ...extra,
});

describe('mergeCameraDetections (zone cameras)', () => {
  test('adds the objects of every zone camera for the same product', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.95),
      detection('cam-1', 7, 0.95),
      detection('cam-2', 7, 0.92),
    ], [camera('cam-1'), camera('cam-2')]);

    assert.equal(result.detections.length, 1);
    assert.deepEqual(result.detections[0].candidates, [
      { productId: 7, score: 0.95 },
    ]);
    assert.equal(result.detections[0].status, 'green');
    assert.equal(result.detections[0].quantity, 3);
    assert.deepEqual(result.detections[0].cameras, ['cam-1', 'cam-2']);
    assert.deepEqual(result.discrepancies, {});
  });

  test('treats cameras missing from the catalog as zone cameras', () => {
    const result = mergeCameraDetections([
      detection('unknown-a', 7, 0.9),
      detection('unknown-b', 7, 0.9),
    ], [camera('cam-1', 'overlap')]);

    assert.equal(result.detections.length, 1);
    assert.equal(result.detections[0].quantity, 2);
    assert.deepEqual(result.discrepancies, {});
  });
});

describe('mergeCameraDetections (overlap cameras)', () => {
  test('keeps the maximum quantity and flags the discrepancy', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.95),
      detection('cam-1', 7, 0.9),
      detection('cam-2', 7, 0.92),
    ], [camera('cam-1', 'overlap'), camera('cam-2', 'overlap')]);

    assert.equal(result.detections.length, 1);

    const merged = result.detections[0];

    assert.equal(merged.quantity, 2);
    assert.equal(merged.status, 'yellow');
    assert.equal(merged.cameraDiscrepancy, true);
    assert.deepEqual(merged.cameras, ['cam-1', 'cam-2']);

    assert.deepEqual(result.discrepancies, {
      7: {
        productId: 7,
        counts: { 'cam-1': 2, 'cam-2': 1 },
        cameras: ['cam-1', 'cam-2'],
        max: 2,
        min: 1,
        quantity: 2,
      },
    });
  });

  test('counts a camera that saw nothing as zero (discrepancy)', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.95),
      detection('cam-2', 8, 0.9),
    ], [camera('cam-1', 'overlap'), camera('cam-2', 'overlap')]);

    const mergedSeven = result.detections.find(
      (item) => item.candidates[0]?.productId === 7,
    );
    const mergedEight = result.detections.find(
      (item) => item.candidates[0]?.productId === 8,
    );

    assert.equal(mergedSeven.quantity, 1);
    assert.equal(mergedSeven.status, 'yellow');
    assert.deepEqual(result.discrepancies['7'].counts, { 'cam-1': 1, 'cam-2': 0 });

    assert.equal(mergedEight.quantity, 1);
    assert.equal(mergedEight.status, 'yellow');
    assert.deepEqual(result.discrepancies['8'].counts, { 'cam-1': 0, 'cam-2': 1 });
  });

  test('does not flag a discrepancy when the cameras agree', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.95),
      detection('cam-2', 7, 0.94),
    ], [camera('cam-1', 'overlap'), camera('cam-2', 'overlap')]);

    assert.equal(result.detections[0].quantity, 1);
    assert.equal(result.detections[0].status, 'green');
    assert.equal('cameraDiscrepancy' in result.detections[0], false);
    assert.deepEqual(result.discrepancies, {});
  });

  test('a single overlap camera cannot disagree with itself', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.95),
      detection('cam-1', 7, 0.95),
    ], [camera('cam-1', 'overlap')]);

    assert.equal(result.detections[0].quantity, 2);
    assert.equal(result.detections[0].status, 'green');
    assert.deepEqual(result.discrepancies, {});
  });

  test('mixes zone sums with overlap maxima', () => {
    const result = mergeCameraDetections([
      detection('zone-1', 7, 0.95),
      detection('zone-1', 7, 0.95),
      detection('overlap-1', 7, 0.9),
      detection('overlap-2', 7, 0.9),
    ], [
      camera('zone-1', 'zone'),
      camera('overlap-1', 'overlap'),
      camera('overlap-2', 'overlap'),
    ]);

    assert.equal(result.detections[0].quantity, 3);
    assert.deepEqual(result.discrepancies, {});
  });

  test('forces yellow on a discrepancy even with a low score', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.4),
      detection('cam-2', 7, 0.4),
      detection('cam-1', 7, 0.4),
    ], [camera('cam-1', 'overlap'), camera('cam-2', 'overlap')]);

    assert.equal(result.detections[0].status, 'yellow');
    assert.equal(result.detections[0].cameraDiscrepancy, true);
  });
});

describe('mergeCameraDetections (edge cases)', () => {
  test('propagates the barcode and lets it turn the product green', () => {
    const result = mergeCameraDetections([
      {
        cameraId: 'cam-1',
        box: box(),
        candidates: [{ productId: 7, score: 0.2 }],
        status: 'red',
        barcode: 'SKU-007',
      },
    ], [camera('cam-1')]);

    assert.equal(result.detections[0].status, 'green');
    assert.equal(result.detections[0].barcode, 'SKU-007');
  });

  test('keeps detections without candidates as red pass-throughs', () => {
    const result = mergeCameraDetections([
      { cameraId: 'cam-1', box: box(0.1, 0.1), candidates: [], status: 'red' },
      { cameraId: 'cam-2', box: box(0.5, 0.5), candidates: [], status: 'red' },
      detection('cam-1', 7, 0.9),
    ], [camera('cam-1'), camera('cam-2')]);

    assert.equal(result.detections.length, 3);

    const reds = result.detections.filter((item) => item.status === 'red');

    assert.equal(reds.length, 2);
    assert.deepEqual(reds[0].candidates, []);
    assert.equal(reds[0].quantity, 1);
    assert.deepEqual(result.discrepancies, {});
  });

  test('ignores invalid detections and handles empty input', () => {
    const result = mergeCameraDetections([
      null,
      { cameraId: '', box: box(), candidates: [], status: 'red' },
      { cameraId: 'cam-1', box: [0, 0, 2, 1], candidates: [], status: 'red' },
    ], [camera('cam-1')]);

    assert.deepEqual(result, { detections: [], discrepancies: {} });
    assert.deepEqual(mergeCameraDetections(null, null), {
      detections: [],
      discrepancies: {},
    });
  });

  test('uses the best scoring detection as representative', () => {
    const result = mergeCameraDetections([
      {
        cameraId: 'cam-1',
        box: [0.1, 0.1, 0.2, 0.2],
        candidates: [{ productId: 7, score: 0.7 }],
        status: 'yellow',
      },
      {
        cameraId: 'cam-2',
        box: [0.4, 0.4, 0.3, 0.3],
        candidates: [{ productId: 7, score: 0.95 }, { productId: 8, score: 0.8 }],
        status: 'green',
      },
    ], [camera('cam-1'), camera('cam-2')]);

    assert.equal(result.detections[0].cameraId, 'cam-2');
    assert.deepEqual(result.detections[0].box, [0.4, 0.4, 0.3, 0.3]);
    assert.equal(result.detections[0].candidates[0].score, 0.95);
  });

  test('applies custom thresholds when recomputing the status', () => {
    const result = mergeCameraDetections([
      detection('cam-1', 7, 0.9),
    ], [camera('cam-1')], {
      thresholds: { green_min: 0.95, yellow_min: 0.85 },
    });

    assert.equal(result.detections[0].status, 'yellow');
  });
});
