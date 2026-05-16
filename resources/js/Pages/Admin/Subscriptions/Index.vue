<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    subscriptions: Object,
    filters: Object,
});

const searchTerm = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || null);

// --- HELPER FUNCTIONS (ESTADOS REALES) ---
const getComputedStatus = (subscription) => {
    // Usamos la propiedad dinámica que nos enviará el modelo desde el backend
    // Si no está (mientras lo configuras), hacemos un fallback al status viejo de la BD
    return subscription.computed_status || subscription.status;
};

const getStatusLabel = (data) => {
    const status = getComputedStatus(data);
    const statuses = {
        'activo': 'Activa',
        'expirado': 'Vencida',
        'suspendido': 'Suspendida',
        // Fallbacks por compatibilidad temporal
        'active': 'Activa',
        'past_due': 'Atrasada',
        'canceled': 'Cancelada',
        'trialing': 'De prueba',
        'unpaid': 'Sin pago',
        'expired': 'Vencida' 
    };
    return statuses[status] || status || 'Desconocido';
};

const getStatusColor = (data) => {
    const status = getComputedStatus(data);
    switch(status) {
        case 'activo':
        case 'active': 
            return 'bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.8)]';
        case 'trialing': 
            return 'bg-blue-500 animate-pulse';
        case 'past_due': 
        case 'unpaid': 
            return 'bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.8)]';
        case 'expirado':
        case 'expired': 
            return 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)]'; // LED Rojo con brillo
        case 'suspendido':
        case 'canceled': 
            return 'bg-gray-500';
        default: 
            return 'bg-gray-500';
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '--';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('es-MX', {
        year: 'numeric', month: 'short', day: 'numeric'
    }).format(date);
};

// --- DATA FETCHING ---
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.subscriptions.per_page,
        sortField: options.sortField || props.filters?.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
        status: statusFilter.value
    };
    router.get(route('admin.subscriptions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });

watch([searchTerm, statusFilter], () => fetchData({ page: 1 }));

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
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

const clearFilters = () => {
    searchTerm.value = '';
    statusFilter.value = null;
};
</script>

<template>
    <AppLayout title="Suscriptores SaaS">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header (Tesla UI) -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Suscriptores</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Directorio y estado operativo de clientes
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <div class="flex flex-col md:flex-row gap-3 w-full md:w-2/3 lg:w-1/2">
                        <IconField iconPosition="left" class="w-full">
                            <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por nombre, empresa o correo..." :pt="inputPt" class="!pl-10" />
                        </IconField>

                        <div class="w-full md:w-64 shrink-0">
                            <!-- Ejemplo de filtro por estado -->
                            <Button v-if="searchTerm || statusFilter" label="Limpiar filtros" icon="pi pi-filter-slash" @click="clearFilters"
                                severity="secondary" text class="!rounded-xl !text-xs !uppercase !tracking-wider w-full md:w-auto" />
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button label="Exportar" icon="pi pi-download" severity="secondary" outlined
                            class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                    </div>
                </div>

                <!-- Tabla Principal -->
                <DataTable :value="subscriptions.data" lazy paginator
                    :totalRecords="subscriptions.total" :rows="subscriptions.per_page" :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} suscriptores"
                    class="cursor-pointer" rowHover :pt="dataTablePt"
                    @row-click="router.get(route('admin.subscriptions.show', $event.data.id))">

                    <Column field="id" header="ID" sortable style="width: 5rem">
                        <template #body="{ data }">
                            <span class="font-mono text-gray-500">#{{ data.id }}</span>
                        </template>
                    </Column>

                    <Column field="commercial_name" header="Comercio" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5 justify-center">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.commercial_name }}</span>
                                <span class="text-[10px] font-mono text-gray-500">{{ data.business_name || 'Sin razón social' }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Contacto">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <span v-if="data.contact_email" class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                                    <i class="pi pi-envelope text-[10px] text-gray-500"></i> {{ data.contact_email }}
                                </span>
                                <span v-if="data.contact_phone" class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                                    <i class="pi pi-phone text-[10px] text-gray-500"></i> {{ data.contact_phone }}
                                </span>
                                <span v-if="!data.contact_email && !data.contact_phone" class="text-xs text-gray-500">
                                    Sin contacto
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- COLUMNA ESTADO COMPUTADO -->
                    <Column field="status" header="Estado" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <span :class="['w-2 h-2 rounded-full', getStatusColor(data)]"></span>
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ getStatusLabel(data) }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <Column field="created_at" header="Registro" sortable>
                        <template #body="{ data }">
                            <span class="text-sm dark:text-gray-400">{{ formatDate(data.created_at) }}</span>
                        </template>
                    </Column>

                    <Column headerStyle="width: 4rem; text-align: center">
                        <template #body="{ data }">
                            <!-- El botón redirige al Show, igual que darle click a la fila -->
                            <Button @click.stop="router.get(route('admin.subscriptions.show', data.id))" icon="pi pi-angle-right" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" />
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-12">
                            <i class="pi pi-users !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-sm text-gray-400 mt-2">No se encontraron suscriptores con los filtros actuales.</p>
                        </div>
                    </template>
                </DataTable>

            </div>
        </div>
    </AppLayout>
</template>