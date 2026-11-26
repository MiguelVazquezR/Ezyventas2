<script setup>
import { computed } from 'vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    priceRange: { type: Object, default: () => ({ min: 0, max: 0 }) },
    activeCategory: { type: String, default: '' },
    minPrice: { type: String, default: '' },
    maxPrice: { type: String, default: '' },
    totalProducts: { type: Number, default: 0 },
    dark: { type: Boolean, default: false },
});

const emit = defineEmits(['select-category', 'update:minPrice', 'update:maxPrice', 'apply', 'clear']);

const bounds = computed(() => {
    const min = Math.max(0, Math.floor(Number(props.priceRange?.min ?? 0)));
    const max = Math.ceil(Number(props.priceRange?.max ?? 0));
    return { min, max: max > min ? max : min + 1 };
});

const hasPriceRange = computed(() => Number(props.priceRange?.max ?? 0) > Number(props.priceRange?.min ?? 0));

const clamp = (value) => Math.min(Math.max(value, bounds.value.min), bounds.value.max);

const currentMin = computed(() => clamp(Number(props.minPrice) || bounds.value.min));
const currentMax = computed(() => clamp(Number(props.maxPrice) || bounds.value.max));

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 0,
}).format(value || 0);

const setRange = (min, max) => {
    const low = clamp(Number(min) || bounds.value.min);
    const high = clamp(Number(max) || bounds.value.max);
    emit('update:minPrice', low > bounds.value.min ? String(Math.round(low)) : '');
    emit('update:maxPrice', high < bounds.value.max ? String(Math.round(high)) : '');
};

const onSliderUpdate = (values) => setRange(values?.[0], values?.[1]);
const onSliderChange = () => emit('apply');
const onMinUpdate = (value) => { setRange(value, currentMax.value); emit('apply'); };
const onMaxUpdate = (value) => { setRange(currentMin.value, value); emit('apply'); };

const labelClass = computed(() => (props.dark ? 'text-gray-500' : 'text-gray-400'));
const rowIdleClass = computed(() => (props.dark
    ? 'text-gray-400 hover:bg-[#2b2b2b] hover:text-gray-200'
    : 'text-gray-500 hover:bg-[#f7f4ef] hover:text-gray-800'));
const countIdleClass = computed(() => (props.dark ? 'text-gray-600' : 'text-gray-300'));

const inputPt = computed(() => {
    const inputClass = [
        'w-full min-w-0 !rounded-xl !border !py-2 !px-3 !text-xs !shadow-none !transition-colors',
        props.dark
            ? '!bg-[#1a1a1a] !border-[#3a3a3a] !text-gray-200 focus:!border-gray-500'
            : '!bg-[#faf8f5] !border-[#eee9e2] !text-gray-800 focus:!border-gray-400',
    ].join(' ');

    return {
        input: { class: inputClass },
        pcInputText: { root: { class: inputClass } },
    };
});
</script>

<template>
    <div class="flex flex-col gap-7">
        <!-- Categories -->
        <section v-if="categories.length > 0">
            <div class="mb-3 flex items-center justify-between">
                <span class="text-[10px] font-bold uppercase tracking-[0.2em]" :class="labelClass">Categorías</span>
                <span class="text-[10px] font-medium tabular-nums" :class="countIdleClass">{{ categories.length }}</span>
            </div>

            <div class="-mr-2 max-h-[42vh] space-y-0.5 overflow-y-auto pr-2 lg:max-h-[calc(100vh-420px)]">
                <button type="button"
                    class="flex w-full items-center justify-between gap-4 rounded-xl px-3 py-2 text-left text-xs transition-colors"
                    :class="!activeCategory ? '' : rowIdleClass"
                    :style="!activeCategory ? { background: 'var(--store-primary-light)', color: 'var(--store-primary)' } : {}"
                    @click="emit('select-category', '')">
                    <span class="truncate font-medium">Todas las categorías</span>
                    <span class="shrink-0 text-[10px] font-semibold tabular-nums">{{ totalProducts }}</span>
                </button>

                <button v-for="category in categories" :key="category.id" type="button"
                    class="flex w-full items-center justify-between gap-4 rounded-xl px-3 py-2 text-left text-xs transition-colors"
                    :class="activeCategory === String(category.id) ? '' : rowIdleClass"
                    :style="activeCategory === String(category.id) ? { background: 'var(--store-primary-light)', color: 'var(--store-primary)' } : {}"
                    @click="emit('select-category', String(category.id))">
                    <span class="truncate font-medium">{{ category.name }}</span>
                    <span class="shrink-0 text-[10px] font-semibold tabular-nums">{{ category.products_count ?? 0 }}</span>
                </button>
            </div>
        </section>

        <!-- Price range -->
        <section v-if="hasPriceRange">
            <div class="mb-3 flex items-center justify-between">
                <span class="text-[10px] font-bold uppercase tracking-[0.2em]" :class="labelClass">Rango de precio</span>
            </div>

            <Slider :modelValue="[currentMin, currentMax]" range :min="bounds.min" :max="bounds.max"
                @update:modelValue="onSliderUpdate" @change="onSliderChange"
                :pt="{
                    root: { class: ['!h-1', dark ? '!bg-[#3a3a3a]' : '!bg-[#eeeae4]'].join(' ') },
                    range: { class: '!bg-[var(--store-primary)]' },
                    handle: { class: '!h-3.5 !w-3.5 !border-2 !border-[var(--store-primary)] !bg-white !shadow-sm' },
                    startHandler: { class: '!h-3.5 !w-3.5 !border-2 !border-[var(--store-primary)] !bg-white !shadow-sm' },
                    endHandler: { class: '!h-3.5 !w-3.5 !border-2 !border-[var(--store-primary)] !bg-white !shadow-sm' },
                }" />

            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="flex flex-col gap-1">
                    <label class="text-[9px] font-bold uppercase tracking-[0.16em] m-0" :class="labelClass">Mínimo</label>
                    <InputNumber :modelValue="currentMin" :min="bounds.min" :max="currentMax" :useGrouping="false"
                        @update:modelValue="onMinUpdate" :pt="inputPt" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-[9px] font-bold uppercase tracking-[0.16em] m-0" :class="labelClass">Máximo</label>
                    <InputNumber :modelValue="currentMax" :min="currentMin" :max="bounds.max" :useGrouping="false"
                        @update:modelValue="onMaxUpdate" :pt="inputPt" />
                </div>
            </div>

            <div class="mt-2 flex items-center justify-between text-[10px] font-medium" :class="countIdleClass">
                <span>{{ formatCurrency(bounds.min) }}</span>
                <span>{{ formatCurrency(bounds.max) }}</span>
            </div>
        </section>

        <!-- Reset -->
        <Button type="button" severity="secondary" text
            class="!w-full !justify-center !rounded-xl !text-[11px] !font-semibold"
            :pt="{ root: { class: dark ? '!text-gray-400 hover:!bg-[#2b2b2b]' : '!text-gray-500 hover:!bg-[#f7f4ef]' } }"
            @click="emit('clear')">
            <i class="pi pi-filter-slash !text-[10px] mr-2" />
            Limpiar filtros
        </Button>
    </div>
</template>
