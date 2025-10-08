<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import debounce from 'lodash/debounce';
import ProductCard from './ProductCard.vue';
import CategoryFilters from './CategoryFilters.vue';
import PendingCartsPopover from './PendingCartsPopover.vue';
import ProductDetailModal from './ProductDetailModal.vue';
import CreateProductModal from '@/Components/CreateProductModal.vue';
import CashMovementModal from '@/Components/CashMovementModal.vue';

const props = defineProps({
    products: Object,
    categories: Array,
    pendingCarts: Array,
    filters: Object,
    activeSession: Object,
});

const emit = defineEmits(['addToCart', 'resumeCart', 'deleteCart', 'productCreatedAndAddToCart', 'refreshSessionData', 'openCloseSessionModal', 'openHistoryModal']);

// --- Lógica para Infinite Scroll y Carga Inicial ---
const loadedProducts = ref([]);
const nextCursor = ref(props.products.next_page_url);
const isLoadingMore = ref(false);
const isInitialising = ref(false);
const productsContainer = ref(null);

const initialiseProductList = async () => {
    const currentPage = props.products.current_page;
    if (currentPage <= 1) {
        loadedProducts.value = props.products.data;
        return;
    }

    isInitialising.value = true;
    try {
        const fetchPromises = [];
        const searchParams = new URLSearchParams(window.location.search);

        for (let page = 1; page < currentPage; page++) {
            searchParams.set('page', page);
            const url = `${window.location.pathname}?${searchParams.toString()}`;
            fetchPromises.push(
                axios.get(url, {
                    headers: {
                        'X-Inertia': 'true',
                        'X-Inertia-Version': usePage().version,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
            );
        }

        const responses = await Promise.all(fetchPromises);
        const previousProducts = responses.flatMap(response => response.data.props.products.data);

        loadedProducts.value = [...previousProducts, ...props.products.data];

    } catch (error) {
        console.error("Failed to load previous product pages:", error);
        loadedProducts.value = props.products.data; // Fallback en caso de error
    } finally {
        isInitialising.value = false;
    }
};

const loadMoreProducts = () => {
    if (!nextCursor.value || isLoadingMore.value) return;
    isLoadingMore.value = true;

    router.get(nextCursor.value, {}, {
        preserveState: true,
        preserveScroll: true,
        only: ['products'],
        onSuccess: (page) => {
            loadedProducts.value.push(...page.props.products.data);
            nextCursor.value = page.props.products.next_page_url;
            isLoadingMore.value = false;
        },
        onError: () => {
            isLoadingMore.value = false;
        },
    });
};

const handleScroll = (event) => {
    const el = event.target;
    if (el.scrollHeight - el.scrollTop <= el.clientHeight + 200) {
        loadMoreProducts();
    }
};

onMounted(() => {
    initialiseProductList();
    productsContainer.value?.addEventListener('scroll', handleScroll);
});

onUnmounted(() => {
    productsContainer.value?.removeEventListener('scroll', handleScroll);
});

// --- Lógica de Filtros ---
const searchTerm = ref(props.filters.search || '');
const selectedCategoryId = ref(props.filters.category || null);

const applyFilters = () => {
    router.get(route('pos.index'), {
        search: searchTerm.value,
        category: selectedCategoryId.value,
    }, {
        preserveState: true,
        preserveScroll: false,
        replace: true,
        only: ['products'],
        onSuccess: (page) => {
            loadedProducts.value = page.props.products.data;
            nextCursor.value = page.props.products.next_page_url;
            if (productsContainer.value) productsContainer.value.scrollTop = 0;
        },
    });
};

watch(searchTerm, debounce(applyFilters, 300));

const handleCategoryFilter = (categoryId) => {
    if (selectedCategoryId.value === categoryId) return;
    selectedCategoryId.value = categoryId;
    applyFilters();
};


// --- Lógica del Menú de Sesión y Modales (sin cambios) ---
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
    <div class="flex flex-col h-full mt-1">
        <div class="lg:px-6 flex-shrink-0">
            <div class="flex justify-between items-center mb-4">
                <h1 class="hidden lg:block text-xl font-bold text-gray-800 dark:text-gray-200 m-0">Registrar ventas</h1>
                <div v-if="activeSession"
                    class="p-1 text-center rounded-full px-2 lg:px-8 text-sm lg:text-base bg-gradient-to-r from-transparent via-[#CEEACB] dark:via-[#366531] to-transparent text-[#24880B] dark:text-[#69f446] font-semibold">
                    Caja Activa: <span class="font-bold">{{ activeSession.cash_register.name }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <Button @click="isCreateProductModalVisible = true" icon="pi pi-plus" rounded severity="secondary"
                        v-tooltip.bottom="'Agregar nuevo producto'" variant="outlined" size="medium"
                        class="!size-8 !bg-white" />

                    <Button @click="toggleMenu" icon="pi pi-inbox" rounded severity="secondary"
                        v-tooltip.bottom="'Resumen de Sesión'" variant="outlined" size="medium"
                        class="!size-8 !bg-white" />
                    <Menu ref="menu" :model="menuItems" :popup="true">
                        <template #end>
                            <div class="lg:px-4 py-2 text-sm text-gray-700 dark:text-gray-200 space-y-3">
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

                    <Button @click="$emit('openHistoryModal')" icon="pi pi-clock" rounded severity="secondary"
                        v-tooltip.bottom="'Ver historial de ventas'" variant="outlined" size="medium"
                        class="!size-8 !bg-white" />
                    <button @click="toggleOverlay" class="relative">
                        <Button icon="pi pi-shopping-cart" rounded severity="secondary"
                            aria-label="Carritos en espera" variant="outlined" size="medium"
                        class="!size-8 !bg-white" />
                        <Badge v-if="pendingCarts.length" :value="pendingCarts.length" severity="contrast"
                            class="absolute top-2 right-0 transform translate-x-1/2 -translate-y-1/2" size="small"></Badge>
                    </button>
                    <Button @click="$emit('openCloseSessionModal')" icon="pi pi-sign-out" rounded severity="danger"
                        v-tooltip.bottom="'Cerrar Caja'" variant="outlined" size="medium"
                        class="!size-8 !bg-white" />
                    <Popover ref="op">
                        <PendingCartsPopover :carts="pendingCarts" @resume-cart="$emit('resumeCart', $event)"
                            @delete-cart="$emit('deleteCart', $event)" />
                    </Popover>
                </div>
            </div>
            <div class="mb-4">
                <IconField iconPosition="left">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="searchTerm" placeholder="Escanear o buscar producto por nombre o SKU"
                        class="w-full" />
                </IconField>
            </div>
            <CategoryFilters :categories="categories" :active-category-id="selectedCategoryId"
                @filter="handleCategoryFilter" />
        </div>

        <div class="flex-grow lg:px-6 pb-6 overflow-y-auto" ref="productsContainer">
            <div v-if="isInitialising" class="flex justify-center items-center h-full">
                <i class="pi pi-spin pi-spinner text-4xl text-gray-400"></i>
            </div>
            <template v-else>
                <p v-if="loadedProducts.length === 0 && !isLoadingMore" class="text-center text-gray-500 mt-8">No se
                    encontraron productos.</p>
                <div v-else class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-2">
                    <ProductCard v-for="product in loadedProducts" :key="`${product.id}-${product.sku}`"
                        :product="product" @showDetails="showProductDetails" @addToCart="$emit('addToCart', $event)" />
                </div>
                <div v-if="isLoadingMore" class="flex justify-center items-center h-24">
                    <i class="pi pi-spin pi-spinner text-3xl text-gray-400"></i>
                </div>
            </template>
        </div>

        <ProductDetailModal v-model:visible="isDetailModalVisible" :product="selectedProductForModal"
            @addToCart="$emit('addToCart', $event)" />
        <CreateProductModal v-model:visible="isCreateProductModalVisible" @created="handleProductCreated" />
        <CashMovementModal v-if="activeSession" v-model:visible="isCashMovementModalVisible" :type="movementType"
            :session-id="activeSession.id" @submitted="handleMovementSubmitted" />
    </div>
</template>