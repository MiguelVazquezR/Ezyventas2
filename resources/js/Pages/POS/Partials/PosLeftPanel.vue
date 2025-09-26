<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import ProductCard from './ProductCard.vue';
import CategoryFilters from './CategoryFilters.vue';
import PendingCartsPopover from './PendingCartsPopover.vue';
import ProductDetailModal from './ProductDetailModal.vue';
import CreateProductModal from '@/Components/CreateProductModal.vue';
import CashMovementModal from '@/Components/CashMovementModal.vue';

const props = defineProps({
    products: Array,
    categories: Array,
    pendingCarts: Array,
    filters: Object,
    activeSession: Object,
});

const emit = defineEmits(['addToCart', 'resumeCart', 'deleteCart', 'productCreatedAndAddToCart', 'refreshSessionData', 'openCloseSessionModal', 'openHistoryModal']);

// --- Lógica del Menú de Sesión ---
const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const cashBalance = computed(() => {
    if (!props.activeSession) return 0;
    const cashSales = props.activeSession.transactions
        .flatMap(t => t.payments)
        .filter(p => p.payment_method === 'efectivo')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
    const inflows = props.activeSession.cash_movements
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
    const outflows = props.activeSession.cash_movements
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
    return parseFloat(props.activeSession.opening_cash_balance) + cashSales + inflows - outflows;
});

const cardTotal = computed(() => props.activeSession?.totals?.card || 0);
const transferTotal = computed(() => props.activeSession?.totals?.transfer || 0);

const menuItems = ref([
    { label: 'Ingresar Efectivo', icon: 'pi pi-arrow-down-left', command: () => openCashMovementModal('ingreso') },
    { label: 'Retirar Efectivo', icon: 'pi pi-arrow-up-right', command: () => openCashMovementModal('egreso') },
    { separator: true },
]);

const isCashMovementModalVisible = ref(false);
const movementType = ref('ingreso');

const openCashMovementModal = (type) => {
    movementType.value = type;
    isCashMovementModalVisible.value = true;
};

const handleMovementSubmitted = () => {
    emit('refreshSessionData');
};

const op = ref();
const toggleOverlay = (event) => op.value.toggle(event);
const isDetailModalVisible = ref(false);
const selectedProductForModal = ref(null);
const showProductDetails = (product) => {
    selectedProductForModal.value = product;
    isDetailModalVisible.value = true;
};
const isCreateProductModalVisible = ref(false);
const handleProductCreated = (newProduct) => {
    emit('productCreatedAndAddToCart', newProduct);
};
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

// --- Lógica para Lector de Código de Barras Global ---
let barcodeBuffer = '';
let barcodeTimer = null;

const handleGlobalKeyDown = (event) => {
    const activeElement = document.activeElement;
    const isInputFocused = ['INPUT', 'TEXTAREA'].includes(activeElement.tagName);
    const isModalVisible = document.querySelector('.p-dialog-mask.p-component-overlay-enter');

    if (isInputFocused || isModalVisible) {
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        if (barcodeBuffer.length > 2) {
            searchTerm.value = barcodeBuffer;
        }
        barcodeBuffer = '';
        return;
    }

    if (event.key.length > 1) {
        return;
    }

    barcodeBuffer += event.key;

    clearTimeout(barcodeTimer);
    barcodeTimer = setTimeout(() => {
        barcodeBuffer = '';
    }, 100); 
};

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeyDown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeyDown);
    clearTimeout(barcodeTimer);
});
</script>

<template>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow-md flex flex-col h-full">
        <div v-if="activeSession" class="p-2 text-center text-sm bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200 font-semibold rounded-t-lg">
            Caja Activa: <span class="font-bold">{{ activeSession.cash_register.name }}</span>
        </div>
        <div class="p-6 pb-4 flex-shrink-0">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Registrar ventas</h1>
                <div class="flex items-center gap-2">
                    <Button @click="isCreateProductModalVisible = true" icon="pi pi-plus" rounded text severity="secondary" v-tooltip.bottom="'Agregar nuevo producto'" />
                    
                    <Button @click="toggleMenu" icon="pi pi-inbox" rounded text severity="secondary" v-tooltip.bottom="'Resumen de Sesión'" />
                    <Menu ref="menu" :model="menuItems" :popup="true">
                         <template #end>
                            <div class="px-4 py-2 text-sm text-gray-700 dark:text-gray-200 space-y-3">
                                <div>
                                    <span class="font-semibold">Efectivo en Caja:</span>
                                    <p class="text-lg font-bold text-right">${{ cashBalance.toFixed(2) }}</p>
                                </div>
                                <div class="border-t dark:border-gray-600 pt-2">
                                    <span class="font-semibold">Ventas (Sesión Actual):</span>
                                    <div class="flex justify-between text-xs mt-1">
                                        <span>Tarjeta:</span>
                                        <span class="font-mono">${{ cardTotal.toFixed(2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span>Transferencia:</span>
                                        <span class="font-mono">${{ transferTotal.toFixed(2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Menu>

                    <Button @click="$emit('openHistoryModal')" icon="pi pi-clock" rounded text severity="secondary" v-tooltip.bottom="'Ver historial de ventas'" />
                    <button @click="toggleOverlay" class="relative">
                        <Button icon="pi pi-shopping-cart" rounded text severity="secondary" aria-label="Carritos en espera" />
                        <Badge :value="pendingCarts.length" severity="danger" class="absolute top-0 right-0 transform translate-x-1/2 -translate-y-1/2"></Badge>
                    </button>
                    <Button @click="$emit('openCloseSessionModal')" icon="pi pi-sign-out" rounded text severity="danger" v-tooltip.bottom="'Cerrar Caja'" />
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
        <CashMovementModal v-if="activeSession" v-model:visible="isCashMovementModalVisible" :type="movementType" :session-id="activeSession.id" @submitted="handleMovementSubmitted" />
    </div>
</template>