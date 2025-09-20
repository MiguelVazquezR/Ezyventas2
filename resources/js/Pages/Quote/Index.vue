<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    quotes: Object,
    filters: Object,
});

const selectedQuotes = ref([]);
const searchTerm = ref(props.filters.search || '');

const splitButtonItems = ref([
    { label: 'Importar Cotizaciones', icon: 'pi pi-upload' },
    { label: 'Exportar Cotizaciones', icon: 'pi pi-download' },
]);

const menu = ref();
const selectedQuoteForMenu = ref(null);
const menuItems = ref([
    { label: 'Ver Detalle', icon: 'pi pi-eye' },
    { label: 'Editar Cotización', icon: 'pi pi-pencil' },
    { label: 'Convertir a Venta', icon: 'pi pi-dollar' },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500' },
]);

const toggleMenu = (event, data) => {
    selectedQuoteForMenu.value = data;
    menu.value.toggle(event);
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.quotes.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('quotes.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const getStatusSeverity = (status) => {
    const map = {
        borrador: 'secondary',
        enviado: 'info',
        autorizada: 'success',
        rechazada: 'danger',
        venta_generada: 'primary',
        expirada: 'warning',
    };
    return map[status] || 'secondary';
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};
</script>

<template>
    <Head title="Cotizaciones" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                     <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Cotizaciones</h1>
                    <div class="flex items-center gap-2">
                         <IconField iconPosition="left" class="w-full md:w-auto">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por folio o cliente..." class="w-full" />
                        </IconField>
                        <SplitButton label="Nueva Cotización" icon="pi pi-plus" @click="router.get(route('quotes.create'))" :model="splitButtonItems" severity="warning"></SplitButton>
                    </div>
                </div>

                <!-- Tabla de Cotizaciones -->
                <DataTable :value="quotes.data" v-model:selection="selectedQuotes" lazy paginator
                    :totalRecords="quotes.total" :rows="quotes.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort"
                    removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cotizaciones">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="folio" header="Folio" sortable></Column>
                    <Column field="customer.name" header="Cliente" sortable></Column>
                    <Column field="expiry_date" header="Fecha de Expiración" sortable>
                        <template #body="{ data }"> {{ formatDate(data.expiry_date) }} </template>
                    </Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status.replace('_', ' ')" :severity="getStatusSeverity(data.status)" class="capitalize" />
                        </template>
                    </Column>
                     <Column field="total_amount" header="Total" sortable>
                        <template #body="{ data }"> {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(data.total_amount) }} </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" /> </template>
                    </Column>
                </DataTable>
                
                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>