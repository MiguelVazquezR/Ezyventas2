<script setup>
import { computed, ref, onMounted } from 'vue';
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

const isDarkTheme = computed(() => store.value.theme_mode === 'dark');

const showContent = ref(false);
const showAnimation = ref(true);

onMounted(() => {
    // Trigger content fade-in after animation
    setTimeout(() => { showContent.value = true; }, 300);
    // Hide success overlay after animation completes
    setTimeout(() => { showAnimation.value = false; }, 2500);
});
</script>

<template>
    <Head :title="'Pedido confirmado — ' + (store.name || 'Tienda')" />
    <StoreLayout>
        <div class="max-w-xl mx-auto px-4 md:px-6 py-12 relative">
            <!-- Success wave animation overlay -->
            <div v-if="showAnimation" class="fixed inset-0 z-50 pointer-events-none flex items-center justify-center">
                <!-- Expanding rings -->
                <div class="absolute w-16 h-16 rounded-full bg-green-500 animate-[ping_1.5s_ease-out_forwards] opacity-0" />
                <div class="absolute w-16 h-16 rounded-full bg-green-400 animate-[ping_2s_ease-out_0.2s_forwards] opacity-0" />
                <div class="absolute w-16 h-16 rounded-full bg-green-300 animate-[ping_2.5s_ease-out_0.4s_forwards] opacity-0" />
                
                <!-- Center checkmark -->
                <div class="w-20 h-20 rounded-full bg-green-500 flex items-center justify-center shadow-[0_0_40px_rgba(34,197,94,0.5)] animate-[scaleIn_0.5s_ease-out_0.3s_both]">
                    <i class="pi pi-check !text-3xl text-white" />
                </div>
            </div>

            <!-- Content card (fades in after animation) -->
            <div :class="[
                'rounded-3xl border p-8 md:p-10 text-center transition-all duration-700 ease-out',
                isDarkTheme ? 'bg-[#232323] border-[#3a3a3a]' : 'bg-white border-gray-100',
                showContent ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
            ]">
                <!-- Success indicator -->
                <div class="flex items-center justify-center gap-2 mb-6">
                    <span class="w-2.5 h-2.5 rounded-full bg-green-500 shadow-[0_0_10px_rgba(34,197,94,0.8)] animate-pulse" />
                    <span class="text-[10px] uppercase tracking-widest font-bold text-green-500">Pedido confirmado</span>
                </div>

                <!-- Success icon (static, smaller) -->
                <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4"
                    :style="{ background: 'var(--store-primary-glow)' }">
                    <i class="pi pi-check !text-2xl" :style="{ color: 'var(--store-primary)' }" />
                </div>

                <h1 class="text-2xl md:text-3xl font-light tracking-tight m-0 mb-2"
                    :class="isDarkTheme ? 'text-white' : 'text-gray-900'">
                    ¡Compra realizada!
                </h1>
                <p class="text-sm mb-8 m-0"
                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">
                    Gracias por tu pedido. Aquí está tu número de orden:
                </p>

                <!-- Order number -->
                <div :class="[
                    'rounded-2xl p-6 mb-8 inline-block',
                    isDarkTheme ? 'bg-[#1a1a1a]' : 'bg-gray-50'
                ]">
                    <p class="text-[10px] uppercase tracking-widest font-bold m-0 mb-1"
                        :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                        Número de pedido
                    </p>
                    <p class="text-3xl md:text-4xl font-light tracking-tight m-0"
                        :style="{ color: 'var(--store-primary)' }">
                        {{ order.formatted_order_number }}
                    </p>
                </div>

                <!-- Order details -->
                <div :class="[
                    'text-left rounded-2xl p-5 mb-8 space-y-2',
                    isDarkTheme ? 'bg-[#1a1a1a]' : 'bg-gray-50'
                ]">
                    <h2 class="text-xs font-semibold uppercase tracking-wider m-0 mb-3"
                        :class="isDarkTheme ? 'text-gray-300' : 'text-gray-700'">
                        Resumen del pedido
                    </h2>
                    <div v-for="item in order.items" :key="item.id" class="flex justify-between text-sm">
                        <span :class="isDarkTheme ? 'text-gray-400' : 'text-gray-600'">
                            {{ item.quantity }}x {{ item.product_name }}
                        </span>
                        <span class="font-medium"
                            :class="isDarkTheme ? 'text-white' : 'text-gray-900'">
                            {{ formatCurrency(item.subtotal) }}
                        </span>
                    </div>
                    <div v-if="order.delivery_fee > 0" class="flex justify-between text-sm">
                        <span :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">Costo de envío</span>
                        <span :class="isDarkTheme ? 'text-white' : 'text-gray-900'">{{ formatCurrency(order.delivery_fee) }}</span>
                    </div>
                    <div :class="[
                        'border-t pt-2 mt-2 flex justify-between',
                        isDarkTheme ? 'border-[#3a3a3a]' : 'border-gray-200'
                    ]">
                        <span class="text-sm font-semibold"
                            :class="isDarkTheme ? 'text-white' : 'text-gray-900'">Total</span>
                        <span class="text-lg font-light tracking-tight"
                            :class="isDarkTheme ? 'text-white' : 'text-gray-900'"
                            :style="{ color: 'var(--store-primary)' }">
                            {{ formatCurrency(order.total) }}
                        </span>
                    </div>
                </div>

                <p class="text-xs mb-8 m-0"
                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">
                    {{ order.delivery_type === 'pickup'
                        ? 'Puedes recoger tu pedido en la tienda. Te avisaremos cuando esté listo.'
                        : 'Tu pedido será enviado a la dirección proporcionada.' }}
                </p>

                <Link :href="route('store.home', { slug: slug })">
                    <Button label="Seguir comprando" icon="pi pi-arrow-left" outlined
                        class="!rounded-full !px-8"
                        :pt="{
                            root: { 
                                class: [
                                    '!border-gray-200 !text-gray-600 hover:!border-gray-400',
                                    isDarkTheme ? '!border-[#3a3a3a] !text-gray-400 hover:!border-gray-500' : ''
                                ].filter(Boolean).join(' ')
                            }
                        }" />
                </Link>
            </div>
        </div>
    </StoreLayout>
</template>

<style scoped>
@keyframes ping {
    0% {
        transform: scale(1);
        opacity: 0.6;
    }
    100% {
        transform: scale(30);
        opacity: 0;
    }
}
@keyframes scaleIn {
    0% {
        transform: scale(0);
        opacity: 0;
    }
    70% {
        transform: scale(1.15);
        opacity: 1;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}
</style>
