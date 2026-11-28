/**
 * Scale driver factory and catalog for the UI.
 *
 * `SCALE_DRIVER_KINDS` is the source of truth for the selector labels (UI text
 * in Spanish). Adding a driver means adding one entry here.
 */

import { ManualScaleDriver } from './ManualScaleDriver.js';
import { SimulatedScaleDriver } from './SimulatedScaleDriver.js';
import { WebSerialScaleDriver } from './WebSerialScaleDriver.js';

export const SCALE_DRIVER_KINDS = Object.freeze([
  Object.freeze({
    value: 'simulated',
    label: 'Báscula simulada',
    description: 'Panel de desarrollo: simula pesos estables e inestables sin hardware.',
  }),
  Object.freeze({
    value: 'manual',
    label: 'Captura manual',
    description: 'El cajero escribe el peso a mano.',
  }),
  Object.freeze({
    value: 'webserial',
    label: 'Báscula por puerto serial',
    description: 'Requiere Chrome o Edge, conexión HTTPS y permiso para el puerto.',
  }),
]);

/** Map `kind -> Spanish label`, handy for badges and diagnostics. */
export const SCALE_DRIVER_LABELS = Object.freeze(
  Object.fromEntries(SCALE_DRIVER_KINDS.map((kind) => [kind.value, kind.label])),
);

/**
 * Create a scale driver.
 *
 * @param {'simulated'|'manual'|'webserial'} kind
 * @param {object} [options] Driver-specific options (see each class).
 * @returns {import('./drivers/ScaleDriver.js').ScaleDriver}
 * @throws {Error} When `kind` is unknown.
 */
export function createScaleDriver(kind, options = {}) {
  switch (kind) {
    case 'simulated':
      return new SimulatedScaleDriver(options);

    case 'manual':
      return new ManualScaleDriver(options);

    case 'webserial':
      return new WebSerialScaleDriver(options);

    default:
      throw new Error(`Tipo de báscula desconocido: ${String(kind)}`);
  }
}

/** Re-exported for convenience so the UI imports a single module. */
export { ManualScaleDriver, SimulatedScaleDriver, WebSerialScaleDriver };
