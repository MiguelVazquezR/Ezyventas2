<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import ProductCard from './ProductCard.vue';
import CategoryFilters from './CategoryFilters.vue';
import PendingCartsPopover from './PendingCartsPopover.vue';
import ProductDetailModal from './ProductDetailModal.vue';
import CreateProductModal from '@/Components/CreateProductModal.vue';

// PrimeVue components
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputIcon from 'primevue/inputicon';
import IconField from 'primevue/iconfield';
import Badge from 'primevue/badge';
import Popover from 'primevue/popover';

const props = defineProps({
    products: Array,
    categories: Array,
    pendingCarts: Array,
    filters: Object,
});

// CAMBIO: Se añade el nuevo evento a la lista de emits
const emit = defineEmits(['addToCart', 'resumeCart', 'deleteCart', 'productCreatedAndAddToCart']);

const op = ref();
const toggleOverlay = (event) => op.value.toggle(event);

const isDetailModalVisible = ref(false);
const selectedProductForModal = ref(null);

const showProductDetails = (product) => {
    selectedProductForModal.value = product;
    isDetailModalVisible.value = true;
};

// --- Lógica de creación rápida de producto ---
const isCreateProductModalVisible = ref(false);

// CAMBIO: Esta función ahora recibe el nuevo producto y lo emite hacia el componente padre
const handleProductCreated = (newProduct) => {
    emit('productCreatedAndAddToCart', newProduct);
};

// --- Lógica de búsqueda y filtrado ---
const searchTerm = ref(props.filters.search || '');
const selectedCategoryId = ref(props.filters.category || null);

const applyFilters = () => {
    router.get(route('pos.index'), {
        search: searchTerm.value,
        category: selectedCategoryId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(searchTerm, debounce(applyFilters, 300));

const handleCategoryFilter = (categoryId) => {
    selectedCategoryId.value = categoryId;
    applyFilters();
};

</script>

<template>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow-md flex flex-col h-full">
        <div class="p-6 pb-4 flex-shrink-0">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Registrar ventas</h1>
                <div class="flex items-center gap-2">
                    <Button @click="isCreateProductModalVisible = true" icon="pi pi-plus" rounded text severity="secondary" v-tooltip.bottom="'Agregar nuevo producto'" />
                    <Button icon="pi pi-th-large" rounded text severity="secondary" v-tooltip.bottom="'Cambiar vista'" />
                    <Button icon="pi pi-clock" rounded text severity="secondary" v-tooltip.bottom="'Ver historial de ventas'" />
                    <button @click="toggleOverlay" class="relative">
                        <Button icon="pi pi-shopping-cart" rounded text severity="secondary" aria-label="Carritos en espera" />
                        <Badge :value="pendingCarts.length" severity="danger" class="absolute top-0 right-0 transform translate-x-1/2 -translate-y-1/2"></Badge>
                    </button>
                    <Popover ref="op">
                        <PendingCartsPopover :carts="pendingCarts" @resume-cart="$emit('resumeCart', $event)" @delete-cart="$emit('deleteCart', $event)" />
                    </Popover>
                </div>
            </div>
            <div class="mb-4">
                <IconField iconPosition="left">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="searchTerm" placeholder="Escanear o buscar producto por nombre o SKU" class="w-full" />
                </IconField>
            </div>
            <CategoryFilters :categories="categories" :active-category-id="selectedCategoryId" @filter="handleCategoryFilter" />
        </div>
        <div class="flex-grow px-6 pb-6 overflow-y-auto">
            <p v-if="products.length === 0" class="text-center text-gray-500 mt-8">No se encontraron productos.</p>
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <ProductCard v-for="product in products" :key="product.id" :product="product" @showDetails="showProductDetails" @addToCart="$emit('addToCart', $event)" />
            </div>
        </div>
        <ProductDetailModal v-model:visible="isDetailModalVisible" :product="selectedProductForModal" @addToCart="$emit('addToCart', $event)" />
        <CreateProductModal v-model:visible="isCreateProductModalVisible" @created="handleProductCreated" />
    </div>
</template>