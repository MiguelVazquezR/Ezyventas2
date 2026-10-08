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
    userBankAccounts: Array, 
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

// Una venta es facturable si está completada, entregada por pagar o a crédito
// (pendiente), no es un abono a saldo y aún no tiene factura. Las ventas a
// crédito se facturan como PPD aunque no estén liquidadas.
const canInvoiceTransaction = (transaction) => {
    if (!transaction) return false;
    if (!['completado', 'entregado_por_pagar', 'pendiente'].includes(transaction.status)) return false;
    if (transaction.channel === 'abono_a_saldo') return false;
    if (transaction.invoiced) return false;
    if (transaction.status === 'pendiente') return true;
    return (parseFloat(transaction.remaining_due ?? transaction.total ?? 0) <= 0.01);
};

// Navega a la creación de factura con la venta pre-seleccionada para que
// el formulario se llene automáticamente con los datos de la venta.
const goToInvoice = (transaction) => {
    router.get(route('billing.invoices.create', { transaction: transaction.id }));
};

// Una venta ya facturada muestra un enlace a su factura: "Ver factura" si ya
// fue timbrada (certificada) o "Ver prefactura" si aún es borrador/pendiente.
const PREFACTURA_STATUSES = ['borrador', 'pendiente'];
const invoiceLinkInfo = (invoice) => {
    if (!invoice) return null;
    const isPrefactura = PREFACTURA_STATUSES.includes(invoice?.status);
    return {
        label: isPrefactura ? 'Ver prefactura' : 'Ver factura',
        icon: isPrefactura ? 'pi pi-file-edit' : 'pi pi-file-pdf',
    };
};
const goToInvoiceShow = (invoice) => {
    if (!invoice?.id) return;
    router.get(route('billing.invoices.show', invoice.id));
};

// --- MENÚ DE ACCIONES ---
const menuItems = computed(() => {
    const transaction = selectedTransactionForMenu.value;
    if (!transaction) return [];

    const canCancelOrRefund = (() => {
        if (!transaction || !transaction.status) return false;
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
        {
            label: 'Facturar',
            icon: 'pi pi-file-edit',
            command: () => goToInvoice(selectedTransactionForMenu.value),
            visible: hasPermission('invoices.create') && canInvoiceTransaction(selectedTransactionForMenu.value)
        },
        {
            label: invoiceLinkInfo(selectedTransactionForMenu.value.invoice)?.label ?? 'Ver factura',
            icon: invoiceLinkInfo(selectedTransactionForMenu.value.invoice)?.icon ?? 'pi pi-file-pdf',
            command: () => goToInvoiceShow(selectedTransactionForMenu.value.invoice),
            visible: !!selectedTransactionForMenu.value.invoice
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

const initiateCancellation = (transaction) => {
    selectedTransactionForMenu.value = transaction;
    
    const totalPaid = (Array.isArray(transaction.payments) ? transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);

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
        'punto_de_venta': { icon: 'pi pi-shop', severity: 'info', label: 'Punto de venta' },
        'tienda_en_linea': { icon: 'pi pi-shopping-cart', severity: 'success', label: 'Tienda en línea' },
        'orden_de_servicio': { icon: 'pi pi-wrench', severity: 'warn', label: 'Orden de servicio' },
        'cotizacion': { icon: 'pi pi-file-check', severity: 'secondary', label: 'Cotización' },
        'manual': { icon: 'pi pi-pen-to-square', severity: 'secondary', label: 'Manual' },
        'abono_a_saldo': { icon: 'pi pi-wallet', severity: 'success', label: 'Abono a saldo' },
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

const getOrderTagLabel = (data) => {
    const type = data.contact_info?.type;
    if (type === 'comanda') return 'Comanda';
    if (type === 'pedido') return 'Pedido';

    // Fallback para registros anteriores al guardado del tipo.
    const pedidoStatuses = ['por_entregar', 'en_ruta', 'entregado_por_pagar'];
    if (pedidoStatuses.includes(data.status) || data.delivery_date) return 'Pedido';
    return 'Comanda';
};

// Indica si la transacción es un pedido/comanda (para mostrar su etiqueta).
const isOrderLike = (data) => {
    return data.delivery_date
        || ['por_entregar', 'en_ruta', 'entregado_por_pagar'].includes(data.status)
        || !!data.contact_info?.type;
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

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors w-full' },
    label: { class: '!text-sm !py-2' },
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
};

const datePickerPt = {
    input: inputPt,
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' }
};

</script>

<template>
    <AppLayout title="Historial de ventas">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Historial de ventas</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Registro operativo y financiero
                    </p>
                </div>
                
                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <!-- Buscador -->
                    <IconField iconPosition="left" class="w-full md:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por folio o cliente..." :pt="inputPt" class="!pl-10" />
                    </IconField>

                    <!-- Filtro de Fechas -->
                    <div class="w-full md:w-1/4 flex items-center gap-2">
                        <DatePicker v-model="dateRange" selectionMode="range" :manualInput="false" placeholder="Rango de fechas" :pt="datePickerPt" showButtonBar class="w-full flex-1" />
                        <Button v-if="dateRange && (Array.isArray(dateRange) ? dateRange[0] : dateRange)" 
                            icon="pi pi-times" severity="secondary" text rounded 
                            @click="dateRange = null" 
                            v-tooltip.top="'Limpiar fechas'"
                            class="!w-10 !h-10 !p-0 shrink-0 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] transition-colors" />
                    </div>

                    <!-- Filtro de Estatus -->
                    <div class="w-full md:w-1/4">
                        <Select v-model="statusFilter" :options="statuses" optionLabel="label" optionValue="value" placeholder="Filtrar por estatus" :pt="selectPt" showClear />
                    </div>
                </div>

                <!-- Tabla de Transacciones -->
                <DataTable :value="transactions.data" v-model:selection="selectedTransactions" lazy paginator
                    :totalRecords="transactions.total" :rows="transactions.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort" removableSort
                    rowHover @row-click="onRowClick" class="cursor-pointer"
                    :pt="dataTablePt"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} ventas">
                    
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }"> 
                            <span class="font-mono font-bold dark:text-gray-300">{{ data.folio }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="created_at" header="Fecha y Hora" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-600 dark:text-gray-400">{{ formatDate(data.created_at) }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="customer.name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <div v-if="data.customer" class="flex items-center gap-2 m-0">
                                <Link :href="route('customers.show', data.customer.id)" class="font-medium text-gray-900 dark:text-gray-100 hover:text-primary-500 transition-colors m-0 block w-max">
                                    {{ data.customer.name }}
                                </Link>
                                <Tag v-if="isOrderLike(data)" severity="info" :value="getOrderTagLabel(data)" class="!text-[9px] !px-1.5 !py-0.5" />
                            </div>
                            <div v-else-if="data.contact_info && data.contact_info.name" class="flex items-center gap-2 m-0">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.contact_info.name }}</span>
                                <Tag v-if="data.channel !== 'tienda_en_linea'" severity="info" :value="getOrderTagLabel(data)" class="!text-[9px] !px-1.5 !py-0.5" />
                            </div>
                            <span v-else class="text-gray-500 italic m-0">Público en general</span>
                        </template>
                    </Column>
                    
                    <Column field="channel" header="Canal" sortable>
                         <template #body="{ data }">
                            <Tag :value="getChannelConfig(data.channel).label" 
                                 :icon="getChannelConfig(data.channel).icon" 
                                 :severity="getChannelConfig(data.channel).severity"
                                 :pt="tagPt" />
                        </template>
                    </Column>
                    
                     <Column field="total" header="Total Venta" sortable class="text-right">
                        <template #body="{ data }"> 
                            <span class="font-light tracking-tight text-lg dark:text-white">{{ formatCurrency(data.total) }}</span>
                        </template>
                    </Column>
                    
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="formatStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" :pt="tagPt" />
                        </template>
                    </Column>
                    
                    <Column field="user.name" header="Cajero" sortable>
                         <template #body="{ data }">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ data.user?.name || 'N/A' }}</span>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" /> 
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-inbox !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay ventas que coincidan con la búsqueda actual.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>

        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource" :available-templates="availableTemplates" />
        
        <TransactionDrawer v-model:visible="isDrawerVisible" :transaction="drawerTransaction" />
        
        <TransactionCancellationModal 
            v-model:visible="isCancellationModalVisible" 
            :transaction="selectedTransactionForMenu" 
            :active-session="activeSession" 
            :bank-accounts="userBankAccounts"
        />
        
    </AppLayout>
</template>