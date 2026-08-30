<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import { useToast } from 'primevue/usetoast';

// Importación de Componentes
import Button from 'primevue/button';
import Menu from 'primevue/menu';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import SelectButton from 'primevue/selectbutton';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';

// Modales Externos
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import PrintModal from '@/Components/PrintModal.vue';

// Parciales extraídos
import CustomerInfoCard from './Partials/CustomerInfoCard.vue';
import CustomerFinancialCard from './Partials/CustomerFinancialCard.vue';

const props = defineProps({
    customer: Object,
    historicalMovements: Array,
    userBankAccounts: Array,
    activeLayaways: Array,
    availableTemplates: Array,
    customerInvoices: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();
const page = usePage();
const toast = useToast();

// --- MÓDULO DE FACTURACIÓN (CFDI) ---
// La sección de facturas solo se muestra a suscriptores con el módulo activo.
const hasBilling = computed(() => page.props.auth?.active_modules?.includes('module_billing'));

const expandedInvoiceRows = ref({});

const statusLabel = (status) => {
    const map = {
        borrador: 'Pre-factura',
        pendiente: 'Pendiente',
        certificada: 'Timbrada',
        en_verificacion: 'En verificación',
        cancelacion_pendiente: 'Cancelación pendiente',
        cancelada: 'Cancelada',
        no_solicitada: 'No solicitada',
        solicitada: 'Solicitada',
        generada: 'Generada',
    };
    return map[status] || status;
};

const statusSeverity = (status) => {
    const map = {
        borrador: 'info',
        pendiente: 'secondary',
        certificada: 'success',
        en_verificacion: 'warn',
        cancelacion_pendiente: 'warn',
        cancelada: 'danger',
        no_solicitada: 'secondary',
        solicitada: 'info',
        generada: 'success',
    };
    return map[status] || 'secondary';
};

const tipoComprobanteLabel = (type) => {
    const map = { I: 'Ingreso', E: 'Egreso', P: 'Pago', T: 'Traslado', N: 'Nómina' };
    return map[type] || type || '—';
};

const paymentMethodLabel = (method) => {
    const map = { PUE: 'Pago en una sola exhibición', PPD: 'Pago en parcialidades o diferido' };
    return map[method] || method || '—';
};

const formatQuantity = (value) => {
    if (value === null || value === undefined) return '—';
    return Number(value).toLocaleString('es-MX', { maximumFractionDigits: 4 });
};

// --- LÓGICA DE SESIÓN ---
const activeSession = computed(() => page.props.activeSession);
const joinableSessions = computed(() => page.props.joinableSessions);
const availableCashRegisters = computed(() => page.props.availableCashRegisters);

const isPaymentModalVisible = ref(false);
const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingPaymentModal = ref(false);
const isPaymentProcessing = ref(false);

// --- Lógica para modal de impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

const openPrintModal = () => {
    printDataSource.value = { type: 'customer', id: props.customer.id };
    isPrintModalVisible.value = true;
};

// Abre el PrintModal automáticamente cuando el backend envía print_data
// (p. ej. tras registrar un abono, mostrando el ticket de abono).
watch(() => page.props.flash.print_data, (newPrintData) => {
    if (newPrintData) {
        printDataSource.value = newPrintData;
        isPrintModalVisible.value = true;
        page.props.flash.print_data = null;
    }
}, { immediate: true });

// --- Lógica para modal de ajuste ---
const isAdjustModalVisible = ref(false);

const adjustForm = useForm({
    adjustment_type: 'add',
    amount: null,
    notes: '',
    direction: 'credit', 
});

const adjustmentTypeOptions = ref([
    { label: 'Aplicar movimiento', value: 'add' },
    { label: 'Definir saldo final', value: 'set_total' },
]);

const adjustmentDirectionOptions = computed(() => {
    if (adjustForm.adjustment_type === 'add') {
        return [
            { label: 'Sumar (+)', value: 'credit', icon: 'pi pi-plus' },
            { label: 'Restar (-)', value: 'debit', icon: 'pi pi-minus' }
        ];
    } else {
        return [
            { label: 'A favor (Positivo)', value: 'credit', icon: 'pi pi-arrow-up' },
            { label: 'Deudor (Negativo)', value: 'debit', icon: 'pi pi-arrow-down' }
        ];
    }
});

const openAdjustModal = () => {
    adjustForm.reset();
    adjustForm.direction = 'credit'; 
    isAdjustModalVisible.value = true;
};

const submitAdjustment = () => {
    adjustForm.transform((data) => {
        let finalAmount = Math.abs(Number(data.amount));
        if (data.direction === 'debit') {
            finalAmount = -finalAmount;
        }
        return {
            ...data,
            amount: finalAmount,
        }
    }).post(route('customers.adjustBalance', props.customer.id), {
        onSuccess: () => {
            isAdjustModalVisible.value = false;
            adjustForm.reset();
        },
        preserveScroll: true,
    });
};

// --- Resto de lógica (Add Balance, etc.) ---
const handleOpenAddBalanceFlow = () => {
    if (activeSession.value) {
        isPaymentModalVisible.value = true;
    } else if (joinableSessions.value && joinableSessions.value.length > 0) {
        sessionModalAwaitingPaymentModal.value = true;
        isJoinSessionModalVisible.value = true;
    } else {
        sessionModalAwaitingPaymentModal.value = true;
        isStartSessionModalVisible.value = true;
    }
};

watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingPaymentModal.value) {
        sessionModalAwaitingPaymentModal.value = false;
        isPaymentModalVisible.value = true;
    }
});

const handleBalancePaymentSubmit = (paymentData) => {
    if (!activeSession.value) {
        usePage().props.flash.error = 'No hay una sesión de caja activa para registrar el pago.';
        return;
    }

    const payload = {
        ...paymentData,
        cash_register_session_id: activeSession.value.id
    };

    isPaymentProcessing.value = true;

    router.post(route('customers.payments.store', props.customer.id), payload, {
        // El PrintModal se abre solo con el print_data que envía el backend.
        onSuccess: () => {
            isPaymentModalVisible.value = false;
        },
        onFinish: () => {
            isPaymentProcessing.value = false;
        },
        preserveScroll: true,
    });
};

const deleteCustomer = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar a ${props.customer.name}? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('customers.destroy', props.customer.id));
        }
    });
};

const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const actionItems = computed(() => [
    { label: 'Abonar / agregar saldo', icon: 'pi pi-dollar', command: handleOpenAddBalanceFlow, visible: hasPermission('customers.edit') },
    { label: 'Ajuste de saldo manual', icon: 'pi pi-sliders-h', command: openAdjustModal, visible: hasPermission('customers.edit') },
    { separator: true },
    { label: 'Imprimir Ficha / Ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('customers.see_details') },
    { label: 'Editar cliente', icon: 'pi pi-pencil', command: () => router.get(route('customers.edit', props.customer.id)), visible: hasPermission('customers.edit') },
    {
        label: 'Estado de cuenta (PDF)',
        icon: 'pi pi-file-pdf',
        command: () => window.open(route('customers.printStatement', props.customer.id), '_blank'),
        visible: hasPermission('customers.see_details')
    },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteCustomer, visible: hasPermission('customers.delete') },
]);

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};

const formatDateOnly = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        return new Date(dateString).toLocaleDateString('es-MX', { dateStyle: 'medium' });
    } catch (e) {
        return dateString;
    }
};

const isExpired = (dateString) => {
    if (!dateString) return false;
    const expiration = new Date(dateString + 'T00:00:00');
    const today = new Date();
    today.setHours(0,0,0,0);
    return expiration < today;
};

const getBalanceClass = (balance) => {
    if (balance > 0) return 'text-green-600 dark:text-green-400';
    if (balance < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-400';
};

const getTransactionStatusSeverity = (status) => {
    const map = {
        completado: 'success',
        pendiente: 'warn',
        cancelado: 'danger',
        reembolsado: 'info',
        apartado: 'info',
    };
    return map[status] || 'secondary';
};

// --- Estado para filas expandidas en las tablas ---
const expandedLayawayRows = ref({});
const expandedTransactionRows = ref({});

// --- TESLA UI PASS-THROUGH (PT) ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
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

const subDataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-100 dark:bg-[#111111]' },
    headerCell: { class: 'bg-transparent text-[9px] uppercase tracking-widest text-gray-500 font-bold py-3 px-3 border-b border-gray-200 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#1a1a1a] hover:bg-gray-100 dark:hover:bg-[#232323] transition-colors text-xs text-gray-600 dark:text-gray-400 group' },
    bodyCell: { class: 'py-3 px-3 border-b border-gray-100 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};

const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } 
};

const selectButtonPt = {
    root: { class: 'flex rounded-xl overflow-hidden border border-gray-200 dark:border-[#3a3a3a]' },
    button: { class: 'flex-1 py-2 text-[10px] uppercase tracking-widest font-bold dark:bg-[#1a1a1a] dark:text-gray-400 hover:dark:bg-[#2a2a2a] transition-colors border-none' }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm w-full font-mono' }
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm w-full' }
};
</script>

<template>
    <Head :title="`Cliente: ${customer.name}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('customers.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al directorio
                </Link>
            </div>

            <!-- Header -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50 font-bold text-2xl">
                        {{ customer.name.substring(0, 1).toUpperCase() }}
                    </div>
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                            {{ customer.name }}
                        </h1>
                        <p v-if="customer.company_name" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-1.5">
                            <i class="pi pi-building !text-[10px]"></i> {{ customer.company_name }}
                        </p>
                    </div>
                </div>
                
                <div class="w-full sm:w-auto shrink-0 flex gap-2">
                    <Button type="button" label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                    <Menu ref="menu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- Columna Izquierda: Información -->
                <div class="lg:col-span-1 space-y-6 lg:space-y-8 flex flex-col">
                    <CustomerInfoCard :customer="customer" />
                    <CustomerFinancialCard v-if="hasPermission('customers.see_financial_info')" :customer="customer" />
                </div>

                <!-- Columna Derecha: Historial -->
                <div class="lg:col-span-2 space-y-6 lg:space-y-8 flex flex-col">
                    
                    <!-- Tabla de Apartados -->
                    <div v-if="activeLayaways && activeLayaways.length > 0" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex justify-between items-start">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Apartados activos</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Mercancía reservada por liquidar</p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                                <i class="pi pi-bookmark !text-sm text-purple-500"></i>
                            </div>
                        </div>

                        <DataTable v-model:expandedRows="expandedLayawayRows" :value="activeLayaways" dataKey="id" responsiveLayout="scroll" :paginator="activeLayaways.length > 5" :rows="5" sortField="created_at" :sortOrder="-1" :pt="dataTablePt">
                            <Column expander style="width: 3rem" />
                            <Column field="folio" header="Folio">
                                <template #body="{ data }">
                                    <Link :href="route('transactions.show', data.id)" class="text-primary-600 dark:text-primary-400 font-medium hover:underline">
                                        #{{ data.folio }}
                                    </Link>
                                </template>
                            </Column>
                            <Column field="created_at" header="Fecha registro" sortable>
                                <template #body="{ data }"><span class="text-xs">{{ formatDateOnly(data.created_at) }}</span></template>
                            </Column>
                            <Column field="layaway_expiration_date" header="Vencimiento" sortable>
                                <template #body="{ data }">
                                    <span class="text-xs font-bold" :class="isExpired(data.layaway_expiration_date) ? 'text-red-500' : 'text-gray-700 dark:text-gray-300'">
                                        {{ formatDateOnly(data.layaway_expiration_date) }}
                                    </span>
                                </template>
                            </Column>
                            <Column field="total_amount" header="Total">
                                <template #body="{ data }"><span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(data.total_amount) }}</span></template>
                            </Column>
                            <Column field="pending_amount" header="Deuda">
                                <template #body="{ data }">
                                    <span class="font-mono font-bold text-red-500">
                                        {{ formatCurrency(data.pending_amount) }}
                                    </span>
                                </template>
                            </Column>
                            
                            <template #expansion="slotProps">
                                <div class="p-4 mx-4 my-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#2a2a2a]">
                                    <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Artículos apartados</h4>
                                    <DataTable :value="slotProps.data.items" :pt="subDataTablePt">
                                        <Column field="quantity" header="Cant." style="width: 5rem">
                                            <template #body="{ data: item }"><span class="font-mono">{{ item.quantity }}</span></template>
                                        </Column>
                                        <Column field="description" header="Descripción">
                                            <template #body="{ data: item }"><span class="font-medium text-gray-900 dark:text-gray-100">{{ item.description }}</span></template>
                                        </Column>
                                        <Column field="line_total" header="Subtotal" class="text-right">
                                            <template #body="{ data: item }"><span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</span></template>
                                        </Column>
                                    </DataTable>
                                </div>
                            </template>
                        </DataTable>
                    </div>

                    <!-- Tabla de Ventas / Órdenes -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex justify-between items-start">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Historial de Ventas y Órdenes</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Registro histórico del cliente</p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                <i class="pi pi-shopping-bag !text-sm text-blue-500"></i>
                            </div>
                        </div>

                        <DataTable v-model:expandedRows="expandedTransactionRows" :value="customer.transactions" dataKey="id" responsiveLayout="scroll" :paginator="customer.transactions?.length > 5" :rows="5" :pt="dataTablePt">
                            <Column expander style="width: 3rem" />
                            <Column field="folio" header="Folio">
                                <template #body="{ data }">
                                    <div class="flex flex-col gap-1 items-start">
                                        <Link :href="route('transactions.show', data.id)" class="text-primary-600 dark:text-primary-400 font-medium hover:underline">
                                        #{{ data.folio }}
                                        </Link>
                                        <span v-if="data.channel === 'pos'" class="text-[9px] uppercase tracking-widest font-bold bg-gray-100 dark:bg-[#3a3a3a] text-gray-500 dark:text-gray-400 px-1.5 py-0.5 rounded">POS</span>
                                        <span v-if="data.channel === 'orden_de_servicio'" class="text-[9px] uppercase tracking-widest font-bold bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-1.5 py-0.5 rounded border border-blue-100 dark:border-blue-800">Orden de S.</span>
                                    </div>
                                </template>
                            </Column>
                            <Column field="created_at" header="Fecha" sortable>
                                <template #body="{ data }"> <span class="text-xs">{{ formatDate(data.created_at) }}</span></template>
                            </Column>
                            <Column field="total" header="Total">
                                <template #body="{ data }">
                                    <span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(data.total) }}</span>
                                </template>
                            </Column>
                            <Column field="status" header="Estatus">
                                <template #body="{ data }">
                                    <Tag :value="data.status.replace('_', ' ')" :severity="getTransactionStatusSeverity(data.status)" class="capitalize" :pt="tagPt" />
                                </template>
                            </Column>
                            
                            <template #expansion="slotProps">
                                <div class="p-4 mx-4 my-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#2a2a2a]">
                                    <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-[#3a3a3a] pb-3">
                                        <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                            {{ slotProps.data.channel === 'orden_de_servicio' ? 'Detalles de la orden de servicio' : 'Artículos de la venta' }}
                                        </h4>
                                        <div class="text-[10px] uppercase tracking-widest text-gray-500">
                                            <i class="pi pi-user !text-[9px] mr-1"></i> {{ slotProps.data.user?.name || 'Sistema' }}
                                        </div>
                                    </div>
                                    
                                    <div v-if="slotProps.data.channel === 'orden_de_servicio' && slotProps.data.transactionable" class="mb-4 p-4 bg-white dark:bg-[#232323] rounded-xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2">
                                        <div class="flex items-start gap-2">
                                            <i class="pi pi-box mt-0.5 text-gray-400 !text-sm"></i>
                                            <div>
                                                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Equipo / artículo</span>
                                                <span class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ slotProps.data.transactionable.item_description || 'No especificado' }}</span>
                                            </div>
                                        </div>
                                        <div class="flex items-start gap-2 mt-1">
                                            <i class="pi pi-exclamation-circle mt-0.5 text-orange-400 !text-sm"></i>
                                            <div>
                                                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Problema reportado</span>
                                                <span class="text-sm text-gray-700 dark:text-gray-300 italic m-0">{{ slotProps.data.transactionable.reported_problems || 'No especificado' }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <DataTable :value="slotProps.data.channel === 'orden_de_servicio' ? (slotProps.data.transactionable?.items || []) : slotProps.data.items" :pt="subDataTablePt">
                                        <Column field="quantity" header="Cant." style="width: 4rem">
                                            <template #body="{ data: item }"><span class="font-mono text-xs">{{ item.quantity }}</span></template>
                                        </Column>
                                        <Column field="description" header="Descripción">
                                            <template #body="{ data: item }">
                                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ item.description }}</span>
                                                <div v-if="item.discount_reason && slotProps.data.channel !== 'orden_de_servicio'" class="text-[10px] text-orange-500 mt-1 flex items-center gap-1">
                                                    <i class="pi pi-tag !text-[8px]"></i> {{ item.discount_reason }}
                                                </div>
                                            </template>
                                        </Column>
                                        <Column field="unit_price" header="P. Unit" class="text-right">
                                            <template #body="{ data: item }"><span class="font-mono">{{ formatCurrency(item.unit_price) }}</span></template>
                                        </Column>
                                        <Column v-if="slotProps.data.channel !== 'orden_de_servicio'" field="discount_amount" header="Desc." class="text-right">
                                            <template #body="{ data: item }">
                                                <span class="font-mono text-red-500">{{ item.discount_amount > 0 ? '-' + formatCurrency(item.discount_amount) : '--' }}</span>
                                            </template>
                                        </Column>
                                        <Column field="line_total" header="Subtotal" class="text-right">
                                            <template #body="{ data: item }"><span class="font-mono font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</span></template>
                                        </Column>
                                        <template #empty>
                                            <div class="text-center text-gray-500 py-3 text-xs italic">No hay conceptos registrados para esta transacción.</div>
                                        </template>
                                    </DataTable>
                                </div>
                            </template>
                            <template #empty>
                                <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                                    <i class="pi pi-shopping-bag !text-3xl text-gray-400 mb-3"></i>
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin ventas</p>
                                    <p class="text-xs text-gray-400 mt-1">Este cliente no ha realizado compras.</p>
                                </div>
                            </template>
                        </DataTable>
                    </div>

                    <!-- Historial de Movimientos de Saldo -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex justify-between items-start">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Historial de movimientos de saldo</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Abonos, compras a crédito y ajustes</p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                                <i class="pi pi-sort-alt !text-sm text-emerald-500"></i>
                            </div>
                        </div>
                        
                        <DataTable :value="historicalMovements" responsiveLayout="scroll" :paginator="historicalMovements?.length > 5" :rows="5" sortField="date" :sortOrder="-1" :pt="dataTablePt">
                            <Column field="date" header="Fecha" sortable>
                                <template #body="{ data }"><span class="text-xs">{{ formatDate(data.date) }}</span></template>
                            </Column>
                            <Column field="type" header="Tipo">
                                <template #body="{ data }">
                                    <span class="text-[10px] uppercase tracking-widest font-bold" :class="data.type.toLowerCase().includes('abono') ? 'text-green-500' : 'text-gray-500'">{{ data.type }}</span>
                                </template>
                            </Column>
                            <Column field="description" header="Descripción">
                                <template #body="{ data }">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 leading-tight m-0">{{ data.description }}</span>
                                </template>
                            </Column>
                            <Column field="amount" header="Monto">
                                <template #body="{ data }">
                                    <span class="font-mono text-sm" :class="{ 'text-green-600 dark:text-green-400': data.type.toLowerCase().includes('abono'), 'text-gray-900 dark:text-white': !data.type.toLowerCase().includes('abono') }">
                                        {{ data.type.toLowerCase().includes('abono') ? '+' : '' }}{{ formatCurrency(data.amount) }}
                                    </span>
                                </template>
                            </Column>
                            <Column field="resulting_balance" header="Saldo Resultante">
                                <template #body="{ data }">
                                    <span :class="getBalanceClass(data.resulting_balance)" class="font-mono font-bold text-sm">
                                        {{ formatCurrency(data.resulting_balance) }}
                                    </span>
                                </template>
                            </Column>
                            <template #empty>
                                <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                                    <i class="pi pi-sort-alt !text-3xl text-gray-400 mb-3"></i>
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin movimientos</p>
                                    <p class="text-xs text-gray-400 mt-1">El saldo del cliente no ha tenido actividad.</p>
                                </div>
                            </template>
                        </DataTable>
                    </div>

                    <!-- Tabla de Facturas (CFDI) — solo visible con el módulo de facturación -->
                    <div v-if="hasBilling" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex justify-between items-start">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Facturas (CFDI)</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Comprobantes fiscales del cliente</p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center flex-shrink-0 border border-indigo-100 dark:border-indigo-900/30">
                                <i class="pi pi-receipt !text-sm text-indigo-500"></i>
                            </div>
                        </div>

                        <DataTable
                            v-model:expandedRows="expandedInvoiceRows"
                            :value="customerInvoices"
                            dataKey="id"
                            responsiveLayout="scroll"
                            :paginator="customerInvoices?.length > 5"
                            :rows="5"
                            sortField="created_at"
                            :sortOrder="-1"
                            :pt="dataTablePt"
                        >
                            <Column expander style="width: 3rem" />
                            <Column field="folio" header="Folio">
                                <template #body="{ data }">
                                    <div class="flex flex-col gap-1 items-start">
                                        <Link
                                            :href="route('billing.invoices.show', data.id)"
                                            class="text-primary-600 dark:text-primary-400 font-medium hover:underline"
                                        >
                                            {{ data.series ? data.series + ' ' : '' }}{{ data.folio }}
                                        </Link>
                                        <span class="text-[9px] uppercase tracking-widest font-bold bg-gray-100 dark:bg-[#3a3a3a] text-gray-500 dark:text-gray-400 px-1.5 py-0.5 rounded">
                                            {{ tipoComprobanteLabel(data.tipo_comprobante) }}
                                        </span>
                                    </div>
                                </template>
                            </Column>
                            <Column field="issued_at" header="Fecha emisión" sortable>
                                <template #body="{ data }">
                                    <span class="text-xs">{{ formatDateOnly(data.issued_at || data.created_at) }}</span>
                                </template>
                            </Column>
                            <Column field="payment_method" header="Método de pago">
                                <template #body="{ data }">
                                    <span
                                        v-if="data.payment_method"
                                        class="text-[10px] uppercase tracking-widest font-bold"
                                        :class="data.payment_method === 'PPD' ? 'text-amber-500' : 'text-sky-500'"
                                    >
                                        {{ data.payment_method }}
                                    </span>
                                    <span v-else class="text-gray-300 dark:text-gray-600 text-xs italic">—</span>
                                </template>
                            </Column>
                            <Column field="total" header="Total">
                                <template #body="{ data }">
                                    <span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(data.total) }}</span>
                                </template>
                            </Column>
                            <Column field="status" header="Estado">
                                <template #body="{ data }">
                                    <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" :pt="tagPt" />
                                </template>
                            </Column>

                            <template #expansion="slotProps">
                                <div class="p-4 mx-4 my-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#2a2a2a]">
                                    <div class="flex flex-wrap justify-between items-center gap-2 mb-4 border-b border-gray-200 dark:border-[#3a3a3a] pb-3">
                                        <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                            Detalles del comprobante
                                        </h4>
                                        <div class="flex items-center gap-4">
                                            <span class="text-[10px] uppercase tracking-widest text-gray-500 flex items-center gap-1">
                                                <i class="pi pi-building !text-[9px]"></i> {{ slotProps.data.fiscal_profile?.razon_social || '—' }}
                                            </span>
                                            <Link
                                                :href="route('billing.invoices.show', slotProps.data.id)"
                                                class="text-[10px] uppercase tracking-widest font-bold text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1"
                                            >
                                                <i class="pi pi-external-link !text-[9px]"></i> Ver factura
                                            </Link>
                                        </div>
                                    </div>

                                    <!-- Datos del comprobante -->
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-5">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">UUID (folio fiscal)</span>
                                            <span v-if="slotProps.data.uuid" class="font-mono text-xs text-gray-700 dark:text-gray-300 break-all m-0">
                                                {{ slotProps.data.uuid }}
                                            </span>
                                            <span v-else class="text-xs italic text-gray-400 m-0">Pendiente de timbrado</span>
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">RFC receptor</span>
                                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300 m-0">{{ slotProps.data.receiver_rfc || '—' }}</span>
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Régimen fiscal</span>
                                            <span class="text-xs text-gray-700 dark:text-gray-300 m-0">{{ slotProps.data.receiver_tax_regime || '—' }}</span>
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Uso de CFDI</span>
                                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300 m-0">{{ slotProps.data.cfdi_use || '—' }}</span>
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Forma de pago</span>
                                            <span class="text-xs text-gray-700 dark:text-gray-300 m-0">
                                                {{ slotProps.data.payment_form || '—' }}
                                                <span v-if="slotProps.data.payment_method" class="text-gray-400"> · {{ paymentMethodLabel(slotProps.data.payment_method) }}</span>
                                            </span>
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Fecha de timbrado</span>
                                            <span class="text-xs text-gray-700 dark:text-gray-300 m-0">
                                                {{ slotProps.data.fecha_timbrado ? formatDateOnly(slotProps.data.fecha_timbrado) : '—' }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Desglose de montos -->
                                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                                        <div class="bg-white dark:bg-[#232323] rounded-xl border border-gray-100 dark:border-[#3a3a3a] p-3">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Subtotal</span>
                                            <span class="font-mono text-sm text-gray-900 dark:text-white m-0">{{ formatCurrency(slotProps.data.subtotal) }}</span>
                                        </div>
                                        <div class="bg-white dark:bg-[#232323] rounded-xl border border-gray-100 dark:border-[#3a3a3a] p-3">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Descuento</span>
                                            <span class="font-mono text-sm text-red-500 m-0">{{ formatCurrency(slotProps.data.discount_total) }}</span>
                                        </div>
                                        <div class="bg-white dark:bg-[#232323] rounded-xl border border-gray-100 dark:border-[#3a3a3a] p-3">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">IVA</span>
                                            <span class="font-mono text-sm text-gray-900 dark:text-white m-0">{{ formatCurrency(slotProps.data.taxes_total) }}</span>
                                        </div>
                                        <div class="bg-white dark:bg-[#232323] rounded-xl border border-indigo-100 dark:border-indigo-900/40 p-3">
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Total</span>
                                            <span class="font-mono text-sm font-bold text-indigo-600 dark:text-indigo-400 m-0">{{ formatCurrency(slotProps.data.total) }}</span>
                                        </div>
                                    </div>

                                    <!-- Conceptos -->
                                    <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Conceptos</h4>
                                    <DataTable :value="slotProps.data.items" :pt="subDataTablePt">
                                        <Column field="sat_product_code" header="Clave SAT" style="width: 8rem">
                                            <template #body="{ data: item }"><span class="font-mono text-xs">{{ item.sat_product_code || '—' }}</span></template>
                                        </Column>
                                        <Column field="quantity" header="Cant." style="width: 5rem">
                                            <template #body="{ data: item }"><span class="font-mono text-xs">{{ formatQuantity(item.quantity) }}</span></template>
                                        </Column>
                                        <Column field="description" header="Descripción">
                                            <template #body="{ data: item }"><span class="font-medium text-gray-900 dark:text-gray-100">{{ item.description }}</span></template>
                                        </Column>
                                        <Column field="unit_price" header="P. Unit" class="text-right">
                                            <template #body="{ data: item }"><span class="font-mono text-xs">{{ formatCurrency(item.unit_price) }}</span></template>
                                        </Column>
                                        <Column field="total" header="Importe" class="text-right">
                                            <template #body="{ data: item }"><span class="font-mono text-xs font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.total) }}</span></template>
                                        </Column>
                                        <template #empty>
                                            <div class="text-center text-gray-500 py-3 text-xs italic">No hay conceptos registrados para este comprobante.</div>
                                        </template>
                                    </DataTable>
                                </div>
                            </template>
                            <template #empty>
                                <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                                    <i class="pi pi-receipt !text-3xl text-gray-400 mb-3"></i>
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin facturas</p>
                                    <p class="text-xs text-gray-400 mt-1">Este cliente no tiene comprobantes fiscales.</p>
                                </div>
                            </template>
                        </DataTable>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modales -->
        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters" :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />
        <PaymentModal v-if="isPaymentModalVisible" v-model:visible="isPaymentModalVisible" :total-amount="0" :client="customer" :loading="isPaymentProcessing" payment-mode="balance" @submit="handleBalancePaymentSubmit" />
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource" :available-templates="availableTemplates" />
        
        <!-- Modal de Ajuste Manual -->
        <Dialog v-model:visible="isAdjustModalVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
            
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                        <i class="pi pi-sliders-h !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Ajuste manual</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                            Modificar saldo del cliente
                        </p>
                    </div>
                </div>
            </template>

            <form @submit.prevent="submitAdjustment" class="flex flex-col gap-5 pt-2">
                <!-- Tipo de Acción -->
                <div class="flex flex-col">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">¿Qué deseas hacer?</label>
                    <SelectButton v-model="adjustForm.adjustment_type" :options="adjustmentTypeOptions" optionLabel="label" optionValue="value" class="w-full" :pt="selectButtonPt" />
                </div>

                <!-- Dirección / Signo -->
                <div class="flex flex-col">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Tipo de movimiento</label>
                    <SelectButton v-model="adjustForm.direction" :options="adjustmentDirectionOptions" optionLabel="label" optionValue="value" class="w-full" :allowEmpty="false" :pt="selectButtonPt">
                        <template #option="slotProps">
                            <div class="flex items-center justify-center gap-1.5 w-full h-full text-[10px] uppercase tracking-widest font-bold transition-colors" :class="{ 'text-green-600 dark:text-green-400': slotProps.option.value === 'credit', 'text-red-600 dark:text-red-400': slotProps.option.value === 'debit' }">
                                <i :class="slotProps.option.icon" class="!text-[9px]"></i>
                                <span>{{ slotProps.option.label }}</span>
                            </div>
                        </template>
                    </SelectButton>
                </div>

                <!-- Monto -->
                <div class="flex flex-col">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">{{ adjustForm.adjustment_type === 'add' ? 'Monto del movimiento *' : 'Nuevo saldo total *' }}</label>
                    <InputNumber v-model="adjustForm.amount" mode="currency" currency="MXN" locale="es-MX" :min="0" :minFractionDigits="2" :maxFractionDigits="2" placeholder="$0.00" :pt="{ input: inputPt }" />
                    <span v-if="adjustForm.errors.amount" class="text-xs text-red-500 font-medium mt-1.5">{{ adjustForm.errors.amount }}</span>
                    <span v-else class="text-[10px] text-gray-500 mt-1 italic block">El monto siempre se ingresa en positivo.</span>
                </div>

                <!-- Notas -->
                <div class="flex flex-col">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Razón del ajuste (Obligatorio) *</label>
                    <Textarea v-model="adjustForm.notes" rows="3" placeholder="Ej: Error en cobro anterior, bonificación, etc." :pt="textareaPt" />
                    <span v-if="adjustForm.errors.notes" class="text-xs text-red-500 font-medium mt-1.5">{{ adjustForm.errors.notes }}</span>
                </div>
            </form>

            <template #footer>
                <div class="flex justify-end items-center gap-3 mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a] w-full">
                    <Button label="Cancelar" text @click="isAdjustModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button label="Aplicar ajuste" @click="submitAdjustment" :loading="adjustForm.processing" severity="warning" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" />
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>