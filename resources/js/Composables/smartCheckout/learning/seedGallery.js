/**
 * Sembrado de la galería de vectores desde el catálogo (Fase 4).
 *
 * El embedding DINOv2 sólo puede calcularse en el navegador, así que el servidor
 * se limita a listar los productos que se pueden sembrar y sus imágenes
 * (`GET /pos/smart-checkout/gallery/candidates`, paginado por cursor) y a aceptar
 * cada muestra ya vectorizada (`POST /pos/smart-checkout/samples`, que ya existía).
 *
 * Este módulo es sólo la **orquestación**: no descarga imágenes, no habla con el
 * worker ni con axios. Todo eso entra inyectado (`fetchCandidates`, `saveSample`),
 * de modo que la lógica delicada —lotes, reanudación, ritmo y cancelación— se
 * puede probar en Node sin DOM, sin ONNX y sin red.
 *
 * Reglas que implementa:
 *  - Lotes de `batchSize` productos (50 por defecto) con **cursor keyset** por id
 *    de producto: lo sembrado desaparece del conjunto «pendiente», así que el
 *    cursor sigue siendo válido aunque el conjunto se encoja.
 *  - **Reanudación**: el cursor se guarda tras cada producto, en el terminal, de
 *    forma que cerrar la pestaña no obliga a empezar de cero.
 *  - **Ritmo**: una muestra cada `minIntervalMs` (por defecto 1,1 s) para no
 *    chocar con el límite de 60 muestras por minuto del endpoint. Si aun así el
 *    servidor responde 429, el llamador devuelve `retryAfterMs` y se espera.
 *  - **Cancelación** limpia: el cursor queda donde se quedó.
 */

/** Prefijo de la clave del cursor en el almacenamiento del terminal. */
const CURSOR_PREFIX = 'smartpos_seed';

/** Valor por defecto del ritmo entre muestras (60 muestras/minuto ⇒ 1 s). */
const DEFAULT_MIN_INTERVAL_MS = 1100;

/** Nunca se espera más de esto por un 429 (ms). */
const DEFAULT_MAX_RETRY_WAIT_MS = 60000;

/** Tope de páginas por corrida: una salida de emergencia ante un cursor loco. */
const MAX_PAGES = 500;

/**
 * Clave del cursor de reanudación de un terminal.
 *
 * La galería es por suscripción, pero el cursor se guarda por terminal
 * (suscripción + sucursal + modelo): dos cajas del mismo negocio no se pisan el
 * progreso y un cambio de modelo empieza de cero, como debe.
 *
 * @param {{subscriptionId?: number|string|null, branchId?: number|string|null, modelVersion?: string|null}} context
 * @returns {string}
 */
export function seedStorageKey({ subscriptionId = null, branchId = null, modelVersion = null } = {}) {
    const part = (value) => (value === null || value === undefined || value === '' ? '-' : String(value));
    return `${CURSOR_PREFIX}:${part(subscriptionId)}:${part(branchId)}:${part(modelVersion) || 'default'}`;
}

/**
 * @param {object} deps
 * @param {(params: {afterId: number|null, limit: number}) => Promise<{items?: Array<object>, has_more?: boolean, total_pending?: number}>} deps.fetchCandidates
 * @param {(item: object, image: object) => Promise<{ok: boolean, skipped?: string, retryAfterMs?: number, message?: string}>} deps.saveSample
 * @param {{getItem: Function, setItem: Function, removeItem?: Function}} [deps.storage] localStorage-like; optional
 * @param {{subscriptionId?: any, branchId?: any, modelVersion?: any}} [deps.context]
 * @param {(progress: object) => void} [deps.onProgress]
 * @param {number} [deps.batchSize]
 * @param {number} [deps.minIntervalMs]
 * @param {number} [deps.maxRetryWaitMs]
 * @param {(ms: number) => Promise<void>} [deps.sleep]
 * @param {() => number} [deps.now]
 */
export function createGallerySeeder(deps = {}) {
    const {
        fetchCandidates,
        saveSample,
        storage = null,
        context = {},
        onProgress = null,
        batchSize = 50,
        minIntervalMs = DEFAULT_MIN_INTERVAL_MS,
        maxRetryWaitMs = DEFAULT_MAX_RETRY_WAIT_MS,
        sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
        now = () => Date.now(),
    } = deps;

    if (typeof fetchCandidates !== 'function') throw new TypeError('createGallerySeeder necesita fetchCandidates.');
    if (typeof saveSample !== 'function') throw new TypeError('createGallerySeeder necesita saveSample.');

    const key = seedStorageKey(context);
    const limit = Math.max(1, Math.min(50, Number(batchSize) || 50));

    let running = false;
    let cancelled = false;

    /* ------------------------------------------------------------- cursor */

    function readCursor() {
        try {
            const raw = storage?.getItem?.(key);
            const value = Number(raw);
            return Number.isFinite(value) && value > 0 ? value : null;
        } catch {
            return null;
        }
    }

    function writeCursor(value) {
        try {
            if (value === null || value === undefined) storage?.removeItem?.(key);
            else storage?.setItem?.(key, String(value));
        } catch {
            /* sin almacenamiento se pierde la reanudación, no el sembrado */
        }
    }

    /* ------------------------------------------------------------- eventos */

    function report(progress) {
        try {
            onProgress?.({ at: now(), ...progress });
        } catch {
            /* un observador roto no puede romper el sembrado */
        }
    }

    /* --------------------------------------------------------------- ciclo */

    /**
     * Siembra los productos pendientes del catálogo.
     *
     * @param {{resume?: boolean, filter?: 'pending'|'all'}} [options]
     *        `resume: false` ignora (y borra) el cursor guardado y empieza por el
     *        principio del catálogo.
     * @returns {Promise<{ok: boolean, saved: number, skipped: number, failed: number, processed: number, cancelled: boolean, totalPending: number|null, seconds: number, reason?: string}>}
     */
    async function run(options = {}) {
        const { resume = true, filter = 'pending' } = options;

        if (running) {
            return { ok: false, saved: 0, skipped: 0, failed: 0, processed: 0, cancelled: false, totalPending: null, seconds: 0, reason: 'busy' };
        }

        running = true;
        cancelled = false;

        const startedAt = now();
        let cursor = resume ? readCursor() : null;
        if (!resume) writeCursor(null);

        let processed = 0;
        let saved = 0;
        let skipped = 0;
        let failed = 0;
        let totalPending = null;
        let pages = 0;
        let lastSampleAt = 0;

        const summary = (reason) => ({
            ok: reason === undefined,
            saved,
            skipped,
            failed,
            processed,
            cancelled: cancelled || reason === 'cancelled',
            totalPending,
            seconds: Math.max(0, Math.round((now() - startedAt) / 1000)),
            ...(reason ? { reason } : {}),
        });

        /** Respeta el ritmo máximo del endpoint entre dos muestras. */
        async function pace() {
            const since = now() - lastSampleAt;
            const wait = Number(minIntervalMs) - since;
            if (lastSampleAt && wait > 0) await sleep(wait);
            lastSampleAt = now();
        }

        try {
            report({ phase: 'start', cursor, processed, saved, skipped, failed, totalPending });

            while (pages < MAX_PAGES) {
                if (cancelled) return summary('cancelled');

                let page;
                try {
                    page = await fetchCandidates({ afterId: cursor, limit, filter });
                } catch (error) {
                    report({ phase: 'error', message: error?.message || 'No se pudo leer el catálogo.', processed, saved, skipped, failed, totalPending });
                    return summary('fetch_failed');
                }

                const items = Array.isArray(page?.items) ? page.items : [];
                if (page?.total_pending !== undefined) totalPending = page.total_pending;

                pages += 1;
                // Punto de partida de la página: la comprobación de avance del
                // cursor se hace contra él, no contra el cursor ya avanzado.
                const cursorBeforePage = cursor;

                for (const item of items) {
                    if (cancelled) return summary('cancelled');

                    const images = Array.isArray(item?.images) ? item.images : [];
                    report({ phase: 'product', current: item?.name ?? null, productId: item?.product_id ?? null, images: images.length, processed, saved, skipped, failed, totalPending });

                    for (const image of images) {
                        if (cancelled) return summary('cancelled');
                        if (!image?.url) {
                            skipped += 1;
                            continue;
                        }

                        await pace();

                        let result;
                        try {
                            result = await saveSample(item, image);
                        } catch (error) {
                            failed += 1;
                            report({ phase: 'sample_error', message: error?.message || 'Falló el guardado de una muestra.', processed, saved, skipped, failed, totalPending });
                            continue;
                        }

                        // Un 429 del `throttle:60,1` no es un fallo: se espera lo
                        // que indique el servidor y se reintenta una sola vez.
                        if (result?.retryAfterMs) {
                            const wait = Math.min(Number(result.retryAfterMs) || 0, Number(maxRetryWaitMs) || 0);
                            if (wait > 0) {
                                report({ phase: 'waiting', retryInMs: wait, processed, saved, skipped, failed, totalPending });
                                await sleep(wait);
                                lastSampleAt = 0;
                                try {
                                    result = await saveSample(item, image);
                                } catch (error) {
                                    result = { ok: false, message: error?.message };
                                }
                            }
                        }

                        if (result?.ok) saved += 1;
                        else if (result?.skipped) skipped += 1;
                        else failed += 1;

                        report({ phase: 'sample', processed, saved, skipped, failed, totalPending });
                    }

                    processed += 1;
                    cursor = Number(item?.product_id) || cursor;
                    // Guardar tras cada producto hace que cerrar la pestaña no
                    // cueste más de un producto de trabajo.
                    writeCursor(cursor);
                }

                if (!page?.has_more) break;

                // El avance se toma del `next_cursor` del servidor y sólo si falta,
                // del último producto visto: con `filter=pending` el servidor puede
                // descartar en PHP todos los productos de una página y devolverla
                // vacía con `has_more: true`, y ahí el último id no existe.
                const nextCursor = Number(page?.next_cursor);
                const lastId = Number(items[items.length - 1]?.product_id);
                const advanceTo = Number.isFinite(nextCursor) && nextCursor > 0
                    ? nextCursor
                    : (Number.isFinite(lastId) && lastId > 0 ? lastId : null);

                // Si el cursor no avanza, se corta antes de entrar en bucle.
                if (advanceTo === null || (cursorBeforePage !== null && advanceTo <= Number(cursorBeforePage))) break;

                cursor = advanceTo;
                writeCursor(cursor);
            }

            if (pages >= MAX_PAGES) {
                report({ phase: 'budget', message: 'Se alcanzó el máximo de páginas por corrida.', processed, saved, skipped, failed, totalPending });
                return summary('budget');
            }

            // Terminado el catálogo: el próximo sembrado empieza de nuevo.
            writeCursor(null);
            report({ phase: 'done', processed, saved, skipped, failed, totalPending });

            return summary();
        } finally {
            running = false;
        }
    }

    /** Detiene la corrida en curso; el cursor se queda donde está. */
    function cancel() {
        cancelled = true;
    }

    return {
        run,
        cancel,
        get running() {
            return running;
        },
        /** Cursor guardado (id del último producto procesado), o null. */
        resumeCursor: readCursor,
        clearCursor: () => writeCursor(null),
        storageKey: key,
    };
}
