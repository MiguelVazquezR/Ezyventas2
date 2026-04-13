<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
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

// NUEVO: Importamos los modales extraídos para limpiar el código principal
import EditPaymentModal from './Partials/EditPaymentModal.vue';
import ExtendLayawayModal from './Partials/ExtendLayawayModal.vue';
import RescheduleOrderModal from './Partials/RescheduleOrderModal.vue';
import EditTransactionDateModal from './Partials/EditTransactionDateModal.vue';

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
const paymentToEdit = ref(null); // Guardamos el pago específico que vamos a editar

const safeBankAccounts = computed(() => Array.isArray(props.userBankAccounts) ? props.userBankAccounts : []);

// --- Acciones de Apertura de Modales ---
const openEditPaymentModal = (payment) => {
    paymentToEdit.value = payment;
    isEditPaymentModalVisible.value = true;
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

// --- HELPER PARA OBTENER SKU ---
const getItemSku = (item) => {
    if (!item.itemable) return '';
    if (item.itemable.sku) return item.itemable.sku;
    if (item.itemable.sku_suffix && item.itemable.product) {
        return `${item.itemable.product.sku}-${item.itemable.sku_suffix}`;
    }
    if (item.itemable.sku_suffix) return `...-${item.itemable.sku_suffix}`; 
    return '';
};

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

const canExtendExpiration = computed(() => ['apartado', 'on_layaway', 'pendiente'].includes(localTransaction.value.status));

const actionItems = computed(() => [
    { label: 'Abonar / Liquidar', icon: 'pi pi-dollar', command: openPaymentModal, disabled: !canAddPayment.value, visible: hasPermission('transactions.add_payment') },
    { label: (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') ? 'Modificar Apartado' : 'Intercambiar producto', icon: 'pi pi-sync', command: openExchangeModal, disabled: !canExchange.value, visible: hasPermission('transactions.exchange') },
    { label: 'Extender Vencimiento', icon: 'pi pi-calendar-plus', command: openExtendLayawayModal, visible: canExtendExpiration.value },
    { separator: true },
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Cancelar / Devolver', icon: 'pi pi-times-circle', class: 'text-red-500', command: initiateCancellation, disabled: !canCancelOrRefund.value, visible: hasPermission('transactions.cancel') || hasPermission('transactions.refund') },
]);

// --- Formatos ---
const getStatusSeverity = (status) => ({ completado: 'success', pendiente: 'warn', cancelado: 'danger', reembolsado: 'info', on_layaway: 'warn', apartado: 'warn', por_entregar: 'info', en_ruta: 'primary', entregado_por_pagar: 'warn' }[status] || 'secondary');
const formatStatusLabel = (status) => status ? (status.replace(/_/g, ' ').charAt(0).toUpperCase() + status.replace(/_/g, ' ').slice(1).toLowerCase()) : '';
const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatDateOnly = (date) => date ? new Date(date).toLocaleDateString('es-MX', { dateStyle: 'long' }) : '';
const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);

const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-[#37672B]' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-[#063C53]' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-[#D2D880]' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' },
    intercambio: { icon: 'pi pi-sync', color: 'text-orange-500' }
};

const getMethodKey = (method) => typeof method === 'object' ? method.value : (method || 'efectivo');

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
            <!-- Acciones con Botón + Menú -->
            <div class="flex items-center gap-2 mt-4 sm:mt-0">
                <Button type="button" label="Acciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleActionsMenu" severity="secondary" outlined />
                <Menu ref="actionsMenu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal -->
            <div class="lg:col-span-2 space-y-6">
                <Card>
                    <template #title>Detalles de los conceptos</template>
                    <template #content>
                        <DataTable :value="transaction.items" class="p-datatable-sm">
                            <Column header="SKU">
                                <template #body="{ data }">
                                    <span class="text-gray-500 dark:text-gray-400 font-mono text-xs">{{ getItemSku(data) }}</span>
                                </template>
                            </Column>
                            <Column field="description" header="Descripción"></Column>
                            <Column field="quantity" header="Cantidad" class="text-center"></Column>
                            <Column header="Precio unitario">
                                <template #body="{ data }">
                                    <div>
                                        <del v-if="parseFloat(data.discount_amount || 0) !== 0" class="text-gray-500 text-xs">
                                            {{ formatCurrency(parseFloat(data.unit_price || 0) + parseFloat(data.discount_amount || 0)) }}
                                        </del>
                                        <p class="font-semibold m-0">{{ formatCurrency(data.unit_price) }}</p>
                                        <p v-if="parseFloat(data.discount_amount) > 0" class="text-xs text-green-600 m-0">Ahorro: {{ formatCurrency(data.discount_amount) }}</p>
                                    </div>
                                </template>
                            </Column>
                            <Column field="line_total" header="Total" class="text-right">
                                <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
                            </Column>
                            <template #empty>
                                <div class="text-center py-4">No hay conceptos registrados en esta venta.</div>
                            </template>
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
                            <li class="flex justify-between"><span>Subtotal:</span><span>{{ formatCurrency(transaction.subtotal) }}</span></li>
                            
                            <li v-if="parseFloat(transaction.shipping_cost) > 0" class="flex justify-between">
                                <span>Envío:</span><span class="font-medium text-blue-600">{{ formatCurrency(transaction.shipping_cost) }}</span>
                            </li>

                            <li v-if="parseFloat(transaction.total_discount) > 0" class="flex justify-between">
                                <span>Descuento:</span><span class="text-green-500">- {{ formatCurrency(transaction.total_discount) }}</span>
                            </li>
                            <li class="flex justify-between font-bold text-base border-t pt-2 mt-2">
                                <span>Total:</span><span>{{ formatCurrency(totalAmount) }}</span>
                            </li>
                            <li class="flex justify-between"><span>Pagado:</span><span class="font-semibold">{{ formatCurrency(totalPaid) }}</span></li>
                            <li v-if="pendingAmount > 0" class="flex justify-between font-bold text-red-600 text-lg bg-red-50 dark:bg-red-900/20 p-2 rounded">
                                <span>Pendiente:</span><span>{{ formatCurrency(pendingAmount) }}</span>
                            </li>
                        </ul>
                        <div v-if="canAddPayment && hasPermission('transactions.add_payment')" class="mt-4">
                            <Button label="Abonar a esta cuenta" icon="pi pi-dollar" class="w-full p-button-success" @click="openPaymentModal" />
                        </div>
                    </template>
                </Card>

                <Card>
                    <template #title>Información de la venta</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Estatus:</span>
                                <Tag :value="formatStatusLabel(localTransaction.status)" :severity="getStatusSeverity(localTransaction.status)" />
                            </li>
                            
                            <!-- DETALLES DE PEDIDO -->
                            <li v-if="transaction.delivery_date" class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg -mx-2 border border-blue-100 dark:border-blue-800">
                                <div class="flex flex-col gap-1">
                                    <span class="text-blue-800 dark:text-blue-300 font-bold text-xs uppercase mb-1">
                                        <i class="pi pi-truck mr-1"></i>Entrega Programada
                                    </span>
                                    <span class="font-bold text-blue-700 dark:text-blue-200 text-base">{{ formatDate(transaction.delivery_date) }}</span>
                                    
                                    <div v-if="transaction.shipping_address" class="mt-2 text-xs text-blue-700 dark:text-blue-300 flex gap-2">
                                        <i class="pi pi-map-marker mt-0.5"></i>
                                        <span>{{ transaction.shipping_address }}</span>
                                    </div>

                                    <div v-if="localTransaction.status === 'por_entregar'" class="mt-2">
                                        <Button label="Reprogramar" icon="pi pi-calendar-plus" size="small" severity="info" outlined class="w-full h-8 text-xs" @click="openRescheduleOrderModal" />
                                    </div>
                                </div>
                            </li>

                            <!-- CONTACTO TEMPORAL -->
                            <li v-if="!transaction.customer && transaction.contact_info" class="flex flex-col border-b pb-2">
                                <span class="text-gray-500 dark:text-gray-400 mb-1 text-xs font-bold">Datos de Contacto (Invitado):</span>
                                <div class="flex items-center gap-2">
                                    <i class="pi pi-user text-gray-400"></i>
                                    <span class="font-medium">{{ transaction.contact_info.name }}</span>
                                </div>
                                <div v-if="transaction.contact_info.phone" class="flex items-center gap-2 mt-1">
                                    <i class="pi pi-phone text-gray-400"></i>
                                    <span class="text-blue-600 hover:text-blue-800 cursor-pointer font-medium" @click="togglePhoneMenu($event, transaction.contact_info.phone)">
                                        {{ transaction.contact_info.phone }} <i class="pi pi-angle-down text-xs ml-1"></i>
                                    </span>
                                </div>
                            </li>

                            <!-- Sección de Vencimiento -->
                            <li v-if="transaction.layaway_expiration_date" class="bg-purple-50 dark:bg-purple-900/20 p-2 rounded -mx-2">
                                <div class="flex justify-between items-center">
                                    <span class="text-purple-800 dark:text-purple-300 font-medium">Vencimiento:</span>
                                    <span class="font-bold text-purple-700 dark:text-purple-200">{{ formatDateOnly(transaction.layaway_expiration_date) }}</span>
                                </div>
                                <div v-if="canExtendExpiration" class="mt-2">
                                    <Button label="Extender fecha" icon="pi pi-calendar-plus" size="small" severity="help" outlined class="w-full h-8 text-xs" @click="openExtendLayawayModal" />
                                </div>
                            </li>

                            <li v-if="transaction.customer" class="flex justify-between items-center">
                                <span>Cliente:</span>
                                <span class="font-medium">
                                    <Link :href="route('customers.show', transaction.customer.id)" class="text-blue-600 hover:underline flex items-center gap-2">
                                        {{ transaction.customer.name }} <i class="pi pi-external-link text-xs"></i>
                                    </Link>
                                </span>
                            </li>
                            <li v-else-if="!transaction.contact_info" class="flex justify-between items-center">
                                <span>Cliente:</span>
                                <span class="font-medium text-gray-500 italic">Público en general</span>
                            </li>

                            <li class="flex justify-between"><span>Cajero:</span><span class="font-medium">{{ transaction.user?.name || 'N/A' }}</span></li>
                            <li v-if="transaction.notes" class="flex flex-col border-t pt-2 mt-2">
                                <span class="text-gray-500 dark:text-gray-400 mb-1 text-xs uppercase font-bold">Notas / Referencia:</span>
                                <p class="text-sm bg-gray-50 dark:bg-gray-700/50 p-2 rounded italic text-gray-700 dark:text-gray-300">{{ transaction.notes }}</p>
                            </li>
                        </ul>
                    </template>
                </Card>

                <Card>
                    <template #title>Pagos realizados</template>
                    <template #content>
                        <div v-if="!localTransaction.payments?.length">
                            <p class="text-center text-gray-500 text-sm py-4">No se han registrado pagos.</p>
                        </div>
                        <ul v-else class="space-y-4">
                            <li v-for="payment in localTransaction.payments" :key="payment.id" class="text-sm">
                                <div class="flex justify-between items-center">
                                    
                                    <!-- Info de Método y Banco -->
                                    <div class="flex flex-col">
                                        <span class="flex items-center gap-2">
                                            <i class="pi" :class="(paymentMethodIcons[getMethodKey(payment.payment_method)]?.icon || 'pi-circle') + ' ' + (paymentMethodIcons[getMethodKey(payment.payment_method)]?.color || 'text-gray-500')"></i>
                                            <span class="capitalize font-medium">
                                                {{ getMethodKey(payment.payment_method) === 'intercambio' ? 'Intercambio' : getMethodKey(payment.payment_method) }}
                                            </span>
                                            <!-- ETIQUETA DE DEVOLUCIÓN -->
                                            <Tag v-if="payment.amount < 0" severity="danger" value="Devolución" class="!text-[10px] !px-1.5 !py-0.5 ml-1" />
                                        </span>
                                        
                                        <!-- CUENTA BANCARIA ASOCIADA -->
                                        <div v-if="payment.bank_account" class="text-xs text-gray-500 flex items-center gap-1 mt-1 ml-6">
                                            <i class="pi pi-building text-[10px]"></i>
                                            <span>{{ payment.bank_account.bank_name }} - {{ payment.bank_account.account_name }}</span>
                                            <span v-if="payment.bank_account.account_number || payment.bank_account.card_number" class="text-[10px] italic">
                                                (***{{ (payment.bank_account.account_number || payment.bank_account.card_number).slice(-4) }})
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Acciones y Monto -->
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-semibold" :class="{'text-red-600': payment.amount < 0}">
                                            {{ formatCurrency(payment.amount) }}
                                        </span>
                                        <Button 
                                            v-if="hasPermission('transactions.edit_payment') && localTransaction.status !== 'cancelado' && localTransaction.status !== 'reembolsado'"
                                            icon="pi pi-pencil" 
                                            class="p-button-text p-button-sm p-button-rounded" 
                                            v-tooltip.top="'Editar pago'"
                                            @click="openEditPaymentModal(payment)" 
                                        />
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 ml-6 mt-1">{{ formatDate(payment.payment_date || payment.created_at) }}</p>
                            </li>
                        </ul>
                    </template>
                </Card>
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