<script setup>
import { ref, computed } from 'vue';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    amountToPay: Number,
    selectedAccountId: Number,
    paymentNotes: String,
    bankAccounts: Array,
    bankAccountOptions: Array,
});

const emit = defineEmits([
    'update:amountToPay',
    'update:selectedAccountId',
    'update:paymentNotes',
    'select-method',
    'submit',
    'add-account'
]);

const toast = useToast();
const currentSubStep = ref('entry'); // 'entry', 'method-details'
const selectedMethod = ref(null);

const paymentMethods = [
    { id: 'efectivo', label: 'Efectivo', icon: '/images/efectivo.webp' },
    { id: 'tarjeta', label: 'Tarjeta', icon: '/images/tarjeta.webp' },
    { id: 'transferencia', label: 'Transferencia', icon: '/images/transferencia.webp' },
];

const selectedBankAccount = computed(() => {
    if (!props.selectedAccountId) return null;
    return props.bankAccounts.find(acc => acc.id === props.selectedAccountId);
});

const handleSelectMethod = (method) => {
    selectedMethod.value = method;
    emit('select-method', method);
    currentSubStep.value = 'method-details';
};

const goBack = () => {
    currentSubStep.value = 'entry';
    emit('select-method', null);
    selectedMethod.value = null;
};

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        toast.add({ severity: 'info', summary: 'Copiado', detail: 'Dato copiado al portapapeles.', life: 2000 });
    });
};

const formatCurrency = (value) => {
    if (typeof value !== 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};
</script>

<template>
    <div class="min-h-[350px] flex flex-col">
        <div v-if="currentSubStep === 'entry'">
            <div class="mb-4">
                <label for="balance-amount-to-pay" class="text-sm font-medium">Monto a abonar</label>
                <InputNumber id="balance-amount-to-pay" :modelValue="amountToPay" @update:modelValue="emit('update:amountToPay', $event)" mode="currency" currency="MXN" locale="es-MX"
                    class="w-full mt-1 text-2xl" inputClass="!py-3" />
            </div>
            <h3 class="text-lg font-semibold text-center my-4">Selecciona un método de pago</h3>
            <div class="grid grid-cols-2 gap-4">
                <button v-for="method in paymentMethods" :key="method.id" @click="handleSelectMethod(method.id)"
                    :disabled="!amountToPay || amountToPay <= 0"
                    class="flex flex-col items-center justify-center p-4 border rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <img :src="method.icon" :alt="method.label" class="h-12 w-12 object-contain mb-2">
                    <span class="font-semibold text-sm text-gray-700 dark:text-gray-300">{{ method.label }}</span>
                </button>
            </div>
        </div>
        <div v-else class="flex-grow flex flex-col">
            <div class="flex-grow">
                <a @click="goBack" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Cambiar monto o método</a>
                
                <!-- INICIO DE CORRECCIÓN: Lógica separada por método -->
                <div v-if="selectedMethod === 'efectivo'">
                    <Message severity="info">El monto de {{ formatCurrency(amountToPay) }} se registrará como un ingreso de efectivo en la sesión de caja actual.</Message>
                </div>
                
                <div v-else> <!-- Tarjeta o Transferencia -->
                    <div v-if="bankAccounts.length > 0">
                        <div class="mb-4">
                            <label for="bank-account" class="text-sm font-medium">Cuenta destino</label>
                            <Dropdown :modelValue="selectedAccountId" @update:modelValue="emit('update:selectedAccountId', $event)" :options="bankAccountOptions" optionLabel="label" optionValue="value" placeholder="Selecciona una cuenta" class="w-full mt-1" />
                        </div>
                        <div class="mb-4">
                            <label for="payment-notes" class="text-sm font-medium">Notas (opcional)</label>
                            <InputText :modelValue="paymentNotes" @update:modelValue="emit('update:paymentNotes', $event)" placeholder="Ej. Abono mensual" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-else class="flex-grow flex flex-col items-center justify-center text-center">
                        <i class="pi pi-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                        <p class="font-semibold">No hay cuentas bancarias</p>
                        <p class="text-sm text-gray-500 mb-4">Para aceptar este tipo de pago, debe registrar una cuenta.</p>
                        <Button label="Registrar Nueva Cuenta" icon="pi pi-plus" @click="emit('add-account')" />
                    </div>
                </div>
                <!-- FIN DE CORRECCIÓN -->
            </div>
            
            <div class="mt-8" v-if="selectedMethod === 'efectivo' || (['tarjeta', 'transferencia'].includes(selectedMethod) && bankAccounts.length > 0)">
                <Button label="Registrar Abono" icon="pi pi-check" @click="emit('submit')" :disabled="amountToPay <= 0" class="w-full !py-3" />
            </div>
        </div>
    </div>
</template>