/**
 * Recognition engine factory and catalog for the UI.
 *
 * `RECOGNITION_ENGINE_KINDS` is the source of truth for the selector labels
 * (UI text in Spanish). Adding an engine means adding one entry here.
 *
 * Phase 3 adds the ONNX engine (`onnx`), which reuses the same contract. It is
 * imported dynamically so the mock engine keeps working when the models are not
 * deployed yet.
 */

import { MockRecognitionEngine } from './MockRecognitionEngine.js';

export const RECOGNITION_ENGINE_KINDS = Object.freeze([
  Object.freeze({
    value: 'mock',
    label: 'Motor simulado',
    description: 'Genera detecciones falsas y deterministas para probar el semáforo sin cámara.',
  }),
  Object.freeze({
    value: 'onnx',
    label: 'Motor ONNX (cámara)',
    description: 'Inferencia real en el navegador con los modelos servidos por el dominio.',
  }),
]);

/** Map `kind -> Spanish label`, handy for badges and diagnostics. */
export const RECOGNITION_ENGINE_LABELS = Object.freeze(
  Object.fromEntries(
    RECOGNITION_ENGINE_KINDS.map((kind) => [kind.value, kind.label]),
  ),
);

/**
 * Create a recognition engine.
 *
 * @param {'mock'|'onnx'} kind
 * @param {object} [options] Engine-specific options (see each engine).
 * @returns {import('./RecognitionEngine.js').RecognitionEngine|Promise<object>}
 * @throws {Error} When `kind` is unknown.
 */
export function createRecognitionEngine(kind, options = {}) {
  switch (kind) {
    case 'mock':
      return new MockRecognitionEngine(options);

    case 'onnx':
      // Dynamic import keeps ONNX Runtime and zxing-wasm out of the bundle until
      // the cashier actually selects the camera engine.
      throw new Error('El motor ONNX se crea de forma asíncrona con createOnnxRecognitionEngine().');

    default:
      throw new Error(`Tipo de motor de reconocimiento desconocido: ${String(kind)}`);
  }
}

/**
 * Async variant: loads the ONNX engine (and with it the worker + WASM runtime).
 *
 * @param {object} [options] See `OnnxWebRecognitionEngine`.
 */
export async function createOnnxRecognitionEngine(options = {}) {
  const { OnnxWebRecognitionEngine } = await import('../inference/OnnxWebRecognitionEngine.js');
  return new OnnxWebRecognitionEngine(options);
}

/** Re-exported so the UI imports a single module. */
export {
  MockRecognitionEngine,
  SAMPLE_SOURCES,
  SAMPLE_SOURCE_LABELS,
} from './MockRecognitionEngine.js';

export { RecognitionEngine } from './RecognitionEngine.js';

export {
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
} from './confidence.js';
