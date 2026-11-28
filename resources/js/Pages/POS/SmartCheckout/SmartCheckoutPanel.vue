<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { formatMoney } from '@/Composables/smartCheckout/scaleMath.js';
import { statusIcon, statusLabel } from '@/Composables/smartCheckout/recognition/confidence.js';
import CreateProductModal from '@/Components/CreateProductModal.vue';

const props = defineProps({
    // Session created by the page (useSmartCheckout) — reactive proxy, refs unwrapped.
    session: { type: Object, required: true },
    smartCheckout: { type: Object, default: () => ({ enabled: false, settings: null }) },
    products: { type: Array, default: () => [] },
    scaleEnabled: { type: Boolean, default: false },
    userId: { type: [Number, String], default: null },
    branchId: { type: [Number, String], default: null },
});

const emit = defineEmits(['close', 'product-created']);

const toast = useToast();
const collapsed = ref(false);
const showSettings = ref(false);
const unknownSearch = ref('');
const isCreateProductVisible = ref(false);
const savingSettings = ref(false);
const previewEl = ref(null);
const calibrating = ref(false);
const draft = ref({ thresholds: { green_min: 0.85, green_margin: 0.08, yellow_min: 0.65 }, auto_accept: true });

/* --------------------------------------------------------------------------
 * Aprendizaje con el uso (Fase 4)
 * ----------------------------------------------------------------------- */
const showLearning = ref(false);
const learningChecked = ref(false);
const galleryDialogVisible = ref(false);
const galleryProduct = ref(null);
const gallerySamples = ref([]);
const galleryLoading = ref(false);
const deletingSampleId = ref(null);

/** Texto de los orígenes de muestra (el API sólo acepta estos cuatro). */
const SAMPLE_SOURCE_LABELS = {
    catalog_image: 'Imagen del catálogo',
    barcode_scan: 'Escaneo en caja',
    quick_register: 'Alta rápida',
    cashier_correction: 'Corrección del cajero',
};

const learning = computed(() => props.session.learning || {});
const canSeedCatalog = computed(() => props.session.engineKind === 'onnx');
/**
 * El porcentaje mezcla lo ya procesado con lo que el servidor dice que sigue
 * pendiente: es una estimación honesta (converge a 100 %) y no una barra falsa.
 */
const seedProgress = computed(() => {
    const done = Number(learning.value.processed || 0);
    const remaining = Number(learning.value.pendingTotal || 0);
    const total = done + remaining;
    if (!total) return 0;
    return Math.min(100, Math.round((done / total) * 100));
});

/**
 * Productos del POS que ya tienen muestras en la galería del motor.
 *
 * `sampleCountFor` lee un Map que no es reactivo, así que esta computada declara
 * explícitamente su dependencia de la versión y el recuento de la galería: cuando
 * se siembra o se borra una muestra, el servidor devuelve otra versión y la lista
 * se vuelve a calcular sola.
 */
const galleryProducts = computed(() => {
    void props.session.galleryVersion;
    void props.session.galleryCount;
    const countFor = props.session.sampleCountFor;
    if (typeof countFor !== 'function') return [];

    return props.products
        .map((product) => ({ id: product.id, name: product.name, sku: product.sku, samples: countFor(product.id) }))
        .filter((row) => row.samples > 0)
        .sort((a, b) => b.samples - a.samples)
        .slice(0, 12);
});

watch(
    () => props.smartCheckout?.settings,
    (next) => {
        if (!next) return;
        draft.value = {
            thresholds: { ...(next.thresholds || {}) },
            auto_accept: next.auto_accept !== false,
        };
    },
    { immediate: true },
);

const statusTone = computed(() => {
    switch (props.session.status) {
        case 'ready':
            return 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/30';
        case 'loading':
            return 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/30';
        case 'error':
            return 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/30';
        case 'paused':
            return 'bg-gray-100 text-gray-600 border-gray-200 dark:bg-[#2b2b2b] dark:text-gray-300 dark:border-[#3a3a3a]';
        default:
            return 'bg-gray-50 text-gray-500 border-gray-200 dark:bg-[#1a1a1a] dark:text-gray-400 dark:border-[#3a3a3a]';
    }
});

const elapsedLabel = computed(() => {
    const total = Number(props.session.elapsedSeconds) || 0;
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const boxTone = {
    green: 'border-emerald-400 bg-emerald-400/10',
    yellow: 'border-amber-400 bg-amber-400/10',
    red: 'border-red-400 bg-red-400/10',
};

const badgeTone = {
    green: 'bg-emerald-500 text-white',
    yellow: 'bg-amber-500 text-white',
    red: 'bg-red-500 text-white',
};

const badgeIconFor = (status) => statusIcon(status);
const badgeTextFor = (status) => statusLabel(status);

const unknownResults = computed(() => {
    const term = unknownSearch.value.trim().toLowerCase();
    if (!term) return [];
    return props.products
        .filter((product) => `${product.name} ${product.sku || ''}`.toLowerCase().includes(term))
        .slice(0, 5);
});

function boxStyle(box) {
    const [x, y, w, h] = box || [];
    return {
        left: `${(Number(x) || 0) * 100}%`,
        top: `${(Number(y) || 0) * 100}%`,
        width: `${(Number(w) || 0) * 100}%`,
        height: `${(Number(h) || 0) * 100}%`,
    };
}

function productFor(detection) {
    const id = detection?.candidates?.[0]?.productId;
    return props.products.find((product) => Number(product.id) === Number(id)) || null;
}

function candidateName(candidate) {
    const product = props.products.find((item) => Number(item.id) === Number(candidate.productId));
    return product?.name || `Producto #${candidate.productId}`;
}

function candidatePrice(candidate) {
    const product = props.products.find((item) => Number(item.id) === Number(candidate.productId));
    return product ? formatMoney(product.price) : '';
}

function confirm(row, product = null) {
    props.session.confirm(row, product || row.product);
    toast.add({ severity: 'success', summary: 'Producto agregado', detail: (product || row.product)?.name, life: 2000 });
}

function confirmUnknown(product) {
    const row = props.session.pendingRed[0];
    if (!row) return;
    props.session.resolveUnknown(row, product, { source: 'barcode_scan', barcode: row.barcode });
    unknownSearch.value = '';
    toast.add({ severity: 'success', summary: 'Producto agregado', detail: product.name, life: 2000 });
}

function togglePause() {
    if (props.session.status === 'paused') props.session.resume();
    else props.session.pause();
}

async function saveSettings() {
    savingSettings.value = true;
    try {
        const { data } = await (await import('axios')).default.put(route('pos.smart-checkout.settings.update'), {
            thresholds: {
                green_min: Number(draft.value.thresholds.green_min),
                green_margin: Number(draft.value.thresholds.green_margin),
                yellow_min: Number(draft.value.thresholds.yellow_min),
            },
            auto_accept: Boolean(draft.value.auto_accept),
        });
        props.session.setSettings?.(data.settings);
        toast.add({ severity: 'success', summary: 'Ajustes guardados', detail: 'Los umbrales se aplicarán en el siguiente análisis.', life: 3000 });
        showSettings.value = false;
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'No se guardaron los ajustes',
            detail: error?.response?.data?.message || 'Revisa los umbrales.',
            life: 4000,
        });
    } finally {
        savingSettings.value = false;
    }
}

function onProductCreated(product) {
    emit('product-created', product);
    const row = props.session.pendingRed[0];
    if (row && product) {
        props.session.resolveUnknown(row, {
            id: product.id,
            name: product.name,
            price: Number(product.selling_price) || 0,
            selling_price: Number(product.selling_price) || 0,
            sku: product.sku || '',
            stock: product.current_stock || 0,
        }, { source: 'quick_register' });
    }
    isCreateProductVisible.value = false;
}

/**
 * Keyboard shortcuts for the frequent actions: 1/2/3 confirm the first pending
 * candidate (never while the cashier is typing), Escape collapses the panel.
 */
function handleShortcut(event) {
    const tag = (event.target?.tagName || '').toLowerCase();
    if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) return;
    if (event.key === 'Escape') {
        collapsed.value = true;
        return;
    }
    if (!['1', '2', '3'].includes(event.key)) return;
    const row = props.session.pendingYellow[0];
    if (!row) return;
    const candidate = row.detection?.candidates?.[Number(event.key) - 1];
    if (!candidate) return;
    event.preventDefault();
    const product = props.products.find((item) => Number(item.id) === Number(candidate.productId)) || null;
    confirm(row, product);
}

onMounted(() => {
    window.addEventListener('keydown', handleShortcut);
    // Fase 4: si el catálogo tiene productos con foto y sin muestras, el panel lo
    // avisa; si la caja está en pausa, aprovecha para sembrar solo.
    void maybeAutoSeed();
});
onBeforeUnmount(() => window.removeEventListener('keydown', handleShortcut));

/** Real camera preview: only the ONNX engine has a video stream. */
async function mountPreview() {
    await nextTick();
    if (props.session.engineKind !== 'onnx' || !previewEl.value) return;
    const attached = props.session.attachPreview?.(previewEl.value);
    if (!attached) {
        toast.add({
            severity: 'warn',
            summary: 'Sin vista de cámara',
            detail: 'No se pudo mostrar la cámara. Revisa el permiso del navegador.',
            life: 4000,
        });
    }
}

watch(
    () => [props.session.active, props.session.engineKind],
    ([isActive]) => {
        if (isActive) void mountPreview();
    },
    { immediate: true },
);

async function recalibrate() {
    calibrating.value = true;
    try {
        const ok = await props.session.calibrate?.();
        toast.add({
            severity: ok ? 'success' : 'warn',
            summary: ok ? 'Mostrador calibrado' : 'No se pudo calibrar',
            detail: ok
                ? 'La referencia del mostrador vacío se guardó para esta cámara.'
                : 'Retira los productos del mostrador y vuelve a intentarlo.',
            life: 4000,
        });
    } finally {
        calibrating.value = false;
    }
}

/* --------------------------------------------------------------------------
 * Sembrado de la galería desde el catálogo
 * ----------------------------------------------------------------------- */

/**
 * Siembra el catálogo (lotes de 50 con reanudación).
 *
 * El sembrado usa el mismo worker que el reconocimiento, así que se pausa el
 * análisis mientras dura y se reanuda al terminar: durante una venta, la caja
 * manda; el catálogo puede esperar unos segundos.
 */
async function seedFromCatalog({ automatic = false } = {}) {
    const session = props.session;
    if (!session?.startSeeding || learning.value.seeding) return;

    if (!canSeedCatalog.value) {
        toast.add({
            severity: 'warn',
            summary: 'Sin modelos publicados',
            detail: 'El sembrado calcula los vectores en el navegador y necesita los modelos de /models.',
            life: 5000,
        });
        return;
    }

    const wasReady = session.status === 'ready';
    if (wasReady) session.pause();

    try {
        const result = await session.startSeeding({ resume: true });

        if (!result) {
            toast.add({
                severity: 'error',
                summary: 'No se pudo sembrar',
                detail: session.learning?.lastError || 'Revisa los modelos publicados e inténtalo de nuevo.',
                life: 5000,
            });
            return;
        }

        if (result.reason === 'cancelled') {
            toast.add({
                severity: 'info',
                summary: 'Sembrado detenido',
                detail: `${result.saved} imágenes guardadas. Al volver a lanzarlo continúa donde se quedó.`,
                life: 4500,
            });
            return;
        }

        const detail = `${result.saved} imágenes guardadas · ${result.skipped} omitidas · ${result.failed} con error`;
        toast.add({
            severity: result.failed ? 'warn' : 'success',
            summary: automatic ? 'Galería del catálogo actualizada' : 'Galería del catálogo sembrada',
            detail,
            life: 5000,
        });
    } finally {
        if (wasReady) session.resume();
    }
}

/**
 * Sembrado automático: sólo cuando el cajero ya pausó el análisis.
 *
 * Es la forma de cumplir «al guardar un producto con foto no quiero acordarme de
 * nada» sin quitarle el worker a una venta en curso: en cuanto la caja está
 * pausada, el terminal aprovecha para poner al día el catálogo solo.
 */
async function maybeAutoSeed() {
    if (learningChecked.value) return;
    learningChecked.value = true;

    const session = props.session;
    if (!session?.refreshPendingCount || !canSeedCatalog.value) return;

    const pending = await session.refreshPendingCount();
    if (!pending) return;

    if (session.status === 'paused') {
        void seedFromCatalog({ automatic: true });
    }
}

function toggleLearning() {
    showLearning.value = !showLearning.value;
    if (showLearning.value) void maybeAutoSeed();
}

function cancelSeeding() {
    props.session.cancelSeeding?.();
}

/* --------------------------------------------------------------------------
 * Gestión de la galería (ver y borrar muestras)
 * ----------------------------------------------------------------------- */

async function openGalleryProduct(product) {
    galleryProduct.value = product;
    galleryDialogVisible.value = true;
    gallerySamples.value = [];
    galleryLoading.value = true;

    try {
        const data = await props.session.loadProductSamples?.(product.id);
        gallerySamples.value = data?.samples || [];
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'No se pudieron leer las muestras',
            detail: error?.response?.data?.message || 'Inténtalo de nuevo.',
            life: 4000,
        });
    } finally {
        galleryLoading.value = false;
    }
}

async function removeSample(sample) {
    if (!sample?.id) return;
    deletingSampleId.value = sample.id;
    try {
        await props.session.deleteSample?.(sample.id);
        gallerySamples.value = gallerySamples.value.filter((row) => row.id !== sample.id);
        toast.add({ severity: 'success', summary: 'Muestra borrada', detail: 'La galería ya no usará esa muestra.', life: 2500 });
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'No se pudo borrar',
            detail: error?.response?.data?.message || 'Inténtalo de nuevo.',
            life: 4000,
        });
    } finally {
        deletingSampleId.value = null;
    }
}

function sampleDate(value) {
    if (!value) return '';
    try {
        return new Date(value).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch {
        return '';
    }
}
</script>

<template>
    <div class="fixed bottom-4 left-4 z-40 w-[440px] max-w-[calc(100vw-2rem)]">
        <!-- Barra colapsada -->
        <button
            v-if="collapsed"
            type="button"
            class="w-full flex items-center gap-3 rounded-2xl border border-gray-200 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] px-4 py-3 shadow-xl text-left"
            @click="collapsed = false"
        >
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true"></span>
            <span class="text-sm font-semibold text-gray-800 dark:text-white">Caja Inteligente</span>
            <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ props.session.counters.auto_accepted }} auto · {{ props.session.counters.confirmed }} confirmados · {{ props.session.counters.unknown }} desconocidos</span>
            <i class="pi pi-chevron-up ml-auto text-gray-400" aria-hidden="true"></i>
        </button>

        <div v-else class="rounded-3xl border border-gray-200 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] shadow-2xl overflow-hidden">
            <!-- Cabecera -->
            <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                <span class="w-2.5 h-2.5 rounded-full" :class="props.session.status === 'ready' ? 'bg-emerald-500 animate-pulse' : props.session.status === 'error' ? 'bg-red-500' : 'bg-amber-400'" aria-hidden="true"></span>
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Modo Caja Inteligente</p>
                    <p class="text-sm font-semibold text-gray-800 dark:text-white m-0 truncate">
                        {{ props.session.statusLabel() }} · {{ props.session.modelVersion }}
                    </p>
                </div>
                <div class="flex items-center gap-1 ml-auto">
                    <Button
                        :icon="props.session.status === 'paused' ? 'pi pi-play' : 'pi pi-pause'"
                        text rounded size="small"
                        :aria-label="props.session.status === 'paused' ? 'Reanudar el análisis' : 'Pausar el análisis'"
                        class="!text-gray-500 dark:!text-gray-300"
                        @click="togglePause"
                    />
                    <Button icon="pi pi-sliders-h" text rounded size="small" aria-label="Ajustes del modo"
                        class="!text-gray-500 dark:!text-gray-300" @click="showSettings = !showSettings" />
                    <span class="relative inline-flex">
                        <Button icon="pi pi-database" text rounded size="small"
                            :aria-label="showLearning ? 'Ocultar el aprendizaje con el uso' : 'Abrir el aprendizaje con el uso'"
                            :aria-expanded="showLearning"
                            class="!text-gray-500 dark:!text-gray-300" @click="toggleLearning" />
                        <span v-if="learning.pendingTotal" class="absolute -top-0.5 -right-0.5 min-w-[14px] h-[14px] px-1 rounded-full bg-primary-500 text-white text-[9px] font-bold flex items-center justify-center">
                            {{ learning.pendingTotal > 99 ? '99+' : learning.pendingTotal }}
                        </span>
                    </span>
                    <Button icon="pi pi-chevron-down" text rounded size="small" aria-label="Contraer el panel"
                        class="!text-gray-500 dark:!text-gray-300" @click="collapsed = true" />
                    <Button icon="pi pi-power-off" text rounded size="small" aria-label="Apagar el Modo Caja Inteligente"
                        class="!text-red-500" @click="emit('close')" />
                </div>
            </div>

            <!-- Ajustes (umbrales del semáforo) -->
            <div v-if="showSettings" class="px-4 py-3 border-b border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] space-y-3">
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Umbrales de confianza</p>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 mb-1" for="sc-green">Verde desde</label>
                        <InputNumber id="sc-green" v-model="draft.thresholds.green_min" :min="0" :max="1" :step="0.01" :maxFractionDigits="2"
                            class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 mb-1" for="sc-margin">Margen 2.º</label>
                        <InputNumber id="sc-margin" v-model="draft.thresholds.green_margin" :min="0" :max="1" :step="0.01" :maxFractionDigits="2"
                            class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 mb-1" for="sc-yellow">Amarillo desde</label>
                        <InputNumber id="sc-yellow" v-model="draft.thresholds.yellow_min" :min="0" :max="1" :step="0.01" :maxFractionDigits="2"
                            class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-[11px] font-semibold text-gray-600 dark:text-gray-300">
                    <ToggleSwitch v-model="draft.auto_accept" />
                    Agregar automáticamente los productos verdes
                </label>
                <div class="flex justify-end gap-2">
                    <Button label="Cancelar" size="small" text severity="secondary" class="!text-[11px] !font-bold" @click="showSettings = false" />
                    <Button label="Guardar" size="small" class="!rounded-xl !text-[11px] !font-bold" :loading="savingSettings" @click="saveSettings" />
                </div>
            </div>

            <!-- Aprendizaje con el uso (Fase 4) -->
            <div v-if="showLearning" class="px-4 py-3 border-b border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Aprendizaje con el uso</p>
                        <p class="text-[11px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">
                            <span class="font-semibold">{{ props.session.galleryCount || 0 }}</span> muestras en la galería
                            <span v-if="learning.galleryFromCache" class="text-gray-400"> · desde este terminal</span>
                        </p>
                    </div>
                    <span v-if="learning.pendingTotal" class="shrink-0 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300 text-[9px] font-bold">
                        {{ learning.pendingTotal }} sin muestras
                    </span>
                </div>

                <p v-if="!canSeedCatalog" class="text-[11px] text-gray-500 dark:text-gray-400 m-0">
                    El sembrado calcula los vectores en el navegador, así que necesita los modelos publicados en <span class="font-semibold">/models</span>.
                    Mientras tanto, cada escaneo, alta rápida o corrección del cajero sí se guarda.
                </p>

                <div v-else class="space-y-2">
                    <div v-if="learning.seeding" class="space-y-1.5">
                        <ProgressBar :value="seedProgress" :showValue="false" class="!h-1.5" />
                        <p class="text-[11px] text-gray-600 dark:text-gray-300 m-0 truncate">
                            {{ learning.current ? `Procesando ${learning.current}` : 'Preparando el lote…' }}
                        </p>
                        <p class="text-[10px] text-gray-400 m-0">
                            {{ learning.saved }} guardadas · {{ learning.skipped }} omitidas · {{ learning.failed }} con error
                            <span v-if="learning.pendingTotal"> · {{ learning.pendingTotal }} pendientes</span>
                        </p>
                        <Button label="Detener" size="small" text severity="secondary" class="!text-[11px] !font-bold" @click="cancelSeeding" />
                    </div>

                    <div v-else class="flex flex-wrap items-center gap-2">
                        <Button
                            :label="learning.pendingTotal ? 'Sembrar catálogo' : 'Actualizar galería'"
                            icon="pi pi-cloud-download"
                            size="small"
                            class="!rounded-xl !text-[11px] !font-bold"
                            @click="seedFromCatalog({})"
                        />
                        <span class="text-[10px] text-gray-400">
                            Lotes de 50 · se reanuda donde se quedó
                        </span>
                    </div>

                    <p v-if="learning.lastError" class="text-[10px] text-amber-600 dark:text-amber-400 m-0">
                        {{ learning.lastError }}
                    </p>
                </div>

                <!-- Productos con muestras: ver y borrar -->
                <div v-if="galleryProducts.length" class="pt-1 space-y-1">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Productos con muestras</p>
                    <div class="max-h-40 overflow-y-auto space-y-1 pr-1">
                        <div
                            v-for="row in galleryProducts"
                            :key="row.id"
                            class="flex items-center gap-2 px-2 py-1 rounded-lg bg-white dark:bg-[#232323] border border-gray-200 dark:border-[#3a3a3a]"
                        >
                            <span class="truncate text-[11px] font-semibold text-gray-700 dark:text-gray-200">{{ row.name }}</span>
                            <span class="ml-auto shrink-0 text-[10px] text-gray-400">{{ row.samples }}/10</span>
                            <Button icon="pi pi-eye" text rounded size="small" aria-label="Ver las muestras del producto"
                                class="!w-6 !h-6 !p-0 !text-gray-500 dark:!text-gray-300" @click="openGalleryProduct(row)" />
                        </div>
                    </div>
                </div>
                <p v-else-if="canSeedCatalog" class="text-[10px] text-gray-400 m-0">
                    Todavía no hay productos con muestras. Siembra el catálogo o resuelve un producto desconocido para empezar.
                </p>
            </div>

            <!-- Vista previa con cajas -->
            <div class="relative m-3 rounded-2xl bg-gray-900 overflow-hidden" style="aspect-ratio: 16 / 9;">
                <!-- Cámara real (motor ONNX) -->
                <video
                    v-show="props.session.engineKind === 'onnx' && props.session.hasRealCamera"
                    ref="previewEl"
                    class="absolute inset-0 w-full h-full object-cover"
                    playsinline
                    muted
                    aria-label="Vista previa de la cámara"
                ></video>

                <div v-show="props.session.engineKind !== 'onnx' || !props.session.hasRealCamera" class="absolute inset-0 opacity-40"
                    style="background-image: linear-gradient(rgba(255,255,255,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.06) 1px, transparent 1px); background-size: 32px 32px;">
                </div>
                <div class="absolute inset-0 flex items-center justify-center" v-if="!props.session.boxes.length">
                    <p class="text-[11px] text-gray-500 m-0">Esperando productos en el mostrador…</p>
                </div>
                <div
                    v-for="(detection, index) in props.session.boxes"
                    :key="`${detection.cameraId}-${index}`"
                    class="absolute border-2 rounded-lg transition-all duration-200"
                    :class="boxTone[detection.status] || boxTone.yellow"
                    :style="boxStyle(detection.box)"
                >
                    <span class="absolute -top-2 left-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold"
                        :class="badgeTone[detection.status] || badgeTone.yellow">
                        <i :class="badgeIconFor(detection.status)" aria-hidden="true"></i>
                        {{ badgeTextFor(detection.status) }}
                    </span>
                    <span class="absolute bottom-1 left-1 right-1 text-[9px] text-white/90 truncate">
                        {{ productFor(detection)?.name || (detection.barcode ? `Código ${detection.barcode}` : 'Sin identificar') }}
                    </span>
                </div>
                <div class="absolute bottom-1 right-2 flex items-center gap-2">
                    <span class="text-[9px] text-gray-400">
                        {{ props.session.engineKind === 'onnx' ? 'ONNX · galería ' + (props.session.galleryCount || 0) : 'Motor simulado' }}
                    </span>
                    <span class="text-[9px] text-gray-400">{{ props.session.latency ? Math.round(props.session.latency) + ' ms' : '—' }}</span>
                </div>

                <!-- Calibración del mostrador vacío (estrategia por diferencia) -->
                <Button
                    v-if="props.session.engineKind === 'onnx'"
                    label="Recalibrar mostrador"
                    icon="pi pi-camera"
                    size="small"
                    :loading="calibrating"
                    class="absolute top-2 right-2 !rounded-xl !text-[10px] !font-bold !bg-black/60 !border-black/40 !text-white hover:!bg-black/80"
                    @click="recalibrate"
                />
            </div>

            <!-- Aviso cuando no hay cámara real: el modo sigue usable con la simulada -->
            <div v-if="props.session.cameraWarnings?.length" class="px-3 pb-1">
                <Message severity="warn" variant="simple" size="small" class="!text-[11px]">
                    <span class="font-semibold">{{ props.session.cameraWarnings[0].message }}</span>
                    Se está usando una cámara simulada para probar el modo; conecta una cámara y vuelve a activarlo para usar la imagen real.
                </Message>
            </div>

            <!-- Selector de candidatos (amarillo) -->
            <div v-if="props.session.pendingYellow.length" class="px-3 pb-2 space-y-2">
                <div v-for="row in props.session.pendingYellow" :key="row.key"
                    class="rounded-2xl border border-amber-200 dark:border-amber-500/30 bg-amber-50/60 dark:bg-amber-500/5 p-2.5">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-amber-700 dark:text-amber-300 m-0 mb-1.5">
                        ¿Cuál es? {{ row.requiresWeigh ? '· por peso' : '' }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="candidate in row.detection.candidates"
                            :key="candidate.productId"
                            type="button"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-white dark:bg-[#232323] border border-amber-200 dark:border-amber-500/30 text-[11px] font-semibold text-gray-700 dark:text-gray-200 hover:border-amber-500 transition-colors"
                            @click="confirm(row, props.products.find((p) => Number(p.id) === Number(candidate.productId)) || null)"
                        >
                            <span class="truncate max-w-[140px]">{{ candidateName(candidate) }}</span>
                            <span class="text-[10px] text-gray-400">{{ Math.round(candidate.score * 100) }}%</span>
                            <span class="text-[10px] text-primary-500">{{ candidatePrice(candidate) }}</span>
                        </button>
                        <button
                            type="button"
                            class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold text-gray-500 hover:text-red-500 transition-colors"
                            @click="props.session.reject(row)"
                        >
                            No es ninguno
                        </button>
                    </div>
                </div>
            </div>

            <!-- Producto desconocido (rojo) -->
            <div v-if="props.session.pendingRed.length" class="px-3 pb-2">
                <div class="rounded-2xl border border-red-200 dark:border-red-500/30 bg-red-50/60 dark:bg-red-500/5 p-2.5 space-y-2">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-red-700 dark:text-red-300 m-0">
                        Producto desconocido
                    </p>
                    <p class="text-[11px] text-gray-600 dark:text-gray-300 m-0">
                        Escanea el código o búscalo en el catálogo. Si no existe, dalo de alta.
                    </p>
                    <InputText
                        v-model="unknownSearch"
                        placeholder="Código o nombre del producto"
                        class="w-full !text-xs !rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]"
                    />
                    <div v-if="unknownResults.length" class="space-y-1">
                        <button
                            v-for="product in unknownResults"
                            :key="product.id"
                            type="button"
                            class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg bg-white dark:bg-[#232323] border border-gray-200 dark:border-[#3a3a3a] text-[11px] hover:border-primary-500"
                            @click="confirmUnknown(product)"
                        >
                            <span class="truncate">{{ product.name }}</span>
                            <span class="text-primary-500 font-bold">{{ formatMoney(product.price) }}</span>
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button label="Alta rápida" icon="pi pi-plus" size="small" outlined class="!rounded-xl !text-[11px] !font-bold"
                            @click="isCreateProductVisible = true" />
                        <Button label="Descartar" size="small" text severity="secondary" class="!text-[11px]"
                            @click="props.session.dismissUnknown(props.session.pendingRed[0])" />
                    </div>
                </div>
            </div>

            <!-- Contadores de la venta en curso -->
            <div class="grid grid-cols-4 gap-1 px-3 py-2 border-t border-gray-100 dark:border-[#3a3a3a]">
                <div class="text-center">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-400 m-0">Detectados</p>
                    <p class="text-sm font-bold text-gray-800 dark:text-white m-0">{{ props.session.counters.detected }}</p>
                </div>
                <div class="text-center">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-400 m-0">Automáticos</p>
                    <p class="text-sm font-bold text-emerald-600 dark:text-emerald-400 m-0">{{ props.session.counters.auto_accepted }}</p>
                </div>
                <div class="text-center">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-400 m-0">Confirmados</p>
                    <p class="text-sm font-bold text-amber-600 dark:text-amber-400 m-0">{{ props.session.counters.confirmed }}</p>
                </div>
                <div class="text-center">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-400 m-0">Desconocidos</p>
                    <p class="text-sm font-bold text-red-600 dark:text-red-400 m-0">{{ props.session.counters.unknown }}</p>
                </div>
            </div>

            <div class="flex items-center justify-between px-3 pb-2">
                <span class="text-[10px] text-gray-400">
                    Venta en curso · {{ elapsedLabel }}
                    <span v-if="props.session.counters.corrected" class="text-sky-500 dark:text-sky-400 font-bold"> · {{ props.session.counters.corrected }} corregidos</span>
                </span>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[9px] font-bold" :class="statusTone">
                    <i class="pi pi-circle-fill !text-[6px]" aria-hidden="true"></i>
                    {{ props.session.statusLabel() }}
                </span>
            </div>
        </div>

        <CreateProductModal v-model:visible="isCreateProductVisible" @created="onProductCreated" />

        <!-- Muestras de un producto: ver el origen y borrar una que esté mal -->
        <Dialog
            v-model:visible="galleryDialogVisible"
            modal
            :header="galleryProduct?.name || 'Muestras del producto'"
            :style="{ width: '420px', maxWidth: 'calc(100vw - 2rem)' }"
            :draggable="false"
        >
            <div class="space-y-2">
                <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0">
                    Cada muestra es una foto del producto que el motor usa para reconocerlo.
                    Si una no corresponde, bórrala: la galería la deja de usar en el siguiente análisis.
                </p>

                <div v-if="galleryLoading" class="flex items-center gap-2 py-3">
                    <i class="pi pi-spin pi-spinner text-primary-500" aria-hidden="true"></i>
                    <span class="text-[11px] text-gray-500">Leyendo las muestras…</span>
                </div>

                <div v-else-if="!gallerySamples.length" class="rounded-xl border border-dashed border-gray-200 dark:border-[#3a3a3a] px-3 py-4 text-center">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0">Este producto no tiene muestras guardadas.</p>
                </div>

                <ul v-else class="space-y-1.5 m-0 p-0 list-none">
                    <li
                        v-for="sample in gallerySamples"
                        :key="sample.id"
                        class="flex items-center gap-2 px-2.5 py-2 rounded-xl border border-gray-200 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]"
                    >
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-200 m-0 truncate">
                                {{ SAMPLE_SOURCE_LABELS[sample.source] || sample.source }}
                            </p>
                            <p class="text-[10px] text-gray-400 m-0">
                                {{ sampleDate(sample.created_at) }}
                                <span v-if="sample.quality !== null && sample.quality !== undefined"> · calidad {{ Math.round(sample.quality * 100) }}%</span>
                                <span v-if="sample.has_crop"> · recorte guardado</span>
                            </p>
                        </div>
                        <Button
                            icon="pi pi-trash"
                            text rounded size="small"
                            severity="danger"
                            :aria-label="`Borrar la muestra del ${sampleDate(sample.created_at)}`"
                            class="ml-auto !w-7 !h-7 !p-0"
                            :loading="deletingSampleId === sample.id"
                            @click="removeSample(sample)"
                        />
                    </li>
                </ul>
            </div>

            <template #footer>
                <Button label="Cerrar" text severity="secondary" size="small" class="!text-[11px] !font-bold" @click="galleryDialogVisible = false" />
            </template>
        </Dialog>
    </div>
</template>
