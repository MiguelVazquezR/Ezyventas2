<script setup>
import { ref } from 'vue';
import ProductCard from './ProductCard.vue';
import CategoryFilters from './CategoryFilters.vue';
import PendingCartsPopover from './PendingCartsPopover.vue';
import ProductDetailModal from './ProductDetailModal.vue';

const props = defineProps({
    products: Array,
    categories: Array,
    pendingCarts: Array,
});

const emit = defineEmits(['addToCart', 'resumeCart', 'deletePendingCart']);

const op = ref();
const toggleOverlay = (event) => {
    op.value.toggle(event);
}

// --- Lógica para el modal de detalles ---
const isDetailModalVisible = ref(false);
const selectedProductForModal = ref(null);

const showProductDetails = (product) => {
    selectedProductForModal.value = product;
    isDetailModalVisible.value = true;
};

// La lógica de añadir al carrito se emite hacia el padre
const handleAddToCart = (product) => {
    emit('addToCart', product);
};

</script>

<template>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow-md flex flex-col h-full">

        <div class="p-6 pb-4 flex-shrink-0">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Registrar ventas</h1>
                <div class="flex items-center gap-2">
                    <Button icon="pi pi-plus" rounded text severity="secondary"
                        v-tooltip.bottom="'Agregar nuevo producto'" />
                    <Button icon="pi pi-th-large" rounded text severity="secondary"
                        v-tooltip.bottom="'Cambiar vista'" />
                    <Button icon="pi pi-clock" rounded text severity="secondary"
                        v-tooltip.bottom="'Ver historial de ventas'" />
                    <button @click="toggleOverlay" class="relative">
                        <Button icon="pi pi-shopping-cart" rounded text severity="secondary"
                            aria-label="Carritos en espera" />
                        <Badge :value="pendingCarts.length" severity="danger"
                            class="absolute top-0 right-0 transform translate-x-1/2 -translate-y-1/2"></Badge>
                    </button>
                    <Popover ref="op">
                        <PendingCartsPopover 
                            :carts="pendingCarts" 
                            @resume-cart="$emit('resumeCart', $event)"
                            @delete-cart="$emit('deletePendingCart', $event)"
                        />
                    </Popover>
                </div>
            </div>

            <div class="mb-4">
                <IconField iconPosition="left">
                    <InputIcon class="pi pi-search"> </InputIcon>
                    <InputText placeholder="Escanear o buscar producto" class="w-full" />
                </IconField>
            </div>

            <CategoryFilters :categories="categories" />
        </div>

        <div class="flex-grow px-6 pb-6 overflow-y-auto">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <ProductCard v-for="product in products" :key="product.id" :product="product"
                    @showDetails="showProductDetails" @addToCart="handleAddToCart" />
            </div>
        </div>

        <ProductDetailModal v-model:visible="isDetailModalVisible" :product="selectedProductForModal"
            @addToCart="handleAddToCart" />
    </div>
</template>