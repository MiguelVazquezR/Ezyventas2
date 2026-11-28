/**
 * Inference worker ("todo en Web Worker": the UI never blocks).
 *
 * Responsibilities:
 *  - load the ONNX models from this domain (`/models/...`, never from Hugging Face),
 *  - propose objects (strategy `diff` = empty-counter difference, or `detector` = RT-DETR),
 *  - read barcodes (native BarcodeDetector or zxing-wasm),
 *  - embed every proposed crop with DINOv2 and search the tenant gallery in memory.
 *
 * Threading: WASM runs single-threaded on purpose. Threads need SharedArrayBuffer
 * and therefore COOP/COEP headers on the POS route, which the spec only allows
 * after proving they do not break printing, payments or third-party scripts.
 * Single-threaded keeps the POS untouched; latency is measured in the phase report.
 */
import * as ort from 'onnxruntime-web/wasm';
import ortWasmUrl from 'onnxruntime-web/ort-wasm-simd-threaded.wasm?url';
import { captureEmptyCounterReference, proposeFromDifference } from './diffProposer.js';
import { postprocessDetections } from './detectorPostprocess.js';
import { grayscaleSignature, createStabilityTracker } from './frameStability.js';
import { decodeGallery, searchGallery } from './gallery.js';
import { normalizeL2, rgbaToNchwFloat32, float32ToBase64 } from './tensorUtils.js';
import { BarcodeReader } from './barcode.js';

ort.env.wasm.wasmPaths = { wasm: ortWasmUrl };
ort.env.wasm.numThreads = 1;
ort.env.wasm.proxy = false;
ort.env.logLevel = 'error';

const DETECTOR_INPUT = 640;
const EMBEDDER_INPUT = 224;
/** DINOv2 resizes the short side to 256 before the 224 centre crop (manifest). */
const EMBEDDER_SHORT_SIDE = 256;
/** The detector was exported with `do_normalize=false`: raw /255 only. */
const NO_NORMALIZATION = { mean: [0, 0, 0], std: [1, 1, 1] };
const DEFAULT_THRESHOLDS = { green_min: 0.85, green_margin: 0.08, yellow_min: 0.65 };

/** Shared worker state. */
const state = {
    detector: null,
    embedder: null,
    manifest: null,
    gallery: { dim: 0, items: [] },
    galleryVersion: null,
    strategy: 'diff',
    references: new Map(), // cameraId → empty counter reference
    stability: new Map(), // cameraId → tracker
    barcode: null,
    backend: 'unknown',
    modelsReady: false,
    initError: null,
    stats: { analyses: 0, lastLatencyMs: 0 },
};

/** Downscales an ImageBitmap into an RGBA buffer of the given size. */
function bitmapToImageData(bitmap, width, height) {
    const canvas = new OffscreenCanvas(width, height);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(bitmap, 0, 0, width, height);
    return ctx.getImageData(0, 0, width, height);
}

/** Crops a normalised box and returns ImageData at `size` (direct resize). */
function cropToImageData(bitmap, box, size) {
    const [x, y, w, h] = box;
    const sourceWidth = bitmap.width;
    const sourceHeight = bitmap.height;
    const sx = Math.max(0, Math.round(x * sourceWidth));
    const sy = Math.max(0, Math.round(y * sourceHeight));
    const sw = Math.max(1, Math.round(w * sourceWidth));
    const sh = Math.max(1, Math.round(h * sourceHeight));

    const canvas = new OffscreenCanvas(size, size);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(bitmap, sx, sy, sw, sh, 0, 0, size, size);
    return ctx.getImageData(0, 0, size, size);
}

/**
 * DINOv2 pre-processing exactly as documented in the manifest: short side to 256
 * keeping the aspect ratio, bicubic, then a centred 224x224 crop.
 */
function cropToModelInput(bitmap, box) {
    const [x, y, w, h] = box;
    const sourceWidth = bitmap.width;
    const sourceHeight = bitmap.height;
    const sx = Math.max(0, Math.round(x * sourceWidth));
    const sy = Math.max(0, Math.round(y * sourceHeight));
    const sw = Math.max(1, Math.round(w * sourceWidth));
    const sh = Math.max(1, Math.round(h * sourceHeight));

    const scale = EMBEDDER_SHORT_SIDE / Math.min(sw, sh);
    const resizedWidth = Math.max(EMBEDDER_INPUT, Math.round(sw * scale));
    const resizedHeight = Math.max(EMBEDDER_INPUT, Math.round(sh * scale));

    const stage = new OffscreenCanvas(resizedWidth, resizedHeight);
    const stageCtx = stage.getContext('2d', { willReadFrequently: true });
    stageCtx.imageSmoothingQuality = 'high';
    stageCtx.drawImage(bitmap, sx, sy, sw, sh, 0, 0, resizedWidth, resizedHeight);

    const cropX = Math.floor((resizedWidth - EMBEDDER_INPUT) / 2);
    const cropY = Math.floor((resizedHeight - EMBEDDER_INPUT) / 2);

    const canvas = new OffscreenCanvas(EMBEDDER_INPUT, EMBEDDER_INPUT);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(stage, cropX, cropY, EMBEDDER_INPUT, EMBEDDER_INPUT, 0, 0, EMBEDDER_INPUT, EMBEDDER_INPUT);
    return ctx.getImageData(0, 0, EMBEDDER_INPUT, EMBEDDER_INPUT);
}

async function loadSession(url) {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(`No se pudo descargar el modelo (${response.status}): ${url}`);
    const bytes = await response.arrayBuffer();
    return ort.InferenceSession.create(new Uint8Array(bytes), {
        executionProviders: ['wasm'],
        graphOptimizationLevel: 'all',
    });
}

/** DINOv2 embedding of one crop, L2 normalised. */
async function embedCrop(bitmap, box) {
    const imageData = cropToModelInput(bitmap, box);
    const tensorData = rgbaToNchwFloat32(imageData.data, EMBEDDER_INPUT, EMBEDDER_INPUT);
    const tensor = new ort.Tensor('float32', tensorData, [1, 3, EMBEDDER_INPUT, EMBEDDER_INPUT]);
    const output = await state.embedder.run({ [state.manifest?.embedder?.input_name || 'pixel_values']: tensor });
    const key = Object.keys(output)[0];
    const dim = state.manifest?.embedder?.embedding_dim || 384;
    return normalizeL2(output[key].data.slice(0, dim));
}

/** Object proposals of one frame, for the configured strategy. */
async function proposeObjects(bitmap, cameraId) {
    if (state.strategy === 'detector' && state.detector) {
        const imageData = bitmapToImageData(bitmap, DETECTOR_INPUT, DETECTOR_INPUT);
        // RT-DETR was exported without normalisation (see modelos.md §6).
        const tensorData = rgbaToNchwFloat32(imageData.data, DETECTOR_INPUT, DETECTOR_INPUT, NO_NORMALIZATION);
        const tensor = new ort.Tensor('float32', tensorData, [1, 3, DETECTOR_INPUT, DETECTOR_INPUT]);
        const names = state.manifest?.detector?.input_name || 'pixel_values';
        const output = await state.detector.run({ [names]: tensor });

        const logits = output[state.manifest?.detector?.logits_name || 'logits'];
        const boxes = output[state.manifest?.detector?.boxes_name || 'pred_boxes'];
        if (!logits || !boxes) return [];

        return postprocessDetections({
            logits: logits.data,
            boxes: boxes.data,
            numClasses: logits.dims?.[2] || 80,
            scoreThreshold: state.manifest?.detector?.score_threshold ?? 0.3,
            iouThreshold: state.manifest?.detector?.nms_iou ?? 0.55,
            maxBoxes: 10,
        });
    }

    // Strategy `diff`: compare against the calibrated empty counter.
    const reference = state.references.get(cameraId);
    if (!reference) return [];
    const imageData = bitmapToImageData(bitmap, DETECTOR_INPUT, DETECTOR_INPUT).data;
    const signature = grayscaleSignature(imageData, DETECTOR_INPUT, DETECTOR_INPUT, {
        gridWidth: reference.gridWidth,
        gridHeight: reference.gridHeight,
    });
    return proposeFromDifference({ reference, current: signature, threshold: 0.08, maxBoxes: 10 });
}

async function analyseFrame(bitmap, cameraId, options) {
    const thresholds = { ...DEFAULT_THRESHOLDS, ...(options?.thresholds || {}) };

    // 1. Stability gate: never analyse a moving frame.
    const tracker = state.stability.get(cameraId) || createStabilityTracker({ threshold: 0.02, samples: 3 });
    state.stability.set(cameraId, tracker);
    const signature = grayscaleSignature(
        bitmapToImageData(bitmap, 320, 180).data,
        320,
        180,
    );
    const stability = tracker.push(signature);
    if (!stability.stable) return { cameraId, detections: [], stable: false };

    // 2. Barcode of the whole frame (cheap: it is the strongest signal when present).
    let barcode = null;
    if (state.barcode) {
        const frameData = bitmapToImageData(bitmap, 640, 360);
        const codes = await state.barcode.detect(frameData);
        barcode = codes[0]?.value || null;
    }

    // 3. Proposals + embedding + gallery search.
    const proposals = await proposeObjects(bitmap, cameraId);
    const detections = [];

    for (const proposal of proposals) {
        let candidates = [];
        if (state.embedder) {
            const vector = await embedCrop(bitmap, proposal.box);
            candidates = searchGallery(state.gallery, vector, { topK: 3 });
        }
        detections.push({
            cameraId,
            box: proposal.box,
            candidates,
            barcode: barcode || undefined,
            // The status is decided by the UI with the shared confidence rules,
            // so the thresholds stay in one place (confidence.js).
            status: 'yellow',
            localScore: proposal.score,
        });
    }

    if (!detections.length && barcode) {
        detections.push({ cameraId, box: [0, 0, 1, 1], candidates: [], barcode, status: 'green' });
    }

    void thresholds;
    return { cameraId, detections, stable: true };
}

async function handleInit(payload) {
    try {
        state.manifest = payload.manifest;
        state.strategy = payload.strategy === 'detector' ? 'detector' : 'diff';
        state.gallery = payload.gallery ? decodeGallery(payload.gallery) : { dim: 0, items: [] };
        state.galleryVersion = payload.gallery?.gallery_version || null;

        const base = String(payload.modelBaseUrl || '/models').replace(/\/$/, '');
        const embedderFile = payload.manifest?.embedder?.file;
        const detectorFile = payload.manifest?.detector?.file;

        state.embedder = embedderFile ? await loadSession(`${base}/${embedderFile}`) : null;
        state.detector = state.strategy === 'detector' && detectorFile ? await loadSession(`${base}/${detectorFile}`) : null;

        state.barcode = new BarcodeReader();
        state.backend = await state.barcode.init();
        state.modelsReady = Boolean(state.embedder);
        state.initError = null;

        return {
            type: 'ready',
            modelsReady: state.modelsReady,
            strategy: state.strategy,
            barcodeBackend: state.backend,
            galleryVersion: state.galleryVersion,
            galleryCount: state.gallery.items.length,
            backend: 'wasm',
            threads: ort.env.wasm.numThreads,
        };
    } catch (error) {
        state.initError = error?.message || 'No se pudieron cargar los modelos.';
        return { type: 'error', code: 'init_failed', message: state.initError };
    }
}

self.onmessage = async (event) => {
    const message = event.data || {};

    try {
        switch (message.type) {
            case 'init': {
                const reply = await handleInit(message.payload || {});
                self.postMessage(reply);
                break;
            }

            case 'gallery': {
                state.gallery = decodeGallery(message.payload || {});
                state.galleryVersion = message.payload?.gallery_version || null;
                self.postMessage({ type: 'gallery-ready', count: state.gallery.items.length, galleryVersion: state.galleryVersion });
                break;
            }

            case 'calibrate': {
                const { cameraId, bitmap } = message.payload || {};
                const imageData = bitmapToImageData(bitmap, DETECTOR_INPUT, DETECTOR_INPUT).data;
                state.references.set(cameraId, captureEmptyCounterReference(imageData, DETECTOR_INPUT, DETECTOR_INPUT));
                state.stability.delete(cameraId);
                bitmap?.close?.();
                self.postMessage({ type: 'calibrated', cameraId });
                break;
            }

            case 'analyze': {
                const started = Date.now();
                const frames = message.payload?.frames || [];
                const options = message.payload?.options || {};
                const results = [];

                for (const frame of frames) {
                    const result = await analyseFrame(frame.bitmap, frame.cameraId, options);
                    frame.bitmap?.close?.();
                    results.push(result);
                }

                state.stats.analyses += 1;
                state.stats.lastLatencyMs = Date.now() - started;

                self.postMessage({
                    type: 'detections',
                    requestId: message.payload?.requestId || null,
                    results,
                    latencyMs: state.stats.lastLatencyMs,
                });
                break;
            }

            case 'sample': {
                const { productId, bitmap, source } = message.payload || {};
                const vector = await embedCrop(bitmap, [0, 0, 1, 1]);
                bitmap?.close?.();
                self.postMessage({
                    type: 'sample',
                    productId,
                    source,
                    dim: vector.length,
                    vector: float32ToBase64(vector),
                });
                break;
            }

            case 'dispose': {
                state.references.clear();
                state.stability.clear();
                await state.detector?.release?.();
                await state.embedder?.release?.();
                state.detector = null;
                state.embedder = null;
                self.postMessage({ type: 'disposed' });
                break;
            }

            default:
                self.postMessage({ type: 'error', code: 'unknown_message', message: `Mensaje no soportado: ${message.type}` });
        }
    } catch (error) {
        self.postMessage({ type: 'error', code: 'worker_error', message: error?.message || 'Error en el worker de inferencia.' });
    }
};
