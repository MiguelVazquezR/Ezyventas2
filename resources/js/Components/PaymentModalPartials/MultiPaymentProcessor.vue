<script setup>
import { ref, computed, watch } from 'vue';

// --- Props ---
const props = defineProps({
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    transactionType: { type: String, required: true },
    bankAccounts: { type: Array, required: true },
    bankAccountOptions: { type: Array, required: true },
    loading: { type: Boolean, default: false },
});

// --- Emits ---
const emit = defineEmits(['submit', 'add-account']);

// --- Formateador ---
const formatCurrency = (value) => {
    if (typeof value !== 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

// --- Estado Interno: Lista de Pagos y Saldo ---
const payments = ref([]);
const useBalance = ref(true);
const layawayExpirationDate = ref(null); // Nuevo estado para fecha de apartado

// --- Configurar fecha default al cambiar a modo Apartado ---
watch(() => props.transactionType, (newType) => {
    if (newType === 'apartado') {
        const defaultDate = new Date();
        defaultDate.setDate(defaultDate.getDate() + 30); // Default 30 días
        layawayExpirationDate.value = defaultDate;
    } else {
        layawayExpirationDate.value = null;
    }
}, { immediate: true });

// --- Título dinámico ---
const currentTransactionInfo = computed(() => {
    switch (props.transactionType) {
        case 'contado':
            return {
                id: 'contado',
                label: 'Pago al contado',
                image: '/images/contado.webp',
                bgColor: '#C5E0F7',
                textColor: '#3D5F9B',
            };
        case 'credito':
            return {
                id: 'credito',
                label: 'A Crédito / Pagos',
                image: '/images/credito.webp',
                bgColor: '#FFCD87',
                textColor: '#603814',
            };
        case 'apartado':
            return {
                id: 'apartado',
                label: 'Sistema de apartado',
                image: '/images/apartado.webp',
                bgColor: '#FFC9E9',
                textColor: '#862384',
            };
        case 'balance':
            return {
                id: 'balance',
                label: 'Abono a saldo',
                image: '/images/efectivo.webp',
                bgColor: '#E5E7EB',
                textColor: '#374151',
            };
        case 'flexible':
            return {
                id: 'flexible',
                label: 'Abono a orden',
                image: '/images/contado.webp',
                bgColor: '#E0F2FE',
                textColor: '#0C4A6E',
            };
        default:
            return {
                id: 'default',
                label: 'Registrar pago',
                image: '/images/contado.webp',
                bgColor: '#E5E7EB',
                textColor: '#374151',
            };
    }
});

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
const effectiveBalanceUsed = computed(() => useBalance.value ? Math.min(clientBalanceToUse.value, props.totalAmount) : 0);
const totalPaid = computed(() => totalAddedPayments.value + effectiveBalanceUsed.value);
const remainingAmount = computed(() => props.totalAmount - totalPaid.value);

// --- Lógica de unicidad y edición de pagos ---
const usedPaymentMethods = computed(() => new Set(payments.value.map(p => p.method)));

const addPaymentMethod = (method) => {
    if (usedPaymentMethods.value.has(method.id)) return;

    let amountToAdd = 0;
    if (remainingAmount.value > 0.01) {
        amountToAdd = remainingAmount.value;
    }
    else if (props.transactionType === 'balance' || props.transactionType === 'apartado' || props.transactionType === 'flexible') {
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

// --- Lógica de Crédito ---
const availableCredit = computed(() => props.client?.available_credit || 0);
const creditDeficit = computed(() => {
    if (props.transactionType !== 'credito') return 0;
    if (availableCredit.value <= 0 && remainingAmount.value > 0.01) {
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

// --- Lógica del Botón Finalizar (Computada) ---
const isFinalizeButtonDisabled = computed(() => {
    // 1. VALIDACIÓN DE CUENTAS BANCARIAS
    const hasMissingBankAccount = payments.value.some(p => 
        ['tarjeta', 'transferencia'].includes(p.method) && 
        p.amount > 0 && 
        !p.bank_account_id
    );
    if (hasMissingBankAccount) return true;

    // 2. VALIDACIÓN DE FECHA DE APARTADO
    if (props.transactionType === 'apartado' && !layawayExpirationDate.value) {
        return true;
    }

    // 3. Validaciones de montos según el tipo
    if (props.transactionType === 'balance' || props.transactionType === 'apartado') {
        return totalPaid.value <= 0.01;
    }
    if (props.transactionType === 'flexible') {
        return totalPaid.value <= 0.01;
    }
    if (props.transactionType === 'contado') {
        return remainingAmount.value > 0.01;
    }
    if (props.transactionType === 'credito') {
        return creditDeficit.value > 0;
    }
    return false;
});

const finalizeButtonLabel = computed(() => {
    if (props.transactionType === 'balance') return 'Registrar abono';
    if (props.transactionType === 'apartado') return 'Crear apartado';
    if (props.transactionType === 'credito') {
        return remainingAmount.value > 0.01 ? `Guardar a crédito (${formatCurrency(remainingAmount.value)})` : 'Finalizar venta';
    }
    if (props.transactionType === 'flexible') return 'Registrar abono';

    return 'Finalizar venta';
});

const handleSubmit = () => {
    if (isFinalizeButtonDisabled.value) return;

    emit('submit', {
        payments: payments.value.filter(p => p.amount > 0),
        use_balance: useBalance.value,
        layaway_expiration_date: layawayExpirationDate.value, // Enviamos la fecha seleccionada
    });
};

// --- Configuración Inicial ---
if (props.totalAmount <= 0 && props.client && props.client.balance > 0) {
    useBalance.value = true;
}
</script>

<template>
    <div class="flex flex-col min-h-[400px]">
        <!-- Título dinámico -->
        <div class="flex items-center gap-3 mb-4 p-3 rounded-lg"
            :style="{ backgroundColor: currentTransactionInfo.bgColor, color: currentTransactionInfo.textColor }">
            <img :src="currentTransactionInfo.image" :alt="currentTransactionInfo.label" class="h-8 w-8 object-contain">
            <h3 class="text-lg font-semibold m-0">{{ currentTransactionInfo.label }}</h3>
        </div>

        <!-- Resumen de Montos -->
        <div v-if="props.transactionType !== 'balance'" class="space-y-2 mb-4">
            <div class="flex justify-between text-2xl font-bold">
                <span class="text-gray-800 dark:text-gray-200">Total de venta:</span>
                <span class="font-mono">{{ formatCurrency(totalAmount) }}</span>
            </div>

            <div class="flex justify-between text-lg">
                <span class="font-semibold" :class="{
                    'text-gray-600 dark:text-gray-300': remainingAmount > 0.01,
                    'text-green-600 dark:text-green-400': remainingAmount < 0.0
                }">
                    {{ remainingAmount < 0.0 ? 'Su cambio:' : 'Restante:' }} </span>
                        <span class="font-bold font-mono" :class="{
                            'text-red-500': remainingAmount > 0.01,
                            'text-green-600 dark:text-green-400': remainingAmount < 0.0
                        }">
                            {{ formatCurrency(remainingAmount < 0.0 ? -remainingAmount : remainingAmount) }} </span>
            </div>
        </div>

        <!-- Toggle de Saldo -->
        <div v-if="clientBalanceToUse > 0 && props.transactionType == 'contado'"
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
        <div class="grid grid-cols-3 gap-3 mb-4">
            <button v-for="method in paymentMethodButtons" :key="method.id" @click="addPaymentMethod(method)"
                :disabled="usedPaymentMethods.has(method.id)"
                class="flex flex-col items-center justify-center p-4 border rounded-lg transition-all duration-200 disabled:opacity-30 disabled:cursor-not-allowed hover:brightness-90"
                :style="{ backgroundColor: method.bgColor, color: method.textColor }">
                <img :src="method.image" :alt="method.label" class="h-10 w-10 object-contain mb-2">
                <span class="font-semibold text-sm text-center">{{ method.label }}</span>
            </button>
        </div>

        <!-- Lista de Pagos Editable -->
        <div v-if="payments.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-3 flex-grow mb-4">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 col-span-full mb-2">Pagos registrados:</label>
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
                                :class="{ 'p-invalid': !payment.bank_account_id && payment.amount > 0 }" />
                            <Button icon="pi pi-plus" rounded severity="secondary" outlined
                                class="!size-8 flex-shrink-0" @click="emit('add-account')"
                                v-tooltip.bottom="'Agregar cuenta'" />
                        </div>
                        <small v-if="!payment.bank_account_id && payment.amount > 0" class="p-error text-xs text-red-500">
                            Se requiere una cuenta para este tipo de pago.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN: CONFIGURACIÓN APARTADO (SOLO VISIBLE SI ES APARTADO) -->
        <div v-if="transactionType === 'apartado'" class="mb-6 p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg">
            <h4 class="text-sm font-semibold text-purple-800 dark:text-purple-300 mb-3 flex items-center gap-2">
                <i class="pi pi-calendar-clock"></i> Configuración del Apartado
            </h4>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-gray-600 dark:text-gray-400">Fecha límite para liquidar:</label>
                <DatePicker v-model="layawayExpirationDate" showIcon :minDate="new Date()" dateFormat="dd/mm/yy" class="w-full" />
                <small class="text-xs text-gray-500">El cliente debe pagar el total antes de esta fecha.</small>
            </div>
        </div>

        <!-- Espaciador -->
        <div v-if="payments.length === 0 && transactionType !== 'apartado'" class="flex-grow"></div>

        <!-- Botón de Finalización -->
        <div class="mt-4">
            <Message v-if="creditDeficit > 0" severity="warn" :closable="false" class="mb-3">
                El cliente no tiene suficiente crédito.
                Se excede por {{ formatCurrency(creditDeficit) }}.
                <br>
                <small>Crédito disponible: {{ formatCurrency(availableCredit) }}</small>
            </Message>

            <Button :label="finalizeButtonLabel" :disabled="isFinalizeButtonDisabled || props.loading" :loading="props.loading"
                @click="handleSubmit" icon="pi pi-check" class="w-full !py-3" />
        </div>
    </div>
</template>