<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3'; // <-- Importado usePage
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import PrintModal from '@/Components/PrintModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    transaction: Object,
    availableTemplates: Array,
});

// composables
const { hasPermission } = usePermissions();
const page = usePage(); // <-- Obtener props compartidos

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);
const openPrintModal = () => {
    printDataSource.value = {
        type: 'transaction',
        id: props.transaction.id
    };
    isPrintModalVisible.value = true;
};

// --- INICIO: Nuevos refs para el modal de reembolso ---
const isRefundModalVisible = ref(false);
const refundMethod = ref('cash'); // Por defecto 'cash'
// No necesitamos refundingTransaction aquí, usaremos props.transaction directamente
const refundProcessing = ref(false); // Estado de carga para el botón de confirmar reembolso
// --- FIN: Nuevos refs ---

// --- Computado para saber si hay sesión de caja activa ---
const activeSession = computed(() => page.props.activeSession);


const confirm = useConfirm();
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Historial de ventas', url: route('transactions.index') },
    { label: `Venta #${props.transaction.folio}` }
]);

// Usaremos props.transaction directamente, pero mantenemos localTransaction por si acaso se usa en otro lado
const localTransaction = ref(props.transaction);
watch(() => props.transaction, (newTransaction) => {
    localTransaction.value = newTransaction;
}, { deep: true });

const totalAmount = computed(() => parseFloat(localTransaction.value.total));

const totalPaid = computed(() => {
    // Asegurarse de que payments sea un array antes de reducir
    if (!Array.isArray(localTransaction.value.payments)) return 0;
    return localTransaction.value.payments.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0); // Añadir || 0 por seguridad
});


const pendingAmount = computed(() => {
    const total = totalAmount.value;
    const paid = totalPaid.value;
    // Evitar resultados negativos muy pequeños debido a errores de punto flotante
    const diff = total - paid;
    return diff < 0.01 ? 0 : diff;
});

const canCancel = computed(() => {
    // Verificar que localTransaction y sus propiedades existan
    if (!localTransaction.value || !localTransaction.value.status) {
        return false;
    }
    // Condición 1: No debe estar ya cancelada o reembolsada
    const isValidStatus = !['cancelado', 'reembolsado'].includes(localTransaction.value.status);
    // Condición 2: No debe tener pagos registrados (total pagado debe ser 0)
    const hasNoPayments = totalPaid.value === 0;

    return isValidStatus && hasNoPayments;
});

const canRefund = computed(() => {
    // Se puede reembolsar si está completada (independientemente de si hay pagos o no)
    // O si está pendiente PERO tiene pagos (caso de anticipo en venta pendiente)
    if (!localTransaction.value || !localTransaction.value.status) {
        return false;
    }
    const isCompleted = localTransaction.value.status === 'completado';
    const isPendingWithPayments = localTransaction.value.status === 'pendiente' && totalPaid.value > 0;

    return isCompleted || isPendingWithPayments;
});

const cancelSale = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres cancelar la venta #${localTransaction.value.folio}? Esta acción repondrá el stock y ajustará el saldo del cliente si fue a crédito.`,
        header: 'Confirmar Cancelación',
        accept: () => {
            router.post(route('transactions.cancel', localTransaction.value.id), {}, {
                preserveScroll: true,
                onSuccess: () => {
                    // Actualizar estado local si es necesario, aunque Inertia debería hacerlo
                    if (localTransaction.value) {
                        localTransaction.value.status = 'cancelado';
                    }
                }
            });
        }
    });
};

// --- INICIO: Nueva lógica de reembolso ---
const openRefundModal = () => {
    // Usamos props.transaction directamente
    // Si no hay cliente, forzar 'cash'. Si hay cliente, default a 'cash' pero permitir cambiar.
    refundMethod.value = props.transaction.customer_id ? 'cash' : 'cash';
    isRefundModalVisible.value = true;
};

const confirmRefund = () => {
    refundProcessing.value = true;
    router.post(route('transactions.refund', props.transaction.id),
        { refund_method: refundMethod.value }, // <-- Enviar el método elegido
        {
            preserveScroll: true,
            onSuccess: () => {
                isRefundModalVisible.value = false; // Cerrar modal en éxito
                // Actualizar estado local si es necesario
                if (localTransaction.value) {
                    localTransaction.value.status = 'reembolsado';
                }
            },
            onFinish: () => {
                refundProcessing.value = false; // Detener carga
            }
        });
};
// --- FIN: Nueva lógica de reembolso ---


const actionItems = computed(() => [
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Generar devolución', icon: 'pi pi-replay', command: openRefundModal, disabled: !canRefund.value, visible: hasPermission('transactions.refund') }, // <-- Llamar a openRefundModal
    { label: 'Cancelar venta', icon: 'pi pi-times-circle', class: 'text-red-500', command: cancelSale, disabled: !canCancel.value, visible: hasPermission('transactions.cancel') },
]);

const getStatusSeverity = (status) => {
    // Agregamos 'on_layaway' o 'apartado' al mapa por si acaso, aunque el color default sirve
    const map = { completado: 'success', pendiente: 'warn', cancelado: 'danger', reembolsado: 'info', on_layaway: 'warn', apartado: 'warn' };
    return map[status] || 'secondary';
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    try {
        return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) {
        console.error("Error formatting date:", dateString, e);
        return dateString;
    }
};

// --- NUEVO HELPER PARA FECHAS SIN HORA (VENCIMIENTOS) ---
const formatDateOnly = (dateString) => {
    if (!dateString) return '';
    try {
        // Agregamos T00:00:00 para asegurar que se interprete como local y no UTC (que podría restar un día)
        return new Date(dateString).toLocaleDateString('es-MX', { dateStyle: 'long' });
    } catch (e) {
        return dateString;
    }
};

const formatCurrency = (value) => {
    if (value === null || value === undefined) return '';
    const numberValue = Number(value);
    if (isNaN(numberValue)) return '';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(numberValue);
};

const paymentMethodIcons = { efectivo: { icon: 'pi pi-money-bill', color: 'text-[#37672B]' }, tarjeta: { icon: 'pi pi-credit-card', color: 'text-[#063C53]' }, transferencia: { icon: 'pi pi-arrows-h', color: 'text-[#D2D880]' }, saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' } };
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
                                        <!-- Corregido: Comparar con parseFloat para evitar errores -->
                                        <del v-if="parseFloat(data.discount_amount || 0) !== 0"
                                            class="text-gray-500 text-xs">
                                            <!-- Corregido: Sumar correctamente -->
                                            {{ formatCurrency(parseFloat(data.unit_price || 0) +
                                                parseFloat(data.discount_amount || 0)) }}
                                        </del>
                                        <p class="font-semibold m-0">
                                            {{ formatCurrency(data.unit_price) }}
                                        </p>
                                        <p v-if="parseFloat(data.discount_amount || 0) > 0"
                                            class="text-xs text-green-600 m-0">
                                            Ahorro: {{ formatCurrency(data.discount_amount) }}
                                            <span v-if="data.discount_reason" class="text-gray-500 dark:text-gray-400">
                                                ({{ data.discount_reason }})
                                            </span>
                                        </p>
                                        <p v-else-if="parseFloat(data.discount_amount || 0) < 0"
                                            class="text-xs text-red-600 m-0">
                                            Aumento: {{ formatCurrency(Math.abs(data.discount_amount)) }}
                                            <span v-if="data.discount_reason" class="text-gray-500 dark:text-gray-400">
                                                ({{ data.discount_reason }})
                                            </span>
                                        </p>
                                    </div>
                                </template>
                            </Column>
                            <!-- Corregido: Usar text-right para alinear -->
                            <Column field="line_total" header="Total" class="text-right">
                                <template #body="{ data }">
                                    {{ formatCurrency(data.line_total) }}
                                </template>
                            </Column>
                            <template #empty>
                                <div class="text-center text-gray-500 py-4">
                                    No hay conceptos registrados.
                                </div>
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
                            <li class="flex justify-between"><span>Subtotal:</span><span>{{
                                formatCurrency(transaction.subtotal) }}</span></li>
                            <li v-if="parseFloat(transaction.total_discount || 0) > 0" class="flex justify-between">
                                <span>Descuento Total:</span><span class="text-green-500">- {{
                                    formatCurrency(transaction.total_discount) }}</span>
                            </li>
                            <li v-else-if="parseFloat(transaction.total_discount || 0) < 0"
                                class="flex justify-between">
                                <span>Aumento Total:</span><span class="text-red-500">+ {{
                                    formatCurrency(Math.abs(transaction.total_discount)) }}</span>
                            </li>
                            <li class="flex justify-between font-bold text-base border-t pt-2 mt-2"><span>Total de la
                                    Venta:</span><span>{{ formatCurrency(totalAmount) }}</span></li>
                            <li class="flex justify-between"><span>Total Pagado:</span><span class="font-semibold">{{
                                formatCurrency(totalPaid) }}</span></li>
                            <li v-if="pendingAmount > 0" class="flex justify-between font-bold text-red-600">
                                <span>Saldo Pendiente:</span><span>{{ formatCurrency(pendingAmount) }}</span>
                            </li>
                        </ul>
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

                            <!-- INICIO: FECHA DE VENCIMIENTO APARTADO -->
                            <li v-if="transaction.layaway_expiration_date" class="flex justify-between items-center bg-purple-50 dark:bg-purple-900/20 p-2 rounded -mx-2">
                                <span class="text-purple-800 dark:text-purple-300 font-medium">Vencimiento Apartado:</span>
                                <span class="font-bold text-purple-700 dark:text-purple-200">
                                    {{ formatDateOnly(transaction.layaway_expiration_date) }}
                                </span>
                            </li>
                            <!-- FIN: FECHA DE VENCIMIENTO APARTADO -->

                            <li class="flex justify-between items-center">
                                <span>Cliente:</span>
                                <span class="font-medium">
                                    <template v-if="transaction.customer">
                                        <Link :href="route('customers.show', transaction.customer.id)"
                                            class="text-blue-600 hover:underline flex items-center gap-2">
                                        {{ transaction.customer.name }}
                                        <i class="pi pi-external-link text-xs"></i>
                                        </Link>
                                    </template>
                                    <template v-else>
                                        Público en general
                                    </template>
                                </span>
                            </li>
                            <li class="flex justify-between"><span>Cajero:</span><span class="font-medium">{{
                                transaction.user?.name || 'N/A' }}</span></li>
                            <li class="flex justify-between"><span>Sucursal:</span><span class="font-medium">{{
                                transaction.branch?.name || 'N/A' }}</span></li>
                            <li class="flex justify-between"><span>Canal:</span><span class="font-medium capitalize">{{
                                (transaction.channel || '').replace('_', ' ') }}</span></li>
                        </ul>
                    </template>
                </Card>
                <Card>
                    <template #title>Pagos realizados</template>
                    <template #content>
                        <!-- Corregido: Verificar si es un array -->
                        <div v-if="!Array.isArray(localTransaction.payments) || localTransaction.payments.length === 0">
                            <p class="text-center text-gray-500 text-sm py-4">No se han registrado pagos.</p>
                        </div>
                        <ul v-else class="space-y-3">
                            <li v-for="payment in localTransaction.payments" :key="payment.id" class="text-sm">
                                <div class="flex justify-between items-center">
                                    <span class="flex items-center gap-2">
                                        <!-- Corregido: Verificar si paymentMethodIcons tiene la clave -->
                                        <i class="pi"
                                            :class="paymentMethodIcons[payment.payment_method]?.icon + ' ' + paymentMethodIcons[payment.payment_method]?.color"></i>
                                        <span class="capitalize font-medium">{{ payment.payment_method }}</span>
                                        <small v-if="payment.bank_account"
                                            class="text-gray-500 dark:text-gray-400 truncate"
                                            v-tooltip.bottom="payment.bank_account.account_name">
                                            ({{ payment.bank_account.account_name }})
                                        </small>
                                    </span>
                                    <span class="font-mono font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                </div>
                                <!-- Corregido: Usar payment_date -->
                                <p class="text-xs text-gray-500 ml-6">{{ formatDate(payment.payment_date ||
                                    payment.created_at) }}</p>
                            </li>
                        </ul>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modal de Impresión -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />

        <!-- Nuevo Modal para Confirmar Reembolso -->
        <Dialog v-model:visible="isRefundModalVisible" modal header="Confirmar devolución" :style="{ width: '30rem' }">
            <!-- Usamos props.transaction directamente -->
            <div class="p-fluid">
                <p class="mb-4">
                    Estás a punto de generar una devolución para la venta <strong>#{{ props.transaction.folio
                        }}</strong>
                    por un total de <strong>{{ formatCurrency(totalPaid) }}</strong>.
                    El stock de los productos será repuesto.
                </p>

                <p class="mb-2 font-semibold">¿Cómo deseas procesar el reembolso?</p>
                <div class="flex flex-col gap-3">
                    <!-- Opción Saldo (Solo si hay cliente) -->
                    <div v-if="props.transaction.customer_id" class="flex items-center">
                        <RadioButton v-model="refundMethod" inputId="refundBalance" name="refundMethod"
                            value="balance" />
                        <label for="refundBalance" class="ml-2">Abonar al saldo del cliente</label>
                    </div>
                    <!-- Opción Efectivo (Siempre visible, pero puede estar deshabilitada) -->
                    <div class="flex items-center">
                        <RadioButton v-model="refundMethod" inputId="refundCash" name="refundMethod" value="cash"
                            :disabled="!activeSession" />
                        <label for="refundCash" class="ml-2">Retirar efectivo de la caja actual</label>
                        <!-- Mensaje si no hay sesión activa -->
                        <small v-if="!activeSession" class="ml-2 text-orange-500">(Necesitas una sesión de caja
                            activa)</small>
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
                <Button label="Cancelar" severity="secondary" @click="isRefundModalVisible = false" text />
                <Button label="Confirmar devolución" icon="pi pi-check" @click="confirmRefund"
                    :loading="refundProcessing" :disabled="refundMethod === 'cash' && !activeSession" />
            </template>
        </Dialog>

    </AppLayout>
</template>