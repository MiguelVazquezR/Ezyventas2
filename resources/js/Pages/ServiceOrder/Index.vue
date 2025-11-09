<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportServiceOrdersModal from './Partials/ImportServiceOrdersModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    serviceOrders: Object,
    filters: Object,
    availableTemplates: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const selectedOrders = ref([]);
const searchTerm = ref(props.filters.search || '');
const menu = ref();
const selectedOrderForMenu = ref(null);
const showImportModal = ref(false);

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

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    //{ label: 'Importar Órdenes', icon: 'pi pi-upload', command: () => showImportModal.value = true },
    { label: 'Exportar Órdenes', icon: 'pi pi-download', command: () => window.location.href = route('import-export.service-orders.export') },
]);

const deleteSingleOrder = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar esta orden de servicio?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('service-orders.destroy', selectedOrderForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => selectedOrders.value = selectedOrders.value.filter(o => o.id !== selectedOrderForMenu.value.id),
            });
        }
    });
};

const deleteSelectedOrders = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar las ${selectedOrders.value.length} órdenes seleccionadas?`,
        header: 'Eliminación Masiva',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            const idsToDelete = selectedOrders.value.map(o => o.id);
            router.post(route('service-orders.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => selectedOrders.value = [],
            });
        }
    });
};

const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => router.get(route('service-orders.show', selectedOrderForMenu.value.id)), visible: hasPermission('services.orders.see_details') },
    { label: 'Editar orden', icon: 'pi pi-pencil', command: () => router.get(route('service-orders.edit', selectedOrderForMenu.value.id)), visible: hasPermission('services.orders.edit') },
    {
        label: 'Imprimir',
        icon: 'pi pi-print',
        command: () => openPrintModal(selectedOrderForMenu.value),
        // visible: hasPermission('services.print_tickets') || hasPermission('services.print_etiquetas')
    },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleOrder, visible: hasPermission('services.orders.delete') },
]);

const toggleMenu = (event, data) => {
    selectedOrderForMenu.value = data;
    menu.value.toggle(event);
};

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

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
};

const getStatusSeverity = (status) => {
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
</script>

<template>
    <AppLayout title="Órdenes de servicio">
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Órdenes de servicio</h1>
                    <div class="flex items-center gap-2">
                        <IconField iconPosition="left" class="w-full md:w-auto">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por folio, cliente o equipo..."
                                class="w-full" />
                        </IconField>
                        <ButtonGroup>
                            <Button v-if="hasPermission('services.orders.create')" label="Nueva orden" icon="pi pi-plus"
                                @click="router.get(route('service-orders.create'))" severity="warning" />
                            <Button v-if="hasPermission('services.orders.import_export')" icon="pi pi-chevron-down"
                                @click="toggleHeaderMenu" severity="warning" />
                        </ButtonGroup>
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas -->
                <div v-if="selectedOrders.length > 0"
                    class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-2 mb-4 flex justify-between items-center">
                    <span class="font-semibold text-sm text-blue-800 dark:text-blue-200">{{ selectedOrders.length }}
                        órdenes seleccionada(s)</span>
                    <Button @click="deleteSelectedOrders" label="Eliminar" icon="pi pi-trash" size="small"
                        severity="danger" outlined />
                </div>

                <!-- Tabla de Órdenes -->
                <DataTable :value="serviceOrders.data" v-model:selection="selectedOrders" lazy paginator
                    :totalRecords="serviceOrders.total" :rows="serviceOrders.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort" removableSort
                    tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} órdenes">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="folio" header="Folio" sortable></Column>
                    <Column field="customer_name" header="Cliente" sortable></Column>
                    <Column field="item_description" header="Equipo" sortable></Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status.replace('_', ' ')" :severity="getStatusSeverity(data.status)"
                                class="capitalize" />
                        </template>
                    </Column>
                    <Column field="received_at" header="Fecha de recepción" sortable>
                        <template #body="{ data }"> {{ formatDate(data.received_at) }} </template>
                    </Column>
                    <Column field="promised_at" header="Fecha promesa" sortable>
                        <template #body="{ data }"> {{ formatDate(data.promised_at) }} </template>
                    </Column>
                    <Column field="final_total" header="Total" sortable>
                        <template #body="{ data }"> {{ new Intl.NumberFormat('es-MX', {
                            style: 'currency', currency:
                                'MXN'
                        }).format(data.final_total) }} </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> </template>
                    </Column>
                    <template #empty>
                        <div class="text-center py-4">No hay órdenes de servicio registrados.</div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- Modal de Importación -->
        <ImportServiceOrdersModal :visible="showImportModal" @update:visible="showImportModal = false" />

        <!-- Modal de Impresión -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>