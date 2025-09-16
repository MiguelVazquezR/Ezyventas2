<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

import PosLeftPanel from './Partials/PosLeftPanel.vue';
import ShoppingCart from './Partials/ShoppingCart.vue';

// Los datos ahora vienen como props desde el controlador de Laravel
const props = defineProps({
    products: Array,
    categories: Array,
    pendingCarts: Array,
    initialClient: Object,
});

// --- ESTADO LOCAL DEL COMPONENTE (Manejo del carrito, etc.) ---

// El carrito de compras sí se manejará localmente hasta que se guarde.
const cartItems = ref([
    { id: 1, name: 'Vestido de mezclilla con cinturón y líneas de colores', price: 500.00, quantity: 2, image: 'https://placehold.co/100x100/EBF8FF/3182CE?text=Vestido', variants: { Talla: 'S', Color: 'Azul' } },
    { id: 4, name: 'Tenis deportivos MIKA', price: 1600.00, originalPrice: 1800.00, quantity: 1, image: 'https://placehold.co/100x100/FFF7ED/F97316?text=Tenis', variants: { Talla: '24', Color: 'Rosa' } },
    { id: 6, name: 'Downy 2.5 L', price: 150.00, quantity: 1, image: 'https://placehold.co/100x100/EFF6FF/3B82F6?text=Suavizante', variants: {} },
]);

// El cliente seleccionado también es un estado local que puede cambiar.
const selectedClient = ref(props.initialClient);

</script>

<template>

    <Head title="Punto de Venta" />

    <AppLayout>
        <div class="flex flex-col lg:flex-row gap-4 h-[calc(100vh-115px)]">
            <!-- Columna Izquierda: Productos -->
            <div class="lg:w-2/3 xl:w-3/4 h-full overflow-hidden">
                <PosLeftPanel :products="products" :categories="categories" :pending-carts="pendingCarts"
                    class="h-full" />
            </div>

            <!-- Columna Derecha: Carrito -->
            <div class="lg:w-1/3 xl:w-1/4 h-full overflow-hidden">
                <ShoppingCart :items="cartItems" :client="selectedClient" class="h-full" />
            </div>
        </div>
    </AppLayout>
</template>