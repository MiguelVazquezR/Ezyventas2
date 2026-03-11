<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ManageStockModal from './Partials/ManageStockModal.vue';
import ImportProductsModal from './Partials/ImportProductsModal.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
import InventorySummaryModal from './Partials/InventorySummaryModal.vue'; 
import ProductDrawerDetails from './Partials/ProductDrawerDetails.vue'; // <-- IMPORTAMOS EL NUEVO COMPONENTE
import PrintModal from '@/Components/PrintModal.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    products: Object,
    filters: Object,
    productLimit: Number,
    productUsage: Number,
    availableTemplates: Array,
    stockByCategory: Array,
    userBankAccounts: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

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
const searchTerm = ref(props.filters.search || '');

const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

// --- ESTADOS DEL DRAWER Y MODALES ADICIONALES ---
const isDrawerVisible = ref(false);
const selectedProductDetails = ref(null);
const showInventorySummary = ref(false); 

// --- HELPER FUNCTIONS PARA STOCK Y VARIANTES ---
// (Mantenemos estas aquí porque se usan en la Tabla Principal)
const getVariants = (product) => {
    if (!product) return [];
    return product.product_attributes || product.productAttributes || [];
};

const hasVariants = (product) => {
    return getVariants(product).length > 0;
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
        return 'warning';
    }
    return 'success';
};
// ------------------------------------------------

// --- GESTIÓN DE STOCK (NUEVO) ---
const openStockModal = (products) => {
    productsForStockModal.value = Array.isArray(products) ? products : [products];
    showManageStockModal.value = true;
};
// --------------------------------

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

const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => { if (selectedProductForMenu.value) router.get(route('products.show', selectedProductForMenu.value.id)); }, visible: hasPermission('products.see_details') },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => { if (selectedProductForMenu.value) router.get(route('products.edit', selectedProductForMenu.value.id)); }, visible: hasPermission('products.edit') },
    { separator: true },
    { label: 'Entrada/salida de stock', icon: 'pi pi-box', class: 'text-green-600', command: () => openStockModal(selectedProductForMenu.value), visible: hasPermission('products.manage_stock') },
    { separator: true },
    { label: 'Agregar promoción', icon: 'pi pi-tag', command: () => { if (selectedProductForMenu.value) router.get(route('products.promotions.create', selectedProductForMenu.value.id)); }, visible: hasPermission('products.manage_promos') },
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
    // Ignorar clic si se hizo sobre un botón, check, o la lupa de la imagen
    if (target.closest('button') || target.closest('.p-image-preview-indicator') || target.closest('.p-checkbox')) {
        return;
    }

    selectedProductDetails.value = event.data;
    isDrawerVisible.value = true;
};

const goToDetails = (id) => {
    if (hasPermission('products.see_details')) {
        router.visit(route('products.show', id));
    }
};
</script>

<template>
    <AppLayout title="Mis productos">
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6">
                    <ProductNavigation v-if="hasPermission('products.manage_global_products')" />
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-4">
                        <IconField iconPosition="left" class="w-full md:w-1/3">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar en mis productos..." class="w-full" />
                        </IconField>
                        <div class="flex items-center gap-2">
                            <div>
                                <ButtonGroup>
                                    <Button v-if="hasPermission('products.create')" label="Nuevo producto"
                                        icon="pi pi-plus" @click="router.get(route('products.create'))"
                                        severity="warning" :disabled="limitReached" 
                                        v-tooltip.bottom="limitReached ? `Límite de ${productLimit} productos alcanzado` : 'Crear nuevo producto'" />
                                    <!-- NUEVO BOTÓN PARA ABRIR RESUMEN -->
                                    <Button icon="pi pi-chart-pie" @click="showInventorySummary = true"
                                        severity="primary" v-tooltip.top="'Ver resumen de inventario'" />
                                    <Button v-if="hasPermission('products.import_export')" icon="pi pi-chevron-down"
                                        @click="toggleHeaderMenu" severity="warning" />
                                </ButtonGroup>
                            </div>
                            <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                        </div>
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedProducts.length > 0"
                    class="bg-gray-50 dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700 rounded-lg p-2 mb-4 flex justify-between items-center transition-all duration-300">
                    <span class="font-semibold text-sm text-[#373737] dark:text-gray-200">{{ selectedProducts.length }}
                        producto(s) seleccionado(s)</span>
                    <div class="flex items-center gap-2">
                        <!-- ACCIONES MASIVAS STOCK -->
                        <Button v-if="hasPermission('products.manage_stock')" @click="openStockModal(selectedProducts)"
                            label="Ajustar stock" icon="pi pi-box" size="small" severity="info" outlined />

                        <Button v-if="hasPermission('products.delete')" @click="deleteSelectedProducts" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined />
                    </div>
                </div>

                <!-- Tabla de Productos -->
                <DataTable :value="products.data" v-model:selection="selectedProducts" lazy paginator
                    :totalRecords="products.total" :rows="products.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 75rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} productos"
                    class="p-datatable-sm cursor-pointer" rowHover @row-click="onRowClick">

                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>

                    <Column header="Imagen" style="width: 5rem">
                        <template #body="{ data }">
                            <div @click.stop class="flex items-center justify-center size-12 bg-gray-100">
                                <Image v-if="data.media && data.media.length > 0" :src="data.media[0].original_url"
                                    :alt="data.name" class="rounded-md shadow-sm !h-full object-cover" preview />
                                <div v-else
                                    class="w-12 h-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                    <i class="pi pi-image text-2xl text-gray-400 dark:text-gray-500"></i>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <Column field="sku" header="Código" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 -ml-2">
                                <Button v-if="data.sku && hasPermission('pos.access')"
                                    @click.stop="openPrintModal(data)" icon="pi pi-print" text rounded
                                    severity="secondary" v-tooltip.bottom="'Imprimir etiqueta'" />
                                <span class="font-mono text-sm">{{ data.sku }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="name" header="Nombre" sortable></Column>

                    <!-- NUEVA COLUMNA DE INDICADOR POS/INSUMO -->
                    <Column field="show_in_pos" header="Tipo / POS" sortable alignFrozen="right">
                        <template #body="{ data }">
                            <div class="flex justify-center items-center">
                                <i v-if="data.show_in_pos" class="pi pi-shop text-green-500 font-bold" v-tooltip.top="'Visible en Punto de Venta'"></i>
                                <i v-else class="pi pi-eye-slash text-gray-400 font-bold" v-tooltip.top="'Solo Insumo (Oculto en POS)'"></i>
                            </div>
                        </template>
                    </Column>

                    <Column header="Sucursales" style="min-width: 10rem">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag v-for="branch in data.branches?.slice(0, 2)" :key="branch.id" :value="branch.name"
                                    severity="info" class="!text-xs" />
                                <Tag v-if="data.branches?.length > 2" :value="`+${data.branches.length - 2}`"
                                    severity="secondary" class="!text-xs cursor-help"
                                    v-tooltip.top="data.branches.slice(2).map(b => b.name).join(', ')" />
                            </div>
                        </template>
                    </Column>

                    <Column field="location" header="Ubicación" sortable>
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ hasVariants(data) ? 'Múltiples (Ver detalle)' : (data.location || '--') }}
                            </span>
                        </template>
                    </Column>

                    <!-- SECCIÓN DINÁMICA DE EXISTENCIAS -->
                    <Column field="current_stock" header="Existencias" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center space-x-2">
                                <Tag :value="getAvailableStock(data)" :severity="getStockSeverity(data)" />

                                <Tag v-if="getCalculatedReserved(data) > 0"
                                    :value="getCalculatedReserved(data) + ' apartado(s)'"
                                    v-tooltip.bottom="`Stock físico Total: ${getCalculatedStock(data)}`"
                                    class="!bg-indigo-100 !text-indigo-600" />

                                <!-- Tooltip visual de Variantes -->
                                <i v-if="hasVariants(data)" class="pi pi-sitemap text-gray-400 cursor-help"
                                    v-tooltip.top="`Se suman las existencias de ${getVariants(data).length} variantes`">
                                </i>
                            </div>
                        </template>
                    </Column>

                    <Column field="selling_price" header="Precio" sortable>
                        <template #body="{ data }">
                            <span class="font-semibold">
                                {{ new Intl.NumberFormat('es-MX', {
                                    style: 'currency', currency: 'MXN'
                                }).format(data.selling_price) }}
                            </span>
                        </template>
                    </Column>
                    <Column field="min_stock" header="Exist. mínimas" sortable>
                        <template #body="{ data }">
                            <span class="text-sm text-gray-500">{{ data.min_stock || '--' }}</span>
                        </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <!-- Botón con stop propagation -->
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                severity="secondary" aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-gray-500 py-4">
                            No hay productos registrados.
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- DRAWER DE DETALLES DEL PRODUCTO (REFACTORIZADO) -->
        <Drawer v-model:visible="isDrawerVisible" position="right"
            class="w-full md:!w-[32rem] !bg-gray-50 dark:!bg-gray-900">
            <template #header>
                <div class="flex items-center gap-2 font-bold text-lg text-gray-800 dark:text-gray-200">
                    <i class="pi pi-box"></i>
                    <span>Vista rápida</span>
                </div>
            </template>

            <!-- USAMOS EL NUEVO COMPONENTE EXTRAÍDO -->
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

        <ImportProductsModal :visible="showImportModal" @update:visible="showImportModal = false" />

        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>