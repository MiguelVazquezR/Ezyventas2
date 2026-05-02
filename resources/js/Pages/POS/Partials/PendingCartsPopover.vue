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
        message: '¿Estás seguro de que quieres eliminar este carrito guardado?',
        group: 'pendent-carts-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
           emit('deleteCart', cartId)
        }
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};
</script>

<template>
    <div class="w-80 md:w-96 p-4">
        <!-- HEADER -->
        <h3 class="font-medium text-sm mb-4 border-b border-gray-100 dark:border-[#3a3a3a] pb-2 m-0 text-gray-900 dark:text-white tracking-tight">
            Carritos en espera
        </h3>

        <!-- EMPTY STATE -->
        <div v-if="carts.length === 0" class="flex flex-col items-center justify-center py-6 opacity-60">
            <i class="pi pi-inbox text-3xl text-gray-400 mb-3"></i>
            <span class="text-xs text-gray-500 m-0">No hay carritos guardados.</span>
        </div>

        <!-- LISTA DE CARRITOS -->
        <div v-else class="space-y-3 max-h-80 overflow-y-auto custom-scrollbar pr-1">
            <div v-for="cart in carts" :key="cart.id" class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 flex flex-col relative transition-colors hover:border-primary-500/30 group">
                 
                 <!-- Botón Eliminar -->
                 <button @click="confirmRemoveItem($event, cart.id)" class="absolute top-2 right-2 w-7 h-7 rounded-full bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-200 dark:hover:border-red-900/50 transition-all flex items-center justify-center shadow-sm">
                    <i class="pi pi-times !text-[10px]"></i>
                 </button>

                 <!-- Información de Cliente -->
                 <div class="mb-3 pr-6">
                     <p class="text-[9px] uppercase tracking-widest text-gray-400 m-0 mb-0.5">Cliente asignado</p>
                     <p class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0 truncate">{{ cart.client.name }}</p>
                 </div>

                 <!-- Operador y Total -->
                 <div class="flex justify-between items-end border-t border-gray-200 dark:border-[#2a2a2a] pt-3 mb-4">
                     <div>
                         <p class="text-[9px] uppercase tracking-widest text-gray-400 m-0 mb-0.5">Operador</p>
                         <p class="text-xs font-medium text-gray-700 dark:text-gray-300 m-0">{{ currentUser.name }}</p>
                         <p class="text-[9px] text-gray-500 font-mono mt-1 m-0 flex items-center gap-1">
                            <i class="pi pi-clock !text-[8px]"></i> {{ cart.time }}
                         </p>
                     </div>
                     <div class="text-right">
                         <p class="text-[9px] uppercase tracking-widest text-gray-400 m-0 mb-0.5">Total</p>
                         <p class="font-light tracking-tight text-xl text-gray-900 dark:text-white m-0 leading-none">
                            {{ formatCurrency(cart.total) }}
                         </p>
                     </div>
                 </div>

                 <!-- Botón de Reanudar -->
                 <Button @click="$emit('resumeCart', cart.id)" 
                    label="Reanudar" 
                    icon="pi pi-play" 
                    class="w-full !rounded-xl !text-[11px] !uppercase !tracking-widest !font-bold !py-2.5 !bg-primary-500 hover:!bg-primary-400 !border-none text-white shadow-[0_4px_10px_rgba(246,140,15,0.3)]" 
                />
            </div>
        </div>
    </div>
    
    <!-- Modales Globales del Componente -->
    <ConfirmPopup group="pendent-carts-delete" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl' } }" />
</template>