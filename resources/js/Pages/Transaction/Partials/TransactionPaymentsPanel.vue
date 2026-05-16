<script setup>
import { usePermissions } from '@/Composables';

defineProps({
    localTransaction: Object,
});

defineEmits(['open-edit-payment-modal', 'confirm-delete-payment']);

const { hasPermission } = usePermissions();

const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);
const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';

const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-green-600 dark:text-green-400', bg: 'bg-green-50 dark:bg-green-900/20 border-green-100 dark:border-green-900/30' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-blue-600 dark:text-blue-400', bg: 'bg-blue-50 dark:bg-blue-900/20 border-blue-100 dark:border-blue-900/30' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-orange-500 dark:text-orange-400', bg: 'bg-orange-50 dark:bg-orange-900/20 border-orange-100 dark:border-orange-900/30' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500 dark:text-purple-400', bg: 'bg-purple-50 dark:bg-purple-900/20 border-purple-100 dark:border-purple-900/30' },
    intercambio: { icon: 'pi pi-sync', color: 'text-cyan-500 dark:text-cyan-400', bg: 'bg-cyan-50 dark:bg-cyan-900/20 border-cyan-100 dark:border-cyan-900/30' }
};

const getMethodKey = (method) => typeof method === 'object' ? method.value : (method || 'efectivo');

// --- TESLA UI PASS-THROUGH (PT) ---
const tagPt = {
    root: { class: '!rounded-full !px-2 !py-0.5 !text-[9px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Pagos realizados</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Historial de abonos a la venta</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                <i class="pi pi-dollar !text-sm text-green-500"></i>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-grow flex flex-col">
            <div v-if="!localTransaction.payments?.length" class="flex flex-col items-center justify-center text-center py-8 opacity-60 flex-grow">
                <i class="pi pi-wallet !text-3xl text-gray-400 mb-3"></i>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin pagos</p>
                <p class="text-xs text-gray-400 mt-1 max-w-[200px]">No se han registrado pagos en esta venta.</p>
            </div>
            
            <ul v-else class="m-0 p-0 list-none space-y-3">
                <li v-for="payment in localTransaction.payments" :key="payment.id" 
                    class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] group transition-colors hover:border-gray-300 dark:hover:border-gray-600">
                    
                    <div class="flex items-start gap-3 w-full sm:w-auto overflow-hidden">
                        <!-- Icono del Método -->
                        <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 border mt-0.5"
                             :class="paymentMethodIcons[getMethodKey(payment.payment_method)]?.bg || 'bg-gray-50 dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a]'">
                            <i class="pi !text-[10px]" :class="(paymentMethodIcons[getMethodKey(payment.payment_method)]?.icon || 'pi-circle') + ' ' + (paymentMethodIcons[getMethodKey(payment.payment_method)]?.color || 'text-gray-500')"></i>
                        </div>
                        
                        <div class="flex flex-col min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-sm font-medium text-gray-900 dark:text-white capitalize leading-tight">
                                    {{ getMethodKey(payment.payment_method) === 'intercambio' ? 'Intercambio' : getMethodKey(payment.payment_method) }}
                                </span>
                                <!-- ETIQUETA DE DEVOLUCIÓN -->
                                <Tag v-if="payment.amount < 0" severity="danger" value="Devolución" :pt="tagPt" />
                            </div>
                            
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest font-bold">{{ formatDate(payment.payment_date || payment.created_at) }}</span>
                            
                            <!-- CUENTA BANCARIA ASOCIADA -->
                            <div v-if="payment.bank_account" class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1 mt-1.5 truncate" v-tooltip.bottom="payment.bank_account.bank_name">
                                <i class="pi pi-building !text-[9px]"></i>
                                <span class="truncate max-w-[140px]">{{ payment.bank_account.account_name }}</span>
                                <span v-if="payment.bank_account.account_number || payment.bank_account.card_number" class="italic flex-shrink-0">
                                    (***{{ (payment.bank_account.account_number || payment.bank_account.card_number).slice(-4) }})
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Monto y Botones -->
                    <div class="flex items-center justify-between w-full sm:w-auto gap-4 pl-11 sm:pl-0 shrink-0">
                        <span class="font-mono font-bold text-lg" :class="payment.amount < 0 ? 'text-red-500' : 'text-gray-900 dark:text-white'">
                            {{ payment.amount < 0 ? '' : '+' }}{{ formatCurrency(payment.amount) }}
                        </span>
                        
                        <div class="flex items-center gap-1 opacity-100 sm:opacity-0 group-hover:opacity-100 transition-opacity">
                            <Button 
                                v-if="hasPermission('transactions.edit_payment') && localTransaction.status !== 'cancelado' && localTransaction.status !== 'reembolsado'"
                                icon="pi pi-pencil" 
                                text rounded
                                class="!w-8 !h-8 !p-0 text-gray-400 hover:text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20" 
                                v-tooltip.top="'Editar pago'"
                                @click="$emit('open-edit-payment-modal', payment)" 
                            />
                            <Button 
                                v-if="hasPermission('transactions.edit_payment') && localTransaction.status !== 'cancelado' && localTransaction.status !== 'reembolsado'"
                                icon="pi pi-trash" 
                                text rounded
                                class="!w-8 !h-8 !p-0 text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" 
                                v-tooltip.top="'Eliminar pago'"
                                @click="$emit('confirm-delete-payment', payment)" 
                            />
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>