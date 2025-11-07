<script setup>
import { ref, computed, watch } from 'vue';
import Message from 'primevue/message';

// --- Props ---
const props = defineProps({
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    transactionType: { type: String, required: true },
    bankAccounts: { type: Array, required: true },
    bankAccountOptions: { type: Array, required: true },
});

// --- Emits ---
const emit = defineEmits(['submit', 'add-account']);

// --- Formateador ---
const formatCurrency = (value) => {
    if (typeof value !== 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

// --- *** INICIO DE NUEVA MODIFICACIÓN: Título Dinámico *** ---
const currentTransactionInfo = computed(() => {
    switch (props.transactionType) {
        case 'contado':
            return {
                label: 'Pago al contado',
                image: '/images/contado.webp',
                bgColor: '#C5E0F7',
                textColor: '#3D5F9B'
            };
        case 'credito':
            return {
                label: 'Venta a crédito / Pagos',
                image: '/images/credito.webp',
                bgColor: '#FFCD87',
                textColor: '#603814'
            };
        case 'apartado':
            return {
                label: 'Sistema de apartado',
                image: '/images/apartado.webp',
                bgColor: '#FFC9E9',
                textColor: '#862384'
            };
        case 'balance':
            return {
                label: 'Abono a saldo',
                image: null, // No hay imagen definida, usamos icono
                icon: 'pi pi-wallet',
                bgColor: '#E0E7FF', // Color Indigo-100
                textColor: '#4338CA' // Color Indigo-700
            };
        default:
            return { label: 'Procesar pago', image: null, icon: 'pi pi-check', bgColor: '#F3F4F6', textColor: '#1F2937' };
    }
});
// --- *** FIN DE NUEVA MODIFICACIÓN *** ---


// --- Estado Interno: Lista de Pagos y Saldo ---
const payments = ref([]);
const useBalance = ref(false);

// --- Botones de Método de Pago ---
const paymentMethodButtons = computed(() => [
    {
        id: 'efectivo',
        label: 'Efectivo',
        image: '/images/efectivo.webp',
        bgColor: '#E0FEC5',
        textColor: '#37672B'
    },
    {
        id: 'tarjeta',
        label: 'Tarjeta',
        image: '/images/tarjeta.webp',
        bgColor: '#DAE6FF',
        textColor: '#063B52'
    },
    {
        id: 'transferencia',
        label: 'Transferencia',
        image: '/images/transferencia.webp',
        bgColor: '#FAFFBA',
        textColor: '#A46400'
    }
]);

// --- Lógica de Pagos Computada ---
const totalAddedPayments = computed(() => payments.value.reduce((sum, p) => sum + (p.amount || 0), 0));
const clientBalanceToUse = computed(() => (props.client && props.client.balance > 0) ? props.client.balance : 0);
const effectiveBalanceUsed = computed(() => useBalance.value ? clientBalanceToUse.value : 0);
const totalPaid = computed(() => totalAddedPayments.value + effectiveBalanceUsed.value);
const remainingAmount = computed(() => props.totalAmount - totalPaid.value);

// --- Lógica de unicidad y edición ---
const usedPaymentMethods = computed(() => new Set(payments.value.map(p => p.method)));

const addPaymentMethod = (method) => {
    if (usedPaymentMethods.value.has(method.id)) return;

    let amountToAdd = 0;
    if (remainingAmount.value > 0.01) {
        amountToAdd = remainingAmount.value;
    }
    else if (props.transactionType === 'balance' || props.transactionType === 'apartado') {
        amountToAdd = 0;
    }
    else {
        amountToAdd = 0;
    }

    payments.value.push({
        id: crypto.randomUUID(),
        method: method.id,
        amount: amountToAdd,
        bank_account_id: null,
        notes: ''
    });
};

const removePayment = (idToRemove) => {
    payments.value = payments.value.filter(p => p.id !== idToRemove);
};

// Lógica de Crédito
const availableCredit = computed(() => props.client?.available_credit || 0);
const creditDeficit = computed(() => {
    if (props.transactionType !== 'credito') return 0;
    if (availableCredit.value <= 0) {
        return remainingAmount.value;
    }
    const deficit = remainingAmount.value - availableCredit.value;
    return deficit > 0.01 ? deficit : 0;
});

// --- Watchers para UX ---
watch(() => props.totalAmount, () => {
    payments.value = [];
    useBalance.value = false;
});

// --- Métodos de Acción ---
const handleSubmit = () => {
    emit('submit', {
        payments: payments.value.filter(p => p.amount > 0),
        use_balance: useBalance.value
    });
};

// --- Lógica del Botón Finalizar (Computada) ---
const finalizeButtonLabel = computed(() => {
    if (props.transactionType === 'balance') return 'Registrar abono';
    if (props.transactionType === 'apartado') return 'Crear apartado';
    if (props.transactionType === 'credito') return `Guardar a crédito (${formatCurrency(remainingAmount.value)})`;
    return 'Finalizar venta';
});

const isFinalizeButtonDisabled = computed(() => {
    if (props.transactionType === 'balance') {
        return totalAddedPayments.value <= 0.01;
    }
    if (props.transactionType === 'apartado') {
        return totalAddedPayments.value <= 0.01;
    }
    if (props.transactionType === 'contado') {
        return remainingAmount.value > 0.01;
    }
    if (props.transactionType === 'credito') {
        return creditDeficit.value > 0;
    }
    return false;
});

// --- Configuración Inicial ---
if (props.totalAmount <= 0) {
    useBalance.value = true;
}
</script>

<template>
    <div class="flex flex-col min-h-[400px]">
        <!-- --- *** INICIO DE NUEVA MODIFICACIÓN: Título *** --- -->
        <div class="flex items-center gap-3 p-3 rounded-lg mb-3 -mt-2"
            :style="{ backgroundColor: currentTransactionInfo.bgColor, color: currentTransactionInfo.textColor }">
            <img v-if="currentTransactionInfo.image" :src="currentTransactionInfo.image"
                :alt="currentTransactionInfo.label" class="size-8 object-contain" />
            <i v-else-if="currentTransactionInfo.icon" :class="[currentTransactionInfo.icon, 'text-xl']"></i>
            <h3 class="text-lg font-bold m-0">{{ currentTransactionInfo.label }}</h3>
        </div>
        <!-- --- *** FIN DE NUEVA MODIFICACIÓN *** --- -->

        <!-- Resumen de Montos -->
        <div class="space-y-2 mb-3">
            <div class="flex justify-between text-xl font-bold">
                <span class="text-gray-800 dark:text-gray-200">Total de venta:</span>
                <span class="font-mono">{{ formatCurrency(totalAmount) }}</span>
            </div>

            <div class="flex justify-between text-lg">
                <span class="font-semibold" :class="{
                    'text-gray-600 dark:text-gray-300': remainingAmount > 0.01,
                    'text-green-600 dark:text-green-400': remainingAmount <= -0.01
                }">
                    {{ remainingAmount <= -0.01 ? 'Su cambio:' : 'Restante:' }} </span>
                        <span class="font-bold font-mono" :class="{
                            'text-red-500': remainingAmount > 0.01,
                            'text-green-600 dark:text-green-400': remainingAmount <= -0.01
                        }">
                            {{ formatCurrency(remainingAmount <= -0.01 ? -remainingAmount : remainingAmount) }} </span>
            </div>
        </div>

        <!-- Toggle de Saldo -->
        <div v-if="clientBalanceToUse > 0"
            class="mb-4 bg-yellow-100 dark:bg-yellow-900/50 border border-yellow-300 dark:border-yellow-700 p-3 rounded-lg flex items-center justify-between">
            <span class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                Usar saldo a favor ({{ formatCurrency(clientBalanceToUse) }})
            </span>
            <InputSwitch v-model="useBalance" />
        </div>
        <div v-if="effectiveBalanceUsed > 0" class="mb-4">
            <div class="flex justify-between items-center bg-gray-100 dark:bg-gray-700 p-3 rounded-lg text-sm">
                <div class="flex items-center gap-2">
                    <i class="pi pi-wallet text-green-600"></i>
                    <span class="font-medium text-gray-800 dark:text-gray-200">Pago con Saldo</span>
                </div>
                <span class="font-semibold font-mono text-gray-800 dark:text-gray-200">
                    {{ formatCurrency(effectiveBalanceUsed) }}
                </span>
            </div>
        </div>

        <!-- Botones de Método de Pago -->
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Agregar método de pago:</label>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
            <button v-for="method in paymentMethodButtons" :key="method.id" @click="addPaymentMethod(method)"
                :disabled="usedPaymentMethods.has(method.id)"
                class="flex flex-col items-center justify-center p-3 border rounded-lg transition-all duration-200 disabled:opacity-30 disabled:cursor-not-allowed hover:brightness-90"
                :style="{ backgroundColor: method.bgColor, color: method.textColor }">
                <img :src="method.image" :alt="method.label" class="size-10 object-contain mb-2">
                <span class="font-semibold text-sm text-center">{{ method.label }}</span>
            </button>
        </div>


        <!-- Lista de Pagos Editable -->
        <div v-if="payments.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-3 flex-grow">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 col-span-full">Pagos registrados:</label>
            <div v-for="(payment, index) in payments" :key="payment.id"
                class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg border dark:border-gray-600">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-semibold capitalize text-gray-800 dark:text-gray-200">{{ payment.method }}</span>
                    <Button icon="pi pi-trash" text rounded severity="danger" @click="removePayment(payment.id)"
                        class="!size-5" size="small" v-tooltip.bottom="'Eliminar pago'" />
                </div>

                <div class="space-y-2">
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Monto</label>
                        <InputNumber fluid v-model="payment.amount" mode="currency" currency="MXN" locale="es-MX"
                            class="w-full mt-1" :min="0" />
                    </div>
                    <div v-if="['tarjeta', 'transferencia'].includes(payment.method)">
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Cuenta destino</label>
                        <div class="flex items-center gap-2">
                            <Select v-model="payment.bank_account_id" :options="bankAccountOptions" optionLabel="label"
                                optionValue="value" placeholder="Selecciona una cuenta" class="w-[80%] mt-1"
                                :class="{ 'p-invalid': !payment.bank_account_id }" />
                            <Button icon="pi pi-plus" rounded severity="secondary" outlined
                                class="!size-8 flex-shrink-0" @click="emit('add-account')"
                                v-tooltip.bottom="'Agregar cuenta'" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Espaciador para empujar el botón hacia abajo si no hay pagos -->
        <div v-if="payments.length === 0" class="flex-grow"></div>

        <!-- Botón de Finalización -->
        <div class="mt-8">
            <Message v-if="creditDeficit > 0" severity="warn" :closable="false" class="mb-3">
                El cliente no tiene suficiente crédito.
                Se excede por {{ formatCurrency(creditDeficit) }}.
                <br>
                <small>Crédito disponible: {{ formatCurrency(availableCredit) }}</small>
            </Message>

            <Button :label="finalizeButtonLabel" :disabled="isFinalizeButtonDisabled" @click="handleSubmit"
                icon="pi pi-check" class="w-full !py-3" />
        </div>
    </div>
</template>