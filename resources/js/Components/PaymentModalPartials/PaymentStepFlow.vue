<script setup>
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import PaymentMethodSelector from './PaymentMethodSelector.vue';

const props = defineProps({
    paymentMode: String,
    totalAmount: Number,
    client: Object,
    customers: Array,
    bankAccounts: Array,
    bankAccountOptions: Array,
    isCreditSale: Boolean,
    creditLimitExceeded: Boolean,
    requiredDownPayment: Number,
    amountToCreditFinal: Number,
    
    cashReceived: Number,
    amountToPay: Number,
    selectedAccountId: Number,
    paymentNotes: String,
    willMakeDownPayment: Boolean,
    downPaymentAmount: Number,
    downPaymentMethod: String,
    downPaymentBankAccountId: Number,
});

const emit = defineEmits([
    'update:cashReceived', 'update:amountToPay', 'update:selectedAccountId', 'update:paymentNotes',
    'update:willMakeDownPayment', 'update:downPaymentAmount', 'update:downPaymentMethod',
    'update:downPaymentBankAccountId',
    'select-method', 'submit', 'add-account', 'select-customer', 'create-customer', 'remove-client'
]);

const localCurrentStep = ref('selection');
const customerSearch = ref('');
const toast = useToast();

const paymentMethods = computed(() => {
    const methods = [
        { id: 'efectivo', label: 'Efectivo', icon: '/images/efectivo.webp' },
        { id: 'tarjeta', label: 'Tarjeta', icon: '/images/tarjeta.webp' },
        { id: 'transferencia', label: 'Transferencia', icon: '/images/transferencia.webp' },
        { id: 'credito', label: 'A crédito', icon: '/images/credito.webp' },
    ];
    
    // CORRECCIÓN: Si el modo es 'flexible', se excluye la opción de pago 'a crédito'.
    if (props.paymentMode === 'flexible') {
        return methods.filter(m => m.id !== 'credito');
    }

    return methods;
});

const handleMethodSelect = (method) => {
    localCurrentStep.value = method;

    if (props.paymentMode === 'strict') {
        if (method === 'efectivo') {
            emit('update:cashReceived', props.totalAmount);
        } else if (['tarjeta', 'transferencia'].includes(method)) {
            // Se pre-llena el 'amountToPay' para tarjeta y transferencia
            emit('update:amountToPay', props.totalAmount);
        }
    }

    if (method === 'credito' && !props.client) {
        localCurrentStep.value = 'credito-customer-selection';
    }
    emit('select-method', method);
};

const goBack = () => {
    localCurrentStep.value = 'selection';
    emit('select-method', null);
};

const handleCustomerSelect = (customer) => {
    emit('select-customer', customer);
    localCurrentStep.value = 'credito';
};

const handleRemoveClient = () => {
    emit('remove-client');
    localCurrentStep.value = 'credito-customer-selection';
};

const paymentAmountModel = computed({
    get: () => (localCurrentStep.value === 'efectivo' ? props.cashReceived : props.amountToPay),
    set: (value) => emit(localCurrentStep.value === 'efectivo' ? 'update:cashReceived' : 'update:amountToPay', value),
});

const formatCurrency = (value) => {
    if (typeof value !== 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        toast.add({ severity: 'info', summary: 'Copiado', detail: 'Dato copiado al portapapeles.', life: 2000 });
    });
};

const abonoOptions = [ { label: 'Sin anticipo', value: false }, { label: 'Agregar anticipo', value: true } ];

const filteredCustomers = computed(() => {
    if (!customerSearch.value) return props.customers;
    return props.customers.filter(c =>
        c.name.toLowerCase().includes(customerSearch.value.toLowerCase()) ||
        c.phone?.includes(customerSearch.value)
    );
});

const amountRemaining = computed(() => props.totalAmount - (props.cashReceived || 0));
const change = computed(() => (props.cashReceived || 0) - props.totalAmount);

const canFinalize = computed(() => {
    if (props.paymentMode === 'strict') {
        if (localCurrentStep.value === 'efectivo') return amountRemaining.value <= 0.01;
        if (localCurrentStep.value === 'credito') return props.isCreditSale;
        return true;
    }
    if (props.paymentMode === 'flexible') {
        if (!localCurrentStep.value) return false;
        if (localCurrentStep.value === 'efectivo') return props.cashReceived > 0.001;
        if (['tarjeta', 'transferencia'].includes(localCurrentStep.value)) return props.amountToPay > 0.001 && !!props.selectedAccountId;
    }
    return false;
});

const balanceCoversTotal = computed(() => props.client && props.client.balance > 0);
const isDownPaymentForced = computed(() => props.client && props.totalAmount > (Math.max(0, props.client.balance) + (props.client.available_credit || 0)));

watch(isDownPaymentForced, (isForced) => {
    if (isForced) {
        emit('update:willMakeDownPayment', true);
    }
});

watch(() => props.paymentMode, () => {
    localCurrentStep.value = 'selection';
}, { immediate: true });

</script>

<template>
    <div>
        <PaymentMethodSelector
            v-if="localCurrentStep === 'selection'"
            :payment-methods="paymentMethods"
            :client="client"
            :is-credit-sale="isCreditSale"
            @select="handleMethodSelect"
        />
        <div v-if="['efectivo', 'tarjeta', 'transferencia'].includes(localCurrentStep)" class="min-h-[350px] flex flex-col">
            <div class="flex-grow">
                <div class="flex items-center gap-3 mb-4">
                    <img :src="paymentMethods.find(m => m.id === localCurrentStep)?.icon" :alt="localCurrentStep" class="h-8">
                    <h3 class="text-xl font-semibold">{{ paymentMethods.find(m => m.id === localCurrentStep)?.label }}</h3>
                </div>
                <a @click="goBack" class="text-sm text-primary cursor-pointer">&larr; Volver</a>
                
                <div v-if="localCurrentStep === 'efectivo' || paymentMode === 'flexible'" class="mb-4 mt-3">
                    <label class="text-sm font-medium">{{ localCurrentStep === 'efectivo' ? 'Monto recibido' : 'Monto a pagar' }}</label>
                    <InputNumber v-model="paymentAmountModel" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1 text-2xl" inputClass="!py-3" />
                </div>

                <div v-if="localCurrentStep === 'efectivo'">
                    <div v-if="change >= 0 && paymentMode === 'strict'" class="bg-green-100 text-green-800 p-4 rounded-lg text-center">
                        <p class="text-lg">Su cambio</p>
                        <p class="text-4xl font-bold">{{ formatCurrency(change) }}</p>
                    </div>
                    <div v-else-if="amountRemaining > 0.01 && paymentMode === 'strict'" class="bg-red-100 text-red-800 p-4 rounded-lg text-center">
                        <p class="text-lg">Faltante</p>
                        <p class="text-4xl font-bold">{{ formatCurrency(amountRemaining) }}</p>
                        <div v-if="client" class="text-center mt-2">
                            <a @click="handleMethodSelect('credito')" class="text-sm text-blue-500 hover:underline cursor-pointer">
                                ¿Pagar diferencia a crédito?
                            </a>
                        </div>
                    </div>
                </div>

                <div v-if="['tarjeta', 'transferencia'].includes(localCurrentStep)">
                    <div v-if="bankAccounts.length > 0">
                        <div v-if="localCurrentStep === 'tarjeta'" class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg text-center mb-6">
                            <i class="pi pi-credit-card !text-2xl text-gray-500 mb-2"></i>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Procesa el pago en la terminal bancaria externa. Este paso solo registra la venta en el sistema.</p>
                        </div>
                        <div v-if="localCurrentStep === 'transferencia'" class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg text-center mb-6">
                            <i class="pi pi-arrows-h !text-2xl text-gray-500 mb-2"></i>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Indica al cliente que realice la transferencia a la cuenta de tu elección.</p>
                        </div>
                        <div class="mb-4">
                            <label for="bank-account" class="text-sm font-medium">Cuenta destino</label>
                            <Select :model-value="selectedAccountId" @update:modelValue="emit('update:selectedAccountId', $event)" :options="bankAccountOptions" optionLabel="label" optionValue="value" placeholder="Selecciona una cuenta" class="w-full mt-1" />
                        </div>
                        <div v-if="localCurrentStep === 'transferencia' && selectedAccountId" class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg space-y-2 text-sm mb-4">
                            <div v-for="detail in [{label: 'Beneficiario', value: bankAccounts.find(b => b.id === selectedAccountId)?.owner_name}, {label: 'Banco', value: bankAccounts.find(b => b.id === selectedAccountId)?.bank_name}, {label: 'CLABE', value: bankAccounts.find(b => b.id === selectedAccountId)?.clabe}]" :key="detail.label">
                                <div v-if="detail.value" class="flex justify-between items-center">
                                    <span>{{ detail.label }}:</span>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono">{{ detail.value }}</span>
                                        <Button icon="pi pi-copy" text rounded size="small" @click="copyToClipboard(detail.value)"/>
                                    </div>
                                </div>
                           </div>
                        </div>
                        <div class="mb-4">
                            <label for="payment-notes" class="text-sm font-medium">Notas (opcional)</label>
                            <InputText :model-value="paymentNotes" @update:modelValue="emit('update:paymentNotes', $event)" placeholder="Ej. Aprobación 568393" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-else class="text-center">
                        <i class="pi pi-exclamation-triangle !text-4xl text-yellow-500 mb-4"></i>
                        <p class="font-semibold">No hay cuentas bancarias</p>
                        <p class="text-sm text-gray-500 mb-4">
                            Para aceptar este tipo de pago, debe registrar una cuenta. <br>
                            Si no eres administrador de la suscripción, pidele a uno que registre una cuenta bancaria y 
                            te la asigne para poder continuar con este proceso.
                        </p>
                        <Button v-if="$page.props.auth.is_subscription_owner" label="Registrar Cuenta" icon="pi pi-plus" @click="emit('add-account')" />
                    </div>
                </div>
            </div>
            <div class="mt-8" v-if="localCurrentStep === 'efectivo' || bankAccounts.length > 0">
                <Button :label="paymentMode === 'flexible' ? 'Registrar Abono' : 'Finalizar Venta'" icon="pi pi-check" @click="emit('submit')" :disabled="!canFinalize" class="w-full !py-3" />
            </div>
        </div>

        <div v-if="localCurrentStep.startsWith('credito')">
            <div v-if="localCurrentStep === 'credito-customer-selection'" class="min-h-[350px] flex flex-col">
                <div class="flex-grow">
                    <h3 class="text-xl font-semibold">Asignar cliente</h3>
                    <a @click="goBack" class="text-sm text-primary cursor-pointer">&larr; Volver</a>
                    <Message severity="info" :closable="false" class="my-2">Se requiere un cliente para venta a crédito.</Message>
                    <div class="mt-4">
                        <span class="p-input-icon-left w-full"><InputText v-model="customerSearch" placeholder="Buscar cliente..." class="w-full"/></span>
                    </div>
                    <div class="border rounded-md mt-2 overflow-y-auto max-h-[150px]">
                        <ul v-if="filteredCustomers.length > 0" class="divide-y">
                            <li v-for="c in filteredCustomers" :key="c.id" @click="handleCustomerSelect(c)" class="p-3 hover:bg-gray-100 cursor-pointer">
                                <p class="font-semibold text-sm">{{ c.name }}</p><p class="text-xs text-gray-500">{{ c.phone }}</p>
                            </li>
                        </ul>
                        <p v-else class="text-center text-sm text-gray-500 p-4">No se encontraron clientes.</p>
                    </div>
                </div>
                <div class="mt-4"><Button label="Crear cliente rápido" icon="pi pi-plus" @click="emit('create-customer')" severity="secondary" class="w-full" /></div>
            </div>

            <div v-if="localCurrentStep === 'credito'" class="min-h-[350px] flex flex-col">
                 <div class="flex-grow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3"><img src="/images/credito.webp" alt="Credito" class="h-8"><h3 class="text-xl font-semibold">Venta a crédito</h3></div>
                        <Button @click="handleRemoveClient" icon="pi pi-user-minus" text rounded severity="danger" v-tooltip.left="'Quitar cliente'" />
                    </div>
                    <a @click="goBack" class="text-sm text-primary cursor-pointer">&larr; Volver</a>
                    
                    <Message v-if="balanceCoversTotal" severity="success" :closable="false" class="!my-3">Se utilizará el saldo a favor ({{ formatCurrency(client.balance) }}) para esta venta.</Message>
                    
                    <div>
                         <label class="text-sm font-medium block my-2">Anticipo</label>
                         <SelectButton :model-value="willMakeDownPayment" @update:modelValue="emit('update:willMakeDownPayment', $event)" :options="abonoOptions" optionLabel="label" optionValue="value" class="mt-1" :optionDisabled="(option) => (option.value === false && isDownPaymentForced) || balanceCoversTotal" />
                    </div>
                    <div v-if="willMakeDownPayment" class="mt-4 space-y-4 pt-4 border-t">
                        <div>
                            <label class="text-sm font-medium">Monto del anticipo</label>
                            <InputNumber :model-value="downPaymentAmount" @update:modelValue="emit('update:downPaymentAmount', $event)" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                            <small v-if="creditLimitExceeded" class="text-red-500 mt-1 block">Anticipo mínimo requerido: {{ formatCurrency(requiredDownPayment) }}</small>
                        </div>
                         <div>
                            <label class="text-sm font-medium">Método de pago del anticipo</label>
                            <Select :model-value="downPaymentMethod" @update:modelValue="emit('update:downPaymentMethod', $event)" :options="['efectivo', 'tarjeta', 'transferencia']" placeholder="Selecciona método" class="w-full mt-1" />
                        </div>
                        <!-- --- NUEVO: Selector de cuenta para el abono --- -->
                        <div v-if="['tarjeta', 'transferencia'].includes(downPaymentMethod)">
                            <label class="text-sm font-medium">Cuenta destino del anticipo</label>
                            <Select 
                                :model-value="downPaymentBankAccountId" 
                                @update:modelValue="emit('update:downPaymentBankAccountId', $event)" 
                                :options="bankAccountOptions" 
                                optionLabel="label" 
                                optionValue="value" 
                                placeholder="Selecciona una cuenta" 
                                class="w-full mt-1" 
                            />
                        </div>
                    </div>
                </div>
                <div class="mt-8"><Button :label="`Guardar a crédito (${formatCurrency(amountToCreditFinal)})`" icon="pi pi-check" @click="emit('submit')" :disabled="!isCreditSale" class="w-full !py-3" /></div>
            </div>
        </div>
    </div>
</template>