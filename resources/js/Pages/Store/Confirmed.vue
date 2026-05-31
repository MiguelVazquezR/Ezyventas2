<script setup>
import { Head } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import Button from 'primevue/button';

const props = defineProps({
    order: Object,
});

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};
</script>

<template>
    <Head title="Pedido confirmado" />
    <StoreLayout>
        <div class="max-w-2xl mx-auto px-4 py-12">
            <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center">
                <!-- Success icon -->
                <div class="w-16 h-16 rounded-full bg-green-100 text-green-600 flex items-center justify-center mx-auto mb-6">
                    <i class="pi pi-check !text-3xl" />
                </div>

                <h1 class="text-2xl font-bold text-gray-900 m-0 mb-2">Pedido confirmado</h1>
                <p class="text-gray-500 mb-6">¡Gracias por tu pedido! Aquí está tu número de pedido:</p>

                <div class="bg-gray-50 rounded-2xl p-6 mb-8 inline-block">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Número de pedido</p>
                    <p class="text-3xl font-mono font-bold m-0" style="color: var(--store-primary)">{{ order.formatted_order_number }}</p>
                </div>

                <!-- Order details -->
                <div class="text-left bg-gray-50 rounded-2xl p-6 mb-8 space-y-2">
                    <h2 class="text-sm font-semibold text-gray-700 m-0 mb-3">Resumen del pedido</h2>
                    <div v-for="item in order.items" :key="item.id" class="flex justify-between text-sm">
                        <span class="text-gray-600">{{ item.quantity }}x {{ item.product_name }}</span>
                        <span class="font-mono">{{ formatCurrency(item.subtotal) }}</span>
                    </div>
                    <div class="border-t border-gray-200 pt-2 mt-2 flex justify-between font-semibold text-sm">
                        <span>Total</span>
                        <span class="font-mono">{{ formatCurrency(order.total) }}</span>
                    </div>
                </div>

                <p class="text-sm text-gray-500 mb-6">
                    {{ order.delivery_type === 'pickup' ? 'Puedes recoger tu pedido en la tienda.' : 'Tu pedido será enviado a la dirección proporcionada.' }}
                </p>

                <a :href="route('store.home', { slug: new URL(window.location.href).pathname.split('/')[2] })">
                    <Button label="Seguir comprando" icon="pi pi-arrow-left" severity="secondary" outlined class="!rounded-full" />
                </a>
            </div>
        </div>
    </StoreLayout>
</template>
