/**
 * Barcode reader used to confirm a detection or to register an unknown product.
 *
 * Two backends, in this order:
 *  1. the native `BarcodeDetector` (Chrome/Edge on Android desktop: no download),
 *  2. `zxing-wasm` (MIT), loaded on demand only when the native API is missing.
 *
 * In this system the scannable code of a product is its **SKU**: the products
 * table has no barcode column (see docs/caja-inteligente/00-descubrimiento.md §5).
 */

const NATIVE_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code', 'itf'];

let zxingModulePromise = null;

async function loadZxing() {
    if (zxingModulePromise) return zxingModulePromise;

    zxingModulePromise = (async () => {
        const [zxing, wasmUrl] = await Promise.all([
            import('zxing-wasm/reader'),
            import('zxing-wasm/reader/zxing_reader.wasm?url').then((module) => module.default),
        ]);

        zxing.prepareZXingModule?.({
            overrides: {
                locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : `${prefix}${path}`),
            },
        });

        return zxing;
    })();

    return zxingModulePromise;
}

export function isNativeBarcodeSupported() {
    return typeof globalThis !== 'undefined' && 'BarcodeDetector' in globalThis;
}

export class BarcodeReader {
    constructor() {
        this.nativeDetector = null;
        this.backend = 'idle';
    }

    /** Picks the best available backend. Never throws: falls back to zxing. */
    async init() {
        if (isNativeBarcodeSupported()) {
            try {
                this.nativeDetector = new globalThis.BarcodeDetector({ formats: NATIVE_FORMATS });
                this.backend = 'native';
                return this.backend;
            } catch {
                this.nativeDetector = null;
            }
        }

        try {
            await loadZxing();
            this.backend = 'zxing-wasm';
        } catch {
            this.backend = 'unavailable';
        }

        return this.backend;
    }

    /**
     * @param {{data: Uint8ClampedArray, width: number, height: number}|ImageBitmap|HTMLVideoElement} source
     * @returns {Promise<Array<{value: string, format: string}>>}
     */
    async detect(source) {
        if (!source) return [];

        if (this.backend === 'native' && this.nativeDetector) {
            try {
                const found = await this.nativeDetector.detect(source);
                return (found || []).map((code) => ({ value: String(code.rawValue || ''), format: String(code.format || '') }));
            } catch {
                return [];
            }
        }

        if (this.backend === 'zxing-wasm') {
            try {
                const zxing = await loadZxing();
                const results = await zxing.readBarcodes(source, { tryHarder: true, formats: [] });
                return (results || [])
                    .filter((result) => result?.isValid !== false && result?.text)
                    .map((result) => ({ value: String(result.text), format: String(result.format || '') }));
            } catch {
                return [];
            }
        }

        return [];
    }
}
