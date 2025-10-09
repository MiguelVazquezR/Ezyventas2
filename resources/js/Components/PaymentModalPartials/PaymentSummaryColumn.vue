<script setup>
import { computed } from 'vue';

const props = defineProps({
    paymentMode: String,
    totalAmount: Number,
    amountToPay: Number,
    client: Object,
});

const formatCurrency = (value) => {
    // if (typeof value != 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const displayAmount = computed(() => {
    if (props.paymentMode === 'balance') {
        // CORRECCIÓN: Si el cliente tiene un saldo negativo, muéstralo.
        // Si no, muestra el monto que se está ingresando para abonar.
        if (props.client && props.client.balance < 0) {
            return props.client.balance;
        }
        return props.amountToPay || 0;
    }
    return props.totalAmount;
});

const title = computed(() => {
    if (props.paymentMode === 'balance') {
        // CORRECCIÓN: Cambia el título si se muestra un saldo deudor.
        if (props.client && props.client.balance < 0) {
            return 'SALDO DEUDOR';
        }
        return 'ABONAR A SALDO';
    }
    return 'SALDO PENDIENTE';
});
</script>

<template>
    <div class="col-span-1 bg-gray-50 dark:bg-gray-800 p-3 lg:p-6 flex flex-col justify-center items-center rounded-l-lg min-h-[300px] lg:min-h-[500px]">
        <p class="lg:text-lg font-semibold text-gray-600 dark:text-gray-300">{{ title }}</p>
        <p class="text-3xl lg:text-6xl font-bold text-gray-800 dark:text-gray-100 mt-4 break-all">
            {{ formatCurrency(displayAmount) }}
        </p>

        <div v-if="client" class="mt-8 w-full border-t border-gray-200 dark:border-gray-700 pt-4 text-sm">
            <p class="font-semibold text-center text-gray-800 dark:text-gray-200 mb-2">{{ client.name }}</p>
            <div class="flex justify-between">
                <span class="text-gray-500 dark:text-gray-400">Saldo Actual:</span>
                <span class="font-mono font-semibold" :class="client.balance >= 0 ? 'text-green-500' : 'text-red-500'">
                    {{ formatCurrency(client.balance) }}
                </span>
            </div>
            <div class="flex justify-between mt-1">
                <span class="text-gray-500 dark:text-gray-400">Crédito Disponible:</span>
                <span class="font-mono font-semibold text-blue-500">
                    {{ formatCurrency(client.available_credit) }}
                </span>
            </div>
        </div>
    </div>
</template>