<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
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
    { separator: true },
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Cancelar / Devolver', icon: 'pi pi-times-circle', class: 'text-red-500', command: initiateCancellation, disabled: !canCancelOrRefund.value, visible: hasPermission('transactions.cancel') || hasPermission('transactions.refund') },
]);

const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([{ label: 'Historial de ventas', url: route('transactions.index') }, { label: `Venta #${props.transaction.folio}` }]);
</script>

<template>
    <AppLayout :title="`Venta #${transaction.folio}`">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">
                    {{ transaction.status === 'por_entregar' ? `Pedido #${transaction.folio}` : `Venta #${transaction.folio}` }}
                </h1>
                <div class="flex items-center gap-2 mt-1">
                    <p class="text-gray-500 dark:text-gray-400 m-0">
                        Realizada el {{ formatDate(transaction.created_at) }}
                    </p>
                    <Button 
                        icon="pi pi-pencil" 
                        text
                        rounded 
                        size="small" 
                        severity="secondary"
                        v-tooltip.bottom="'Editar fecha'"
                        @click="openEditDateModal"
                    />
                </div>
            </div>
            <div class="flex items-center gap-2 mt-4 sm:mt-0">
                <Button type="button" label="Acciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleActionsMenu" severity="secondary" outlined />
                <Menu ref="actionsMenu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal -->
            <div class="lg:col-span-2 space-y-6">
                <TransactionItemsPanel :transaction="transaction" />
            </div>
            
            <!-- Columna Derecha -->
            <div class="lg:col-span-1 space-y-6">
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

        <!-- Componentes Globales y Modales Externos -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource" :available-templates="availableTemplates" />
        <Menu ref="phoneMenu" :model="phoneMenuItems" :popup="true" />
        
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