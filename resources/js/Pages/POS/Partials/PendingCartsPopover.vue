<script setup>
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    carts: Array,
});

const emit = defineEmits(['resumeCart', 'deleteCart']);

const currentUser = usePage().props.auth.user;
</script>
<template>
    <div class="w-80 md:w-96 p-4">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-bold text-lg">Carritos en espera</h3>
        </div>
        <div v-if="carts.length === 0" class="text-center text-gray-500 py-8">
            No hay carritos guardados.
        </div>
        <div v-else class="space-y-3 max-h-96 overflow-y-auto">
            <div v-for="cart in carts" :key="cart.id" class="border rounded-lg p-4 flex flex-col gap-3 relative">
                 <Button @click="$emit('deleteCart', cart.id)" icon="pi pi-trash" rounded text severity="danger" class="!absolute top-2 right-2"/>
                 <div>
                     <p class="text-xs text-gray-500">Cliente</p>
                     <p class="font-bold">{{ cart.client.name }}</p>
                 </div>
                 <div class="flex justify-between items-end">
                     <div>
                         <p class="text-xs text-gray-500">Vendedor</p>
                         <p class="text-sm font-semibold">{{ currentUser.name }}</p>
                         <p class="text-xs text-gray-400 mt-1">{{ cart.time }}</p>
                     </div>
                     <div class="text-right">
                         <p class="text-xs text-gray-500">Total</p>
                         <p class="font-bold text-lg">${{ cart.total.toFixed(2) }}</p>
                     </div>
                 </div>
                 <Button @click="$emit('resumeCart', cart.id)" label="Reanudar" class="w-full bg-orange-500 hover:bg-orange-600 border-none mt-2"/>
            </div>
        </div>
    </div>
</template>