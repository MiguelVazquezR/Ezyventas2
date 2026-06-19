<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ManageStockModal from './Partials/ManageStockModal.vue';
import ImportProductsModal from './Partials/ImportProductsModal.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
import InventorySummaryModal from './Partials/InventorySummaryModal.vue'; 
import ProductDrawerDetails from './Partials/ProductDrawerDetails.vue';
import BulkEditProductsModal from './Partials/BulkEditProductsModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import { useToast } from "primevue/usetoast";

const props = defineProps({
    products: Object,
    filters: Object,
    productLimit: Number,
    productUsage: Number,
    availableTemplates: Array,
    stockByCategory: Array,
    userBankAccounts: Array,
});

const page = usePage();

const confirm = useConfirm();
const toast = useToast();
const { hasPermission } = usePermissions();

const hasOnlineStore = computed(() => usePage().props.auth.active_modules?.includes('module_online_store'));

const limitReached = computed(() => {
    if (props.productLimit === -1) return false;
    return props.productUsage >= props.productLimit;
});

const totalStock = computed(() => {
    if (!props.stockByCategory || props.stockByCategory.length === 0) {
        return 0;
    }
    return props.stockByCategory.reduce((total, category) => {
        return total + (Number(category.products_sum_current_stock) || 0);
    }, 0);
});

const selectedProducts = ref([]);
const showManageStockModal = ref(false);
const productsForStockModal = ref([]);
const showImportModal = ref(false);
const showBulkEditModal = ref(false); 
const searchTerm = ref(props.filters.search || '');

const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

// --- ESTADOS DEL DRAWER Y MODALES ADICIONALES ---
const isDrawerVisible = ref(false);
const selectedProductDetails = ref(null);
const showInventorySummary = ref(false); 

// --- HELPER FUNCTIONS PARA STOCK Y VARIANTES ---
const getVariants = (product) => {
    if (!product) return [];
    return product.product_attributes || product.productAttributes || [];
};

const hasVariants = (product) => {
    return getVariants(product).length > 0;
};

const isComposite = (product) => {
    return product.components && product.components.length > 0;
};

const isBulk = (product) => {
    return product.is_bulk;
};

const getCalculatedStock = (product) => {
    if (!product) return 0;
    if (hasVariants(product)) {
        return getVariants(product).reduce((sum, v) => sum + (Number(v.current_stock) || 0), 0);
    }
    return Number(product.current_stock) || 0;
};

const getCalculatedReserved = (product) => {
    if (!product) return 0;
    if (hasVariants(product)) {
        return getVariants(product).reduce((sum, v) => sum + (Number(v.reserved_stock) || 0), 0);
    }
    return Number(product.reserved_stock) || 0;
};

const getAvailableStock = (product) => {
    return getCalculatedStock(product) - getCalculatedReserved(product);
};

const getStockSeverity = (product) => {
    const availableStock = getAvailableStock(product);
    if (availableStock <= 0) return 'danger';
    const minStock = Number(product.min_stock);
    if (minStock && availableStock <= minStock) {
        return 'warn';
    }
    return 'success';
};

// Formateador de stock (Hasta 3 decimales para granel, 0 para normales)
const formatStock = (stock, isBulkProduct) => {
    const num = Number(stock) || 0;
    if (isBulkProduct) {
        return new Intl.NumberFormat('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 3 }).format(num);
    }
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(Math.round(num));
};

// --- GESTIÓN DE STOCK ---
const openStockModal = (products) => {
    productsForStockModal.value = Array.isArray(products) ? products : [products];
    showManageStockModal.value = true;
};

const openPrintModal = (product) => {
    printDataSource.value = {
        type: 'product',
        id: product.id
    };
    isPrintModalVisible.value = true;
};

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    {
        label: 'Exportar a excel',
        icon: 'pi pi-download',
        command: () => window.location.href = route('import-export.products.export')
    },
]);

const deleteSelectedProducts = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar los ${selectedProducts.value.length} productos seleccionados? Esta acción no se puede deshacer.`,
        header: 'Confirmación de eliminación masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToDelete = selectedProducts.value.map(p => p.id);
            router.post(route('products.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => selectedProducts.value = [],
                preserveScroll: true,
            });
        }
    });
};

const menu = ref();
const selectedProductForMenu = ref(null);

const deleteSingleProduct = () => {
    if (!selectedProductForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el producto "${selectedProductForMenu.value.name}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('products.destroy', selectedProductForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => selectedProducts.value = selectedProducts.value.filter(p => p.id !== selectedProductForMenu.value.id),
            });
        }
    });
};

const toggleOnline = (product) => {
    product.show_online = !product.show_online;
    router.put(route('products.toggle-online', product.id), {}, {
        preserveScroll: true,
        onError: () => { product.show_online = !product.show_online; },
    });
};

const toggleFeatured = (product) => {
    product.is_featured = !product.is_featured;
    router.put(route('products.toggle-featured', product.id), {}, {
        preserveScroll: true,
        onError: () => { product.is_featured = !product.is_featured; },
    });
};

const togglePos = (product) => {
    product.show_in_pos = !product.show_in_pos;
    router.put(route('products.toggle-pos', product.id), {}, {
        preserveScroll: true,
        onError: () => { product.show_in_pos = !product.show_in_pos; },
    });
};

const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => { if (selectedProductForMenu.value) router.get(route('products.show', selectedProductForMenu.value.id)); }, visible: hasPermission('products.see_details') },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => { if (selectedProductForMenu.value) router.get(route('products.edit', selectedProductForMenu.value.id)); }, visible: hasPermission('products.edit') },
    { separator: true },
    { label: 'Entrada/salida de stock', icon: 'pi pi-box', class: 'text-green-600', command: () => openStockModal(selectedProductForMenu.value), visible: hasPermission('products.manage_stock') },
    { separator: true },
    { label: 'Agregar promoción', icon: 'pi pi-percentage', command: () => { if (selectedProductForMenu.value) router.get(route('products.promotions.create', selectedProductForMenu.value.id)); }, visible: hasPermission('products.manage_promos') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleProduct, visible: hasPermission('products.delete') },
]);

const toggleMenu = (event, data) => {
    selectedProductForMenu.value = data;
    menu.value.toggle(event);
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.products.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('products.index'), queryParams, { preserveState: true, replace: true, });
};
const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-image-preview-indicator') || target.closest('.p-checkbox')) {
        return;
    }

    const clickAction = page.props.auth.preferences?.product_table_row_click_action || 'Vista lateral con algunos detalles';

    if (clickAction === 'Redirección a vista de detalles') {
        router.get(route('products.show', event.data.id));
    } else {
        selectedProductDetails.value = event.data;
        isDrawerVisible.value = true;
    }
};

const goToDetails = (id) => {
    if (hasPermission('products.see_details')) {
        router.visit(route('products.show', id));
    }
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' }
};

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-0 custom-scrollbar' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <AppLayout title="Mis productos">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Catálogo de productos</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de inventario y artículos
                    </p>
                </div>

                <!-- Componente de Navegación Existente -->
                <ProductNavigation v-if="hasPermission('products.manage_global_products')" class="mb-6" />

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por código o nombre..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button v-if="hasPermission('products.create')" label="Nuevo producto"
                            icon="pi pi-plus" @click="router.get(route('products.create'))"
                            severity="warning" :disabled="limitReached" 
                            v-tooltip.bottom="limitReached ? `Límite de ${productLimit} productos alcanzado` : 'Crear nuevo producto'" 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                        
                        <Button icon="pi pi-chart-pie" @click="showInventorySummary = true"
                            severity="primary" v-tooltip.top="'Ver resumen de inventario'" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Button v-if="hasPermission('products.import_export')" icon="pi pi-chevron-down"
                            @click="toggleHeaderMenu" severity="warning" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedProducts.length > 0" class="bg-primary-50 dark:bg-primary-900/10 border border-primary-100 dark:border-primary-900/30 rounded-2xl p-3 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 transition-all duration-300">
                    <span class="font-bold text-xs uppercase tracking-widest text-primary-700 dark:text-primary-300 m-0">
                        <i class="pi pi-check-square mr-1"></i> {{ selectedProducts.length }} seleccionados
                    </span>
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto custom-scrollbar pb-1 md:pb-0">
                        <Button v-if="hasPermission('products.edit')" @click="showBulkEditModal = true"
                            label="Edición rápida" icon="pi pi-pencil" size="small" severity="success" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />

                        <Button v-if="hasPermission('products.manage_stock')" @click="openStockModal(selectedProducts)"
                            label="Ajustar stock" icon="pi pi-box" size="small" severity="info" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />

                        <Button v-if="hasPermission('products.delete')" @click="deleteSelectedProducts" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />
                    </div>
                </div>

                <!-- Tabla de Productos -->
                <DataTable :value="products.data" v-model:selection="selectedProducts" lazy paginator
                    :totalRecords="products.total" :rows="products.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 75rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} productos"
                    class="cursor-pointer" rowHover @row-click="onRowClick" :pt="dataTablePt">

                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>

                    <Column header="Imagen" style="width: 5rem">
                        <template #body="{ data }">
                            <div @click.stop class="flex items-center justify-center w-10 h-10 bg-gray-100 dark:bg-[#1a1a1a] rounded-xl overflow-hidden border border-gray-200 dark:border-[#3a3a3a]">
                                <Image v-if="data.media && data.media.length > 0" :src="data.media[0].original_url"
                                    :alt="data.name" class="!h-full w-full object-cover" preview />
                                <i v-else class="pi pi-image text-lg text-gray-400 dark:text-gray-500"></i>
                            </div>
                        </template>
                    </Column>

                    <Column field="sku" header="Código" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 -ml-2">
                                <Button v-if="data.sku && hasPermission('pos.access')"
                                    @click.stop="openPrintModal(data)" icon="pi pi-print" text rounded
                                    severity="secondary" v-tooltip.bottom="'Imprimir etiqueta'" class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" />
                                <span class="font-mono font-bold dark:text-gray-300">{{ data.sku }}</span>
                            </div>
                        </template>
                    </Column>

                    <!-- COLUMNA NOMBRE -->
                    <Column field="name" header="Nombre" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1 items-start justify-center">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                                <Tag v-if="isComposite(data)" value="Kit/Combo" severity="contrast" :pt="tagPt" />
                                <Tag v-else-if="isBulk(data)" value="Venta a granel" severity="warn" :pt="tagPt" />
                            </div>
                        </template>
                    </Column>

                    <Column field="show_in_pos" header="Visibilidad" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-1.5">
                                <!-- POS visibility -->
                                <button @click.stop="togglePos(data)"
                                    class="flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider cursor-pointer transition-all border"
                                    :class="data.show_in_pos
                                        ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800/50 hover:bg-blue-100 dark:hover:bg-blue-900/40'
                                        : 'bg-gray-50 dark:bg-[#1a1a1a] text-gray-400 dark:text-gray-500 border-gray-200 dark:border-[#3a3a3a] hover:bg-gray-100 dark:hover:bg-[#2a2a2a]'"
                                    v-tooltip.top="data.show_in_pos ? 'Visible en Punto de Venta — clic para ocultar' : 'Oculto en Punto de Venta — clic para mostrar'">
                                    <i :class="data.show_in_pos ? 'pi pi-shop !text-[10px]' : 'pi pi-eye-slash !text-[10px]'" />
                                </button>
                                <!-- Online visibility -->
                                <button v-if="hasOnlineStore" @click.stop="toggleOnline(data)"
                                    class="flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider cursor-pointer transition-all border"
                                    :class="data.show_online
                                        ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800/50 hover:bg-green-100 dark:hover:bg-green-900/40'
                                        : 'bg-gray-50 dark:bg-[#1a1a1a] text-gray-400 dark:text-gray-500 border-gray-200 dark:border-[#3a3a3a] hover:bg-gray-100 dark:hover:bg-[#2a2a2a]'"
                                    v-tooltip.top="data.show_online ? 'Visible en tienda en línea — clic para ocultar' : 'Oculto en tienda — clic para mostrar'">
                                    <span class="w-1.5 h-1.5 rounded-full" :class="data.show_online ? 'bg-green-500' : 'bg-gray-400'" />
                                    <i class="pi pi-globe !text-[10px]" />
                                </button>
                                <!-- Featured -->
                                <button v-if="hasOnlineStore" @click.stop="toggleFeatured(data)"
                                    class="flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider cursor-pointer transition-all border"
                                    :class="data.is_featured
                                        ? 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800/50 hover:bg-yellow-100 dark:hover:bg-yellow-900/40'
                                        : 'bg-gray-50 dark:bg-[#1a1a1a] text-gray-400 dark:text-gray-500 border-gray-200 dark:border-[#3a3a3a] hover:bg-gray-100 dark:hover:bg-[#2a2a2a]'"
                                    v-tooltip.top="data.is_featured ? 'Destacado en tienda — clic para quitar' : 'No destacado — clic para destacar'">
                                    <i :class="data.is_featured ? 'pi pi-star-fill !text-[10px]' : 'pi pi-star !text-[10px]'" />
                                </button>
                            </div>
                        </template>
                    </Column>

                    <Column header="Sucursales" style="min-width: 10rem">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag v-for="branch in data.branches?.slice(0, 2)" :key="branch.id" :value="branch.name"
                                    severity="info" :pt="tagPt" />
                                <Tag v-if="data.branches?.length > 2" :value="`+${data.branches.length - 2}`"
                                    severity="secondary" :pt="tagPt" class="cursor-help"
                                    v-tooltip.top="data.branches.slice(2).map(b => b.name).join(', ')" />
                            </div>
                        </template>
                    </Column>

                    <Column field="location" header="Ubicación" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400">
                                <template v-if="isComposite(data)">--</template>
                                <template v-else-if="hasVariants(data)">Múltiples</template>
                                <template v-else>{{ data.location || '--' }}</template>
                            </span>
                        </template>
                    </Column>

                    <!-- COLUMNA EXISTENCIAS ACTUALIZADA CON FORMATEO DINÁMICO -->
                    <Column field="current_stock" header="Existencias" sortable>
                        <template #body="{ data }">
                            <div v-if="isComposite(data)" class="flex items-center space-x-2">
                                <Tag value="Dinámico" severity="info" icon="pi pi-link" :pt="tagPt" v-tooltip.top="'Stock dependiente de sus componentes'" />
                            </div>
                            <div v-else class="flex items-center space-x-2">
                                <Tag :value="formatStock(getAvailableStock(data), isBulk(data))" :severity="getStockSeverity(data)" :pt="tagPt" />

                                <Tag v-if="getCalculatedReserved(data) > 0"
                                    :value="formatStock(getCalculatedReserved(data), isBulk(data)) + ' apartado(s)'"
                                    v-tooltip.bottom="`Stock físico Total: ${formatStock(getCalculatedStock(data), isBulk(data))}`"
                                    severity="secondary" :pt="tagPt" />

                                <!-- Tooltip visual de Variantes -->
                                <i v-if="hasVariants(data)" class="pi pi-sitemap text-gray-400 !text-xs cursor-help"
                                    v-tooltip.top="`Se suman existencias de ${getVariants(data).length} variantes`">
                                </i>
                            </div>
                        </template>
                    </Column>

                    <Column field="selling_price" header="Precio" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg dark:text-white">
                                {{ new Intl.NumberFormat('es-MX', {
                                    style: 'currency', currency: 'MXN'
                                }).format(data.selling_price) }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column field="min_stock" header="Mínimo" sortable>
                        <template #body="{ data }">
                            <span class="font-mono dark:text-gray-400">{{ data.min_stock !== null ? formatStock(data.min_stock, isBulk(data)) : '--' }}</span>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <!-- Botón con stop propagation -->
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-box !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay productos que coincidan con la búsqueda actual.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>

        <!-- DRAWER DE DETALLES DEL PRODUCTO -->
        <Drawer v-model:visible="isDrawerVisible" position="right"
            class="w-full md:!w-[32rem]" :pt="drawerPt">
            <template #header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-box text-blue-500 !text-sm"></i>
                    </div>
                    <span class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Vista rápida</span>
                </div>
            </template>

            <ProductDrawerDetails 
                v-if="selectedProductDetails" 
                :product="selectedProductDetails" 
                :can-see-details="hasPermission('products.see_details')"
                @go-to-details="goToDetails" 
            />
        </Drawer>

        <!-- NUEVO MODAL DE RESUMEN DE INVENTARIO -->
        <InventorySummaryModal v-model:visible="showInventorySummary" :stockByCategory="stockByCategory"
            :totalStock="totalStock" />

        <!-- Modales Independientes -->
        <ManageStockModal :visible="showManageStockModal" :products="productsForStockModal"
            @update:visible="showManageStockModal = false" />

        <BulkEditProductsModal v-model:visible="showBulkEditModal" :products="selectedProducts" @success="selectedProducts = []" />

        <ImportProductsModal :visible="showImportModal" @update:visible="showImportModal = false" />

        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>