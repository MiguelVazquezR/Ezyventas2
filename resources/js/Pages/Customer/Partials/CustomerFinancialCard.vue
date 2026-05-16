<script setup>
import { computed } from 'vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true
    }
});

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const getBalanceClass = (balance) => {
    if (balance > 0) return 'text-green-600 dark:text-green-400';
    if (balance < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-900 dark:text-white';
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        <h2 class="text-xs font-bold text-cyan-800 dark:text-cyan-500 tracking-widest uppercase m-0 mb-6 flex items-center gap-2">
            <i class="pi pi-wallet !text-[10px]"></i> Estado financiero
        </h2>
        
        <ul class="m-0 p-0 list-none space-y-4">
            <li class="flex justify-between items-end border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Saldo actual</span>
                <span :class="getBalanceClass(customer.balance)" class="font-light tracking-tight text-4xl leading-none m-0">
                    {{ formatCurrency(customer.balance) }}
                </span>
            </li>
            
            <li class="flex justify-between items-end pt-2">
                <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0">Crédito disponible</span>
                <span class="font-mono font-medium text-lg text-blue-600 dark:text-blue-400 m-0">
                    {{ formatCurrency(customer.available_credit) }}
                </span>
            </li>
            
            <li class="flex justify-between items-end pt-2">
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Límite otorgado</span>
                <span class="font-mono text-sm text-gray-700 dark:text-gray-300 m-0">
                    {{ formatCurrency(customer.credit_limit) }}
                </span>
            </li>
        </ul>
    </div>
</template>