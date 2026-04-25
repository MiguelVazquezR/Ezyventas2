<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    serviceOrder: Object,
    totalPaid: Number,
    amountDue: Number,
    technicianCommissionCostNumeric: Number,
});

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });
};

const partsCost = computed(() => {
    if (!props.serviceOrder.items || props.serviceOrder.items.length === 0) return 0;
    
    return props.serviceOrder.items.reduce((total, item) => {
        if (item.itemable_type === 'App\\Models\\Product') {
            const cost = parseFloat(item.unit_price) || 0;
            const quantity = parseFloat(item.quantity) || 0;
            return total + (cost * quantity);
        }
        return total;
    }, 0);
});

const profitAnalysis = computed(() => {
    const subtotal = parseFloat(props.serviceOrder.subtotal) || 0;
    const discount = parseFloat(props.serviceOrder.discount_amount) || 0;
    const netRevenue = parseFloat(props.serviceOrder.final_total) || 0;
    const commission = props.technicianCommissionCostNumeric;
    const parts = partsCost.value;

    const totalCosts = commission + parts;
    const netProfit = netRevenue - totalCosts;
    const margin = netRevenue > 0 ? (netProfit / netRevenue) * 100 : 0;

    return {
        subtotal,
        discount,
        netRevenue,
        commission,
        parts,
        totalCosts,
        netProfit,
        margin,
    };
});

const getPaymentMethodIcon = (method) => {
    const icons = {
        efectivo: 'pi pi-money-bill',
        tarjeta: 'pi pi-credit-card',
        transferencia: 'pi pi-arrows-h',
    };
    return icons[method] || 'pi pi-question-circle';
};
</script>

<template>
    <div class="space-y-6">
        <!-- Estado de cuenta -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Estado de cuenta</h2>
            <ul class="space-y-2 text-sm">
                <li class="flex justify-between">
                    <span>Subtotal:</span>
                    <span class="font-semibold">{{ formatCurrency(serviceOrder.subtotal) }}</span>
                </li>
                <li v-if="serviceOrder.discount_amount > 0" class="flex justify-between">
                    <span>Descuento:</span>
                    <span class="font-semibold text-red-500">(-) {{ formatCurrency(serviceOrder.discount_amount) }}</span>
                </li>
                <li class="flex justify-between font-bold">
                    <span>Total de la orden:</span>
                    <span>{{ formatCurrency(serviceOrder.final_total) }}</span>
                </li>
                <li class="flex justify-between">
                    <span>Total pagado:</span>
                    <span class="font-semibold text-green-600">{{ formatCurrency(totalPaid) }}</span>
                </li>
                <li class="flex justify-between text-base font-bold border-t pt-2 mt-2"
                    :class="amountDue > 0.01 ? 'text-red-500' : 'text-gray-800 dark:text-gray-200'">
                    <span>Saldo pendiente:</span>
                    <span>{{ formatCurrency(amountDue) }}</span>
                </li>
            </ul>
        </div>

        <!-- Análisis de ganancia -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Análisis de ganancia</h2>
            <ul class="space-y-2 text-sm">
                <li class="flex justify-between">
                    <span>Ingresos (Subtotal):</span>
                    <span class="font-semibold">{{ formatCurrency(profitAnalysis.subtotal) }}</span>
                </li>
                <li v-if="profitAnalysis.discount > 0" class="flex justify-between">
                    <span>Descuento aplicado:</span>
                    <span class="text-red-500">(-) {{ formatCurrency(profitAnalysis.discount) }}</span>
                </li>
                <li class="flex justify-between font-semibold border-t pt-2 mt-2">
                    <span>Ingresos netos:</span>
                    <span>{{ formatCurrency(profitAnalysis.netRevenue) }}</span>
                </li>
                <li class="flex justify-between mt-4">
                    <span>Costo de refacciones:</span>
                    <span class="text-red-500">(-) {{ formatCurrency(profitAnalysis.parts) }}</span>
                </li>
                <li class="flex justify-between">
                    <span>Comisión del técnico:</span>
                    <span class="text-red-500">(-) {{ formatCurrency(profitAnalysis.commission) }}</span>
                </li>
                <li class="flex justify-between font-medium border-t pt-2 mt-2">
                    <span>Costos totales:</span>
                    <span>{{ formatCurrency(profitAnalysis.totalCosts) }}</span>
                </li>
                <li class="flex justify-between text-base font-bold text-green-600 border-t pt-2 mt-2">
                    <span>Ganancia neta:</span>
                    <span>{{ formatCurrency(profitAnalysis.netProfit) }}</span>
                </li>
                <li class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mt-2">
                    <span>Margen de ganancia:</span>
                    <span>{{ profitAnalysis.margin.toFixed(2) }}%</span>
                </li>
            </ul>
        </div>

        <!-- Pagos registrados -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Pagos registrados</h2>
            
            <p v-if="serviceOrder.transaction && serviceOrder.transaction.payments?.length > 0" class="text-sm text-gray-600 dark:text-gray-400 mb-4 bg-blue-50 dark:bg-blue-900/20 p-3 rounded-md border border-blue-100 dark:border-blue-800">
                <i class="pi pi-info-circle mr-2 text-blue-500"></i>
                Si quieres editar o eliminar un pago dirígete a la transacción
                <Link :href="route('transactions.show', serviceOrder.transaction.id)" class="text-blue-600 dark:text-blue-400 hover:underline font-semibold ml-1">
                    {{ serviceOrder.transaction.folio }}
                </Link>
            </p>

            <DataTable :value="serviceOrder.transaction?.payments" class="p-datatable-sm" responsiveLayout="scroll">
                <Column field="payment_date" header="Fecha">
                    <template #body="{ data }">{{ formatDate(data.payment_date) }}</template>
                </Column>
                <Column field="payment_method" header="Método" style="width: 10rem">
                    <template #body="{ data }">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2">
                                <i :class="getPaymentMethodIcon(data.payment_method)" class="text-gray-500"></i>
                                <span class="capitalize font-medium">{{ data.payment_method }}</span>
                            </div>
                            <small v-if="data.bank_account" class="text-gray-500 dark:text-gray-400 mt-1 pl-1 truncate"
                                v-tooltip.bottom="data.bank_account.account_name">
                                {{ data.bank_account.account_name }}
                            </small>
                        </div>
                    </template>
                </Column>
                <Column field="amount" header="Monto" style="width: 8rem" class="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.amount) }}</template>
                </Column>
                <template #empty>
                    <div class="text-center text-gray-500 py-4">No se han registrado pagos.</div>
                </template>
            </DataTable>
        </div>
    </div>
</template>