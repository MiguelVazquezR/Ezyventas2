/**
 * Base class for every scale driver.
 *
 * Public contract (see the Smart Checkout spec):
 *   connect(): Promise<void>
 *   disconnect(): Promise<void>
 *   onReading(cb): () => void
 *   tare(): Promise<void>
 *
 * Plus the shared events `reading`, `error`, `status` (and `raw` for drivers
 * that expose a transport log, like Web Serial).
 *
 * No hardware or browser API is touched at construction time, so importing
 * this module is side-effect free.
 */

import {
  fromGrams,
  normalizeUnit,
  toGrams,
} from '../scaleMath.js';

export class ScaleDriver {
  /**
   * @param {object} [options]
   */
  constructor(options = {}) {
    this.options = options;
    this.isConnected = false;
    this.status = 'idle';
    this._listeners = new Map();
    this._lastReading = null;
  }

  /** Stable identifier used by the factory (`simulated`, `manual`, ...). */
  get kind() {
    return 'base';
  }

  /** Last reading emitted (already validated), or `null`. */
  get lastReading() {
    return this._lastReading;
  }

  /**
   * Subscribe to a driver event.
   *
   * @param {'reading'|'error'|'status'|'raw'|string} event
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
   * Convenience subscription to scale readings.
   *
   * @param {(reading: {weight: number, unit: 'kg'|'g', stable: boolean}) => void} callback
   * @returns {() => void}
   */
  onReading(callback) {
    return this.on('reading', callback);
  }

  /**
   * Convenience subscription to driver errors.
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
   * Convenience subscription to raw transport lines (Web Serial only).
   *
   * @param {(line: string) => void} callback
   * @returns {() => void}
   */
  onRaw(callback) {
    return this.on('raw', callback);
  }

  /**
   * Alias of `onRaw` used by the weighing panel.
   *
   * @param {(line: string) => void} callback
   * @returns {() => void}
   */
  onRawLine(callback) {
    return this.on('raw', callback);
  }

  /**
   * Emit an event. A throwing listener never breaks the driver (or the other
   * listeners).
   *
   * @param {string} event
   * @param {any} payload
   */
  _emit(event, payload) {
    const listeners = this._listeners.get(event);

    if (!listeners) return;

    for (const listener of [...listeners]) {
      try {
        listener(payload);
      } catch {
        // Listener errors are the listener's problem; the driver must survive.
      }
    }
  }

  /**
   * Update `status` and notify subscribers.
   *
   * @param {string} status
   * @param {object} [detail]
   */
  _setStatus(status, detail = {}) {
    this.status = status;
    this._emit('status', { status, kind: this.kind, ...detail });
  }

  /**
   * Validate, store and emit a reading.
   *
   * @param {{weight: number, unit: string, stable?: boolean}} reading
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null} Normalized
   *   reading, or `null` when it was rejected (an `error` event is emitted).
   */
  _emitReading(reading) {
    const normalized = this.normalizeReading(reading);

    if (normalized === null) {
      this._emitError({
        code: 'invalid_reading',
        message: 'La lectura de la báscula no es válida: se esperaba un peso mayor o igual a cero con unidad kg o g.',
      });

      return null;
    }

    this._lastReading = normalized;
    this._emit('reading', normalized);

    return normalized;
  }

  /**
   * Notify subscribers of an error.
   *
   * @param {Error|{code?: string, message: string}} error
   * @param {string} [code]
   */
  _emitError(error, code = 'driver_error') {
    if (error instanceof Error) {
      const payload = { code: error.code ?? code, message: error.message, error };

      this._emit('error', payload);

      return;
    }

    this._emit('error', {
      code: error?.code ?? code,
      message: error?.message ?? String(error),
    });
  }

  /**
   * Normalize and validate a reading.
   *
   * Rules: finite number, unit `kg`/`g` (aliases accepted), weight `>= 0`
   * (negatives are rejected). Values are rounded to 3 decimals.
   *
   * @param {{weight: number|string, unit: string, stable?: boolean}} reading
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null}
   */
  normalizeReading(reading) {
    if (!reading || typeof reading !== 'object') return null;

    const unit = normalizeUnit(reading.unit);

    if (unit === null) return null;

    const grams = toGrams(reading.weight, unit);

    if (grams === null || grams < 0) return null;

    return {
      weight: fromGrams(grams, unit),
      unit,
      stable: Boolean(reading.stable),
    };
  }

  /**
   * Open the hardware and start producing readings.
   *
   * @abstract
   * @returns {Promise<void>}
   */
  async connect() {
    throw new Error('ScaleDriver.connect() debe implementarse en una subclase.');
  }

  /**
   * Close the hardware and stop producing readings.
   *
   * @returns {Promise<void>}
   */
  async disconnect() {
    this.isConnected = false;
    this._setStatus('disconnected');
  }

  /**
   * Zero the scale.
   *
   * @abstract
   * @returns {Promise<void>}
   */
  async tare() {
    throw new Error('ScaleDriver.tare() debe implementarse en una subclase.');
  }

  /**
   * Release the driver: disconnect and drop every listener. Safe to call more
   * than once.
   *
   * @returns {Promise<void>}
   */
  async dispose() {
    try {
      await this.disconnect();
    } finally {
      this._listeners.clear();
      this.isConnected = false;
      this.status = 'disposed';
    }
  }
}

export default ScaleDriver;
