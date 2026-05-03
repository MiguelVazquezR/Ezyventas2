<script setup>
import { computed } from 'vue';

const props = defineProps({
    sessionTotals: {
        type: Object,
        required: true
    }
});

const paymentMethodDetails = {
    efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'text-green-600 dark:text-green-400' },
    tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'text-blue-600 dark:text-blue-400' },
    transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'text-orange-500 dark:text-orange-400' },
    saldo: { name: 'Saldo de cliente', icon: 'pi pi-wallet', color: 'text-purple-500 dark:text-purple-400' },
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
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Desglose de ingresos reales</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Agrupados por método de pago</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                <i class="pi pi-chart-pie !text-sm text-green-500"></i>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-grow flex flex-col">
            <ul v-if="Object.keys(filteredIncomeTotals).length > 0" class="m-0 p-0 list-none space-y-4">
                <li v-for="(total, method) in filteredIncomeTotals" :key="method" class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4 last:border-0 last:pb-0">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-200 dark:border-[#3a3a3a]">
                            <i class="pi !text-[10px]" :class="paymentMethodDetails[method]?.icon + ' ' + paymentMethodDetails[method]?.color"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white m-0 capitalize">{{ paymentMethodDetails[method]?.name || method }}</span>
                    </div>
                    <span class="font-mono text-base text-gray-900 dark:text-white m-0">{{ formatCurrency(total) }}</span>
                </li>
            </ul>
            
            <div v-else class="flex flex-col items-center justify-center text-center py-8 opacity-60 flex-grow">
                <i class="pi pi-chart-pie !text-3xl text-gray-400 mb-3"></i>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin ingresos</p>
                <p class="text-xs text-gray-400 mt-1">No se registraron ingresos en esta sesión.</p>
            </div>
            
            <!-- Resumen Total (Footer interno) -->
            <div v-if="Object.keys(filteredIncomeTotals).length > 0" class="mt-6 pt-6 border-t border-gray-200 dark:border-[#3a3a3a] flex items-end justify-between">
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1">
                    <i class="pi pi-plus-circle !text-[10px]"></i> Total global
                </span>
                <span class="font-light tracking-tight text-3xl leading-none text-green-600 dark:text-green-500 m-0">
                    {{ formatCurrency(totalAllIncome) }}
                </span>
            </div>
        </div>
    </div>
</template>