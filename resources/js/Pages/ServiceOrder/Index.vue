<script setup>
import { ref, watch } from 'vue';
import { router, usePage, Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportServiceOrdersModal from './Partials/ImportServiceOrdersModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import { usePermissions } from '@/Composables';

// Importamos el nuevo componente
import ServiceOrderDrawerDetail from './Partials/ServiceOrderDrawerDetail.vue';

const props = defineProps({
    serviceOrders: Object,
    filters: Object,
    availableTemplates: Array,
});

const page = usePage();
const confirm = useConfirm();
const { hasPermission } = usePermissions();

// --- Estado y Lógica ---
const selectedOrders = ref([]);
const searchTerm = ref(props.filters.search || '');
const showImportModal = ref(false);

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};

const splitButtonItems = ref([
    { label: 'Exportar Órdenes', icon: 'pi pi-download', command: () => window.location.href = route('import-export.service-orders.export') },
]);

const menu = ref();
const selectedOrderForMenu = ref(null);

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

const openPrintModal = (serviceOrder) => {
    printDataSource.value = {
        type: 'service_order',
        id: serviceOrder.id
    };
    isPrintModalVisible.value = true;
};

// --- Estado para el Drawer (Panel lateral) ---
const isDrawerVisible = ref(false);
const drawerOrder = ref(null);

// --- Lógica de Acciones ---
const deleteSingleOrder = () => {
    if (!selectedOrderForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar esta orden de servicio?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('service-orders.destroy', selectedOrderForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    selectedOrders.value = selectedOrders.value.filter(o => o.id !== selectedOrderForMenu.value.id);
                    if (drawerOrder.value?.id === selectedOrderForMenu.value.id) {
                        isDrawerVisible.value = false;
                    }
                }
            });
        }
    });
};

const deleteSelectedOrders = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar las ${selectedOrders.value.length} órdenes seleccionadas?`,
        header: 'Eliminación Masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToDelete = selectedOrders.value.map(o => o.id);
            router.post(route('service-orders.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => {
                    selectedOrders.value = [];
                    isDrawerVisible.value = false;
                },
                preserveScroll: true,
            });
        }
    });
};

const menuItems = ref([
    { label: 'Ver detalles', icon: 'pi pi-eye', command: () => router.get(route('service-orders.show', selectedOrderForMenu.value.id)), visible: hasPermission('services.orders.see_details') },
    { label: 'Editar orden', icon: 'pi pi-pencil', command: () => router.get(route('service-orders.edit', selectedOrderForMenu.value.id)), visible: hasPermission('services.orders.edit') },
    {
        label: 'Imprimir',
        icon: 'pi pi-print',
        command: () => openPrintModal(selectedOrderForMenu.value),
    },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleOrder, visible: hasPermission('services.orders.delete') },
]);

const toggleMenu = (event, data) => {
    selectedOrderForMenu.value = data;
    menu.value.toggle(event);
};

// --- Lógica de la Tabla ---
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.serviceOrders.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('service-orders.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

// --- Funciones de formato y visualización ---
const formatDate = (dateString) => {
    if (!dateString) return '--';
    const date = new Date(dateString);
    return date.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatCurrency = (value) => {
     if (value === null || value === undefined) return '';
     const numberValue = Number(value);
     if (isNaN(numberValue)) return '';
     return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(numberValue);
};

const getStatusSeverity = (status) => {
    if (!status) return 'secondary';
    const map = {
        cancelado: 'danger',
        pendiente: 'warn',
        en_progreso: 'info',
        esperando_refaccion: 'secondary',
        terminado: 'success',
        entregado: 'success',
    };
    return map[status] || 'secondary';
};

// --- NAVEGACIÓN Y APERTURA DEL DRAWER ---
const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox') || target.closest('a')) {
        return;
    }

    const clickAction = page.props.auth.preferences?.service_order_table_row_click_action || 'Vista lateral con algunos detalles';

    if (clickAction === 'Redirección a vista de detalles') {
        router.get(route('service-orders.show', event.data.id));
    } else {
        drawerOrder.value = event.data;
        isDrawerVisible.value = true;
    }
};

const goToDetails = (id) => {
    router.visit(route('service-orders.show', id));
};

const goToEdit = (id) => {
    router.visit(route('service-orders.edit', id));
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
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
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
    <Head title="Órdenes de servicio" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Órdenes de servicio</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de reparaciones y mantenimientos
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por folio, cliente o equipo..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button v-if="hasPermission('services.orders.create')" label="Nueva orden"
                            icon="pi pi-plus" @click="router.get(route('service-orders.create'))"
                            severity="warning"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                        
                        <Button v-if="hasPermission('services.orders.import_export')" icon="pi pi-chevron-down"
                            @click="toggleHeaderMenu" severity="warning" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedOrders.length > 0" class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-2xl p-3 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 transition-all duration-300">
                    <span class="font-bold text-xs uppercase tracking-widest text-blue-700 dark:text-blue-300 m-0">
                        <i class="pi pi-check-square mr-1"></i> {{ selectedOrders.length }} seleccionados
                    </span>
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto custom-scrollbar pb-1 md:pb-0">
                        <Button @click="deleteSelectedOrders" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />
                    </div>
                </div>

                <!-- Tabla de Órdenes -->
                <DataTable :value="serviceOrders.data" v-model:selection="selectedOrders" lazy paginator
                    :totalRecords="serviceOrders.total" :rows="serviceOrders.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} órdenes"
                    class="cursor-pointer" rowHover @row-click="onRowClick" :pt="dataTablePt">

                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <span class="font-mono font-bold dark:text-gray-300">{{ data.folio }}</span>
                        </template>
                    </Column>
                    
                    <Column field="customer_name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.customer_name || 'Público general' }}</span>
                        </template>
                    </Column>
                    
                    <Column field="item_description" header="Equipo" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400 truncate max-w-[200px] block" :title="data.item_description">
                                {{ data.item_description }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="(data.status || '').replace('_', ' ')" :severity="getStatusSeverity(data.status)" class="capitalize" :pt="tagPt" />
                        </template>
                    </Column>
                    
                    <Column field="received_at" header="Fecha recepción" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-600 dark:text-gray-400">{{ formatDate(data.received_at) }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="promised_at" header="Fecha promesa" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-600 dark:text-gray-400">{{ formatDate(data.promised_at) }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="final_total" header="Total" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg dark:text-white">{{ formatCurrency(data.final_total) }}</span>
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
                            <i class="pi pi-clipboard !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay órdenes de servicio que coincidan con la búsqueda actual.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>

        <!-- Drawer de Detalles de la Orden Aislado -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="w-full md:!w-[30rem]" :pt="drawerPt">
            <template #header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-clipboard text-blue-500 !text-sm"></i>
                    </div>
                    <span class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Detalles rápidos</span>
                </div>
            </template>
            
            <ServiceOrderDrawerDetail 
                v-if="drawerOrder"
                :order="drawerOrder"
                :can-see-details="hasPermission('services.orders.see_details')"
                :can-edit="hasPermission('services.orders.edit')"
                @go-to-details="goToDetails(drawerOrder.id)"
                @go-to-edit="goToEdit(drawerOrder.id)"
            />
        </Drawer>
        
        <!-- Modal de Importación -->
        <ImportServiceOrdersModal :visible="showImportModal" @update:visible="showImportModal = false" />
        
        <!-- Modal de Impresión -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>