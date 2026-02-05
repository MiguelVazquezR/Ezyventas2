<script setup>
import { ref, watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import PrintModal from '@/Components/PrintModal.vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

// Importaciones para filtros y UI
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import RadioButton from 'primevue/radiobutton';

const props = defineProps({
    transactions: Object,
    filters: Object,
    availableTemplates: Array,
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
    { label: 'Por entregar', value: 'por_entregar' },
    { label: 'En ruta', value: 'en_ruta' },
    { label: 'Entregado por pagar', value: 'entregado_por_pagar' },
];

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

// --- Lógica Unificada de Cancelación/Devolución ---
const isCancellationModalVisible = ref(false);
const cancellationAction = ref('refund'); // 'refund' | 'penalty'
const cancellationRefundMethod = ref('cash'); // 'balance' | 'cash'
const isCancelling = ref(false);
// Usamos selectedTransactionForMenu para la transacción actual

// --- Computado para sesión activa ---
const activeSession = computed(() => page.props.activeSession);

const openPrintModal = (transaction) => {
    printDataSource.value = {
        type: 'transaction',
        id: transaction.id
    };
    isPrintModalVisible.value = true;
};

// --- MENÚ DE ACCIONES ---
const menuItems = computed(() => {
    const transaction = selectedTransactionForMenu.value;
    if (!transaction) return [];

    const canCancelOrRefund = (() => {
        if (!transaction || !transaction.status) return false;
        const status = transaction.status;
        return !['cancelado', 'reembolsado'].includes(status);
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
            separator: true
        },
        // Opción Unificada
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

// --- NAVEGACIÓN POR FILA ---
const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
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
                  los saldos del cliente y el registro desaparecerá para siempre.`,
        header: '¿Eliminar permanentemente?',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar para siempre',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('transactions.destroy', transaction.id), {
                preserveScroll: true,
            });
        }
    });
};

// --- NUEVA LÓGICA DE CANCELACIÓN / DEVOLUCIÓN ---
const initiateCancellation = (transaction) => {
    selectedTransactionForMenu.value = transaction; // Aseguramos que sea la actual
    
    const totalPaid = (Array.isArray(transaction.payments) ? transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);

    // Caso 1: No hay dinero de por medio -> Cancelación directa con confirmación simple
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

    // Caso 2: Hay dinero -> Abrir Modal Completo
    cancellationAction.value = 'refund'; // Reset default
    
    // Default Refund Method: Caja si hay sesión, sino Saldo si hay cliente
    if (activeSession.value) {
        cancellationRefundMethod.value = 'cash';
    } else if (transaction.customer_id) {
        cancellationRefundMethod.value = 'balance';
    } else {
        cancellationRefundMethod.value = null; // No hay opción válida por defecto
    }
    
    isCancellationModalVisible.value = true;
};

const submitCancellation = () => {
    const transaction = selectedTransactionForMenu.value;
    if (!transaction) return;

    isCancelling.value = true;
    
    const payload = {
        action: cancellationAction.value
    };
    
    if (cancellationAction.value === 'refund') {
        if (!cancellationRefundMethod.value) {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Selecciona un método de reembolso.', life: 3000 });
            isCancelling.value = false;
            return;
        }
        payload.refund_method = cancellationRefundMethod.value;
    }

    router.post(route('transactions.cancel', transaction.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            isCancellationModalVisible.value = false;
        },
        onFinish: () => isCancelling.value = false
    });
};

// --- FUNCIONES DE TABLA Y FORMATO ---
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
    if (!newVal) {
        fetchData({ page: 1 });
        return;
    }
    if (Array.isArray(newVal)) {
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

const formatStatusLabel = (status) => {
    if (!status) return '';
    const text = status.replace(/_/g, ' ');
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

// Helper para obtener total pagado en template
const getTransactionTotalPaid = (transaction) => {
    return (Array.isArray(transaction.payments) ? transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
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

        <PrintModal
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />

        <!-- MODAL UNIFICADO DE CANCELACIÓN Y DEVOLUCIÓN -->
        <Dialog v-model:visible="isCancellationModalVisible" modal header="Anular Transacción" :style="{ width: '32rem' }">
            <div class="p-fluid" v-if="selectedTransactionForMenu">
                <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800 mb-4 text-sm text-blue-800 dark:text-blue-200">
                    <i class="pi pi-info-circle mr-1"></i>
                    Esta venta (Folio #{{ selectedTransactionForMenu.folio }}) tiene pagos registrados por <strong>{{ formatCurrency(getTransactionTotalPaid(selectedTransactionForMenu)) }}</strong>.
                </div>

                <div class="flex flex-col gap-4">
                    <p class="font-bold text-gray-700 dark:text-gray-300">¿Qué deseas hacer con el dinero?</p>
                    
                    <!-- Opción 1: Reembolsar -->
                    <div class="border rounded p-3" :class="cancellationAction === 'refund' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-200 dark:border-gray-700'">
                        <div class="flex items-center mb-2">
                            <RadioButton v-model="cancellationAction" inputId="actionRefund" value="refund" />
                            <label for="actionRefund" class="ml-2 font-bold cursor-pointer">Devolver al cliente (Reembolso)</label>
                        </div>
                        
                        <!-- Subopciones de Reembolso (Solo si está seleccionado) -->
                        <div v-if="cancellationAction === 'refund'" class="ml-7 flex flex-col gap-2 mt-2 animate-fade-in">
                            <div v-if="activeSession" class="flex items-center">
                                <RadioButton v-model="cancellationRefundMethod" inputId="methodCash" value="cash" />
                                <label for="methodCash" class="ml-2 text-sm cursor-pointer">Entregar efectivo de caja</label>
                            </div>
                            <div v-else class="text-xs text-orange-500 ml-1">
                                * No hay caja abierta para devolver efectivo.
                            </div>

                            <div v-if="selectedTransactionForMenu.customer_id" class="flex items-center">
                                <RadioButton v-model="cancellationRefundMethod" inputId="methodBalance" value="balance" />
                                <label for="methodBalance" class="ml-2 text-sm cursor-pointer">Abonar a su saldo a favor</label>
                            </div>
                            <div v-else class="text-xs text-orange-500 ml-1">
                                * No se puede abonar a saldo (Venta sin cliente registrado).
                            </div>
                        </div>
                    </div>

                    <!-- Opción 2: Penalizar -->
                    <div class="border rounded p-3" :class="cancellationAction === 'penalty' ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-200 dark:border-gray-700'">
                        <div class="flex items-center">
                            <RadioButton v-model="cancellationAction" inputId="actionPenalty" value="penalty" />
                            <label for="actionPenalty" class="ml-2 font-bold cursor-pointer text-red-600">Cobrar como penalización</label>
                        </div>
                        <p class="text-xs text-gray-500 ml-7 mt-1">
                            El dinero NO se devuelve. Se cancela la venta pero el negocio retiene el monto pagado.
                        </p>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" @click="isCancellationModalVisible = false" text />
                <Button 
                    :label="cancellationAction === 'refund' ? 'Confirmar Devolución' : 'Confirmar Penalización'" 
                    :icon="cancellationAction === 'refund' ? 'pi pi-replay' : 'pi pi-ban'" 
                    @click="submitCancellation" 
                    :loading="isCancelling" 
                    :severity="cancellationAction === 'refund' ? 'primary' : 'danger'"
                    :disabled="cancellationAction === 'refund' && !cancellationRefundMethod"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>