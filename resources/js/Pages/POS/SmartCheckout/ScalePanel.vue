<script setup>
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { formatMoney, formatWeight } from '@/Composables/smartCheckout/scaleMath.js';
import { SCALE_DRIVER_KINDS } from '@/Composables/smartCheckout/drivers/index.js';
import { isWeighableProduct, normalizeProductUnit, useScaleSession } from '@/Composables/smartCheckout/useScaleSession';

const props = defineProps({
    visible: { type: Boolean, default: false },
    scale: { type: Object, default: () => ({ enabled: false, can_use: false, can_configure: false, settings: null }) },
    product: { type: Object, default: null },
    products: { type: Array, default: () => [] },
    loadingProducts: { type: Boolean, default: false },
    userId: { type: [Number, String], default: null },
    branchId: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:visible', 'add', 'open-settings', 'search']);

const toast = useToast();
const searchTerm = ref('');
const manualWeight = ref(null);
const showSimulator = ref(false);

const scale = useScaleSession({
    kind: props.scale?.settings?.driver || 'simulated',
    settings: props.scale?.settings || null,
    userId: props.userId,
    branchId: props.branchId,
});

const isOpen = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value),
});

const selectedProduct = computed(() => props.product);
const productUnit = computed(() => normalizeProductUnit(selectedProduct.value?.measure_unit));
const line = computed(() => (selectedProduct.value ? scale.lineFor(selectedProduct.value) : { ok: false }));
const canAdd = computed(() => Boolean(selectedProduct.value) && line.value.ok && scale.isStable.value && scale.hasWeight.value);

const stabilityLabel = computed(() => (scale.isStable.value ? 'Peso estable' : 'Peso inestable'));
const stabilityIcon = computed(() => (scale.isStable.value ? 'pi pi-check-circle' : 'pi pi-exclamation-circle'));
const stabilityClass = computed(() =>
    scale.isStable.value
        ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/30'
        : 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/30',
);

const driverOptions = SCALE_DRIVER_KINDS;

const driverKind = computed({
    get: () => scale.kind.value,
    set: (value) => {
        if (value) void scale.setKind(value);
    },
});

const webserialSupported = computed(() => typeof navigator !== 'undefined' && 'serial' in navigator);

const weightDisplay = computed(() => formatWeight(scale.reading.value.weight, scale.reading.value.unit));

const visibleProducts = computed(() => props.products.filter((item) => isWeighableProduct(item)));

watch(
    () => props.scale?.settings,
    (next) => {
        // The driver is a per-terminal choice (localStorage), so the business
        // default is applied by useScaleSession only when the terminal has none.
        scale.settings.value = next || null;
    },
);

watch(
    () => props.visible,
    async (open) => {
        manualWeight.value = null;
        searchTerm.value = '';
        if (!open) {
            await scale.disconnect();
            return;
        }
        if (scale.kind.value !== 'manual' && !scale.connected.value) {
            await scale.connect();
        }
    },
);

function confirm() {
    if (!selectedProduct.value) {
        toast.add({ severity: 'warn', summary: 'Falta el producto', detail: 'Elige un producto a granel para continuar.', life: 3000 });
        return;
    }
    if (!line.value.ok) {
        toast.add({ severity: 'warn', summary: 'Peso no válido', detail: line.value.error || 'Coloca el producto en la báscula.', life: 3000 });
        return;
    }
    if (!scale.isStable.value) {
        toast.add({ severity: 'warn', summary: 'Peso inestable', detail: 'Espera a que la lectura se estabilice.', life: 3000 });
        return;
    }
    emit('add', {
        product: selectedProduct.value,
        quantity: line.value.quantity,
        unit: line.value.productUnit,
        weight: scale.reading.value.weight,
        weightUnit: scale.reading.value.unit,
    });
    isOpen.value = false;
}

async function applyManual() {
    const value = manualWeight.value;
    if (scale.submitManual(value, productUnit.value)) {
        manualWeight.value = null;
    }
}

function pickProduct(item) {
    emit('search', '');
    emit('add', { product: item, quantity: null, pick: true });
}

let searchTimer = null;
watch(searchTerm, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => emit('search', value), 300);
});
</script>

<template>
    <Dialog
        v-model:visible="isOpen"
        modal
        :style="{ width: '580px', maxWidth: '96vw' }"
        :pt="{
            root: { class: '!rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a] !bg-white dark:!bg-[#232323] !shadow-2xl' },
            header: { class: '!px-6 !pt-6 !pb-2 !border-none' },
            content: { class: '!px-6 !pt-2 !pb-6' },
            closeButton: { class: '!text-gray-400 hover:!text-gray-600 dark:hover:!text-gray-200' },
        }"
        :draggable="false"
        @keydown.enter.prevent="confirm"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl flex items-center justify-center bg-primary-500/10 text-primary-500">
                    <i class="pi pi-box text-lg" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Báscula · granel</p>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white m-0 truncate">
                        {{ selectedProduct ? selectedProduct.name : 'Elige un producto a granel' }}
                    </h3>
                </div>
            </div>
        </template>

        <!-- Estado de la báscula -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11px] font-bold"
                :class="scale.connected.value ? 'bg-gray-50 text-gray-600 border-gray-200 dark:bg-[#1a1a1a] dark:text-gray-300 dark:border-[#3a3a3a]' : 'bg-red-50 text-red-600 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/30'"
            >
                <span class="w-1.5 h-1.5 rounded-full" :class="scale.connected.value ? 'bg-emerald-500' : 'bg-red-500'"></span>
                {{ scale.connected.value ? 'Conectada' : 'Desconectada' }}
            </span>

            <!-- Tipo de conexión: se recuerda por terminal -->
            <Select
                v-model="driverKind"
                :options="driverOptions"
                optionLabel="label"
                optionValue="value"
                size="small"
                aria-label="Tipo de báscula"
                class="!text-[11px]"
                :pt="{ root: { class: '!rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-xs' } }"
            />

            <div class="flex items-center gap-1.5 ml-auto">
                <Button
                    v-if="!scale.connected.value && scale.kind.value !== 'manual'"
                    icon="pi pi-refresh"
                    label="Conectar"
                    size="small"
                    :loading="scale.connecting.value"
                    class="!rounded-xl !text-[11px] !font-bold"
                    @click="scale.connect()"
                />
                <Button
                    v-if="props.scale.can_configure"
                    icon="pi pi-cog"
                    text
                    rounded
                    size="small"
                    aria-label="Configurar báscula"
                    class="!text-gray-500 dark:!text-gray-300"
                    @click="emit('open-settings')"
                />
            </div>
        </div>

        <Message v-if="scale.error.value" severity="error" variant="simple" size="small" class="mb-4">{{ scale.error.value }}</Message>

        <Message
            v-if="scale.kind.value === 'webserial' && !webserialSupported"
            severity="warn"
            variant="simple"
            size="small"
            class="mb-4"
        >
            Este navegador no admite Web Serial. Usa Chrome o Edge actualizado sobre HTTPS, o cambia a captura manual.
        </Message>

        <!-- Selector de producto cuando el panel se abre desde "Granel" -->
        <div v-if="!selectedProduct" class="space-y-3">
            <InputText
                v-model="searchTerm"
                placeholder="Buscar producto a granel..."
                class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]"
                @keydown.enter.prevent.stop
            />
            <div class="max-h-72 overflow-y-auto pr-1 space-y-2">
                <div v-if="props.loadingProducts" class="flex items-center justify-center py-8 text-gray-400">
                    <i class="pi pi-spin pi-spinner mr-2"></i> Cargando productos...
                </div>
                <template v-else-if="visibleProducts.length">
                    <button
                        v-for="item in visibleProducts"
                        :key="item.id"
                        type="button"
                        class="w-full flex items-center gap-3 p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-primary-500 text-left transition-colors"
                        @click="pickProduct(item)"
                    >
                        <img :src="item.image" :alt="item.name" class="w-10 h-10 rounded-xl object-cover" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-800 dark:text-white truncate">{{ item.name }}</span>
                            <span class="block text-[11px] text-gray-500 dark:text-gray-400">
                                {{ formatMoney(item.price) }} / {{ item.measure_unit || 'kg' }}
                            </span>
                        </span>
                        <i class="pi pi-chevron-right text-gray-300" aria-hidden="true"></i>
                    </button>
                </template>
                <p v-else class="text-center text-sm text-gray-400 py-8 m-0">
                    No hay productos a granel con unidad de peso. Marca el producto como "granel" y usa Kg o Grs.
                </p>
            </div>
        </div>

        <!-- Pesaje -->
        <div v-else class="space-y-4">
            <div class="rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Peso en báscula</p>
                        <p class="text-4xl font-bold text-gray-900 dark:text-white m-0 tabular-nums" aria-live="polite">
                            {{ weightDisplay }}
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11px] font-bold" :class="stabilityClass">
                        <i :class="stabilityIcon" aria-hidden="true"></i>
                        {{ stabilityLabel }}
                    </span>
                </div>

                <div class="flex items-center gap-2 mt-4">
                    <Button
                        icon="pi pi-refresh"
                        label="Tara"
                        size="small"
                        :disabled="!scale.connected.value"
                        class="!rounded-xl !text-[11px] !font-bold !bg-white dark:!bg-[#232323] !text-gray-700 dark:!text-gray-200 !border-gray-200 dark:!border-[#3a3a3a]"
                        @click="scale.tare()"
                    />
                    <span class="text-[11px] text-gray-400">
                        {{ selectedProduct.measure_unit ? 'Unidad: ' + selectedProduct.measure_unit : 'Unidad: kg' }}
                    </span>
                </div>
            </div>

            <!-- Captura manual -->
            <div v-if="scale.kind.value === 'manual'" class="flex items-end gap-2">
                <div class="flex-1">
                    <label for="scale-manual-weight" class="block text-[10px] uppercase tracking-widest font-bold text-gray-500 mb-1">
                        Peso manual ({{ productUnit }})
                    </label>
                    <InputNumber
                        id="scale-manual-weight"
                        v-model="manualWeight"
                        :min="0"
                        :maxFractionDigits="3"
                        :step="0.05"
                        :minFractionDigits="0"
                        placeholder="0.000"
                        class="w-full"
                        :inputClass="'!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]'"
                        @keydown.enter.prevent.stop="applyManual"
                    />
                </div>
                <Button
                    label="Capturar"
                    icon="pi pi-check"
                    class="!rounded-xl !font-bold"
                    :disabled="!manualWeight"
                    @click="applyManual"
                />
            </div>

            <!-- Simulador (solo desarrollo: la especificación lo exige para probar sin hardware) -->
            <div v-if="scale.kind.value === 'simulated'" class="rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a] p-3">
                <button
                    type="button"
                    class="flex w-full items-center justify-between text-[11px] font-bold text-gray-500 dark:text-gray-400"
                    @click="showSimulator = !showSimulator"
                >
                    <span class="inline-flex items-center gap-1.5"><i class="pi pi-sliders-h" aria-hidden="true"></i> Simulador (desarrollo)</span>
                    <i :class="showSimulator ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" aria-hidden="true"></i>
                </button>
                <div v-if="showSimulator" class="grid grid-cols-3 gap-2 mt-3">
                    <Button label="0.250" size="small" outlined class="!rounded-xl" @click="scale.simulate(0.25, 'kg', true)" />
                    <Button label="0.750" size="small" outlined class="!rounded-xl" @click="scale.simulate(0.75, 'kg', true)" />
                    <Button label="1.500" size="small" outlined class="!rounded-xl" @click="scale.simulate(1.5, 'kg', true)" />
                    <Button label="Inestable" size="small" severity="warn" outlined class="!rounded-xl col-span-2" @click="scale.simulate(scale.reading.value.weight || 0.5, 'kg', false)" />
                    <Button label="Cero" size="small" text class="!rounded-xl" @click="scale.simulate(0, 'kg', true)" />
                </div>
            </div>

            <!-- Resumen -->
            <div class="rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 space-y-1.5">
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Precio por {{ selectedProduct.measure_unit || 'kg' }}</span>
                    <span class="tabular-nums">{{ formatMoney(selectedProduct.price) }}</span>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Cantidad</span>
                    <span class="tabular-nums">{{ line.ok ? line.displayQuantity : '—' }}</span>
                </div>
                <div class="flex items-center justify-between pt-1.5 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <span class="text-[11px] uppercase tracking-widest font-bold text-gray-500">Total</span>
                    <span class="text-2xl font-bold text-primary-500 tabular-nums">{{ formatMoney(line.ok ? line.total : 0) }}</span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    class="!rounded-xl !font-bold"
                    @click="isOpen = false"
                />
                <Button
                    :label="line.ok ? `Agregar ${formatMoney(line.total)}` : 'Agregar al carrito'"
                    icon="pi pi-shopping-cart"
                    class="!rounded-xl !font-bold flex-1"
                    :disabled="!canAdd"
                    @click="confirm"
                />
            </div>
            <p class="text-[11px] text-gray-400 text-center m-0">
                Enter para agregar · espera el peso estable para continuar
            </p>
        </div>
    </Dialog>
</template>
