<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';

const page = usePage();
const store = computed(() => page.props.store || {});
const isDarkTheme = computed(() => store.value.theme_mode === 'dark');

const props = defineProps({
    product: Object,
    freeShippingMinimum: Number,
    allowOutOfStockPurchases: Boolean,
    outOfStockExtraMinutes: Number,
});

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const quantity = ref(1);
const addingToCart = ref(false);
const goingToCart = ref(false);

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});

const getCartItems = () => {
    try {
        return JSON.parse(sessionStorage.getItem('store_cart') || '[]');
    } catch {
        return [];
    }
};

const cartQty = computed(() => {
    const item = getCartItems().find(i => i.product_id === props.product.id);
    return item ? item.quantity : 0;
});

const step = computed(() => props.product.is_bulk ? 0.1 : 1);
const minQty = computed(() => props.product.is_bulk ? 0.1 : 1);

const canAddToCart = computed(() => {
    if (!props.product.is_out_of_stock) return true;
    return props.allowOutOfStockPurchases === true;
});

const selectedImage = ref(props.product.images?.[0] || props.product.image_url || null);

const freeShippingInfo = computed(() => {
    const min = props.freeShippingMinimum;
    if (!min || min <= 0) return null;
    const productPrice = Number(props.product.price);
    const remaining = min - productPrice * quantity.value;
    if (remaining <= 0) return '¡Envío gratis! El total supera el monto mínimo.';
    return `Agrega ${formatCurrency(remaining)} más para obtener envío gratis.`;
});

const addToCartAndStay = () => {
    addingToCart.value = true;
    doAddToCart(() => {
        router.get(route('store.home', { slug: slug.value }), {}, { onFinish: () => { addingToCart.value = false; } });
    });
};

const addToCartAndGo = () => {
    goingToCart.value = true;
    doAddToCart(() => {
        router.get(route('store.cart', { slug: slug.value }), {}, { onFinish: () => { goingToCart.value = false; } });
    });
};

const doAddToCart = (onDone) => {
    const cartItems = getCartItems();
    const existing = cartItems.find(i => i.product_id === props.product.id);

    if (existing) {
        existing.quantity += quantity.value;
    } else {
        cartItems.push({
            product_id: props.product.id,
            name: props.product.name,
            price: props.product.price,
            quantity: quantity.value,
            image_url: props.product.image_url || null,
            is_bulk: props.product.is_bulk || false,
            measure_unit: props.product.measure_unit || '',
        });
    }

    sessionStorage.setItem('store_cart', JSON.stringify(cartItems));
    onDone();
};

const goBack = () => {
    router.get(route('store.home', { slug: slug.value }));
};

// Safely render HTML description
const safeDescription = computed(() => {
    const desc = props.product.description;
    if (!desc) return '';
    return desc;
});

// Convert total minutes to a human-readable days/hours/minutes string
const restockTimeLabel = computed(() => {
    const t = Math.max(0, parseInt(props.outOfStockExtraMinutes) || 0);
    if (t === 0) return '';
    const days = Math.floor(t / 1440);
    const hours = Math.floor((t % 1440) / 60);
    const minutes = t % 60;
    const parts = [];
    if (days > 0) parts.push(`${days} ${days === 1 ? 'día' : 'días'}`);
    if (hours > 0) parts.push(`${hours} ${hours === 1 ? 'hora' : 'horas'}`);
    if (minutes > 0) parts.push(`${minutes} ${minutes === 1 ? 'minuto' : 'minutos'}`);
    return parts.join(', ');
});
</script>

<template>
    <Head :title="product.name" />
    <StoreLayout>
        <div class="max-w-5xl mx-auto px-4 md:px-6 py-8">
            <!-- Back -->
            <button @click="goBack"
                :class="[
                    'flex items-center gap-1.5 text-xs font-medium transition-colors mb-6',
                    isDarkTheme ? 'text-gray-500 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'
                ]">
                <i class="pi pi-arrow-left !text-[10px]" />
                Volver a la tienda
            </button>

            <div :class="[
                'rounded-3xl border p-6 md:p-8',
                isDarkTheme ? 'bg-[#252525] border-[#2a2a2a]' : 'bg-white border-[#e8e3dc]'
            ]">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-10">
                    <!-- Image gallery -->
                    <div class="space-y-3">
                        <div :class="[
                            'h-72 md:h-96 flex items-center justify-center rounded-2xl relative overflow-hidden',
                            isDarkTheme ? 'bg-[#1a1a1a]' : 'bg-[#f5f2ed]'
                        ]">
                            <img v-if="selectedImage" :src="selectedImage"
                                class="max-h-full max-w-full object-contain p-4" />
                            <div v-else class="flex flex-col items-center gap-2">
                                <i class="pi pi-image !text-5xl"
                                    :class="isDarkTheme ? 'text-gray-600' : 'text-gray-300'" />
                                <p class="text-xs m-0"
                                    :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">Sin imagen</p>
                            </div>
                        </div>
                        <!-- Thumbnails -->
                        <div v-if="product.images && product.images.length > 1" class="flex gap-2 overflow-x-auto pb-1">
                            <button v-for="(img, i) in product.images" :key="i"
                                @click="selectedImage = img"
                                :class="[
                                    'w-16 h-16 shrink-0 rounded-xl border-2 overflow-hidden transition-all',
                                    isDarkTheme ? 'bg-[#1a1a1a]' : 'bg-gray-50',
                                    selectedImage === img
                                        ? (isDarkTheme ? 'border-white' : 'border-gray-800')
                                        : (isDarkTheme ? 'border-[#3a3a3a] opacity-70 hover:opacity-100' : 'border-gray-100 opacity-70 hover:opacity-100')
                                ]">
                                <img :src="img" class="w-full h-full object-contain p-1" alt="Miniatura" />
                            </button>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="flex flex-col min-w-0">
                        <div class="flex-1">
                            <p class="text-[10px] uppercase tracking-widest font-bold m-0"
                                :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                                {{ product.category || 'Producto' }}
                            </p>
                            <h1 class="text-2xl md:text-3xl font-light tracking-tight mt-2 mb-4 m-0 break-words"
                                :class="isDarkTheme ? 'text-white' : 'text-gray-900'">
                                {{ product.name }}
                            </h1>

                            <!-- Bulk indicator -->
                            <div v-if="product.is_bulk" class="flex items-center gap-1.5 mb-3">
                                <span :class="[
                                    'text-[10px] uppercase tracking-widest font-bold px-2 py-0.5 rounded-full',
                                    isDarkTheme ? 'text-amber-400 bg-amber-900/20' : 'text-amber-600 bg-amber-50'
                                ]">
                                    Venta a granel
                                </span>
                                <span v-if="product.measure_unit" class="text-[10px] uppercase tracking-widest font-bold m-0"
                                    :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                                    por {{ product.measure_unit }}
                                </span>
                            </div>

                            <!-- Out-of-stock legend -->
                            <div v-if="product.is_out_of_stock && allowOutOfStockPurchases && outOfStockExtraMinutes"
                                :class="[
                                    'flex items-start gap-2 mb-4 p-3 rounded-2xl border',
                                    isDarkTheme ? 'bg-amber-900/10 border-amber-900/30' : 'bg-amber-50 border-amber-100'
                                ]">
                                <i class="pi pi-clock !text-sm mt-0.5 shrink-0"
                                    :class="isDarkTheme ? 'text-amber-400' : 'text-amber-600'" />
                                <div>
                                    <p class="text-xs font-medium m-0"
                                        :class="isDarkTheme ? 'text-amber-300' : 'text-amber-700'">Producto agotado — disponible bajo pedido</p>
                                    <p class="text-[11px] m-0 mt-0.5 leading-relaxed"
                                        :class="isDarkTheme ? 'text-amber-400' : 'text-amber-600'">
                                        Tiempo estimado de resurtimiento: {{ restockTimeLabel }}.
                                    </p>
                                </div>
                            </div>

                            <!-- Description (rendered HTML) -->
                            <div v-if="product.description"
                                :class="[
                                    'text-sm leading-relaxed mb-6 break-words overflow-hidden',
                                    isDarkTheme ? 'text-gray-400' : 'text-gray-500'
                                ]"
                                style="word-break: break-word; overflow-wrap: anywhere;"
                                v-html="safeDescription" />
                        </div>

                        <div class="mt-auto">
                            <!-- Price -->
                            <p class="text-3xl md:text-4xl font-light tracking-tight m-0 mb-4"
                                :style="{ color: 'var(--store-primary)' }">
                                {{ formatCurrency(product.price) }}
                                <span v-if="product.is_bulk" class="text-xs font-normal"
                                    :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">/ {{ product.measure_unit || 'unidad' }}</span>
                            </p>

                            <!-- Free shipping info -->
                            <p v-if="freeShippingInfo" class="text-xs mb-4 flex items-center gap-1.5"
                                :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                                <i class="pi pi-truck !text-xs" />
                                {{ freeShippingInfo }}
                            </p>

                            <!-- Cart quantity indicator -->
                            <div v-if="cartQty > 0" :class="[
                                'flex items-center gap-2 mb-4 p-3 rounded-2xl',
                                isDarkTheme ? 'bg-[#1a1a1a]' : 'bg-gray-50'
                            ]">
                                <i class="pi pi-shopping-cart !text-sm" :style="{ color: 'var(--store-primary)' }" />
                                <span class="text-xs"
                                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-600'">
                                    Ya tienes <strong :class="isDarkTheme ? 'text-white' : 'text-gray-900'">{{ cartQty }}</strong> en el carrito
                                </span>
                            </div>

                            <!-- Quantity -->
                            <div class="flex flex-col gap-1.5 mb-5">
                                <label class="text-[10px] uppercase tracking-widest font-bold m-0"
                                    :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                                    Cantidad
                                    <span v-if="product.is_bulk" class="font-normal text-gray-400">(decimales permitidos)</span>
                                </label>
                                <InputNumber fluid v-model="quantity" :min="minQty" :max="999" :step="step" showButtons class="!w-36"
                                    :pt="{
                                        input: {
                                            root: {
                                                class: [
                                                    '!rounded-xl !text-center',
                                                    isDarkTheme
                                                        ? '!bg-[#1a1a1a] !border-[#3a3a3a] !text-white'
                                                        : '!bg-white !border-gray-100 !text-gray-900'
                                                ].join(' ')
                                            }
                                        }
                                    }" />
                            </div>

                            <!-- Out-of-stock block -->
                            <div v-if="!canAddToCart" :class="[
                                'p-4 rounded-2xl border mb-4',
                                isDarkTheme ? 'bg-red-900/10 border-red-900/30' : 'bg-red-50 border-red-100'
                            ]">
                                <p class="text-xs font-medium m-0"
                                    :class="isDarkTheme ? 'text-red-300' : 'text-red-700'">
                                    <i class="pi pi-info-circle !text-xs mr-1" />
                                    Este producto está agotado y la tienda no permite comprar productos sin stock.
                                </p>
                            </div>

                            <!-- Buttons -->
                            <div v-if="canAddToCart" class="space-y-3">
                                <Button label="Guardar en carrito e ir a pagar" icon="pi pi-shopping-cart"
                                    :loading="goingToCart" @click="addToCartAndGo"
                                    class="w-full !rounded-xl !py-3"
                                    :pt="{ root: { style: `background: var(--store-primary); border-color: var(--store-primary);` } }" />
                                <Button label="Guardar en carrito y seguir comprando" icon="pi pi-cart-plus"
                                    :loading="addingToCart" outlined @click="addToCartAndStay"
                                    class="w-full !rounded-xl !py-3"
                                    :pt="{
                                        root: {
                                            class: [
                                                '!border-gray-200 !text-gray-600 hover:!border-gray-400',
                                                isDarkTheme ? '!border-[#3a3a3a] !text-gray-400 hover:!border-gray-500' : ''
                                            ].filter(Boolean).join(' ')
                                        }
                                    }" />
                            </div>

                            <!-- Continue shopping -->
                            <button @click="goBack"
                                :class="[
                                    'w-full mt-4 py-2.5 text-xs font-medium transition-colors rounded-xl border',
                                    isDarkTheme
                                        ? 'text-gray-500 hover:text-gray-300 border-[#3a3a3a] bg-[#1a1a1a]'
                                        : 'text-gray-400 hover:text-gray-600 border-gray-100 bg-white'
                                ]">
                                Seguir comprando
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
