<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import Button from 'primevue/button';

const page = usePage();
const store = computed(() => page.props.store || {});

const props = defineProps({
    order: Object,
});

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};
</script>

<template>
    <Head :title="'Pedido confirmado — ' + (store.name || 'Tienda')" />
    <StoreLayout>
        <div class="max-w-xl mx-auto px-4 md:px-6 py-12">
            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-8 md:p-10 text-center">
                <!-- Success indicator -->
                <div class="flex items-center justify-center gap-2 mb-8">
                    <span class="w-2.5 h-2.5 rounded-full bg-green-500 shadow-[0_0_10px_rgba(34,197,94,0.8)] animate-pulse" />
                    <span class="text-[10px] uppercase tracking-widest font-bold text-green-500">Pedido confirmado</span>
                </div>

                <!-- Success icon -->
                <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6"
                    :style="{ background: 'var(--store-primary-glow)' }">
                    <i class="pi pi-check !text-3xl" :style="{ color: 'var(--store-primary)' }" />
                </div>

                <h1 class="text-2xl md:text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0 mb-2">
                    ¡Pedido confirmado!
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-8 m-0">
                    Gracias por tu pedido. Aquí está tu número de orden:
                </p>

                <!-- Order number -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-6 mb-8 inline-block">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-1">
                        Número de pedido
                    </p>
                    <p class="text-3xl md:text-4xl font-light tracking-tight m-0"
                        :style="{ color: 'var(--store-primary)' }">
                        {{ order.formatted_order_number }}
                    </p>
                </div>

                <!-- Order details -->
                <div class="text-left bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 mb-8 space-y-2">
                    <h2 class="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider m-0 mb-3">
                        Resumen del pedido
                    </h2>
                    <div v-for="item in order.items" :key="item.id" class="flex justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">
                            {{ item.quantity }}x {{ item.product_name }}
                        </span>
                        <span class="text-gray-900 dark:text-white font-medium">
                            {{ formatCurrency(item.subtotal) }}
                        </span>
                    </div>
                    <div class="border-t border-gray-200 dark:border-[#3a3a3a] pt-2 mt-2 flex justify-between">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Total</span>
                        <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white"
                            :style="{ color: 'var(--store-primary)' }">
                            {{ formatCurrency(order.total) }}
                        </span>
                    </div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 mb-8 m-0">
                    {{ order.delivery_type === 'pickup'
                        ? 'Puedes recoger tu pedido en la tienda. Te avisaremos cuando esté listo.'
                        : 'Tu pedido será enviado a la dirección proporcionada.' }}
                </p>

                <Link :href="route('store.home', { slug: slug })">
                    <Button label="Seguir comprando" icon="pi pi-arrow-left" outlined
                        class="!rounded-full !px-8"
                        :pt="{
                            root: { class: '!border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-400 hover:!border-gray-400 dark:hover:!border-gray-500' }
                        }" />
                </Link>
            </div>
        </div>
    </StoreLayout>
</template>
