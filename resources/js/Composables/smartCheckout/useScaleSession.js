/**
 * Scale session: owns the driver lifecycle and exposes the values the
 * weighing panel needs. Everything hardware specific lives behind the driver
 * interface (see ./drivers), so the UI never talks to Web Serial directly.
 *
 * Terminal-only preferences (driver kind, profile selection) are remembered in
 * localStorage, following the same pattern the POS already uses for the cart
 * and the retail/kitchen mode: `pos_data_{userId}_{branchId}_...`.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { createScaleDriver } from './drivers/index.js';
import { computeLineTotal, convertWeight, formatWeight } from './scaleMath.js';

const TERMINAL_KEY_PREFIX = 'smartpos_scale';

/** Stable storage key per terminal (user + branch), like the POS cart. */
export function scaleStorageKey(userId, branchId) {
    return `${TERMINAL_KEY_PREFIX}_${userId ?? 'guest'}_${branchId ?? 'none'}`;
}

export function readTerminalScaleState(userId, branchId) {
    try {
        const raw = localStorage.getItem(scaleStorageKey(userId, branchId));
        return raw ? JSON.parse(raw) : {};
    } catch {
        return {};
    }
}

export function writeTerminalScaleState(userId, branchId, state) {
    try {
        localStorage.setItem(scaleStorageKey(userId, branchId), JSON.stringify(state));
    } catch {
        /* storage full or disabled: the session keeps working in memory */
    }
}

/**
 * Accepts the shapes `computeLineTotal` may return (an `{ok, ...}` result or a
 * plain `{quantity, total}` object) and normalizes them for the UI. The math
 * module reports errors as `{ok:false, error:'code', message:'texto'}`.
 */
function normalizeTotal(result) {
    if (!result) return { ok: false, error: 'Peso no válido' };
    if (result.ok === false) return { ok: false, error: result.message || result.error || 'Peso no válido' };
    if (result.ok === true) return result;
    return { ok: true, quantity: result.quantity, total: result.total };
}

export function useScaleSession(options = {}) {
    // Layer 2 of the preference model: the driver this cashier picked on this
    // terminal wins over the business default (layer 1, stored in the backend).
    const terminalState = readTerminalScaleState(options.userId, options.branchId);
    const kind = ref(terminalState.driver || options.kind || 'simulated');
    const settings = ref(options.settings || null);
    const driver = shallowRef(null);
    const connected = ref(false);
    const connecting = ref(false);
    const error = ref(null);
    const reading = ref({ weight: 0, unit: 'kg', stable: false });
    const rawLines = ref([]);
    const lastReadingAt = ref(null);

    const activeProfile = computed(() => {
        const list = settings.value?.profiles ?? [];
        const id = settings.value?.active_profile_id;
        return list.find((profile) => profile.id === id) || list[0] || null;
    });

    const stabilityOptions = computed(() => ({
        minReadings: Number(settings.value?.stability?.readings) || 5,
        toleranceGrams: Number(settings.value?.stability?.tolerance_g) || 3,
    }));

    const isStable = computed(() => Boolean(reading.value?.stable));
    const hasWeight = computed(() => Number(reading.value?.weight) > 0);

    function pushRawLine(line) {
        const next = rawLines.value.concat(line).slice(-200);
        rawLines.value = next;
    }

    function buildDriver() {
        const profile = activeProfile.value;
        const instance = createScaleDriver(kind.value, {
            profile,
            stability: stabilityOptions.value,
        });

        instance.onReading((next) => {
            if (!next || typeof next.weight !== 'number' || Number.isNaN(next.weight)) return;
            const weight = next.weight < 0 ? 0 : next.weight;
            reading.value = {
                weight,
                unit: next.unit === 'g' ? 'g' : 'kg',
                stable: Boolean(next.stable),
            };
            lastReadingAt.value = Date.now();
        });

        if (typeof instance.onRawLine === 'function') {
            instance.onRawLine((line) => pushRawLine(line));
        }

        // A serial port can fail at any time (busy, unplugged, permission
        // revoked). The panel must show that instead of a frozen weight.
        if (typeof instance.onError === 'function') {
            instance.onError((payload) => {
                error.value = payload?.message || 'La báscula reportó un error.';
                if (payload?.code === 'device_disconnected' || payload?.code === 'device_lost' || payload?.code === 'read_error') {
                    connected.value = false;
                }
            });
        }

        if (typeof instance.onStatus === 'function') {
            instance.onStatus((payload) => {
                if (payload?.status === 'disconnected' || payload?.status === 'closed') {
                    connected.value = false;
                }
            });
        }

        return instance;
    }

    async function disconnect() {
        const current = driver.value;
        driver.value = null;
        connected.value = false;
        if (current) {
            try {
                await current.disconnect();
            } catch {
                /* already closed */
            }
        }
    }

    async function connect() {
        error.value = null;
        connecting.value = true;
        try {
            if (driver.value) await disconnect();
            driver.value = buildDriver();
            await driver.value.connect();
            connected.value = true;
        } catch (e) {
            error.value = e?.message || 'No se pudo conectar con la báscula.';
            connected.value = false;
            await disconnect();
        } finally {
            connecting.value = false;
        }
        return connected.value;
    }

    async function setKind(nextKind, { autoConnect = true } = {}) {
        if (nextKind === kind.value) return;
        await disconnect();
        kind.value = nextKind;
        reading.value = { weight: 0, unit: 'kg', stable: false };
        if (autoConnect && nextKind !== 'manual') await connect();
    }

    async function tare() {
        if (!driver.value) return;
        error.value = null;
        try {
            await driver.value.tare();
        } catch (e) {
            error.value = e?.message || 'No se pudo tarar la báscula.';
        }
        reading.value = { ...reading.value, weight: 0, stable: false };
    }

    /** Manual capture: the cashier types the weight, so it counts as stable. */
    function submitManual(weight, unit = 'kg') {
        const value = Number(weight);
        if (!Number.isFinite(value) || value <= 0) {
            error.value = 'Captura un peso mayor a cero.';
            return false;
        }
        error.value = null;
        reading.value = { weight: value, unit: unit === 'g' ? 'g' : 'kg', stable: true };
        lastReadingAt.value = Date.now();
        return true;
    }

    /** Dev helper used by the simulated driver panel. */
    function simulate(weight, unit = 'kg', stable = true) {
        const instance = driver.value;
        if (!instance || typeof instance.setWeight !== 'function') return;
        instance.setWeight(Number(weight) || 0, unit);
        if (typeof instance.setStable === 'function') instance.setStable(Boolean(stable));
    }

    /**
     * Weight expressed in the product unit (Kg or Grs) plus the money total.
     * The price on the product is the price of one `measure_unit`.
     */
    function lineFor(product) {
        if (!product) return { ok: false, error: 'Elige un producto.' };
        const productUnit = normalizeProductUnit(product.measure_unit);
        const quantity = convertWeight(reading.value.weight, reading.value.unit, productUnit);
        const result = normalizeTotal(
            computeLineTotal({
                weight: reading.value.weight,
                unit: reading.value.unit,
                pricePerUnit: Number(product.price ?? product.selling_price ?? 0),
                productUnit,
            }),
        );
        if (!result.ok) return result;
        return { ...result, productUnit, displayQuantity: formatWeight(quantity, productUnit) };
    }

    function totalFor(product) {
        const line = lineFor(product);
        return line.ok ? line.total : 0;
    }

    watch(
        () => [settings.value?.active_profile_id, kind.value],
        () => {
            if (connected.value) {
                // Profile changed while connected: reconnect with the new port settings.
                void connect();
            }
        },
    );

    // Remember the driver chosen on this terminal so the cashier does not have
    // to pick it again after leaving the POS module.
    watch(kind, (value) => {
        writeTerminalScaleState(options.userId, options.branchId, {
            ...readTerminalScaleState(options.userId, options.branchId),
            driver: value,
        });
    });

    onBeforeUnmount(() => {
        void disconnect();
    });

    return {
        kind,
        settings,
        driver,
        connected,
        connecting,
        error,
        reading,
        rawLines,
        lastReadingAt,
        isStable,
        hasWeight,
        activeProfile,
        stabilityOptions,
        connect,
        disconnect,
        setKind,
        tare,
        submitManual,
        simulate,
        lineFor,
        totalFor,
        clearRawLines: () => {
            rawLines.value = [];
        },
    };
}

/** Normalizes `measure_unit` to the canonical `kg` | `g` values. */
export function normalizeProductUnit(rawUnit) {
    const unit = String(rawUnit ?? '').trim().toLowerCase();
    if (['g', 'gr', 'grs', 'gramo', 'gramos'].includes(unit)) return 'g';
    return 'kg';
}

/** A product is weighable when it is bulk AND its unit is a weight unit. */
export function isWeighableProduct(product) {
    if (!product || !product.is_bulk) return false;
    const unit = String(product.measure_unit ?? '').trim().toLowerCase();
    return ['kg', 'kgs', 'g', 'gr', 'grs', 'gramo', 'gramos'].includes(unit);
}
