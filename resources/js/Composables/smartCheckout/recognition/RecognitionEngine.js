/**
 * Base class for every Smart Checkout recognition engine.
 *
 * Public contract (see the Smart Checkout spec):
 *   init({ galleryVersion }): Promise<void>
 *   analyze(frames): Promise<Detection[]>
 *   addSample(productId, crop, source): Promise<void>
 *   dispose(): void
 *
 * Plus the shared events `detection`, `error` and `status`, and the convenience
 * subscriptions `onDetection`, `onError` and `onStatus`.
 *
 * No browser API, worker or model is touched at construction time, so importing
 * this module is side-effect free and safe under Node for `node:test`.
 *
 * The base class is abstract on purpose: `init`, `analyze`, `addSample` and
 * `dispose` throw until a subclass implements them.
 */

import {
  DETECTION_STATUSES,
  topCandidates,
} from './confidence.js';

/** Statuses accepted by the engine status event. */
export const RECOGNITION_STATUSES = Object.freeze([
  'idle',
  'loading',
  'ready',
  'paused',
  'error',
]);

/** Floating point tolerance for normalized boxes. */
const EPSILON = 1e-9;

export class RecognitionEngine {
  /**
   * @param {object} [options] Engine specific options. The base class only
   *   stores them; each subclass documents its own.
   */
  constructor(options = {}) {
    this.options = options && typeof options === 'object' ? options : {};
    this.status = 'idle';
    this.isInitialized = false;
    this.modelVersion = typeof this.options.modelVersion === 'string'
      ? this.options.modelVersion
      : 'base';

    /** @type {Map<string, Set<(payload: any) => void>>} */
    this._listeners = new Map();
  }

  /** Stable identifier used by the factory (`mock`, `onnx`, ...). */
  get kind() {
    return 'base';
  }

  /**
   * Subscribe to an engine event.
   *
   * @param {'detection'|'error'|'status'|string} event
   * @param {(payload: any) => void} listener
   * @returns {() => void} Unsubscribe function.
   */
  on(event, listener) {
    if (typeof listener !== 'function') {
      throw new TypeError('El listener del evento debe ser una función.');
    }

    if (!this._listeners.has(event)) {
      this._listeners.set(event, new Set());
    }

    this._listeners.get(event).add(listener);

    return () => this.off(event, listener);
  }

  /**
   * Remove a previously registered listener.
   *
   * @param {string} event
   * @param {(payload: any) => void} listener
   */
  off(event, listener) {
    this._listeners.get(event)?.delete(listener);
  }

  /**
   * Convenience subscription to detection batches.
   *
   * @param {(detections: Array<object>) => void} callback
   * @returns {() => void}
   */
  onDetection(callback) {
    return this.on('detection', callback);
  }

  /**
   * Convenience subscription to engine errors.
   *
   * @param {(error: {code: string, message: string, error?: Error}) => void} callback
   * @returns {() => void}
   */
  onError(callback) {
    return this.on('error', callback);
  }

  /**
   * Convenience subscription to status changes.
   *
   * @param {(status: {status: string, kind: string}) => void} callback
   * @returns {() => void}
   */
  onStatus(callback) {
    return this.on('status', callback);
  }

  /**
   * Emit an event. A throwing listener never breaks the engine (or the other
   * listeners).
   *
   * @param {string} event
   * @param {any} payload
   */
  emit(event, payload) {
    const listeners = this._listeners.get(event);

    if (!listeners) return;

    for (const listener of [...listeners]) {
      try {
        listener(payload);
      } catch {
        // Listener errors are the listener's problem; the engine must survive.
      }
    }
  }

  /**
   * Update `status` and notify subscribers.
   *
   * @param {'idle'|'loading'|'ready'|'paused'|'error'} status
   * @param {object} [detail]
   */
  _setStatus(status, detail = {}) {
    this.status = status;
    this.emit('status', { status, kind: this.kind, ...detail });
  }

  /**
   * Notify subscribers of an error.
   *
   * @param {Error|{code?: string, message: string}} error
   * @param {string} [code]
   */
  emitError(error, code = 'recognition_error') {
    if (error instanceof Error) {
      this.emit('error', {
        code: error.code ?? code,
        message: error.message,
        error,
      });

      return;
    }

    this.emit('error', {
      code: error?.code ?? code,
      message: error?.message ?? String(error),
    });
  }

  /**
   * Throw unless `init()` already resolved.
   *
   * @throws {Error} When the engine was not initialized or was disposed.
   */
  assertInitialized() {
    if (!this.isInitialized) {
      throw new Error(
        'El motor de reconocimiento no está inicializado: llama a init() antes de usarlo.',
      );
    }
  }

  /**
   * Validate and normalize a detection.
   *
   * Rules:
   * - `cameraId` is a non-empty string.
   * - `box` is exactly four finite numbers in `0..1`, with positive `w`/`h`
   *   and fully inside the frame.
   * - `status` is one of `green`, `yellow` or `red`.
   * - `candidates` (when present) is an array; it is ordered, deduped and
   *   capped to 3 by `topCandidates` (scores rounded to 3 decimals).
   * - `barcode`, when present, must be a non-empty string.
   *
   * Coherence between `status` and `candidates` is not enforced here: a forced
   * red detection with no candidates is a valid detection.
   *
   * @param {unknown} detection
   * @returns {{
   *   cameraId: string,
   *   box: [number, number, number, number],
   *   candidates: Array<{productId: number, score: number}>,
   *   status: 'green'|'yellow'|'red',
   *   barcode?: string,
   * }|null} `null` when the detection is invalid.
   */
  normalizeDetection(detection) {
    if (!detection || typeof detection !== 'object') return null;

    const cameraId = typeof detection.cameraId === 'string'
      ? detection.cameraId.trim()
      : '';

    if (cameraId === '') return null;

    const box = detection.box;

    if (!Array.isArray(box) || box.length !== 4) return null;

    if (!box.every((value) => typeof value === 'number' && Number.isFinite(value))) {
      return null;
    }

    const [x, y, w, h] = box;

    if (x < 0 || y < 0 || w <= 0 || h <= 0) return null;
    if (x > 1 || y > 1 || w > 1 || h > 1) return null;
    if (x + w > 1 + EPSILON || y + h > 1 + EPSILON) return null;

    const status = typeof detection.status === 'string'
      ? detection.status.toLowerCase()
      : '';

    if (!DETECTION_STATUSES.includes(status)) return null;

    if (detection.candidates !== undefined && !Array.isArray(detection.candidates)) {
      return null;
    }

    const normalized = {
      cameraId,
      box: [x, y, w, h],
      candidates: topCandidates(detection.candidates ?? [], 3),
      status,
    };

    if (typeof detection.barcode === 'string' && detection.barcode.trim() !== '') {
      normalized.barcode = detection.barcode.trim();
    }

    return normalized;
  }

  /**
   * Open the gallery/model and leave the engine ready.
   *
   * @abstract
   * @param {{galleryVersion: string}} [opts]
   * @returns {Promise<void>}
   */
  async init(opts = {}) {
    void opts;

    throw new Error(
      'RecognitionEngine.init() is not implemented; extend RecognitionEngine.',
    );
  }

  /**
   * Analyze one frame per camera.
   *
   * @abstract
   * @param {Array<{cameraId: string, frame: ImageBitmap|null}>} frames
   * @returns {Promise<Array<object>>}
   */
  async analyze(frames = []) {
    void frames;

    throw new Error(
      'RecognitionEngine.analyze() is not implemented; extend RecognitionEngine.',
    );
  }

  /**
   * Store a training sample for the gallery.
   *
   * @abstract
   * @param {number} productId
   * @param {ImageBitmap} crop
   * @param {'catalog_image'|'barcode_scan'|'quick_register'|'cashier_correction'} source
   * @returns {Promise<void>}
   */
  async addSample(productId, crop, source) {
    void productId;
    void crop;
    void source;

    throw new Error(
      'RecognitionEngine.addSample() is not implemented; extend RecognitionEngine.',
    );
  }

  /**
   * Release timers, workers and listeners.
   *
   * Subclasses override it; `_release()` does the shared cleanup. Calling
   * `super.dispose()` from a subclass is intentionally not possible.
   *
   * @abstract
   */
  dispose() {
    throw new Error(
      'RecognitionEngine.dispose() is not implemented; extend RecognitionEngine.',
    );
  }

  /**
   * Shared cleanup for subclasses: drop listeners and mark the engine as
   * disposed. Safe to call more than once.
   *
   * @protected
   */
  _release() {
    this._listeners.clear();
    this.isInitialized = false;
    this.status = 'disposed';
  }
}

export default RecognitionEngine;
