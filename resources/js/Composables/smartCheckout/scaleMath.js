/**
 * Pure scale math for the Smart Checkout scale.
 *
 * Deliberately framework free: no Vue, no DOM, no timers and no side effects on
 * import, so it is safe for Vite and for `node:test`.
 *
 * Money convention: 2 decimals. Weight convention: 3 decimals (the POS stores
 * `decimal(10,3)` quantities).
 */

/** Canonical weight units understood by every helper. */
export const SCALE_UNITS = Object.freeze(['kg', 'g']);

/** Defaults used by `isStable` and by the drivers when the profile is silent. */
export const DEFAULT_STABILITY = Object.freeze({
  minReadings: 3,
  toleranceGrams: 2,
});

const UNIT_ALIASES = Object.freeze({
  kg: 'kg',
  kgs: 'kg',
  kilo: 'kg',
  kilos: 'kg',
  g: 'g',
  gr: 'g',
  grs: 'g',
  gramo: 'g',
  gramos: 'g',
});

/** Values that mean "the scale itself says this reading is stable". */
const DEFAULT_STABLE_VALUES = Object.freeze([
  'ST',
  'S',
  '1',
  'TRUE',
  'STABLE',
  'ESTABLE',
]);

const SERIAL_TERMINATORS = Object.freeze({
  lf: '\n',
  cr: '\r',
  crlf: '\r\n',
});

/** Generic line format: `+  1.250 kg`, `ST,GS,1.250 kg`, etc. */
const DEFAULT_SERIAL_PATTERN = '([+-]?\\s*\\d+(?:[.,]\\d+)?)\\s*(kgs?|grs?|gr|g)\\b';

const MONEY_FORMATTER = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
});

const WEIGHT_FORMATTER = new Intl.NumberFormat('es-MX', {
  maximumFractionDigits: 3,
});

/**
 * Normalize any supported weight unit to `'kg'` or `'g'`.
 *
 * @param {unknown} unit
 * @returns {'kg'|'g'|null} `null` when the unit is unknown.
 */
export function normalizeUnit(unit) {
  if (typeof unit !== 'string') return null;

  const key = unit.trim().toLowerCase();

  return UNIT_ALIASES[key] ?? null;
}

/**
 * Coerce a number or a numeric string into a finite number.
 * Empty strings, booleans, `NaN`, `Infinity` and `null` become `null`.
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
 * Round half away from zero at `decimals`, using exponent notation so that
 * values like `1.005` or `2.675` round as a human expects instead of as their
 * binary representation suggests.
 *
 * @param {unknown} value
 * @param {number} decimals
 * @returns {number|null}
 */
function roundTo(value, decimals) {
  const numeric = toFiniteNumber(value);

  if (numeric === null) return null;

  if (Math.abs(numeric) >= 1e15) {
    return Math.round(numeric);
  }

  const shifted = Number(`${numeric}e${decimals}`);

  if (!Number.isFinite(shifted)) {
    const factor = 10 ** decimals;

    return Math.round(numeric * factor) / factor;
  }

  const result = Number(`${Math.round(shifted)}e-${decimals}`);

  return Number.isFinite(result) ? result : numeric;
}

/**
 * Convert a weight to grams.
 *
 * @param {number|string} value Weight expressed in `unit`.
 * @param {string} unit `'kg'|'g'` or an alias (`kgs`, `grs`, `gr`, case-insensitive).
 * @returns {number|null} Grams rounded to 3 decimals, or `null` on invalid input.
 */
export function toGrams(value, unit) {
  const numeric = toFiniteNumber(value);
  const normalizedUnit = normalizeUnit(unit);

  if (numeric === null || normalizedUnit === null) return null;

  return roundTo(normalizedUnit === 'kg' ? numeric * 1000 : numeric, 3);
}

/**
 * Convert grams to a target unit.
 *
 * @param {number|string} grams
 * @param {string} unit `'kg'|'g'` or an alias.
 * @returns {number|null} Weight in `unit` rounded to 3 decimals, or `null`.
 */
export function fromGrams(grams, unit) {
  const numeric = toFiniteNumber(grams);
  const normalizedUnit = normalizeUnit(unit);

  if (numeric === null || normalizedUnit === null) return null;

  return roundTo(normalizedUnit === 'kg' ? numeric / 1000 : numeric, 3);
}

/**
 * Convert a weight between units without losing the 3-decimal convention.
 *
 * @param {number|string} value
 * @param {string} fromUnit
 * @param {string} toUnit
 * @returns {number|null} Weight in `toUnit` rounded to 3 decimals, or `null`.
 */
export function convertWeight(value, fromUnit, toUnit) {
  const numeric = toFiniteNumber(value);
  const from = normalizeUnit(fromUnit);
  const to = normalizeUnit(toUnit);

  if (numeric === null || from === null || to === null) return null;

  if (from === to) return roundTo(numeric, 3);

  const grams = toGrams(numeric, from);

  if (grams === null) return null;

  return fromGrams(grams, to);
}

/**
 * Decide whether a set of readings is stable.
 *
 * A set is stable when it has at least `minReadings` valid readings and the
 * range (`max - min`, in grams) is `<= toleranceGrams`.
 *
 * Readings may be `{weight, unit}` objects or already-normalized gram numbers.
 * An invalid entry makes the set unstable (it returns `false`).
 *
 * @param {Array<{weight: number|string, unit: string}>|Array<number|string>} readings
 * @param {{minReadings?: number, toleranceGrams?: number}} [options]
 * @returns {boolean}
 */
export function isStable(readings, options = {}) {
  if (!Array.isArray(readings)) return false;

  const minReadings = Math.max(
    1,
    Math.floor(toFiniteNumber(options?.minReadings) ?? DEFAULT_STABILITY.minReadings),
  );

  const toleranceValue = toFiniteNumber(options?.toleranceGrams);
  const toleranceGrams = toleranceValue !== null && toleranceValue >= 0
    ? toleranceValue
    : DEFAULT_STABILITY.toleranceGrams;

  if (readings.length < minReadings) return false;

  const grams = [];

  for (const reading of readings) {
    let value = null;

    if (typeof reading === 'number' || typeof reading === 'string') {
      value = toFiniteNumber(reading);
    } else if (reading && typeof reading === 'object') {
      value = toGrams(reading.weight, reading.unit);
    }

    if (value === null) return false;

    grams.push(value);
  }

  if (grams.length < minReadings) return false;

  const min = Math.min(...grams);
  const max = Math.max(...grams);

  return max - min <= toleranceGrams;
}

/**
 * Compute the line total for a weighed product.
 *
 * `pricePerUnit` is the price of one `productUnit` (D3b). The quantity is
 * converted to the product unit and rounded to 3 decimals, and the money total
 * is derived from that same rounded quantity so the UI and the backend
 * (`unit_price * quantity`) agree to the cent.
 *
 * @param {{
 *   weight: number|string,
 *   unit: string,
 *   pricePerUnit: number|string,
 *   productUnit: string,
 * }} input
 * @returns {{
 *   ok: true,
 *   quantity: number,
 *   total: number,
 *   unit: 'kg'|'g',
 * } | {
 *   ok: false,
 *   error: 'invalid_weight'|'weight_not_positive'|'invalid_unit'|
 *     'invalid_product_unit'|'invalid_price',
 *   message: string,
 * }}
 */
export function computeLineTotal(input = {}) {
  const { weight, unit, pricePerUnit, productUnit } = input ?? {};

  const numericWeight = toFiniteNumber(weight);
  const numericPrice = toFiniteNumber(pricePerUnit);
  const scaleUnit = normalizeUnit(unit);
  const targetUnit = normalizeUnit(productUnit);

  if (numericWeight === null) {
    return {
      ok: false,
      error: 'invalid_weight',
      message: 'El peso de la báscula no es un número válido.',
    };
  }

  if (numericWeight <= 0) {
    return {
      ok: false,
      error: 'weight_not_positive',
      message: 'El peso debe ser mayor que cero.',
    };
  }

  if (scaleUnit === null) {
    return {
      ok: false,
      error: 'invalid_unit',
      message: 'La unidad de la báscula no es válida.',
    };
  }

  if (targetUnit === null) {
    return {
      ok: false,
      error: 'invalid_product_unit',
      message: 'La unidad del producto no es válida.',
    };
  }

  if (numericPrice === null || numericPrice < 0) {
    return {
      ok: false,
      error: 'invalid_price',
      message: 'El precio por unidad del producto no es válido.',
    };
  }

  const quantity = convertWeight(numericWeight, scaleUnit, targetUnit);

  if (quantity === null || quantity <= 0) {
    return {
      ok: false,
      error: 'invalid_weight',
      message: 'No se pudo convertir el peso a la unidad del producto.',
    };
  }

  return {
    ok: true,
    quantity,
    total: roundMoney(quantity * numericPrice),
    unit: targetUnit,
  };
}

/**
 * Round a money amount to 2 decimals.
 *
 * @param {number|string} value
 * @returns {number|null} `null` when the value is not a finite number.
 */
export function roundMoney(value) {
  return roundTo(value, 2);
}

/**
 * Round a weight to 3 decimals.
 *
 * @param {number|string} value
 * @returns {number|null} `null` when the value is not a finite number.
 */
export function roundWeight(value) {
  return roundTo(value, 3);
}

/**
 * Format a weight for the UI (`es-MX`, up to 3 decimals) with its unit.
 *
 * @param {number|string} quantity
 * @param {string} unit
 * @returns {string} e.g. `'1.25 kg'`, or `''` on invalid input.
 */
export function formatWeight(quantity, unit) {
  const numeric = toFiniteNumber(quantity);
  const normalizedUnit = normalizeUnit(unit);

  if (numeric === null || normalizedUnit === null) return '';

  return `${WEIGHT_FORMATTER.format(roundTo(numeric, 3))} ${normalizedUnit}`;
}

/**
 * Format money for the UI: `es-MX` locale, `MXN` currency, 2 decimals.
 *
 * @param {number|string} value
 * @returns {string} e.g. `'$29.97'`, or `''` on invalid input.
 */
export function formatMoney(value) {
  const numeric = toFiniteNumber(value);

  if (numeric === null) return '';

  return MONEY_FORMATTER.format(roundTo(numeric, 2));
}

/**
 * Resolve a capture group by numeric index or by named group.
 *
 * @param {RegExpExecArray} match
 * @param {number|string|null|undefined} group
 * @returns {string|null}
 */
function resolveGroup(match, group) {
  if (group === null || group === undefined) return null;

  if (typeof group === 'number') {
    const value = match[group];

    return typeof value === 'string' ? value : null;
  }

  if (typeof group === 'string') {
    if (match.groups && group in match.groups) {
      const value = match.groups[group];

      return typeof value === 'string' ? value : null;
    }

    // Profiles serialized by the backend may send the group index as "2".
    if (/^\d+$/.test(group)) {
      const value = match[Number(group)];

      return typeof value === 'string' ? value : null;
    }
  }

  return null;
}

/**
 * Turn the captured numeric text into a number, honoring the decimal
 * separator used by the scale.
 *
 * @param {string} raw
 * @param {boolean} decimalComma
 * @returns {number|null}
 */
function parseCapturedNumber(raw, decimalComma) {
  const withoutSpaces = raw.replace(/\s+/g, '');

  const normalized = decimalComma
    ? withoutSpaces.replace(/\./g, '').replace(',', '.')
    : withoutSpaces.replace(/,/g, '');

  return toFiniteNumber(normalized);
}

/**
 * Parse one raw serial line with a scale profile.
 *
 * Profile shape:
 * ```
 * {
 *   pattern: string|RegExp,   // usually with capture groups
 *   weightGroup: number|string, // default 1
 *   unitGroup?: number|string,  // optional
 *   stableGroup?: number|string,// optional
 *   unit?: 'kg'|'g',            // fallback when the line has no unit
 *   stableValues?: string[],    // values meaning "stable", default ST/S/1/...
 *   decimalComma?: boolean,     // `1,250` -> 1.25
 *   flags?: string,             // RegExp flags when pattern is a string
 * }
 * ```
 *
 * With no `stableGroup` the parsed reading is reported as `stable: true`; the
 * driver still combines it with the multi-reading stability window.
 *
 * @param {string} line
 * @param {object} [profile]
 * @returns {{weight: number, unit: 'kg'|'g', stable: boolean}|null}
 */
export function parseSerialLine(line, profile = {}) {
  if (typeof line !== 'string') return null;

  const text = line.trim();

  if (text === '') return null;

  // Defaults let a profile declare only its `pattern`; explicit `null` still
  // disables the corresponding group.
  const settings = {
    weightGroup: 1,
    unitGroup: 2,
    unit: 'kg',
    ...(profile ?? {}),
  };

  const pattern = settings.pattern ?? DEFAULT_SERIAL_PATTERN;

  // A caller-supplied pattern keeps its exact semantics; the built-in one is
  // case-insensitive because scale vendors write `kg`, `KG` or `Kg`.
  const flags = settings.flags ?? (settings.pattern === undefined ? 'i' : '');

  let regex;

  try {
    regex = pattern instanceof RegExp
      ? pattern
      : new RegExp(pattern, flags);
  } catch {
    return null;
  }

  // A profile could reuse an instance with the global flag; resetting it keeps
  // every line parsed from the start.
  regex.lastIndex = 0;

  const match = regex.exec(text);

  if (!match) return null;

  const rawWeight = resolveGroup(match, settings.weightGroup);

  if (rawWeight === null) return null;

  const weight = parseCapturedNumber(rawWeight, settings.decimalComma === true);

  if (weight === null) return null;

  let unit = normalizeUnit(resolveGroup(match, settings.unitGroup) ?? '');

  if (unit === null) unit = normalizeUnit(settings.unit ?? '');

  if (unit === null) return null;

  let stable = true;

  if (settings.stableGroup !== undefined && settings.stableGroup !== null) {
    const rawStable = resolveGroup(match, settings.stableGroup);

    if (rawStable === null) {
      stable = false;
    } else {
      const stableValues = Array.isArray(settings.stableValues) && settings.stableValues.length > 0
        ? settings.stableValues
        : DEFAULT_STABLE_VALUES;

      const normalizedRaw = rawStable.trim().toUpperCase();

      stable = stableValues.some(
        (value) => String(value).trim().toUpperCase() === normalizedRaw,
      );
    }
  }

  return {
    weight: roundTo(weight, 3),
    unit,
    stable,
  };
}

/**
 * Split an accumulated serial buffer on the configured line terminator.
 *
 * The trailing fragment (no terminator yet) is returned as `rest` so the
 * driver can prepend the next chunk.
 *
 * @param {string} buffer
 * @param {'lf'|'cr'|'crlf'} [terminator]
 * @returns {{lines: string[], rest: string}}
 * @throws {Error} When the terminator is unknown.
 */
export function splitSerialBuffer(buffer, terminator = 'crlf') {
  const separator = SERIAL_TERMINATORS[terminator];

  if (!separator) {
    throw new Error(`Terminador de línea de báscula desconocido: ${terminator}`);
  }

  const text = typeof buffer === 'string' ? buffer : '';

  // Devices configured as lf/cr sometimes still emit CRLF; normalize so the
  // fragment is not polluted with stray characters.
  const normalized = terminator === 'crlf'
    ? text
    : text.replace(/\r\n/g, separator);

  const parts = normalized.split(separator);
  const rest = parts.pop() ?? '';

  return { lines: parts, rest };
}
