<script setup>
defineProps({
    paymentMethods: Array,
    client: Object,
    isCreditSale: Boolean,
});

const emit = defineEmits(['select']);
</script>

<template>
    <div class="min-h-[350px] flex flex-col justify-center">
        <h3 class="text-lg lg:text-xl font-semibold text-center mb-6">Opciones de pago</h3>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
            <button v-for="method in paymentMethods" :key="method.id" @click="emit('select', method.id)"
                class="flex flex-col items-center justify-center p-3 border rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                v-tooltip.bottom="method.id === 'credito' && client && !isCreditSale ? 'El cliente no tiene suficiente crédito' : ''">
                <img :src="method.icon" :alt="method.label" class="size-10 lg:size-16 object-contain mb-2">
                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ method.label }}</span>
            </button>
        </div>
    </div>
</template>