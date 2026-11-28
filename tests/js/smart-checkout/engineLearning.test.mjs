/**
 * Caminos de error de la parte de aprendizaje del motor real (Fase 5).
 *
 * El motor ONNX es el único que aporta píxeles, y es justo la pieza que se cae de
 * formas aburridas: un worker que muere sin contestar, un fotograma que ya no está,
 * un navegador sin `createImageBitmap` o un documento que no existe (Node, SSR).
 * Nada de eso puede dejar la caja esperando ni romper el POS, así que aquí se
 * fija el contrato: **siempre se resuelve**, con el valor o con `null`.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import { OnnxWebRecognitionEngine } from '../../../resources/js/Composables/smartCheckout/inference/OnnxWebRecognitionEngine.js';

/** Worker de mentira: registra lo enviado y permite simular respuestas. */
function makeWorker() {
    const listeners = new Set();
    const posted = [];

    return {
        listeners,
        posted,
        addEventListener(type, callback) {
            if (type === 'message') listeners.add(callback);
        },
        removeEventListener(type, callback) {
            if (type === 'message') listeners.delete(callback);
        },
        postMessage(message, transfer) {
            posted.push({ message, transfer });
        },
        /** Emula un mensaje del worker hacia el hilo principal. */
        emit(data) {
            for (const callback of Array.from(listeners)) callback({ data });
        },
    };
}

function makeBitmap(width = 224, height = 224) {
    return { width, height, close() {} };
}

test('addSample envía el recorte al worker y resuelve con el vector', async () => {
    const engine = new OnnxWebRecognitionEngine();
    const worker = makeWorker();
    engine.worker = worker;

    const bitmap = makeBitmap();
    const promise = engine.addSample(5, bitmap, 'barcode_scan');

    assert.equal(worker.posted.length, 1);
    assert.equal(worker.posted[0].message.type, 'sample');
    assert.deepEqual(worker.posted[0].message.payload, { productId: 5, bitmap, source: 'barcode_scan' });
    // El bitmap se transfiere (no se copia): es lo que después lo deja detached.
    assert.deepEqual(worker.posted[0].transfer, [bitmap]);

    worker.emit({ type: 'sample', productId: 5, dim: 384, vector: 'AAAAAAAA' });

    const result = await promise;
    assert.equal(result.dim, 384);
    assert.equal(result.vector, 'AAAAAAAA');
    assert.equal(worker.listeners.size, 0, 'el listener se quita al resolver');
});

test('addSample ignora los mensajes de otra petición de muestra', async () => {
    const engine = new OnnxWebRecognitionEngine();
    const worker = makeWorker();
    engine.worker = worker;

    const promise = engine.addSample(7, makeBitmap(), 'quick_register');

    // Otra muestra en paralelo del mismo worker: no debe resolver la primera.
    worker.emit({ type: 'sample', productId: 99, dim: 384, vector: 'OTRO' });
    worker.emit({ type: 'detections', requestId: 'x' });
    worker.emit({ type: 'sample', productId: 7, dim: 384, vector: 'CORRECTO' });

    const result = await promise;
    assert.equal(result.vector, 'CORRECTO');
});

test('addSample se rinde si el worker no contesta (no deja la promesa colgada)', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });

    const engine = new OnnxWebRecognitionEngine();
    const worker = makeWorker();
    engine.worker = worker;

    const promise = engine.addSample(5, makeBitmap(), 'cashier_correction');

    // El worker muere en silencio: a los 20 s la promesa se resuelve en null.
    t.mock.timers.tick(20000);

    assert.equal(await promise, null);
    assert.equal(worker.listeners.size, 0, 'no se queda un listener colgado');
});

test('addSample devuelve null sin worker o sin bitmap', async () => {
    const engine = new OnnxWebRecognitionEngine();

    assert.equal(await engine.addSample(5, makeBitmap(), 'barcode_scan'), null, 'sin worker');

    engine.worker = makeWorker();
    assert.equal(await engine.addSample(5, null, 'barcode_scan'), null, 'sin píxeles');
    assert.equal(engine.worker.posted.length, 0, 'no se envía nada al worker');
});

test('sin fotograma bufferizado no hay nada que recortar', async () => {
    const engine = new OnnxWebRecognitionEngine();

    assert.equal(engine.hasFrame(), false);
    assert.equal(engine.frameInfo(), null);
    assert.equal(await engine.captureCrop('cam-1', [0.1, 0.1, 0.2, 0.2]), null);
    assert.equal(await engine.captureDetectionCrop(null), null);
});

test('rememberFrame no rompe fuera del navegador (Node, SSR, tests)', () => {
    const engine = new OnnxWebRecognitionEngine();

    // En Node no hay `document`: la copia del fotograma es una optimización, no un
    // requisito, así que se ignora en silencio.
    engine.rememberFrame('cam-1', { width: 1280, height: 720 });
    engine.rememberFrame('cam-1', null);
    engine.rememberFrame('cam-1', { width: 0, height: 0 });

    assert.equal(engine.hasFrame('cam-1'), false);
    assert.equal(engine.lastFrames.size, 0);
});

test('frameInfo informa tamaño y antigüedad del fotograma de la cámara pedida', () => {
    const engine = new OnnxWebRecognitionEngine();
    const old = Date.now() - 30000;

    engine.lastFrames.set('cam-1', { canvas: { width: 960, height: 540 }, at: Date.now() });
    engine.lastFrames.set('cam-2', { canvas: { width: 640, height: 360 }, at: old });

    const first = engine.frameInfo('cam-1');
    assert.equal(first.width, 960);
    assert.equal(first.height, 540);
    assert.ok(first.ageMs >= 0 && first.ageMs < 5000, 'un fotograma recién copiado está fresco');

    // Un fotograma viejo se reporta como tal: es lo que la sesión usa para negarse
    // a guardar una muestra de un producto que ya se fue del mostrador.
    const second = engine.frameInfo('cam-2');
    assert.ok(second.ageMs >= 29000, 'la antigüedad se calcula de verdad');

    // Cámara desconocida: se usa el primer fotograma disponible en vez de fallar.
    const fallback = engine.frameInfo('cam-inexistente');
    assert.ok(fallback !== null);
    assert.equal(fallback.width, 960);
});

test('captureCrop devuelve null si el navegador no sabe recortar sin canvas', async () => {
    const engine = new OnnxWebRecognitionEngine();
    engine.lastFrames.set('cam-1', { canvas: { width: 960, height: 540 }, at: Date.now() });

    if (typeof createImageBitmap === 'function') {
        // En un navegador real este camino sí produce un ImageBitmap; en Node no
        // hay `createImageBitmap`, que es exactamente el caso que se prueba abajo.
        return;
    }

    assert.equal(await engine.captureCrop('cam-1', [0.2, 0.2, 0.3, 0.3]), null);
});

test('dispose olvida los fotogramas bufferizados', async () => {
    const engine = new OnnxWebRecognitionEngine();
    engine.lastFrames.set('cam-1', { canvas: { width: 960, height: 540 }, at: Date.now() });

    await engine.dispose();

    assert.equal(engine.lastFrames.size, 0);
});
