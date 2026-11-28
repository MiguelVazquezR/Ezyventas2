/**
 * Manual scale driver: the cashier types the weight.
 *
 * `submit()` emits exactly one reading flagged `stable: true`, because a human
 * confirmation is stable by definition. `connect()` has no hardware to open
 * and `tare()` is a documented no-op.
 */

import { ScaleDriver } from './ScaleDriver.js';
import { normalizeUnit } from '../scaleMath.js';

export class ManualScaleDriver extends ScaleDriver {
  /**
   * @param {{unit?: string}} [options]
   */
  constructor(options = {}) {
    super(options);

    this.unit = normalizeUnit(options.unit ?? 'kg') ?? 'kg';
  }

  /** @returns {'manual'} */
  get kind() {
    return 'manual';
  }

  /** No hardware: marks the driver as ready. @returns {Promise<void>} */
  async connect() {
    this.isConnected = true;
    this._setStatus('connected');
  }

  /** @returns {Promise<void>} */
  async disconnect() {
    this.isConnected = false;
    this._setStatus('disconnected');
  }

  /**
   * Emit one manual reading.
   *
   * @param {number|string} weight
   * @param {string} [unit] Defaults to the current unit.
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null} `null`
   *   when the weight is rejected (an `error` event is emitted).
   * @throws {Error} When the driver has not been connected yet.
   */
  submit(weight, unit = this.unit) {
    if (!this.isConnected) {
      throw new Error('Conecta la captura manual antes de enviar un peso.');
    }

    return this._emitReading({
      weight,
      unit: unit ?? this.unit,
      stable: true,
    });
  }

  /**
   * No-op: a typed weight has no hardware offset to zero. Kept so the driver
   * honors the common interface.
   *
   * @returns {Promise<void>}
   */
  async tare() {
    // Intentionally empty.
  }
}

export default ManualScaleDriver;
