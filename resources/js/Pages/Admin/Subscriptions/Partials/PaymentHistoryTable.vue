<script setup>

defineProps({
    payments: Array
});

const emit = defineEmits(['reviewPayment']);

// --- HELPER FUNCTIONS ---
const formatDate = (dateString) => {
    if (!dateString) return '--';
    return new Intl.DateTimeFormat('es-MX', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(dateString));
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const getPaymentStatusIcon = (status) => {
    switch(status) {
        case 'approved': return { icon: 'pi pi-check-circle', class: 'text-green-500' };
        case 'pending': return { icon: 'pi pi-clock', class: 'text-orange-500' };
        case 'rejected': return { icon: 'pi pi-times-circle', class: 'text-red-500' };
        default: return { icon: 'pi pi-info-circle', class: 'text-gray-500' };
    }
};

// --- TESLA UI PT ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-3 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-3 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};
</script>

<template>
    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
        <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
            <i class="pi pi-money-bill"></i> Historial de transacciones
        </h2>
        
        <DataTable :value="payments" :paginator="true" :rows="5" removableSort :pt="dataTablePt">
            
            <Column field="created_at" header="Fecha" sortable>
                <template #body="{ data }">
                    <span class="text-xs dark:text-gray-300">{{ formatDate(data.created_at) }}</span>
                </template>
            </Column>
            
            <Column field="amount" header="Monto">
                <template #body="{ data }">
                    <span class="font-mono text-sm dark:text-white">{{ formatCurrency(data.amount) }}</span>
                </template>
            </Column>

            <Column field="payment_method" header="Método" style="text-transform: capitalize;">
                <template #body="{ data }">
                    <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-widest">{{ data.payment_method }}</span>
                </template>
            </Column>

            <Column field="status" header="Estado">
                <template #body="{ data }">
                    <div class="flex items-center gap-2">
                        <i :class="[getPaymentStatusIcon(data.status).icon, getPaymentStatusIcon(data.status).class, '!text-[10px]']"></i>
                        <span class="text-xs font-medium dark:text-gray-300 uppercase">{{ data.status }}</span>
                    </div>
                </template>
            </Column>

            <Column headerStyle="width: 4rem; text-align: center">
                <template #body="{ data }">
                    <Button v-if="data.status === 'pending'" @click="emit('reviewPayment', data)" 
                        icon="pi pi-search" text rounded v-tooltip.left="'Revisar pago'"
                        class="!w-8 !h-8 !text-primary-500 hover:!bg-primary-900/20 !transition-colors" />
                </template>
            </Column>

            <template #empty>
                <div class="text-center py-6 text-gray-500 text-xs">No hay historial de pagos registrado.</div>
            </template>
        </DataTable>
    </div>
</template>