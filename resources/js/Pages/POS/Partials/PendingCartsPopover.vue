<script setup>
const props = defineProps({
    carts: Array,
});

const emit = defineEmits(['resumeCart', 'deleteCart']);

const getClientName = (client) => {
    return client ? client.name : 'Público en General';
}

</script>
<template>
    <div class="w-80 md:w-96 p-4 bg-white dark:bg-gray-800 rounded-lg shadow-xl">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-bold text-lg text-gray-800 dark:text-gray-200">Carritos en espera</h3>
        </div>
        <div class="space-y-3 max-h-96 overflow-y-auto">
            <p v-if="carts.length === 0" class="text-center text-gray-500 dark:text-gray-400 py-4">No hay carritos guardados.</p>
            <div v-for="cart in carts" :key="cart.id" class="border dark:border-gray-700 rounded-lg p-4 flex flex-col gap-3 relative transition-all hover:border-orange-500">
                 <Button @click="$emit('deleteCart', cart.id)" icon="pi pi-trash" rounded text severity="danger" class="!absolute top-2 right-2"/>
                 <div>
                     <p class="text-xs text-gray-500 dark:text-gray-400">Cliente</p>
                     <p class="font-bold text-gray-800 dark:text-gray-200">{{ getClientName(cart.client) }}</p>
                 </div>
                 <div class="flex justify-between items-end">
                     <div>
                         <p class="text-xs text-gray-500 dark:text-gray-400">Vendedor</p>
                         <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ cart.vendor }}</p>
                         <p class="text-xs text-gray-400 mt-1">{{ cart.time }}</p>
                     </div>
                     <div class="text-right">
                         <p class="text-xs text-gray-500 dark:text-gray-400">Total</p>
                         <p class="font-bold text-lg text-orange-500">${{ cart.total.toFixed(2) }}</p>
                     </div>
                 </div>
                 <Button @click="$emit('resumeCart', cart.id)" label="Reanudar" class="w-full bg-orange-500 hover:bg-orange-600 border-none mt-2"/>
            </div>
        </div>
    </div>
</template>