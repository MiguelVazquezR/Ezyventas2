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
    efectivo: { icon: 'pi pi-money-bill', color: 'text-[#37672B]' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-[#063C53]' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-[#D2D880]' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' },
    intercambio: { icon: 'pi pi-sync', color: 'text-orange-500' }
};

const getMethodKey = (method) => typeof method === 'object' ? method.value : (method || 'efectivo');
</script>

<template>
    <Card>
        <template #title>Pagos realizados</template>
        <template #content>
            <div v-if="!localTransaction.payments?.length">
                <p class="text-center text-gray-500 text-sm py-4">No se han registrado pagos.</p>
            </div>
            <ul v-else class="space-y-4">
                <li v-for="payment in localTransaction.payments" :key="payment.id" class="text-sm">
                    <div class="flex justify-between items-center">
                        
                        <!-- Info de Método y Banco -->
                        <div class="flex flex-col">
                            <span class="flex items-center gap-2">
                                <i class="pi" :class="(paymentMethodIcons[getMethodKey(payment.payment_method)]?.icon || 'pi-circle') + ' ' + (paymentMethodIcons[getMethodKey(payment.payment_method)]?.color || 'text-gray-500')"></i>
                                <span class="capitalize font-medium">
                                    {{ getMethodKey(payment.payment_method) === 'intercambio' ? 'Intercambio' : getMethodKey(payment.payment_method) }}
                                </span>
                                <!-- ETIQUETA DE DEVOLUCIÓN -->
                                <Tag v-if="payment.amount < 0" severity="danger" value="Devolución" class="!text-[10px] !px-1.5 !py-0.5 ml-1" />
                            </span>
                            
                            <!-- CUENTA BANCARIA ASOCIADA -->
                            <div v-if="payment.bank_account" class="text-xs text-gray-500 flex items-center gap-1 mt-1 ml-6">
                                <i class="pi pi-building text-[10px]"></i>
                                <span>{{ payment.bank_account.bank_name }} - {{ payment.bank_account.account_name }}</span>
                                <span v-if="payment.bank_account.account_number || payment.bank_account.card_number" class="text-[10px] italic">
                                    (***{{ (payment.bank_account.account_number || payment.bank_account.card_number).slice(-4) }})
                                </span>
                            </div>
                        </div>

                        <!-- Acciones y Monto -->
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-semibold" :class="{'text-red-600': payment.amount < 0}">
                                {{ formatCurrency(payment.amount) }}
                            </span>
                            <div class="flex items-center">
                                <Button 
                                    v-if="hasPermission('transactions.edit_payment') && localTransaction.status !== 'cancelado' && localTransaction.status !== 'reembolsado'"
                                    icon="pi pi-pencil" 
                                    class="p-button-text p-button-sm p-button-rounded" 
                                    v-tooltip.top="'Editar pago'"
                                    @click="$emit('open-edit-payment-modal', payment)" 
                                />
                                <Button 
                                    v-if="hasPermission('transactions.edit_payment') && localTransaction.status !== 'cancelado' && localTransaction.status !== 'reembolsado'"
                                    icon="pi pi-trash" 
                                    severity="danger"
                                    class="p-button-text p-button-sm p-button-rounded" 
                                    v-tooltip.top="'Eliminar pago'"
                                    @click="$emit('confirm-delete-payment', payment)" 
                                />
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 ml-6 mt-1">{{ formatDate(payment.payment_date || payment.created_at) }}</p>
                </li>
            </ul>
        </template>
    </Card>
</template>