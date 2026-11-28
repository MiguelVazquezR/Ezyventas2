import test, { describe } from 'node:test';
import assert from 'node:assert/strict';

import { WebSerialScaleDriver } from '../../../resources/js/Composables/smartCheckout/drivers/WebSerialScaleDriver.js';

/**
 * Minimal SerialPort double, enough for the driver's read loop.
 */
class FakePort {
  constructor() {
    this.openCount = 0;
    this.openedWith = null;
    this.closed = false;
    this.readable = null;
    this._controller = null;
  }

  async open(options) {
    this.openCount += 1;
    this.openedWith = options;
    this.readable = new ReadableStream({
      start: (controller) => {
        this._controller = controller;
      },
    });
  }

  push(text) {
    this._controller.enqueue(new TextEncoder().encode(text));
  }

  async close() {
    this.closed = true;

    try {
      this._controller?.close();
    } catch {
      // Already cancelled by disconnect().
    }
  }
}

/**
 * Minimal Serial API double (`navigator.serial`).
 */
class FakeSerial {
  constructor(port) {
    this.port = port;
    this.requestCount = 0;
    this.listeners = new Map();
  }

  async requestPort() {
    this.requestCount += 1;

    return this.port;
  }

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());

    this.listeners.get(type).add(listener);
  }

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
  }

  emit(type, event) {
    for (const listener of [...(this.listeners.get(type) ?? [])]) {
      listener(event);
    }
  }
}

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

const toledoLine = (weight, status = 'ST') => `${status},GS,+  ${weight} kg\r\n`;

describe('WebSerialScaleDriver capability and profile', () => {
  test('isSupported is false outside a browser with Web Serial', () => {
    assert.equal(WebSerialScaleDriver.isSupported(), false);
  });

  test('connect without a Serial API rejects as unsupported', async () => {
    const driver = new WebSerialScaleDriver();

    await assert.rejects(driver.connect(), (error) => error.code === 'unsupported');
    assert.equal(driver.isConnected, false);
    assert.equal(driver.status, 'error');
  });

  test('constructing the driver does not touch navigator', () => {
    assert.doesNotThrow(() => new WebSerialScaleDriver({ debug: false }));
  });
});

describe('WebSerialScaleDriver connection errors', () => {
  test('user cancelling the port chooser is reported as cancelled', async () => {
    const serial = {
      async requestPort() {
        const error = new Error('No port selected by the user.');

        error.name = 'NotFoundError';

        throw error;
      },
    };

    const driver = new WebSerialScaleDriver({ serial });

    await assert.rejects(driver.connect(), (error) => error.code === 'port_selection_cancelled');
    assert.equal(driver.status, 'idle');
  });

  test('a busy port is reported as port_busy and cleaned up', async () => {
    const port = new FakePort();

    port.open = async () => {
      const error = new Error('Failed to open serial port.');

      error.name = 'InvalidStateError';

      throw error;
    };

    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({ serial });

    await assert.rejects(driver.connect(), (error) => error.code === 'port_busy');
    assert.equal(driver.isConnected, false);
    assert.equal(driver.status, 'error');
    assert.equal(port.closed, true);
  });
});

describe('WebSerialScaleDriver reading pipeline', () => {
  test('opens the port with the profile and parses complete lines', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({
      serial,
      profile: { baudRate: 19200, dataBits: 7, stopBits: 2, parity: 'even', terminator: 'crlf' },
      stability: { minReadings: 3, toleranceGrams: 2 },
    });

    const rawLines = [];

    driver.onRaw((line) => rawLines.push(line));

    await driver.connect();

    assert.equal(serial.requestCount, 1);
    assert.deepEqual(port.openedWith, {
      baudRate: 19200,
      dataBits: 7,
      stopBits: 2,
      parity: 'even',
      flowControl: 'none',
    });

    port.push(toledoLine('1.250') + toledoLine('1.250') + toledoLine('1.250'));

    await waitFor(() => driver.getRawLog().length === 3 && driver.lastReading !== null);

    assert.deepEqual(driver.lastReading, { weight: 1.25, unit: 'kg', stable: true });
    assert.deepEqual(rawLines, [
      'ST,GS,+  1.250 kg',
      'ST,GS,+  1.250 kg',
      'ST,GS,+  1.250 kg',
    ]);

    port.push(toledoLine('1.400'));

    await waitFor(() => driver.lastReading.weight === 1.4);

    assert.equal(driver.lastReading.stable, false);

    await driver.disconnect();

    assert.equal(port.closed, true);
    assert.equal(driver.isConnected, false);
    assert.equal(driver.status, 'disconnected');
  });

  test('buffers a line split across two chunks', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({
      serial,
      stability: { minReadings: 1 },
    });

    await driver.connect();

    port.push('ST,GS,+  1.2');
    port.push('60 kg\r\n');

    await waitFor(() => driver.lastReading !== null);

    assert.equal(driver.lastReading.weight, 1.26);

    await driver.disconnect();
  });

  test('coerces framing values stored as strings', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({
      serial,
      profile: {
        baudRate: '19200',
        dataBits: '8',
        stopBits: '1',
        parity: 'EVEN',
        terminator: 'CRLF',
      },
    });

    await driver.connect();

    assert.equal(port.openedWith.baudRate, 19200);
    assert.equal(port.openedWith.dataBits, 8);
    assert.equal(port.openedWith.stopBits, 1);
    assert.equal(port.openedWith.parity, 'even');
    assert.equal(driver.profile.terminator, 'crlf');

    await driver.disconnect();
  });

  test('reports a stream that ends by itself as a lost device', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({ serial });
    const errors = [];

    driver.onError((error) => errors.push(error));

    await driver.connect();
    port._controller.close();

    await waitFor(() => driver.status === 'error');

    assert.equal(errors[0].code, 'device_lost');
    assert.equal(driver.isConnected, false);

    await driver.disconnect();
  });

  test('connect is idempotent and does not reopen the port', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({ serial });

    await driver.connect();
    await driver.connect();

    assert.equal(port.openCount, 1);

    await driver.disconnect();
  });

  test('an injected port skips requestPort', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({ serial, port });

    await driver.connect();

    assert.equal(serial.requestCount, 0);
    assert.equal(port.openCount, 1);

    await driver.disconnect();
  });

  test('decodes a decimal comma profile', () => {
    const driver = new WebSerialScaleDriver({
      profile: {
        pattern: '([\\d.,]+)\\s*(kg)',
        weightGroup: 1,
        unitGroup: 2,
        unit: 'kg',
        decimalComma: true,
      },
      stability: { minReadings: 1 },
    });

    assert.deepEqual(driver.ingestLine('1,250 kg'), {
      weight: 1.25,
      unit: 'kg',
      stable: true,
    });
  });
});

describe('WebSerialScaleDriver tare', () => {
  test('software tare subtracts the current weight from later readings', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({
      serial,
      stability: { minReadings: 1 },
    });

    await driver.connect();

    port.push(toledoLine('1.250'));

    await waitFor(() => driver.lastReading !== null);

    const zeroPromise = new Promise((resolve) => driver.onReading((reading) => {
      if (reading.weight === 0) resolve(reading);
    }));

    await driver.tare();

    assert.deepEqual(await zeroPromise, { weight: 0, unit: 'kg', stable: true });
    assert.equal(driver.tareOffsetGrams, 1250);

    port.push(toledoLine('1.300'));

    await waitFor(() => driver.lastReading.weight === 0.05);

    assert.equal(driver.lastReading.weight, 0.05);
    assert.equal(driver.lastReading.unit, 'kg');

    await driver.disconnect();
  });

  test('tare can be cleared and requires a connection', async () => {
    const driver = new WebSerialScaleDriver();

    await assert.rejects(driver.tare(), /no está conectada/);

    driver.clearTare();

    assert.equal(driver.tareOffsetGrams, 0);
  });

  test('rejects a negative reading after applying the tare offset', async () => {
    const driver = new WebSerialScaleDriver({ stability: { minReadings: 1 } });
    const errors = [];

    driver.onError((error) => errors.push(error));

    assert.equal(driver.ingestLine('-0.500 kg'), null);
    assert.equal(driver.lastReading, null);
    assert.equal(errors[0].code, 'invalid_reading');
  });
});

describe('WebSerialScaleDriver diagnostics', () => {
  test('keeps a 200-line raw log by default', () => {
    const driver = new WebSerialScaleDriver();

    assert.equal(driver.rawLogLimit, 200);

    for (let index = 0; index < 205; index += 1) {
      driver.ingestLine(`NO DATA ${index}`);
    }

    const log = driver.getRawLog();

    assert.equal(log.length, 200);
    assert.equal(log[0], 'NO DATA 5');
    assert.equal(log.at(-1), 'NO DATA 204');
  });

  test('honors a custom raw log limit and clearRawLog', () => {
    const driver = new WebSerialScaleDriver({ rawLogLimit: 3 });

    driver.ingestLine('A');
    driver.ingestLine('B');
    driver.ingestLine('C');
    driver.ingestLine('D');

    assert.deepEqual(driver.getRawLog(), ['B', 'C', 'D']);

    driver.clearRawLog();

    assert.deepEqual(driver.getRawLog(), []);
  });

  test('ignores lines without a match and keeps them in the log', () => {
    const driver = new WebSerialScaleDriver();

    assert.equal(driver.ingestLine('NO MATCH'), null);
    assert.equal(driver.ingestLine(''), null);
    assert.deepEqual(driver.getRawLog(), ['NO MATCH', '']);
  });
});

describe('WebSerialScaleDriver physical disconnect', () => {
  test('reports the device disconnection and releases the port', async () => {
    const port = new FakePort();
    const serial = new FakeSerial(port);
    const driver = new WebSerialScaleDriver({ serial, stability: { minReadings: 1 } });
    const errors = [];

    driver.onError((error) => errors.push(error));

    await driver.connect();

    port.push(toledoLine('1.250'));

    await waitFor(() => driver.lastReading !== null);

    serial.emit('disconnect', { target: port });

    await waitFor(() => driver.status === 'disconnected');

    assert.equal(errors[0].code, 'device_disconnected');
    assert.equal(driver.isConnected, false);
    assert.equal(port.closed, true);
  });
});
