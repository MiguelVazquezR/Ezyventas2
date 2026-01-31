<script setup>
import { ref, watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import PrintModal from '@/Components/PrintModal.vue';
import { usePage } from '@inertiajs/vue3';
// Importaciones para filtros (Asegúrate de tenerlos en tu proyecto, son de PrimeVue)
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';

const props = defineProps({
    transactions: Object,
    filters: Object,
    availableTemplates: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();
const page = usePage();

const selectedTransactions = ref([]);
const searchTerm = ref(props.filters.search || '');
const menu = ref();
const selectedTransactionForMenu = ref(null);

// --- FILTROS ---
// Rango de fechas (Inicializar si viene en props)
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
    { label: 'Por entregar', value: 'por_entregar' }, // Estatus nuevos
    { label: 'En ruta', value: 'en_ruta' },
    { label: 'Entregado por pagar', value: 'entregado_por_pagar' },
];

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

// --- Refs para el modal de reembolso ---
const isRefundModalVisible = ref(false);
const refundMethod = ref('cash');
const refundingTransaction = ref(null);
const refundProcessing = ref(false);
const amountToRefund = ref(0);

// --- Computado para sesión activa ---
const activeSession = computed(() => page.props.activeSession);

const openPrintModal = (transaction) => {
    printDataSource.value = {
        type: 'transaction',
        id: transaction.id
    };
    isPrintModalVisible.value = true;
};

// --- LÓGICA DE CANCEL/REFUND ---
const menuItems = computed(() => {
    const transaction = selectedTransactionForMenu.value;
    if (!transaction) return [];

    const totalPaid = (Array.isArray(transaction.payments) ? transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);

    const canCancelComputed = (() => {
        if (!transaction || !transaction.status) return false;
        const status = transaction.status;
        const isValidStatus = !['cancelado', 'reembolsado'].includes(status);
        const hasNoPayments = totalPaid === 0;
        const isLayaway = status === 'apartado' || status === 'por_entregar'; // Permitir también en pedidos por entregar
        return isValidStatus && (hasNoPayments || isLayaway);
    })();

    const canRefundComputed = (() => {
        if (!transaction || !transaction.status) return false;
        const isCompleted = transaction.status === 'completado';
        const isPendingWithPayments = transaction.status === 'pendiente' && totalPaid > 0;
        return (isCompleted || isPendingWithPayments) && totalPaid > 0;
    })();

    return [
        {
            label: 'Ver detalle',
            icon: 'pi pi-eye',
            command: () => router.get(route('transactions.show', selectedTransactionForMenu.value.id)),
            visible: hasPermission('transactions.see_details')
        },
        {
            label: 'Generar devolución',
            icon: 'pi pi-replay',
            disabled: !canRefundComputed,
            command: () => openRefundModal(selectedTransactionForMenu.value),
            visible: hasPermission('transactions.refund')
        },
        {
            label: 'Imprimir',
            icon: 'pi pi-print',
            command: () => openPrintModal(selectedTransactionForMenu.value),
            visible: hasPermission('pos.access')
        },
        {
            separator: true
        },
        {
            label: transaction.status === 'apartado' ? 'Cancelar apartado' : 'Cancelar venta',
            icon: 'pi pi-times-circle',
            class: 'text-orange-500',
            disabled: !canCancelComputed,
            command: cancelSale,
            visible: hasPermission('transactions.cancel')
        },
        {
            label: 'Eliminar permanentemente',
            icon: 'pi pi-trash',
            class: 'text-red-500 font-bold',
            command: confirmDeleteTransaction,
            visible: hasPermission('transactions.delete') // Asegúrate de tener este permiso en el seeder o usar uno existente
        },
    ];
});

const toggleMenu = (event, data) => {
    selectedTransactionForMenu.value = data;
    menu.value.toggle(event);
};

// --- NAVEGACIÓN POR FILA ---
const onRowClick = (event) => {
    // Evitamos navegar si se hizo clic en el botón del menú (acciones)
    // El evento row-click de PrimeVue devuelve { originalEvent, data, index }
    // Verificamos si el target fue un botón o icono
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button')) {
        return;
    }
    
    if (hasPermission('transactions.see_details')) {
        router.visit(route('transactions.show', event.data.id));
    }
};

const confirmDeleteTransaction = () => {
    const transaction = selectedTransactionForMenu.value;
    confirm.require({
        message: `ADVERTENCIA: Estás a punto de eliminar la venta #${transaction.folio} de forma permanente. 
                  Esta acción NO se puede deshacer. Se intentará revertir el inventario pero no se ajustarán
                  los saldos del cliente y el registro desaparecerá para siempre.
                  Si la venta tiene pagos registrados, recomendamos cancelar en lugar de eliminar.`,
        header: '¿Eliminar permanentemente?',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar para siempre',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('transactions.destroy', transaction.id), {
                preserveScroll: true,
                onSuccess: () => {
                    // El flash message se manejará automáticamente
                }
            });
        }
    });
};

const cancelSale = () => {
    const transaction = selectedTransactionForMenu.value;
    const isLayaway = transaction.status === 'apartado';
    
    const message = isLayaway 
        ? `¿Seguro que quieres cancelar este APARTADO (#${transaction.folio})? Se liberará el inventario reservado y cualquier abono realizado se devolverá al saldo del cliente.`
        : `¿Estás seguro? Esta venta no tiene pagos registrados (#${transaction.folio}). El stock será repuesto y el saldo del cliente ajustado si fue a crédito.`;

    confirm.require({
        message: message,
        header: 'Confirmar cancelación',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, cancelar',
        rejectLabel: 'No',
        accept: () => {
            router.post(route('transactions.cancel', transaction.id), {}, { preserveScroll: true });
        }
    });
};

const openRefundModal = (transaction) => {
    refundingTransaction.value = transaction;
    refundMethod.value = transaction.customer_id ? 'cash' : 'cash';
    amountToRefund.value = (Array.isArray(transaction.payments) ? transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
    isRefundModalVisible.value = true;
};

const confirmRefund = () => {
    if (!refundingTransaction.value) return;
    refundProcessing.value = true;
    router.post(route('transactions.refund', refundingTransaction.value.id),
        { refund_method: refundMethod.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                isRefundModalVisible.value = false;
                refundingTransaction.value = null;
                amountToRefund.value = 0;
            },
            onFinish: () => { refundProcessing.value = false; }
        });
};

// --- FUNCIONES DE TABLA Y FORMATO ---
const fetchData = (options = {}) => {
    // Procesar fechas
    let dStart = null;
    let dEnd = null;
    if (dateRange.value && dateRange.value[0]) {
        dStart = dateRange.value[0].toISOString().split('T')[0];
        // Si hay fecha fin, usarla
        dEnd = dateRange.value[1] ? dateRange.value[1].toISOString().split('T')[0] : null;
    }

    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.transactions.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : (options.sortOrder === -1 ? 'desc' : (props.filters.sortOrder || 'desc')),
        search: searchTerm.value,
        // Nuevos filtros
        status: statusFilter.value,
        date_start: dStart,
        date_end: dEnd,
    };
    
    router.get(route('transactions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });

// Watchers para filtros
watch(searchTerm, () => fetchData({ page: 1 }));
watch(statusFilter, () => fetchData({ page: 1 }));

// --- MEJORA: Watcher inteligente para rango de fechas ---
watch(dateRange, (newVal) => {
    // 1. Si se limpia el filtro (es null) -> Recargar para mostrar todo
    if (!newVal) {
        fetchData({ page: 1 });
        return;
    }

    // 2. Si es un array (modo rango)
    if (Array.isArray(newVal)) {
        // Solo recargar si tenemos ambas fechas (Inicio y Fin) definidas.
        // Esto evita la recarga incómoda al primer click.
        if (newVal[0] && newVal[1]) {
            fetchData({ page: 1 });
        }
    }
});

const getStatusSeverity = (status) => {
    const map = { 
        completado: 'success', 
        pendiente: 'warn', 
        cancelado: 'danger', 
        reembolsado: 'info', 
        apartado: 'warn',
        por_entregar: 'info',
        en_ruta: 'info',
        entregado_por_pagar: 'warn'
    };
    return map[status] || 'secondary';
};

// FORMATEADOR DE ESTATUS: "por_entregar" -> "Por entregar"
const formatStatusLabel = (status) => {
    if (!status) return '';
    // Reemplaza guiones bajos por espacios
    const text = status.replace(/_/g, ' ');
    // Capitaliza solo la primera letra
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    try {
        return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) { console.error("Error formatting date:", dateString, e); return dateString; }
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
                            <DatePicker v-model="dateRange" selectionMode="range" :manualInput="false" placeholder="Rango de fechas" class="w-full" showButtonBar />
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
                            <span v-else>Público en general</span>
                        </template>
                    </Column>
                    <Column field="channel" header="Canal" sortable>
                         <template #body="{ data }">
                            <span class="capitalize">{{ (data.channel || '').replace(/_/g, ' ') }}</span>
                        </template>
                    </Column>
                     <Column field="total" header="Total Venta" sortable class="text-right">
                        <template #body="{ data }"> {{ formatCurrency(data.total) }}
                        </template>
                    </Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <!-- Uso del formateador aquí -->
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
                            <!-- Detener propagación para que no dispare onRowClick -->
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

        <PrintModal
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />

        <Dialog v-model:visible="isRefundModalVisible" modal header="Confirmar devolución" :style="{ width: '30rem' }">
            <div v-if="refundingTransaction" class="p-fluid">
                <p class="mb-4">
                    Estás a punto de generar una devolución para la venta <strong>#{{ refundingTransaction.folio }}</strong>
                    por un total pagado de <strong>{{ formatCurrency(amountToRefund) }}</strong>.
                    El stock de los productos será repuesto.
                </p>

                <p class="mb-2 font-semibold">¿Cómo deseas procesar el reembolso?</p>
                <div class="flex flex-col gap-3">
                     <div v-if="refundingTransaction.customer_id" class="flex items-center">
                        <RadioButton v-model="refundMethod" inputId="refundBalance" name="refundMethod" value="balance" />
                        <label for="refundBalance" class="ml-2">Abonar al saldo del cliente</label>
                    </div>
                    <div class="flex items-center">
                        <RadioButton v-model="refundMethod" inputId="refundCash" name="refundMethod" value="cash" :disabled="!activeSession" />
                        <label for="refundCash" class="ml-2">Retirar efectivo de la caja actual</label>
                        <small v-if="!activeSession" class="ml-2 text-orange-500">(Necesitas una sesión de caja activa)</small>
                    </div>
                </div>

                 <Message v-if="refundMethod === 'cash' && activeSession" severity="warn" :closable="false" class="mt-4">
                    Asegúrate de entregar el efectivo al cliente. Se registrará una salida en tu sesión de caja actual.
                 </Message>
                  <Message v-if="refundMethod === 'balance'" severity="info" :closable="false" class="mt-4">
                    El monto se sumará al saldo a favor del cliente.
                 </Message>
            </div>

            <template #footer>
                <Button label="Cancelar" severity="secondary" @click="isRefundModalVisible = false; amountToRefund = 0;" text />
                <Button label="Confirmar devolución" icon="pi pi-check" @click="confirmRefund" :loading="refundProcessing" :disabled="refundMethod === 'cash' && !activeSession" />
            </template>
        </Dialog>
    </AppLayout>
</template>