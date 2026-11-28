/**
 * Camera abstraction (`CameraSource` in the spec).
 *
 * Real cameras go through `getUserMedia`; when there is no hardware (or the
 * browser denies the permission) a `SimulatedCameraSource` produces synthetic
 * frames so the flow, the worker and the UI can still be exercised. The number of
 * cameras is configuration, never a constant: the engine receives a list of
 * frames per analysis.
 */

export const DEFAULT_RESOLUTION = { width: 1280, height: 720 };

export function isCameraSupported() {
    return typeof navigator !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia);
}

export function isSecureContextForCamera() {
    if (typeof window === 'undefined') return false;
    return window.isSecureContext || ['localhost', '127.0.0.1'].includes(window.location?.hostname || '');
}

export class CameraSource {
    constructor() {
        this.stream = null;
        this.video = null;
        this.deviceId = null;
        this.label = null;
        this.error = null;
    }

    static isSupported() {
        return isCameraSupported();
    }

    /**
     * Cameras of the machine. Labels are only readable after the permission has
     * been granted at least once, so the first call may return empty names.
     *
     * @returns {Promise<Array<{deviceId: string, label: string}>>}
     */
    static async listDevices() {
        if (!isCameraSupported() || !navigator.mediaDevices.enumerateDevices) return [];
        const devices = await navigator.mediaDevices.enumerateDevices();
        return devices
            .filter((device) => device.kind === 'videoinput')
            .map((device, index) => ({
                deviceId: device.deviceId,
                label: device.label || `Cámara ${index + 1}`,
            }));
    }

    /**
     * Asks for the permission once and returns the devices with real labels. Used
     * by the "Elegir cámara" action, which must run inside a user gesture.
     *
     * @returns {Promise<Array<{deviceId: string, label: string}>>}
     */
    static async requestPermissionAndList() {
        if (!isCameraSupported()) return [];
        const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        stream.getTracks().forEach((track) => track.stop());
        return this.listDevices();
    }

    /**
     * @param {{deviceId?: string, width?: number, height?: number, label?: string}} [options]
     */
    async start(options = {}) {
        if (!isCameraSupported()) {
            throw new Error('Este navegador no permite acceder a la cámara.');
        }
        if (!isSecureContextForCamera()) {
            throw new Error('La cámara requiere HTTPS o localhost.');
        }

        const width = Number(options.width) || DEFAULT_RESOLUTION.width;
        const height = Number(options.height) || DEFAULT_RESOLUTION.height;

        this.stop();

        this.stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                deviceId: options.deviceId ? { exact: options.deviceId } : undefined,
                width: { ideal: width },
                height: { ideal: height },
            },
        });

        this.deviceId = options.deviceId || this.stream.getVideoTracks()[0]?.getSettings()?.deviceId || null;
        this.label = options.label || this.stream.getVideoTracks()[0]?.label || 'Cámara';

        this.video = document.createElement('video');
        this.video.playsInline = true;
        this.video.muted = true;
        this.video.srcObject = this.stream;
        await this.video.play().catch(() => {});

        return this;
    }

    /** True when the video element already has real frames. */
    isReady() {
        return Boolean(this.video && this.video.videoWidth > 0);
    }

    get resolution() {
        return {
            width: this.video?.videoWidth || 0,
            height: this.video?.videoHeight || 0,
        };
    }

    /**
     * Grabs one frame as an `ImageBitmap` (transferable to the worker).
     *
     * @returns {Promise<ImageBitmap|null>}
     */
    async grabFrame() {
        if (!this.isReady()) return null;
        try {
            return await createImageBitmap(this.video);
        } catch {
            return null;
        }
    }

    /** The element the UI should render as the preview. */
    attachTo(element) {
        if (!element || !this.stream) return;
        element.srcObject = this.stream;
        element.play?.().catch(() => {});
    }

    stop() {
        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop());
        }
        this.stream = null;
        this.deviceId = null;
        return this;
    }
}

/**
 * Synthetic camera: draws a counter scene on a canvas and returns frames, with a
 * blank-counter reference available for calibration. Only used when no hardware
 * is present (development, automated checks, demos).
 */
export class SimulatedCameraSource {
    constructor(options = {}) {
        this.width = Number(options.width) || DEFAULT_RESOLUTION.width;
        this.height = Number(options.height) || DEFAULT_RESOLUTION.height;
        this.label = options.label || 'Cámara simulada';
        this.deviceId = options.deviceId || 'simulated';
        this.canvas = null;
        this.context = null;
        this.frameCount = 0;
        this.products = options.products || [];
    }

    async start() {
        this.canvas = document.createElement('canvas');
        this.canvas.width = this.width;
        this.canvas.height = this.height;
        this.context = this.canvas.getContext('2d', { willReadFrequently: true });
        this.paint(0);
        return this;
    }

    isReady() {
        return Boolean(this.context);
    }

    get resolution() {
        return { width: this.width, height: this.height };
    }

    /** `objectCount` products on the counter (0 = blank counter for calibration). */
    paint(objectCount = 0) {
        const ctx = this.context;
        if (!ctx) return;

        // Counter background with a subtle gradient: stable between frames.
        const gradient = ctx.createLinearGradient(0, 0, this.width, this.height);
        gradient.addColorStop(0, '#2b2b2b');
        gradient.addColorStop(1, '#3a3a3a');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, this.width, this.height);

        const palette = ['#C26B2D', '#2F7D4F', '#B3322E', '#3B5BA9', '#C7A008', '#7A3E9D'];
        for (let index = 0; index < objectCount; index += 1) {
            const width = this.width * (0.1 + (index % 3) * 0.03);
            const height = this.height * (0.16 + (index % 2) * 0.05);
            const x = this.width * (0.12 + index * 0.17) - width / 2;
            const y = this.height * 0.55 + (index % 2) * 40;
            ctx.fillStyle = palette[index % palette.length];
            ctx.fillRect(Math.max(8, x), y, width, height);
            ctx.fillStyle = 'rgba(255,255,255,0.25)';
            ctx.fillRect(Math.max(8, x), y, width, 8);
        }

        this.frameCount += 1;
    }

    async grabFrame() {
        if (!this.canvas) return null;
        try {
            return await createImageBitmap(this.canvas);
        } catch {
            return null;
        }
    }

    attachTo(element) {
        if (!element || !this.canvas) return;
        element.srcObject = null;
        const ctx = element.getContext?.('2d');
        if (ctx) ctx.drawImage(this.canvas, 0, 0, element.width, element.height);
    }

    stop() {
        this.context = null;
        this.canvas = null;
        return this;
    }
}

/**
 * Turns a `getUserMedia` failure into something a cashier can understand.
 *
 * @param {Error & {name?: string}} error
 * @returns {{code: string, message: string}}
 */
export function describeCameraError(error) {
    const name = error?.name || '';

    if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
        return {
            code: 'no_camera',
            message: 'No hay ninguna cámara conectada en esta computadora.',
        };
    }

    if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
        return {
            code: 'permission_denied',
            message: 'El navegador bloqueó el acceso a la cámara. Habilítalo desde el candado de la barra de direcciones.',
        };
    }

    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return {
            code: 'camera_busy',
            message: 'La cámara está siendo usada por otro programa. Ciérralo y vuelve a intentarlo.',
        };
    }

    return {
        code: 'camera_error',
        message: error?.message || 'No se pudo abrir la cámara.',
    };
}

/**
 * Builds the camera a terminal should use: real when available and allowed,
 * simulated otherwise (with the reason kept, so the UI can explain it).
 *
 * @param {{kind?: 'auto'|'real'|'simulated', deviceId?: string, label?: string, width?: number, height?: number, products?: Array}} [options]
 * @returns {Promise<CameraSource|SimulatedCameraSource>}
 */
export async function createCameraSource(options = {}) {
    const kind = options.kind || 'auto';

    if (kind === 'simulated') {
        return new SimulatedCameraSource(options).start().then((source) => source);
    }

    if (!isCameraSupported()) {
        return new SimulatedCameraSource(options).start().then((source) => {
            source.fallbackCode = 'unsupported';
            source.fallbackReason = 'Este navegador no permite usar la cámara. Usa Chrome o Edge actualizado.';
            return source;
        });
    }

    if (!isSecureContextForCamera()) {
        return new SimulatedCameraSource(options).start().then((source) => {
            source.fallbackCode = 'insecure';
            source.fallbackReason = 'La cámara requiere HTTPS o localhost.';
            return source;
        });
    }

    const source = new CameraSource();
    try {
        await source.start(options);
        return source;
    } catch (error) {
        if (kind === 'real') throw error;
        const described = describeCameraError(error);
        const simulated = await new SimulatedCameraSource(options).start();
        // The POS keeps working; the panel explains why there is no real image.
        simulated.fallbackCode = described.code;
        simulated.fallbackReason = described.message;
        return simulated;
    }
}
