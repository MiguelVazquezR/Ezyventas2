<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";

// Asumimos que pasamos paginación y filtros desde el controlador
const props = defineProps({
    planItems: Object,
    filters: Object,
});

const confirm = useConfirm();

const searchTerm = ref(props.filters?.search || '');
const selectedItems = ref([]);

// --- HELPER FUNCTIONS ---
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency', 
        currency: 'MXN'
    }).format(value);
};

const getTypeLabel = (type) => {
    return type === 'module' ? 'Módulo' : 'Límite';
};

const getTypeSeverity = (type) => {
    return type === 'module' ? 'info' : 'warn';
};

// --- DATA FETCHING ---
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.planItems.per_page,
        sortField: options.sortField || props.filters?.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('admin.plan-items.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

// --- ACCIONES ---
const menu = ref();
const selectedItemForMenu = ref(null);

const toggleMenu = (event, data) => {
    selectedItemForMenu.value = data;
    menu.value.toggle(event);
};

const deleteSingleItem = () => {
    if (!selectedItemForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el ítem "${selectedItemForMenu.value.name}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('admin.plan-items.destroy', selectedItemForMenu.value.id), {
                preserveScroll: true,
            });
        }
    });
};

const toggleStatus = () => {
    if (!selectedItemForMenu.value) return;
    router.put(route('admin.plan-items.update', selectedItemForMenu.value.id), {
        ...selectedItemForMenu.value,
        is_active: !selectedItemForMenu.value.is_active
    }, { preserveScroll: true });
};

const menuItems = ref([
    { label: 'Editar', icon: 'pi pi-pencil', command: () => { if (selectedItemForMenu.value) router.get(route('admin.plan-items.edit', selectedItemForMenu.value.id)); } },
    { separator: true },
    { label: 'Cambiar estado', icon: 'pi pi-power-off', class: 'text-orange-500', command: toggleStatus },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleItem },
]);

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
</script>

<template>
    <AppLayout title="Ítems de planes">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título (Tesla UI) -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Ítems de planes</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de módulos y límites del sistema
                    </p>
                </div>

                <!-- Barra de Herramientas -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por nombre o key..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button label="Nuevo ítem" icon="pi pi-plus" @click="router.get(route('admin.plan-items.create'))"
                            severity="primary" class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                    </div>
                </div>

                <!-- Tabla de Ítems (Tesla UI Pass-Through) -->
                <DataTable :value="planItems.data" lazy paginator
                    :totalRecords="planItems.total" :rows="planItems.per_page" :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} ítems"
                    class="cursor-pointer" rowHover :pt="dataTablePt">

                    <!-- Ícono / Identificador visual -->
                    <Column header="Ícono" style="width: 5rem">
                        <template #body="{ data }">
                            <div class="flex items-center justify-center w-10 h-10 bg-gray-100 dark:bg-[#1a1a1a] rounded-xl border border-gray-200 dark:border-[#3a3a3a]">
                                <i :class="[data.meta?.icon || 'pi pi-box', '!text-lg text-gray-500 dark:text-gray-400']"></i>
                            </div>
                        </template>
                    </Column>

                    <!-- Nombre y Key -->
                    <Column field="name" header="Detalle del ítem" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5 justify-center">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                                <span class="text-[10px] font-mono text-gray-500">{{ data.key }}</span>
                            </div>
                        </template>
                    </Column>

                    <!-- Tipo -->
                    <Column field="type" header="Tipo" sortable>
                        <template #body="{ data }">
                            <Tag :value="getTypeLabel(data.type)" :severity="getTypeSeverity(data.type)" :pt="tagPt" />
                        </template>
                    </Column>

                    <!-- Metadatos (Cantidad) -->
                    <Column header="Valor / Límite">
                        <template #body="{ data }">
                            <span v-if="data.type === 'limit' && data.meta?.quantity" class="font-medium dark:text-gray-300">
                                {{ data.meta.quantity }} unid.
                            </span>
                            <span v-else class="text-gray-400 dark:text-gray-600">--</span>
                        </template>
                    </Column>

                    <!-- Precio (Telemetría) -->
                    <Column field="monthly_price" header="Precio mensual" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-xl dark:text-white">
                                {{ formatCurrency(data.monthly_price) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Estado (LED Indicator) -->
                    <Column field="is_active" header="Estado" sortable alignFrozen="right">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <span :class="['w-2 h-2 rounded-full', data.is_active ? 'bg-green-500 animate-pulse' : 'bg-gray-400 dark:bg-gray-600']"></span>
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ data.is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- Acciones -->
                    <Column headerStyle="width: 4rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-12">
                            <i class="pi pi-box !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-sm text-gray-400 mt-2">No hay ítems configurados en el sistema.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>
    </AppLayout>
</template>