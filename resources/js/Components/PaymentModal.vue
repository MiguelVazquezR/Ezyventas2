<script setup>
import { ref, computed, watch, nextTick, onMounted, inject } from 'vue';
import axios from 'axios';
import AddBankAccountModal from '@/Components/AddBankAccountModal.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    visible: Boolean,
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    paymentMode: {
        type: String,
        default: 'strict', // 'strict', 'flexible', 'balance'
        validator: (value) => ['strict', 'flexible', 'balance'].includes(value)
    }
});

const emit = defineEmits(['update:visible', 'submit', 'update:client', 'customerCreated']);
const toast = useToast();
const activeSession = inject('activeSession', ref(null));


const currentStep = ref('selection');
const selectedMethod = ref(null);
const payments = ref([]);
const showAddBankAccountModal = ref(false);
const showCreateCustomerModal = ref(false);

const customerSearch = ref('');
const filteredCustomers = computed(() => {
    if (!customerSearch.value) {
        return props.customers;
    }
    return props.customers.filter(c =>
        c.name.toLowerCase().includes(customerSearch.value.toLowerCase()) ||
        c.phone?.includes(customerSearch.value)
    );
});


const cashReceived = ref(null);
const cashInput = ref(null);
const bankAccounts = ref([]);
const selectedAccountId = ref(null);
const paymentNotes = ref('');
const amountToPay = ref(0);

const paymentAmountModel = computed({
    get() {
        return currentStep.value === 'efectivo' ? cashReceived.value : amountToPay.value;
    },
    set(newValue) {
        if (currentStep.value === 'efectivo') {
            cashReceived.value = newValue;
        } else {
            amountToPay.value = newValue;
        }
    }
});

const willMakeDownPayment = ref(false);
const downPaymentAmount = ref(null);
const downPaymentMethod = ref('efectivo');

onMounted(() => {
    fetchBankAccounts();
});

const fetchBankAccounts = async () => {
    try {
        const response = await axios.get(route('branch-bank-accounts'));
        bankAccounts.value = response.data;
        if (bankAccounts.value.length === 1) {
            selectedAccountId.value = bankAccounts.value[0].id;
        }
    } catch (error) {
        console.error("Error fetching bank accounts:", error);
    }
};

const onBankAccountAdded = () => {
    showAddBankAccountModal.value = false;
    toast.add({ severity: 'success', summary: 'Éxito', detail: 'Cuenta bancaria registrada.', life: 3000 });
    fetchBankAccounts();
};

const bankAccountOptions = computed(() => {
    return bankAccounts.value.map(acc => ({
        label: `${acc.bank_name} - ${acc.account_name} (...${(acc.card_number || acc.account_number || 'N/A').slice(-4)})`,
        value: acc.id
    }));
});

const selectedBankAccount = computed(() => {
    if (!selectedAccountId.value) return null;
    return bankAccounts.value.find(acc => acc.id === selectedAccountId.value);
});


const totalPaid = computed(() => {
    if (props.paymentMode === 'balance') return amountToPay.value;
    if (selectedMethod.value === 'efectivo') return cashReceived.value || 0;
    if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) {
        return props.paymentMode === 'flexible' ? amountToPay.value : props.totalAmount;
    }
    if (selectedMethod.value === 'credito') return downPaymentAmount.value || 0;
    return 0;
});

const amountRemaining = computed(() => props.totalAmount - totalPaid.value);
const change = computed(() => -amountRemaining.value);
const clientBalance = computed(() => props.client?.balance || 0);
const balanceUsedForSale = computed(() => {
    const positiveBalance = Math.max(0, clientBalance.value);
    return Math.min(props.totalAmount, positiveBalance);
});
const amountAfterBalance = computed(() => props.totalAmount - balanceUsedForSale.value);
const amountToCreditFinal = computed(() => {
    if (willMakeDownPayment.value && downPaymentAmount.value > 0) {
        return amountAfterBalance.value - downPaymentAmount.value;
    }
    return amountAfterBalance.value;
});
const futureDebt = computed(() => {
    const currentDebt = Math.abs(Math.min(0, clientBalance.value));
    return currentDebt + amountToCreditFinal.value;
});
const creditLimitExceeded = computed(() => futureDebt.value > (props.client?.credit_limit || 0));
const requiredDownPayment = computed(() => {
    if (!creditLimitExceeded.value) return 0;
    return futureDebt.value - (props.client?.credit_limit || 0);
});
const isCreditSale = computed(() => {
    if (!props.client) return false;
    return amountToCreditFinal.value <= (props.client?.available_credit || 0);
});

const canFinalize = computed(() => {
    if (props.paymentMode === 'balance') {
        return amountToPay.value > 0.001 && selectedMethod.value;
    }
    if (props.paymentMode === 'flexible') {
        if (!selectedMethod.value) return false;
        if (selectedMethod.value === 'efectivo') return cashReceived.value > 0.001;
        if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) return amountToPay.value > 0.001 && !!selectedAccountId.value;
    }
    if (selectedMethod.value === 'efectivo') return amountRemaining.value <= 0.01;
    if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) return !!selectedAccountId.value;
    if (selectedMethod.value === 'credito') {
        if (!isCreditSale.value) return false;
        if (creditLimitExceeded.value) {
            return willMakeDownPayment.value && (downPaymentAmount.value || 0) >= requiredDownPayment.value;
        }
        return true;
    }
    return false;
});

const selectPaymentMethod = (method) => {
    selectedMethod.value = method;

    // --- INICIO DE CORRECCIÓN ---
    // Solo se resetean los montos si no estamos en modo 'balance'
    if (props.paymentMode !== 'balance') {
        cashReceived.value = props.totalAmount > 0 ? props.totalAmount : null;
        amountToPay.value = props.totalAmount > 0 ? props.totalAmount : null;
    }
    // --- FIN DE CORRECCIÓN ---

    currentStep.value = method;
    if (method === 'efectivo') {
        nextTick(() => cashInput.value?.$el.focus());
    } else if (method === 'credito') {
        if (!props.client) currentStep.value = 'credit-customer-selection';
    }
};

const handleCustomerSelectInModal = (customer) => {
    emit('update:client', customer);
    nextTick(() => { currentStep.value = 'credit'; });
};
const handleCustomerCreatedInModal = (newCustomer) => {
    emit('customerCreated', newCustomer);
    emit('update:client', newCustomer);
    showCreateCustomerModal.value = false;
    nextTick(() => { currentStep.value = 'credit'; });
};
const removeClient = () => {
    emit('update:client', null);
    currentStep.value = 'credit-customer-selection';
    willMakeDownPayment.value = false;
    downPaymentAmount.value = null;
};

const goBackToSelection = () => {
    if (props.paymentMode === 'balance') {
        currentStep.value = 'balance-mode-entry';
    } else {
        currentStep.value = 'selection';
    }
    selectedMethod.value = null;
    cashReceived.value = null;
    // No resetear amountToPay aquí, se hace en el watch
    selectedAccountId.value = bankAccounts.value.find(acc => acc.is_favorite)?.id || (bankAccounts.value.length === 1 ? bankAccounts.value[0].id : null);
    paymentNotes.value = '';
    payments.value = [];
    willMakeDownPayment.value = false;
    downPaymentAmount.value = null;
};

watch(() => props.visible, (newVal) => {
    if (newVal) {
        amountToPay.value = 0; // Siempre resetear el monto al abrir
        goBackToSelection();
        if (props.paymentMode === 'balance') {
            if (props.client?.balance < 0) {
                amountToPay.value = Math.abs(props.client.balance);
            }
        }
    }
});

const submitForm = () => {
    payments.value = [];
    let amount = 0;

    if (props.paymentMode === 'balance') {
        amount = amountToPay.value;
    } else if (selectedMethod.value === 'credito') {
        if (willMakeDownPayment.value && downPaymentAmount.value > 0) {
            payments.value.push({
                amount: downPaymentAmount.value,
                method: downPaymentMethod.value,
                notes: 'Abono inicial en venta a crédito'
            });
        }
    } else {
        if (selectedMethod.value === 'efectivo') {
            amount = cashReceived.value;
        } else {
            amount = amountToPay.value;
        }
        
        const finalAmount = props.paymentMode !== 'strict' ? Math.min(amount, props.totalAmount) : amount;
        
        if (finalAmount > 0) {
             const paymentPayload = {
                amount: finalAmount,
                method: selectedMethod.value
            };
            if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) {
                paymentPayload.bank_account_id = selectedAccountId.value;
                paymentPayload.notes = paymentNotes.value;
            }
            payments.value.push(paymentPayload);
        }
    }
    
    if (props.paymentMode === 'balance' && amount > 0) {
        // Evita duplicados, la lógica de arriba ya no aplica para balance, así que esta es la única
        payments.value = [{
            amount: amount,
            method: selectedMethod.value,
            notes: paymentNotes.value,
            bank_account_id: selectedAccountId.value
        }];
    }

    if (payments.value.length === 0 && selectedMethod.value !== 'credito') {
         toast.add({ severity: 'warn', summary: 'Monto Inválido', detail: 'El monto a pagar debe ser mayor a cero.', life: 4000 });
         return;
    }
    
    emit('submit', { 
        payments: payments.value,
        cash_register_session_id: activeSession.value?.id 
    });
    emit('update:visible', false);
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

const paymentMethods = computed(() => {
    const allMethods = [
        { id: 'efectivo', label: 'Efectivo', icon: '/images/efectivo.png' },
        { id: 'tarjeta', label: 'Tarjeta', icon: '/images/tarjeta.png' },
        { id: 'transferencia', label: 'Transferencia', icon: '/images/transferencia.png' },
        { id: 'credito', label: 'A crédito', icon: '/images/credito.png' },
    ];
    if (props.paymentMode === 'balance') {
        return allMethods.filter(m => m.id !== 'credito');
    }
    return allMethods;
});

const abonoOptions = [
    { label: 'Sin abono', value: false },
    { label: 'Agregar abono', value: true }
]
</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', false)" modal :header="paymentMode === 'balance' ? 'Abonar a Saldo' : 'Procesar Pago'"
        :style="{ width: '50rem' }" :draggable="false">
        <Toast />
        <div class="grid grid-cols-2">
            <!-- Columna Izquierda Fija -->
            <div class="col-span-1 bg-gray-50 dark:bg-gray-800 p-8 flex flex-col justify-center items-center rounded-l-lg min-h-[500px]">
                <p class="text-lg font-semibold text-gray-600 dark:text-gray-300">
                    {{ paymentMode === 'balance' ? 'MONTO A ABONAR' : 'SALDO PENDIENTE' }}
                </p>
                <p class="text-6xl font-bold text-gray-800 dark:text-gray-100 mt-4 break-all">
                     {{ formatCurrency(paymentMode === 'balance' ? (amountToPay || 0) : totalAmount) }}
                </p>
                <div v-if="client" class="mt-8 w-full border-t pt-4 text-center">
                     <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ client.name }}</p>
                </div>
            </div>

            <!-- Columna Derecha Dinámica -->
            <div class="col-span-1 p-8">
                <!-- Modo Balance: Paso 1 (Introducir Monto) -->
                <div v-if="paymentMode === 'balance'">
                    <div v-if="currentStep === 'balance-mode-entry'">
                         <div class="mb-4">
                            <label for="balance-amount-to-pay" class="text-sm font-medium">Monto a abonar</label>
                            <InputNumber id="balance-amount-to-pay" v-model="amountToPay" mode="currency" currency="MXN" locale="es-MX"
                                class="w-full mt-1 text-2xl" inputClass="!py-3" />
                        </div>
                        <h3 class="text-lg font-semibold text-center my-4">Selecciona un método de pago</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <button v-for="method in paymentMethods" :key="method.id" @click="selectPaymentMethod(method.id)"
                                :disabled="!amountToPay || amountToPay <= 0"
                                class="flex flex-col items-center justify-center p-4 border rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                <img :src="method.icon" :alt="method.label" class="h-12 w-12 object-contain mb-2">
                                <span class="font-semibold text-sm text-gray-700 dark:text-gray-300">{{ method.label }}</span>
                            </button>
                        </div>
                    </div>
                     <div v-else class="min-h-[350px] flex flex-col">
                        <div class="flex-grow">
                            <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Cambiar monto o método</a>
                            <div v-if="currentStep === 'efectivo'">
                                <Message severity="info">El monto de {{ formatCurrency(amountToPay) }} se registrará como un ingreso de efectivo en la sesión de caja actual.</Message>
                            </div>
                            <div v-else-if="bankAccounts.length > 0">
                                <div class="mb-4">
                                    <label for="bank-account" class="text-sm font-medium">Cuenta destino</label>
                                    <Dropdown v-model="selectedAccountId" :options="bankAccountOptions" optionLabel="label" optionValue="value" placeholder="Selecciona una cuenta" class="w-full mt-1" />
                                </div>
                                <div class="mb-4">
                                    <label for="payment-notes" class="text-sm font-medium">Notas (opcional)</label>
                                    <InputText id="payment-notes" v-model="paymentNotes" placeholder="Ej. Abono mensual" class="w-full mt-1" />
                                </div>
                            </div>
                             <div v-else class="flex-grow flex flex-col items-center justify-center text-center">
                                <i class="pi pi-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                                <p class="font-semibold">No hay cuentas bancarias</p>
                                <p class="text-sm text-gray-500 mb-4">Para aceptar este tipo de pago, debe registrar una cuenta.</p>
                                <Button label="Registrar Nueva Cuenta" icon="pi pi-plus" @click="showAddBankAccountModal = true" />
                            </div>
                        </div>
                        <div class="mt-8" v-if="currentStep === 'efectivo' || bankAccounts.length > 0">
                            <Button label="Registrar Abono" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" class="w-full !py-3" />
                        </div>
                     </div>
                </div>

                <!-- Modos Strict y Flexible -->
                <div v-else>
                    <div v-if="currentStep === 'selection'" class="min-h-[350px] flex flex-col justify-center">
                        <h3 class="text-xl font-semibold text-center mb-6">Opciones de pago</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <button v-for="method in paymentMethods" :key="method.id" @click="selectPaymentMethod(method.id)"
                                class="flex flex-col items-center justify-center p-4 border rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                v-tooltip.bottom="method.id === 'credito' && client && !isCreditSale ? 'El cliente no tiene suficiente crédito' : ''">
                                <img :src="method.icon" :alt="method.label" class="h-16 w-16 object-contain mb-2">
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ method.label }}</span>
                            </button>
                        </div>
                    </div>
                    
                    <div v-if="currentStep === 'efectivo' || currentStep === 'tarjeta' || currentStep === 'transferencia'" class="min-h-[350px] flex flex-col">
                        <div class="flex-grow">
                            <div class="flex items-center gap-3 mb-4">
                                 <img :src="paymentMethods.find(m => m.id === currentStep).icon" :alt="currentStep" class="h-8">
                                <h3 class="text-xl font-semibold">{{ paymentMethods.find(m => m.id === currentStep).label }}</h3>
                            </div>
                            <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Volver</a>
                            
                            <div v-if="currentStep === 'efectivo' || paymentMode === 'flexible'" class="mb-4">
                                <label for="amount-to-pay" class="text-sm font-medium">{{ currentStep === 'efectivo' ? 'Monto recibido' : 'Monto a pagar' }}</label>
                                <InputNumber :id="currentStep === 'efectivo' ? 'cash-received' : 'amount-to-pay'" 
                                    :ref="currentStep === 'efectivo' ? 'cashInput' : null"
                                    v-model="paymentAmountModel" 
                                    mode="currency" currency="MXN" locale="es-MX"
                                    class="w-full mt-1 text-2xl" inputClass="!py-3" />
                            </div>

                            <div v-if="currentStep === 'tarjeta' || currentStep === 'transferencia'">
                                 <div v-if="bankAccounts.length > 0">
                                    <div class="mb-4">
                                        <label for="bank-account" class="text-sm font-medium">Cuenta destino</label>
                                        <Dropdown v-model="selectedAccountId" :options="bankAccountOptions" optionLabel="label" optionValue="value"
                                            placeholder="Selecciona una cuenta" class="w-full mt-1" />
                                    </div>
                                    <div v-if="currentStep === 'transferencia' && selectedBankAccount" class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg space-y-2 text-sm mb-4">
                                       <div v-for="detail in [{label: 'Banco', value: selectedBankAccount.bank_name}, {label: 'Propietario', value: selectedBankAccount.owner_name}, {label: 'Cuenta', value: selectedBankAccount.account_number}, {label: 'CLABE', value: selectedBankAccount.clabe}]" :key="detail.label">
                                            <div v-if="detail.value" class="flex justify-between items-center">
                                                <span class="text-gray-500">{{ detail.label }}:</span>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold font-mono">{{ detail.value }}</span>
                                                    <Button icon="pi pi-copy" text rounded severity="secondary" @click="copyToClipboard(detail.value)" v-tooltip.left="'Copiar'" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label for="payment-notes" class="text-sm font-medium">Notas de venta (opcional)</label>
                                        <InputText id="payment-notes" v-model="paymentNotes" placeholder="Ej. Aprobación 568393" class="w-full mt-1" />
                                    </div>
                                </div>
                                 <div v-else class="flex-grow flex flex-col items-center justify-center text-center">
                                    <i class="pi pi-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                                    <p class="font-semibold">No hay cuentas bancarias</p>
                                    <p class="text-sm text-gray-500 mb-4">Para aceptar este tipo de pago, debe registrar una cuenta.</p>
                                    <Button label="Registrar Nueva Cuenta" icon="pi pi-plus" @click="showAddBankAccountModal = true" />
                                </div>
                            </div>
                        </div>
                        <div class="mt-8" v-if="currentStep === 'efectivo' || bankAccounts.length > 0">
                            <Button :label="paymentMode === 'flexible' ? 'Registrar Abono' : 'Finalizar Venta'" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" class="w-full !py-3" />
                        </div>
                    </div>
                    
                    <div v-if="currentStep === 'credit-customer-selection'" class="min-h-[350px] flex flex-col">
                        <div class="flex-grow">
                            <h3 class="text-xl font-semibold">Asignar cliente</h3>
                            <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-4 block">&larr; Volver a métodos de pago</a>
                            <Message severity="info" :closable="false">Se requiere un cliente para guardar una venta a crédito.</Message>
                            <div class="mt-4">
                                <span class="p-input-icon-left w-full">
                                    <i class="pi pi-search" />
                                    <InputText v-model="customerSearch" placeholder="Buscar cliente por nombre o teléfono" class="w-full"/>
                                </span>
                            </div>
                            <div class="border rounded-md mt-2 overflow-y-auto max-h-[150px]">
                                <p v-if="filteredCustomers.length === 0" class="text-center text-sm text-gray-500 p-4">No se encontraron clientes.</p>
                                <ul v-else class="divide-y">
                                    <li v-for="customer in filteredCustomers" :key="customer.id" @click="handleCustomerSelectInModal(customer)"
                                        class="p-3 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                                        <p class="font-semibold text-sm">{{ customer.name }}</p>
                                        <p class="text-xs text-gray-500">{{ customer.phone }}</p>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="mt-4">
                            <Button label="Crear cliente rápido" icon="pi pi-plus" @click="showCreateCustomerModal = true" severity="secondary" class="w-full" />
                        </div>
                    </div>

                    <div v-if="currentStep === 'credit'" class="min-h-[350px] flex flex-col">
                        <div class="flex-grow">
                             <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <img src="/images/credito.png" alt="Credito" class="h-8">
                                    <h3 class="text-xl font-semibold">Venta a crédito</h3>
                                </div>
                                <Button @click="removeClient" icon="pi pi-user-minus" text rounded severity="danger" v-tooltip.left="'Quitar cliente'" />
                            </div>
                            <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Volver a métodos de pago</a>
                            
                            <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg mb-6 text-sm">
                                <p>Cliente: <span class="font-bold">{{ client.name }}</span></p>
                                <div class="flex justify-between">
                                    <span>{{ client.balance >= 0 ? 'Saldo a favor:' : 'Deuda actual:' }}</span>
                                    <span class="font-mono font-semibold" :class="client.balance >= 0 ? 'text-green-600' : 'text-red-600'">
                                        {{ formatCurrency(client.balance) }}
                                    </span>
                                </div>
                                <Message v-if="balanceUsedForSale > 0" severity="success" :closable="false" class="!text-xs !mt-2">Se aplicará {{formatCurrency(balanceUsedForSale)}} de saldo a favor en esta venta.</Message>
                                <Message v-if="client.balance < 0" severity="warn" :closable="false" class="!text-xs !mt-2">El monto de esta venta se sumará a la deuda actual.</Message>
                            </div>
                            
                            <div>
                                 <label class="text-sm font-medium">Abono inicial</label>
                                 <SelectButton v-model="willMakeDownPayment" :options="abonoOptions" optionLabel="label" optionValue="value" class="mt-1" />
                            </div>

                            <div v-if="willMakeDownPayment" class="mt-4 space-y-4 pt-4 border-t">
                                <div>
                                    <label class="text-sm font-medium">Monto del abono</label>
                                    <InputNumber v-model="downPaymentAmount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                                    <small v-if="creditLimitExceeded" class="text-red-500 mt-1 block">
                                        Límite de crédito excedido. Abono mínimo requerido: {{ formatCurrency(requiredDownPayment) }}
                                    </small>
                                </div>
                                 <div>
                                    <label class="text-sm font-medium">Método de pago del abono</label>
                                    <Dropdown v-model="downPaymentMethod" :options="['efectivo', 'tarjeta', 'transferencia']" placeholder="Selecciona método" class="w-full mt-1" />
                                </div>
                            </div>
                        </div>
                        <div class="mt-8">
                             <Button :label="`Guardar a crédito (${formatCurrency(amountToCreditFinal)})`" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" class="w-full !py-3" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <template #footer><div></div></template>
    </Dialog>
    
    <AddBankAccountModal :visible="showAddBankAccountModal" @update:visible="showAddBankAccountModal = $event" @success="onBankAccountAdded" />
    <CreateCustomerModal :visible="showCreateCustomerModal" @update:visible="showCreateCustomerModal = $event" @created="handleCustomerCreatedInModal" />
</template>