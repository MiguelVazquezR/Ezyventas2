<script setup>
import { usePage } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    carts: Array,
});

const confirm = useConfirm();

const emit = defineEmits(['resumeCart', 'deleteCart']);

const currentUser = usePage().props.auth.user;

const confirmRemoveItem = (event, cartId) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este elemento?',
        group: 'pendent-carts-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí',
        rejectLabel: 'No',
        accept: () => {
           emit('deleteCart', cartId)
        }
    });
};
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
                 <Button @click="confirmRemoveItem($event, cart.id)" icon="pi pi-trash" rounded text severity="danger" class="!absolute top-2 right-2"/>
                 <div>
                     <p class="text-xs text-gray-500 m-0">Cliente</p>
                     <p class="font-bold m-0">{{ cart.client.name }}</p>
                 </div>
                 <div class="flex justify-between items-end">
                     <div>
                         <p class="text-xs text-gray-500 m-0">Vendedor</p>
                         <p class="text-sm font-semibold m-0">{{ currentUser.name }}</p>
                         <p class="text-xs text-gray-400 mt-1">{{ cart.time }}</p>
                     </div>
                     <div class="text-right">
                         <p class="text-xs text-gray-500 m-0">Total</p>
                         <p class="font-bold text-lg m-0">${{ cart.total.toFixed(2) }}</p>
                     </div>
                 </div>
                 <Button @click="$emit('resumeCart', cart.id)" label="Reanudar" class="w-full bg-orange-500 hover:bg-orange-600 border-none mt-2"/>
            </div>
        </div>
    </div>
    <ConfirmPopup group="pendent-carts-delete" />
</template>