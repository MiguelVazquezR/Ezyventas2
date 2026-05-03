<script setup>
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';

const props = defineProps({
    summary: {
        type: Array,
        required: true
    }
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6">
            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Resumen de cuentas bancarias</h2>
            <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Variación de saldos durante la sesión</p>
        </div>

        <DataTable :value="summary" :pt="dataTablePt" responsiveLayout="scroll">
            
            <Column header="Cuenta">
                <template #body="{ data }">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                            <i class="pi pi-building text-blue-500 !text-xs"></i>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.account_name }}</span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest m-0">{{ data.bank_name }}</span>
                        </div>
                    </div>
                </template>
            </Column>
            
            <Column header="Saldo inicial">
                <template #body="{ data }">
                    <span class="font-mono text-gray-600 dark:text-gray-400">{{ formatCurrency(data.initial_balance) }}</span>
                </template>
            </Column>
            
            <Column header="Saldo final">
                <template #body="{ data }">
                    <span class="font-mono font-medium text-gray-900 dark:text-white">{{ formatCurrency(data.final_balance) }}</span>
                </template>
            </Column>
            
            <Column header="Diferencia">
                <template #body="{ data }">
                    <span class="font-mono font-bold" 
                          :class="{'text-green-500': (data.final_balance - data.initial_balance) > 0, 
                                   'text-red-500': (data.final_balance - data.initial_balance) < 0, 
                                   'text-gray-400': (data.final_balance - data.initial_balance) === 0}">
                        {{ (data.final_balance - data.initial_balance) > 0 ? '+' : '' }}{{ formatCurrency(data.final_balance - data.initial_balance) }}
                    </span>
                </template>
            </Column>

            <template #empty>
                <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                    <i class="pi pi-wallet !text-3xl text-gray-400 mb-3"></i>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin cuentas</p>
                    <p class="text-xs text-gray-400 mt-1">No se detectaron cuentas bancarias en esta sucursal.</p>
                </div>
            </template>
            
        </DataTable>
    </div>
</template>