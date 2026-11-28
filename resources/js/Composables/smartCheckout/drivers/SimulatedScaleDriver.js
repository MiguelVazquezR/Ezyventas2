/**
 * Simulated scale driver.
 *
 * No hardware at all. It is the panel used during development and the driver
 * used by the tests: it can emit stable or unstable readings on a timer and
 * lets the caller move the simulated weight.
 *
 * Extra API (not part of the interface):
 *   setWeight(value, unit?) -> reading|null
 *   setStable(value)        -> boolean
 *   nudge(delta)            -> reading|null
 *   tick()                  -> reading|null (emit once, right now)
 */

import { ScaleDriver } from './ScaleDriver.js';
import {
  fromGrams,
  normalizeUnit,
  roundWeight,
  toGrams,
} from '../scaleMath.js';

export class SimulatedScaleDriver extends ScaleDriver {
  /**
   * @param {{
   *   weight?: number,
   *   unit?: string,
   *   stable?: boolean,
   *   intervalMs?: number,
   *   autoEmit?: boolean,
   * }} [options]
   */
  constructor(options = {}) {
    super(options);

    this.unit = normalizeUnit(options.unit ?? 'kg') ?? 'kg';

    const initialWeight = Number(options.weight ?? 0);

    this._weight = Number.isFinite(initialWeight) ? roundWeight(initialWeight) : 0;
    this._stable = options.stable !== false;

    const interval = Number(options.intervalMs ?? 250);

    this.intervalMs = Number.isFinite(interval) ? Math.max(0, interval) : 250;
    this.autoEmit = options.autoEmit !== false;
    this._timer = null;
  }

  /** @returns {'simulated'} */
  get kind() {
    return 'simulated';
  }

  /** Current simulated weight (in `this.unit`). */
  get weight() {
    return this._weight;
  }

  /** Whether the simulated readings are flagged as stable. */
  get stable() {
    return this._stable;
  }

  /** @returns {Promise<void>} */
  async connect() {
    if (this.isConnected) return;

    this.isConnected = true;
    this._setStatus('connected');

    if (this.autoEmit && this.intervalMs > 0) {
      this._startTimer();
    }

    this.tick();
  }

  /**
   * Update the simulated weight and emit a reading immediately.
   *
   * @param {number|string} value
   * @param {string} [unit] Defaults to the current unit.
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null} `null` on
   *   invalid input (state is left untouched and an `error` event is emitted).
   */
  setWeight(value, unit = this.unit) {
    const targetUnit = normalizeUnit(unit ?? this.unit);
    const grams = targetUnit === null ? null : toGrams(value, targetUnit);

    if (targetUnit === null || grams === null || grams < 0) {
      this._emitError({
        code: 'invalid_reading',
        message: 'El peso simulado debe ser un número mayor o igual a cero.',
      });

      return null;
    }

    this.unit = targetUnit;
    this._weight = fromGrams(grams, targetUnit);

    return this.tick();
  }

  /**
   * Toggle the simulated stability flag and emit a reading immediately.
   *
   * @param {boolean} stable
   * @returns {boolean} The stored flag.
   */
  setStable(stable) {
    this._stable = Boolean(stable);

    this.tick();

    return this._stable;
  }

  /**
   * Add `delta` (in the current unit) to the simulated weight.
   *
   * @param {number|string} delta
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null}
   */
  nudge(delta) {
    const numericDelta = Number(delta);

    if (!Number.isFinite(numericDelta)) {
      this._emitError({
        code: 'invalid_reading',
        message: 'El incremento simulado debe ser un número.',
      });

      return null;
    }

    return this.setWeight(roundWeight(this._weight + numericDelta));
  }

  /**
   * Emit one reading right now, whether the timer is running or not.
   *
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null}
   */
  tick() {
    if (!this.isConnected) return null;

    return this._emitReading({
      weight: this._weight,
      unit: this.unit,
      stable: this._stable,
    });
  }

  /**
   * Reset the simulated weight to zero and emit the zero reading.
   *
   * @returns {Promise<void>}
   */
  async tare() {
    if (!this.isConnected) {
      throw new Error('La báscula simulada no está conectada.');
    }

    this._weight = 0;
    this.tick();

    this._setStatus('tared', { offsetGrams: 0 });
  }

  /** @returns {Promise<void>} */
  async disconnect() {
    this._stopTimer();
    this.isConnected = false;
    this._setStatus('disconnected');
  }

  /** @private */
  _startTimer() {
    this._stopTimer();

    const timer = globalThis.setInterval(() => this.tick(), this.intervalMs);

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

export default SimulatedScaleDriver;
