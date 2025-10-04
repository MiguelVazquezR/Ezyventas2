<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
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
        default: 'strict',
        validator: (value) => ['strict', 'flexible'].includes(value)
    }
});

const emit = defineEmits(['update:visible', 'submit', 'update:client', 'customerCreated']);
const toast = useToast();

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
    if (selectedMethod.value === 'efectivo' && cashReceived.value !== null) {
        return parseFloat(cashReceived.value);
    }
    if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) {
        return props.totalAmount;
    }
    if (selectedMethod.value === 'credito') {
        return downPaymentAmount.value || 0;
    }
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
    if (props.paymentMode === 'flexible') return true;
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
    if (method === 'efectivo') {
        currentStep.value = 'cash';
        cashReceived.value = props.totalAmount;
        nextTick(() => cashInput.value?.$el.focus());
    } else if (method === 'tarjeta') {
        currentStep.value = 'card';
    } else if (method === 'transferencia') {
        currentStep.value = 'transfer';
    } else if (method === 'credito') {
        if (props.client) {
            currentStep.value = 'credit';
        } else {
            currentStep.value = 'credit-customer-selection';
        }
    }
};

const handleCustomerSelectInModal = (customer) => {
    emit('update:client', customer);
    nextTick(() => {
        currentStep.value = 'credit';
    });
};

const handleCustomerCreatedInModal = (newCustomer) => {
    emit('customerCreated', newCustomer); 
    emit('update:client', newCustomer);
    showCreateCustomerModal.value = false;
    nextTick(() => {
        currentStep.value = 'credit';
    });
};

// --- INICIO DE CORRECCIÓN ---
const removeClient = () => {
    emit('update:client', null);
    currentStep.value = 'credit-customer-selection';
    willMakeDownPayment.value = false;
    downPaymentAmount.value = null;
};
// --- FIN DE CORRECCIÓN ---

const goBackToSelection = () => {
    currentStep.value = 'selection';
    selectedMethod.value = null;
    cashReceived.value = null;
    selectedAccountId.value = bankAccounts.value.find(acc => acc.is_favorite)?.id || (bankAccounts.value.length === 1 ? bankAccounts.value[0].id : null);
    paymentNotes.value = '';
    payments.value = [];
    willMakeDownPayment.value = false;
    downPaymentAmount.value = null;
};

const submitForm = () => {
    payments.value = [];
    let paymentPayload = {};
    if (selectedMethod.value === 'credito') {
        if (willMakeDownPayment.value && downPaymentAmount.value > 0) {
             payments.value.push({
                amount: downPaymentAmount.value,
                method: downPaymentMethod.value,
                notes: 'Abono inicial en venta a crédito'
             });
        }
    } else {
        paymentPayload = {
            amount: props.totalAmount,
            method: selectedMethod.value
        };
        if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) {
            paymentPayload.bank_account_id = selectedAccountId.value;
            paymentPayload.notes = paymentNotes.value;
        }
        payments.value.push(paymentPayload);
    }
    emit('submit', { payments: payments.value });
    emit('update:visible', false);
};

watch(() => props.visible, (newVal) => {
    if (newVal) {
        goBackToSelection();
    }
});

const copyToClipboard = (text) => {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    toast.add({ severity: 'info', summary: 'Copiado', detail: 'Dato copiado al portapapeles.', life: 2000 });
};

const formatCurrency = (value) => {
    if (typeof value !== 'number') return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const paymentMethods = [
    { id: 'efectivo', label: 'Efectivo', icon: '/images/efectivo.png' },
    { id: 'tarjeta', label: 'Tarjeta', icon: '/images/tarjeta.png' },
    { id: 'transferencia', label: 'Transferencia', icon: '/images/transferencia.png' },
    { id: 'credito', label: 'A crédito', icon: '/images/credito.png' },
];

const abonoOptions = [
    { label: 'Sin abono', value: false },
    { label: 'Agregar abono', value: true }
]
</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', false)" modal header="Procesar Pago"
        :style="{ width: '50rem' }" :draggable="false">
        <Toast />
        <div class="grid grid-cols-2">
            <!-- Columna Izquierda Fija -->
            <div class="col-span-1 bg-gray-50 dark:bg-gray-800 p-8 flex flex-col justify-center items-center rounded-l-lg">
                <p class="text-lg font-semibold text-gray-600 dark:text-gray-300">TOTAL A PAGAR</p>
                <p class="text-6xl font-bold text-gray-800 dark:text-gray-100 mt-4">
                    {{ formatCurrency(totalAmount) }}
                </p>

                <div v-if="currentStep === 'credit' && client" class="w-full text-center mt-6 space-y-2 border-t pt-4">
                    <div v-if="balanceUsedForSale > 0" class="flex justify-between text-lg">
                        <span class="text-gray-500">Saldo a favor</span>
                        <span class="text-green-500 font-mono">-{{ formatCurrency(balanceUsedForSale) }}</span>
                    </div>
                     <div class="flex justify-between text-lg font-bold border-t pt-2">
                        <span class="text-gray-600">Subtotal</span>
                        <span class="text-gray-800 font-mono">{{ formatCurrency(amountAfterBalance) }}</span>
                    </div>
                    <div v-if="willMakeDownPayment && downPaymentAmount > 0" class="flex justify-between text-lg">
                        <span class="text-gray-500">Abono inicial</span>
                        <span class="text-green-500 font-mono">-{{ formatCurrency(downPaymentAmount) }}</span>
                    </div>
                </div>

                <div v-if="client" class="mt-8 w-full border-t pt-4">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ client.name }}</p>
                    <div class="flex justify-between text-xs mt-1">
                        <span class="text-gray-500 dark:text-gray-400">Crédito Disponible:</span>
                        <span class="font-mono font-semibold text-blue-500">{{ formatCurrency(client.available_credit) }}</span>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha Dinámica -->
            <div class="col-span-1 p-8">
                <div v-if="currentStep === 'selection'" class="min-h-[350px] flex flex-col justify-center">
                    <h3 class="text-xl font-semibold text-center mb-6">Opciones de pago</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <button v-for="method in paymentMethods" :key="method.id" @click="selectPaymentMethod(method.id)"
                            class="flex flex-col items-center justify-center p-4 border rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            v-tooltip.bottom="method.id === 'credito' && client && !isCreditSale ? 'El cliente no tiene suficiente crédito. Requiere un abono mínimo' : ''">
                            <img :src="method.icon" :alt="method.label" class="h-16 w-16 object-contain mb-2">
                            <span class="font-semibold text-gray-700 dark:text-gray-300">{{ method.label }}</span>
                        </button>
                    </div>
                </div>
                <div v-if="currentStep === 'cash'" class="min-h-[350px] flex flex-col">
                    <div class="flex-grow">
                        <div class="flex items-center gap-3 mb-4">
                            <img src="/images/efectivo.png" alt="Efectivo" class="h-8">
                            <h3 class="text-xl font-semibold">Pago en efectivo</h3>
                        </div>
                        <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Volver</a>
                        <div class="mb-4">
                            <label for="cash-received" class="text-sm font-medium">Monto recibido</label>
                            <InputNumber id="cash-received" ref="cashInput" v-model="cashReceived" mode="currency" currency="MXN" locale="es-MX"
                                class="w-full mt-1 text-2xl" inputClass="!py-3" />
                        </div>
                        <div v-if="change >= 0" class="bg-green-100 text-green-800 p-4 rounded-lg text-center">
                            <p class="text-lg">Su cambio</p>
                            <p class="text-4xl font-bold">{{ formatCurrency(change) }}</p>
                        </div>
                        <div v-else class="bg-red-100 text-red-800 p-4 rounded-lg text-center">
                            <p class="text-lg">Faltante</p>
                            <p class="text-4xl font-bold">{{ formatCurrency(Math.abs(amountRemaining)) }}</p>
                        </div>
                    </div>
                    <div class="mt-8">
                         <Button label="Finalizar Venta" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" class="w-full !py-3" />
                    </div>
                </div>
                <div v-if="currentStep === 'card'" class="min-h-[350px] flex flex-col">
                    <div class="flex-grow">
                        <div class="flex items-center gap-3 mb-4">
                             <img src="/images/tarjeta.png" alt="Tarjeta" class="h-8">
                            <h3 class="text-xl font-semibold">Pago con tarjeta</h3>
                        </div>
                        <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Volver</a>
                        <div v-if="bankAccounts.length > 0">
                            <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg text-center mb-6">
                                <i class="pi pi-credit-card text-2xl text-gray-500 mb-2"></i>
                                <p class="text-sm text-gray-600 dark:text-gray-300">Procesa el pago en la terminal. Este paso solo registra la venta.</p>
                            </div>
                            <div class="mb-4">
                                <label for="bank-account" class="text-sm font-medium">Cuenta destino</label>
                                <Dropdown v-model="selectedAccountId" :options="bankAccountOptions" optionLabel="label" optionValue="value"
                                    placeholder="Selecciona una cuenta" class="w-full mt-1" />
                            </div>
                            <div class="mb-4">
                                <label for="payment-notes" class="text-sm font-medium">Notas de venta (opcional)</label>
                                <InputText id="payment-notes" v-model="paymentNotes" placeholder="Ej. Aprobación 568393" class="w-full mt-1" />
                            </div>
                        </div>
                        <div v-else class="flex-grow flex flex-col items-center justify-center text-center">
                            <i class="pi pi-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                            <p class="font-semibold">No hay cuentas bancarias</p>
                            <p class="text-sm text-gray-500 mb-4">Para aceptar pagos con tarjeta, debe registrar una cuenta.</p>
                            <Button label="Registrar Nueva Cuenta" icon="pi pi-plus" @click="showAddBankAccountModal = true" />
                        </div>
                    </div>
                    <div class="mt-8" v-if="bankAccounts.length > 0">
                         <Button label="Confirmar y finalizar venta" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" class="w-full !py-3" />
                    </div>
                </div>
                <div v-if="currentStep === 'transfer'" class="min-h-[350px] flex flex-col">
                    <div class="flex-grow">
                        <div class="flex items-center gap-3 mb-4">
                             <img src="/images/transferencia.png" alt="Transferencia" class="h-8">
                            <h3 class="text-xl font-semibold">Pago con transferencia</h3>
                        </div>
                        <a @click="goBackToSelection" class="text-sm text-blue-500 hover:underline cursor-pointer mb-6 block">&larr; Volver</a>
                        <div v-if="bankAccounts.length > 0">
                            <div class="mb-4">
                                <label for="bank-account-transfer" class="text-sm font-medium">Cuenta destino</label>
                                <Dropdown v-model="selectedAccountId" :options="bankAccountOptions" optionLabel="label" optionValue="value"
                                    placeholder="Selecciona una cuenta" class="w-full mt-1" />
                            </div>
                            <div v-if="selectedBankAccount" class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg space-y-2 text-sm">
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
                            <div class="mt-4">
                                <label for="payment-notes-transfer" class="text-sm font-medium">Notas (opcional)</label>
                                <InputText id="payment-notes-transfer" v-model="paymentNotes" placeholder="Ej. Ref. 987654" class="w-full mt-1" />
                            </div>
                        </div>
                        <div v-else class="flex-grow flex flex-col items-center justify-center text-center">
                            <i class="pi pi-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                            <p class="font-semibold">No hay cuentas bancarias</p>
                            <p class="text-sm text-gray-500 mb-4">Para aceptar transferencias, debe registrar una cuenta.</p>
                            <Button label="Registrar Nueva Cuenta" icon="pi pi-plus" @click="showAddBankAccountModal = true" />
                        </div>
                    </div>
                    <div class="mt-8" v-if="bankAccounts.length > 0">
                         <Button label="Confirmar y finalizar venta" icon="pi pi-check" @click="submitForm" :disabled="!canFinalize" class="w-full !py-3" />
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
                            <!-- INICIO DE CORRECCIÓN: Botón para quitar cliente -->
                            <Button @click="removeClient" icon="pi pi-user-minus" text rounded severity="danger" v-tooltip.left="'Quitar cliente'" />
                            <!-- FIN DE CORRECCIÓN -->
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
        <template #footer><div></div></template>
    </Dialog>
    
    <AddBankAccountModal 
        :visible="showAddBankAccountModal" 
        @update:visible="showAddBankAccountModal = $event"
        @success="onBankAccountAdded"
    />
    <CreateCustomerModal 
        :visible="showCreateCustomerModal"
        @update:visible="showCreateCustomerModal = $event"
        @created="handleCustomerCreatedInModal"
    />
</template>