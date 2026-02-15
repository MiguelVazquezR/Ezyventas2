<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { router, Link, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from 'primevue/usetoast';
import PrintModal from '@/Components/PrintModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import { usePermissions } from '@/Composables';
import ProductExchangeModal from './Partials/ProductExchangeModal.vue';
import LayawayExchangeModal from './Partials/LayawayExchangeModal.vue';
import DatePicker from 'primevue/datepicker'; 
import Menu from 'primevue/menu'; 
import RadioButton from 'primevue/radiobutton'; 

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

// --- NUEVO: Modal Unificado de Cancelación/Devolución ---
const isCancellationModalVisible = ref(false);
const cancellationAction = ref('refund'); // 'refund' | 'penalty'
const cancellationRefundMethod = ref('cash'); // 'balance' | 'cash'
const isCancelling = ref(false);

// --- Modal de Pagos (Abonar) ---
const isPaymentModalVisible = ref(false);
const isPaymentProcessing = ref(false);

// --- Modal de Edición de Pago ---
const isEditPaymentModalVisible = ref(false);
const editingPaymentId = ref(null);

const editForm = useForm({
    amount: 0,
    payment_method: '',
    bank_account_id: null,
    notes: ''
});

// Aseguramos que las cuentas bancarias sean un arreglo válido para evitar errores de renderizado
const safeBankAccounts = computed(() => Array.isArray(props.userBankAccounts) ? props.userBankAccounts : []);

const openEditPaymentModal = (payment) => {
    editForm.clearErrors();
    editingPaymentId.value = payment.id;
    editForm.amount = parseFloat(payment.amount);
    editForm.payment_method = typeof payment.payment_method === 'object' && payment.payment_method !== null 
        ? payment.payment_method.value 
        : payment.payment_method;
    editForm.bank_account_id = payment.bank_account_id;
    editForm.notes = payment.notes || '';
    nextTick(() => {
        isEditPaymentModalVisible.value = true;
    });
};

const submitEditPayment = () => {
    editForm.put(route('transactions.updatePayment', { 
        transaction: props.transaction.id, 
        payment: editingPaymentId.value 
    }), {
        preserveScroll: true,
        onSuccess: () => {
            isEditPaymentModalVisible.value = false;
        }
    });
};

// --- Modales de Intercambio ---
const isProductExchangeModalVisible = ref(false);
const isLayawayExchangeModalVisible = ref(false);

const openExchangeModal = () => {
    if (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') {
        isLayawayExchangeModalVisible.value = true;
    } else {
        isProductExchangeModalVisible.value = true;
    }
};

// --- HELPER PARA FECHAS LOCALES ---
const toLocalISOString = (date) => {
    if (!date) return null;
    const tzOffset = date.getTimezoneOffset() * 60000;
    const localISOTime = (new Date(date - tzOffset)).toISOString().slice(0, 19).replace('T', ' ');
    return localISOTime;
};

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

// --- Modal de Extender Apartado ---
const isExtendLayawayModalVisible = ref(false);
const newExpirationDate = ref(null);
const isExtendingLayaway = ref(false);

const openExtendLayawayModal = () => {
    newExpirationDate.value = props.transaction.layaway_expiration_date 
        ? new Date(props.transaction.layaway_expiration_date) 
        : null;
    isExtendLayawayModalVisible.value = true;
};

const submitExtendLayaway = () => {
    if (!newExpirationDate.value) return;
    
    isExtendingLayaway.value = true;
    router.put(route('transactions.extend-layaway', props.transaction.id), {
        new_expiration_date: toLocalISOString(newExpirationDate.value)
    }, {
        onSuccess: () => {
            isExtendLayawayModalVisible.value = false;
        },
        onFinish: () => isExtendingLayaway.value = false
    });
};

// --- Modal de Reprogramar Pedido ---
const isRescheduleOrderModalVisible = ref(false);
const newDeliveryDate = ref(null);
const isReschedulingOrder = ref(false);

// --- NUEVO: Modal de Editar Fecha de Transacción ---
const isEditDateModalVisible = ref(false);
const newTransactionDate = ref(null);
const isUpdatingDate = ref(false);

const openEditDateModal = () => {
    // Inicializar con la fecha actual de la transacción
    newTransactionDate.value = props.transaction.created_at 
        ? new Date(props.transaction.created_at) 
        : new Date();
    isEditDateModalVisible.value = true;
};

const submitUpdateDate = () => {
    if (!newTransactionDate.value) return;
    
    isUpdatingDate.value = true;
    router.put(route('transactions.update-date', props.transaction.id), {
        created_at: toLocalISOString(newTransactionDate.value)
    }, {
        onSuccess: () => {
            isEditDateModalVisible.value = false;
        },
        onFinish: () => isUpdatingDate.value = false
    });
};

const openRescheduleOrderModal = () => {
    newDeliveryDate.value = props.transaction.delivery_date 
        ? new Date(props.transaction.delivery_date) 
        : null;
    isRescheduleOrderModalVisible.value = true;
};

const submitRescheduleOrder = () => {
    if (!newDeliveryDate.value) return;
    
    isReschedulingOrder.value = true;
    router.put(route('transactions.reschedule-order', props.transaction.id), {
        new_delivery_date: toLocalISOString(newDeliveryDate.value)
    }, {
        onSuccess: () => {
            isRescheduleOrderModalVisible.value = false;
        },
        onFinish: () => isReschedulingOrder.value = false
    });
};

// --- Menú de Teléfono ---
const phoneMenu = ref();
const targetPhone = ref('');

const phoneMenuItems = computed(() => [
    {
        label: 'Llamar',
        icon: 'pi pi-phone',
        command: () => {
            window.location.href = `tel:${targetPhone.value}`;
        }
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
const toggleActionsMenu = (event) => {
    actionsMenu.value.toggle(event);
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

// --- ACCIONES PERMITIDAS ---
const canCancelOrRefund = computed(() => {
    if (!localTransaction.value?.status) return false;
    const status = localTransaction.value.status;
    return !['cancelado', 'reembolsado'].includes(status);
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

// --- Lógica de Acciones ---
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

// --- LÓGICA DE CANCELACIÓN / DEVOLUCIÓN UNIFICADA ---
const initiateCancellation = () => {
    // Caso 1: No hay dinero de por medio -> Cancelación directa con confirmación simple
    if (totalPaid.value <= 0) {
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
            accept: () => {
                router.post(route('transactions.cancel', localTransaction.value.id), {}, { preserveScroll: true });
            }
        });
        return;
    }

    // Caso 2: Hay dinero -> Abrir Modal Completo
    cancellationAction.value = 'refund'; // Reset default
    // Default Refund Method: Caja si hay sesión, sino Saldo si hay cliente
    if (activeSession.value) {
        cancellationRefundMethod.value = 'cash';
    } else if (props.transaction.customer_id) {
        cancellationRefundMethod.value = 'balance';
    } else {
        cancellationRefundMethod.value = null; // No hay opción válida por defecto
    }
    
    isCancellationModalVisible.value = true;
};

const submitCancellation = () => {
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

    router.post(route('transactions.cancel', props.transaction.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            isCancellationModalVisible.value = false;
        },
        onFinish: () => isCancelling.value = false
    });
};

// Helper para verificar si es editable la fecha (Apartado o Crédito)
const canExtendExpiration = computed(() => {
    return ['apartado', 'on_layaway', 'pendiente'].includes(localTransaction.value.status);
});

const actionItems = computed(() => [
    { 
        label: 'Abonar / Liquidar', 
        icon: 'pi pi-dollar', 
        command: openPaymentModal, 
        disabled: !canAddPayment.value, 
        visible: hasPermission('transactions.add_payment') 
    },
    { 
        label: (localTransaction.value.status === 'apartado' || localTransaction.value.status === 'on_layaway') ? 'Modificar Apartado' : 'Intercambiar producto', 
        icon: 'pi pi-sync', 
        command: openExchangeModal, 
        disabled: !canExchange.value, 
        visible: hasPermission('transactions.exchange') 
    },
    { 
        label: 'Extender Vencimiento', 
        icon: 'pi pi-calendar-plus', 
        command: openExtendLayawayModal, 
        visible: canExtendExpiration.value // Visible para apartado y crédito
    },
    { separator: true },
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    // UNIFICADO: Solo una opción de Cancelar/Devolver
    { 
        label: 'Cancelar / Devolver', 
        icon: 'pi pi-times-circle', 
        class: 'text-red-500', 
        command: initiateCancellation, 
        disabled: !canCancelOrRefund.value, 
        visible: hasPermission('transactions.cancel') || hasPermission('transactions.refund') 
    },
]);

// Mapas de estatus y formato
const getStatusSeverity = (status) => ({ 
    completado: 'success', 
    pendiente: 'warn', 
    cancelado: 'danger', 
    reembolsado: 'info', 
    on_layaway: 'warn', 
    apartado: 'warn',
    por_entregar: 'info',
    en_ruta: 'primary',
    entregado_por_pagar: 'warn'
}[status] || 'secondary');

const formatStatusLabel = (status) => {
    if (!status) return '';
    const text = status.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
};

const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatDateOnly = (date) => date ? new Date(date).toLocaleDateString('es-MX', { dateStyle: 'long' }) : '';
const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);

const paymentMethods = [
    { label: 'Efectivo', value: 'efectivo' },
    { label: 'Tarjeta', value: 'tarjeta' },
    { label: 'Transferencia', value: 'transferencia' },
    { label: 'Saldo de Cliente', value: 'saldo' },
];

const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-[#37672B]' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-[#063C53]' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-[#D2D880]' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' },
    intercambio: { icon: 'pi pi-sync', color: 'text-orange-500' }
};

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
                <Button 
                    type="button" 
                    label="Acciones" 
                    icon="pi pi-chevron-down" 
                    iconPos="right" 
                    @click="toggleActionsMenu" 
                    severity="secondary" 
                    outlined 
                />
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
                            <!-- NUEVA COLUMNA DE SKU -->
                            <Column header="SKU">
                                <template #body="{ data }">
                                    <span class="text-gray-500 dark:text-gray-400 font-mono text-xs">
                                        {{ getItemSku(data) }}
                                    </span>
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
                                    <span class="font-bold text-blue-700 dark:text-blue-200 text-base">
                                        {{ formatDate(transaction.delivery_date) }}
                                    </span>
                                    
                                    <div v-if="transaction.shipping_address" class="mt-2 text-xs text-blue-700 dark:text-blue-300 flex gap-2">
                                        <i class="pi pi-map-marker mt-0.5"></i>
                                        <span>{{ transaction.shipping_address }}</span>
                                    </div>

                                    <!-- Botón Reprogramar Pedido -->
                                    <div v-if="localTransaction.status === 'por_entregar'" class="mt-2">
                                        <Button 
                                            label="Reprogramar" 
                                            icon="pi pi-calendar-plus" 
                                            size="small" 
                                            severity="info" 
                                            outlined
                                            class="w-full h-8 text-xs" 
                                            @click="openRescheduleOrderModal" 
                                        />
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
                                    <span 
                                        class="text-blue-600 hover:text-blue-800 cursor-pointer font-medium"
                                        @click="togglePhoneMenu($event, transaction.contact_info.phone)"
                                    >
                                        {{ transaction.contact_info.phone }} <i class="pi pi-angle-down text-xs ml-1"></i>
                                    </span>
                                </div>
                            </li>

                            <!-- Sección de Vencimiento (Apartado y Crédito) -->
                            <li v-if="transaction.layaway_expiration_date" class="bg-purple-50 dark:bg-purple-900/20 p-2 rounded -mx-2">
                                <div class="flex justify-between items-center">
                                    <span class="text-purple-800 dark:text-purple-300 font-medium">Vencimiento:</span>
                                    <span class="font-bold text-purple-700 dark:text-purple-200">{{ formatDateOnly(transaction.layaway_expiration_date) }}</span>
                                </div>
                                
                                <div v-if="canExtendExpiration" class="mt-2">
                                    <Button 
                                        label="Extender fecha" 
                                        icon="pi pi-calendar-plus" 
                                        size="small" 
                                        severity="help" 
                                        outlined
                                        class="w-full h-8 text-xs" 
                                        @click="openExtendLayawayModal" 
                                    />
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
                        <ul v-else class="space-y-3">
                            <li v-for="payment in localTransaction.payments" :key="payment.id" class="text-sm">
                                <div class="flex justify-between items-center">
                                    <span class="flex items-center gap-2">
                                        <i class="pi" :class="(paymentMethodIcons[payment.payment_method]?.icon || 'pi-circle') + ' ' + (paymentMethodIcons[payment.payment_method]?.color || 'text-gray-500')"></i>
                                        <span class="capitalize font-medium">
                                            {{ payment.payment_method === 'intercambio' ? 'Intercambio' : payment.payment_method }}
                                        </span>
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                        <Button 
                                            v-if="hasPermission('transactions.edit_payment') && localTransaction.status !== 'cancelado' && localTransaction.status !== 'reembolsado'"
                                            icon="pi pi-pencil" 
                                            class="p-button-text p-button-sm p-button-rounded" 
                                            v-tooltip.top="'Editar pago'"
                                            @click="openEditPaymentModal(payment)" 
                                        />
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 ml-6">{{ formatDate(payment.payment_date || payment.created_at) }}</p>
                            </li>
                        </ul>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modales de Sistema -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource" :available-templates="availableTemplates" />

        <!-- MODAL UNIFICADO DE CANCELACIÓN Y DEVOLUCIÓN -->
        <Dialog v-model:visible="isCancellationModalVisible" modal header="Anular Transacción" :style="{ width: '32rem' }">
            <div class="p-fluid">
                <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800 mb-4 text-sm text-blue-800 dark:text-blue-200">
                    <i class="pi pi-info-circle mr-1"></i>
                    Esta venta tiene pagos registrados por <strong>{{ formatCurrency(totalPaid) }}</strong>.
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

                            <div v-if="transaction.customer_id" class="flex items-center">
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

        <!-- Modal de Edición de Pago -->
        <Dialog v-model:visible="isEditPaymentModalVisible" modal header="Editar Pago Realizado" :style="{ width: '32rem' }">
            <div class="flex flex-col gap-6 pt-2">
                <div class="flex flex-col gap-2">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Monto del pago</label>
                    <InputNumber v-model="editForm.amount" mode="currency" currency="MXN" locale="es-MX" :min="0.01" autofocus class="w-full" />
                    <small v-if="editForm.errors.amount" class="text-red-500 font-medium">{{ editForm.errors.amount }}</small>
                </div>
                <div class="flex flex-col gap-2">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Método de pago</label>
                    <Select v-model="editForm.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" placeholder="Seleccione método" class="w-full" />
                    <small v-if="editForm.errors.payment_method" class="text-red-500 font-medium">{{ editForm.errors.payment_method }}</small>
                </div>
                <div v-if="editForm.payment_method !== 'efectivo' && editForm.payment_method !== 'saldo'" class="flex flex-col gap-2">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Cuenta bancaria de destino</label>
                    <Select 
                        v-model="editForm.bank_account_id" 
                        :options="safeBankAccounts" 
                        optionLabel="bank_name" 
                        optionValue="id" 
                        placeholder="Seleccione cuenta"  
                        class="w-full"
                    >
                        <template #option="slotProps">
                            <div class="flex flex-col py-0.5">
                                <span class="font-semibold text-sm">{{ slotProps.option.bank_name }}</span>
                                <span class="text-xs text-gray-500 italic">{{ slotProps.option.owner_name }} ({{ slotProps.option.account_name }})</span>
                            </div>
                        </template>
                    </Select>
                    <small v-if="editForm.errors.bank_account_id" class="text-red-500 font-medium">{{ editForm.errors.bank_account_id }}</small>
                </div>
                <div class="flex flex-col gap-2">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Notas internas / Referencia</label>
                    <Textarea v-model="editForm.notes" rows="3" placeholder="Ej. Folio de transferencia, terminal usada, etc." class="w-full" />
                    <small v-if="editForm.errors.notes" class="text-red-500 font-medium">{{ editForm.errors.notes }}</small>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end items-center gap-3">
                    <Button label="Cancelar" severity="secondary" @click="isEditPaymentModalVisible = false" text />
                    <Button label="Guardar Cambios" icon="pi pi-save" @click="submitEditPayment" :loading="editForm.processing" severity="primary" />
                </div>
            </template>
        </Dialog>

        <!-- Nuevo Modal de Extender Apartado -->
        <Dialog v-model:visible="isExtendLayawayModalVisible" modal header="Extender fecha de vencimiento" :style="{ width: '25rem' }">
            <div class="flex flex-col gap-4 py-2">
                <p class="text-sm text-gray-500">Selecciona la nueva fecha límite para liquidar este saldo.</p>
                <div class="flex flex-col gap-2">
                    <label class="font-bold text-gray-700 dark:text-gray-300">Nueva fecha</label>
                    <DatePicker v-model="newExpirationDate" dateFormat="dd/mm/yy" :minDate="new Date()" showIcon class="w-full" />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Cancelar" severity="secondary" text @click="isExtendLayawayModalVisible = false" />
                    <Button label="Guardar fecha" icon="pi pi-check" @click="submitExtendLayaway" :loading="isExtendingLayaway" />
                </div>
            </template>
        </Dialog>

        <!-- Nuevo Modal de Reprogramar Pedido -->
        <Dialog v-model:visible="isRescheduleOrderModalVisible" modal header="Reprogramar entrega" :style="{ width: '25rem' }">
            <div class="flex flex-col gap-4 py-2">
                <p class="text-sm text-gray-500">Selecciona la nueva fecha y hora para la entrega del pedido.</p>
                <div class="flex flex-col gap-2">
                    <label class="font-bold text-gray-700 dark:text-gray-300">Nueva fecha de entrega</label>
                    <DatePicker v-model="newDeliveryDate" showTime hourFormat="12" dateFormat="dd/mm/yy" :minDate="new Date()" showIcon class="w-full" />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Cancelar" severity="secondary" text @click="isRescheduleOrderModalVisible = false" />
                    <Button label="Guardar fecha" icon="pi pi-check" @click="submitRescheduleOrder" :loading="isReschedulingOrder" />
                </div>
            </template>
        </Dialog>

        <!-- NUEVO MODAL: Editar Fecha de Creación -->
        <Dialog v-model:visible="isEditDateModalVisible" modal header="Editar fecha de venta" :style="{ width: '25rem' }">
            <div class="flex flex-col gap-4 py-2">
                <Message severity="warn" :closable="false">
                    Cambiar la fecha afectará los reportes y cortes de caja de ese día.
                </Message>
                <div class="flex flex-col gap-2">
                    <label class="font-bold text-gray-700 dark:text-gray-300">Nueva fecha y hora</label>
                    <DatePicker v-model="newTransactionDate" showTime hourFormat="12" dateFormat="dd/mm/yy" showIcon class="w-full" />
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Cancelar" severity="secondary" text @click="isEditDateModalVisible = false" />
                    <Button label="Actualizar" icon="pi pi-save" @click="submitUpdateDate" :loading="isUpdatingDate" />
                </div>
            </template>
        </Dialog>

        <!-- Menu Teléfono -->
        <Menu ref="phoneMenu" :model="phoneMenuItems" :popup="true" />

        <!-- MODALES DE INTERCAMBIO -->
        <ProductExchangeModal v-if="transaction" v-model:visible="isProductExchangeModalVisible" :transaction="transaction" :user-bank-accounts="userBankAccounts" @success="router.reload()" />
        
        <!-- MODAL NUEVO DE APARTADO -->
        <LayawayExchangeModal v-if="transaction" v-model:visible="isLayawayExchangeModalVisible" :transaction="transaction" :user-bank-accounts="userBankAccounts" @success="router.reload()" />

        <PaymentModal v-if="isPaymentModalVisible" v-model:visible="isPaymentModalVisible" :total-amount="pendingAmount" :client="transaction.customer" :loading="isPaymentProcessing" payment-mode="flexible" @submit="handlePaymentSubmit" />
        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters" :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />
    </AppLayout>
</template>