<script setup>
import { ref, computed, watch } from 'vue';

// --- Props ---
const props = defineProps({
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    // 'contado', 'credito', 'apartado', 'balance'
    transactionType: { type: String, required: true },
    bankAccounts: { type: Array, default: () => [] },
    bankAccountOptions: { type: Array, default: () => [] },
});

// --- Emits ---
const emit = defineEmits([
    'submit', // Envía el payload final
    'add-account', // Abre modal de crear cuenta
]);

// --- Formateador ---
const formatCurrency = (value) => {
    if (typeof value !== 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

// --- Estado Interno: Formulario para "Agregar Pago" ---
const amountToAdd = ref(null);
const methodToAdd = ref('efectivo');
const accountToAdd = ref(null);
const notesToAdd = ref('');

// --- Estado Interno: Lista de Pagos y Saldo ---
const payments = ref([]);
const useBalance = ref(false);

const paymentMethodOptions = [
    { id: 'efectivo', label: 'Efectivo', icon: 'pi pi-money-bill' },
    { id: 'tarjeta', label: 'Tarjeta', icon: 'pi pi-credit-card' },
    { id: 'transferencia', label: 'Transferencia', icon: 'pi pi-arrows-h' },
];

// --- Lógica de Pagos Computada ---
const totalAddedPayments = computed(() => payments.value.reduce((sum, p) => sum + p.amount, 0));

const clientBalanceToUse = computed(() => (props.client && props.client.balance > 0) ? props.client.balance : 0);

const effectiveBalanceUsed = computed(() => {
    if (!useBalance.value || clientBalanceToUse.value <= 0 || props.transactionType === 'balance') return 0;
    // El saldo no puede cubrir más que el total de la venta actual
    const baseAmount = props.transactionType === 'balance' ? 0 : props.totalAmount;
    return Math.min(clientBalanceToUse.value, baseAmount);
});

const totalPaid = computed(() => totalAddedPayments.value + effectiveBalanceUsed.value);

const remainingAmount = computed(() => {
    const baseAmount = props.transactionType === 'balance' ? 0 : props.totalAmount;
    return baseAmount - totalPaid.value;
});

// --- LÓGICA DE CRÉDITO ---
const availableCredit = computed(() => props.client?.available_credit || 0);

/**
 * Calcula el déficit de crédito.
 * Es positivo si el monto restante es MAYOR que el crédito disponible.
 */
const creditDeficit = computed(() => {
    // Esta lógica solo aplica al modo crédito
    if (props.transactionType !== 'credito') return 0;

    // Si el cliente no tiene crédito, el déficit es el total restante
    if (availableCredit.value <= 0) {
        return remainingAmount.value;
    }

    // Calcula el déficit
    const deficit = remainingAmount.value - availableCredit.value;

    // Retorna el déficit solo si es positivo (si se excede)
    return deficit > 0.01 ? deficit : 0;
});

// --- Watchers para UX ---
watch(remainingAmount, (newVal) => {
    // Auto-rellena el campo de monto con el restante
    if (props.transactionType !== 'balance') {
        const remaining = newVal > 0.01 ? newVal : null;
        amountToAdd.value = remaining ? parseFloat(remaining.toFixed(2)) : null;
    }
}, { immediate: true });

watch(methodToAdd, (newVal) => {
    // Resetea la cuenta si se cambia a efectivo
    if (newVal === 'efectivo') {
        accountToAdd.value = null;
        notesToAdd.value = '';
    }
});

// --- Métodos de Acción ---
const addPayment = () => {
    // Validaciones
    if (!amountToAdd.value || amountToAdd.value <= 0) return;
    if (['tarjeta', 'transferencia'].includes(methodToAdd.value) && !accountToAdd.value) {
        // Podrías añadir un toast aquí
        alert('Por favor, selecciona una cuenta destino para este método de pago.');
        return;
    }

    // Añade el pago a la lista
    payments.value.push({
        id: crypto.randomUUID(), // key para v-for
        amount: amountToAdd.value,
        method: methodToAdd.value,
        bank_account_id: accountToAdd.value,
        notes: notesToAdd.value,
        // Info para UI
        methodLabel: paymentMethodOptions.find(m => m.id === methodToAdd.value).label,
        accountLabel: accountToAdd.value ? props.bankAccountOptions.find(o => o.value === accountToAdd.value)?.label : null
    });

    // Resetear formulario
    methodToAdd.value = 'efectivo';
    accountToAdd.value = null;
    notesToAdd.value = '';
    // amountToAdd se resetea automáticamente por el watcher de remainingAmount
};

const removePayment = (id) => {
    payments.value = payments.value.filter(p => p.id !== id);
};

const handleSubmit = () => {
    emit('submit', {
        payments: payments.value,
        use_balance: useBalance.value
    });
};

// --- Lógica del Botón Finalizar (Computada) ---
const finalizeButtonLabel = computed(() => {
    if (props.transactionType === 'balance') return 'Registrar Abono';
    if (props.transactionType === 'apartado') return 'Crear Apartado';
    if (props.transactionType === 'credito') {
        if (remainingAmount.value > 0.01) {
            return `Guardar a Crédito (${formatCurrency(remainingAmount.value)})`;
        }
        return 'Finalizar (Pago Completo)'; // Pagó todo incluso en modo crédito
    }
    // 'contado'
    return 'Finalizar Venta';
});

const isFinalizeButtonDisabled = computed(() => {
    if (props.transactionType === 'balance') {
        // Debe haber al menos un pago agregado (no se usa saldo)
        return totalAddedPayments.value <= 0;
    }
    if (props.transactionType === 'apartado') {
        // Debe haber un anticipo (pagos + saldo)
        return totalPaid.value <= 0;
    }
    if (props.transactionType === 'contado') {
        // El restante debe ser 0 (o menor, por si hay cambio)
        return remainingAmount.value > 0.01;
    }
    if (props.transactionType === 'credito') {
        // Siempre habilitado si hay un cliente (el modal padre ya lo validó)
        // y deshabilitar si hay un déficit de crédito.
        return creditDeficit.value > 0;
    }
    return false;
});

// --- Configuración Inicial ---
if (props.transactionType === 'balance') {
    amountToAdd.value = null; // Empezar vacío en modo abono
}
</script>

<template>
    <div class="flex flex-col min-h-[400px]">
        <!-- Resumen de Montos -->
        <div class="space-y-2 text-sm">
            <div v-if="transactionType !== 'balance'" class="flex justify-between font-semibold">
                <span class="text-gray-600 dark:text-gray-300">Total de Venta:</span>
                <span class="text-gray-800 dark:text-gray-100">{{ formatCurrency(totalAmount) }}</span>
            </div>
            <div v-if="effectiveBalanceUsed > 0" class="flex justify-between text-green-600">
                <span class="pl-4">Saldo Usado:</span>
                <span>-{{ formatCurrency(effectiveBalanceUsed) }}</span>
            </div>
            <div v-for="p in payments" :key="p.id" class="flex justify-between text-blue-600">
                <span class="pl-4">Pago ({{ p.methodLabel }}):</span>
                <span>-{{ formatCurrency(p.amount) }}</span>
            </div>
            <div class="flex justify-between text-lg font-bold pt-2 border-t">
                <span class="text-gray-600 dark:text-gray-300">
                    {{ transactionType === 'balance' ? 'Total Abonado:' : 'Restante:' }}
                </span>
                <span :class="transactionType === 'balance' ? 'text-green-600' : 'text-gray-800 dark:text-gray-100'">
                    {{ formatCurrency(transactionType === 'balance' ? totalAddedPayments : remainingAmount) }}
                </span>
            </div>
        </div>

        <!-- Toggle de Saldo -->
        <div v-if="clientBalanceToUse > 0 && transactionType !== 'balance'"
            class="my-4 p-3 bg-green-50 dark:bg-green-900/30 rounded-lg flex items-center justify-between">
            <div class="flex items-center">
                <i class="pi pi-wallet mr-2 text-green-600"></i>
                <div>
                    <span class="text-sm font-semibold text-green-800 dark:text-green-200">Usar saldo a favor</span>
                    <p class="text-xs text-green-600 dark:text-green-300 m-0">
                        Disponible: {{ formatCurrency(clientBalanceToUse) }}
                    </p>
                </div>
            </div>
            <InputSwitch v-model="useBalance" />
        </div>

        <!-- Lista de Pagos Agregados -->
        <div v-if="payments.length > 0" class="my-4 space-y-2">
            <p class="text-xs font-semibold text-gray-500">Pagos Registrados</p>
            <div v-for="(p, index) in payments" :key="p.id"
                class="flex items-center justify-between p-2 bg-gray-100 dark:bg-gray-700 rounded-lg">
                <div class="flex items-center">
                    <i :class="paymentMethodOptions.find(m => m.id === p.method).icon" class="mr-2 text-primary"></i>
                    <div>
                        <span class="text-sm font-semibold">{{ formatCurrency(p.amount) }}</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400 m-0">
                            {{ p.methodLabel }}
                            <span v-if="p.accountLabel"> &rarr; {{ p.accountLabel.split(' - ')[0] }}</span>
                        </p>
                    </div>
                </div>
                <Button icon="pi pi-times" text rounded severity="danger" @click="removePayment(p.id)" />
            </div>
        </div>

        <!-- Formulario "Agregar Pago" -->
        <div class="mt-4 border-t pt-4 space-y-3 flex-grow">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                {{ transactionType === 'balance' ? 'Registrar Abono' : 'Agregar Pago' }}
            </p>
            <div>
                <label class="text-xs font-medium">Monto</label>
                <InputNumber v-model="amountToAdd" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
            </div>
            <div>
                <label class="text-xs font-medium">Método</label>
                <SelectButton v-model="methodToAdd" :options="paymentMethodOptions" optionValue="id" optionLabel="label"
                    class="w-full mt-1">
                    <template #option="slotProps">
                        <div class="flex items-center justify-center w-full">
                            <i :class="slotProps.option.icon" class="mr-2"></i>
                            <span>{{ slotProps.option.label }}</span>
                        </div>
                    </template>
                </SelectButton>
            </div>
            <div v-if="['tarjeta', 'transferencia'].includes(methodToAdd)">
                <label class="text-xs font-medium">Cuenta Destino</label>
                <Dropdown v-model="accountToAdd" :options="bankAccountOptions" optionLabel="label" optionValue="value"
                    placeholder="Selecciona una cuenta" class="w-full mt-1">
                    <template v-if="!bankAccounts || bankAccounts.length === 0" #footer>
                        <div class="p-3 text-center">
                            <p class="text-sm">No hay cuentas bancarias.</p>
                            <Button label="Agregar Cuenta" icon="pi pi-plus" size="small"
                                @click="emit('add-account')" />
                        </div>
                    </template>
                </Dropdown>
            </div>
            <div v-if="['tarjeta', 'transferencia'].includes(methodToAdd) || transactionType === 'balance'">
                <label class="text-xs font-medium">Notas (Opcional)</label>
                <InputText v-model="notesToAdd" placeholder="Ej. Aprobación 123, Abono..." class="w-full mt-1" />
            </div>

            <Button label="Agregar" icon="pi pi-plus" @click="addPayment" :disabled="!amountToAdd || amountToAdd <= 0"
                class="w-full" severity="secondary" />
        </div>

        <!-- --- MENSAJE DE FEEDBACK --- -->
        <Message v-if="creditDeficit > 0" severity="warn" :closable="false" class="mb-3">
            El cliente no tiene suficiente crédito.
            Se excede por {{ formatCurrency(creditDeficit) }}.
            <br>
            <small>Crédito disponible: {{ formatCurrency(availableCredit) }}</small>
        </Message>

        <!-- Botón de Finalización -->
        <div class="mt-8">
            <Button :label="finalizeButtonLabel" :disabled="isFinalizeButtonDisabled" @click="handleSubmit"
                icon="pi pi-check" class="w-full !py-3" />
        </div>
    </div>
</template>