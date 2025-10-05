<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
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

const confirm = useConfirm();
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Historial de Ventas', url: route('transactions.index') },
    { label: `Venta #${props.transaction.folio}` }
]);

const localTransaction = ref(props.transaction);
watch(() => props.transaction, (newTransaction) => {
    localTransaction.value = newTransaction;
}, { deep: true });

const totalAmount = computed(() => parseFloat(localTransaction.value.total));

const totalPaid = computed(() => {
    return localTransaction.value.payments.reduce((sum, p) => sum + parseFloat(p.amount), 0);
});

const pendingAmount = computed(() => totalAmount.value - totalPaid.value);

const canCancel = computed(() => ['completado', 'pendiente'].includes(localTransaction.value.status));
const canRefund = computed(() => localTransaction.value.status === 'completado');

const cancelSale = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres cancelar la venta #${localTransaction.value.folio}? Esta acción repondrá el stock.`,
        header: 'Confirmar Cancelación',
        accept: () => {
            router.patch(route('transactions.cancel', localTransaction.value.id), {}, {
                preserveScroll: true,
                onSuccess: () => { localTransaction.value.status = 'cancelado'; }
            });
        }
    });
};

const generateReturn = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres generar una devolución para la venta #${localTransaction.value.folio}? El stock será repuesto.`,
        header: 'Confirmar Devolución',
        accept: () => {
            router.patch(route('transactions.refund', localTransaction.value.id), {}, {
                preserveScroll: true,
                onSuccess: () => { localTransaction.value.status = 'reembolsado'; }
            });
        }
    });
};

const actionItems = computed(() => [
    { label: 'Imprimir ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Generar devolución', icon: 'pi pi-replay', command: generateReturn, disabled: !canRefund.value, visible: hasPermission('transactions.refund') },
    { label: 'Cancelar venta', icon: 'pi pi-times-circle', class: 'text-red-500', command: cancelSale, disabled: !canCancel.value, visible: hasPermission('transactions.cancel') },
]);

const getStatusSeverity = (status) => {
    const map = { completado: 'success', pendiente: 'info', cancelado: 'danger', reembolsado: 'warning' };
    return map[status] || 'secondary';
};
const formatDate = (dateString) => new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const paymentMethodIcons = { efectivo: { icon: 'pi pi-money-bill', color: 'text-green-500' }, tarjeta: { icon: 'pi pi-credit-card', color: 'text-blue-500' }, transferencia: { icon: 'pi pi-globe', color: 'text-purple-500' }, saldo: { icon: 'pi pi-wallet', color: 'text-orange-500' } };
</script>

<template>

    <Head :title="`Venta #${transaction.folio}`" />
    <AppLayout>
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
                    <template #title>Detalles de los Conceptos</template>
                    <template #content>
                        <DataTable :value="transaction.items" class="p-datatable-sm">
                            <Column field="description" header="Descripción"></Column>
                            <Column field="quantity" header="Cantidad" class="text-center"></Column>
                            <Column header="Precio Unitario">
                                <template #body="{ data }">
                                    <div>
                                        <del v-if="parseFloat(data.discount_amount) > 0" class="text-gray-500 text-xs">
                                            {{ formatCurrency(parseFloat(data.unit_price) + parseFloat(data.discount_amount)) }}
                                        </del>
                                        <p class="font-semibold m-0">
                                            {{ formatCurrency(data.unit_price) }}
                                        </p>
                                        <p v-if="parseFloat(data.discount_amount) > 0" class="text-xs text-green-600 m-0">
                                            Ahorro: {{ formatCurrency(data.discount_amount) }}
                                        </p>
                                    </div>
                                </template>
                            </Column>
                            <Column field="line_total" header="Total" class="text-right">
                                <template #body="{ data }">
                                    {{ formatCurrency(data.line_total) }}
                                </template>
                            </Column>
                        </DataTable>
                    </template>
                </Card>
            </div>
            <!-- Columna Derecha -->
            <div class="lg:col-span-1 space-y-6">
                <Card>
                    <template #title>Resumen Financiero</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Subtotal:</span><span>{{
                                formatCurrency(transaction.subtotal) }}</span></li>
                            <li class="flex justify-between"><span>Descuento:</span><span class="text-red-500">- {{
                                formatCurrency(transaction.total_discount) }}</span></li>
                            <li class="flex justify-between font-bold text-base border-t pt-2 mt-2"><span>Total de la
                                    Venta:</span><span>{{ formatCurrency(totalAmount) }}</span></li>
                            <li class="flex justify-between"><span>Total Pagado:</span><span class="font-semibold">{{
                                formatCurrency(totalPaid) }}</span></li>
                            <li v-if="pendingAmount > 0.01" class="flex justify-between font-bold text-red-600">
                                <span>Saldo Pendiente:</span><span>{{ formatCurrency(pendingAmount) }}</span></li>
                        </ul>
                    </template>
                </Card>
                <Card>
                    <template #title>Información de la Venta</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Estatus:</span>
                                <Tag :value="localTransaction.status"
                                    :severity="getStatusSeverity(localTransaction.status)" class="capitalize" />
                            </li>
                            <li class="flex justify-between items-center">
                                <span>Cliente:</span>
                                <span class="font-medium">
                                    <template v-if="transaction.customer">
                                        <Link :href="route('customers.show', transaction.customer.id)" class="text-blue-600 hover:underline flex items-center gap-2">
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
                                transaction.user.name }}</span></li>
                            <li class="flex justify-between"><span>Sucursal:</span><span class="font-medium">{{
                                transaction.branch.name }}</span></li>
                            <li class="flex justify-between"><span>Canal:</span><span class="font-medium capitalize">{{
                                transaction.channel.replace('_', ' ') }}</span></li>
                        </ul>
                    </template>
                </Card>
                <Card>
                    <template #title>Pagos Realizados</template>
                    <template #content>
                        <div v-if="localTransaction.payments.length === 0">
                            <p class="text-center text-gray-500 text-sm py-4">No se han registrado pagos.</p>
                        </div>
                        <ul v-else class="space-y-3">
                            <li v-for="payment in localTransaction.payments" :key="payment.id" class="text-sm">
                                <div class="flex justify-between items-center">
                                    <span class="flex items-center gap-2"><i class="pi"
                                            :class="paymentMethodIcons[payment.payment_method].icon + ' ' + paymentMethodIcons[payment.payment_method].color"></i>
                                        <span class="capitalize font-medium">{{ payment.payment_method }}</span></span>
                                    <span class="font-mono font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                </div>
                                <p class="text-xs text-gray-500 ml-6">{{ formatDate(payment.payment_date) }}</p>
                            </li>
                        </ul>
                    </template>
                </Card>
            </div>
        </div>
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>