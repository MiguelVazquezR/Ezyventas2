<script setup>
import { usePermissions } from '@/Composables';

defineProps({
    transaction: Object,
    totalAmount: Number,
    totalPaid: Number,
    pendingAmount: Number,
    canAddPayment: Boolean,
});

defineEmits(['open-payment-modal']);

const { hasPermission } = usePermissions();

const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Resumen financiero</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Balance de la venta</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                <i class="pi pi-wallet !text-sm text-emerald-500"></i>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-grow flex flex-col">
            
            <!-- Caja de Desglose -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 lg:p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Subtotal</span>
                    <span class="font-mono text-sm text-gray-900 dark:text-gray-300 m-0">{{ formatCurrency(transaction.subtotal) }}</span>
                </div>
                
                <div v-if="parseFloat(transaction.shipping_cost) > 0" class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Envío</span>
                    <span class="font-mono text-sm text-blue-600 dark:text-blue-400 m-0">{{ formatCurrency(transaction.shipping_cost) }}</span>
                </div>

                <div v-if="parseFloat(transaction.total_discount) > 0" class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descuento</span>
                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(transaction.total_discount) }}</span>
                </div>
                
                <div class="border-t border-gray-200 dark:border-[#2a2a2a] my-1"></div>
                
                <div class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-900 dark:text-gray-100 m-0">Total</span>
                    <span class="font-mono text-lg font-bold text-gray-900 dark:text-white m-0">{{ formatCurrency(totalAmount) }}</span>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Abonado</span>
                    <span class="font-mono text-sm text-green-600 dark:text-green-500 font-medium m-0">{{ formatCurrency(totalPaid) }}</span>
                </div>
            </div>

            <!-- Indicador Dinámico de Deuda / Liquidado -->
            <div v-if="pendingAmount > 0" class="mt-4 bg-red-50 dark:bg-red-900/10 p-4 lg:p-5 rounded-2xl border border-red-100 dark:border-red-900/30 flex justify-between items-center">
                <span class="text-[10px] uppercase tracking-widest font-bold text-red-800 dark:text-red-400 m-0">Resta por pagar</span>
                <span class="font-light tracking-tight text-3xl leading-none text-red-600 dark:text-red-500 m-0">{{ formatCurrency(pendingAmount) }}</span>
            </div>
            <div v-else class="mt-4 bg-green-50 dark:bg-green-900/10 p-4 rounded-2xl border border-green-100 dark:border-green-900/30 flex justify-between items-center">
                <span class="text-[10px] uppercase tracking-widest font-bold text-green-800 dark:text-green-400 m-0 flex items-center gap-1.5"><i class="pi pi-check-circle !text-[10px]"></i> Liquidada</span>
                <span class="font-mono text-lg font-bold text-green-600 dark:text-green-500 m-0">{{ formatCurrency(0) }}</span>
            </div>

            <!-- Botón de Acción -->
            <div v-if="canAddPayment && hasPermission('transactions.add_payment')" class="mt-6 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button 
                    label="Abonar a esta cuenta" 
                    icon="pi pi-dollar" 
                    severity="success"
                    class="!w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold !py-3 shadow-[0_4px_14px_rgba(34,197,94,0.4)]" 
                    @click="$emit('open-payment-modal')" 
                />
            </div>
        </div>
    </div>
</template>