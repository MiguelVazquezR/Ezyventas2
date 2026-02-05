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

// --- Sintaxis explícita para evitar errores de compilación ---
const props = defineProps({
    products: {
        type: Object,
        required: true
    },
    categories: {
        type: Array,
        default: () => []
    },
    pendingCarts: {
        type: Array,
        default: () => []
    },
    filters: {
        type: Object,
        default: () => ({})
    },
    activeSession: {
        type: Object,
        default: null
    },
    cartItems: { // <--- Nueva prop recibida
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['addToCart', 'resumeCart', 'deleteCart', 'productCreatedAndAddToCart', 'refreshSessionData', 'openCloseSessionModal', 'openHistoryModal']);
const confirm = useConfirm();

// --- Lógica de Scroll simplificada ---
const loadedProducts = ref(props.products.data);
const nextCursor = ref(props.products.next_page_url);
const isLoadingMore = ref(false);
const productsContainer = ref(null);

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

// --- Nueva función para limpiar búsqueda ---
const clearSearch = () => {
    searchTerm.value = '';
    // Opcional: Devolver el foco al input después de limpiar para mejor UX
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
        only: ['products'],
        onSuccess: (page) => {
            page.props.flash.success = null;
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

// --- Lógica de Detección de Entidades (Ventas / Clientes) ---
const isCheckingEntity = ref(false); 
const isSmartSearchHelpVisible = ref(false); 

// Helper para corregir errores comunes de lectores de código (ej: V'001 -> V-001)
const sanitizeInput = (input) => {
    if (!input) return '';
    // Reemplaza comilla simple por guion, y limpia espacios extras
    return input.replace(/'/g, '-').trim();
};

const checkAndRedirect = async (rawValue) => {
    const query = sanitizeInput(rawValue);
    
    // Validar longitud mínima
    if (!query || query.length < 3) return false; 

    isCheckingEntity.value = true;

    try {
        const response = await axios.get(route('pos.check-entity'), { params: { query } });
        const result = response.data;

        if (result && result.found) {
            confirm.require({
                message: result.message,
                header: 'Entidad Detectada',
                icon: 'pi pi-info-circle',
                acceptLabel: 'Ver detalles',
                rejectLabel: 'Cancelar',
                accept: () => {
                    let routeName = 'transactions.show';
                    if (result.type === 'customer') routeName = 'customers.show';
                    if (result.type === 'service_order') routeName = 'service-orders.show';
                    
                    // Limpiar búsqueda si se aceptó ir al detalle
                    searchTerm.value = '';
                    
                    window.open(route(routeName, result.id), '_blank');
                    
                },
                reject: () => {
                    // Si cancela, mostramos el término corregido en el input
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

// --- Lógica para Lector de Código de Barras Global (MEJORADA) ---
let barcodeBuffer = '';
let barcodeTimer = null;

const handleGlobalKeyDown = async (event) => {
    const activeElement = document.activeElement;
    
    // Verificar si el foco está en el input de búsqueda del POS
    const isSearchInput = activeElement.classList.contains('pos-search-input');
    
    // Verificar si hay algún OTRO input enfocado (ej: en un modal, o un campo de notas)
    // Si es el input de búsqueda, SÍ queremos procesar (para detectar Enter y limpiar buffer)
    // Si es otro input, NO queremos interferir.
    const isOtherInputFocused = ['INPUT', 'TEXTAREA'].includes(activeElement.tagName) && !isSearchInput;
    const isModalVisible = document.querySelector('.p-dialog-mask.p-component-overlay-enter');

    // Si hay un modal abierto o el usuario escribe en otro campo, no interferir.
    if (isOtherInputFocused || isModalVisible) {
        return;
    }

    // Si la tecla es Enter, procesamos el buffer acumulado
    if (event.key === 'Enter') {
        // Si el buffer tiene contenido (vino del scanner o tipeo rápido sin foco)
        if (barcodeBuffer.length > 2) {
            event.preventDefault(); // Prevenir submit de forms si los hubiera
            
            // 1. Sanitizar (arreglar V'001 -> V-001)
            const cleanQuery = sanitizeInput(barcodeBuffer);
            
            // 2. Intentar detectar entidad inteligente
            const handled = await checkAndRedirect(cleanQuery);
            
            // 3. Si no fue entidad, ponerlo en el buscador para buscar producto
            if (!handled) {
                searchTerm.value = cleanQuery;
            }
            
            barcodeBuffer = '';
            return;
        }
        
        // Si el buffer está vacío pero estamos en el input de búsqueda y presionamos Enter,
        // procesamos el valor actual del input manualmente.
        if (isSearchInput && searchTerm.value.length > 2) {
             event.preventDefault();
             await handleManualSearch();
             return;
        }
    }

    // Ignorar teclas de control, shift, etc., si vienen solas.
    if (event.key.length > 1) return;

    // Acumular caracteres en el buffer
    barcodeBuffer += event.key;

    // Reiniciar buffer si pasa mucho tiempo (escritura manual lenta vs scanner rápido)
    // Aumentado a 200ms para ser más tolerante con scanners lentos o lag
    clearTimeout(barcodeTimer);
    barcodeTimer = setTimeout(() => { 
        // Si el buffer se limpia por timeout, asumimos que no fue un scan completo
        // pero NO borramos el buffer si el usuario está escribiendo en el input enfocado,
        // ya que el v-model se encarga de eso. El buffer es principalmente para cuando NO hay foco.
        barcodeBuffer = ''; 
    }, 200); 
};

// Manejo manual (cuando el usuario escribe y da Enter)
const handleManualSearch = async () => {
    if (searchTerm.value.length > 2) {
        // También sanitizamos lo escrito manualmente por si acaso
        const cleanQuery = sanitizeInput(searchTerm.value);
        
        // Verificamos entidad
        const handled = await checkAndRedirect(cleanQuery);
        
        // Si fue manejado (es entidad), checkAndRedirect ya limpió o gestionó.
        // Si NO fue manejado, el watcher de `searchTerm` se encargará de filtrar productos.
        if (!handled && searchTerm.value !== cleanQuery) {
             searchTerm.value = cleanQuery; // Actualizar con la versión corregida si cambió
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
                                    <span class="font-semibold">Efectivo en caja:</span>
                                    <p class="text-lg font-bold text-right">${{ cashBalance.toFixed(2) }}</p>
                                </div>
                                <div class="border-t dark:border-gray-600 pt-2">
                                    <span class="font-semibold">Ventas (sesión actual):</span>
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
                        <Button icon="pi pi-shopping-cart" rounded severity="secondary" aria-label="Carritos en espera"
                            variant="outlined" size="medium" class="!size-8 !bg-white" />
                        <Badge v-if="pendingCarts.length" :value="pendingCarts.length" severity="contrast"
                            class="absolute top-2 right-0 transform translate-x-1/2 -translate-y-1/2" size="small">
                        </Badge>
                    </button>
                    <Button @click="$emit('openCloseSessionModal')" icon="pi pi-sign-out" rounded severity="danger"
                        v-tooltip.bottom="'Cerrar Caja'" variant="outlined" size="medium" class="!size-8 !bg-white" />
                    <Popover ref="op">
                        <PendingCartsPopover :carts="pendingCarts" @resume-cart="$emit('resumeCart', $event)"
                            @delete-cart="$emit('deleteCart', $event)" />
                    </Popover>
                </div>
            </div>
            
            <!-- BARRA DE BÚSQUEDA MEJORADA -->
            <div class="mb-4 flex gap-2 items-center">
                <div class="flex-grow relative"> <!-- Contenedor relativo para el botón absoluto -->
                    <IconField iconPosition="left" class="w-full">
                        <!-- Spinner de carga o Lupa normal -->
                        <InputIcon v-if="!isCheckingEntity" class="pi pi-search" />
                        <InputIcon v-else class="pi pi-spin pi-spinner text-blue-500 font-bold" />
                        
                        <!-- Eliminamos showClear y agregamos pr-10 para espacio del botón -->
                        <InputText 
                            v-model="searchTerm" 
                            @keydown.enter="handleManualSearch" 
                            placeholder="Escanear o buscar producto por nombre o SKU"
                            class="w-full pos-search-input pr-10" 
                        />
                    </IconField>
                    
                    <!-- Botón Absoluto de Limpieza -->
                    <button 
                        v-if="searchTerm"
                        @click="clearSearch"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 dark:text-gray-500 dark:hover:text-red-400 bg-transparent border-none cursor-pointer flex items-center justify-center transition-colors z-10 p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800"
                        type="button"
                        aria-label="Limpiar búsqueda"
                    >
                        <i class="pi pi-times text-sm font-bold"></i>
                    </button>
                </div>

                <!-- Botón de Información de Búsqueda Inteligente -->
                <Button label="Búsqueda Inteligente" icon="pi pi-sparkles" text size="small" severity="info" @click="isSmartSearchHelpVisible = true" />
            </div>

            <CategoryFilters :categories="categories" :active-category-id="selectedCategoryId"
                @filter="handleCategoryFilter" />
        </div>

        <div class="flex-grow lg:px-6 pb-6 overflow-y-auto" ref="productsContainer">
            <template v-if="loadedProducts.length > 0">
                <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-2">
                    <ProductCard v-for="product in loadedProducts" :key="`${product.id}-${product.sku}`"
                        :product="product" :cart-items="cartItems" @showDetails="showProductDetails" @addToCart="$emit('addToCart', $event)" />
                </div>
            </template>
            <p v-else-if="!isLoadingMore" class="text-center text-gray-500 mt-8">
                No se encontraron productos.
            </p>
            <div v-if="isLoadingMore" class="flex justify-center items-center h-24">
                <i class="pi pi-spin pi-spinner !text-3xl text-gray-400"></i>
            </div>
        </div>

        <ProductDetailModal v-model:visible="isDetailModalVisible" :product="selectedProductForModal"
            @addToCart="$emit('addToCart', $event)" />
        <CreateProductModal v-model:visible="isCreateProductModalVisible" @created="handleProductCreated" />
        <CashMovementModal v-if="activeSession" v-model:visible="isCashMovementModalVisible" :type="movementType"
            :session-id="activeSession.id" @submitted="handleMovementSubmitted" />

        <!-- MODAL DE AYUDA BÚSQUEDA INTELIGENTE -->
        <Dialog v-model:visible="isSmartSearchHelpVisible" modal header="🧠 Búsqueda Inteligente" :style="{ width: '50rem' }" :breakpoints="{ '960px': '75vw', '640px': '90vw' }">
            <div class="p-2 space-y-6">
                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg flex gap-4 items-start">
                    <i class="pi pi-info-circle text-2xl text-blue-600 mt-1"></i>
                    <div>
                        <h4 class="font-bold text-lg text-blue-800 dark:text-blue-200 m-0">¿Qué es esto?</h4>
                        <p class="text-base text-blue-700 dark:text-blue-300 m-0">
                            La barra de búsqueda principal no solo encuentra productos. Está diseñada para detectar automáticamente 
                            códigos escaneados de tickets o información de clientes para agilizar tu flujo de trabajo.
                            Si no cuentas con lector de códigos de barras, también puedes escribir manualmente los folios o números de teléfono y
                            presionar <kbd class="bg-gray-300 text-gray-600 rounded-md px-1 py-px">Enter</kbd> para activar la búsqueda inteligente.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Sección de Ventas -->
                    <div class="border dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex items-center gap-2 mb-2 text-primary">
                            <i class="pi pi-receipt !text-xl"></i>
                            <h3 class="font-bold text-lg m-0">Folios de venta</h3>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3 m-0">
                            Escanea el código de barras/QR de un ticket o escribe el folio (ej: <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">V-001</span>, <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">OS-V-005</span>).
                        </p>
                        <ul class="text-sm space-y-2 text-gray-700 dark:text-gray-300">
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Cancelaciones rápidas</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Devoluciones y cambios</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Agregar pagos a créditos</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Reimpresión de tickets</li>
                        </ul>
                        <small class="block mt-3 text-xs text-gray-500 italic">
                            * Aplica para ventas fisicas desde punto de venta y Órdenes de Servicio (si el módulo está activo).
                        </small>
                    </div>

                    <!-- Sección de Clientes -->
                    <div class="border dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex items-center gap-2 mb-2 text-purple-600">
                            <i class="pi pi-user !text-xl"></i>
                            <h3 class="font-bold text-lg m-0">Clientes</h3>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                            Escribe el <strong>número de teléfono</strong> (10 dígitos) o busca por <strong>nombre</strong> exacto.
                        </p>
                        <ul class="text-sm space-y-2 text-gray-700 dark:text-gray-300">
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Abonar a saldo pendiente</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Revisar historial de apartados</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Imprimir estado de cuenta</li>
                            <li class="flex items-center gap-2"><i class="pi pi-check text-green-500 !text-xs"></i> Ajustes de saldo</li>
                        </ul>
                    </div>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end">
                    <Button label="Entendido" icon="pi pi-check" @click="isSmartSearchHelpVisible = false" autofocus />
                </div>
            </template>
        </Dialog>
    </div>
</template>