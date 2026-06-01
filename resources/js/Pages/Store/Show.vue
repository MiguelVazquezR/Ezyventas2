<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';

const page = usePage();
const store = computed(() => page.props.store || {});

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
                class="flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors mb-6">
                <i class="pi pi-arrow-left !text-[10px]" />
                Volver a la tienda
            </button>

            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6 md:p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-10">
                    <!-- Image gallery -->
                    <div class="space-y-3">
                        <div class="h-72 md:h-96 flex items-center justify-center bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl relative overflow-hidden">
                            <img v-if="selectedImage" :src="selectedImage"
                                class="max-h-full max-w-full object-contain p-4" />
                            <div v-else class="flex flex-col items-center gap-2">
                                <i class="pi pi-image !text-5xl text-gray-300 dark:text-gray-600" />
                                <p class="text-xs text-gray-400 dark:text-gray-500 m-0">Sin imagen</p>
                            </div>
                        </div>
                        <!-- Thumbnails -->
                        <div v-if="product.images && product.images.length > 1" class="flex gap-2 overflow-x-auto pb-1">
                            <button v-for="(img, i) in product.images" :key="i"
                                @click="selectedImage = img"
                                class="w-16 h-16 shrink-0 rounded-xl border-2 overflow-hidden bg-gray-50 dark:bg-[#1a1a1a] transition-all"
                                :class="selectedImage === img ? 'border-gray-800 dark:border-white' : 'border-gray-100 dark:border-[#3a3a3a] opacity-70 hover:opacity-100'">
                                <img :src="img" class="w-full h-full object-contain p-1" alt="Miniatura" />
                            </button>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="flex flex-col min-w-0">
                        <div class="flex-1">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">
                                {{ product.category || 'Producto' }}
                            </p>
                            <h1 class="text-2xl md:text-3xl font-light tracking-tight text-gray-900 dark:text-white mt-2 mb-4 m-0 break-words">
                                {{ product.name }}
                            </h1>

                            <!-- Bulk indicator -->
                            <div v-if="product.is_bulk" class="flex items-center gap-1.5 mb-3">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 px-2 py-0.5 rounded-full">
                                    Venta a granel
                                </span>
                                <span v-if="product.measure_unit" class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500">
                                    por {{ product.measure_unit }}
                                </span>
                            </div>

                            <!-- Out-of-stock legend -->
                            <div v-if="product.is_out_of_stock && allowOutOfStockPurchases && outOfStockExtraMinutes"
                                class="flex items-start gap-2 mb-4 p-3 bg-amber-50 dark:bg-amber-900/10 rounded-2xl border border-amber-100 dark:border-amber-900/30">
                                <i class="pi pi-clock !text-sm text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" />
                                <div>
                                    <p class="text-xs font-medium text-amber-700 dark:text-amber-300 m-0">Producto agotado — disponible bajo pedido</p>
                                    <p class="text-[11px] text-amber-600 dark:text-amber-400 m-0 mt-0.5 leading-relaxed">
                                        Tiempo estimado de resurtimiento: {{ restockTimeLabel }}.
                                    </p>
                                </div>
                            </div>

                            <!-- Description (rendered HTML) -->
                            <div v-if="product.description"
                                class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-6 break-words overflow-hidden"
                                style="word-break: break-word; overflow-wrap: anywhere;"
                                v-html="safeDescription" />
                        </div>

                        <div class="mt-auto">
                            <!-- Price -->
                            <p class="text-3xl md:text-4xl font-light tracking-tight m-0 mb-4"
                                :style="{ color: 'var(--store-primary)' }">
                                {{ formatCurrency(product.price) }}
                                <span v-if="product.is_bulk" class="text-xs text-gray-400 dark:text-gray-500 font-normal">/ {{ product.measure_unit || 'unidad' }}</span>
                            </p>

                            <!-- Free shipping info -->
                            <p v-if="freeShippingInfo" class="text-xs text-gray-400 dark:text-gray-500 mb-4 flex items-center gap-1.5">
                                <i class="pi pi-truck !text-xs" />
                                {{ freeShippingInfo }}
                            </p>

                            <!-- Cart quantity indicator -->
                            <div v-if="cartQty > 0" class="flex items-center gap-2 mb-4 p-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                <i class="pi pi-shopping-cart !text-sm" :style="{ color: 'var(--store-primary)' }" />
                                <span class="text-xs text-gray-600 dark:text-gray-400">
                                    Ya tienes <strong class="text-gray-900 dark:text-white">{{ cartQty }}</strong> en el carrito
                                </span>
                            </div>

                            <!-- Quantity -->
                            <div class="flex flex-col gap-1.5 mb-5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">
                                    Cantidad
                                    <span v-if="product.is_bulk" class="font-normal text-gray-400">(decimales permitidos)</span>
                                </label>
                                <InputNumber fluid v-model="quantity" :min="minQty" :max="999" :step="step" showButtons class="!w-36"
                                    :pt="{
                                        input: {
                                            root: { class: '!rounded-xl !text-center !bg-white dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-900 dark:!text-white' }
                                        }
                                    }" />
                            </div>

                            <!-- Out-of-stock block -->
                            <div v-if="!canAddToCart" class="p-4 bg-red-50 dark:bg-red-900/10 rounded-2xl border border-red-100 dark:border-red-900/30 mb-4">
                                <p class="text-xs font-medium text-red-700 dark:text-red-300 m-0">
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
                                        root: { class: '!border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-400 hover:!border-gray-400 dark:hover:!border-gray-500' }
                                    }" />
                            </div>

                            <!-- Continue shopping -->
                            <button @click="goBack"
                                class="w-full mt-4 py-2.5 text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors rounded-xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#1a1a1a]">
                                Seguir comprando
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
