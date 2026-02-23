<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportServiceOrdersModal from './Partials/ImportServiceOrdersModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import { usePermissions } from '@/Composables';

// Nuevas importaciones para el Drawer
import Drawer from 'primevue/drawer';
import Divider from 'primevue/divider';

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

// --- Lógica del Drawer (Vista Rápida) ---
const isDrawerVisible = ref(false);
const drawerOrder = ref(null);

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
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatFriendlyDate = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const d = new Date(dateString);
        const day = d.getDate();
        const month = new Intl.DateTimeFormat('es-MX', { month: 'long' }).format(d);
        let hour = d.getHours();
        const minute = d.getMinutes().toString().padStart(2, '0');
        const ampm = hour >= 12 ? 'pm' : 'am';
        hour = hour % 12;
        hour = hour ? hour : 12;
        return `${day} de ${month}, ${hour}:${minute}${ampm}`;
    } catch (e) {
        return dateString;
    }
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

// Helper para obtener pagos
const getOrderTotalPaid = (order) => {
    return (Array.isArray(order?.transaction?.payments) ? order.transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
};

// Helper para obtener saldo pendiente
const getOrderPending = (order) => {
    if (!order) return 0;
    const total = parseFloat(order.final_total || 0);
    const paid = getOrderTotalPaid(order);
    return Math.max(0, total - paid);
};

// --- NAVEGACIÓN Y APERTURA DEL DRAWER ---
const onRowClick = (event) => {
    const target = event.originalEvent.target;
    // Evitamos navegar si se hizo clic en un botón, checkbox o enlace
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox') || target.closest('a')) {
        return;
    }
    
    drawerOrder.value = event.data;
    isDrawerVisible.value = true;
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
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} órdenes"
                    rowHover
                    class="cursor-pointer"
                    @row-click="onRowClick">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="folio" header="Folio" sortable></Column>
                    <Column field="customer_name" header="Cliente" sortable></Column>
                    <Column field="item_description" header="Equipo" sortable></Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="(data.status || '').replace('_', ' ')" :severity="getStatusSeverity(data.status)"
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
                        <template #body="{ data }"> {{ formatCurrency(data.final_total) }} </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
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

        <!-- DRAWER DE VISTA RÁPIDA -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="!w-full md:!w-[400px]">
            <template #header>
                <div class="flex items-center gap-2">
                    <span class="font-bold text-lg">Resumen Orden #{{ drawerOrder?.folio }}</span>
                </div>
            </template>
            
            <div v-if="drawerOrder" class="flex flex-col gap-6">
                <!-- Información General -->
                <div class="flex flex-col gap-2">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 text-sm">Estatus</span>
                        <Tag :value="(drawerOrder.status || '').replace('_', ' ')" :severity="getStatusSeverity(drawerOrder.status)" class="capitalize" />
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 text-sm">Ingreso</span>
                        <span class="font-medium text-sm">{{ formatFriendlyDate(drawerOrder.received_at) }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 text-sm">Entrega Promesa</span>
                        <span class="font-medium text-sm text-primary-600 dark:text-primary-400">
                            {{ formatFriendlyDate(drawerOrder.promised_at) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 text-sm">Cliente</span>
                        <span class="font-medium text-sm text-right truncate max-w-[200px]" :title="drawerOrder.customer_name">
                            {{ drawerOrder.customer_name || 'Público en general' }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 text-sm">Técnico Asignado</span>
                        <span class="font-medium text-sm text-right">
                            {{ drawerOrder.technician_name || 'Sin asignar' }}
                        </span>
                    </div>
                </div>

                <Divider class="!my-0" />

                <!-- Datos del Equipo -->
                <div class="flex flex-col gap-2 bg-blue-50 dark:bg-blue-900/10 p-3 rounded-lg border border-blue-100 dark:border-blue-900/30">
                    <span class="text-gray-500 text-sm font-bold uppercase tracking-wider">Equipo / Dispositivo</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ drawerOrder.item_description }}</span>
                    <div v-if="drawerOrder.reported_problems" class="mt-2 text-sm text-gray-600 dark:text-gray-400 border-t border-blue-200 dark:border-blue-800/50 pt-2">
                        <span class="font-semibold block mb-1">Falla reportada:</span>
                        {{ drawerOrder.reported_problems }}
                    </div>
                </div>

                <Divider class="!my-0" />

                <!-- Lista de Artículos / Conceptos -->
                <div class="flex flex-col gap-2">
                    <span class="text-gray-500 text-sm font-bold uppercase tracking-wider">Conceptos (Refacciones/Servicios)</span>
                    <ul v-if="drawerOrder.items && drawerOrder.items.length" class="flex flex-col gap-3">
                        <li v-for="item in drawerOrder.items" :key="item.id" class="flex justify-between text-sm">
                            <div class="flex flex-col flex-1">
                                <span class="font-medium leading-tight">
                                    <span class="text-gray-500 mr-1">{{ Math.round(item.quantity) }}x</span>
                                    {{ item.description }}
                                </span>
                            </div>
                            <span class="font-semibold ml-2">{{ formatCurrency(item.line_total) }}</span>
                        </li>
                    </ul>
                    <div v-else class="text-sm text-gray-400 italic">
                        No hay artículos registrados o no están cargados en esta vista.
                    </div>
                </div>

                <Divider class="!my-0" />

                <!-- Historial de Pagos -->
                <div class="flex flex-col gap-2">
                    <span class="text-gray-500 text-sm font-bold uppercase tracking-wider">Historial de Pagos</span>
                    <ul v-if="drawerOrder.transaction && drawerOrder.transaction.payments && drawerOrder.transaction.payments.length" class="flex flex-col gap-3 relative border-l-2 border-gray-200 dark:border-gray-700 ml-2 pl-4 py-1">
                        <li v-for="payment in drawerOrder.transaction.payments" :key="payment.id" class="flex flex-col text-sm relative">
                            <!-- Timeline dot -->
                            <div class="absolute w-2 h-2 bg-primary-500 rounded-full -left-[21px] top-1.5"></div>
                            
                            <div class="flex justify-between items-start">
                                <span class="font-medium capitalize">{{ (payment.payment_method || 'Desconocido').replace(/_/g, ' ') }}</span>
                                <span class="font-bold text-green-600 dark:text-green-400">{{ formatCurrency(payment.amount) }}</span>
                            </div>
                            <span class="text-xs text-gray-500">{{ formatFriendlyDate(payment.created_at) }}</span>
                        </li>
                    </ul>
                    <div v-else class="text-sm text-gray-400 italic">No se han registrado pagos para esta orden.</div>
                </div>

                <!-- Resumen Financiero Total -->
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg flex flex-col gap-1 border dark:border-gray-700">
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Total de la orden:</span>
                        <span>{{ formatCurrency(drawerOrder.final_total) }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Abonado (Anticipos):</span>
                        <span>{{ formatCurrency(getOrderTotalPaid(drawerOrder)) }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-lg mt-2 pt-2 border-t dark:border-gray-600">
                        <span>Resta:</span>
                        <span :class="getOrderPending(drawerOrder) > 0 ? 'text-red-500' : 'text-green-500'">
                            {{ formatCurrency(getOrderPending(drawerOrder)) }}
                        </span>
                    </div>
                </div>

                <!-- Acción Footer -->
                <div class="mt-auto pt-4 flex gap-2">
                    <Button 
                        v-if="hasPermission('services.orders.see_details')"
                        label="Ver detalles completos" 
                        icon="pi pi-external-link" 
                        class="w-full" 
                        @click="router.visit(route('service-orders.show', drawerOrder.id))" 
                    />
                </div>
            </div>
        </Drawer>
    </AppLayout>
</template>