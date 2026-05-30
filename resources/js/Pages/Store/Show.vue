<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';

const props = defineProps({
    product: Object,
});

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const quantity = ref(1);

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
            image_url: props.product.media?.[0]?.original_url || null,
        });
    }

    sessionStorage.setItem('store_cart', JSON.stringify(cartItems));
    router.get(route('store.cart', { slug: new URL(window.location.href).pathname.split('/')[2] }));
};
</script>

<template>
    <Head :title="product.name" />
    <StoreLayout>
        <div class="max-w-4xl mx-auto px-4 py-8">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 md:p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Image -->
                    <div class="h-64 md:h-80 flex items-center justify-center bg-gray-50 rounded-2xl">
                        <img v-if="product.image_url" :src="product.image_url" class="max-h-full max-w-full object-contain" />
                        <i v-else class="pi pi-image !text-5xl text-gray-300" />
                    </div>

                    <!-- Info -->
                    <div class="flex flex-col">
                        <p class="text-xs text-gray-400 uppercase tracking-widest font-bold m-0">{{ product.category || 'Product' }}</p>
                        <h1 class="text-2xl font-bold text-gray-900 mt-2 mb-4 m-0">{{ product.name }}</h1>

                        <p v-if="product.description" class="text-gray-600 text-sm leading-relaxed mb-6">{{ product.description }}</p>

                        <div class="mt-auto">
                            <p class="text-3xl font-bold m-0 mb-6" style="color: var(--store-primary)">{{ formatCurrency(product.price) }}</p>

                            <div class="flex items-center gap-3 mb-4">
                                <label class="text-sm text-gray-500">Quantity:</label>
                                <InputNumber v-model="quantity" :min="1" :max="99" class="w-20" :pt="{ input: { root: { class: '!rounded-xl !text-center' } } }" />
                            </div>

                            <Button label="Add to cart" icon="pi pi-shopping-cart" @click="addToCart" class="w-full !rounded-xl" style="background: var(--store-primary); border-color: var(--store-primary)" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
