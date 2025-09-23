<script setup>
import { ref, computed, watch } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import SelectButton from 'primevue/selectbutton';
import Divider from 'primevue/divider';
import Message from 'primevue/message';

const props = defineProps({
    visible: Boolean,
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null } // AÑADIDO: Recibe el cliente
});

const emit = defineEmits(['update:visible', 'submit']);

const payments = ref([]);
const newPaymentAmount = ref(props.totalAmount);
const newPaymentMethod = ref('efectivo');
const paymentOptions = ref(['efectivo', 'tarjeta', 'transferencia']);

const totalPaid = computed(() => payments.value.reduce((sum, p) => sum + p.amount, 0));
const amountRemaining = computed(() => props.totalAmount - totalPaid.value);
const change = computed(() => {
    const diff = totalPaid.value - props.totalAmount;
    return diff > 0 ? diff : 0;
});

// --- LÓGICA INTELIGENTE ---
const isCreditSale = computed(() => {
    // Es una venta a crédito si hay un cliente, queda un monto por pagar,
    // y ese monto es cubierto por su crédito disponible.
    return props.client && amountRemaining.value > 0.01 && amountRemaining.value <= props.client.available_credit;
});

const canFinalize = computed(() => {
    if (amountRemaining.value <= 0.01) return true; // Totalmente pagado
    return isCreditSale.value; // O cubierto por el crédito
});

const finalizeButtonLabel = computed(() => {
    if (isCreditSale.value) {
        return `Finalizar (Crédito: $${amountRemaining.value.toFixed(2)})`;
    }
    return 'Finalizar Venta';
});


watch(() => props.visible, (newVal) => {
    if (newVal) {
        payments.value = [];
        newPaymentAmount.value = props.totalAmount > 0 ? props.totalAmount : null;
        newPaymentMethod.value = 'efectivo';
    }
});

const addPayment = () => {
    if (newPaymentAmount.value > 0) {
        payments.value.push({
            amount: newPaymentAmount.value,
            method: newPaymentMethod.value
        });
        newPaymentAmount.value = amountRemaining.value > 0 ? amountRemaining.value : null;
    }
};

const removePayment = (index) => {
    payments.value.splice(index, 1);
    newPaymentAmount.value = amountRemaining.value > 0 ? amountRemaining.value : null;
};

const submitForm = () => {
    emit('submit', { payments: payments.value });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', $event)" modal header="Procesar Pago" :style="{ width: '35rem' }">
        
        <!-- AÑADIDO: Info del Cliente -->
        <div v-if="client" class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ client.name }}</p>
            <div class="flex justify-between text-xs mt-1">
                <span class="text-gray-500 dark:text-gray-400">Crédito Disponible:</span>
                <span class="font-mono font-semibold text-blue-600">${{ client.available_credit.toFixed(2) }}</span>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-6 p-2">
            <!-- Columna Izquierda: Resumen -->
            <div class="space-y-4">
                <div class="text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total a Pagar</p>
                    <p class="text-4xl font-bold text-gray-800 dark:text-gray-100">${{ totalAmount.toFixed(2) }}</p>
                </div>
                <Divider />
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span>Total Pagado:</span><span class="font-semibold">${{ totalPaid.toFixed(2) }}</span></div>
                    <div class="flex justify-between font-bold" :class="amountRemaining > 0 ? 'text-red-500' : 'text-gray-800 dark:text-gray-200'">
                        <span>Restante:</span>
                        <span>${{ amountRemaining.toFixed(2) }}</span>
                    </div>
                    <div v-if="change > 0" class="flex justify-between text-green-600 font-bold">
                        <span>Cambio:</span>
                        <span>${{ change.toFixed(2) }}</span>
                    </div>
                </div>
                 <!-- AÑADIDO: Mensaje de Venta a Crédito -->
                <Message v-if="isCreditSale" severity="info" :closable="false">
                    El monto restante se añadirá al saldo deudor del cliente.
                </Message>
            </div>

            <!-- Columna Derecha: Métodos de Pago -->
            <div class="space-y-4">
                 <p class="font-semibold text-center text-gray-700 dark:text-gray-300">Agregar Pago</p>
                <div class="p-fluid">
                    <div class="p-field">
                        <label for="payment-amount" class="text-sm">Monto</label>
                        <InputNumber id="payment-amount" v-model="newPaymentAmount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1"/>
                    </div>
                     <div class="p-field mt-4">
                        <label class="text-sm">Método</label>
                         <SelectButton v-model="newPaymentMethod" :options="paymentOptions" aria-labelledby="basic" class="w-full mt-1"/>
                    </div>
                </div>
                <Button @click="addPayment" label="Agregar Pago" icon="pi pi-plus" class="w-full" :disabled="!newPaymentAmount || newPaymentAmount <= 0" />

                <div v-if="payments.length > 0" class="space-y-2 pt-2">
                     <div v-for="(payment, index) in payments" :key="index" class="flex items-center justify-between bg-gray-100 dark:bg-gray-800 p-2 rounded-md text-sm">
                        <span class="capitalize">{{ payment.method }}: <b>${{ payment.amount.toFixed(2) }}</b></span>
                        <Button @click="removePayment(index)" icon="pi pi-times" text rounded severity="secondary" size="small" />
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="$emit('update:visible', false)" />
            <Button :label="finalizeButtonLabel" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" />
        </template>
    </Dialog>
</template>