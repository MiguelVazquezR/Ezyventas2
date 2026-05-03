<script setup>
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    visible: Boolean,
    account: Object,
});

const emit = defineEmits(['update:visible']);

const movements = ref([]);
const loading = ref(false);

const fetchHistory = async () => {
    if (!props.account) return;
    loading.value = true;
    try {
        const response = await axios.get(route('bank-accounts.history', props.account.id));
        movements.value = response.data;
    } catch (error) {
        console.error("Error al cargar el historial:", error);
    } finally {
        loading.value = false;
    }
};

watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchHistory();
    } else {
        movements.value = []; // Limpiar al cerrar
    }
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const formatDateTime = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    }).format(date);
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Historial de movimientos"
        class="w-full max-w-5xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5 md:px-8 md:py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white' },
            content: { class: 'dark:bg-[#232323] px-4 py-6 md:px-8' }
        }">
        
        <div>
            <!-- Resumen de cuenta (Cabecera) -->
            <div v-if="account" class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center text-primary-500 shadow-sm border border-primary-100 dark:border-primary-900/50">
                        <i class="pi pi-building text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-lg text-gray-900 dark:text-gray-100 tracking-tight m-0">{{ account.account_name }}</h3>
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest m-0">{{ account.bank_name }}</p>
                    </div>
                </div>
                <div class="mt-2 md:mt-0 text-left md:text-right w-full md:w-auto border-t md:border-t-0 border-gray-200 dark:border-[#3a3a3a] pt-4 md:pt-0">
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mb-1">Saldo actual</p>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatCurrency(account.balance) }}</p>
                </div>
            </div>

            <!-- Tabla de movimientos -->
            <DataTable :value="movements" :loading="loading" responsiveLayout="scroll" paginator :rows="10"
                :pt="{
                    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
                    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
                    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
                    row: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#2a2a2a] transition-colors' },
                    bodyCell: { class: 'py-4 border-b border-gray-50 dark:border-[#2a2a2a] text-sm text-gray-800 dark:text-gray-200' },
                    paginator: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] rounded-b-2xl' }
                }">
                
                <template #empty>
                    <div v-if="!loading" class="flex flex-col items-center justify-center py-12 text-gray-500">
                        <i class="pi pi-inbox text-3xl mb-3 opacity-50"></i>
                        <p class="text-sm">No se encontraron movimientos registrados.</p>
                    </div>
                </template>
                
                <template #loading>
                    <div class="flex flex-col items-center justify-center py-12 text-primary-500">
                        <i class="pi pi-spin pi-spinner-dotted text-3xl mb-3"></i>
                        <span class="text-[10px] uppercase tracking-widest">Sincronizando...</span>
                    </div>
                </template>

                <Column field="date" header="Fecha y hora" style="min-width: 140px;">
                    <template #body="{ data }">
                        <span class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ formatDateTime(data.date) }}</span>
                    </template>
                </Column>
                
                <Column field="type" header="Tipo" style="min-width: 120px;">
                    <template #body="{ data }">
                        <span class="text-[11px] font-medium uppercase tracking-wider px-2 py-1 rounded-full bg-gray-100 dark:bg-[#3a3a3a] text-gray-600 dark:text-gray-300">
                            {{ data.type }}
                        </span>
                    </template>
                </Column>
                
                <Column field="folio" header="Folio/ref." style="min-width: 120px;">
                    <template #body="{ data }">
                        <Link v-if="data.related_url" :href="data.related_url" class="text-primary-600 dark:text-primary-400 hover:text-primary-500 font-mono font-medium transition-colors">
                            {{ data.folio }}
                        </Link>
                        <span v-else class="font-mono font-medium text-gray-500">{{ data.folio }}</span>
                    </template>
                </Column>
                
                <Column field="method" header="Método" style="min-width: 120px;">
                    <template #body="{ data }">
                        <span class="capitalize text-xs">{{ data.method }}</span>
                    </template>
                </Column>
                
                <Column field="amount" header="Monto" style="min-width: 120px;" alignFrozen="right">
                     <template #body="{ data }">
                        <span class="font-light text-lg tracking-tight" :class="data.amount > 0 ? 'text-green-500' : 'text-red-500'">
                            <span v-if="data.amount > 0">+</span>{{ formatCurrency(data.amount) }}
                        </span>
                    </template>
                </Column>
                
                <Column field="balance_after" header="Saldo final" style="min-width: 130px;">
                    <template #body="{ data }">
                        <span class="font-mono text-sm">{{ formatCurrency(data.balance_after) }}</span>
                    </template>
                </Column>
            </DataTable>
        </div>
    </Dialog>
</template>