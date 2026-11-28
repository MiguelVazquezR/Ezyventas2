import test, { describe } from 'node:test';
import assert from 'node:assert/strict';

import { RecognitionEngine } from '../../../resources/js/Composables/smartCheckout/recognition/RecognitionEngine.js';
import {
  MockRecognitionEngine,
  SAMPLE_SOURCE_LABELS,
  SAMPLE_SOURCES,
} from '../../../resources/js/Composables/smartCheckout/recognition/MockRecognitionEngine.js';
import {
  RECOGNITION_ENGINE_KINDS,
  RECOGNITION_ENGINE_LABELS,
  createRecognitionEngine,
} from '../../../resources/js/Composables/smartCheckout/recognition/index.js';
import {
  DEFAULT_THRESHOLDS,
  classifyCandidates,
} from '../../../resources/js/Composables/smartCheckout/recognition/confidence.js';

/**
 * Resolve with the next payload of `event`.
 *
 * @param {RecognitionEngine} engine
 * @param {string} [event]
 * @param {number} [timeout]
 */
function once(engine, event = 'detection', timeout = 1000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      off();
      reject(new Error(`Timed out waiting for "${event}"`));
    }, timeout);

    const off = engine.on(event, (payload) => {
      clearTimeout(timer);
      off();
      resolve(payload);
    });
  });
}

/**
 * Poll `predicate` until it is truthy.
 *
 * @param {() => unknown} predicate
 * @param {number} [timeout]
 */
function waitFor(predicate, timeout = 1000) {
  const startedAt = Date.now();

  return new Promise((resolve, reject) => {
    const poll = () => {
      let value;

      try {
        value = predicate();
      } catch (error) {
        reject(error);

        return;
      }

      if (value) {
        resolve(value);

        return;
      }

      if (Date.now() - startedAt > timeout) {
        reject(new Error('Timed out waiting for condition'));

        return;
      }

      setTimeout(poll, 5);
    };

    poll();
  });
}

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const PRODUCTS = [
  {
    id: 1,
    name: 'Coca-Cola 600 ml',
    price: 18,
    sku: 'SKU-001',
    is_bulk: false,
    measure_unit: 'Pza',
  },
  {
    id: 2,
    name: 'Sabritas original',
    selling_price: 15,
    sku: 'SKU-002',
  },
  {
    id: 3,
    name: 'Plátano',
    price: 24.5,
    sku: 'SKU-003',
    is_bulk: true,
    measure_unit: 'Kg',
  },
];

const CAMERAS = [
  { id: 'cam-1', label: 'Cámara 1', mode: 'zone', resolution: '1280x720' },
  { id: 'cam-2', label: 'Cámara 2', mode: 'overlap' },
];

const createMock = (overrides = {}) => new MockRecognitionEngine({
  products: PRODUCTS,
  cameras: CAMERAS,
  seed: 1,
  ...overrides,
});

const boxesOverlap = (left, right) => (
  left[0] < right[0] + right[2]
  && right[0] < left[0] + left[2]
  && left[1] < right[1] + right[3]
  && right[1] < left[1] + left[3]
);

const collectSampleIds = async (engine, cycles) => {
  const ids = new Set();

  for (let index = 0; index < cycles; index += 1) {
    const detections = await engine.analyze([]);

    for (const detection of detections) {
      for (const candidate of detection.candidates) ids.add(candidate.productId);
    }
  }

  return ids;
};

describe('RecognitionEngine (base)', () => {
  test('declares the base state', () => {
    const engine = new RecognitionEngine();

    assert.equal(engine.kind, 'base');
    assert.equal(engine.status, 'idle');
    assert.equal(engine.isInitialized, false);
    assert.equal(engine.modelVersion, 'base');
  });

  test('throws "not implemented" for the abstract methods', async () => {
    const engine = new RecognitionEngine();

    await assert.rejects(engine.init({ galleryVersion: 'v1' }), /not implemented/i);
    await assert.rejects(engine.analyze([]), /not implemented/i);
    await assert.rejects(
      engine.addSample(1, null, 'quick_register'),
      /not implemented/i,
    );
    assert.throws(() => engine.dispose(), /not implemented/i);
  });

  test('supports on/off with a safe unsubscribe', () => {
    const engine = new RecognitionEngine();
    const seen = [];
    const off = engine.on('detection', (payload) => seen.push(payload));

    engine.emit('detection', ['first']);
    off();
    engine.emit('detection', ['second']);

    assert.deepEqual(seen, [['first']]);
    assert.throws(() => engine.on('detection', null), TypeError);
  });

  test('a throwing listener does not break the engine or the other listeners', () => {
    const engine = new RecognitionEngine();
    const seen = [];

    engine.on('status', () => {
      throw new Error('listener boom');
    });
    engine.on('status', (payload) => seen.push(payload.status));

    engine._setStatus('ready');

    assert.deepEqual(seen, ['ready']);
  });

  test('exposes onDetection/onError/onStatus conveniences', () => {
    const engine = new RecognitionEngine();
    const seen = [];

    engine.onDetection(() => seen.push('detection'));
    engine.onStatus(() => seen.push('status'));
    engine.onError(() => seen.push('error'));

    engine.emit('detection', []);
    engine.emit('status', { status: 'ready' });
    engine.emit('error', { code: 'x', message: 'y' });

    assert.deepEqual(seen, ['detection', 'status', 'error']);
  });

  test('emitError accepts an Error and a plain object', () => {
    const engine = new RecognitionEngine();
    const seen = [];

    engine.onError((payload) => seen.push(payload));

    const error = new Error('boom');
    error.code = 'boom_code';
    engine.emitError(error);
    engine.emitError({ code: 'plain_code', message: 'plain' }, 'fallback');

    assert.equal(seen[0].code, 'boom_code');
    assert.equal(seen[0].message, 'boom');
    assert.equal(seen[0].error, error);
    assert.deepEqual(seen[1], { code: 'plain_code', message: 'plain' });
  });

  test('assertInitialized guards the public methods', () => {
    const engine = new RecognitionEngine();

    assert.throws(() => engine.assertInitialized(), /inicializado/i);

    engine.isInitialized = true;

    assert.doesNotThrow(() => engine.assertInitialized());
  });

  test('normalizeDetection accepts and canonicalizes a valid detection', () => {
    const engine = new RecognitionEngine();

    const normalized = engine.normalizeDetection({
      cameraId: ' cam-1 ',
      box: [0.1, 0.2, 0.3, 0.4],
      candidates: [
        { productId: 2, score: 0.7 },
        { productId: 1, score: 0.9 },
        { productId: 1, score: 0.8 },
      ],
      status: 'GREEN',
      barcode: ' SKU-001 ',
    });

    assert.deepEqual(normalized, {
      cameraId: 'cam-1',
      box: [0.1, 0.2, 0.3, 0.4],
      candidates: [
        { productId: 1, score: 0.9 },
        { productId: 2, score: 0.7 },
      ],
      status: 'green',
      barcode: 'SKU-001',
    });
  });

  test('normalizeDetection accepts a red detection without candidates', () => {
    const engine = new RecognitionEngine();

    const normalized = engine.normalizeDetection({
      cameraId: 'cam-1',
      box: [0, 0, 1, 1],
      status: 'red',
    });

    assert.deepEqual(normalized, {
      cameraId: 'cam-1',
      box: [0, 0, 1, 1],
      candidates: [],
      status: 'red',
    });
  });

  test('normalizeDetection discards invalid boxes, statuses and ids', () => {
    const engine = new RecognitionEngine();

    const invalid = [
      null,
      'detection',
      {},
      { cameraId: '', box: [0, 0, 1, 1], status: 'green' },
      { cameraId: '  ', box: [0, 0, 1, 1], status: 'green' },
      { cameraId: 'cam-1', box: [0, 0, 1.2, 1], status: 'green' },
      { cameraId: 'cam-1', box: [-0.1, 0, 0.2, 0.2], status: 'green' },
      { cameraId: 'cam-1', box: [0.9, 0.1, 0.2, 0.2], status: 'green' },
      { cameraId: 'cam-1', box: [0, 0, 0, 0.2], status: 'green' },
      { cameraId: 'cam-1', box: [0, 0, 0.2, 0], status: 'green' },
      { cameraId: 'cam-1', box: [0, 0, 1], status: 'green' },
      { cameraId: 'cam-1', box: [0, 0, 1, 1], status: 'blue' },
      { cameraId: 'cam-1', box: [0, 0, 1, 1], status: undefined },
      { cameraId: 'cam-1', box: [0, 0, 1, 'x'], status: 'green' },
      { cameraId: 'cam-1', box: [0, 0, 1, Number.NaN], status: 'green' },
      {
        cameraId: 'cam-1',
        box: [0, 0, 1, 1],
        candidates: 'nope',
        status: 'green',
      },
    ];

    for (const detection of invalid) {
      assert.equal(engine.normalizeDetection(detection), null);
    }
  });
});

describe('recognition engine factory', () => {
  test('creates the mock engine', () => {
    const engine = createRecognitionEngine('mock', {
      products: PRODUCTS,
      cameras: CAMERAS,
    });

    assert.ok(engine instanceof MockRecognitionEngine);
    assert.equal(engine.kind, 'mock');
  });

  test('rejects unknown engine kinds with a clear message', () => {
    // The ONNX engine exists but is created asynchronously (it loads a worker).
    assert.throws(() => createRecognitionEngine('onnx'), /asíncrona/);
    assert.throws(() => createRecognitionEngine('tensorflow'), /desconocido/);
    assert.throws(() => createRecognitionEngine(), /desconocido/);
  });

  test('publishes the Spanish catalog', () => {
    assert.equal(Object.isFrozen(RECOGNITION_ENGINE_KINDS), true);
    assert.equal(RECOGNITION_ENGINE_KINDS.length, 2);
    assert.equal(RECOGNITION_ENGINE_KINDS[0].value, 'mock');
    assert.equal(RECOGNITION_ENGINE_KINDS[0].label, 'Motor simulado');
    assert.equal(RECOGNITION_ENGINE_KINDS[1].value, 'onnx');
    assert.equal(RECOGNITION_ENGINE_LABELS.mock, 'Motor simulado');
    assert.equal(RECOGNITION_ENGINE_LABELS.onnx, 'Motor ONNX (cámara)');
  });
});

describe('MockRecognitionEngine options and status', () => {
  test('applies the documented defaults', () => {
    const engine = new MockRecognitionEngine();

    assert.equal(engine.intervalMs, 2500);
    assert.equal(engine.seed, 1);
    assert.equal(engine.modelVersion, 'mock-1.0');
    assert.equal(engine.autoAccept, false);
    assert.deepEqual(engine.thresholds, DEFAULT_THRESHOLDS);
    assert.deepEqual(engine.products, []);
    assert.deepEqual(engine.cameras, []);
    assert.equal(engine.latencyMs, 0);
  });

  test('honors custom options and sanitizes invalid ones', () => {
    const custom = new MockRecognitionEngine({
      products: PRODUCTS,
      cameras: CAMERAS,
      thresholds: { green_min: 0.9, green_margin: 0.1, yellow_min: 0.7 },
      autoAccept: true,
      intervalMs: 40,
      seed: 42,
      modelVersion: 'mock-2.0',
    });

    assert.equal(custom.intervalMs, 40);
    assert.equal(custom.seed, 42);
    assert.equal(custom.autoAccept, true);
    assert.equal(custom.modelVersion, 'mock-2.0');
    assert.deepEqual(custom.thresholds, {
      green_min: 0.9,
      green_margin: 0.1,
      yellow_min: 0.7,
    });

    const fallback = new MockRecognitionEngine({
      intervalMs: 'nope',
      seed: Number.NaN,
      modelVersion: '   ',
    });

    assert.equal(fallback.intervalMs, 2500);
    assert.equal(fallback.seed, 1);
    assert.equal(fallback.modelVersion, 'mock-1.0');
  });

  test('init emits loading then ready and stores the gallery version', async () => {
    const engine = createMock();
    const statuses = [];

    engine.onStatus((payload) => statuses.push(payload.status));

    await engine.init({ galleryVersion: 'gallery-7' });

    assert.deepEqual(statuses, ['loading', 'ready']);
    assert.equal(engine.status, 'ready');
    assert.equal(engine.isInitialized, true);
    assert.equal(engine.galleryVersion, 'gallery-7');
  });

  test('init falls back to a default gallery version', async () => {
    const engine = createMock();

    await engine.init();

    assert.equal(engine.galleryVersion, 'mock-gallery');
  });

  test('analyze requires init', async () => {
    const engine = createMock();

    await assert.rejects(engine.analyze([]), /inicializado/i);
    await assert.rejects(engine.addSample(1, null, 'quick_register'), /inicializado/i);
  });
});

describe('MockRecognitionEngine analyze', () => {
  test('returns one to three valid, non-overlapping detections per camera', async () => {
    const engine = createMock();

    await engine.init({ galleryVersion: 'g-1' });

    const detections = await engine.analyze([]);
    const byCamera = new Map();

    for (const detection of detections) {
      const normalized = engine.normalizeDetection(detection);

      assert.ok(normalized, `invalid detection: ${JSON.stringify(detection)}`);
      assert.deepEqual(normalized, detection);

      assert.ok(Array.isArray(detection.box));
      assert.equal(detection.box.length, 4);

      for (const value of detection.box) {
        assert.ok(value >= 0 && value <= 1, `box out of range: ${value}`);
      }

      assert.ok(detection.box[2] > 0 && detection.box[3] > 0);
      assert.ok(detection.candidates.length >= 1 && detection.candidates.length <= 3);

      for (let index = 1; index < detection.candidates.length; index += 1) {
        assert.ok(
          detection.candidates[index - 1].score >= detection.candidates[index].score,
          'candidates must be sorted descending',
        );
      }

      assert.equal(
        detection.status,
        classifyCandidates(detection.candidates, {
          thresholds: engine.thresholds,
          barcode: detection.barcode,
        }),
        'status must agree with the thresholds',
      );

      const list = byCamera.get(detection.cameraId) ?? [];

      list.push(detection);
      byCamera.set(detection.cameraId, list);
    }

    assert.equal(byCamera.size, CAMERAS.length);

    for (const list of byCamera.values()) {
      assert.ok(list.length >= 1 && list.length <= 3);

      for (let left = 0; left < list.length; left += 1) {
        for (let right = left + 1; right < list.length; right += 1) {
          assert.equal(
            boxesOverlap(list[left].box, list[right].box),
            false,
            'boxes of the same camera must not overlap',
          );
        }
      }
    }
  });

  test('is deterministic for the same seed', async () => {
    const first = createMock({ seed: 7 });
    const second = createMock({ seed: 7 });

    await first.init({ galleryVersion: 'g-1' });
    await second.init({ galleryVersion: 'g-1' });

    const firstBatch = await first.analyze([]);
    const secondBatch = await second.analyze([]);

    assert.deepEqual(firstBatch, secondBatch);
  });

  test('different seeds produce different batches', async () => {
    const first = createMock({ seed: 1 });
    const second = createMock({ seed: 99 });

    await first.init({ galleryVersion: 'g-1' });
    await second.init({ galleryVersion: 'g-1' });

    const firstBatch = await first.analyze([]);
    const secondBatch = await second.analyze([]);

    assert.notDeepEqual(firstBatch, secondBatch);
  });

  test('produces red detections from time to time', async () => {
    const engine = createMock({ seed: 1 });
    const statuses = new Set();

    await engine.init({ galleryVersion: 'g-1' });

    for (let index = 0; index < 25; index += 1) {
      const detections = await engine.analyze([]);

      for (const detection of detections) statuses.add(detection.status);
    }

    assert.ok(statuses.has('red'), `expected a red detection, got ${[...statuses]}`);
  });

  test('uses the product SKU as an occasional barcode', async () => {
    const engine = createMock({ seed: 1 });
    const skus = new Set(PRODUCTS.map((product) => product.sku));

    await engine.init({ galleryVersion: 'g-1' });

    for (let index = 0; index < 40; index += 1) {
      const detections = await engine.analyze([]);
      const withBarcode = detections.find((detection) => detection.barcode);

      if (withBarcode) {
        assert.ok(skus.has(withBarcode.barcode));
        assert.equal(withBarcode.status, 'green');

        return;
      }
    }

    assert.fail('expected at least one barcode over 40 analyze cycles');
  });

  test('applies the configured thresholds instead of hard-coded ones', async () => {
    const thresholds = { green_min: 0.99, green_margin: 0.3, yellow_min: 0.9 };
    const engine = createMock({ thresholds, seed: 5 });

    await engine.init({ galleryVersion: 'g-1' });

    for (let index = 0; index < 10; index += 1) {
      const detections = await engine.analyze([]);

      for (const detection of detections) {
        assert.equal(
          detection.status,
          classifyCandidates(detection.candidates, {
            thresholds,
            barcode: detection.barcode,
          }),
        );

        if (detection.status === 'green') {
          assert.ok(
            detection.barcode || detection.candidates[0].score >= 0.99,
            'green requires a barcode or a score above green_min',
          );
        }
      }
    }
  });

  test('updates latencyMs on every analyze', async () => {
    const engine = createMock();

    await engine.init({ galleryVersion: 'g-1' });

    for (let index = 0; index < 5; index += 1) {
      await engine.analyze([]);

      assert.ok(Number.isFinite(engine.latencyMs));
      assert.ok(engine.latencyMs >= 0);
    }
  });

  test('returns no detections without cameras', async () => {
    const engine = new MockRecognitionEngine({
      products: PRODUCTS,
      cameras: [],
      seed: 1,
    });

    await engine.init({ galleryVersion: 'g-1' });

    assert.deepEqual(await engine.analyze([]), []);
  });

  test('returns red detections without candidates when there are no products', async () => {
    const engine = new MockRecognitionEngine({
      products: [],
      cameras: CAMERAS,
      seed: 1,
    });

    await engine.init({ galleryVersion: 'g-1' });

    const detections = await engine.analyze([]);

    assert.ok(detections.length >= 1);

    for (const detection of detections) {
      assert.deepEqual(detection.candidates, []);
      assert.equal(detection.status, 'red');
    }
  });
});

describe('MockRecognitionEngine loop', () => {
  test('start emits detections on the interval', async () => {
    const engine = createMock({ intervalMs: 15 });
    const statuses = [];

    engine.onStatus((payload) => statuses.push(payload));

    await engine.init({ galleryVersion: 'g-1' });

    const pending = once(engine, 'detection');

    engine.start();

    const detections = await pending;

    assert.ok(Array.isArray(detections));
    assert.ok(detections.length > 0);

    const startStatus = statuses.find((payload) => payload.running === true);

    assert.equal(startStatus.status, 'ready');

    engine.stop();
  });

  test('pause stops the emissions and resume restarts them', async () => {
    const engine = createMock({ intervalMs: 10 });
    let count = 0;

    engine.onDetection(() => {
      count += 1;
    });

    await engine.init({ galleryVersion: 'g-1' });

    engine.start();

    await waitFor(() => count > 0);

    assert.equal(engine.pause(), true);
    assert.equal(engine.pause(), false);
    assert.equal(engine.status, 'paused');

    await delay(20);

    const frozen = count;

    await delay(40);

    assert.equal(count, frozen, 'no detections may be emitted while paused');

    assert.equal(engine.resume(), true);
    assert.equal(engine.resume(), false);
    assert.equal(engine.status, 'ready');

    await waitFor(() => count > frozen);

    engine.stop();

    assert.equal(engine.status, 'idle');
  });

  test('dispose stops the loop and releases the listeners', async () => {
    const engine = createMock({ intervalMs: 10 });
    let count = 0;

    engine.onDetection(() => {
      count += 1;
    });

    await engine.init({ galleryVersion: 'g-1' });

    engine.start();

    await waitFor(() => count > 0);

    engine.dispose();

    await delay(25);

    const frozen = count;

    await delay(50);

    assert.equal(count, frozen);
    assert.equal(engine.status, 'disposed');
    assert.equal(engine.isInitialized, false);

    engine.dispose();
  });
});

describe('MockRecognitionEngine catalog and samples', () => {
  test('setProducts swaps the candidate source at runtime', async () => {
    const engine = new MockRecognitionEngine({
      products: [PRODUCTS[0]],
      cameras: [CAMERAS[0]],
      seed: 1,
    });

    await engine.init({ galleryVersion: 'g-1' });

    const initialIds = await collectSampleIds(engine, 15);

    assert.ok(initialIds.size > 0);
    assert.deepEqual([...initialIds], [1]);

    const count = engine.setProducts([
      { id: 99, name: 'Producto nuevo', sku: 'SKU-099' },
    ]);

    assert.equal(count, 1);

    const updatedIds = await collectSampleIds(engine, 15);

    assert.ok(updatedIds.size > 0);
    assert.deepEqual([...updatedIds], [99]);
  });

  test('setProducts validates the list and emits the new count', async () => {
    const engine = createMock();
    const statuses = [];

    engine.onStatus((payload) => {
      if (payload.productsCount !== undefined) statuses.push(payload.productsCount);
    });

    await engine.init({ galleryVersion: 'g-1' });

    assert.equal(engine.setProducts([PRODUCTS[0], PRODUCTS[1], { id: 'x' }]), 2);
    assert.equal(statuses.at(-1), 2);
  });

  test('addSample records the sample in memory and emits the counter', async () => {
    const engine = createMock();
    const statuses = [];

    engine.onStatus((payload) => {
      if (payload.samplesCollected !== undefined) statuses.push(payload);
    });

    await engine.init({ galleryVersion: 'g-1' });

    const first = await engine.addSample(1, null, 'quick_register');

    assert.equal(first.productId, 1);
    assert.equal(first.source, 'quick_register');
    assert.equal(typeof first.at, 'number');
    assert.equal(engine.samples.length, 1);
    assert.equal(engine.samplesCollected, 1);
    assert.equal(statuses.at(-1).samplesCollected, 1);
    assert.deepEqual(statuses.at(-1).lastSample, {
      productId: 1,
      source: 'quick_register',
    });

    const second = await engine.addSample(2, null, 'barcode_scan');

    assert.equal(second.productId, 2);
    assert.equal(engine.samples.length, 2);
    assert.equal(statuses.at(-1).samplesCollected, 2);
  });

  test('addSample rejects invalid sources and products with an error event', async () => {
    const engine = createMock();
    const errors = [];

    engine.onError((payload) => errors.push(payload));

    await engine.init({ galleryVersion: 'g-1' });

    assert.equal(await engine.addSample(1, null, 'from_the_future'), null);
    assert.equal(errors.at(-1).code, 'invalid_sample_source');

    assert.equal(await engine.addSample('abc', null, 'quick_register'), null);
    assert.equal(errors.at(-1).code, 'invalid_product');
    assert.equal(engine.samples.length, 0);
  });

  test('exposes the sample sources and their Spanish labels', () => {
    assert.deepEqual([...SAMPLE_SOURCES], [
      'catalog_image',
      'barcode_scan',
      'quick_register',
      'cashier_correction',
    ]);

    for (const source of SAMPLE_SOURCES) {
      assert.equal(typeof SAMPLE_SOURCE_LABELS[source], 'string');
      assert.ok(SAMPLE_SOURCE_LABELS[source].length > 0);
    }
  });
});

describe('MockRecognitionEngine forceDetection', () => {
  test('returns the requested status and emits the detection', async () => {
    const engine = createMock();
    const batches = [];

    engine.onDetection((payload) => batches.push(payload));

    await engine.init({ galleryVersion: 'g-1' });

    for (const status of ['green', 'yellow', 'red']) {
      const detection = engine.forceDetection(status);

      assert.equal(detection.status, status);
      assert.deepEqual(batches.at(-1), [detection]);
      assert.ok(engine.normalizeDetection(detection));
    }

    assert.deepEqual(batches.at(-1)[0].candidates, []);
  });

  test('builds coherent candidates for green and yellow', async () => {
    const engine = createMock();

    await engine.init({ galleryVersion: 'g-1' });

    const green = engine.forceDetection('green');
    const yellow = engine.forceDetection('yellow');

    assert.equal(green.candidates.length, 2);
    assert.ok(green.candidates[0].score - green.candidates[1].score >= 0.08);

    assert.equal(yellow.candidates.length, 2);
    assert.ok(yellow.candidates[0].score - yellow.candidates[1].score < 0.08);
  });

  test('rejects an unknown forced status', async () => {
    const engine = createMock();

    await engine.init({ galleryVersion: 'g-1' });

    assert.throws(() => engine.forceDetection('blue'), /desconocido/);
    assert.throws(() => engine.forceDetection(), /desconocido/);
  });
});
