<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePermissions } from '@/Composables';

const props = defineProps({
    visible: Boolean,
    transaction: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['update:visible']);

const { hasPermission } = usePermissions();

// --- Computada para el v-model bidireccional ---
const isVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value)
});

// --- Formateadores ---
const formatCurrency = (value) => {
     if (value === null || value === undefined) return '';
     const numberValue = Number(value);
     if (isNaN(numberValue)) return '';
     return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(numberValue);
};

const formatFriendlyDate = (dateString) => {
    if (!dateString) return '';
    try {
        const d = new Date(dateString);
        const day = d.getDate();
        const month = new Intl.DateTimeFormat('es-MX', { month: 'long' }).format(d);
        let hour = d.getHours();
        const minute = d.getMinutes().toString().padStart(2, '0');
        const ampm = hour >= 12 ? 'pm' : 'am';
        hour = hour % 12;
        hour = hour ? hour : 12;
        return `${day} de ${month}, ${hour}:${minute}${ampm}`;
    } catch (e) {
        return dateString;
    }
};

const getStatusSeverity = (status) => {
    const map = { 
        completado: 'success', 
        pendiente: 'warn', 
        cancelado: 'danger', 
        reembolsado: 'info', 
        apartado: 'warn',
        por_entregar: 'info',
        en_ruta: 'info',
        entregado_por_pagar: 'warn'
    };
    return map[status] || 'secondary';
};

const formatStatusLabel = (status) => {
    if (!status) return '';
    const text = status.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
};

const getMethodKey = (method) => typeof method === 'object' ? method.value : (method || 'efectivo');

// --- Helpers Matemáticos ---
const getTransactionTotalPaid = (txn) => {
    return (Array.isArray(txn?.payments) ? txn.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
};

const getTransactionPending = (txn) => {
    if (!txn) return 0;
    const total = parseFloat(txn.total || 0);
    const paid = getTransactionTotalPaid(txn);
    return Math.max(0, total - paid);
};
</script>

<template>
    <Drawer v-model:visible="isVisible" position="right" class="!w-full md:!w-[400px]">
        <template #header>
            <div class="flex items-center gap-2">
                <span class="font-bold text-lg">Resumen Venta #{{ transaction?.folio }}</span>
            </div>
        </template>
        
        <div v-if="transaction" class="flex flex-col gap-6 overflow-y-auto h-full pr-2 pb-4">
            <!-- Información General -->
            <div class="flex flex-col gap-2">
                <div class="flex justify-between items-center">
                    <span class="text-gray-500 text-sm">Estatus</span>
                    <Tag :value="formatStatusLabel(transaction.status)" :severity="getStatusSeverity(transaction.status)" />
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-500 text-sm">Fecha</span>
                    <span class="font-medium text-sm">{{ formatFriendlyDate(transaction.created_at) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-500 text-sm">Cliente</span>
                    <div class="flex flex-col items-end">
                        <span v-if="transaction.customer" class="font-medium text-sm text-right truncate max-w-[200px]" :title="transaction.customer.name">
                            {{ transaction.customer.name }}
                        </span>
                        <span v-else-if="transaction.contact_info && transaction.contact_info.name" class="font-medium text-sm text-right truncate max-w-[200px]" :title="transaction.contact_info.name">
                            {{ transaction.contact_info.name }} <Tag severity="info" value="Comanda" class="!text-[10px] !px-1 !py-0 ml-1"></Tag>
                        </span>
                        <span v-else class="font-medium text-sm text-right truncate max-w-[200px]" title="Público en general">
                            Público en general
                        </span>
                    </div>
                </div>
            </div>

            <Divider class="!my-0" />

            <!-- Lista de Artículos -->
            <div class="flex flex-col gap-2">
                <span class="text-gray-500 text-sm font-bold uppercase tracking-wider">Artículos</span>
                <ul v-if="transaction.items && transaction.items.length" class="flex flex-col gap-3">
                    <li v-for="item in transaction.items" :key="item.id" class="flex justify-between text-sm">
                        <div class="flex flex-col flex-1">
                            <span class="font-medium leading-tight">
                                <span class="text-gray-500 mr-1">{{ Math.round(item.quantity) }}x</span>
                                {{ item.description }}
                            </span>
                        </div>
                        <span class="font-semibold ml-2">{{ formatCurrency(item.line_total) }}</span>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-400 italic">No hay artículos registrados.</div>
            </div>

            <Divider class="!my-0" />

            <!-- Lista de Pagos -->
            <div class="flex flex-col gap-2">
                <span class="text-gray-500 text-sm font-bold uppercase tracking-wider">Historial de pagos</span>
                <ul v-if="transaction.payments && transaction.payments.length" class="flex flex-col gap-3 relative border-l-2 border-gray-200 dark:border-gray-700 ml-2 pl-4 py-1">
                    <li v-for="payment in transaction.payments" :key="payment.id" class="flex flex-col text-sm relative">
                        <!-- Viñeta visual -->
                        <div class="absolute size-2 rounded-full -left-[18px] top-1.5" :class="payment.amount < 0 ? 'bg-red-500' : 'bg-primary-500'"></div>
                        
                        <div class="flex justify-between items-start">
                            <div class="flex items-center gap-1">
                                <span class="font-medium capitalize">{{ (getMethodKey(payment.payment_method) || 'Desconocido').replace(/_/g, ' ') }}</span>
                                <!-- Etiqueta de Devolución -->
                                <Tag v-if="payment.amount < 0" severity="danger" value="Devolución" class="!text-[9px] !px-1 !py-0" />
                            </div>
                            
                            <!-- Monto en verde (pago) o rojo (devolución) -->
                            <span class="font-bold" :class="payment.amount < 0 ? 'text-red-500' : 'text-green-600 dark:text-green-400'">
                                {{ formatCurrency(payment.amount) }}
                            </span>
                        </div>
                        
                        <!-- Información bancaria (si aplica) -->
                        <div v-if="payment.bank_account" class="text-xs text-gray-500 flex items-center gap-1 mt-0.5">
                            <i class="pi pi-building text-[10px]"></i>
                            <span class="truncate max-w-[150px]">{{ payment.bank_account.bank_name }} - {{ payment.bank_account.account_name }}</span>
                            <span v-if="payment.bank_account.account_number || payment.bank_account.card_number" class="text-[10px] italic flex-shrink-0">
                                (***{{ (payment.bank_account.account_number || payment.bank_account.card_number).slice(-4) }})
                            </span>
                        </div>

                        <span class="text-xs text-gray-500 mt-0.5">{{ formatFriendlyDate(payment.created_at) }}</span>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-400 italic">No se han registrado pagos.</div>
            </div>
        </div>

        <!-- Footer Fijo -->
        <template #footer>
            <div v-if="transaction" class="flex flex-col gap-4 w-full pt-4 border-t dark:border-gray-700 bg-surface-0 dark:bg-surface-900">
                <!-- Resumen Total Fijo -->
                <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg flex flex-col gap-1 border dark:border-gray-700">
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Total de la venta:</span>
                        <span>{{ formatCurrency(transaction.total) }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Abonado:</span>
                        <span>{{ formatCurrency(getTransactionTotalPaid(transaction)) }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-base mt-2 pt-2 border-t dark:border-gray-600">
                        <span>Resta:</span>
                        <span :class="getTransactionPending(transaction) > 0 ? 'text-red-500' : 'text-green-500'">
                            {{ formatCurrency(getTransactionPending(transaction)) }}
                        </span>
                    </div>
                </div>

                <!-- Acción Footer -->
                <Button 
                    v-if="hasPermission('transactions.see_details')"
                    label="Ver detalles completos" 
                    icon="pi pi-external-link" 
                    class="w-full" 
                    @click="router.visit(route('transactions.show', transaction.id))" 
                />
            </div>
        </template>
    </Drawer>
</template>