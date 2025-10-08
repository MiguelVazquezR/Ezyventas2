<script setup>
import { ref, computed, watch, inject } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import BankAccountModal from '@/Components/BankAccountModal.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentSummaryColumn from './PaymentModalPartials/PaymentSummaryColumn.vue';
import PaymentStepBalance from './PaymentModalPartials/PaymentStepBalance.vue';
import PaymentStepFlow from './PaymentModalPartials/PaymentStepFlow.vue';

const props = defineProps({
    visible: Boolean,
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    paymentMode: {
        type: String,
        default: 'strict',
        validator: (value) => ['strict', 'flexible', 'balance'].includes(value)
    }
});

const emit = defineEmits(['update:visible', 'submit', 'update:client', 'customerCreated']);
const toast = useToast();
const activeSession = inject('activeSession', ref(null));

// --- ESTADO PRINCIPAL DEL COMPONENTE ---
const selectedMethod = ref(null);
const payments = ref([]);
const showAddBankAccountModal = ref(false);
const showCreateCustomerModal = ref(false);
const cashReceived = ref(null);
const bankAccounts = ref([]);
const selectedAccountId = ref(null);
const paymentNotes = ref('');
const amountToPay = ref(0);
const willMakeDownPayment = ref(false);
const downPaymentAmount = ref(null);
const downPaymentMethod = ref('efectivo');
// --- NUEVO: Estado para la cuenta del abono ---
const downPaymentBankAccountId = ref(null);

// --- LÓGICA DE DATOS Y CÁLCULOS ---
const fetchBankAccounts = async () => {
    try {
        const response = await axios.get(route('branch-bank-accounts'));
        bankAccounts.value = response.data;
        if (bankAccounts.value.length === 1) {
            selectedAccountId.value = bankAccounts.value[0].id;
            downPaymentBankAccountId.value = bankAccounts.value[0].id; // Pre-seleccionar también
        }
    } catch (error) { console.error("Error fetching bank accounts:", error); }
};

const onBankAccountAdded = () => {
    showAddBankAccountModal.value = false;
    toast.add({ severity: 'success', summary: 'Éxito', detail: 'Cuenta bancaria registrada.', life: 3000 });
    fetchBankAccounts();
};

const bankAccountOptions = computed(() => bankAccounts.value.map(acc => ({
    label: `${acc.bank_name} - ${acc.account_name} (...${(acc.card_number || acc.account_number || 'N/A').slice(-4)})`,
    value: acc.id
})));

const clientBalance = computed(() => props.client?.balance || 0);
const amountAfterBalance = computed(() => props.totalAmount - Math.min(props.totalAmount, Math.max(0, clientBalance.value)));
const amountToCreditFinal = computed(() => (willMakeDownPayment.value && downPaymentAmount.value > 0) ? amountAfterBalance.value - downPaymentAmount.value : amountAfterBalance.value);
const futureDebt = computed(() => Math.abs(Math.min(0, clientBalance.value)) + amountToCreditFinal.value);
const creditLimitExceeded = computed(() => futureDebt.value > (props.client?.credit_limit || 0));
const requiredDownPayment = computed(() => creditLimitExceeded.value ? futureDebt.value - (props.client?.credit_limit || 0) : 0);
const isCreditSale = computed(() => props.client ? amountToCreditFinal.value <= (props.client?.available_credit || 0) : false);

// --- LÓGICA DE FLUJO Y MANEJO DE EVENTOS ---
watch(() => props.visible, (newVal) => {
    if (newVal) {
        fetchBankAccounts();
        resetState();
        if (props.paymentMode === 'balance' && props.client?.balance < 0) {
            amountToPay.value = Math.abs(props.client.balance);
        }
    }
});

const resetState = () => {
    selectedMethod.value = null;
    payments.value = [];
    cashReceived.value = null;
    selectedAccountId.value = null;
    paymentNotes.value = '';
    amountToPay.value = 0;
    willMakeDownPayment.value = false;
    downPaymentAmount.value = null;
    downPaymentMethod.value = 'efectivo';
    downPaymentBankAccountId.value = null; // Resetear
};

const submitForm = () => {
    payments.value = [];
    let amount = 0;

    if (props.paymentMode === 'balance') {
        amount = amountToPay.value;
        if (amount > 0) payments.value.push({ amount, method: selectedMethod.value, notes: paymentNotes.value, bank_account_id: selectedAccountId.value });
    } else if (selectedMethod.value === 'credito') {
        if (willMakeDownPayment.value && downPaymentAmount.value > 0) {
            const downPaymentPayload = { 
                amount: downPaymentAmount.value, 
                method: downPaymentMethod.value, 
                notes: 'Abono inicial en venta a crédito' 
            };
            // --- NUEVO: Añadir ID de cuenta bancaria si es necesario ---
            if (['tarjeta', 'transferencia'].includes(downPaymentMethod.value)) {
                downPaymentPayload.bank_account_id = downPaymentBankAccountId.value;
            }
            payments.value.push(downPaymentPayload);
        }
    } else {
        amount = selectedMethod.value === 'efectivo' ? (cashReceived.value || 0) : (amountToPay.value || 0);
        const finalAmount = Math.min(amount, props.totalAmount);
        if (finalAmount > 0) {
            const payload = { amount: finalAmount, method: selectedMethod.value };
            if (['tarjeta', 'transferencia'].includes(selectedMethod.value)) {
                payload.bank_account_id = selectedAccountId.value;
                payload.notes = paymentNotes.value;
            }
            payments.value.push(payload);
        }
    }

    if (payments.value.length === 0 && selectedMethod.value !== 'credito') {
        toast.add({ severity: 'warn', summary: 'Monto Inválido', detail: 'El monto a pagar debe ser mayor a cero.', life: 4000 });
        return;
    }

    emit('submit', { payments: payments.value, cash_register_session_id: activeSession.value?.id });
    emit('update:visible', false);
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', false)" modal :header="paymentMode === 'balance' ? 'Abonar a Saldo' : 'Procesar Pago'"
        :style="{ width: '50rem' }" :draggable="false">
        <Toast />
        <div class="grid grid-cols-2">
            <PaymentSummaryColumn
                :payment-mode="paymentMode"
                :total-amount="totalAmount"
                :amount-to-pay="amountToPay"
                :client="client"
            />
            <div class="col-span-1 p-8">
                <PaymentStepBalance
                    v-if="paymentMode === 'balance'"
                    v-model:amountToPay="amountToPay"
                    v-model:selectedAccountId="selectedAccountId"
                    v-model:paymentNotes="paymentNotes"
                    :bank-accounts="bankAccounts"
                    :bank-account-options="bankAccountOptions"
                    @select-method="selectedMethod = $event"
                    @submit="submitForm"
                    @add-account="showAddBankAccountModal = true"
                />
                <PaymentStepFlow
                    v-else
                    v-model:cashReceived="cashReceived"
                    v-model:amountToPay="amountToPay"
                    v-model:selectedAccountId="selectedAccountId"
                    v-model:paymentNotes="paymentNotes"
                    v-model:willMakeDownPayment="willMakeDownPayment"
                    v-model:downPaymentAmount="downPaymentAmount"
                    v-model:downPaymentMethod="downPaymentMethod"
                    v-model:downPaymentBankAccountId="downPaymentBankAccountId"
                    :payment-mode="paymentMode"
                    :total-amount="totalAmount"
                    :client="client"
                    :customers="customers"
                    :bank-accounts="bankAccounts"
                    :bank-account-options="bankAccountOptions"
                    :is-credit-sale="isCreditSale"
                    :credit-limit-exceeded="creditLimitExceeded"
                    :required-down-payment="requiredDownPayment"
                    :amount-to-credit-final="amountToCreditFinal"
                    @select-method="selectedMethod = $event"
                    @submit="submitForm"
                    @add-account="showAddBankAccountModal = true"
                    @select-customer="emit('update:client', $event)"
                    @create-customer="showCreateCustomerModal = true"
                    @remove-client="emit('update:client', null)"
                />
            </div>
        </div>
        <template #footer><div></div></template>
    </Dialog>
    
    <BankAccountModal :visible="showAddBankAccountModal" @update:visible="showAddBankAccountModal = $event" @success="onBankAccountAdded" />
    <CreateCustomerModal :visible="showCreateCustomerModal" @update:visible="showCreateCustomerModal = $event" @created="emit('customerCreated', $event)" />
</template>