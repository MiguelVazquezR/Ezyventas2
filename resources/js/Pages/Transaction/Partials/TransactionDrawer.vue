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
        const month = new Intl.DateTimeFormat('es-MX', { month: 'short' }).format(d);
        let hour = d.getHours();
        const minute = d.getMinutes().toString().padStart(2, '0');
        const ampm = hour >= 12 ? 'pm' : 'am';
        hour = hour % 12;
        hour = hour ? hour : 12;
        return `${day} ${month}, ${hour}:${minute} ${ampm}`;
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

// --- TESLA UI PASS-THROUGH (PT) ---
const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-0 custom-scrollbar flex flex-col' },
    footer: { class: 'dark:bg-[#232323] p-0' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <Drawer v-model:visible="isVisible" position="right" class="w-full md:!w-[30rem]" :pt="drawerPt">
        
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50">
                    <i class="pi pi-receipt !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Venta {{ transaction?.folio }}</h2>
                </div>
            </div>
        </template>
        
        <div v-if="transaction" class="flex-grow space-y-6 overflow-y-auto pb-6 px-6 pt-6 custom-scrollbar">
            
            <!-- Información General -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0">Estatus</h3>
                    <Tag :value="formatStatusLabel(transaction.status)" :severity="getStatusSeverity(transaction.status)" :pt="tagPt" />
                </div>
                
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3 pt-2">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha y Hora</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-white flex items-center gap-1">
                        <i class="pi pi-calendar !text-xs text-gray-400"></i>
                        {{ formatFriendlyDate(transaction.created_at) }}
                    </span>
                </div>

                <div class="flex justify-between items-center pt-1 border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cliente</span>
                    <div class="flex flex-col items-end">
                        <span v-if="transaction.customer" class="font-medium text-sm text-gray-900 dark:text-white truncate max-w-[180px]" :title="transaction.customer.name">
                            {{ transaction.customer.name }}
                        </span>
                        <span v-else-if="transaction.contact_info && transaction.contact_info.name" class="font-medium text-sm text-gray-900 dark:text-white truncate max-w-[180px]" :title="transaction.contact_info.name">
                            {{ transaction.contact_info.name }} <Tag severity="info" value="Comanda" class="!text-[9px] !px-1.5 !py-0.5 ml-1" />
                        </span>
                        <span v-else class="text-gray-500 italic text-sm truncate max-w-[180px]" title="Público en general">
                            Público general
                        </span>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cajero</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-white truncate max-w-[180px]">
                        {{ transaction.user?.name || 'N/A' }}
                    </span>
                </div>
            </div>

            <!-- Lista de Artículos -->
            <div class="space-y-3 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-3">Artículos registrados</h3>
                
                <ul v-if="transaction.items && transaction.items.length" class="flex flex-col gap-3 m-0 p-0 list-none">
                    <li v-for="item in transaction.items" :key="item.id" class="flex justify-between items-center text-sm border-b border-gray-200 dark:border-[#2a2a2a] pb-2 last:border-0 last:pb-0">
                        <div class="flex flex-col flex-1 pr-2">
                            <span class="font-medium text-gray-800 dark:text-gray-200 leading-tight">
                                <span class="text-gray-400 mr-1">{{ Math.round(item.quantity) }}x</span>
                                {{ item.description }}
                            </span>
                        </div>
                        <span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</span>
                    </li>
                </ul>
                <div v-else class="text-xs text-gray-400 italic text-center py-2">
                    No hay artículos registrados en esta venta.
                </div>
            </div>

            <!-- Historial de Pagos -->
            <div class="space-y-3 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-3">Historial de pagos</h3>
                
                <ul v-if="transaction.payments && transaction.payments.length" class="flex flex-col gap-4 m-0 p-0 list-none relative border-l border-gray-200 dark:border-[#3a3a3a] ml-2 pl-4 py-1">
                    <li v-for="payment in transaction.payments" :key="payment.id" class="flex flex-col text-sm relative">
                        <!-- Viñeta LED visual -->
                        <div class="absolute w-2 h-2 rounded-full -left-[18px] top-1.5" :class="payment.amount < 0 ? 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]' : 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]'"></div>
                        
                        <div class="flex justify-between items-start">
                            <div class="flex items-center gap-2">
                                <span class="font-medium capitalize text-gray-800 dark:text-gray-200">{{ (getMethodKey(payment.payment_method) || 'Desconocido').replace(/_/g, ' ') }}</span>
                                <Tag v-if="payment.amount < 0" severity="danger" value="Devolución" :pt="tagPt" />
                            </div>
                            
                            <span class="font-mono font-bold" :class="payment.amount < 0 ? 'text-red-500' : 'text-green-600 dark:text-green-400'">
                                {{ formatCurrency(payment.amount) }}
                            </span>
                        </div>
                        
                        <!-- Información bancaria (si aplica) -->
                        <div v-if="payment.bank_account" class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 flex items-center gap-1">
                            <i class="pi pi-building !text-[9px]"></i>
                            <span class="truncate max-w-[180px]">{{ payment.bank_account.bank_name }} - {{ payment.bank_account.account_name }}</span>
                            <span v-if="payment.bank_account.account_number || payment.bank_account.card_number" class="italic flex-shrink-0">
                                (***{{ (payment.bank_account.account_number || payment.bank_account.card_number).slice(-4) }})
                            </span>
                        </div>

                        <span class="text-[10px] uppercase tracking-widest text-gray-500 mt-1">{{ formatFriendlyDate(payment.created_at) }}</span>
                    </li>
                </ul>
                <div v-else class="text-xs text-gray-400 italic text-center py-2">No se han registrado pagos en esta venta.</div>
            </div>
            
        </div>

        <!-- Footer Fijo (Resumen Financiero y Acciones) -->
        <template #footer>
            <div v-if="transaction" class="p-6 border-t border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] flex flex-col gap-4">
                
                <!-- Resumen Financiero Tesla UI -->
                <div class="flex flex-col gap-2 bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400">
                        <span class="text-[10px] uppercase tracking-widest font-bold m-0">Total de venta</span>
                        <span class="font-mono">{{ formatCurrency(transaction.total) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                        <span class="text-[10px] uppercase tracking-widest font-bold m-0">Abonado</span>
                        <span class="font-mono text-green-600 dark:text-green-500">{{ formatCurrency(getTransactionTotalPaid(transaction)) }}</span>
                    </div>
                    <div class="flex justify-between items-center pt-2">
                        <span class="text-[10px] uppercase tracking-widest font-bold m-0">Resta por cobrar</span>
                        <span class="font-light tracking-tight text-3xl leading-none m-0" :class="getTransactionPending(transaction) > 0 ? 'text-red-500' : 'text-green-500'">
                            {{ formatCurrency(getTransactionPending(transaction)) }}
                        </span>
                    </div>
                </div>

                <Button 
                    v-if="hasPermission('transactions.see_details')"
                    label="Ver detalles completos" 
                    icon="pi pi-eye" 
                    class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                    @click="router.visit(route('transactions.show', transaction.id))" 
                />
            </div>
        </template>
    </Drawer>
</template>