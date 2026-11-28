/**
 * Sembrado de la galería desde el catálogo (Fase 4).
 *
 * El orquestador (`createGallerySeeder`) no toca el DOM, ni ONNX, ni axios: todas
 * sus dependencias entran inyectadas, así que aquí se prueba lo que de verdad
 * puede romperse —paginación por cursor, reanudación, ritmo, 429 y cancelación—
 * sin navegador ni servidor.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import { createGallerySeeder, seedStorageKey } from '../../../resources/js/Composables/smartCheckout/learning/seedGallery.js';

/** Almacenamiento en memoria con la misma interfaz que `localStorage`. */
function makeStorage(initial = {}) {
    const data = new Map(Object.entries(initial));
    return {
        data,
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        removeItem: (key) => data.delete(key),
    };
}

/** Reloj y `sleep` controlados: el sembrado no espera de verdad en las pruebas. */
function makeClock() {
    let current = 1000;
    const slept = [];
    return {
        slept,
        now: () => current,
        sleep: async (ms) => {
            slept.push(ms);
            current += ms;
        },
        advance: (ms) => {
            current += ms;
        },
    };
}

function makeProduct(id, images = 1, name = `Producto ${id}`) {
    return {
        product_id: id,
        name,
        images: Array.from({ length: images }, (_, index) => ({ url: `https://ezy.test/${id}-${index}.jpg`, kind: 'general' })),
    };
}

/** `saveSample` que siempre funciona y apunta lo que recibe. */
function makeSaver(result = { ok: true }) {
    const calls = [];
    const save = async (item, image) => {
        calls.push({ productId: item.product_id, url: image.url });
        return typeof result === 'function' ? result(item, image) : result;
    };
    save.calls = calls;
    return save;
}

test('seedStorageKey es estable y distingue terminal y modelo', () => {
    const a = seedStorageKey({ subscriptionId: 7, branchId: 3, modelVersion: 'dinov2-x' });
    assert.equal(a, seedStorageKey({ subscriptionId: 7, branchId: 3, modelVersion: 'dinov2-x' }));
    assert.notEqual(a, seedStorageKey({ subscriptionId: 8, branchId: 3, modelVersion: 'dinov2-x' }));
    assert.notEqual(a, seedStorageKey({ subscriptionId: 7, branchId: 3, modelVersion: 'otro-modelo' }));
    assert.ok(!a.includes('undefined'), 'la clave nunca debe contener "undefined"');

    const empty = seedStorageKey();
    assert.ok(!empty.includes('undefined'));
    assert.ok(!empty.includes('null'));
});

test('siembra todas las imágenes de todas las páginas avanzando el cursor keyset', async () => {
    const clock = makeClock();
    const saver = makeSaver();
    const requests = [];
    const pages = [
        { items: [makeProduct(1), makeProduct(2, 2)], has_more: true, total_pending: 3 },
        { items: [makeProduct(3)], has_more: false, total_pending: 0 },
    ];

    const seeder = createGallerySeeder({
        fetchCandidates: async (params) => {
            requests.push(params);
            return pages[requests.length - 1];
        },
        saveSample: saver,
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.ok, true);
    assert.equal(result.saved, 4);
    assert.equal(result.skipped, 0);
    assert.equal(result.failed, 0);
    assert.equal(result.processed, 3);

    assert.equal(requests.length, 2);
    assert.deepEqual(requests[0], { afterId: null, limit: 50, filter: 'pending' });
    assert.deepEqual(requests[1], { afterId: 2, limit: 50, filter: 'pending' });
    assert.deepEqual(saver.calls.map((call) => call.productId), [1, 2, 2, 3]);
});

test('el lote nunca supera las 50 unidades que admite el endpoint', async () => {
    const clock = makeClock();
    const requests = [];

    const seeder = createGallerySeeder({
        fetchCandidates: async (params) => {
            requests.push(params);
            return { items: [makeProduct(1)], has_more: false };
        },
        saveSample: makeSaver(),
        storage: makeStorage(),
        batchSize: 500,
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    await seeder.run();

    assert.equal(requests[0].limit, 50);
});

test('reanuda desde el cursor guardado y lo limpia al terminar el catálogo', async () => {
    const clock = makeClock();
    const storage = makeStorage();
    const key = seedStorageKey({ subscriptionId: 7, branchId: 3, modelVersion: 'v1' });
    storage.setItem(key, '42');

    const requests = [];
    const seeder = createGallerySeeder({
        fetchCandidates: async (params) => {
            requests.push(params);
            return { items: [makeProduct(43)], has_more: false };
        },
        saveSample: makeSaver(),
        storage,
        context: { subscriptionId: 7, branchId: 3, modelVersion: 'v1' },
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    assert.equal(seeder.resumeCursor(), 42);

    const result = await seeder.run();

    assert.equal(result.ok, true);
    assert.equal(requests[0].afterId, 42, 'la primera página arranca en el cursor guardado');
    assert.equal(storage.getItem(key), null, 'al terminar, el siguiente sembrado empieza de cero');
    assert.equal(seeder.resumeCursor(), null);
});

test('resume=false ignora el cursor guardado y empieza por el principio', async () => {
    const clock = makeClock();
    const storage = makeStorage();
    storage.setItem(seedStorageKey({ subscriptionId: 7, branchId: 3, modelVersion: 'v1' }), '42');

    const requests = [];
    const seeder = createGallerySeeder({
        fetchCandidates: async (params) => {
            requests.push(params);
            return { items: [makeProduct(1)], has_more: false };
        },
        saveSample: makeSaver(),
        storage,
        context: { subscriptionId: 7, branchId: 3, modelVersion: 'v1' },
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    await seeder.run({ resume: false });

    assert.equal(requests[0].afterId, null);
});

test('cancelar detiene el lote y conserva el cursor para reanudar', async () => {
    const clock = makeClock();
    const storage = makeStorage();
    const key = seedStorageKey({ subscriptionId: 1, branchId: 1, modelVersion: 'v1' });
    let seeder;

    const seederRef = { current: null };
    const saver = makeSaver(() => {
        // A mitad del segundo producto se pulsa «Detener».
        seederRef.current.cancel();
        return { ok: true };
    });

    seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(1), makeProduct(2), makeProduct(3)], has_more: true }),
        saveSample: saver,
        storage,
        context: { subscriptionId: 1, branchId: 1, modelVersion: 'v1' },
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });
    seederRef.current = seeder;

    const result = await seeder.run();

    assert.equal(result.cancelled, true);
    assert.equal(result.reason, 'cancelled');
    assert.equal(result.saved, 1);
    assert.equal(storage.getItem(key), '1', 'el cursor queda en el último producto terminado');
});

test('un 429 espera lo que pide el servidor y reintenta una sola vez', async () => {
    const clock = makeClock();
    let attempts = 0;

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(9)], has_more: false }),
        saveSample: async () => {
            attempts += 1;
            if (attempts === 1) return { ok: false, retryAfterMs: 5000 };
            return { ok: true };
        },
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
        maxRetryWaitMs: 60000,
    });

    const result = await seeder.run();

    assert.equal(attempts, 2, 'se reintenta exactamente una vez');
    assert.deepEqual(clock.slept, [5000]);
    assert.equal(result.saved, 1);
    assert.equal(result.failed, 0);
});

test('si el reintento también falla, cuenta como error y sigue con el resto', async () => {
    const clock = makeClock();

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(1), makeProduct(2)], has_more: false }),
        saveSample: async (item) => (item.product_id === 1 ? { ok: false, retryAfterMs: 10 } : { ok: true }),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.failed, 1);
    assert.equal(result.saved, 1);
    assert.equal(result.ok, true, 'un producto con error no invalida el lote');
});

test('un saveSample que lanza no rompe el lote', async () => {
    const clock = makeClock();

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(1), makeProduct(2)], has_more: false }),
        saveSample: async (item) => {
            if (item.product_id === 1) throw new Error('worker caído');
            return { ok: true };
        },
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.failed, 1);
    assert.equal(result.saved, 1);
    assert.equal(result.ok, true);
});

test('respeta el ritmo entre muestras para no chocar con el límite de 60 por minuto', async () => {
    const clock = makeClock();

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(1, 3)], has_more: false }),
        saveSample: makeSaver(),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 1100,
    });

    await seeder.run();

    // La primera muestra no espera; las dos siguientes sí.
    assert.deepEqual(clock.slept, [1100, 1100]);
});

test('un cursor que no avanza corta la corrida en vez de entrar en bucle', async () => {
    const clock = makeClock();
    let calls = 0;

    const seeder = createGallerySeeder({
        fetchCandidates: async () => {
            calls += 1;
            // El servidor devuelve siempre la misma página y dice que hay más.
            return { items: [makeProduct(1), makeProduct(2)], has_more: true };
        },
        saveSample: makeSaver(),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    await seeder.run();

    assert.equal(calls, 2, 'se corta en la segunda página idéntica');
});

test('un fallo al leer el catálogo devuelve fetch_failed sin lanzar', async () => {
    const clock = makeClock();

    const seeder = createGallerySeeder({
        fetchCandidates: async () => {
            throw new Error('sin red');
        },
        saveSample: makeSaver(),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.ok, false);
    assert.equal(result.reason, 'fetch_failed');
    assert.equal(result.saved, 0);
});

test('una página vacía con has_more no termina el sembrado: avanza con next_cursor', async () => {
    // Caso real: con filter=pending el servidor descarta en PHP los productos que
    // ya no están pendientes, así que una página puede llegar vacía con
    // has_more = true. El sembrado debe seguir por el resto del catálogo.
    const clock = makeClock();
    const requests = [];
    const pages = [
        { items: [], has_more: true, next_cursor: 40, total_pending: 2 },
        { items: [makeProduct(41), makeProduct(42)], has_more: false, total_pending: 0 },
        { items: [], has_more: false, total_pending: 0 },
    ];

    const seeder = createGallerySeeder({
        fetchCandidates: async (params) => {
            requests.push(params);
            return pages[requests.length - 1] || { items: [], has_more: false };
        },
        saveSample: makeSaver(),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(requests.length, 2);
    assert.equal(requests[1].afterId, 40, 'salta la página vacía con el cursor del servidor');
    assert.equal(result.saved, 2);
    assert.equal(result.ok, true);
});

test('sin productos pendientes termina limpio', async () => {
    const clock = makeClock();

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [], has_more: false, total_pending: 0 }),
        saveSample: makeSaver(),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.ok, true);
    assert.equal(result.processed, 0);
    assert.equal(result.totalPending, 0);
});

test('dos corridas a la vez no se pisan', async () => {
    const clock = makeClock();
    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });

    const seeder = createGallerySeeder({
        fetchCandidates: async () => {
            await gate;
            return { items: [], has_more: false };
        },
        saveSample: makeSaver(),
        storage: makeStorage(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const first = seeder.run();
    const second = await seeder.run();
    release();
    await first;

    assert.equal(second.ok, false);
    assert.equal(second.reason, 'busy');
});

test('un almacenamiento que falla no impide sembrar', async () => {
    const clock = makeClock();
    const storage = {
        getItem: () => {
            throw new Error('almacenamiento bloqueado');
        },
        setItem: () => {
            throw new Error('cuota superada');
        },
        removeItem: () => {
            throw new Error('almacenamiento bloqueado');
        },
    };

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(1)], has_more: false }),
        saveSample: makeSaver(),
        storage,
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.ok, true);
    assert.equal(result.saved, 1);
});

test('sin almacenamiento inyectado el sembrado sigue funcionando', async () => {
    const clock = makeClock();

    const seeder = createGallerySeeder({
        fetchCandidates: async () => ({ items: [makeProduct(1)], has_more: false }),
        saveSample: makeSaver(),
        now: clock.now,
        sleep: clock.sleep,
        minIntervalMs: 0,
    });

    const result = await seeder.run();

    assert.equal(result.saved, 1);
    assert.equal(seeder.resumeCursor(), null);
});

test('rechaza dependencias obligatorias ausentes', () => {
    assert.throws(() => createGallerySeeder({}), /fetchCandidates/);
    assert.throws(() => createGallerySeeder({ fetchCandidates: async () => ({}) }), /saveSample/);
});
