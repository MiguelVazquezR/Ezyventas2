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
    <Card>
        <template #title>Resumen financiero</template>
        <template #content>
            <ul class="space-y-3 text-sm">
                <li class="flex justify-between">
                    <span>Subtotal:</span>
                    <span>{{ formatCurrency(transaction.subtotal) }}</span>
                </li>
                
                <li v-if="parseFloat(transaction.shipping_cost) > 0" class="flex justify-between">
                    <span>Envío:</span>
                    <span class="font-medium text-blue-600">{{ formatCurrency(transaction.shipping_cost) }}</span>
                </li>

                <li v-if="parseFloat(transaction.total_discount) > 0" class="flex justify-between">
                    <span>Descuento:</span>
                    <span class="text-green-500">- {{ formatCurrency(transaction.total_discount) }}</span>
                </li>
                
                <li class="flex justify-between font-bold text-base border-t pt-2 mt-2">
                    <span>Total:</span>
                    <span>{{ formatCurrency(totalAmount) }}</span>
                </li>
                
                <li class="flex justify-between">
                    <span>Pagado:</span>
                    <span class="font-semibold">{{ formatCurrency(totalPaid) }}</span>
                </li>
                
                <li v-if="pendingAmount > 0" class="flex justify-between font-bold text-red-600 text-lg bg-red-50 dark:bg-red-900/20 p-2 rounded">
                    <span>Pendiente:</span>
                    <span>{{ formatCurrency(pendingAmount) }}</span>
                </li>
            </ul>
            <div v-if="canAddPayment && hasPermission('transactions.add_payment')" class="mt-4">
                <Button label="Abonar a esta cuenta" icon="pi pi-dollar" class="w-full p-button-success" @click="$emit('open-payment-modal')" />
            </div>
        </template>
    </Card>
</template>