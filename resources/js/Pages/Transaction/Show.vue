<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3'; // <-- Importado usePage
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from 'primevue/usetoast'; // Importamos toast
import PrintModal from '@/Components/PrintModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import { usePermissions } from '@/Composables';
import ProductExchangeModal from './Partials/ProductExchangeModal.vue';

const props = defineProps({
    transaction: Object,
    availableTemplates: Array,
    // Props para gestión de sesión/pagos
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

// --- Modales de Sesión ---
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

// --- Modal de Reembolso ---
const isRefundModalVisible = ref(false);
const refundMethod = ref('cash');
const refundProcessing = ref(false);

// --- Modal de Pagos (Abonar) ---
const isPaymentModalVisible = ref(false);
const isPaymentProcessing = ref(false);

// --- NUEVO: Modal de Información de Intercambio ---
const isProductExchangeModalVisible = ref(false);

const openExchangeModal = () => {
    isProductExchangeModalVisible.value = true;
};

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

// --- Acciones Permitidas ---
const canCancel = computed(() => {
    if (!localTransaction.value?.status) return false;
    return !['cancelado', 'reembolsado'].includes(localTransaction.value.status) && totalPaid.value === 0;
});

const canRefund = computed(() => {
    if (!localTransaction.value?.status) return false;
    const isCompleted = localTransaction.value.status === 'completado';
    const isPendingWithPayments = localTransaction.value.status === 'pendiente' && totalPaid.value > 0;
    const isOnLayaway = localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway';
    return isCompleted || isPendingWithPayments || isOnLayaway;
});

// Permitir abono si hay deuda y no está cancelada/reembolsada
const canAddPayment = computed(() => {
    if (!localTransaction.value?.status) return false;
    const isCancelledOrRefunded = ['cancelado', 'reembolsado'].includes(localTransaction.value.status);
    return !isCancelledOrRefunded && pendingAmount.value > 0.01;
});

// NUEVO: Regla para permitir intercambio (mientras no esté cancelada o reembolsada)
const canExchange = computed(() => {
    if (!localTransaction.value?.status) return false;
    return !['cancelado', 'reembolsado'].includes(localTransaction.value.status);
});

// --- Lógica de Acciones ---
const openPaymentModal = () => {
    // Verificar sesión activa
    if (!activeSession.value) {
        if (props.joinableSessions && props.joinableSessions.length > 0) {
            sessionModalAwaitingPayment.value = true;
            isJoinSessionModalVisible.value = true;
        } else if (props.availableCashRegisters && props.availableCashRegisters.length > 0) {
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

    const payload = {
        ...paymentData,
        cash_register_session_id: activeSession.value.id
    };

    router.post(route('transactions.addPayment', props.transaction.id), payload, {
        onSuccess: () => isPaymentModalVisible.value = false,
        onFinish: () => isPaymentProcessing.value = false,
        preserveScroll: true,
    });
};

const cancelSale = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres cancelar la venta #${localTransaction.value.folio}?`,
        header: 'Confirmar Cancelación',
        accept: () => {
            router.post(route('transactions.cancel', localTransaction.value.id), {}, {
                preserveScroll: true,
                onSuccess: () => { if (localTransaction.value) localTransaction.value.status = 'cancelado'; }
            });
        }
    });
};

const openRefundModal = () => {
    refundMethod.value = props.transaction.customer_id ? 'cash' : 'cash';
    isRefundModalVisible.value = true;
};

const confirmRefund = () => {
    refundProcessing.value = true;
    router.post(route('transactions.refund', props.transaction.id), { refund_method: refundMethod.value }, {
        preserveScroll: true,
        onSuccess: () => {
            isRefundModalVisible.value = false;
            if (localTransaction.value) localTransaction.value.status = 'reembolsado';
        },
        onFinish: () => refundProcessing.value = false
    });
};

// --- Menú de Acciones ---
const actionItems = computed(() => [
    { label: 'Abonar / Liquidar', icon: 'pi pi-dollar', command: openPaymentModal, disabled: !canAddPayment.value, visible: hasPermission('transactions.add_payment') },

    // --- NUEVA OPCIÓN: INTERCAMBIO ---
    { label: 'Intercambiar producto', icon: 'pi pi-sync', command: openExchangeModal, disabled: !canExchange.value, visible: hasPermission('transactions.exchange') },
    // ---------------------------------

    { separator: true },
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Generar devolución', icon: 'pi pi-replay', command: openRefundModal, disabled: !canRefund.value, visible: hasPermission('transactions.refund') },
    { label: 'Cancelar venta', icon: 'pi pi-times-circle', class: 'text-red-500', command: cancelSale, disabled: !canCancel.value, visible: hasPermission('transactions.cancel') },
]);

// Helpers visuales
const getStatusSeverity = (status) => ({ completado: 'success', pendiente: 'warn', cancelado: 'danger', reembolsado: 'info', on_layaway: 'warn', apartado: 'warn' }[status] || 'secondary');
const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatDateOnly = (date) => date ? new Date(date + 'T00:00:00').toLocaleDateString('es-MX', { dateStyle: 'long' }) : '';
const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);

// --- ACTUALIZADO: Iconos de métodos de pago (incluyendo intercambio) ---
const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-[#37672B]' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-[#063C53]' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-[#D2D880]' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' },
    intercambio: { icon: 'pi pi-sync', color: 'text-orange-500' } // <--- NUEVO
};

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([{ label: 'Historial de ventas', url: route('transactions.index') }, { label: `Venta #${props.transaction.folio}` }]);
</script>

<template>
    <AppLayout :title="`Venta #${transaction.folio}`">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Venta #{{ transaction.folio }}</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Realizada el {{ formatDate(transaction.created_at) }}
                </p>
            </div>
            <div class="flex items-center gap-2 mt-4 sm:mt-0">
                <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal -->
            <div class="lg:col-span-2 space-y-6">
                <Card>
                    <template #title>Detalles de los conceptos</template>
                    <template #content>
                        <DataTable :value="transaction.items" class="p-datatable-sm">
                            <Column field="description" header="Descripción"></Column>
                            <Column field="quantity" header="Cantidad" class="text-center"></Column>
                            <Column header="Precio unitario">
                                <template #body="{ data }">
                                    <div>
                                        <del v-if="parseFloat(data.discount_amount || 0) !== 0"
                                            class="text-gray-500 text-xs">
                                            {{ formatCurrency(parseFloat(data.unit_price || 0) +
                                                parseFloat(data.discount_amount || 0)) }}
                                        </del>
                                        <p class="font-semibold m-0">{{ formatCurrency(data.unit_price) }}</p>
                                        <p v-if="parseFloat(data.discount_amount) > 0"
                                            class="text-xs text-green-600 m-0">Ahorro: {{
                                                formatCurrency(data.discount_amount) }}</p>
                                    </div>
                                </template>
                            </Column>
                            <Column field="line_total" header="Total" class="text-right">
                                <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
                            </Column>
                        </DataTable>
                    </template>
                </Card>
            </div>
            <!-- Columna Derecha -->
            <div class="lg:col-span-1 space-y-6">
                <Card>
                    <template #title>Resumen financiero</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Subtotal:</span><span>{{
                                formatCurrency(transaction.subtotal) }}</span></li>
                            <li v-if="parseFloat(transaction.total_discount) > 0" class="flex justify-between">
                                <span>Descuento:</span><span class="text-green-500">- {{
                                    formatCurrency(transaction.total_discount) }}</span>
                            </li>
                            <li class="flex justify-between font-bold text-base border-t pt-2 mt-2">
                                <span>Total:</span><span>{{ formatCurrency(totalAmount) }}</span>
                            </li>
                            <li class="flex justify-between"><span>Pagado:</span><span class="font-semibold">{{
                                formatCurrency(totalPaid) }}</span></li>
                            <li v-if="pendingAmount > 0"
                                class="flex justify-between font-bold text-red-600 text-lg bg-red-50 dark:bg-red-900/20 p-2 rounded">
                                <span>Pendiente:</span><span>{{ formatCurrency(pendingAmount) }}</span>
                            </li>
                        </ul>
                        <div v-if="canAddPayment && hasPermission('transactions.add_payment')" class="mt-4">
                            <Button label="Abonar a esta cuenta" icon="pi pi-dollar" class="w-full p-button-success"
                                @click="openPaymentModal" />
                        </div>
                    </template>
                </Card>
                <Card>
                    <template #title>Información de la venta</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Estatus:</span>
                                <Tag :value="localTransaction.status"
                                    :severity="getStatusSeverity(localTransaction.status)" class="capitalize" />
                            </li>
                            <li v-if="transaction.layaway_expiration_date"
                                class="flex justify-between items-center bg-purple-50 dark:bg-purple-900/20 p-2 rounded -mx-2">
                                <span class="text-purple-800 dark:text-purple-300 font-medium">Vencimiento:</span>
                                <span class="font-bold text-purple-700 dark:text-purple-200">{{
                                    formatDateOnly(transaction.layaway_expiration_date) }}</span>
                            </li>
                            <li class="flex justify-between items-center">
                                <span>Cliente:</span>
                                <span class="font-medium">
                                    <Link v-if="transaction.customer"
                                        :href="route('customers.show', transaction.customer.id)"
                                        class="text-blue-600 hover:underline flex items-center gap-2">{{
                                            transaction.customer.name }} <i class="pi pi-external-link text-xs"></i></Link>
                                    <span v-else>Público en general</span>
                                </span>
                            </li>
                            <li class="flex justify-between"><span>Cajero:</span><span class="font-medium">{{
                                transaction.user?.name || 'N/A' }}</span></li>

                            <!-- NUEVO: Mostrar Notas (Crucial para ver referencia de intercambio) -->
                            <li v-if="transaction.notes" class="flex flex-col border-t pt-2 mt-2">
                                <span class="text-gray-500 dark:text-gray-400 mb-1 text-xs uppercase font-bold">Notas /
                                    Referencia:</span>
                                <p
                                    class="text-sm bg-gray-50 dark:bg-gray-700/50 p-2 rounded italic text-gray-700 dark:text-gray-300">
                                    {{ transaction.notes }}
                                </p>
                            </li>
                            <!-- ----------------------------------------------------------------- -->
                        </ul>
                    </template>
                </Card>
                <Card>
                    <template #title>Pagos realizados</template>
                    <template #content>
                        <div v-if="!localTransaction.payments?.length">
                            <p class="text-center text-gray-500 text-sm py-4">No se han registrado pagos.</p>
                        </div>
                        <ul v-else class="space-y-3">
                            <li v-for="payment in localTransaction.payments" :key="payment.id" class="text-sm">
                                <div class="flex justify-between items-center">
                                    <span class="flex items-center gap-2">
                                        <!-- Manejo seguro de iconos por si llega un método nuevo -->
                                        <i class="pi"
                                            :class="(paymentMethodIcons[payment.payment_method]?.icon || 'pi-circle') + ' ' + (paymentMethodIcons[payment.payment_method]?.color || 'text-gray-500')"></i>
                                        <span class="capitalize font-medium">
                                            {{ payment.payment_method === 'intercambio' ? 'Intercambio de Producto' :
                                                payment.payment_method }}
                                        </span>
                                    </span>
                                    <span class="font-mono font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                </div>
                                <p class="text-xs text-gray-500 ml-6">{{ formatDate(payment.payment_date ||
                                    payment.created_at) }}</p>
                            </li>
                        </ul>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modales -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />

        <!-- Modal de Reembolso -->
        <Dialog v-model:visible="isRefundModalVisible" modal header="Confirmar devolución" :style="{ width: '30rem' }">
            <div class="p-fluid">
                <p class="mb-4">Vas a generar una devolución para la venta <strong>#{{ props.transaction.folio
                        }}</strong> por
                    <strong>{{ formatCurrency(totalPaid) }}</strong>.
                </p>
                <div class="flex flex-col gap-3">
                    <div v-if="props.transaction.customer_id" class="flex items-center">
                        <RadioButton v-model="refundMethod" inputId="refundBalance" value="balance" /><label
                            for="refundBalance" class="ml-2">Abonar al saldo del cliente</label>
                    </div>
                    <div class="flex items-center">
                        <RadioButton v-model="refundMethod" inputId="refundCash" value="cash"
                            :disabled="!activeSession" />
                        <label for="refundCash" class="ml-2">Retirar efectivo de caja</label><small
                            v-if="!activeSession" class="ml-2 text-orange-500">(Sin sesión activa)</small>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" @click="isRefundModalVisible = false" text />
                <Button label="Confirmar" icon="pi pi-check" @click="confirmRefund" :loading="refundProcessing"
                    :disabled="refundMethod === 'cash' && !activeSession" />
            </template>
        </Dialog>

        <!-- Modal de Información de Intercambio (NUEVO) -->
        <ProductExchangeModal v-if="transaction" v-model:visible="isProductExchangeModalVisible"
            :transaction="transaction" :user-bank-accounts="userBankAccounts" @success="router.reload()" />

        <!-- Modal de Pagos (Para abonos) -->
        <PaymentModal v-if="isPaymentModalVisible" v-model:visible="isPaymentModalVisible" :total-amount="pendingAmount"
            :client="transaction.customer" :loading="isPaymentProcessing" payment-mode="flexible"
            @submit="handlePaymentSubmit" />

        <!-- Modales de Sesión -->
        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />

    </AppLayout>
</template>