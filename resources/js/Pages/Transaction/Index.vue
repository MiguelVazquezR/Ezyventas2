<script setup>
import { ref, watch, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import PrintModal from '@/Components/PrintModal.vue';
import { useToast } from 'primevue/usetoast';

// Importamos los nuevos parciales
import TransactionDrawer from './Partials/TransactionDrawer.vue';
import TransactionCancellationModal from './Partials/TransactionCancellationModal.vue';

const props = defineProps({
    transactions: Object,
    filters: Object,
    availableTemplates: Array,
    userBankAccounts: Array, // MODIFICACIÓN: Agregamos la prop de cuentas de banco
});

const confirm = useConfirm();
const toast = useToast();
const { hasPermission } = usePermissions();
const page = usePage();

const selectedTransactions = ref([]);
const searchTerm = ref(props.filters.search || '');
const menu = ref();
const selectedTransactionForMenu = ref(null);

// --- FILTROS ---
const dateRange = ref(props.filters.date_start && props.filters.date_end 
    ? [new Date(props.filters.date_start), new Date(props.filters.date_end)] 
    : null
);
const statusFilter = ref(props.filters.status || null);

const statuses = [
    { label: 'Todos', value: null },
    { label: 'Completado', value: 'completado' },
    { label: 'Pendiente', value: 'pendiente' },
    { label: 'Cancelado', value: 'cancelado' },
    { label: 'Reembolsado', value: 'reembolsado' },
    { label: 'Apartado', value: 'apartado' },
    { label: 'Cambiado', value: 'cambiado' },
    { label: 'Por entregar', value: 'por_entregar' },
    { label: 'En ruta', value: 'en_ruta' },
    { label: 'Entregado por pagar', value: 'entregado_por_pagar' },
];

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

const openPrintModal = (transaction) => {
    printDataSource.value = { type: 'transaction', id: transaction.id };
    isPrintModalVisible.value = true;
};

// --- Computado para sesión activa ---
const activeSession = computed(() => page.props.activeSession);

// --- Estado de Parciales ---
const isDrawerVisible = ref(false);
const drawerTransaction = ref(null);
const isCancellationModalVisible = ref(false);

// --- MENÚ DE ACCIONES ---
const menuItems = computed(() => {
    const transaction = selectedTransactionForMenu.value;
    if (!transaction) return [];

    const canCancelOrRefund = (() => {
        if (!transaction || !transaction.status) return false;
        // Evitamos cancelar ventas canceladas, reembolsadas o cambiadas
        return !['cancelado', 'reembolsado', 'cambiado'].includes(transaction.status);
    })();

    return [
        {
            label: 'Ver detalle',
            icon: 'pi pi-eye',
            command: () => router.get(route('transactions.show', selectedTransactionForMenu.value.id)),
            visible: hasPermission('transactions.see_details')
        },
        {
            label: 'Imprimir',
            icon: 'pi pi-print',
            command: () => openPrintModal(selectedTransactionForMenu.value),
            visible: hasPermission('pos.access')
        },
        { separator: true },
        {
            label: 'Cancelar / Devolver',
            icon: 'pi pi-times-circle',
            class: 'text-red-500 font-bold',
            disabled: !canCancelOrRefund,
            command: () => initiateCancellation(selectedTransactionForMenu.value),
            visible: hasPermission('transactions.cancel') || hasPermission('transactions.refund')
        },
        {
            label: 'Eliminar permanentemente',
            icon: 'pi pi-trash',
            class: 'text-red-500',
            command: confirmDeleteTransaction,
            visible: hasPermission('transactions.delete')
        },
    ];
});

const toggleMenu = (event, data) => {
    selectedTransactionForMenu.value = data;
    menu.value.toggle(event);
};

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox') || target.closest('a')) {
        return;
    }
    
    // Obtenemos la preferencia global dinámica configurada para ventas
    const clickAction = page.props.auth.preferences?.sale_table_row_click_action || 'Vista lateral con algunos detalles';

    if (clickAction === 'Redirección a vista de detalles') {
        router.get(route('transactions.show', event.data.id));
    } else {
        drawerTransaction.value = event.data;
        isDrawerVisible.value = true;
    }
};

const confirmDeleteTransaction = () => {
    const transaction = selectedTransactionForMenu.value;
    confirm.require({
        message: `ADVERTENCIA: Estás a punto de eliminar la venta #${transaction.folio} de forma permanente. 
                  Esta acción NO se puede deshacer. Se intentará revertir el inventario pero no se ajustarán
                  los saldos del cliente y el registro desaparecerá para siempre.`,
        header: '¿Eliminar permanentemente?',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar para siempre',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('transactions.destroy', transaction.id), { preserveScroll: true });
        }
    });
};

// --- LÓGICA UNIFICADA Y DELEGADA ---
const initiateCancellation = (transaction) => {
    selectedTransactionForMenu.value = transaction;
    
    const totalPaid = (Array.isArray(transaction.payments) ? transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);

    // Caso 1: No hay pagos, cancelación directa
    if (totalPaid <= 0.01) {
        let message = `¿Seguro que quieres cancelar la venta #${transaction.folio}? Se liberará el inventario reservado.`;
        if (transaction.status === 'apartado') {
            message = `¿Seguro que quieres cancelar este APARTADO (#${transaction.folio})? No hay pagos registrados.`;
        } else if (transaction.status === 'por_entregar') {
            message = `¿Seguro que quieres cancelar este PEDIDO (#${transaction.folio})? Se liberará el inventario reservado.`;
        }

        confirm.require({
            message: message,
            header: 'Confirmar cancelación',
            icon: 'pi pi-exclamation-triangle',
            acceptClass: 'p-button-danger',
            acceptLabel: 'Sí, cancelar',
            rejectLabel: 'No',
            accept: () => {
                router.post(route('transactions.cancel', transaction.id), {}, { preserveScroll: true });
            }
        });
        return;
    }

    // Caso 2: Hay pagos, el Modal se encarga del resto
    isCancellationModalVisible.value = true;
};

// --- FUNCIONES DE TABLA ---
const fetchData = (options = {}) => {
    let dStart = null;
    let dEnd = null;
    if (dateRange.value && dateRange.value[0]) {
        dStart = dateRange.value[0].toISOString().split('T')[0];
        dEnd = dateRange.value[1] ? dateRange.value[1].toISOString().split('T')[0] : null;
    }

    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.transactions.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : (options.sortOrder === -1 ? 'desc' : (props.filters.sortOrder || 'desc')),
        search: searchTerm.value,
        status: statusFilter.value,
        date_start: dStart,
        date_end: dEnd,
    };
    
    router.get(route('transactions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });

watch(searchTerm, () => fetchData({ page: 1 }));
watch(statusFilter, () => fetchData({ page: 1 }));
watch(dateRange, (newVal) => {
    if (!newVal || (Array.isArray(newVal) && newVal[0] && newVal[1])) fetchData({ page: 1 });
});

// Helper para colores e íconos de canales de venta
const getChannelConfig = (channel) => {
    const map = {
        'punto_de_venta': { icon: 'pi pi-shop', severity: 'info', label: 'Punto de Venta' },
        'tienda_en_linea': { icon: 'pi pi-shopping-cart', severity: 'success', label: 'Tienda en Línea' },
        'orden_de_servicio': { icon: 'pi pi-wrench', severity: 'warn', label: 'Orden de Servicio' },
        'cotizacion': { icon: 'pi pi-file-check', severity: 'secondary', label: 'Cotización' },
        'manual': { icon: 'pi pi-pen-to-square', severity: 'secondary', label: 'Manual' },
        'abono_a_saldo': { icon: 'pi pi-wallet', severity: 'success', label: 'Abono a Saldo' },
        'whatsapp': { icon: 'pi pi-whatsapp', severity: 'success', label: 'WhatsApp' }
    };
    
    if (!channel) return { icon: 'pi pi-tag', severity: 'secondary', label: 'Desconocido' };
    return map[channel] || { 
        icon: 'pi pi-tag', 
        severity: 'secondary', 
        label: channel.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) 
    };
};

const getStatusSeverity = (status) => {
    const map = { 
        completado: 'success', pendiente: 'warn', cancelado: 'danger', 
        reembolsado: 'info', apartado: 'warn', por_entregar: 'info',
        en_ruta: 'info', entregado_por_pagar: 'warn', cambiado: 'secondary'
    };
    return map[status] || 'secondary';
};

const formatStatusLabel = (status) => {
    if (!status) return '';
    const text = status.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
};

const getOrderTagLabel = (status) => {
    const pedidoStatuses = ['por_entregar', 'en_ruta', 'entregado_por_pagar'];
    return pedidoStatuses.includes(status) ? 'Pedido' : 'Comanda';
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    try {
        return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) { return dateString; }
};

const formatCurrency = (value) => {
     if (value === null || value === undefined) return '';
     const numberValue = Number(value);
     if (isNaN(numberValue)) return '';
     return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(numberValue);
};
</script>

<template>
    <AppLayout title="Historial de ventas">
        <div class="p-4 md:p-6 lg:p-8 bg-surface-100 dark:bg-surface-900 min-h-full">
            <div class="bg-white dark:bg-surface-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header con Título y Filtros -->
                <div class="mb-6 flex flex-col gap-4">
                    <div class="flex justify-between items-center">
                        <h1 class="text-2xl font-bold text-surface-800 dark:text-surface-200">Historial de ventas</h1>
                    </div>
                    
                    <!-- Barra de Herramientas de Filtros -->
                    <div class="flex flex-col md:flex-row gap-4 items-end md:items-center">
                        <!-- Buscador -->
                        <IconField iconPosition="left" class="w-full md:w-1/3">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por folio o cliente..." class="w-full" />
                        </IconField>

                        <!-- Filtro de Fechas -->
                        <div class="w-full md:w-1/4">
                            <div class="flex items-center gap-2 w-full">
                                <DatePicker v-model="dateRange" selectionMode="range" :manualInput="false" placeholder="Rango de fechas" class="w-full flex-1" showButtonBar />
                                <Button v-if="dateRange && (Array.isArray(dateRange) ? dateRange[0] : dateRange)" 
                                    icon="pi pi-times" severity="secondary" text rounded 
                                    @click="dateRange = null" 
                                    title="Limpiar fechas"
                                    class="!w-10 !h-10 !p-0 shrink-0" />
                            </div>
                        </div>

                        <!-- Filtro de Estatus -->
                        <div class="w-full md:w-1/4">
                            <Select v-model="statusFilter" :options="statuses" optionLabel="label" optionValue="value" placeholder="Filtrar por estatus" class="w-full" showClear />
                        </div>
                    </div>
                </div>

                <!-- Tabla de Transacciones -->
                <DataTable :value="transactions.data" v-model:selection="selectedTransactions" lazy paginator
                    :totalRecords="transactions.total" :rows="transactions.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort" removableSort
                    tableStyle="min-width: 60rem"
                    rowHover
                    @row-click="onRowClick"
                    class="cursor-pointer"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} ventas">
                    
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="folio" header="Folio" sortable></Column>
                    <Column field="created_at" header="Fecha y Hora" sortable>
                        <template #body="{ data }"> {{ formatDate(data.created_at) }} </template>
                    </Column>
                    <Column field="customer.name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <Link v-if="data.customer" :href="route('customers.show', data.customer.id)" class="text-primary-600 hover:underline">
                                {{ data.customer.name }}
                            </Link>
                            <span v-else-if="data.contact_info && data.contact_info.name" class="flex items-center gap-2">
                                {{ data.contact_info.name }}
                                <Tag severity="info" :value="getOrderTagLabel(data.status)" class="!text-[10px] !px-1.5 !py-0.5" />
                            </span>
                            <span v-else>Público en general</span>
                        </template>
                    </Column>
                    <Column field="channel" header="Canal" sortable>
                         <template #body="{ data }">
                            <Tag :value="getChannelConfig(data.channel).label" 
                                 :icon="getChannelConfig(data.channel).icon" 
                                 :severity="getChannelConfig(data.channel).severity" />
                        </template>
                    </Column>
                     <Column field="total" header="Total Venta" sortable class="text-right">
                        <template #body="{ data }"> {{ formatCurrency(data.total) }}
                        </template>
                    </Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="formatStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                        </template>
                    </Column>
                    <Column field="user.name" header="Cajero" sortable>
                         <template #body="{ data }">
                            {{ data.user?.name || 'N/A' }}
                        </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> 
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center py-4">No hay ventas registradas que coincidan con la búsqueda.</div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource" :available-templates="availableTemplates" />
        
        <TransactionDrawer v-model:visible="isDrawerVisible" :transaction="drawerTransaction" />
        
        <!-- MODIFICACIÓN: Pasamos userBankAccounts al modal -->
        <TransactionCancellationModal 
            v-model:visible="isCancellationModalVisible" 
            :transaction="selectedTransactionForMenu" 
            :active-session="activeSession" 
            :bank-accounts="userBankAccounts"
        />
        
    </AppLayout>
</template>