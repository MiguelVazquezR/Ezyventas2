/**
 * `OnnxWebRecognitionEngine`: the real engine, behind the same interface as the
 * mock (`RecognizeEngine` of the spec). It owns the Web Worker, the cameras and
 * the multi-camera fusion; the UI only listens to `detection`/`status`/`error`.
 *
 * The WASM backend runs single-threaded (see inference.worker.js) so the POS keeps
 * its current headers: no COOP/COEP, nothing new that could break printing,
 * payments or third-party scripts.
 */
import { mergeCameraDetections, classifyCandidates } from '../recognition/confidence.js';
import { cropBoxToSquare } from '../learning/cropUtils.js';
import { createCameraSource } from './cameraSource.js';

const DEFAULT_INTERVAL_MS = 1500;
const STABLE_DELAY_MS = 2500;

/**
 * Width the last frame of every camera is kept at (Phase 4).
 *
 * The learning phase needs the pixels of what the camera actually saw, but the
 * `ImageBitmap` of a frame is transferred to the worker (and therefore detached)
 * the moment it is posted, so a copy has to survive on this side. 960 px wide is
 * ~2 MB per camera and far more than the 224x224 crop the embedder uses.
 */
const FRAME_BUFFER_WIDTH = 960;

/** How long a `sample` request may stay unanswered before giving up (ms). */
const SAMPLE_TIMEOUT_MS = 20000;

/**
 * Structured clone cannot serialise Vue reactive proxies ("#<Object> could not be
 * cloned"), so anything sent to the worker goes through these two helpers: plain
 * objects with plain values only.
 */
export function plainThresholds(thresholds) {
    return thresholds ? { ...thresholds } : null;
}

export function plainGallery(gallery) {
    if (!gallery) return null;
    return {
        gallery_version: gallery.gallery_version ?? null,
        model_version: gallery.model_version ?? null,
        dim: gallery.dim ?? 0,
        // Spread copies the values out of the reactive proxy without touching the
        // (potentially multi-megabyte) base64 payload.
        items: Array.isArray(gallery.items) ? gallery.items.map((item) => ({ ...item })) : [],
        vectors: typeof gallery.vectors === 'string' ? gallery.vectors : '',
    };
}

export class OnnxWebRecognitionEngine {
    constructor(options = {}) {
        this.options = options;
        this.worker = null;
        this.cameras = []; // [{ id, label, mode, source }]
        this.status = 'idle';
        this.intervalMs = Number(options.intervalMs) || DEFAULT_INTERVAL_MS;
        this.strategy = options.strategy === 'detector' ? 'detector' : 'diff';
        this.thresholds = options.thresholds || null;
        this.modelBaseUrl = String(options.modelBaseUrl || '/models').replace(/\/$/, '');
        this.gallery = options.gallery || null;
        this.manifest = null;
        this.listeners = { detection: [], status: [], error: [], raw: [] };
        this.timer = null;
        this.busy = false;
        this.lastLatencyMs = 0;
        this.pendingRequest = null;
        this.stableSince = null;
        /** cameraId → { canvas, context, at }: last frame seen, for Phase 4 crops. */
        this.lastFrames = new Map();
    }

    get kind() {
        return 'onnx';
    }

    get isConnected() {
        return Boolean(this.worker);
    }

    /* ------------------------------------------------------------------ events */

    on(event, callback) {
        if (!this.listeners[event]) this.listeners[event] = [];
        this.listeners[event].push(callback);
        return () => this.off(event, callback);
    }

    off(event, callback) {
        this.listeners[event] = (this.listeners[event] || []).filter((cb) => cb !== callback);
    }

    emit(event, payload) {
        (this.listeners[event] || []).forEach((callback) => {
            try {
                callback(payload);
            } catch {
                /* a listener must not break the engine */
            }
        });
    }

    onDetection(callback) {
        return this.on('detection', callback);
    }

    onStatus(callback) {
        return this.on('status', callback);
    }

    onError(callback) {
        return this.on('error', callback);
    }

    setStatus(status, extra = {}) {
        this.status = status;
        this.emit('status', { status, kind: this.kind, ...extra });
    }

    /* ------------------------------------------------------------------- setup */

    /**
     * Loads the manifest of this domain and starts the worker with the models.
     *
     * @param {{galleryVersion?: string}} [opts]
     */
    async init(opts = {}) {
        this.setStatus('loading');
        try {
            const index = await this.fetchManifestIndex();
            this.manifest = index;

            await this.startCameras();
            await this.startWorker();

            // Without hardware the synthetic counter starts calibrated (blank
            // reference) and then shows a few products, so the pipeline can still
            // be exercised end to end.
            void this.bootstrapSimulatedCameras();

            this.setStatus('ready', {
                cameras: this.cameras.map((camera) => ({ id: camera.id, label: camera.label, mode: camera.mode })),
                strategy: this.strategy,
                hasRealCamera: this.hasRealCamera,
                warnings: this.warnings,
                galleryVersion: this.gallery?.gallery_version || opts.galleryVersion || null,
            });
            return this;
        } catch (error) {
            this.setStatus('error');
            this.emit('error', { code: 'init_failed', message: error?.message || 'No se pudo iniciar el motor de reconocimiento.' });
            throw error;
        }
    }

    /**
     * `/models/index.json` describes both models; absence means "not deployed yet".
     * The index lists models by role, so it is normalised into the shape the
     * worker expects (one entry per role).
     */
    async fetchManifestIndex() {
        const response = await fetch(`${this.modelBaseUrl}/index.json`, { credentials: 'same-origin' });
        if (!response.ok) throw new Error(`No hay modelos publicados en ${this.modelBaseUrl} (${response.status}).`);

        const index = await response.json();
        const byRole = Object.fromEntries((index.models || []).map((model) => [model.role, model]));

        if (!byRole.embeddings) throw new Error('El índice de modelos no declara el modelo de embeddings.');

        this.galleryModelVersion = index.gallery_model_version || null;

        return {
            schema: index.schema,
            gallery_model_version: this.galleryModelVersion,
            embedder: {
                file: byRole.embeddings.file,
                model_version: byRole.embeddings.model_version,
                input_name: 'pixel_values',
                embedding_dim: 384,
                short_side: 256,
                input_size: 224,
                normalize: true,
            },
            detector: byRole.detector
                ? {
                    file: byRole.detector.file,
                    model_version: byRole.detector.model_version,
                    input_name: 'pixel_values',
                    input_size: 640,
                    // Exported with `do_normalize=false`: only /255.
                    normalize: false,
                    logits_name: 'logits',
                    boxes_name: 'pred_boxes',
                    score_threshold: 0.3,
                    nms_iou: 0.55,
                }
                : null,
        };
    }

    async startCameras() {
        const configured = Array.isArray(this.options.cameras) && this.options.cameras.length
            ? this.options.cameras
            : [{ id: 'cam-1', label: 'Cámara 1', mode: 'zone' }];

        this.cameras = [];
        this.cameraWarnings = [];

        for (const camera of configured) {
            const source = await createCameraSource({
                kind: camera.kind || this.options.cameraKind || 'auto',
                deviceId: camera.deviceId,
                label: camera.label,
                products: this.options.products,
            });

            // No hardware (or no permission): the simulator keeps the mode usable
            // but the UI must say so instead of pretending there is a camera.
            if (source.fallbackCode) {
                this.cameraWarnings.push({
                    cameraId: camera.id,
                    code: source.fallbackCode,
                    message: source.fallbackReason,
                });
            }

            this.cameras.push({ ...camera, source, simulated: Boolean(source.fallbackCode) || source.constructor?.name === 'SimulatedCameraSource' });
        }
    }

    async bootstrapSimulatedCameras() {
        for (const camera of this.cameras.filter((item) => item.simulated)) {
            try {
                camera.source.paint?.(0);
                const bitmap = await camera.source.grabFrame?.();
                if (bitmap && this.worker) {
                    this.worker.postMessage({ type: 'calibrate', payload: { cameraId: camera.id, bitmap } }, [bitmap]);
                }
                camera.source.paint?.(3);
            } catch {
                /* the simulated counter is best effort */
            }
        }
    }

    get hasRealCamera() {
        return this.cameras.some((camera) => !camera.simulated);
    }

    get warnings() {
        return this.cameraWarnings || [];
    }

    startWorker() {
        return new Promise((resolve, reject) => {
            const worker = new Worker(new URL('./inference.worker.js', import.meta.url), { type: 'module' });
            this.worker = worker;

            const onMessage = (event) => {
                const message = event.data || {};
                if (message.type === 'ready') {
                    this.worker.removeEventListener('message', onMessage);
                    resolve(message);
                    return;
                }
                if (message.type === 'error' && message.code === 'init_failed') {
                    this.worker.removeEventListener('message', onMessage);
                    reject(new Error(message.message));
                    return;
                }
                this.handleWorkerMessage(message);
            };

            worker.addEventListener('message', onMessage);
            worker.addEventListener('error', (event) => {
                this.emit('error', { code: 'worker_crashed', message: event?.message || 'El worker de inferencia se detuvo.' });
            });

            worker.postMessage({
                type: 'init',
                payload: {
                    manifest: this.manifest,
                    strategy: this.strategy,
                    modelBaseUrl: this.modelBaseUrl,
                    gallery: plainGallery(this.gallery),
                    thresholds: plainThresholds(this.thresholds),
                },
            });
        });
    }

    handleWorkerMessage(message) {
        if (message.type === 'detections') {
            this.lastLatencyMs = Number(message.latencyMs) || 0;
            this.busy = false;
            this.resolveAnalysis(this.buildDetections(message.results || []));
            return;
        }

        if (message.type === 'calibrated') {
            this.emit('status', { status: this.status, kind: this.kind, calibratedCamera: message.cameraId });
            return;
        }

        if (message.type === 'gallery-ready') {
            this.emit('status', { status: this.status, kind: this.kind, galleryCount: message.count });
            return;
        }

        if (message.type === 'error') {
            this.busy = false;
            this.emit('error', { code: message.code || 'worker_error', message: message.message || 'Error del worker.' });
        }
    }

    /**
     * Raw worker results → `Detection[]`, applying the multi-camera fusion and the
     * confidence rules (both shared with the mock engine and unit tested).
     */
    buildDetections(results) {
        const flat = [];
        for (const result of results) {
            for (const detection of result.detections || []) {
                flat.push({
                    cameraId: detection.cameraId,
                    box: detection.box,
                    candidates: detection.candidates || [],
                    barcode: detection.barcode,
                });
            }
        }

        const merged = mergeCameraDetections(flat, this.cameras.map((camera) => ({ id: camera.id, mode: camera.mode })), {
            thresholds: this.thresholds,
        });

        return merged.detections.map((detection) => ({
            ...detection,
            status: classifyCandidates(detection.candidates, {
                thresholds: this.thresholds,
                barcode: detection.barcode,
                cameraDiscrepancy: detection.cameraDiscrepancy,
            }),
        }));
    }

    /* ------------------------------------------------------------------ running */

    /** Grabs one frame per camera and sends them to the worker. */
    async analyze() {
        if (!this.worker || this.busy) return [];
        const frames = [];

        for (const camera of this.cameras) {
            const bitmap = await camera.source?.grabFrame?.();
            if (bitmap) {
                // Copy before transferring: `postMessage` detaches the bitmap and
                // the learning phase still needs it to crop a sample.
                this.rememberFrame(camera.id, bitmap);
                frames.push({ cameraId: camera.id, bitmap });
            }
        }

        if (!frames.length) {
            this.busy = false;
            return [];
        }

        this.busy = true;
        const requestId = `${Date.now()}`;
        this.pendingRequest = new Promise((resolve) => {
            this.resolveAnalysis = (detections) => resolve(detections);
        });

        this.worker.postMessage(
            {
                type: 'analyze',
                payload: {
                    requestId,
                    frames,
                    options: { thresholds: plainThresholds(this.thresholds) },
                },
            },
            frames.map((frame) => frame.bitmap),
        );

        return this.pendingRequest;
    }

    start() {
        if (this.timer) return this;
        this.stableSince = null;
        this.timer = setInterval(async () => {
            if (this.status === 'paused') return;
            try {
                const detections = await this.analyze();
                // Only surface a result when the counter has been still for a moment.
                if (detections.length) {
                    this.stableSince = this.stableSince || Date.now();
                    if (Date.now() - this.stableSince >= STABLE_DELAY_MS) {
                        this.emit('detection', detections);
                    }
                } else {
                    this.stableSince = null;
                    this.emit('detection', []);
                }
            } catch (error) {
                this.emit('error', { code: 'analysis_failed', message: error?.message || 'Falló el análisis de imagen.' });
            }
        }, this.intervalMs);

        void this.analyze().then((detections) => detections.length && this.emit('detection', detections));
        return this;
    }

    pause() {
        this.setStatus('paused');
        return this;
    }

    resume() {
        this.setStatus('ready');
        return this;
    }

    stop() {
        if (this.timer) clearInterval(this.timer);
        this.timer = null;
        this.stableSince = null;
        return this;
    }

    /** Calibrates the empty counter of one camera (strategy `diff`). */
    async calibrate(cameraId) {
        const camera = this.cameras.find((item) => item.id === cameraId) || this.cameras[0];
        if (!camera || !this.worker) return false;
        const bitmap = await camera.source?.grabFrame?.();
        if (!bitmap) return false;
        this.worker.postMessage({ type: 'calibrate', payload: { cameraId: camera.id, bitmap } }, [bitmap]);
        return true;
    }

    /** Replaces the tenant gallery after an incremental sync. */
    setGallery(galleryPayload) {
        this.gallery = galleryPayload;
        this.worker?.postMessage({ type: 'gallery', payload: plainGallery(galleryPayload) });
    }

    setThresholds(thresholds) {
        this.thresholds = thresholds || null;
    }

    setStrategy(strategy) {
        this.strategy = strategy === 'detector' ? 'detector' : 'diff';
    }

    /** Vector of a crop for the learning phase (Fase 4). */
    async addSample(productId, bitmap, source = 'barcode_scan') {
        if (!this.worker || !bitmap) return null;

        return new Promise((resolve) => {
            const finish = (value) => {
                clearTimeout(timer);
                this.worker?.removeEventListener?.('message', onMessage);
                resolve(value);
            };

            // A worker that dies must not leave the caller waiting forever: the
            // cashier's sale keeps going and the sample is simply not stored.
            const timer = setTimeout(() => finish(null), SAMPLE_TIMEOUT_MS);

            const onMessage = (event) => {
                const message = event.data || {};
                if (message.type === 'sample' && message.productId === productId) {
                    finish(message);
                }
            };

            this.worker.addEventListener('message', onMessage);
            this.worker.postMessage({ type: 'sample', payload: { productId, bitmap, source } }, [bitmap]);
        });
    }

    /* --------------------------------------------------------- frame buffer */
    /* Fase 4: the cashier's decisions must leave a sample of what the camera  */
    /* saw, and detections carry only a normalised box, never pixels.          */

    /**
     * Keeps a downscaled copy of the last frame of a camera.
     *
     * Best effort by design: no canvas (tests, Node), a broken bitmap or a
     * tainted surface must never interrupt the analysis loop.
     */
    rememberFrame(cameraId, bitmap) {
        if (!bitmap || typeof document === 'undefined') return;

        try {
            const width = Number(bitmap.width) || 0;
            const height = Number(bitmap.height) || 0;
            if (!width || !height) return;

            const scale = Math.min(1, FRAME_BUFFER_WIDTH / width);
            const targetWidth = Math.max(1, Math.round(width * scale));
            const targetHeight = Math.max(1, Math.round(height * scale));

            let entry = this.lastFrames.get(cameraId);
            if (!entry) {
                const canvas = document.createElement('canvas');
                entry = { canvas, context: canvas.getContext('2d') };
                this.lastFrames.set(cameraId, entry);
            }

            if (entry.canvas.width !== targetWidth || entry.canvas.height !== targetHeight) {
                entry.canvas.width = targetWidth;
                entry.canvas.height = targetHeight;
            }

            entry.context.drawImage(bitmap, 0, 0, targetWidth, targetHeight);
            entry.at = Date.now();
        } catch {
            /* the buffer is an optimisation of the learning phase, not a requirement */
        }
    }

    /** Whether there is a usable frame of that camera (or of any camera if null). */
    hasFrame(cameraId = null) {
        if (cameraId !== null) return this.lastFrames.has(cameraId);
        return this.lastFrames.size > 0;
    }

    /**
     * Size and age of the buffered frame of a camera, or null when there is none.
     * The learning phase refuses to crop a frame that is already stale.
     *
     * @returns {{width: number, height: number, ageMs: number}|null}
     */
    frameInfo(cameraId = null) {
        const entry = cameraId !== null && this.lastFrames.has(cameraId)
            ? this.lastFrames.get(cameraId)
            : this.lastFrames.values().next().value;

        if (!entry?.canvas) return null;

        return {
            width: entry.canvas.width,
            height: entry.canvas.height,
            ageMs: entry.at ? Date.now() - entry.at : Number.MAX_SAFE_INTEGER,
        };
    }

    /**
     * `ImageBitmap` of the crop of one detection, ready for `addSample()`.
     *
     * @param {string|null} cameraId
     * @param {number[]} box normalised `[x, y, w, h]` of the detection
     * @param {{margin?: number}} [options]
     * @returns {Promise<ImageBitmap|null>}
     */
    async captureCrop(cameraId, box, options = {}) {
        if (typeof createImageBitmap !== 'function') return null;

        const entry = cameraId !== null && this.lastFrames.has(cameraId)
            ? this.lastFrames.get(cameraId)
            : this.lastFrames.values().next().value;

        if (!entry?.canvas) return null;

        const rect = cropBoxToSquare(box, entry.canvas.width, entry.canvas.height, options);
        if (!rect) return null;

        try {
            return await createImageBitmap(entry.canvas, rect.sx, rect.sy, rect.sw, rect.sh);
        } catch {
            // Safari < 15 and some embedded webviews reject the 5-argument form.
            try {
                const target = document.createElement('canvas');
                target.width = rect.sw;
                target.height = rect.sh;
                target.getContext('2d').drawImage(
                    entry.canvas,
                    rect.sx, rect.sy, rect.sw, rect.sh,
                    0, 0, rect.sw, rect.sh,
                );
                return await createImageBitmap(target);
            } catch {
                return null;
            }
        }
    }

    /** Convenience wrapper over a `Detection` of this engine. */
    async captureDetectionCrop(detection, options = {}) {
        if (!detection) return null;
        return this.captureCrop(detection.cameraId ?? null, detection.box, options);
    }

    /** Preview stream of one camera for the UI (`<video>` element). */
    attachPreview(cameraId, element) {
        const camera = this.cameras.find((item) => item.id === cameraId) || this.cameras[0];
        camera?.source?.attachTo?.(element);
        return Boolean(camera);
    }

    get cameraList() {
        return this.cameras.map((camera) => ({ id: camera.id, label: camera.label, mode: camera.mode }));
    }

    async dispose() {
        this.stop();
        this.lastFrames.clear();
        this.cameras.forEach((camera) => {
            try {
                camera.source?.stop?.();
            } catch {
                /* already stopped */
            }
        });
        this.cameras = [];

        if (this.worker) {
            try {
                this.worker.postMessage({ type: 'dispose' });
                this.worker.terminate();
            } catch {
                /* already terminated */
            }
        }
        this.worker = null;
        this.setStatus('idle');
    }
}
