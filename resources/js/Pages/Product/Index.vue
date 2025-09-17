<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// Componentes de PrimeVue
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import SelectButton from 'primevue/selectbutton';
import SplitButton from 'primevue/splitbutton';
import Menu from 'primevue/menu';
import Tag from 'primevue/tag';

const props = defineProps({
    products: Object,
    filters: Object,
});

// --- ESTADO Y LÓGICA DEL COMPONENTE ---

const splitButtonItems = ref([
    { label: 'Dar entrada a producto', icon: 'pi pi-plus' },
    { label: 'Importar productos', icon: 'pi pi-upload' },
    { label: 'Exportar productos', icon: 'pi pi-download' },
]);

const productType = ref(props.filters.product_type || 'my_products');
const productTypeOptions = ref([
    { label: 'Mis Productos', value: 'my_products' },
    { label: 'Catálogo Base', value: 'base_catalog' }
]);

const selectedProducts = ref([]);
const menu = ref();
const selectedProductForMenu = ref(null); // Para guardar el producto del menú activo

const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye' },
    {
        label: 'Editar',
        icon: 'pi pi-pencil',
        command: () => {
            if (selectedProductForMenu.value) {
                router.get(route('products.edit', selectedProductForMenu.value.id));
            }
        }
    },
    { label: 'Agregar promoción', icon: 'pi pi-tag' },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500' },
]);

const toggleMenu = (event, data) => {
    selectedProductForMenu.value = data; // Guardar el producto actual antes de abrir el menú
    menu.value.toggle(event);
};

// --- LÓGICA PARA LA TABLA (Server-Side) ---

const searchTerm = ref(props.filters.search || '');

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.products.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
        product_type: productType.value,
    };
    
    router.get(route('products.index'), queryParams, {
        preserveState: true,
        replace: true,
    });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });

watch([searchTerm, productType], () => {
    fetchData();
});

const getStockSeverity = (product) => {
    if (product.current_stock <= 0) return 'danger';
    if (product.current_stock <= product.min_stock) return 'warning';
    return 'success';
};

</script>

<template>
    <Head title="Productos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header con filtros y acciones -->
                <div class="mb-6">
                    <div class="mb-4">
                        <SelectButton v-model="productType" :options="productTypeOptions" optionLabel="label" optionValue="value" aria-labelledby="basic" />
                    </div>
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                        <IconField iconPosition="left" class="w-full md:w-1/3">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por código o nombre..." class="w-full" />
                        </IconField>
                        <div class="flex items-center gap-2">
                            <SplitButton label="Nuevo producto" icon="pi pi-plus" :model="splitButtonItems" severity="warning"></SplitButton>
                        </div>
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
    </AppLayout>
</template>