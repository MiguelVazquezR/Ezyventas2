<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddBatchStockModal from './Partials/AddBatchStockModal.vue';
import ImportProductsModal from './Partials/ImportProductsModal.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
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
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const limitReached = computed(() => {
    if (props.productLimit === -1) return false;
    return props.productUsage >= props.productLimit;
});

// --- NUEVO: Cálculo del total de unidades ---
const totalStock = computed(() => {
    if (!props.stockByCategory || props.stockByCategory.length === 0) {
        return 0;
    }
    return props.stockByCategory.reduce((total, category) => {
        // Aseguramos que el valor es numérico antes de sumar
        return total + (Number(category.products_sum_current_stock) || 0);
    }, 0);
});


const selectedProducts = ref([]);
const showAddStockModal = ref(false);
const showImportModal = ref(false);
const searchTerm = ref(props.filters.search || '');

const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

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
const getStockSeverity = (product) => {
    // Calcular el stock disponible (físico - apartado)
    const availableStock = (product.current_stock || 0) - (product.reserved_stock || 0);

    if (availableStock <= 0) return 'danger';
    // Asegurarse de que min_stock existe y es un número antes de comparar
    if (product.min_stock && typeof product.min_stock === 'number' && availableStock <= product.min_stock) {
        return 'warning';
    }
    return 'success';
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
                            <div
                                v-tooltip.bottom="limitReached ? `Límite de ${productLimit} productos alcanzado` : 'Crear nuevo producto'">
                                <ButtonGroup>
                                    <Button v-if="hasPermission('products.create')" label="Nuevo producto"
                                        icon="pi pi-plus" @click="router.get(route('products.create'))"
                                        severity="warning" :disabled="limitReached" />
                                    <Button v-if="hasPermission('products.import_export')" icon="pi pi-chevron-down"
                                        @click="toggleHeaderMenu" severity="warning" />
                                </ButtonGroup>
                            </div>
                            <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                        </div>
                    </div>
                </div>

                <!-- MEJORADO: Resumen de Stock por Categoría -->
                <Panel v-if="stockByCategory && stockByCategory.length > 0" toggleable collapsed
                    class="mb-6 !shadow-none border dark:border-gray-700">
                    <template #header>
                        <div class="flex items-center gap-2 text-gray-800 dark:text-gray-200">
                            <i class="pi pi-chart-bar"></i>
                            <span class="font-semibold">Resumen de Inventario</span>
                        </div>
                    </template>

                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        <ul class="space-y-3">
                            <li v-for="cat in stockByCategory" :key="cat.id"
                                class="flex justify-between items-baseline">
                                <span class="text-gray-600 dark:text-gray-400">{{ cat.name }}</span>
                                <span
                                    class="flex-grow border-b border-dashed border-gray-300 dark:border-gray-600 mx-2"></span>
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{
                                    cat.products_sum_current_stock }}
                                    unidades</span>
                            </li>
                        </ul>
                        <Divider />
                        <div class="flex justify-between items-center font-bold text-base mt-2">
                            <span>Total General</span>
                            <span class="text-primary-500">{{ new Intl.NumberFormat().format(totalStock) }}
                                unidades</span>
                        </div>
                    </div>
                </Panel>


                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedProducts.length > 0"
                    class="bg-gray-50 dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700 rounded-lg p-2 mb-4 flex justify-between items-center transition-all duration-300">
                    <span class="font-semibold text-sm text-[#373737] dark:text-gray-200">{{ selectedProducts.length }}
                        producto(s) seleccionado(s)</span>
                    <div class="flex items-center gap-2">
                        <Button v-if="hasPermission('products.manage_stock')" @click="showAddStockModal = true"
                            label="Dar entrada" icon="pi pi-arrow-down" size="small" severity="secondary" outlined />
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
                    class="p-datatable-sm">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column header="Imagen" style="width: 5rem">
                        <template #body="{ data }">
                            <img v-if="data.media && data.media.length > 0" :src="data.media[0].original_url"
                                :alt="data.name" class="w-12 h-12 rounded-md object-cover">
                            <div v-else
                                class="w-12 h-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <i class="pi pi-image text-2xl text-gray-400 dark:text-gray-500"></i>
                            </div>
                        </template>
                    </Column>
                    <Column field="sku" header="Código" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 -ml-2">
                                <Button v-if="data.sku && hasPermission('pos.access')" @click="openPrintModal(data)"
                                    icon="pi pi-print" text rounded severity="secondary"
                                    v-tooltip.bottom="'Imprimir Etiqueta'" />
                                <span>{{ data.sku }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="name" header="Nombre" sortable></Column>
                    <Column field="current_stock" header="Existencias" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center space-x-2">
                                <!-- El Tag muestra el stock disponible -->
                                <Tag :value="(data.current_stock || 0) - (data.reserved_stock || 0)"
                                    :severity="getStockSeverity(data)" />

                                <Tag v-if="data.reserved_stock && data.reserved_stock > 0"
                                    :value="data.reserved_stock + ' apartado(s)'"
                                    v-tooltip.bottom="`Stock físico Total: ${data.current_stock}`"
                                    class="!bg-indigo-100 !text-indigo-600" />
                            </div>
                        </template>
                    </Column>
                    <Column field="selling_price" header="Precio" sortable>
                        <template #body="{ data }">
                            {{ new Intl.NumberFormat('es-MX', {
                                style: 'currency', currency: 'MXN'
                            }).format(data.selling_price) }}
                        </template>
                    </Column>
                    <Column field="min_stock" header="Exist. mínimas" sortable></Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
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

        <!-- Modales -->
        <AddBatchStockModal :visible="showAddStockModal" :products="selectedProducts"
            @update:visible="showAddStockModal = false" />
        <ImportProductsModal :visible="showImportModal" @update:visible="showImportModal = false" />

        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>