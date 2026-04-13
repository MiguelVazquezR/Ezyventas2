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
</script>

<template>
    <Dialog v-model:visible="isVisible" modal header="Anular Transacción" :style="{ width: '32rem' }">
        <div class="p-fluid" v-if="transaction">
            <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800 mb-4 text-sm text-blue-800 dark:text-blue-200">
                <i class="pi pi-info-circle mr-1"></i>
                Esta venta (Folio #{{ transaction.folio }}) tiene pagos registrados por <strong>{{ formatCurrency(totalPaid) }}</strong>.
            </div>

            <div class="flex flex-col gap-4">
                <p class="font-bold text-gray-700 dark:text-gray-300">¿Qué deseas hacer con el dinero?</p>
                
                <!-- Opción 1: Reembolsar -->
                <div class="border rounded p-3" :class="cancellationAction === 'refund' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-200 dark:border-gray-700'">
                    <div class="flex items-center mb-2">
                        <RadioButton v-model="cancellationAction" inputId="actionRefund" value="refund" />
                        <label for="actionRefund" class="ml-2 font-bold cursor-pointer">Devolver al cliente (Reembolso)</label>
                    </div>
                    
                    <!-- Subopciones de Reembolso -->
                    <div v-if="cancellationAction === 'refund'" class="ml-7 flex flex-col gap-2 mt-2 animate-fade-in">
                        <div v-if="activeSession" class="flex items-center">
                            <RadioButton v-model="cancellationRefundMethod" inputId="methodCash" value="cash" />
                            <label for="methodCash" class="ml-2 text-sm cursor-pointer">Entregar efectivo de caja</label>
                        </div>
                        <div v-else class="text-xs text-orange-500 ml-1">
                            * No hay caja abierta para devolver efectivo.
                        </div>

                        <!-- MODIFICACIÓN: Nueva opción para Transferencia -->
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center">
                                <RadioButton v-model="cancellationRefundMethod" inputId="methodTransfer" value="transfer" />
                                <label for="methodTransfer" class="ml-2 text-sm cursor-pointer">Transferencia bancaria</label>
                            </div>
                            <!-- Select para escoger el banco (solo visible si se elige transferencia) -->
                            <div v-if="cancellationRefundMethod === 'transfer'" class="ml-6 mt-1 mb-2">
                                <Select 
                                    v-model="selectedBankAccount" 
                                    :options="bankAccounts" 
                                    optionLabel="name" 
                                    optionValue="id" 
                                    placeholder="Selecciona la cuenta bancaria" 
                                    class="w-full"
                                >
                                    <template #empty>
                                        <div class="p-2 text-gray-500">No hay cuentas de banco disponibles</div>
                                    </template>
                                </Select>
                            </div>
                        </div>

                        <div v-if="transaction.customer_id" class="flex items-center">
                            <RadioButton v-model="cancellationRefundMethod" inputId="methodBalance" value="balance" />
                            <label for="methodBalance" class="ml-2 text-sm cursor-pointer">Abonar a su saldo a favor</label>
                        </div>
                        <div v-else class="text-xs text-orange-500 ml-1">
                            * No se puede abonar a saldo (Venta sin cliente registrado).
                        </div>
                    </div>
                </div>

                <!-- Opción 2: Penalizar -->
                <div class="border rounded p-3" :class="cancellationAction === 'penalty' ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-200 dark:border-gray-700'">
                    <div class="flex items-center">
                        <RadioButton v-model="cancellationAction" inputId="actionPenalty" value="penalty" />
                        <label for="actionPenalty" class="ml-2 font-bold cursor-pointer text-red-600">Cobrar como penalización</label>
                    </div>
                    <p class="text-xs text-gray-500 ml-7 mt-1">
                        El dinero NO se devuelve. Se cancela la venta pero el negocio retiene el monto pagado.
                    </p>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Cancelar" severity="secondary" @click="isVisible = false" text />
            <Button 
                :label="cancellationAction === 'refund' ? 'Confirmar Devolución' : 'Confirmar Penalización'" 
                :icon="cancellationAction === 'refund' ? 'pi pi-replay' : 'pi pi-ban'" 
                @click="submitCancellation" 
                :loading="isCancelling" 
                :severity="cancellationAction === 'refund' ? 'primary' : 'danger'"
                :disabled="cancellationAction === 'refund' && !cancellationRefundMethod"
            />
        </template>
    </Dialog>
</template>