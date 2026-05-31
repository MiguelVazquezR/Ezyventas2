<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';

const page = usePage();
const store = computed(() => page.props.store || {});

const props = defineProps({
    product: Object,
});

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const quantity = ref(1);

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    // path: /store/{slug}/product/{id}
    return parts[2] || '';
});

const addToCart = () => {
    const cartItems = JSON.parse(sessionStorage.getItem('store_cart') || '[]');
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
        });
    }

    sessionStorage.setItem('store_cart', JSON.stringify(cartItems));
    router.get(route('store.cart', { slug: slug.value }));
};

const goBack = () => {
    router.get(route('store.home', { slug: slug.value }));
};
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
                    <!-- Image -->
                    <div class="h-72 md:h-96 flex items-center justify-center bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl relative overflow-hidden">
                        <img v-if="product.image_url" :src="product.image_url"
                            class="max-h-full max-w-full object-contain p-4" />
                        <div v-else class="flex flex-col items-center gap-2">
                            <i class="pi pi-image !text-5xl text-gray-300 dark:text-gray-600" />
                            <p class="text-xs text-gray-400 dark:text-gray-500 m-0">Sin imagen</p>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="flex flex-col justify-between">
                        <div>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">
                                {{ product.category || 'Producto' }}
                            </p>
                            <h1 class="text-2xl md:text-3xl font-light tracking-tight text-gray-900 dark:text-white mt-2 mb-4 m-0">
                                {{ product.name }}
                            </h1>

                            <p v-if="product.description" class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-6">
                                {{ product.description }}
                            </p>
                        </div>

                        <div class="mt-auto">
                            <!-- Price -->
                            <p class="text-3xl md:text-4xl font-light tracking-tight m-0 mb-6"
                                :style="{ color: 'var(--store-primary)' }">
                                {{ formatCurrency(product.price) }}
                            </p>

                            <!-- Quantity -->
                            <div class="flex flex-col gap-1.5 mb-6">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">
                                    Cantidad
                                </label>
                                <InputNumber v-model="quantity" :min="1" :max="99" class="w-24"
                                    :pt="{
                                        input: {
                                            root: { class: '!rounded-xl !text-center !bg-white dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-900 dark:!text-white' }
                                        }
                                    }" />
                            </div>

                            <!-- Add to cart -->
                            <Button label="Agregar al carrito" icon="pi pi-shopping-cart" @click="addToCart"
                                class="w-full !rounded-xl !py-3"
                                :pt="{
                                    root: { style: `background: var(--store-primary); border-color: var(--store-primary);` }
                                }" />

                            <!-- Continue shopping -->
                            <button @click="goBack"
                                class="w-full mt-3 py-2.5 text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors rounded-xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#1a1a1a]">
                                Seguir comprando
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
