import test, { describe } from 'node:test';
import assert from 'node:assert/strict';

import { ScaleDriver } from '../../../resources/js/Composables/smartCheckout/drivers/ScaleDriver.js';
import { SimulatedScaleDriver } from '../../../resources/js/Composables/smartCheckout/drivers/SimulatedScaleDriver.js';
import { ManualScaleDriver } from '../../../resources/js/Composables/smartCheckout/drivers/ManualScaleDriver.js';
import { WebSerialScaleDriver } from '../../../resources/js/Composables/smartCheckout/drivers/WebSerialScaleDriver.js';
import {
  SCALE_DRIVER_KINDS,
  SCALE_DRIVER_LABELS,
  createScaleDriver,
} from '../../../resources/js/Composables/smartCheckout/drivers/index.js';

/**
 * Resolve with the next payload of `event`.
 *
 * @param {ScaleDriver} driver
 * @param {string} [event]
 * @param {number} [timeout]
 */
function once(driver, event = 'reading', timeout = 1000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      off();
      reject(new Error(`Timed out waiting for "${event}"`));
    }, timeout);

    const off = driver.on(event, (payload) => {
      clearTimeout(timer);
      off();
      resolve(payload);
    });
  });
}

/**
 * Poll `predicate` until it is truthy.
 *
 * @param {() => unknown} predicate
 * @param {number} [timeout]
 */
function waitFor(predicate, timeout = 1000) {
  const startedAt = Date.now();

  return new Promise((resolve, reject) => {
    const poll = () => {
      let value;

      try {
        value = predicate();
      } catch (error) {
        reject(error);

        return;
      }

      if (value) {
        resolve(value);

        return;
      }

      if (Date.now() - startedAt > timeout) {
        reject(new Error('Timed out waiting for condition'));

        return;
      }

      setTimeout(poll, 5);
    };

    poll();
  });
}

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

describe('ScaleDriver (base)', () => {
  test('declares the abstract interface', async () => {
    const driver = new ScaleDriver();

    assert.equal(driver.kind, 'base');
    assert.equal(driver.isConnected, false);
    assert.equal(driver.lastReading, null);
    await assert.rejects(driver.connect(), /debe implementarse/);
    await assert.rejects(driver.tare(), /debe implementarse/);
  });

  test('emits readings and normalizes them', async () => {
    const driver = new ScaleDriver();
    const readings = [];

    const unsubscribe = driver.onReading((reading) => readings.push(reading));

    const emitted = driver._emitReading({ weight: '1.5', unit: 'Grs', stable: 1 });

    assert.deepEqual(emitted, { weight: 1.5, unit: 'g', stable: true });
    assert.deepEqual(readings, [{ weight: 1.5, unit: 'g', stable: true }]);
    assert.deepEqual(driver.lastReading, { weight: 1.5, unit: 'g', stable: true });

    unsubscribe();
    driver._emitReading({ weight: 2, unit: 'kg' });

    assert.equal(readings.length, 1);
  });

  test('rejects negative weights and unknown units', () => {
    const driver = new ScaleDriver();
    const errors = [];

    driver.onError((error) => errors.push(error));

    assert.equal(driver.normalizeReading({ weight: -1, unit: 'kg' }), null);
    assert.equal(driver.normalizeReading({ weight: 1, unit: 'lb' }), null);
    assert.equal(driver.normalizeReading(null), null);
    assert.equal(driver._emitReading({ weight: -0.001, unit: 'kg' }), null);
    assert.equal(driver._emitReading({ weight: 1, unit: 'lb' }), null);
    assert.equal(errors.length, 2);
    assert.equal(errors[0].code, 'invalid_reading');
    assert.match(errors[0].message, /no es válida/);
    assert.equal(driver.lastReading, null);
  });

  test('a throwing listener does not break the driver or other listeners', () => {
    const driver = new ScaleDriver();
    const seen = [];

    driver.onReading(() => {
      throw new Error('listener boom');
    });
    driver.onReading((reading) => seen.push(reading));

    assert.doesNotThrow(() => driver._emitReading({ weight: 1, unit: 'kg' }));
    assert.equal(seen.length, 1);
  });

  test('exposes onRaw and its onRawLine alias', () => {
    const driver = new ScaleDriver();
    const lines = [];

    const offRaw = driver.onRaw((line) => lines.push(`raw:${line}`));
    const offAlias = driver.onRawLine((line) => lines.push(`alias:${line}`));

    driver._emit('raw', 'ST,GS,1.250 kg');

    assert.deepEqual(lines, ['raw:ST,GS,1.250 kg', 'alias:ST,GS,1.250 kg']);

    offRaw();
    offAlias();
  });

  test('tracks status changes', async () => {
    const driver = new ScaleDriver();
    const statuses = [];

    driver.onStatus((status) => statuses.push(status));

    await driver.disconnect();

    assert.equal(driver.isConnected, false);
    assert.deepEqual(statuses.map((entry) => entry.status), ['disconnected']);
  });

  test('dispose disconnects and clears listeners', async () => {
    const driver = new ScaleDriver();
    let calls = 0;

    driver.onReading(() => {
      calls += 1;
    });

    await driver.dispose();
    driver._emitReading({ weight: 1, unit: 'kg' });

    assert.equal(driver.status, 'disposed');
    assert.equal(calls, 0);
  });
});

describe('SimulatedScaleDriver', () => {
  test('connect emits an immediate reading', async () => {
    const driver = new SimulatedScaleDriver({ weight: 1.25, unit: 'kg', autoEmit: false });

    const reading = await Promise.all([once(driver), driver.connect()]).then(([value]) => value);

    assert.deepEqual(reading, { weight: 1.25, unit: 'kg', stable: true });
    assert.equal(driver.isConnected, true);

    await driver.disconnect();
  });

  test('emits periodic readings while connected', async () => {
    const driver = new SimulatedScaleDriver({ intervalMs: 10 });
    const readings = [];

    driver.onReading((reading) => readings.push(reading));
    await driver.connect();
    await waitFor(() => readings.length >= 3, 1000);

    assert.ok(readings.length >= 3);

    await driver.disconnect();
  });

  test('setWeight, nudge and setStable update the emitted reading', async () => {
    const driver = new SimulatedScaleDriver({ autoEmit: false });

    await driver.connect();

    assert.deepEqual(driver.setWeight(2.5, 'kg'), { weight: 2.5, unit: 'kg', stable: true });
    assert.deepEqual(driver.nudge(0.25), { weight: 2.75, unit: 'kg', stable: true });
    assert.deepEqual(driver.nudge(-1), { weight: 1.75, unit: 'kg', stable: true });

    const unstable = driver.setStable(false);

    assert.equal(unstable, false);
    assert.equal(driver.lastReading.stable, false);

    assert.deepEqual(driver.setWeight(1250, 'g'), { weight: 1250, unit: 'g', stable: false });
    assert.equal(driver.unit, 'g');

    await driver.disconnect();
  });

  test('rejects invalid weights without changing the state', async () => {
    const driver = new SimulatedScaleDriver({ weight: 1 });
    const errors = [];

    driver.onError((error) => errors.push(error));
    await driver.connect();

    assert.equal(driver.setWeight(-1), null);
    assert.equal(driver.setWeight('abc'), null);
    assert.equal(driver.nudge(NaN), null);
    assert.equal(driver.weight, 1);
    assert.equal(errors.length, 3);
    assert.equal(errors[0].code, 'invalid_reading');

    await driver.disconnect();
  });

  test('tare resets to zero and emits the zero reading', async () => {
    const driver = new SimulatedScaleDriver({ weight: 2.4 });

    await driver.connect();

    const zeroPromise = once(driver);

    await driver.tare();

    assert.deepEqual(await zeroPromise, { weight: 0, unit: 'kg', stable: true });
    assert.equal(driver.weight, 0);

    await driver.disconnect();
  });

  test('disconnect stops the periodic readings', async () => {
    const driver = new SimulatedScaleDriver({ intervalMs: 10 });
    let count = 0;

    driver.onReading(() => {
      count += 1;
    });

    await driver.connect();
    await waitFor(() => count >= 2);

    await driver.disconnect();

    const countAtDisconnect = count;

    await delay(80);

    assert.equal(count, countAtDisconnect);
    assert.equal(driver.tick(), null);
  });

  test('tare requires a connection', async () => {
    const driver = new SimulatedScaleDriver();

    await assert.rejects(driver.tare(), /no está conectada/);
  });
});

describe('ManualScaleDriver', () => {
  test('submit emits one stable reading', async () => {
    const driver = new ManualScaleDriver();
    const readings = [];

    driver.onReading((reading) => readings.push(reading));
    await driver.connect();

    assert.deepEqual(driver.submit(0.75, 'kg'), { weight: 0.75, unit: 'kg', stable: true });
    assert.deepEqual(driver.submit('2'), { weight: 2, unit: 'kg', stable: true });
    assert.deepEqual(driver.submit(1250, 'Grs'), { weight: 1250, unit: 'g', stable: true });
    assert.equal(readings.length, 3);
    assert.ok(readings.every((reading) => reading.stable === true));

    await driver.disconnect();
  });

  test('submit rejects invalid weights with an error event', async () => {
    const driver = new ManualScaleDriver();
    const errors = [];

    driver.onError((error) => errors.push(error));
    await driver.connect();

    assert.equal(driver.submit(-1), null);
    assert.equal(driver.submit(NaN), null);
    assert.equal(driver.submit(1, 'lb'), null);
    assert.equal(errors.length, 3);

    // A zero reading is a legitimate display state (e.g. right after a tare);
    // the business rejection of `<= 0` lives in computeLineTotal.
    assert.deepEqual(driver.submit(0), { weight: 0, unit: 'kg', stable: true });

    await driver.disconnect();
  });

  test('submit requires connect and tare is a documented no-op', async () => {
    const driver = new ManualScaleDriver();

    assert.throws(() => driver.submit(1), /Conecta la captura manual/);

    await driver.connect();
    driver.submit(1);

    const before = driver.lastReading;

    await driver.tare();

    assert.deepEqual(driver.lastReading, before);

    await driver.disconnect();
  });
});

describe('createScaleDriver / SCALE_DRIVER_KINDS', () => {
  test('creates each driver kind', () => {
    assert.ok(createScaleDriver('simulated') instanceof SimulatedScaleDriver);
    assert.ok(createScaleDriver('manual') instanceof ManualScaleDriver);
    assert.ok(createScaleDriver('webserial') instanceof WebSerialScaleDriver);
    assert.ok(createScaleDriver('simulated', { weight: 3 }) instanceof SimulatedScaleDriver);
  });

  test('passes options through to the driver', () => {
    const driver = createScaleDriver('simulated', { weight: 3, unit: 'g' });

    assert.equal(driver.weight, 3);
    assert.equal(driver.unit, 'g');
  });

  test('rejects unknown kinds', () => {
    assert.throws(() => createScaleDriver('bluetooth'), /Tipo de báscula desconocido/);
    assert.throws(() => createScaleDriver(), /Tipo de báscula desconocido/);
  });

  test('exposes Spanish labels', () => {
    assert.equal(SCALE_DRIVER_KINDS.length, 3);
    assert.deepEqual(
      SCALE_DRIVER_KINDS.map((kind) => kind.value),
      ['simulated', 'manual', 'webserial'],
    );

    for (const kind of SCALE_DRIVER_KINDS) {
      assert.equal(typeof kind.label, 'string');
      assert.ok(kind.label.length > 0);
      assert.equal(SCALE_DRIVER_LABELS[kind.value], kind.label);
    }

    assert.match(SCALE_DRIVER_LABELS.webserial, /serial/i);
    assert.match(SCALE_DRIVER_LABELS.manual, /manual/i);
  });
});
