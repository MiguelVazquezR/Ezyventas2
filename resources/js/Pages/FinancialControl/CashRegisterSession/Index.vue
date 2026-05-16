<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    sessions: Object,
    filters: Object,
});

const searchTerm = ref(props.filters.search || '');

const menu = ref();
const selectedSessionForMenu = ref(null);
const menuItems = ref([
    { label: 'Ver detalles', icon: 'pi pi-eye', command: () => router.get(route('cash-register-sessions.show', selectedSessionForMenu.value.id)) },
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
        sortOrder: options.sortOrder === 1 ? 'asc' : (options.sortOrder === -1 ? 'desc' : props.filters.sortOrder),
        search: searchTerm.value,
    };
    router.get(route('cash-register-sessions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

const formatDate = (dateString) => {
    if (!dateString) return '--';
    const date = new Date(dateString);
    return date.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
        return;
    }
    router.get(route('cash-register-sessions.show', event.data.id));
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
</script>

<template>
    <Head title="Historial de Cortes de Caja" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Historial de cortes de caja</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Arqueos y cierres operativos
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por cajero o caja..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                </div>

                <!-- Tabla de Sesiones -->
                <DataTable :value="sessions.data" lazy paginator
                    :totalRecords="sessions.total" :rows="sessions.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort"
                    removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cortes"
                    class="cursor-pointer" rowHover @row-click="onRowClick" :pt="dataTablePt">
                    
                    <Column field="id" header="Folio" sortable>
                        <template #body="{ data }">
                            <span class="font-mono font-bold dark:text-gray-300">#{{ data.id }}</span>
                        </template>
                    </Column>
                    
                    <Column field="cash_register.name" header="Caja" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                    <i class="pi pi-desktop text-blue-500 !text-[10px]"></i>
                                </div>
                                <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.cash_register?.name || 'N/A' }}</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="closed_at" header="Fecha de cierre" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                                <i class="pi pi-calendar-times !text-[10px]"></i> {{ formatDate(data.closed_at) }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column field="opener.name" header="Operador" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400">
                                <i class="pi pi-user !text-[10px] mr-1"></i> {{ data.opener?.name || 'N/A' }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column field="opening_cash_balance" header="Fondo inicial" sortable>
                        <template #body="{ data }"> 
                            <span class="font-mono text-sm text-gray-600 dark:text-gray-400">{{ formatCurrency(data.opening_cash_balance) }}</span> 
                        </template>
                    </Column>
                    
                     <Column field="calculated_cash_total" header="Total calculado" sortable>
                        <template #body="{ data }"> 
                            <span class="font-light tracking-tight text-lg text-gray-900 dark:text-white">{{ formatCurrency(data.calculated_cash_total) }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="cash_difference" header="Diferencia" sortable>
                        <template #body="{data}">
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-sm" :class="data.cash_difference < 0 ? 'text-red-500' : (data.cash_difference > 0 ? 'text-green-500' : 'text-gray-400')">
                                    {{ data.cash_difference > 0 ? '+' : '' }}{{ formatCurrency(data.cash_difference) }}
                                </span>
                                <i v-if="data.cash_difference !== 0" class="pi !text-[10px]" :class="data.cash_difference < 0 ? 'pi-arrow-down text-red-500' : 'pi-arrow-up text-green-500'"></i>
                            </div>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" aria-controls="overlay_menu" /> 
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-calculator !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay cortes de caja que coincidan con la búsqueda actual.</p>
                        </div>
                    </template>
                </DataTable>
                
                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>
    </AppLayout>
</template>