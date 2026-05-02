<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import debounce from 'lodash/debounce';
import { useConfirm } from "primevue/useconfirm";
import ProductCard from './ProductCard.vue';
import CategoryFilters from './CategoryFilters.vue';
import PendingCartsPopover from './PendingCartsPopover.vue';
import ProductDetailModal from './ProductDetailModal.vue';
import CreateProductModal from '@/Components/CreateProductModal.vue';
import CashMovementModal from '@/Components/CashMovementModal.vue';

const props = defineProps({
    products: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    pendingCarts: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    activeSession: { type: Object, default: null },
    cartItems: { type: Array, default: () => [] },
    posMode: { type: String, default: 'retail' }
});

const emit = defineEmits(['addToCart', 'resumeCart', 'deleteCart', 'productCreatedAndAddToCart', 'refreshSessionData', 'openCloseSessionModal', 'openHistoryModal', 'update:posMode']);
const confirm = useConfirm();

// --- Lógica de Scroll simplificada ---
const loadedProducts = ref(props.products.data);
const nextCursor = ref(props.products.next_page_url);
const isLoadingMore = ref(false);
const productsContainer = ref(null);

// MODIFICACIÓN CLAVE: Este watcher escucha los cambios reales en los props (cuando hay una venta o filtro nuevo) 
// y reemplaza los productos cargados en memoria, forzando la actualización visual.
watch(() => props.products, (newProducts) => {
    // Si no estamos en medio de una carga por scroll infinito
    if (!isLoadingMore.value) {
        loadedProducts.value = newProducts.data;
        nextCursor.value = newProducts.next_page_url;
        if (productsContainer.value) productsContainer.value.scrollTop = 0;
    }
}, { deep: true });

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
    productsContainer.value?.addEventListener('scroll', handleScroll);
    window.addEventListener('keydown', handleGlobalKeyDown);
});

onUnmounted(() => {
    productsContainer.value?.removeEventListener('scroll', handleScroll);
    window.removeEventListener('keydown', handleGlobalKeyDown);
    clearTimeout(barcodeTimer);
});

// --- Lógica de Filtros ---
const searchTerm = ref(props.filters.search || '');
const selectedCategoryId = ref(props.filters.category || null);

const clearSearch = () => {
    searchTerm.value = '';
    const input = document.querySelector('.pos-search-input');
    if (input) input.focus();
};

const applyFilters = () => {
    router.get(route('pos.index'), {
        search: searchTerm.value,
        category: selectedCategoryId.value,
    }, {
        preserveState: true,
        preserveScroll: false,
        replace: true,
        only: ['products']
        // El nuevo watch en props.products hará la actualización visual automáticamente
    });
};

watch(searchTerm, debounce(applyFilters, 300));

const handleCategoryFilter = (categoryId) => {
    if (selectedCategoryId.value === categoryId) return;
    selectedCategoryId.value = categoryId;
    applyFilters();
};

// --- Lógica de Detección de Entidades (Ventas / Clientes) ---
const isCheckingEntity = ref(false);
const isSmartSearchHelpVisible = ref(false);

const sanitizeInput = (input) => {
    if (!input) return '';
    return input.replace(/'/g, '-').trim();
};

const checkAndRedirect = async (rawValue) => {
    const query = sanitizeInput(rawValue);
    if (!query || query.length < 3) return false;

    isCheckingEntity.value = true;

    try {
        const response = await axios.get(route('pos.check-entity'), { params: { query } });
        const result = response.data;

        if (result && result.found) {
            confirm.require({
                message: result.message,
                header: 'Entidad detectada',
                icon: 'pi pi-info-circle',
                acceptLabel: 'Ver detalles',
                rejectLabel: 'Cancelar',
                accept: () => {
                    let routeName = 'transactions.show';
                    if (result.type === 'customer') routeName = 'customers.show';
                    if (result.type === 'service_order') routeName = 'service-orders.show';

                    searchTerm.value = '';
                    window.open(route(routeName, result.id), '_blank');
                },
                reject: () => {
                    if (searchTerm.value !== query) {
                        searchTerm.value = query;
                    }
                }
            });
            return true;
        }
    } catch (error) {
        console.error("Error verificando entidad:", error);
    } finally {
        isCheckingEntity.value = false;
    }
    return false;
};

// --- Lógica para Lector de Código de Barras Global ---
let barcodeBuffer = '';
let barcodeTimer = null;

const handleGlobalKeyDown = async (event) => {
    const activeElement = document.activeElement;
    const isSearchInput = activeElement.classList.contains('pos-search-input');
    const isOtherInputFocused = ['INPUT', 'TEXTAREA'].includes(activeElement.tagName) && !isSearchInput;
    const isModalVisible = document.querySelector('.p-dialog-mask.p-component-overlay-enter');

    if (isOtherInputFocused || isModalVisible) {
        return;
    }

    if (event.key === 'Enter') {
        if (barcodeBuffer.length > 2) {
            event.preventDefault();
            const cleanQuery = sanitizeInput(barcodeBuffer);
            const handled = await checkAndRedirect(cleanQuery);
            if (!handled) {
                searchTerm.value = cleanQuery;
            }
            barcodeBuffer = '';
            return;
        }

        if (isSearchInput && searchTerm.value.length > 2) {
            event.preventDefault();
            await handleManualSearch();
            return;
        }
    }

    if (event.key.length > 1) return;

    barcodeBuffer += event.key;

    clearTimeout(barcodeTimer);
    barcodeTimer = setTimeout(() => {
        barcodeBuffer = '';
    }, 200);
};

const handleManualSearch = async () => {
    if (searchTerm.value.length > 2) {
        const cleanQuery = sanitizeInput(searchTerm.value);
        const handled = await checkAndRedirect(cleanQuery);
        if (!handled && searchTerm.value !== cleanQuery) {
            searchTerm.value = cleanQuery;
        }
    }
};

// --- Lógica del Menú de Sesión y Modales ---
const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const cashBalance = computed(() => {
    if (!props.activeSession) return 0;
    const cashSales = props.activeSession.payments
        ? props.activeSession.payments
            .filter(p => p && p.payment_method === 'efectivo' && p.status === 'completado')
            .reduce((sum, p) => sum + parseFloat(p.amount), 0)
        : 0;
    const inflows = props.activeSession.cash_movements
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
    const outflows = props.activeSession.cash_movements
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
    return (parseFloat(props.activeSession.opening_cash_balance) || 0) + cashSales + inflows - outflows;
});

const cardTotal = computed(() => props.activeSession?.totals?.card || 0);
const transferTotal = computed(() => props.activeSession?.totals?.transfer || 0);

const menuItems = ref([
    { label: 'Ingresar efectivo', icon: 'pi pi-arrow-down-left', command: () => openCashMovementModal('ingreso') },
    { label: 'Retirar efectivo', icon: 'pi pi-arrow-up-right', command: () => openCashMovementModal('egreso') },
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
</script>

<template>
    <div class="flex flex-col h-full bg-white dark:bg-[#232323] p-4 lg:p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] shadow-sm">
        
        <!-- HEADER -->
        <div class="flex-shrink-0">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                
                <div class="flex items-center gap-4">
                    <h1 class="hidden lg:block text-2xl md:text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">Registrar ventas</h1>
                    
                    <!-- Badge Caja Activa (Estilo Telemetría) -->
                    <div v-if="activeSession" class="flex items-center gap-2 bg-gray-50 dark:bg-[#1a1a1a] px-3 py-1.5 rounded-full border border-gray-200 dark:border-[#3a3a3a] shadow-inner">
                        <span class="w-2 h-2 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                        <span class="text-[10px] font-bold text-gray-600 dark:text-gray-300 tracking-widest uppercase m-0">
                            Caja: {{ activeSession.cash_register.name }}
                        </span>
                    </div>
                </div>

                <!-- Botones de Acción Superiores -->
                <div class="flex items-center gap-2">
                    <Button @click="$emit('update:posMode', posMode === 'retail' ? 'food' : 'retail')"
                        :icon="posMode === 'retail' ? 'pi pi-shop' : 'pi pi-receipt'" rounded 
                        v-tooltip.bottom="posMode === 'retail' ? 'Cambiar a comandas' : 'Cambiar a retail'"
                        class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-purple-500 hover:!border-purple-500 transition-colors" />

                    <Button @click="isCreateProductModalVisible = true" icon="pi pi-plus" rounded 
                        v-tooltip.bottom="'Agregar producto'"
                        class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500 transition-colors" />

                    <Button @click="toggleMenu" icon="pi pi-wallet" rounded 
                        v-tooltip.bottom="'Resumen de sesión'"
                        class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500 transition-colors" />
                    
                    <Menu ref="menu" :model="menuItems" :popup="true" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl' } }">
                        <template #end>
                            <div class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200 space-y-3 bg-gray-50 dark:bg-[#1a1a1a] m-2 rounded-xl">
                                <div>
                                    <span class="text-[10px] uppercase tracking-widest text-gray-500">Efectivo en caja</span>
                                    <p class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">${{ cashBalance.toFixed(2) }}</p>
                                </div>
                                <div class="border-t border-gray-200 dark:border-[#3a3a3a] pt-3">
                                    <span class="text-[10px] uppercase tracking-widest text-gray-500">Ventas (sesión)</span>
                                    <div class="flex justify-between items-center text-xs mt-2">
                                        <span class="text-gray-600 dark:text-gray-400">Tarjeta</span>
                                        <span class="font-mono font-medium">${{ cardTotal.toFixed(2) }}</span>
                                    </div>
                                    <div class="flex justify-between items-center text-xs mt-1">
                                        <span class="text-gray-600 dark:text-gray-400">Transferencia</span>
                                        <span class="font-mono font-medium">${{ transferTotal.toFixed(2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Menu>

                    <Button @click="$emit('openHistoryModal')" icon="pi pi-clock" rounded 
                        v-tooltip.bottom="'Historial de ventas'"
                        class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500 transition-colors" />
                    
                    <button @click="toggleOverlay" class="relative group">
                        <Button icon="pi pi-shopping-cart" rounded aria-label="Carritos en espera"
                            class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 group-hover:!border-primary-500 transition-colors" />
                        <Badge v-if="pendingCarts.length" :value="pendingCarts.length" severity="contrast"
                            class="absolute top-0 right-0 transform translate-x-1/4 -translate-y-1/4 !text-[10px]" size="small">
                        </Badge>
                    </button>
                    
                    <Button @click="$emit('openCloseSessionModal')" icon="pi pi-power-off" rounded 
                        v-tooltip.bottom="'Cerrar caja'"
                        class="!w-10 !h-10 !bg-red-50 dark:!bg-red-900/20 !border-red-200 dark:!border-red-900/50 !text-red-500 hover:!bg-red-500 hover:!text-white transition-all ml-2" />
                    
                    <Popover ref="op" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-3xl' } }">
                        <PendingCartsPopover :carts="pendingCarts" @resume-cart="$emit('resumeCart', $event)"
                            @delete-cart="$emit('deleteCart', $event)" />
                    </Popover>
                </div>
            </div>

            <!-- BARRA DE BÚSQUEDA INTEGRADA -->
            <div class="mb-6 flex flex-col md:flex-row gap-2 bg-gray-50 dark:bg-[#1a1a1a] p-2 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex-grow relative">
                    <IconField iconPosition="left" class="w-full">
                        <InputIcon v-if="!isCheckingEntity" class="pi pi-search text-gray-400" />
                        <InputIcon v-else class="pi pi-spin pi-spinner text-primary-500 font-bold" />
                        <InputText v-model="searchTerm" @keydown.enter="handleManualSearch"
                            placeholder="Escanear o buscar producto..."
                            class="w-full pos-search-input !bg-transparent !border-none !shadow-none !pl-10 !py-3 focus:!ring-0 dark:!text-white" />
                    </IconField>

                    <button v-if="searchTerm" @click="clearSearch"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 transition-colors size-7 flex items-center justify-center rounded-full hover:bg-gray-200 dark:hover:bg-[#2a2a2a]"
                        type="button" aria-label="Limpiar búsqueda">
                        <i class="pi pi-times !text-xs font-bold"></i>
                    </button>
                </div>

                <Button label="Búsqueda inteligente" icon="pi pi-sparkles" 
                    @click="isSmartSearchHelpVisible = true"
                    class="!rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-700 dark:!text-gray-300 hover:!border-primary-500 !text-[11px] !uppercase !tracking-widest !font-bold transition-all md:w-auto w-full" />
            </div>

            <!-- FILTROS DE CATEGORÍA -->
            <CategoryFilters :categories="categories" :active-category-id="selectedCategoryId"
                @filter="handleCategoryFilter" class="mb-4" />
        </div>

        <!-- CONTENEDOR DE PRODUCTOS -->
        <div class="flex-grow overflow-y-auto custom-scrollbar -mx-2 px-2" ref="productsContainer">
            <template v-if="loadedProducts.length > 0">
                <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 lg:gap-4 pb-6">
                    <ProductCard v-for="product in loadedProducts" :key="`${product.id}-${product.sku}`"
                        :product="product" :cart-items="cartItems" @showDetails="showProductDetails"
                        @addToCart="$emit('addToCart', $event)" />
                </div>
            </template>
            
            <div v-else-if="!isLoadingMore" class="flex flex-col items-center justify-center h-full text-center py-12">
                <div class="w-16 h-16 bg-gray-50 dark:bg-[#1a1a1a] rounded-full flex items-center justify-center mb-4 border border-gray-100 dark:border-[#3a3a3a]">
                    <i class="pi pi-box !text-2xl text-gray-400"></i>
                </div>
                <h3 class="text-xl font-light text-gray-900 dark:text-white tracking-tight m-0 mb-2">Sin resultados</h3>
                <p class="text-sm text-gray-500">No se encontraron productos con esos filtros.</p>
            </div>
            
            <div v-if="isLoadingMore" class="flex justify-center items-center py-8">
                <i class="pi pi-spin pi-spinner-dotted !text-3xl text-primary-500"></i>
            </div>
        </div>

        <!-- MODALES HIJOS -->
        <ProductDetailModal v-model:visible="isDetailModalVisible" :product="selectedProductForModal"
            @addToCart="$emit('addToCart', $event)" />
        <CreateProductModal v-model:visible="isCreateProductModalVisible" @created="handleProductCreated" />
        <CashMovementModal v-if="activeSession" v-model:visible="isCashMovementModalVisible" :type="movementType"
            :session-id="activeSession.id" @submitted="handleMovementSubmitted" />

        <!-- MODAL AYUDA BÚSQUEDA INTELIGENTE -->
        <Dialog v-model:visible="isSmartSearchHelpVisible" modal header="Búsqueda inteligente"
            class="w-full max-w-3xl"
            :breakpoints="{ '960px': '75vw', '640px': '95vw' }"
            :pt="{
                root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
                header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
                title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
                content: { class: 'dark:bg-[#232323] px-8 py-6' },
                footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-8 py-4' }
            }">
            
            <div class="space-y-6">
                <!-- Info Banner -->
                <div class="bg-blue-50 dark:bg-blue-900/10 p-5 rounded-2xl flex gap-4 items-start border border-blue-100 dark:border-blue-900/30">
                    <div class="bg-blue-100 dark:bg-blue-900/30 w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-info-circle !text-xl text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div>
                        <h4 class="font-medium text-lg text-blue-900 dark:text-blue-300 m-0 mb-1 tracking-tight">¿Cómo funciona?</h4>
                        <p class="text-sm text-blue-800 dark:text-blue-200/70 m-0 leading-relaxed">
                            La barra principal no solo encuentra productos. Detecta automáticamente códigos de tickets o teléfonos de clientes. 
                            Escribe o escanea y presiona <kbd class="bg-white dark:bg-[#1a1a1a] text-xs px-2 py-1 rounded-md shadow-sm border border-blue-200 dark:border-blue-800 font-mono text-blue-900 dark:text-blue-300 mx-1">Enter</kbd> para activar la búsqueda.
                        </p>
                    </div>
                </div>

                <!-- Cards explicativas -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-5 hover:border-primary-500/50 transition-colors group">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center shadow-sm text-primary-500">
                                <i class="pi pi-receipt !text-lg"></i>
                            </div>
                            <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Folios de venta</h3>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 m-0 leading-relaxed">
                            Escanea un ticket o escribe el folio (ej: <span class="font-mono bg-white dark:bg-[#232323] px-1.5 py-0.5 rounded border border-gray-200 dark:border-gray-700">V-001</span>).
                        </p>
                        <ul class="text-[11px] uppercase tracking-wide space-y-3 text-gray-600 dark:text-gray-400 m-0 p-0 list-none">
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-green-500"></i> Cancelaciones rápidas</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-green-500"></i> Devoluciones y cambios</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-green-500"></i> Pagos a créditos</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-green-500"></i> Reimpresión de tickets</li>
                        </ul>
                    </div>

                    <div class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-5 hover:border-purple-500/50 transition-colors group">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center shadow-sm text-purple-500">
                                <i class="pi pi-user !text-lg"></i>
                            </div>
                            <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Clientes</h3>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 m-0 leading-relaxed">
                            Escribe el teléfono a 10 dígitos o busca por nombre exacto.
                        </p>
                        <ul class="text-[11px] uppercase tracking-wide space-y-3 text-gray-600 dark:text-gray-400 m-0 p-0 list-none">
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Abonar saldo pendiente</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Historial de apartados</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Imprimir estado de cuenta</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Ajustes de saldo</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <template #footer>
                <div class="flex justify-end">
                    <Button label="Entendido" icon="pi pi-check" @click="isSmartSearchHelpVisible = false" autofocus class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-8" />
                </div>
            </template>
        </Dialog>
    </div>
</template>