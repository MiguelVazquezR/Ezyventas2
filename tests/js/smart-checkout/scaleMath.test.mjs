import test, { describe } from 'node:test';
import assert from 'node:assert/strict';

import {
  DEFAULT_STABILITY,
  SCALE_UNITS,
  computeLineTotal,
  convertWeight,
  formatMoney,
  formatWeight,
  fromGrams,
  isStable,
  normalizeUnit,
  parseSerialLine,
  roundMoney,
  roundWeight,
  splitSerialBuffer,
  toGrams,
} from '../../../resources/js/Composables/smartCheckout/scaleMath.js';

describe('units', () => {
  test('toGrams normalizes kg and g', () => {
    assert.equal(toGrams(1, 'kg'), 1000);
    assert.equal(toGrams(1.5, 'kg'), 1500);
    assert.equal(toGrams(250, 'g'), 250);
    assert.equal(toGrams(0, 'kg'), 0);
  });

  test('accepts aliases and numeric strings, case-insensitively', () => {
    assert.equal(normalizeUnit('KGS'), 'kg');
    assert.equal(normalizeUnit('Grs'), 'g');
    assert.equal(normalizeUnit(' gr '), 'g');
    assert.equal(normalizeUnit('gramos'), 'g');
    assert.equal(normalizeUnit('Kilos'), 'kg');
    assert.equal(toGrams('1.5', 'Kg'), 1500);
    assert.equal(toGrams(1, 'GRS'), 1);
  });

  test('toGrams avoids floating point noise', () => {
    assert.equal(toGrams(0.1, 'kg'), 100);
    assert.equal(toGrams(0.3, 'kg'), 300);
  });

  test('rejects invalid values and unknown units', () => {
    assert.equal(toGrams(null, 'kg'), null);
    assert.equal(toGrams(NaN, 'kg'), null);
    assert.equal(toGrams(Infinity, 'kg'), null);
    assert.equal(toGrams('', 'kg'), null);
    assert.equal(toGrams('abc', 'kg'), null);
    assert.equal(toGrams(1, 'lb'), null);
    assert.equal(toGrams(1, undefined), null);
    assert.equal(normalizeUnit(null), null);
    assert.equal(normalizeUnit(5), null);
  });

  test('fromGrams rounds to 3 decimals', () => {
    assert.equal(fromGrams(1500, 'kg'), 1.5);
    assert.equal(fromGrams(250, 'g'), 250);
    assert.equal(fromGrams(1234.5678, 'g'), 1234.568);
    assert.equal(fromGrams(null, 'kg'), null);
    assert.equal(fromGrams(1000, 'oz'), null);
  });

  test('convertWeight converts between kg and g and keeps precision', () => {
    assert.equal(convertWeight(1.5, 'kg', 'g'), 1500);
    assert.equal(convertWeight(1250, 'g', 'kg'), 1.25);
    assert.equal(convertWeight(0.333, 'kg', 'g'), 333);
    assert.equal(convertWeight(1, 'kg', 'kg'), 1);
    assert.equal(convertWeight(0.0005, 'kg', 'g'), 0.5);
    assert.equal(convertWeight(1, 'lb', 'kg'), null);
    assert.equal(convertWeight(1, 'kg', 'lb'), null);
    assert.equal(convertWeight('abc', 'kg', 'g'), null);
  });

  test('exports the canonical units and default stability policy', () => {
    assert.deepEqual([...SCALE_UNITS], ['kg', 'g']);
    assert.deepEqual({ ...DEFAULT_STABILITY }, { minReadings: 3, toleranceGrams: 2 });
  });
});

describe('isStable', () => {
  const reading = (weight, unit = 'kg') => ({ weight, unit });

  test('a steady set of readings is stable', () => {
    assert.equal(isStable([reading(1.25), reading(1.25), reading(1.25)]), true);
  });

  test('requires the minimum number of readings', () => {
    assert.equal(isStable([reading(1.25), reading(1.25)]), false);
    assert.equal(
      isStable([reading(1.25), reading(1.25)], { minReadings: 2, toleranceGrams: 2 }),
      true,
    );
    assert.equal(isStable([reading(1.25)], { minReadings: 1 }), true);
  });

  test('uses the gram tolerance for the max-min range', () => {
    assert.equal(isStable([reading(1.0), reading(1.001), reading(1.002)], { toleranceGrams: 2 }), true);
    assert.equal(
      isStable([reading(1.0), reading(1.003)], { toleranceGrams: 2, minReadings: 2 }),
      false,
    );
    assert.equal(isStable([reading(1.0), reading(1.001), reading(1.001)], { toleranceGrams: 1 }), true);
    assert.equal(isStable([reading(1.0), reading(1.001), reading(1.002)], { toleranceGrams: 1 }), false);
    assert.equal(isStable([reading(1.0), reading(1.0), reading(1.0)], { toleranceGrams: 0 }), true);
  });

  test('normalizes mixed units before comparing', () => {
    assert.equal(
      isStable([reading(1, 'kg'), reading(999, 'g'), reading(1001, 'g')], { toleranceGrams: 2 }),
      true,
    );
    assert.equal(
      isStable([reading(1, 'kg'), reading(990, 'g')], { toleranceGrams: 2, minReadings: 2 }),
      false,
    );
    assert.equal(
      isStable([reading(1, 'kgs'), reading(1000, 'Grs'), reading(1000, 'g')], { toleranceGrams: 0 }),
      true,
    );
  });

  test('accepts already-normalized grams', () => {
    assert.equal(isStable([1000, 1001, 1000], { toleranceGrams: 1 }), true);
    assert.equal(isStable([1000, 1001, 1000], { toleranceGrams: 0 }), false);
  });

  test('an invalid entry or a non-array makes the set unstable', () => {
    assert.equal(isStable([reading(1), { weight: NaN, unit: 'kg' }, reading(1)]), false);
    assert.equal(isStable([reading(1), null, reading(1)]), false);
    assert.equal(isStable([reading(1), reading(1, 'lb')]), false);
    assert.equal(isStable(null), false);
    assert.equal(isStable([]), false);
  });

  test('ignores invalid options and falls back to defaults', () => {
    assert.equal(
      isStable([reading(1.25), reading(1.25), reading(1.25)], { minReadings: 'x', toleranceGrams: -1 }),
      true,
    );
  });
});

describe('computeLineTotal', () => {
  test('kg product, kg scale', () => {
    const result = computeLineTotal({
      weight: 0.333,
      unit: 'kg',
      pricePerUnit: 89.99,
      productUnit: 'Kg',
    });

    assert.equal(result.ok, true);
    assert.equal(result.quantity, 0.333);
    assert.equal(result.total, 29.97);
    assert.equal(result.unit, 'kg');
  });

  test('Grs product with a kg scale converts x1000 before multiplying', () => {
    const result = computeLineTotal({
      weight: 1.25,
      unit: 'kg',
      pricePerUnit: 0.15,
      productUnit: 'Grs',
    });

    assert.equal(result.ok, true);
    assert.equal(result.quantity, 1250);
    assert.equal(result.total, 187.5);
    assert.equal(result.unit, 'g');
  });

  test('kg product with a g scale divides by 1000', () => {
    const result = computeLineTotal({
      weight: 1500,
      unit: 'g',
      pricePerUnit: 90,
      productUnit: 'kg',
    });

    assert.equal(result.ok, true);
    assert.equal(result.quantity, 1.5);
    assert.equal(result.total, 135);
  });

  test('quantity is rounded to 3 decimals and the total follows it', () => {
    const result = computeLineTotal({
      weight: 1.23456,
      unit: 'kg',
      pricePerUnit: 10,
      productUnit: 'kg',
    });

    assert.equal(result.ok, true);
    assert.equal(result.quantity, 1.235);
    assert.equal(result.total, 12.35);
  });

  test('accepts numeric strings and a zero price', () => {
    const result = computeLineTotal({
      weight: '0.5',
      unit: 'kg',
      pricePerUnit: '100',
      productUnit: 'Kg',
    });

    assert.deepEqual(result, { ok: true, quantity: 0.5, total: 50, unit: 'kg' });

    const free = computeLineTotal({
      weight: 1,
      unit: 'kg',
      pricePerUnit: 0,
      productUnit: 'kg',
    });

    assert.equal(free.ok, true);
    assert.equal(free.total, 0);
  });

  test('rejects a zero or negative weight', () => {
    for (const weight of [0, -1, -0.001]) {
      const result = computeLineTotal({
        weight,
        unit: 'kg',
        pricePerUnit: 10,
        productUnit: 'kg',
      });

      assert.equal(result.ok, false, `weight ${String(weight)} must be rejected`);
      assert.equal(result.error, 'weight_not_positive');
      assert.equal(typeof result.message, 'string');
      assert.ok(result.message.length > 0);
    }
  });

  test('rejects a NaN, Infinity or missing weight', () => {
    for (const weight of [NaN, Infinity, -Infinity, null, undefined, '', 'abc']) {
      const result = computeLineTotal({
        weight,
        unit: 'kg',
        pricePerUnit: 10,
        productUnit: 'kg',
      });

      assert.equal(result.ok, false, `weight ${String(weight)} must be rejected`);
      assert.equal(result.error, 'invalid_weight');
      assert.equal(typeof result.message, 'string');
      assert.ok(result.message.length > 0);
    }
  });

  test('rejects unknown scale and product units', () => {
    assert.equal(
      computeLineTotal({ weight: 1, unit: 'lb', pricePerUnit: 10, productUnit: 'kg' }).error,
      'invalid_unit',
    );
    assert.equal(
      computeLineTotal({ weight: 1, unit: 'kg', pricePerUnit: 10, productUnit: 'Lts' }).error,
      'invalid_product_unit',
    );
  });

  test('rejects a NaN or negative price', () => {
    for (const pricePerUnit of [NaN, Infinity, -1, null, 'abc']) {
      const result = computeLineTotal({
        weight: 1,
        unit: 'kg',
        pricePerUnit,
        productUnit: 'kg',
      });

      assert.equal(result.ok, false);
      assert.equal(result.error, 'invalid_price');
    }
  });

  test('rejects an empty input object', () => {
    assert.equal(computeLineTotal().error, 'invalid_weight');
    assert.equal(computeLineTotal(null).error, 'invalid_weight');
  });
});

describe('rounding and formatting', () => {
  test('roundMoney keeps 2 decimals', () => {
    assert.equal(roundMoney(29.966670000000002), 29.97);
    assert.equal(roundMoney(0.333 * 89.99), 29.97);
    assert.equal(roundMoney(1.005), 1.01);
    assert.equal(roundMoney(2.675), 2.68);
    assert.equal(roundMoney(10), 10);
    assert.equal(roundMoney(NaN), null);
    assert.equal(roundMoney(Infinity), null);
  });

  test('roundWeight keeps 3 decimals and fixes 0.1 + 0.2', () => {
    assert.equal(roundWeight(0.1 + 0.2), 0.3);
    assert.equal(roundWeight(1.23456), 1.235);
    assert.equal(roundWeight('1.2344'), 1.234);
    assert.equal(roundWeight(null), null);
  });

  test('formatMoney uses es-MX and MXN', () => {
    assert.equal(formatMoney(29.97), '$29.97');
    assert.equal(formatMoney(1234.5), '$1,234.50');
    assert.equal(formatMoney('0.333'), '$0.33');
    assert.equal(formatMoney(NaN), '');
    assert.equal(formatMoney(undefined), '');
  });

  test('formatWeight uses es-MX grouping and the canonical unit', () => {
    assert.equal(formatWeight(1.25, 'kg'), '1.25 kg');
    assert.equal(formatWeight(1250, 'g'), '1,250 g');
    assert.equal(formatWeight(1.23456, 'kg'), '1.235 kg');
    assert.equal(formatWeight(1, 'Grs'), '1 g');
    assert.equal(formatWeight(NaN, 'kg'), '');
    assert.equal(formatWeight(1, 'lb'), '');
  });
});

describe('parseSerialLine', () => {
  const toledoProfile = {
    pattern: '(ST|US),GS,\\s*([+-]?\\s*\\d+(?:[.,]\\d+)?)\\s*(kg|g)\\b',
    flags: 'i',
    weightGroup: 2,
    unitGroup: 3,
    stableGroup: 1,
    stableValues: ['ST'],
    unit: 'kg',
  };

  test('parses a stable Toledo-style line', () => {
    assert.deepEqual(parseSerialLine('ST,GS,+  1.250 kg', toledoProfile), {
      weight: 1.25,
      unit: 'kg',
      stable: true,
    });
  });

  test('reports unstable when the stability field is not whitelisted', () => {
    assert.deepEqual(parseSerialLine('US,GS,+  1.250 kg', toledoProfile), {
      weight: 1.25,
      unit: 'kg',
      stable: false,
    });
  });

  test('uses default stable values when stableValues is omitted', () => {
    const profile = {
      ...toledoProfile,
      stableValues: undefined,
    };

    assert.equal(parseSerialLine('ST,GS,1.250 kg', profile).stable, true);
    assert.equal(parseSerialLine('US,GS,1.250 kg', profile).stable, false);
  });

  test('parses decimal comma when the profile asks for it', () => {
    const profile = {
      pattern: '([\\d.,]+)\\s*kg',
      weightGroup: 1,
      unit: 'kg',
      decimalComma: true,
    };

    assert.deepEqual(parseSerialLine('1,250 kg', profile), {
      weight: 1.25,
      unit: 'kg',
      stable: true,
    });

    assert.deepEqual(parseSerialLine('1.250,5 kg', profile), {
      weight: 1250.5,
      unit: 'kg',
      stable: true,
    });
  });

  test('treats commas as thousands separators when decimalComma is false', () => {
    const profile = {
      pattern: '([\\d.,]+)\\s*kg',
      weightGroup: 1,
      unit: 'kg',
    };

    assert.equal(parseSerialLine('1,250 kg', profile).weight, 1250);
  });

  test('falls back to the profile unit when the line has none', () => {
    const profile = {
      pattern: '([+-]?\\d+(?:\\.\\d+)?)',
      weightGroup: 1,
      unit: 'kg',
    };

    assert.deepEqual(parseSerialLine('1.500', profile), {
      weight: 1.5,
      unit: 'kg',
      stable: true,
    });
  });

  test('supports named capture groups', () => {
    const profile = {
      pattern: '(?<weight>[+-]?\\d+(?:[.,]\\d+)?)\\s*(?<unit>kgs?|grs?|gr|g)\\b',
      weightGroup: 'weight',
      unitGroup: 'unit',
      unit: 'kg',
      flags: 'i',
    };

    assert.deepEqual(parseSerialLine('0.750 kg', profile), {
      weight: 0.75,
      unit: 'kg',
      stable: true,
    });
  });

  test('keeps the sign so the driver can reject negative readings', () => {
    const profile = {
      pattern: '([+-]?\\d+(?:\\.\\d+)?)\\s*(kg|g)',
      weightGroup: 1,
      unitGroup: 2,
      unit: 'kg',
    };

    assert.equal(parseSerialLine('-0.020 kg', profile).weight, -0.02);
  });

  test('rounds the parsed weight to 3 decimals', () => {
    const profile = {
      pattern: '([\\d.]+)\\s*(kg)',
      weightGroup: 1,
      unitGroup: 2,
      unit: 'kg',
    };

    assert.equal(parseSerialLine('1.23456 kg', profile).weight, 1.235);
  });

  test('uses the default profile when none is supplied', () => {
    assert.deepEqual(parseSerialLine('+  1.250 kg'), {
      weight: 1.25,
      unit: 'kg',
      stable: true,
    });

    assert.deepEqual(parseSerialLine('+  1.250 KG'), {
      weight: 1.25,
      unit: 'kg',
      stable: true,
    });
  });

  test('accepts a group index serialized as a string', () => {
    const profile = {
      pattern: '([\\d.]+)\\s*(kg)',
      weightGroup: '1',
      unitGroup: '2',
      unit: 'kg',
    };

    assert.deepEqual(parseSerialLine('0.750 kg', profile), {
      weight: 0.75,
      unit: 'kg',
      stable: true,
    });
  });

  test('tolerates empty, non-matching and malformed input', () => {
    assert.equal(parseSerialLine('', toledoProfile), null);
    assert.equal(parseSerialLine('   ', toledoProfile), null);
    assert.equal(parseSerialLine('NO DATA', toledoProfile), null);
    assert.equal(parseSerialLine(null, toledoProfile), null);
    assert.equal(parseSerialLine(42, toledoProfile), null);
    assert.equal(parseSerialLine('1.250 kg', { pattern: '(', unit: 'kg' }), null);
    assert.equal(parseSerialLine('1.250 kg', { pattern: '([\\d.]+)', weightGroup: 1, unit: 'lb' }), null);
  });
});

describe('splitSerialBuffer', () => {
  test('splits with lf and keeps the trailing fragment', () => {
    assert.deepEqual(splitSerialBuffer('A\nB\nC', 'lf'), { lines: ['A', 'B'], rest: 'C' });
    assert.deepEqual(splitSerialBuffer('A\nB\n', 'lf'), { lines: ['A', 'B'], rest: '' });
  });

  test('splits with cr and keeps the trailing fragment', () => {
    assert.deepEqual(splitSerialBuffer('A\rB\rC', 'cr'), { lines: ['A', 'B'], rest: 'C' });
    assert.deepEqual(splitSerialBuffer('A\rB\r', 'cr'), { lines: ['A', 'B'], rest: '' });
  });

  test('splits with crlf', () => {
    assert.deepEqual(splitSerialBuffer('A\r\nB\r\n', 'crlf'), { lines: ['A', 'B'], rest: '' });
    assert.deepEqual(splitSerialBuffer('A\r\nB', 'crlf'), { lines: ['A'], rest: 'B' });
    assert.deepEqual(splitSerialBuffer('A\r\nB\r', 'crlf'), { lines: ['A'], rest: 'B\r' });
  });

  test('normalizes CRLF input when the profile says lf or cr', () => {
    assert.deepEqual(splitSerialBuffer('A\r\nB\n', 'lf'), { lines: ['A', 'B'], rest: '' });
    assert.deepEqual(splitSerialBuffer('A\r\nB\r', 'cr'), { lines: ['A', 'B'], rest: '' });
  });

  test('handles an empty buffer', () => {
    assert.deepEqual(splitSerialBuffer('', 'crlf'), { lines: [], rest: '' });
    assert.deepEqual(splitSerialBuffer(null, 'lf'), { lines: [], rest: '' });
  });

  test('throws on an unknown terminator', () => {
    assert.throws(() => splitSerialBuffer('A', 'tab'), /Terminador/);
  });
});
