<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from 'primevue/usetoast';
import { usePermissions } from '@/Composables';

// Importaciones de Modales / Componentes
import PrintModal from '@/Components/PrintModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import ProductExchangeModal from './Partials/ProductExchangeModal.vue';
import LayawayExchangeModal from './Partials/LayawayExchangeModal.vue';
import TransactionCancellationModal from './Partials/TransactionCancellationModal.vue';
import EditPaymentModal from './Partials/EditPaymentModal.vue';
import ExtendLayawayModal from './Partials/ExtendLayawayModal.vue';
import RescheduleOrderModal from './Partials/RescheduleOrderModal.vue';
import EditTransactionDateModal from './Partials/EditTransactionDateModal.vue';

// Importaciones de Componentes de Paneles
import TransactionItemsPanel from './Partials/TransactionItemsPanel.vue';
import TransactionFinancialPanel from './Partials/TransactionFinancialPanel.vue';
import TransactionInfoPanel from './Partials/TransactionInfoPanel.vue';
import TransactionPaymentsPanel from './Partials/TransactionPaymentsPanel.vue';

const props = defineProps({
    transaction: Object,
    availableTemplates: Array,
    availableCashRegisters: Array,
    userBankAccounts: Array,
    joinableSessions: Array,
});

const { hasPermission } = usePermissions();
const page = usePage();
const toast = useToast();
const confirm = useConfirm();

// --- Lógica de Sesión Activa ---
const activeSession = computed(() => page.props.activeSession);
const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingPayment = ref(false);

watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingPayment.value) {
        sessionModalAwaitingPayment.value = false;
        openPaymentModal();
    }
});

// --- Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);
const openPrintModal = () => {
    printDataSource.value = { type: 'transaction', id: props.transaction.id };
    isPrintModalVisible.value = true;
};

// --- Modales de Funcionalidades Específicas (Control de Visibilidad) ---
const isCancellationModalVisible = ref(false);
const isPaymentModalVisible = ref(false);
const isEditPaymentModalVisible = ref(false);
const isProductExchangeModalVisible = ref(false);
const isLayawayExchangeModalVisible = ref(false);
const isExtendLayawayModalVisible = ref(false);
const isRescheduleOrderModalVisible = ref(false);
const isEditDateModalVisible = ref(false);

const isPaymentProcessing = ref(false);
const paymentToEdit = ref(null);

const safeBankAccounts = computed(() => Array.isArray(props.userBankAccounts) ? props.userBankAccounts : []);

// --- Acciones de Apertura de Modales ---
const openEditPaymentModal = (payment) => {
    paymentToEdit.value = payment;
    isEditPaymentModalVisible.value = true;
};

const confirmDeletePayment = (payment) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar permanentemente este pago por ${formatCurrency(payment.amount)}?`,
        header: 'Eliminar Pago',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('transactions.destroyPayment', { transaction: localTransaction.value.id, payment: payment.id }), {
                preserveScroll: true,
                onSuccess: () => {
                }
            });
        }
    });
};

const openExchangeModal = () => {
    if (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') {
        isLayawayExchangeModalVisible.value = true;
    } else {
        isProductExchangeModalVisible.value = true;
    }
};

const openExtendLayawayModal = () => isExtendLayawayModalVisible.value = true;
const openRescheduleOrderModal = () => isRescheduleOrderModalVisible.value = true;
const openEditDateModal = () => isEditDateModalVisible.value = true;

// --- Menú de Teléfono ---
const phoneMenu = ref();
const targetPhone = ref('');

const phoneMenuItems = computed(() => [
    {
        label: 'Llamar',
        icon: 'pi pi-phone',
        command: () => window.location.href = `tel:${targetPhone.value}`
    },
    {
        label: 'Mandar WhatsApp',
        icon: 'pi pi-whatsapp',
        command: () => {
            const cleanNumber = targetPhone.value.replace(/\D/g, ''); 
            window.open(`https://wa.me/${cleanNumber}`, '_blank');
        }
    }
]);

const togglePhoneMenu = (event, phone) => {
    if (!phone) return;
    targetPhone.value = phone;
    phoneMenu.value.toggle(event);
};

// --- Menú de Acciones ---
const actionsMenu = ref();
const toggleActionsMenu = (event) => actionsMenu.value.toggle(event);

// --- Datos Computados de la Transacción ---
const localTransaction = ref(props.transaction);
watch(() => props.transaction, (newVal) => localTransaction.value = newVal, { deep: true });

const totalAmount = computed(() => parseFloat(localTransaction.value.total));
const totalPaid = computed(() => {
    if (!Array.isArray(localTransaction.value.payments)) return 0;
    return localTransaction.value.payments.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
});
const pendingAmount = computed(() => {
    const diff = totalAmount.value - totalPaid.value;
    return diff < 0.01 ? 0 : diff;
});

// --- ACCIONES PERMITIDAS ---
const canCancelOrRefund = computed(() => {
    if (!localTransaction.value?.status) return false;
    return !['cancelado', 'reembolsado'].includes(localTransaction.value.status);
});

const canAddPayment = computed(() => {
    if (!localTransaction.value?.status) return false;
    const isCancelledOrRefunded = ['cancelado', 'reembolsado'].includes(localTransaction.value.status);
    return !isCancelledOrRefunded && pendingAmount.value > 0.01;
});

const canExchange = computed(() => {
    if (!localTransaction.value?.status) return false;
    if (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') return true;
    return !['cancelado', 'reembolsado'].includes(localTransaction.value.status);
});

const canExtendExpiration = computed(() => ['apartado', 'on_layaway', 'pendiente'].includes(localTransaction.value.status));

// --- Facturación (CFDI) ---
// Una venta es facturable si está completada, entregada por pagar o a crédito
// (pendiente), no es un abono a saldo y aún no tiene factura. Las ventas a
// crédito se facturan como PPD aunque no estén liquidadas.
const canInvoice = computed(() => {
    const t = localTransaction.value;
    if (!t) return false;
    if (!['completado', 'entregado_por_pagar', 'pendiente'].includes(t.status)) return false;
    if (t.channel === 'abono_a_saldo') return false;
    if (t.invoiced) return false;
    if (t.status === 'pendiente') return true;
    return (parseFloat(t.remaining_due ?? t.total ?? 0) <= 0.01);
});

// Navega a la creación de factura con la venta pre-seleccionada para que
// el formulario se llene automáticamente con los datos de la venta.
const goToInvoice = () => {
    router.get(route('billing.invoices.create', { transaction: localTransaction.value.id }));
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

// --- Lógica de Pagos y Cancelación ---
const openPaymentModal = () => {
    if (!activeSession.value) {
        if (props.joinableSessions && props.joinableSessions?.length > 0) {
            sessionModalAwaitingPayment.value = true;
            isJoinSessionModalVisible.value = true;
        } else if (props.availableCashRegisters && props.availableCashRegisters?.length > 0) {
            sessionModalAwaitingPayment.value = true;
            isStartSessionModalVisible.value = true;
        } else {
            toast.add({ severity: 'error', summary: 'Sin Caja', detail: 'No hay cajas disponibles para registrar el pago.', life: 5000 });
        }
        return;
    }
    isPaymentModalVisible.value = true;
};

const handlePaymentSubmit = (paymentData) => {
    if (!activeSession.value) return;
    isPaymentProcessing.value = true;
    const payload = { ...paymentData, cash_register_session_id: activeSession.value.id };

    router.post(route('transactions.addPayment', props.transaction.id), payload, {
        onSuccess: () => {
            isPaymentModalVisible.value = false;
            openPrintModal();
        },
        onFinish: () => isPaymentProcessing.value = false,
        preserveScroll: true,
    });
};

const initiateCancellation = () => {
    if (totalPaid.value <= 0.01) {
        let message = `¿Seguro que quieres cancelar la venta #${localTransaction.value.folio}? Se liberará el inventario reservado.`;
        if (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') {
            message = `¿Seguro que quieres cancelar este APARTADO? No hay pagos registrados.`;
        } else if (localTransaction.value.status === 'por_entregar') {
            message = `¿Seguro que quieres cancelar este PEDIDO? Se liberará el inventario reservado.`;
        } else if (localTransaction.value.status === 'pendiente') {
            message = `¿Seguro que quieres cancelar esta venta a CRÉDITO (#${localTransaction.value.folio})? Se anulará la deuda del cliente y se liberará el stock.`;
        }
        confirm.require({
            message: message,
            header: 'Confirmar cancelación',
            icon: 'pi pi-exclamation-triangle',
            acceptClass: 'p-button-danger',
            accept: () => router.post(route('transactions.cancel', localTransaction.value.id), {}, { preserveScroll: true })
        });
        return;
    }
    isCancellationModalVisible.value = true;
};

const actionItems = computed(() => [
    { label: 'Abonar / Liquidar', icon: 'pi pi-dollar', command: openPaymentModal, disabled: !canAddPayment.value, visible: hasPermission('transactions.add_payment') },
    { label: (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') ? 'Modificar Apartado' : 'Intercambiar producto', icon: 'pi pi-sync', command: openExchangeModal, disabled: !canExchange.value, visible: hasPermission('transactions.exchange') },
    { label: 'Extender Vencimiento', icon: 'pi pi-calendar-plus', command: openExtendLayawayModal, visible: canExtendExpiration.value },
    { label: 'Facturar venta', icon: 'pi pi-file-edit', command: goToInvoice, visible: hasPermission('invoices.create') && canInvoice.value },
    {
        label: invoiceLinkInfo(localTransaction.value.invoice)?.label ?? 'Ver factura',
        icon: invoiceLinkInfo(localTransaction.value.invoice)?.icon ?? 'pi pi-file-pdf',
        command: () => goToInvoiceShow(localTransaction.value.invoice),
        visible: !!localTransaction.value.invoice
    },
    { separator: true },
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Cancelar / Devolver', icon: 'pi pi-times-circle', class: 'text-red-500', command: initiateCancellation, disabled: !canCancelOrRefund.value, visible: hasPermission('transactions.cancel') || hasPermission('transactions.refund') },
]);

const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);

// --- TESLA UI PASS-THROUGH (PT) ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};
</script>

<template>
    <Head :title="`Venta #${transaction.folio}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('transactions.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al historial
                </Link>
            </div>

            <!-- Header de la página al estilo Tesla UI -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-4">
                        {{ transaction.status === 'por_entregar' ? `Pedido #${transaction.folio}` : `Venta #${transaction.folio}` }}
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full" :class="['cancelado', 'reembolsado'].includes(transaction.status) ? 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)] animate-pulse' : 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse'"></span>
                            <span class="capitalize">{{ (transaction.status || '').replace('_', ' ') }}</span>
                        </p>
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Fecha:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                <i class="pi pi-calendar !text-[10px] text-gray-400"></i>
                                {{ formatDate(transaction.created_at) }}
                            </span>
                            <Button 
                                icon="pi pi-pencil" 
                                text
                                rounded 
                                class="!w-6 !h-6 !p-0 text-gray-400 hover:text-primary-500"
                                v-tooltip.bottom="'Editar fecha'"
                                @click="openEditDateModal"
                            />
                        </div>
                    </div>
                </div>
                
                <div class="w-full sm:w-auto shrink-0 flex gap-2">
                    <Button type="button" label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleActionsMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                    <Menu ref="actionsMenu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <!-- Enlace a la factura relacionada -->
            <div v-if="localTransaction.value.invoice" class="bg-white dark:bg-[#232323] p-4 lg:p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
                        <i class="pi pi-file-pdf !text-sm text-emerald-500"></i>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white m-0">
                            {{ localTransaction.value.invoice.series ? `${localTransaction.value.invoice.series}-${localTransaction.value.invoice.folio}` : localTransaction.value.invoice.folio }}
                        </span>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            {{ invoiceLinkInfo(localTransaction.value.invoice)?.label }}
                        </span>
                    </div>
                </div>
                <Button type="button" :label="invoiceLinkInfo(localTransaction.value.invoice)?.label" :icon="invoiceLinkInfo(localTransaction.value.invoice)?.icon" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold shrink-0" @click="goToInvoiceShow(localTransaction.value.invoice)" />
            </div>

            <!-- Contenedor Principal (Grid Layout) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- Columna Principal -->
                <div class="lg:col-span-2 space-y-6 lg:space-y-8 flex flex-col">
                    <TransactionItemsPanel :transaction="transaction" />
                </div>
                
                <!-- Columna Derecha -->
                <div class="lg:col-span-1 space-y-6 lg:space-y-8 flex flex-col">
                    <TransactionFinancialPanel 
                        :transaction="transaction" 
                        :total-amount="totalAmount"
                        :total-paid="totalPaid"
                        :pending-amount="pendingAmount"
                        :can-add-payment="canAddPayment"
                        @open-payment-modal="openPaymentModal"
                    />

                    <TransactionInfoPanel 
                        :transaction="transaction"
                        :local-transaction="localTransaction"
                        :can-extend-expiration="canExtendExpiration"
                        :pending-amount="pendingAmount"
                        @open-reschedule-order-modal="openRescheduleOrderModal"
                        @toggle-phone-menu="togglePhoneMenu"
                        @open-extend-layaway-modal="openExtendLayawayModal"
                    />

                    <TransactionPaymentsPanel 
                        :local-transaction="localTransaction"
                        @open-edit-payment-modal="openEditPaymentModal"
                        @confirm-delete-payment="confirmDeletePayment"
                    />
                </div>
            </div>
        </div>

        <!-- Componentes Globales y Modales Externos -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource" :available-templates="availableTemplates" />
        <Menu ref="phoneMenu" :model="phoneMenuItems" :popup="true" :pt="menuPt" />
        
        <PaymentModal v-if="isPaymentModalVisible" v-model:visible="isPaymentModalVisible" :total-amount="pendingAmount" :client="transaction.customer" :loading="isPaymentProcessing" payment-mode="flexible" @submit="handlePaymentSubmit" />
        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters" :user-bank-accounts="safeBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />

        <!-- MODALES DE LA TRANSACCIÓN (Parciales extraídos) -->
        <ProductExchangeModal v-if="transaction" v-model:visible="isProductExchangeModalVisible" :transaction="transaction" :user-bank-accounts="safeBankAccounts" @success="router.reload()" />
        <LayawayExchangeModal v-if="transaction" v-model:visible="isLayawayExchangeModalVisible" :transaction="transaction" :user-bank-accounts="safeBankAccounts" @success="router.reload()" />
        <TransactionCancellationModal v-model:visible="isCancellationModalVisible" :transaction="localTransaction" :active-session="activeSession" :bank-accounts="safeBankAccounts" />
        
        <EditPaymentModal v-model:visible="isEditPaymentModalVisible" :transaction-id="transaction.id" :payment="paymentToEdit" :bank-accounts="safeBankAccounts" />
        <ExtendLayawayModal v-model:visible="isExtendLayawayModalVisible" :transaction-id="transaction.id" :current-date="transaction.layaway_expiration_date" />
        <RescheduleOrderModal v-model:visible="isRescheduleOrderModalVisible" :transaction-id="transaction.id" :current-date="transaction.delivery_date" />
        <EditTransactionDateModal v-model:visible="isEditDateModalVisible" :transaction-id="transaction.id" :current-date="transaction.created_at" />

    </AppLayout>
</template>