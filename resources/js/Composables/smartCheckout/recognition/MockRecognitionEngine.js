/**
 * Simulated recognition engine for Phase 2 of the Smart Checkout.
 *
 * It produces deterministic fake detections from a seed so the UI (boxes,
 * traffic light, camera fusion) can be built and tested before the real ONNX
 * models arrive in Phase 3. It never opens a camera, never calls the network
 * and never writes to storage.
 *
 * Extra API (not part of the interface):
 *   start() / stop() / pause() / resume() -> timer driven `detection` events
 *   setProducts(list)                     -> hot swap the catalog
 *   forceDetection(kind)                  -> inject a green/yellow/red demo
 *   addSample(productId, crop, source)    -> in-memory sample counter
 *   latencyMs                             -> last measured analyze latency
 */

import { RecognitionEngine } from './RecognitionEngine.js';
import {
  classifyCandidates,
  normalizeThresholds,
  topCandidates,
  DETECTION_STATUSES,
} from './confidence.js';

/** Sample sources accepted by `addSample`, per the spec. */
export const SAMPLE_SOURCES = Object.freeze([
  'catalog_image',
  'barcode_scan',
  'quick_register',
  'cashier_correction',
]);

/** Spanish labels for the sample sources (UI text lives here). */
export const SAMPLE_SOURCE_LABELS = Object.freeze({
  catalog_image: 'Imagen de catálogo',
  barcode_scan: 'Escaneo de código de barras',
  quick_register: 'Registro rápido',
  cashier_correction: 'Corrección del cajero',
});

const DEFAULT_INTERVAL_MS = 2500;
const DEFAULT_SEED = 1;
const DEFAULT_MODEL_VERSION = 'mock-1.0';
const DEFAULT_GALLERY_VERSION = 'mock-gallery';

/** Detection box layout: three horizontal, non-overlapping bands. */
const BAND_COUNT = 3;
const BOX_MARGIN = 0.02;

/**
 * Coerce a value to a finite number.
 *
 * @param {unknown} value
 * @returns {number|null}
 */
function toFiniteNumber(value) {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }

  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value.trim());

    return Number.isFinite(parsed) ? parsed : null;
  }

  return null;
}

/**
 * Normalize a product id (positive integer, numeric strings accepted).
 *
 * @param {unknown} value
 * @returns {number|null}
 */
function normalizeProductId(value) {
  const numeric = toFiniteNumber(value);

  if (numeric === null || !Number.isInteger(numeric) || numeric < 0) return null;

  return numeric;
}

/**
 * Round a normalized coordinate or score to `decimals` places.
 *
 * @param {number} value
 * @param {number} [decimals]
 * @returns {number}
 */
function roundTo(value, decimals = 4) {
  const shifted = Number(`${value}e${decimals}`);

  if (!Number.isFinite(shifted)) return value;

  const rounded = Number(`${Math.round(shifted)}e-${decimals}`);

  return Number.isFinite(rounded) ? rounded : value;
}

/**
 * `performance.now()` when the runtime exposes it, `Date.now()` otherwise.
 *
 * @returns {number}
 */
function nowMs() {
  const performanceApi = globalThis.performance;

  if (performanceApi && typeof performanceApi.now === 'function') {
    const value = performanceApi.now();

    if (Number.isFinite(value)) return value;
  }

  return Date.now();
}

/**
 * Deterministic mulberry32 PRNG. The same seed always yields the same sequence
 * in any JavaScript runtime (Vite, Node, worker).
 *
 * @param {number} seed
 * @returns {() => number} Float in `[0, 1)`.
 */
function createSeededRandom(seed) {
  let state = (Number.isFinite(seed) ? Math.trunc(seed) : DEFAULT_SEED) >>> 0;

  return () => {
    state = (state + 0x6d2b79f5) >>> 0;

    let value = state;

    value = Math.imul(value ^ (value >>> 15), value | 1);
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61);

    return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * Shuffle the band indexes with the seeded PRNG (Fisher-Yates).
 *
 * @param {() => number} random
 * @returns {number[]}
 */
function shuffledBands(random) {
  const bands = Array.from({ length: BAND_COUNT }, (_, index) => index);

  for (let index = bands.length - 1; index > 0; index -= 1) {
    const swap = Math.floor(random() * (index + 1));
    const current = bands[index];

    bands[index] = bands[swap];
    bands[swap] = current;
  }

  return bands;
}

/**
 * Keep only the product fields the simulated engine needs.
 *
 * @param {unknown} list
 * @returns {Array<{
 *   id: number,
 *   name: string,
 *   price: number|null,
 *   selling_price: number|null,
 *   is_bulk: boolean,
 *   measure_unit: string|null,
 *   sku: string,
 * }>}
 */
function normalizeProducts(list) {
  const products = [];
  const seen = new Set();
  const source = Array.isArray(list) ? list : [];

  for (const raw of source) {
    if (!raw || typeof raw !== 'object') continue;

    const id = normalizeProductId(raw.id);

    if (id === null || seen.has(id)) continue;

    seen.add(id);

    const name = typeof raw.name === 'string' && raw.name.trim() !== ''
      ? raw.name.trim()
      : `Producto ${id}`;

    const price = toFiniteNumber(raw.price);
    const sellingPrice = toFiniteNumber(raw.selling_price);

    products.push({
      id,
      name,
      price,
      selling_price: sellingPrice,
      is_bulk: raw.is_bulk === true,
      measure_unit: typeof raw.measure_unit === 'string' ? raw.measure_unit : null,
      sku: typeof raw.sku === 'string' ? raw.sku.trim() : '',
    });
  }

  return products;
}

/**
 * Keep only the camera fields the simulated engine needs.
 *
 * @param {unknown} list
 * @returns {Array<{
 *   id: string,
 *   label: string,
 *   mode: 'zone'|'overlap',
 *   resolution: string|null,
 * }>}
 */
function normalizeCameras(list) {
  const cameras = [];
  const seen = new Set();
  const source = Array.isArray(list) ? list : [];

  for (const raw of source) {
    if (!raw || typeof raw !== 'object') continue;

    const rawId = typeof raw.id === 'number' ? String(raw.id) : raw.id;
    const id = typeof rawId === 'string' ? rawId.trim() : '';

    if (id === '' || seen.has(id)) continue;

    seen.add(id);

    cameras.push({
      id,
      label: typeof raw.label === 'string' && raw.label.trim() !== ''
        ? raw.label.trim()
        : id,
      mode: raw.mode === 'overlap' ? 'overlap' : 'zone',
      resolution: typeof raw.resolution === 'string' ? raw.resolution : null,
    });
  }

  return cameras;
}

export class MockRecognitionEngine extends RecognitionEngine {
  /**
   * @param {{
   *   products?: Array<{
   *     id: number,
   *     name: string,
   *     price?: number,
   *     selling_price?: number,
   *     is_bulk?: boolean,
   *     measure_unit?: string,
   *     sku?: string,
   *   }>,
   *   cameras?: Array<{
   *     id: string,
   *     label: string,
   *     mode: 'zone'|'overlap',
   *     resolution?: string,
   *   }>,
   *   thresholds?: object,
   *   autoAccept?: boolean,
   *   intervalMs?: number,
   *   seed?: number,
   *   modelVersion?: string,
   * }} [options]
   */
  constructor(options = {}) {
    super(options);

    this.modelVersion = typeof options.modelVersion === 'string'
      && options.modelVersion.trim() !== ''
      ? options.modelVersion.trim()
      : DEFAULT_MODEL_VERSION;

    this.seed = Number.isFinite(Number(options.seed))
      ? Math.trunc(Number(options.seed))
      : DEFAULT_SEED;

    const interval = toFiniteNumber(options.intervalMs);

    this.intervalMs = interval !== null && interval >= 0
      ? interval
      : DEFAULT_INTERVAL_MS;

    this.autoAccept = options.autoAccept === true;
    this.thresholds = normalizeThresholds(options.thresholds);
    this.products = normalizeProducts(options.products);
    this.cameras = normalizeCameras(options.cameras);

    this.galleryVersion = null;
    this.samples = [];

    this._random = createSeededRandom(this.seed);
    this._timer = null;
    this._started = false;
    this._paused = false;
    this._latencyMs = 0;
    this._lastDetections = [];
  }

  /** @returns {'mock'} */
  get kind() {
    return 'mock';
  }

  /** Last measured `analyze()` latency in milliseconds (always `>= 0`). */
  get latencyMs() {
    return this._latencyMs;
  }

  /** Number of samples registered since construction. */
  get samplesCollected() {
    return this.samples.length;
  }

  /** Last detection batch produced by `analyze()`/`start()`. */
  get lastDetections() {
    return this._lastDetections;
  }

  /**
   * Leave the engine ready. Emits `loading` and then `ready`.
   *
   * @param {{galleryVersion?: string}} [opts]
   * @returns {Promise<void>}
   */
  async init(opts = {}) {
    const requested = typeof opts?.galleryVersion === 'string'
      ? opts.galleryVersion.trim()
      : '';

    this.galleryVersion = requested !== '' ? requested : DEFAULT_GALLERY_VERSION;

    this._setStatus('loading', {
      modelVersion: this.modelVersion,
      galleryVersion: this.galleryVersion,
    });

    this.isInitialized = true;

    this._setStatus('ready', {
      modelVersion: this.modelVersion,
      galleryVersion: this.galleryVersion,
      productsCount: this.products.length,
      camerasCount: this.cameras.length,
      samplesCollected: this.samples.length,
    });
  }

  /**
   * Build one detection batch, one to three objects per configured camera.
   *
   * `frames` is accepted for contract compatibility but ignored in Phase 2:
   * the simulated engine does not look at pixels.
   *
   * @param {Array<{cameraId: string, frame: ImageBitmap|null}>} [frames]
   * @returns {Promise<Array<object>>}
   */
  async analyze(frames = []) {
    void frames;

    this.assertInitialized();

    const startedAt = nowMs();
    const detections = [];

    for (const camera of this.cameras) {
      detections.push(...this._analyzeCamera(camera));
    }

    this._latencyMs = Math.max(0, nowMs() - startedAt);
    this._lastDetections = detections;

    return detections;
  }

  /**
   * Start the detection loop. Emits one batch immediately and then every
   * `intervalMs`.
   *
   * @returns {this}
   */
  start() {
    this.assertInitialized();

    if (this._paused) {
      this.resume();

      return this;
    }

    if (this._timer !== null) return this;

    this._started = true;
    this._paused = false;

    this._setStatus('ready', {
      running: true,
      intervalMs: this.intervalMs,
      samplesCollected: this.samples.length,
    });

    this._tick();
    this._startTimer();

    return this;
  }

  /**
   * Stop the loop and return to `idle`. Safe to call more than once.
   *
   * @returns {this}
   */
  stop() {
    this._stopTimer();
    this._started = false;
    this._paused = false;

    if (this.status !== 'disposed') {
      this._setStatus('idle', {
        running: false,
        samplesCollected: this.samples.length,
      });
    }

    return this;
  }

  /**
   * Pause the loop, keeping the engine initialized (`paused` status).
   *
   * @returns {boolean} `true` when it actually paused.
   */
  pause() {
    if (!this._started || this._paused) return false;

    this._stopTimer();
    this._paused = true;

    this._setStatus('paused', {
      running: false,
      samplesCollected: this.samples.length,
    });

    return true;
  }

  /**
   * Resume a paused loop. Emits a batch immediately.
   *
   * @returns {boolean} `true` when it actually resumed.
   */
  resume() {
    this.assertInitialized();

    if (!this._paused) return false;

    this._paused = false;
    this._started = true;

    this._setStatus('ready', {
      running: true,
      intervalMs: this.intervalMs,
      samplesCollected: this.samples.length,
    });

    this._tick();
    this._startTimer();

    return true;
  }

  /**
   * Stop the loop and drop every listener. Safe to call more than once.
   */
  dispose() {
    this._stopTimer();
    this._started = false;
    this._paused = false;
    this._lastDetections = [];
    this._release();
  }

  /**
   * Replace the product catalog at runtime.
   *
   * @param {unknown} list Same shape as the constructor option.
   * @returns {number} Number of accepted products.
   */
  setProducts(list) {
    this.products = normalizeProducts(list);

    if (this.status !== 'disposed') {
      this._setStatus(this.status, { productsCount: this.products.length });
    }

    return this.products.length;
  }

  /**
   * Register a training sample in memory. Nothing is persisted and no request
   * is made: Phase 2 only needs the counter that Phase 4 will display.
   *
   * @param {number} productId
   * @param {ImageBitmap} crop Ignored by the simulated engine.
   * @param {'catalog_image'|'barcode_scan'|'quick_register'|'cashier_correction'} source
   * @returns {Promise<{productId: number, source: string, at: number}|null>}
   */
  async addSample(productId, crop, source) {
    this.assertInitialized();

    void crop;

    const id = normalizeProductId(productId);

    if (id === null) {
      this.emitError({
        code: 'invalid_product',
        message: 'El identificador del producto para la muestra no es válido.',
      });

      return null;
    }

    if (!SAMPLE_SOURCES.includes(source)) {
      this.emitError({
        code: 'invalid_sample_source',
        message: `El origen de la muestra no es válido: ${String(source)}.`,
      });

      return null;
    }

    const sample = { productId: id, source, at: Date.now() };

    this.samples.push(sample);

    this._setStatus(this.status === 'disposed' ? 'disposed' : this.status, {
      samplesCollected: this.samples.length,
      lastSample: { productId: id, source },
    });

    return sample;
  }

  /**
   * Inject a forced detection so the traffic light can be demoed without
   * waiting for the random simulation. The forced status is respected even if
   * it does not match the candidates.
   *
   * @param {'green'|'yellow'|'red'} kind
   * @returns {{
   *   cameraId: string,
   *   box: [number, number, number, number],
   *   candidates: Array<{productId: number, score: number}>,
   *   status: 'green'|'yellow'|'red',
   * }} The injected detection (also emitted as a one-item `detection` batch).
   * @throws {Error} When `kind` is not a valid status.
   */
  forceDetection(kind) {
    const normalized = typeof kind === 'string' ? kind.toLowerCase() : '';

    if (!DETECTION_STATUSES.includes(normalized)) {
      throw new Error(
        `Tipo de detección forzada desconocido: ${String(kind)}. Usa green, yellow o red.`,
      );
    }

    const camera = this.cameras[0] ?? { id: 'mock-camera' };
    const [first, second] = this.products;

    let candidates = [];

    if (normalized === 'green') {
      if (first) {
        candidates = second
          ? [
            { productId: first.id, score: 0.96 },
            { productId: second.id, score: 0.86 },
          ]
          : [{ productId: first.id, score: 0.96 }];
      }
    } else if (normalized === 'yellow') {
      if (first) {
        candidates = second
          ? [
            { productId: first.id, score: 0.92 },
            // Gap below green_margin: yellow by margin.
            { productId: second.id, score: 0.86 },
          ]
          : [{ productId: first.id, score: 0.72 }];
      }
    }

    const detection = {
      cameraId: camera.id,
      box: [0.08, 0.08, 0.34, 0.34],
      candidates: topCandidates(candidates, 3),
      status: normalized,
    };

    this._lastDetections = [detection];
    this.emit('detection', [detection]);

    return detection;
  }

  /**
   * Analyze one camera: one to three objects placed in non-overlapping bands.
   *
   * @param {{id: string, mode: 'zone'|'overlap'}} camera
   * @returns {Array<object>}
   * @private
   */
  _analyzeCamera(camera) {
    const objectCount = 1 + Math.floor(this._random() * 3);
    const bands = shuffledBands(this._random);
    const detections = [];

    for (let index = 0; index < objectCount; index += 1) {
      const box = this._boxForBand(bands[index]);
      const { candidates, barcode } = this._buildCandidates();
      const status = classifyCandidates(candidates, {
        thresholds: this.thresholds,
        barcode,
      });

      const detection = {
        cameraId: camera.id,
        box,
        candidates,
        status,
      };

      if (barcode !== null) detection.barcode = barcode;

      detections.push(detection);
    }

    return detections;
  }

  /**
   * Pick the candidates for one simulated object.
   *
   * The simulation is tuned so that green, yellow and red all appear over a
   * few cycles: some objects are not recognized at all (red without
   * candidates), some get a low best score (red by score) and the rest get a
   * random gap that may or may not reach `green_margin` (green vs yellow).
   *
   * @returns {{
   *   candidates: Array<{productId: number, score: number}>,
   *   barcode: string|null,
   * }} Already ordered by `topCandidates`.
   * @private
   */
  _buildCandidates() {
    if (this.products.length === 0) {
      return { candidates: [], barcode: null };
    }

    // Nothing recognized at all.
    if (this._random() < 0.12) {
      return { candidates: [], barcode: null };
    }

    const primaryIndex = Math.floor(this._random() * this.products.length);
    const primary = this.products[primaryIndex];

    const lowScore = this._random() < 0.15;
    let primaryScore;

    if (lowScore) {
      primaryScore = 0.35 + this._random() * 0.25;
    } else if (this._random() < 0.6) {
      primaryScore = 0.86 + this._random() * 0.13;
    } else {
      primaryScore = 0.68 + this._random() * 0.18;
    }

    const candidates = [
      { productId: primary.id, score: roundTo(primaryScore, 3) },
    ];

    const extraCount = Math.floor(this._random() * 3);
    const pool = this.products.filter((product) => product.id !== primary.id);

    for (let index = 0; index < extraCount && pool.length > 0; index += 1) {
      const pick = Math.floor(this._random() * pool.length);
      const [other] = pool.splice(pick, 1);

      const score = primaryScore * (0.55 + this._random() * 0.4);

      candidates.push({
        productId: other.id,
        score: roundTo(score, 3),
      });
    }

    // Barcodes are occasional and never on a low score branch: a valid read
    // would turn that detection green.
    const barcode = primary.sku !== ''
      && primaryScore >= this.thresholds.yellow_min
      && this._random() < 0.12
      ? primary.sku
      : null;

    return {
      candidates: topCandidates(candidates, 3),
      barcode,
    };
  }

  /**
   * Build a box inside one of the three horizontal bands. Bands are disjoint,
   * so two objects of the same camera never overlap.
   *
   * @param {number} band `0..2`
   * @returns {[number, number, number, number]}
   * @private
   */
  _boxForBand(band) {
    const bandHeight = 1 / BAND_COUNT;
    const top = band * bandHeight;

    const height = roundTo(0.16 + this._random() * 0.12);
    const width = roundTo(0.2 + this._random() * 0.3);

    const minY = top + BOX_MARGIN;
    const maxY = top + bandHeight - BOX_MARGIN - height;
    const y = roundTo(minY + this._random() * Math.max(0, maxY - minY));

    const x = roundTo(0.03 + this._random() * (0.94 - width));

    return [x, y, width, height];
  }

  /**
   * Run one loop cycle: analyze and emit the batch.
   *
   * @returns {Promise<void>}
   * @private
   */
  _tick() {
    return this.analyze([])
      .then((detections) => {
        this.emit('detection', detections);
      })
      .catch((error) => {
        this.emitError(error, 'analyze_failed');
      });
  }

  /** @private */
  _startTimer() {
    this._stopTimer();

    if (!(this.intervalMs > 0)) return;

    const timer = globalThis.setInterval(() => {
      this._tick();
    }, this.intervalMs);

    // In node:test an unref'ed timer does not keep the process alive.
    if (timer && typeof timer.unref === 'function') timer.unref();

    this._timer = timer;
  }

  /** @private */
  _stopTimer() {
    if (this._timer !== null) {
      globalThis.clearInterval(this._timer);
      this._timer = null;
    }
  }
}

export default MockRecognitionEngine;
