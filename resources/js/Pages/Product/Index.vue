<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddBatchStockModal from './Partials/AddBatchStockModal.vue';
import ImportProductsModal from './Partials/ImportProductsModal.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    products: Object,
    filters: Object,
});

const confirm = useConfirm();

// --- Estado y Lógica ---
const selectedProducts = ref([]);
const showAddStockModal = ref(false);
const showImportModal = ref(false);
const searchTerm = ref(props.filters.search || '');

const splitButtonItems = ref([
    { 
        label: 'Importar desde Excel', 
        icon: 'pi pi-upload',
        command: () => showImportModal.value = true
    },
    { 
        label: 'Exportar a Excel', 
        icon: 'pi pi-download',
        command: () => window.location.href = route('products.export')
    },
]);

const deleteSelectedProducts = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar los ${selectedProducts.value.length} productos seleccionados? Esta acción no se puede deshacer.`,
        header: 'Confirmación de Eliminación Masiva',
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
        header: 'Confirmar Eliminación',
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
    { label: 'Ver', icon: 'pi pi-eye', command: () => { if (selectedProductForMenu.value) router.get(route('products.show', selectedProductForMenu.value.id)); }},
    { label: 'Editar', icon: 'pi pi-pencil', command: () => { if (selectedProductForMenu.value) router.get(route('products.edit', selectedProductForMenu.value.id)); }},
    { label: 'Agregar promoción', icon: 'pi pi-tag', command: () => { if (selectedProductForMenu.value) router.get(route('products.promotions.create', selectedProductForMenu.value.id)); }},
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleProduct },
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
        product_type: 'my_products', // Siempre será 'my_products' en esta vista
    };
    router.get(route('products.index'), queryParams, { preserveState: true, replace: true });
};
const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());
const getStockSeverity = (product) => {
    if (product.current_stock <= 0) return 'danger';
    if (product.current_stock <= product.min_stock) return 'warning';
    return 'success';
};
</script>

<template>
    <Head title="Mis Productos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6">
                    <!-- Componente de Navegación -->
                    <ProductNavigation />
                    
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-4">
                        <IconField iconPosition="left" class="w-full md:w-1/3">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar en mis productos..." class="w-full" />
                        </IconField>
                        <div class="flex items-center gap-2">
                            <SplitButton label="Nuevo producto" icon="pi pi-plus" @click="router.get(route('products.create'))" :model="splitButtonItems" severity="warning"></SplitButton>
                        </div>
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedProducts.length > 0" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-2 mb-4 flex justify-between items-center transition-all duration-300">
                    <span class="font-semibold text-sm text-blue-800 dark:text-blue-200">{{ selectedProducts.length }} producto(s) seleccionado(s)</span>
                    <div class="flex items-center gap-2">
                        <Button @click="showAddStockModal = true" label="Dar Entrada" icon="pi pi-arrow-down" size="small" severity="secondary" outlined />
                        <Button @click="deleteSelectedProducts" label="Eliminar" icon="pi pi-trash" size="small" severity="danger" outlined />
                    </div>
                </div>

                <!-- Tabla de Productos -->
                <DataTable 
                    :value="products.data" 
                    v-model:selection="selectedProducts"
                    lazy paginator
                    :totalRecords="products.total"
                    :rows="products.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id"
                    @page="onPage"
                    @sort="onSort"
                    removableSort
                    tableStyle="min-width: 75rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} productos"
                    class="p-datatable-sm"
                >
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column header="Imagen" style="width: 5rem">
                        <template #body="{ data }">
                            <img v-if="data.media && data.media.length > 0" :src="data.media[0].original_url" :alt="data.name" class="w-12 h-12 rounded-md object-cover">
                            <div v-else class="w-12 h-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <i class="pi pi-image text-2xl text-gray-400 dark:text-gray-500"></i>
                            </div>
                        </template>
                    </Column>
                    <Column field="sku" header="Código" sortable></Column>
                    <Column field="name" header="Nombre" sortable></Column>
                    <Column field="current_stock" header="Existencias" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.current_stock" :severity="getStockSeverity(data)" />
                        </template>
                    </Column>
                    <Column field="selling_price" header="Precio" sortable>
                        <template #body="{ data }">
                            {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(data.selling_price) }}
                        </template>
                    </Column>
                    <Column field="min_stock" header="Exist. Mínimas" sortable></Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>
                </DataTable>
                
                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- Modales -->
        <AddBatchStockModal 
            :visible="showAddStockModal" 
            :products="selectedProducts" 
            @update:visible="showAddStockModal = false"
        />
        <ImportProductsModal 
            :visible="showImportModal" 
            @update:visible="showImportModal = false"
        />
    </AppLayout>
</template>