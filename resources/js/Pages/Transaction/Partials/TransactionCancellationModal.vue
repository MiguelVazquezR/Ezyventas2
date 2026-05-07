<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    visible: Boolean,
    transaction: {
        type: Object,
        default: null
    },
    activeSession: {
        type: Object,
        default: null
    },
    bankAccounts: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:visible']);
const toast = useToast();

const isVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value)
});

// --- Estado Interno del Formulario ---
const cancellationAction = ref('refund'); // 'refund' | 'penalty'
const cancellationRefundMethod = ref('cash'); // 'balance' | 'cash' | 'transfer'
const selectedBankAccount = ref(null);
const isCancelling = ref(false);

// Calculamos pagos totales de la transacción seleccionada
const totalPaid = computed(() => {
    if (!props.transaction) return 0;
    return (Array.isArray(props.transaction.payments) ? props.transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
});

// Reseteamos las selecciones cuando se abre el modal
watch(() => props.visible, (newVal) => {
    if (newVal && props.transaction) {
        cancellationAction.value = 'refund'; // Acción por defecto
        selectedBankAccount.value = null; // Limpiar banco seleccionado
        
        // Método por defecto inteligente
        if (props.activeSession) {
            cancellationRefundMethod.value = 'cash';
        } else if (props.transaction.customer_id) {
            cancellationRefundMethod.value = 'balance';
        } else {
            cancellationRefundMethod.value = null; 
        }
    }
});

const formatCurrency = (value) => {
     if (value === null || value === undefined) return '';
     const numberValue = Number(value);
     if (isNaN(numberValue)) return '';
     return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(numberValue);
};

const submitCancellation = () => {
    if (!props.transaction) return;

    isCancelling.value = true;
    
    const payload = { action: cancellationAction.value };
    
    if (cancellationAction.value === 'refund') {
        if (!cancellationRefundMethod.value) {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Selecciona un método de reembolso.', life: 3000 });
            isCancelling.value = false;
            return;
        }

        // Validación extra para transferencia
        if (cancellationRefundMethod.value === 'transfer' && !selectedBankAccount.value) {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Selecciona una cuenta bancaria para el reembolso.', life: 3000 });
            isCancelling.value = false;
            return;
        }

        payload.refund_method = cancellationRefundMethod.value;
        if (cancellationRefundMethod.value === 'transfer') {
            payload.bank_account_id = selectedBankAccount.value;
        }
    }

    router.post(route('transactions.cancel', props.transaction.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            isVisible.value = false; // Cerramos el modal
        },
        onFinish: () => isCancelling.value = false
    });
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } // Fondo sólido oscuro
};

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors w-full' },
    label: { class: '!text-sm !py-2.5' },
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
};
</script>

<template>
    <Dialog v-model:visible="isVisible" modal class="w-full max-w-lg mx-4" :pt="dialogPt">
        
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-red-50 dark:bg-red-900/20 text-red-500 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                    <i class="pi pi-times-circle !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Anular transacción</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Cancelación o reembolso
                    </p>
                </div>
            </div>
        </template>

        <div class="p-fluid" v-if="transaction">
            <!-- Info General -->
            <div class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl flex items-start gap-3 border border-blue-100 dark:border-blue-900/30 mb-6">
                <i class="pi pi-info-circle mt-0.5 !text-lg text-blue-500"></i>
                <div>
                    <p class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 mb-1">Información de pagos</p>
                    <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                        Esta venta (Folio <strong class="font-bold">#{{ transaction.folio }}</strong>) tiene pagos registrados por <strong class="font-bold">{{ formatCurrency(totalPaid) }}</strong>.
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-1">
                    ¿Qué deseas hacer con el dinero recibido?
                </span>
                
                <!-- Opción 1: Reembolsar -->
                <div class="p-5 rounded-2xl border transition-colors cursor-pointer" 
                     @click="cancellationAction = 'refund'"
                     :class="cancellationAction === 'refund' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center mb-2">
                        <RadioButton v-model="cancellationAction" inputId="actionRefund" value="refund" class="pointer-events-none" />
                        <label for="actionRefund" class="ml-3 font-medium text-sm text-gray-900 dark:text-white cursor-pointer m-0">Devolver al cliente (Reembolso)</label>
                    </div>
                    
                    <!-- Subopciones de Reembolso -->
                    <div v-if="cancellationAction === 'refund'" class="ml-8 flex flex-col gap-3 mt-4 border-t border-primary-200 dark:border-primary-800/50 pt-4">
                        
                        <!-- Opción Caja -->
                        <div v-if="activeSession" class="flex items-center">
                            <RadioButton v-model="cancellationRefundMethod" inputId="methodCash" value="cash" />
                            <label for="methodCash" class="ml-3 text-sm text-gray-700 dark:text-gray-300 cursor-pointer m-0">Entregar efectivo de caja</label>
                        </div>
                        <div v-else class="text-[11px] text-orange-600 dark:text-orange-400 flex items-center gap-1.5 bg-orange-50 dark:bg-orange-900/10 p-2.5 rounded-xl border border-orange-100 dark:border-orange-900/30">
                            <i class="pi pi-exclamation-triangle"></i> No hay caja abierta para devolver efectivo.
                        </div>

                        <!-- Opción Transferencia -->
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center">
                                <RadioButton v-model="cancellationRefundMethod" inputId="methodTransfer" value="transfer" />
                                <label for="methodTransfer" class="ml-3 text-sm text-gray-700 dark:text-gray-300 cursor-pointer m-0">Transferencia bancaria</label>
                            </div>
                            <!-- Select para escoger el banco (solo visible si se elige transferencia) -->
                            <div v-if="cancellationRefundMethod === 'transfer'" class="ml-7 mt-1">
                                <Select 
                                    v-model="selectedBankAccount" 
                                    :options="bankAccounts" 
                                    optionLabel="name" 
                                    optionValue="id" 
                                    placeholder="Selecciona la cuenta bancaria" 
                                    class="w-full"
                                    :pt="selectPt"
                                >
                                    <template #empty>
                                        <div class="p-3 text-sm text-gray-500">No hay cuentas de banco disponibles</div>
                                    </template>
                                </Select>
                            </div>
                        </div>

                        <!-- Opción Saldo a Favor -->
                        <div v-if="transaction.customer_id" class="flex items-center">
                            <RadioButton v-model="cancellationRefundMethod" inputId="methodBalance" value="balance" />
                            <label for="methodBalance" class="ml-3 text-sm text-gray-700 dark:text-gray-300 cursor-pointer m-0">Abonar a su saldo a favor</label>
                        </div>
                        <div v-else class="text-[11px] text-orange-600 dark:text-orange-400 flex items-center gap-1.5 bg-orange-50 dark:bg-orange-900/10 p-2.5 rounded-xl border border-orange-100 dark:border-orange-900/30">
                            <i class="pi pi-exclamation-triangle"></i> No se puede abonar a saldo (Venta sin cliente).
                        </div>
                    </div>
                </div>

                <!-- Opción 2: Penalizar -->
                <div class="p-5 rounded-2xl border transition-colors cursor-pointer" 
                     @click="cancellationAction = 'penalty'"
                     :class="cancellationAction === 'penalty' ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center">
                        <RadioButton v-model="cancellationAction" inputId="actionPenalty" value="penalty" class="pointer-events-none" />
                        <label for="actionPenalty" class="ml-3 font-medium text-sm text-red-600 dark:text-red-400 cursor-pointer m-0">Cobrar como penalización</label>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 ml-8 mt-2 m-0 leading-relaxed">
                        El dinero <strong class="font-bold">NO</strong> se devuelve. Se cancela la venta pero el negocio retiene el monto pagado.
                    </p>
                </div>
            </div>
        </div>
        
        <template #footer>
            <div class="flex justify-end gap-3 mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a] w-full">
                <Button type="button" severity="secondary" label="Cancelar" text @click="isVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                <Button 
                    :label="cancellationAction === 'refund' ? 'Confirmar Devolución' : 'Confirmar Penalización'" 
                    :icon="cancellationAction === 'refund' ? 'pi pi-replay' : 'pi pi-ban'" 
                    @click="submitCancellation" 
                    :loading="isCancelling" 
                    :severity="cancellationAction === 'refund' ? 'primary' : 'danger'"
                    :disabled="cancellationAction === 'refund' && !cancellationRefundMethod"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                />
            </div>
        </template>
    </Dialog>
</template>