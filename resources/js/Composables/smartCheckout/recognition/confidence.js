/**
 * Confidence rules for the Smart Checkout recognition engine.
 *
 * Deliberately framework free: no Vue, no DOM, no timers and no side effects on
 * import, so it is safe for Vite and for `node:test`.
 *
 * The three traffic-light colours are defined by the Smart Checkout spec:
 *
 *   green  -> best score  >= green_min  AND  gap to the runner-up >= green_margin,
 *             OR a valid barcode read.
 *   yellow -> best score  >= yellow_min (but not green),
 *             OR the gap to the runner-up is smaller than green_margin,
 *             OR the cameras disagree on the quantity of the product.
 *   red    -> best score  < yellow_min, or there are no candidates at all.
 *
 * Thresholds are never hard-coded at the call sites: the defaults live in
 * `DEFAULT_THRESHOLDS` and every entry point runs its input through
 * `normalizeThresholds`.
 *
 * Camera fusion follows the spec:
 *   - `zone` cameras add their counts (two cameras watching two different
 *     shelves count independently).
 *   - `overlap` cameras watch the same area, so the quantity is the MAXIMUM
 *     count reported by any of them; when they disagree the product is flagged
 *     `cameraDiscrepancy: true` and downgraded to yellow.
 */

/** Default thresholds. The only place where the numbers live. */
export const DEFAULT_THRESHOLDS = Object.freeze({
  green_min: 0.85,
  green_margin: 0.08,
  yellow_min: 0.65,
});

/** Valid detection statuses, in traffic-light order. */
export const DETECTION_STATUSES = Object.freeze(['green', 'yellow', 'red']);

/** Human (Spanish) labels for the UI. */
export const STATUS_LABELS = Object.freeze({
  green: 'Producto identificado',
  yellow: 'Verificar producto',
  red: 'Producto no identificado',
});

/** PrimeIcons class names for the UI (no icon decisions in the components). */
export const STATUS_ICONS = Object.freeze({
  green: 'pi-check-circle',
  yellow: 'pi-question-circle',
  red: 'pi-times-circle',
});

/** Fallback used by `statusLabel`/`statusIcon` for unknown statuses. */
const UNKNOWN_STATUS_LABEL = 'Estado desconocido';
const UNKNOWN_STATUS_ICON = 'pi-info-circle';

/** Floating point tolerance for the `0..1` box and threshold comparisons. */
const EPSILON = 1e-9;

/**
 * Coerce a number or a numeric string into a finite number.
 * Empty strings, booleans, `null`, `NaN` and `Infinity` become `null`.
 *
 * @param {unknown} value
 * @returns {number|null}
 */
function toFiniteNumber(value) {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }

  if (typeof value === 'string') {
    const trimmed = value.trim();

    if (trimmed === '') return null;

    const parsed = Number(trimmed);

    return Number.isFinite(parsed) ? parsed : null;
  }

  return null;
}

/**
 * Round a score to 3 decimals (the precision the UI displays) using exponent
 * notation so that values such as `0.8505` round as a human expects.
 *
 * @param {number} value
 * @returns {number}
 */
function roundScore(value) {
  const shifted = Number(`${value}e3`);

  if (!Number.isFinite(shifted)) return value;

  const rounded = Number(`${Math.round(shifted)}e-3`);

  return Number.isFinite(rounded) ? rounded : value;
}

/**
 * Normalize a product id. The UI and the backend use numeric ids; numeric
 * strings from JSON payloads are accepted too.
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
 * Normalize a barcode read. Empty or non-string values mean "no barcode".
 *
 * @param {unknown} value
 * @returns {string|null}
 */
function normalizeBarcode(value) {
  if (typeof value !== 'string') return null;

  const trimmed = value.trim();

  return trimmed === '' ? null : trimmed;
}

/**
 * Validate a normalized box (`x, y, w, h` in `0..1`).
 *
 * @param {unknown} box
 * @returns {[number, number, number, number]|null}
 */
function normalizeBox(box) {
  if (!Array.isArray(box) || box.length !== 4) return null;

  const numbers = box.map((value) => toFiniteNumber(value));

  if (numbers.some((value) => value === null)) return null;

  const [x, y, w, h] = numbers;

  if (x < 0 || y < 0 || w <= 0 || h <= 0) return null;
  if (x > 1 || y > 1 || w > 1 || h > 1) return null;
  if (x + w > 1 + EPSILON || y + h > 1 + EPSILON) return null;

  return [x, y, w, h];
}

/**
 * Sanitize the threshold object.
 *
 * Rules:
 * - Missing or invalid values fall back to `DEFAULT_THRESHOLDS`.
 * - Valid values are clamped to `0..1`.
 * - `yellow_min` can never be greater than `green_min`: `green_min` is raised
 *   to `yellow_min` so the green gate is never easier than the yellow gate.
 *
 * @param {unknown} [input]
 * @returns {{green_min: number, green_margin: number, yellow_min: number}}
 */
export function normalizeThresholds(input = {}) {
  const source = input && typeof input === 'object' ? input : {};

  const pick = (key) => {
    const numeric = toFiniteNumber(source[key]);

    if (numeric === null) return DEFAULT_THRESHOLDS[key];

    return Math.min(1, Math.max(0, numeric));
  };

  const yellow_min = pick('yellow_min');
  const green_min = Math.max(pick('green_min'), yellow_min);

  return {
    green_min,
    green_margin: pick('green_margin'),
    yellow_min,
  };
}

/**
 * Order, dedupe and cap a candidate list.
 *
 * - Invalid entries (unknown product, non-numeric score) are ignored.
 * - Scores are clamped to `0..1` and rounded to 3 decimals.
 * - Duplicates of the same product keep the highest score.
 * - Ties are broken by ascending `productId` so the output is deterministic.
 * - The input array is never mutated.
 *
 * @param {Array<{productId?: number, product_id?: number, score?: number}>|unknown} candidates
 * @param {number} [n] Maximum number of candidates (default 3).
 * @returns {Array<{productId: number, score: number}>}
 */
export function topCandidates(candidates, n = 3) {
  if (!Array.isArray(candidates)) return [];

  const requested = toFiniteNumber(n);
  const limit = Math.max(1, Math.min(50, Math.floor(requested ?? 3)));

  /** @type {Map<number, number>} */
  const bestByProduct = new Map();

  for (const raw of candidates) {
    if (!raw || typeof raw !== 'object') continue;

    const productId = normalizeProductId(raw.productId ?? raw.product_id);
    const score = toFiniteNumber(raw.score);

    if (productId === null || score === null) continue;

    const clamped = Math.min(1, Math.max(0, score));
    const current = bestByProduct.get(productId);

    if (current === undefined || clamped > current) {
      bestByProduct.set(productId, clamped);
    }
  }

  return [...bestByProduct.entries()]
    .sort((left, right) => right[1] - left[1] || left[0] - right[0])
    .slice(0, limit)
    .map(([productId, score]) => ({
      productId,
      score: roundScore(score),
    }));
}

/**
 * Classify a candidate list into the traffic light status.
 *
 * The classification uses the already-normalized top candidates, so the
 * semáforo always agrees with the scores the UI shows (rounded to 3 decimals).
 * With a single candidate the gap is measured against `0`.
 *
 * A valid barcode is enough for green, but a camera discrepancy always wins
 * over it: if the cameras disagree, the product must be reviewed.
 *
 * @param {Array<{productId: number, score: number}>|unknown} candidates
 * @param {{
 *   thresholds?: object,
 *   barcode?: string|null,
 *   cameraDiscrepancy?: boolean,
 * }} [options]
 * @returns {'green'|'yellow'|'red'}
 */
export function classifyCandidates(candidates, options = {}) {
  const thresholds = normalizeThresholds(options?.thresholds);
  const barcode = normalizeBarcode(options?.barcode);
  const cameraDiscrepancy = options?.cameraDiscrepancy === true;

  // A barcode identifies the product without vision, but a quantity mismatch
  // between cameras still has to be confirmed by the cashier.
  if (barcode !== null && !cameraDiscrepancy) return 'green';

  const top = topCandidates(candidates, 3);

  if (top.length === 0) return 'red';

  const best = top[0].score;

  if (best < thresholds.yellow_min - EPSILON) return 'red';

  const gap = top.length > 1
    ? roundScore(top[0].score - top[1].score)
    : top[0].score;

  const confident = best >= thresholds.green_min - EPSILON
    && gap >= thresholds.green_margin - EPSILON;

  if (confident && !cameraDiscrepancy) return 'green';

  return 'yellow';
}

/**
 * Spanish label for a detection status.
 *
 * @param {unknown} status
 * @returns {string}
 */
export function statusLabel(status) {
  const key = typeof status === 'string' ? status.toLowerCase() : '';

  return STATUS_LABELS[key] ?? UNKNOWN_STATUS_LABEL;
}

/**
 * PrimeIcons class name for a detection status.
 *
 * @param {unknown} status
 * @returns {string}
 */
export function statusIcon(status) {
  const key = typeof status === 'string' ? status.toLowerCase() : '';

  return STATUS_ICONS[key] ?? UNKNOWN_STATUS_ICON;
}

/**
 * Minimal detection sanitizer used by `mergeCameraDetections`.
 *
 * @param {unknown} detection
 * @returns {{
 *   cameraId: string,
 *   box: [number, number, number, number],
 *   candidates: Array<{productId: number, score: number}>,
 *   barcode?: string,
 * }|null}
 */
function sanitizeDetection(detection) {
  if (!detection || typeof detection !== 'object') return null;

  const cameraId = typeof detection.cameraId === 'string'
    ? detection.cameraId.trim()
    : '';

  if (cameraId === '') return null;

  const box = normalizeBox(detection.box);

  if (box === null) return null;

  const sanitized = {
    cameraId,
    box,
    candidates: topCandidates(detection.candidates, 3),
  };

  const barcode = normalizeBarcode(detection.barcode);

  if (barcode !== null) sanitized.barcode = barcode;

  return sanitized;
}

/**
 * Merge the detections of every camera into one consolidated list.
 *
 * Signature:
 * ```js
 * mergeCameraDetections(detections, cameras, { thresholds } = {})
 *   -> {
 *     detections: Detection[],          // consolidated, same shape as the input
 *     discrepancies: Record<string, {  // keyed by productId, only for overlap
 *       productId: number,
 *       counts: Record<string, number>,// count per (overlap) camera, 0 included
 *       cameras: string[],
 *       max: number,
 *       min: number,
 *       quantity: number,              // the merged quantity (the max)
 *     }>
 *   }
 * ```
 *
 * Rules:
 * - Detections without candidates cannot be grouped by product; they are
 *   returned as-is after the merged ones and always stay red.
 * - `zone` cameras add up. `overlap` cameras keep the maximum. If a product is
 *   seen by both groups, the merged quantity is `zoneSum + overlapMax`.
 * - Cameras missing from `cameras` default to `zone`.
 * - For overlap cameras, a camera that did not see the product counts as `0`;
 *   any difference between the counts is a discrepancy.
 * - A discrepancy forces the merged status to `yellow` and adds
 *   `cameraDiscrepancy: true`.
 * - The consolidated detection keeps the box and candidates of the highest
 *   scoring detection for that product, lists every source camera in
 *   `cameras`, adds `quantity`, and propagates the first barcode found.
 *
 * @param {Array<{
 *   cameraId: string,
 *   box: [number, number, number, number],
 *   candidates: Array<{productId: number, score: number}>,
 *   barcode?: string,
 *   status?: 'green'|'yellow'|'red',
 * }>|unknown} detections
 * @param {Array<{
 *   id: string,
 *   label?: string,
 *   mode?: 'zone'|'overlap',
 *   resolution?: string,
 * }>|unknown} cameras
 * @param {{thresholds?: object}} [options]
 * @returns {{
 *   detections: Array<{
 *     cameraId: string,
 *     box: [number, number, number, number],
 *     candidates: Array<{productId: number, score: number}>,
 *     status: 'green'|'yellow'|'red',
 *     quantity: number,
 *     cameras: string[],
 *     barcode?: string,
 *     cameraDiscrepancy?: true,
 *   }>,
 *   discrepancies: Record<string, {
 *     productId: number,
 *     counts: Record<string, number>,
 *     cameras: string[],
 *     max: number,
 *     min: number,
 *     quantity: number,
 *   }>,
 * }}
 */
export function mergeCameraDetections(detections, cameras, options = {}) {
  const thresholds = normalizeThresholds(options?.thresholds);
  const list = Array.isArray(detections) ? detections : [];
  const cameraList = Array.isArray(cameras) ? cameras : [];

  /** @type {Map<string, 'zone'|'overlap'>} */
  const modeByCamera = new Map();

  for (const camera of cameraList) {
    if (!camera || typeof camera !== 'object') continue;

    const id = typeof camera.id === 'string' ? camera.id.trim() : '';

    if (id === '') continue;

    modeByCamera.set(id, camera.mode === 'overlap' ? 'overlap' : 'zone');
  }

  const overlapCameraIds = [...modeByCamera.entries()]
    .filter(([, mode]) => mode === 'overlap')
    .map(([id]) => id);

  /** @type {Array<ReturnType<typeof sanitizeDetection>>} */
  const ungrouped = [];

  /** @type {Map<number, {
   *   productId: number,
   *   detections: Array<NonNullable<ReturnType<typeof sanitizeDetection>>>,
   *   counts: Map<string, number>,
   *   cameraIds: string[],
   * }>} */
  const groups = new Map();

  for (const raw of list) {
    const detection = sanitizeDetection(raw);

    if (detection === null) continue;

    const primary = detection.candidates[0];

    if (!primary) {
      ungrouped.push(detection);
      continue;
    }

    let group = groups.get(primary.productId);

    if (!group) {
      group = {
        productId: primary.productId,
        detections: [],
        counts: new Map(),
        cameraIds: [],
      };

      groups.set(primary.productId, group);
    }

    group.detections.push(detection);
    group.counts.set(
      detection.cameraId,
      (group.counts.get(detection.cameraId) ?? 0) + 1,
    );

    if (!group.cameraIds.includes(detection.cameraId)) {
      group.cameraIds.push(detection.cameraId);
    }
  }

  const merged = [];
  const discrepancies = {};

  for (const group of groups.values()) {
    let zoneQuantity = 0;

    /** @type {Map<string, number>} */
    const overlapCounts = new Map();

    for (const [cameraId, count] of group.counts) {
      if (modeByCamera.get(cameraId) === 'overlap') {
        overlapCounts.set(cameraId, count);
      } else {
        zoneQuantity += count;
      }
    }

    let overlapQuantity = 0;
    let discrepancy = null;

    if (overlapCounts.size > 0) {
      /** @type {Record<string, number>} */
      const counts = {};

      // Configured overlap cameras that did not see the product count as zero:
      // one camera seeing an item while the other sees nothing IS a mismatch.
      for (const cameraId of overlapCameraIds) {
        counts[cameraId] = overlapCounts.get(cameraId) ?? 0;
      }

      // Detections coming from overlap cameras not declared in `cameras`.
      for (const [cameraId, count] of overlapCounts) {
        counts[cameraId] = count;
      }

      const values = Object.values(counts);
      const max = Math.max(...values);
      const min = Math.min(...values);

      overlapQuantity = max;

      if (max !== min) {
        discrepancy = {
          productId: group.productId,
          counts,
          cameras: Object.keys(counts),
          max,
          min,
          quantity: max,
        };

        discrepancies[String(group.productId)] = discrepancy;
      }
    }

    const representative = group.detections.reduce(
      (best, detection) => (
        detection.candidates[0].score > best.candidates[0].score
          ? detection
          : best
      ),
      group.detections[0],
    );

    const barcode = group.detections
      .map((detection) => detection.barcode)
      .find((value) => value !== undefined) ?? null;

    let status = classifyCandidates(representative.candidates, {
      thresholds,
      barcode,
      cameraDiscrepancy: discrepancy !== null,
    });

    // A quantity mismatch must be reviewed by the cashier even when the score
    // alone would have been red.
    if (discrepancy !== null) status = 'yellow';

    const consolidated = {
      cameraId: representative.cameraId,
      box: [...representative.box],
      candidates: representative.candidates.map((candidate) => ({ ...candidate })),
      status,
      quantity: zoneQuantity + overlapQuantity,
      cameras: [...group.cameraIds],
    };

    if (barcode !== null) consolidated.barcode = barcode;
    if (discrepancy !== null) consolidated.cameraDiscrepancy = true;

    merged.push(consolidated);
  }

  for (const detection of ungrouped) {
    merged.push({
      cameraId: detection.cameraId,
      box: [...detection.box],
      candidates: [],
      status: 'red',
      quantity: 1,
      cameras: [detection.cameraId],
      ...(detection.barcode !== undefined ? { barcode: detection.barcode } : {}),
    });
  }

  return { detections: merged, discrepancies };
}
