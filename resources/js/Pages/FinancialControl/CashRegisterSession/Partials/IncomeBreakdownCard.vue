<script setup>
import { computed } from 'vue';

const props = defineProps({
    sessionTotals: {
        type: Object,
        required: true
    }
});

const paymentMethodDetails = {
    efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'text-green-600' },
    tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'text-blue-600' },
    transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'text-orange-500' },
    saldo: { name: 'Saldo de cliente', icon: 'pi pi-wallet', color: 'text-purple-500' },
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

// --- Lógica para filtrar ingresos (Excluir 'saldo') ---
const filteredIncomeTotals = computed(() => {
    if (!props.sessionTotals) return {};
    const { saldo, ...incomeTotals } = props.sessionTotals;
    return incomeTotals;
});

const totalAllIncome = computed(() => {
    return Object.values(filteredIncomeTotals.value).reduce((sum, total) => sum + parseFloat(total), 0);
});
</script>

<template>
    <Card>
        <template #title>Desglose de Ingresos Reales</template>
        <template #content>
            <ul v-if="Object.keys(filteredIncomeTotals).length > 0" class="space-y-3 text-sm">
                 <li v-for="(total, method) in filteredIncomeTotals" :key="method" class="flex justify-between items-center">
                     <span><i class="pi mr-2" :class="paymentMethodDetails[method]?.icon + ' ' + paymentMethodDetails[method]?.color"></i>{{ paymentMethodDetails[method]?.name || method }}</span>
                     <span class="font-mono font-semibold">{{ formatCurrency(total) }}</span>
                </li>
                <li class="flex justify-between items-center border-t border-gray-200 dark:border-gray-700 pt-3 mt-3 font-bold">
                     <span><i class="pi pi-chart-bar mr-2"></i>Total de Ingresos</span>
                     <span class="font-mono text-primary-600 dark:text-primary-400">{{ formatCurrency(totalAllIncome) }}</span>
                </li>
            </ul>
             <p v-else class="text-sm text-gray-500 text-center">No se registraron ingresos en esta sesión.</p>
        </template>
    </Card>
</template>