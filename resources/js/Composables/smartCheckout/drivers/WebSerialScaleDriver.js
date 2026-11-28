/**
 * Web Serial scale driver (Chrome / Edge, HTTPS or localhost only).
 *
 * Reads text lines from a serial port, parses them with a per-profile regular
 * expression, applies a software tare offset and computes stability over the
 * last N readings.
 *
 * The browser API is only touched inside `connect()` / `isSupported()`, never
 * at module load, so this file is safe to import in Vite and in `node:test`.
 *
 * Diagnostic mode: every raw line is kept in a 200-line ring buffer
 * (`getRawLog()`, `raw` event). With `debug: true` each line is also logged
 * with `console.debug`.
 */

import { ScaleDriver } from './ScaleDriver.js';
import {
  DEFAULT_STABILITY,
  fromGrams,
  isStable,
  normalizeUnit,
  parseSerialLine,
  roundWeight,
  splitSerialBuffer,
  toGrams,
} from '../scaleMath.js';

/** Profile defaults, overridable per business (baudios, bits, regex...). */
export const DEFAULT_SERIAL_PROFILE = Object.freeze({
  baudRate: 9600,
  dataBits: 8,
  stopBits: 1,
  parity: 'none',
  flowControl: 'none',
  terminator: 'crlf',
  pattern: '([+-]?\\s*\\d+(?:[.,]\\d+)?)\\s*(kgs?|grs?|gr|g)\\b',
  flags: 'i',
  weightGroup: 1,
  unitGroup: 2,
  stableGroup: null,
  stableValues: [],
  unit: 'kg',
  decimalComma: false,
});

const RAW_LOG_LIMIT = 200;

export class WebSerialScaleDriver extends ScaleDriver {
  /**
   * @param {{
   *   profile?: object,
   *   stability?: {minReadings?: number, toleranceGrams?: number},
   *   rawLogLimit?: number,
   *   debug?: boolean,
   *   serial?: object,     // injectable Serial API (tests)
   *   port?: object,       // injectable SerialPort (tests)
   * }} [options]
   */
  constructor(options = {}) {
    super(options);

    const merged = { ...DEFAULT_SERIAL_PROFILE, ...(options.profile ?? {}) };
    const baudRate = Number(merged.baudRate);
    const dataBits = Number(merged.dataBits);
    const stopBits = Number(merged.stopBits);

    this.profile = {
      ...merged,
      // The profile travels as JSON; coerce the framing values so a string from
      // an old row cannot make `port.open()` throw.
      baudRate: Number.isFinite(baudRate) && baudRate > 0 ? baudRate : DEFAULT_SERIAL_PROFILE.baudRate,
      dataBits: Number.isFinite(dataBits) && dataBits > 0 ? dataBits : DEFAULT_SERIAL_PROFILE.dataBits,
      stopBits: Number.isFinite(stopBits) && stopBits > 0 ? stopBits : DEFAULT_SERIAL_PROFILE.stopBits,
      parity: String(merged.parity ?? 'none').toLowerCase(),
      flowControl: String(merged.flowControl ?? 'none').toLowerCase(),
      terminator: String(merged.terminator ?? 'crlf').toLowerCase(),
    };

    const minReadings = Number(options.stability?.minReadings ?? DEFAULT_STABILITY.minReadings);
    const toleranceGrams = Number(options.stability?.toleranceGrams ?? DEFAULT_STABILITY.toleranceGrams);

    this.stability = {
      minReadings: Number.isFinite(minReadings) && minReadings >= 1
        ? Math.floor(minReadings)
        : DEFAULT_STABILITY.minReadings,
      toleranceGrams: Number.isFinite(toleranceGrams) && toleranceGrams >= 0
        ? toleranceGrams
        : DEFAULT_STABILITY.toleranceGrams,
    };

    const rawLogLimit = Number(options.rawLogLimit ?? RAW_LOG_LIMIT);

    this.rawLogLimit = Number.isFinite(rawLogLimit) && rawLogLimit >= 1
      ? Math.floor(rawLogLimit)
      : RAW_LOG_LIMIT;

    this.debug = options.debug === true;

    this._serial = options.serial ?? null;
    this._injectedPort = options.port ?? null;
    this._serialApi = null;
    this._port = null;
    this._reader = null;
    this._writer = null;
    this._readLoopPromise = null;
    this._onSerialDisconnect = null;
    this._tareOffsetGrams = 0;
    this._rawLog = [];
    this._recentGrams = [];
  }

  /** @returns {'webserial'} */
  get kind() {
    return 'webserial';
  }

  /**
   * Feature detection. Only true in secure contexts (HTTPS or localhost).
   *
   * @returns {boolean}
   */
  static isSupported() {
    return typeof navigator !== 'undefined' && 'serial' in navigator;
  }

  /** Instance shortcut for template use. @returns {boolean} */
  get supported() {
    return WebSerialScaleDriver.isSupported();
  }

  /** Tare offset in grams (never negative). @returns {number} */
  get tareOffsetGrams() {
    return this._tareOffsetGrams;
  }

  /**
   * Open the port, apply the profile and start the reading loop.
   *
   * @returns {Promise<void>}
   * @throws {Error & {code: string}} `unsupported`, `port_selection_cancelled`,
   *   `permission_denied`, `port_busy`, `device_lost` or `serial_error`.
   */
  async connect() {
    if (this.isConnected) return;

    // A previous port may have ended without a clean disconnect (cable pulled):
    // release it before opening a new one.
    if (this._port) await this.disconnect();

    const serial = this._getSerialApi();

    if (!serial) {
      this._setStatus('error', { code: 'unsupported' });

      throw this._createError(
        'unsupported',
        'Este navegador no soporta Web Serial. Usa Chrome o Edge sobre HTTPS y, si sigue fallando, captura el peso a mano.',
      );
    }

    if (typeof TextDecoderStream === 'undefined') {
      this._setStatus('error', { code: 'unsupported' });

      throw this._createError(
        'unsupported',
        'Este navegador no puede decodificar el puerto serial. Usa una versión reciente de Chrome o Edge.',
      );
    }

    this._setStatus('connecting');

    let port;

    try {
      port = this._injectedPort ?? await serial.requestPort();
    } catch (error) {
      this._setStatus('idle');

      throw this._translateError(error);
    }

    if (!port) {
      this._setStatus('idle');

      throw this._createError(
        'port_selection_cancelled',
        'No se seleccionó ningún puerto serial.',
      );
    }

    try {
      const openOptions = {
        baudRate: this.profile.baudRate,
        dataBits: this.profile.dataBits,
        stopBits: this.profile.stopBits,
        parity: this.profile.parity,
        flowControl: this.profile.flowControl ?? 'none',
      };

      if (this.profile.bufferSize) openOptions.bufferSize = this.profile.bufferSize;

      await port.open(openOptions);

      this._port = port;
      this.isConnected = true;

      this._attachDisconnectListener(serial, port);
      this._setStatus('connected', { profile: this.profile });

      this._readLoopPromise = this._readLoop(port);

      // A transport failure must never become an unhandled rejection.
      this._readLoopPromise.catch(() => {});
    } catch (error) {
      const translated = this._translateError(error);

      this._port = null;
      this.isConnected = false;

      try {
        await port.close();
      } catch {
        // The port never opened (or is already gone): nothing to release.
      }

      this._setStatus('error', { code: translated.code });

      throw translated;
    }
  }

  /**
   * Stop the reading loop and release reader, writer and port.
   *
   * @returns {Promise<void>}
   */
  async disconnect() {
    const port = this._port;

    this.isConnected = false;
    this._detachDisconnectListener();

    try {
      const reader = this._reader;

      this._reader = null;

      if (reader) {
        try {
          await reader.cancel();
        } catch {
          // Reading already finished.
        }
      }

      if (this._readLoopPromise) {
        try {
          await this._readLoopPromise;
        } catch {
          // Already reported by the loop.
        }

        this._readLoopPromise = null;
      }

      if (this._writer) {
        try {
          this._writer.releaseLock?.();
        } catch {
          // Nothing to release.
        }

        this._writer = null;
      }
    } finally {
      this._port = null;
      this._recentGrams = [];

      if (port) {
        try {
          await port.close();
        } catch (error) {
          this._emitError(this._translateError(error), 'close_error');
        }
      }

      this._setStatus('disconnected');
    }
  }

  /**
   * Software tare: the current weight becomes the new zero.
   *
   * @returns {Promise<void>}
   * @throws {Error} When the scale is not connected.
   */
  async tare() {
    if (!this.isConnected) {
      throw new Error('La báscula serial no está conectada.');
    }

    const last = this._lastReading;
    const offset = last ? toGrams(last.weight, last.unit) : 0;

    this._tareOffsetGrams = offset ?? 0;
    this._recentGrams = [];
    this._setStatus('tared', { offsetGrams: this._tareOffsetGrams });

    this._emitReading({
      weight: 0,
      unit: last?.unit ?? normalizeUnit(this.profile.unit) ?? 'kg',
      stable: true,
    });
  }

  /** Remove the software tare offset. */
  clearTare() {
    this._tareOffsetGrams = 0;
    this._recentGrams = [];
    this._setStatus('tare_cleared', { offsetGrams: 0 });
  }

  /**
   * Last raw lines seen on the port (diagnostic panel).
   *
   * @returns {string[]} Copy of the ring buffer, oldest first.
   */
  getRawLog() {
    return [...this._rawLog];
  }

  /** Empty the diagnostic raw log. */
  clearRawLog() {
    this._rawLog = [];
  }

  /**
   * Feed one raw line through the same pipeline the serial loop uses. Useful
   * for the diagnostic panel ("paste a line") and for tests.
   *
   * @param {string} rawLine
   * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null}
   */
  ingestLine(rawLine) {
    if (typeof rawLine !== 'string') return null;

    this._pushRaw(rawLine);

    const parsed = parseSerialLine(rawLine, this.profile);

    if (parsed === null) return null;

    const grams = toGrams(parsed.weight, parsed.unit);

    if (grams === null) return null;

    const adjustedGrams = roundWeight(grams - this._tareOffsetGrams);

    if (adjustedGrams < 0) {
      this._emitError({
        code: 'invalid_reading',
        message: 'La báscula entregó un peso negativo después de la tara.',
      });

      return null;
    }

    this._recentGrams.push(adjustedGrams);

    while (this._recentGrams.length > this.stability.minReadings) {
      this._recentGrams.shift();
    }

    const stable = parsed.stable && isStable(this._recentGrams, this.stability);

    return this._emitReading({
      weight: fromGrams(adjustedGrams, parsed.unit),
      unit: parsed.unit,
      stable,
    });
  }

  /** @private */
  _getSerialApi() {
    if (this._serial) return this._serial;

    if (typeof navigator !== 'undefined' && 'serial' in navigator) {
      return navigator.serial;
    }

    return null;
  }

  /** @private */
  async _readLoop(port) {
    let reader = null;
    let pipePromise = null;

    try {
      if (!port.readable) {
        throw new Error('El puerto serial no expone un flujo de lectura.');
      }

      const decoder = new TextDecoderStream();

      pipePromise = port.readable.pipeTo(decoder.writable).catch((error) => {
        if (this.isConnected) {
          this._emitError(this._translateError(error), 'read_error');
        }
      });

      reader = decoder.readable.getReader();
      this._reader = reader;

      let buffer = '';

      while (this.isConnected) {
        const { value, done } = await reader.read();

        if (done) break;

        if (typeof value !== 'string') continue;

        buffer += value;

        const { lines, rest } = splitSerialBuffer(buffer, this.profile.terminator);

        buffer = rest;

        for (const line of lines) {
          this.ingestLine(line);
        }
      }

      // The stream ended by itself (unplugged device or driver reset) while we
      // still believed the port was open.
      if (this.isConnected) {
        const error = this._createError('device_lost', 'La báscula cerró la conexión serial.');

        this.isConnected = false;
        this._emitError(error, 'device_lost');
        this._setStatus('error', { code: 'device_lost' });
      }
    } catch (error) {
      if (this.isConnected) {
        const translated = this._translateError(error);

        this._emitError(translated, 'read_error');
        this._setStatus('error', { code: translated.code });
      }
    } finally {
      if (reader) {
        try {
          reader.releaseLock();
        } catch {
          // Already released.
        }
      }

      if (pipePromise) {
        try {
          await pipePromise;
        } catch {
          // Already reported.
        }
      }

      this._reader = null;
    }
  }

  /** @private */
  _pushRaw(line) {
    this._rawLog.push(line);

    if (this._rawLog.length > this.rawLogLimit) {
      this._rawLog.splice(0, this._rawLog.length - this.rawLogLimit);
    }

    this._emit('raw', line);

    if (this.debug) {
      console.debug('[WebSerialScaleDriver] raw:', JSON.stringify(line));
    }
  }

  /** @private */
  _attachDisconnectListener(serial, port) {
    if (!serial || typeof serial.addEventListener !== 'function') return;

    this._serialApi = serial;

    this._onSerialDisconnect = (event) => {
      if (event?.target && event.target !== port) return;

      const error = this._createError(
        'device_disconnected',
        'La báscula se desconectó físicamente del puerto serial.',
      );

      this._emitError(error, 'device_disconnected');
      this.disconnect().catch(() => {});
    };

    serial.addEventListener('disconnect', this._onSerialDisconnect);
  }

  /** @private */
  _detachDisconnectListener() {
    if (
      this._serialApi
      && this._onSerialDisconnect
      && typeof this._serialApi.removeEventListener === 'function'
    ) {
      this._serialApi.removeEventListener('disconnect', this._onSerialDisconnect);
    }

    this._serialApi = null;
    this._onSerialDisconnect = null;
  }

  /** @private */
  _createError(code, message) {
    const error = new Error(message);

    error.code = code;

    return error;
  }

  /** @private */
  _translateError(error) {
    const name = error?.name ?? '';
    const message = error?.message ?? '';

    if (name === 'NotFoundError') {
      return this._createError(
        'port_selection_cancelled',
        'No se seleccionó ningún puerto serial.',
      );
    }

    if (name === 'SecurityError') {
      return this._createError(
        'permission_denied',
        'El navegador bloqueó el acceso al puerto serial. Acepta el permiso y verifica que el sitio use HTTPS.',
      );
    }

    if (name === 'InvalidStateError' || /already open|failed to open/i.test(message)) {
      return this._createError(
        'port_busy',
        'El puerto serial está ocupado. Cierra el programa que lo esté usando e inténtalo de nuevo.',
      );
    }

    if (name === 'NetworkError' || /device has been lost|disconnected/i.test(message)) {
      return this._createError(
        'device_lost',
        'Se perdió la conexión con la báscula. Revisa el cable y vuelve a conectar el puerto.',
      );
    }

    return this._createError(
      'serial_error',
      message !== '' ? `Error de puerto serial: ${message}` : 'Error desconocido del puerto serial.',
    );
  }
}

export default WebSerialScaleDriver;
