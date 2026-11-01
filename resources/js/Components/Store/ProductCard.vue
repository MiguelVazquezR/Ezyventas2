<script setup>
import { computed } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    dark: { type: Boolean, default: false },
    allowOutOfStock: { type: Boolean, default: false },
    cartQuantity: { type: Number, default: 0 },
});

const emit = defineEmits(['open', 'add']);

const price = computed(() => Number(props.product.online_price || props.product.selling_price || 0));
const stock = computed(() => Number(props.product.available_stock ?? 0));
const isOutOfStock = computed(() => stock.value <= 0);
const canPurchase = computed(() => !isOutOfStock.value || props.allowOutOfStock);
const imageUrl = computed(() => (props.product.media?.length ? props.product.media[0].original_url : null));

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
const formattedPrice = computed(() => formatCurrency(price.value).replace(/\.00$/, ''));

const unitLabel = computed(() => {
    if (!props.product.is_bulk || !props.product.measure_unit) return '';
    return `/ ${props.product.measure_unit}`;
});

const stockLabel = computed(() => {
    if (!isOutOfStock.value) {
        return stock.value <= 5 ? `${stock.value} ${stock.value === 1 ? 'disponible' : 'disponibles'}` : 'Disponible';
    }
    return props.allowOutOfStock ? 'Bajo pedido' : 'Sin existencias';
});

const stockTone = computed(() => {
    if (!isOutOfStock.value) {
        return stock.value <= 5
            ? { dot: 'bg-amber-500', text: 'text-amber-600' }
            : { dot: 'bg-emerald-500', text: props.dark ? 'text-gray-500' : 'text-gray-400' };
    }
    return props.allowOutOfStock
        ? { dot: 'bg-amber-500', text: 'text-amber-600' }
        : { dot: 'bg-red-500', text: 'text-red-500' };
});
</script>

<template>
    <article :class="[
        'group relative flex cursor-pointer flex-col overflow-hidden rounded-3xl border transition-all duration-300 ease-out',
        dark
            ? 'border-[#2f2f2f] bg-[#232323] hover:-translate-y-1 hover:border-[#494949] hover:shadow-[0_28px_48px_-30px_rgba(0,0,0,0.95)]'
            : 'border-[#efeae2] bg-white hover:-translate-y-1 hover:border-[#ddd5c9] hover:shadow-[0_28px_50px_-32px_rgba(31,26,20,0.45)]'
    ]" @click="emit('open')">
        <!-- Product image -->
        <div :class="[
            'relative aspect-square w-full overflow-hidden border-b',
            dark
                ? (imageUrl ? 'border-[#2c2c2c] bg-[#f6f6f7]' : 'border-[#2c2c2c] bg-[#1e1e1e]')
                : 'border-[#f4f0ea] bg-white'
        ]">
            <img v-if="imageUrl" :src="imageUrl" :alt="product.name" loading="lazy"
                :class="[
                    'absolute inset-0 h-full w-full object-contain p-4 transition-transform duration-700 ease-out md:p-5 group-hover:scale-[1.05]',
                    { 'opacity-40 grayscale': isOutOfStock && !allowOutOfStock }
                ]" />
            <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2"
                :class="dark ? 'text-gray-600' : 'text-gray-300'">
                <i class="pi pi-box !text-2xl" />
                <span class="text-[9px] font-bold uppercase tracking-[0.2em]">Sin imagen</span>
            </div>

            <!-- Stock state -->
            <div v-if="isOutOfStock" class="absolute left-3 top-3">
                <span :class="[
                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9px] font-bold uppercase tracking-[0.14em] backdrop-blur',
                    allowOutOfStock
                        ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                        : dark
                            ? 'bg-black/55 text-gray-200'
                            : 'bg-gray-900/80 text-white'
                ]">
                    <span class="h-1.5 w-1.5 rounded-full" :class="allowOutOfStock ? 'bg-amber-500' : 'bg-red-400'" />
                    {{ allowOutOfStock ? 'Bajo pedido' : 'Agotado' }}
                </span>
            </div>

            <!-- Quantity in cart -->
            <span v-if="cartQuantity > 0"
                class="absolute right-3 top-3 flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-[10px] font-bold text-white shadow-sm"
                :style="{ background: 'var(--store-primary)' }">
                {{ cartQuantity }}
            </span>

            <!-- Quick add -->
            <button v-if="canPurchase" type="button"
                class="absolute bottom-3 right-3 flex h-9 w-9 items-center justify-center rounded-full text-white shadow-lg transition-all duration-300 hover:scale-105 active:scale-95 md:translate-y-1 md:opacity-0 md:group-hover:translate-y-0 md:group-hover:opacity-100"
                :style="{ background: 'var(--store-primary)' }"
                :aria-label="`Agregar ${product.name} al carrito`" title="Agregar al carrito"
                @click.stop="emit('add')">
                <i class="pi pi-plus !text-xs" />
            </button>
        </div>

        <!-- Product info -->
        <div class="flex flex-1 flex-col p-3.5 md:p-4">
            <p class="m-0 mb-1 truncate text-[9px] font-bold uppercase tracking-[0.18em]"
                :class="dark ? 'text-gray-600' : 'text-gray-400'">
                {{ product.category?.name || 'General' }}
            </p>

            <h3 :class="[
                'm-0 min-h-[36px] text-[13px] font-medium leading-snug line-clamp-2',
                dark ? 'text-gray-100' : 'text-gray-800'
            ]">
                {{ product.name }}
            </h3>

            <div class="mt-2.5 flex items-baseline gap-1">
                <p class="m-0 text-base font-semibold tracking-tight md:text-lg"
                    :style="{ color: 'var(--store-primary)' }">
                    {{ formattedPrice }}
                </p>
                <span v-if="unitLabel" class="text-[10px] font-medium"
                    :class="dark ? 'text-gray-600' : 'text-gray-400'">
                    {{ unitLabel }}
                </span>
            </div>

            <div class="mt-2.5 flex items-center gap-1.5 border-t pt-2"
                :class="dark ? 'border-[#2c2c2c]' : 'border-[#f4f0ea]'">
                <span class="h-1.5 w-1.5 rounded-full" :class="stockTone.dot" />
                <span class="text-[10px] font-medium" :class="stockTone.text">{{ stockLabel }}</span>
            </div>
        </div>
    </article>
</template>
