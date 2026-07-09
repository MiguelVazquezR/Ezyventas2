<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import InputNumber from 'primevue/inputnumber';

const props = defineProps({
    session: {
        type: Object,
        required: true
    },
    sessionTotals: {
        type: Object,
        required: true
    }
});

const isEditingClosingCash = ref(false);
const closingCashEditValue = ref(0);

const totalInflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const totalOutflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const safeCashMovements = computed(() => props.session?.cash_movements || []);

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

const formatFriendlyTime = (dateString) => {
    if (!dateString) return '';
    const d = new Date(dateString);
    return d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};

function startEditingClosingCash() {
    closingCashEditValue.value = parseFloat(props.session.closing_cash_balance) || 0;
    isEditingClosingCash.value = true;
}

function cancelEditingClosingCash() {
    isEditingClosingCash.value = false;
}

function saveClosingCash() {
    router.patch(
        route('cash-register-sessions.update-closing-cash', props.session.id),
        { closing_cash_balance: closingCashEditValue.value },
        {
            onSuccess: () => {
                isEditingClosingCash.value = false;
            },
            onError: () => {
                // mantener el modo edición para que el usuario corrija
            },
        }
    );
}
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6">
            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Resumen financiero y movimientos</h2>
            <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Balance de efectivo y auditoría</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
            
            <!-- Columna 1: Balance y Desglose Matemático -->
            <div class="space-y-6 flex flex-col">
                
                <!-- Telemetría Principal (Diferencia) -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 lg:p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col items-center text-center relative overflow-hidden group hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                    <!-- Glow sutil dependiento de si hay diferencia -->
                    <div class="absolute inset-0 opacity-10 transition-opacity group-hover:opacity-20 pointer-events-none"
                         :class="session.cash_difference < 0 ? 'bg-red-500' : (session.cash_difference > 0 ? 'bg-green-500' : 'bg-transparent')">
                    </div>

                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3 relative z-10">Diferencia de caja (Sobrante/Faltante)</span>
                    <span class="text-5xl font-light tracking-tight m-0 leading-none relative z-10" 
                          :class="session.cash_difference < 0 ? 'text-red-500' : (session.cash_difference > 0 ? 'text-green-500' : 'text-gray-900 dark:text-white')">
                        {{ session.cash_difference > 0 ? '+' : '' }}{{ formatCurrency(session.cash_difference) }}
                    </span>
                    
                    <div class="grid grid-cols-2 gap-4 w-full mt-6 pt-5 border-t border-gray-200 dark:border-[#2a2a2a] relative z-10">
                        <div class="text-center border-r border-gray-200 dark:border-[#2a2a2a]">
                            <span class="text-[9px] uppercase tracking-widest text-gray-400 block m-0 mb-1">Esperado sistema</span>
                            <span class="font-mono text-sm text-gray-700 dark:text-gray-300 m-0">{{ formatCurrency(session.calculated_cash_total) }}</span>
                        </div>
                        <div class="text-center">
                            <span class="text-[9px] uppercase tracking-widest text-gray-400 block m-0 mb-1">Contado físico</span>

                            <!-- Modo edición -->
                            <div v-if="isEditingClosingCash" class="flex flex-col items-center gap-2">
                                <InputNumber
                                    v-model="closingCashEditValue"
                                    mode="currency"
                                    currency="MXN"
                                    :minFractionDigits="2"
                                    :maxFractionDigits="2"
                                    class="w-36"
                                    :pt="{
                                        input: { root: { class: 'w-full min-w-0 !rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !py-1.5 !text-sm !font-mono !text-gray-900 dark:!text-white text-center' } }
                                    }"
                                />
                                <div class="flex items-center gap-1.5">
                                    <button
                                        @click="saveClosingCash"
                                        class="w-7 h-7 rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center justify-center transition-colors"
                                        title="Guardar"
                                    >
                                        <i class="pi pi-check !text-[10px]"></i>
                                    </button>
                                    <button
                                        @click="cancelEditingClosingCash"
                                        class="w-7 h-7 rounded-full bg-gray-300 dark:bg-gray-600 hover:bg-gray-400 dark:hover:bg-gray-500 text-white flex items-center justify-center transition-colors"
                                        title="Cancelar"
                                    >
                                        <i class="pi pi-times !text-[10px]"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Modo lectura -->
                            <div v-else class="group inline-flex items-center gap-1.5 cursor-pointer" @click="startEditingClosingCash" title="Haz clic para editar el contado físico">
                                <span class="font-mono text-sm text-gray-900 dark:text-white font-bold m-0">{{ formatCurrency(session.closing_cash_balance) }}</span>
                                <i class="pi pi-pencil !text-[10px] text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Desglose Matemático -->
                <div class="space-y-3">
                    <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-2">Desglose de cálculo</h3>
                    
                    <div class="flex justify-between items-center text-sm py-1 border-b border-gray-50 dark:border-[#2a2a2a]/50">
                        <span class="text-gray-600 dark:text-gray-400 font-medium">Fondo inicial en caja</span>
                        <span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(session.opening_cash_balance) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm py-1 border-b border-gray-50 dark:border-[#2a2a2a]/50">
                        <span class="text-gray-600 dark:text-gray-400 font-medium">(+) Ventas cobradas en efectivo</span>
                        <span class="font-mono text-green-600 dark:text-green-400">{{ formatCurrency(sessionTotals.efectivo || 0) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm py-1 border-b border-gray-50 dark:border-[#2a2a2a]/50">
                        <span class="text-gray-600 dark:text-gray-400 font-medium">(+) Ingresos manuales</span>
                        <span class="font-mono text-green-600 dark:text-green-400">{{ formatCurrency(totalInflows) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm py-1 border-b border-gray-50 dark:border-[#2a2a2a]/50">
                        <span class="text-gray-600 dark:text-gray-400 font-medium">(-) Retiros manuales</span>
                        <span class="font-mono text-red-500">{{ formatCurrency(totalOutflows) }}</span>
                    </div>
                </div>

                <!-- Notas del Cajero -->
                <div v-if="session.notes" class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30 mt-auto">
                    <span class="text-[9px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest block m-0 mb-2 flex items-center gap-1.5"><i class="pi pi-comment !text-[10px]"></i> Notas del cajero</span>
                    <p class="text-sm text-blue-900 dark:text-blue-200 italic m-0 leading-relaxed">"{{ session.notes }}"</p>
                </div>
            </div>

            <!-- Columna 2: Lista de Movimientos Estilo Tesla -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col h-full overflow-hidden min-h-[300px]">
                <div class="p-4 border-b border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center bg-gray-100/50 dark:bg-[#232323]">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Registro de movimientos de caja</span>
                    <span class="bg-gray-200 dark:bg-[#3a3a3a] text-gray-600 dark:text-gray-400 px-2 py-0.5 rounded-full text-[9px] font-bold">{{ safeCashMovements.length }}</span>
                </div>
                
                <div class="overflow-y-auto custom-scrollbar flex-grow p-4 space-y-3">
                    <ul v-if="safeCashMovements.length > 0" class="m-0 p-0 list-none space-y-3">
                        <li v-for="mov in safeCashMovements" :key="mov.id" class="flex justify-between items-start border-b border-gray-200 dark:border-[#2a2a2a] pb-3 last:border-0 last:pb-0">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0" :class="mov.type === 'ingreso' ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 border border-green-200 dark:border-green-800' : 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800'">
                                    <i class="pi" :class="mov.type === 'ingreso' ? 'pi-arrow-down-left' : 'pi-arrow-up-right'" style="font-size: 0.75rem;"></i>
                                </div>
                                <div class="flex flex-col gap-1 mt-0.5">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 leading-tight m-0">{{ mov.description }}</span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[9px] uppercase tracking-widest text-gray-500 m-0"><i class="pi pi-user !text-[8px] mr-0.5"></i> {{ mov.user?.name || 'Sistema' }}</span>
                                        <span class="text-[9px] text-gray-400 m-0">• {{ formatFriendlyTime(mov.created_at) }}</span>
                                    </div>
                                </div>
                            </div>
                            <span class="font-mono text-sm font-bold mt-1.5" :class="mov.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">
                                {{ mov.type === 'ingreso' ? '+' : '-' }}{{ formatCurrency(mov.amount) }}
                            </span>
                        </li>
                    </ul>
                    <div v-else class="h-full flex flex-col items-center justify-center text-center py-12 opacity-60">
                        <i class="pi pi-book !text-3xl text-gray-400 mb-3"></i>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin movimientos</span>
                        <span class="text-xs text-gray-400 mt-1 max-w-[200px]">No se registraron ingresos o retiros manuales durante esta sesión.</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>