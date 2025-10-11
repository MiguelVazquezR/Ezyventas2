<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    sessions: Object,
    filters: Object,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Historial de Cortes de Caja' }
]);

const searchTerm = ref(props.filters.search || '');

const menu = ref();
const selectedSessionForMenu = ref(null);
const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => router.get(route('cash-register-sessions.show', selectedSessionForMenu.value.id)) },
    { label: 'Imprimir reporte', icon: 'pi pi-print', command: () => window.open(route('cash-register-sessions.print', selectedSessionForMenu.value.id), '_blank') },
]);

const toggleMenu = (event, data) => {
    selectedSessionForMenu.value = data;
    menu.value.toggle(event);
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.sessions.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('cash-register-sessions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};
</script>

<template>
    <Head title="Historial de Cortes de Caja" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="p-4 md:p-6 lg:p-8">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                     <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Historial de Cortes de Caja</h1>
                     <IconField iconPosition="left" class="w-full md:w-1/3">
                        <InputIcon class="pi pi-search"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por cajero o caja..." class="w-full" />
                    </IconField>
                </div>

                <!-- Tabla de Sesiones -->
                <DataTable :value="sessions.data" lazy paginator
                    :totalRecords="sessions.total" :rows="sessions.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort"
                    removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cortes">
                    <template #empty>
                        <div class="text-center py-4">No se encontraron cortes de caja.</div>
                    </template>
                    <Column field="id" header="ID Sesión" sortable></Column>
                    <Column field="cash_register.name" header="Caja" sortable></Column>
                    <Column field="closed_at" header="Fecha de Cierre" sortable>
                        <template #body="{ data }"> {{ formatDate(data.closed_at) }} </template>
                    </Column>
                    <Column field="user.name" header="Cajero" sortable></Column>
                    <Column field="opening_cash_balance" header="Fondo Inicial" sortable>
                        <template #body="{ data }"> {{ formatCurrency(data.opening_cash_balance) }} </template>
                    </Column>
                     <Column field="calculated_cash_total" header="Total Calculado" sortable>
                        <template #body="{ data }"> {{ formatCurrency(data.calculated_cash_total) }} </template>
                    </Column>
                    <Column field="cash_difference" header="Diferencia" sortable>
                        <template #body="{data}">
                            <span :class="data.cash_difference < 0 ? 'text-red-500' : (data.cash_difference > 0 ? 'text-green-500' : '')">
                                {{ formatCurrency(data.cash_difference) }}
                            </span>
                        </template>
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