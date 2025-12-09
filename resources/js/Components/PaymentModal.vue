<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import BankAccountModal from '@/Components/BankAccountModal.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentSummaryColumn from './PaymentModalPartials/PaymentSummaryColumn.vue';
import MultiPaymentProcessor from './PaymentModalPartials/MultiPaymentProcessor.vue';

const props = defineProps({
    visible: Boolean,
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    paymentMode: { type: String, default: 'strict' },
    allowCredit: { type: Boolean, default: true },
    allowLayaway: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'submit', 'update:client', 'customerCreated']);
const toast = useToast();

const currentStep = ref('selecType');
const transactionType = ref('contado');

// --- Búsqueda de Clientes (AutoComplete) ---
const filteredCustomers = ref([]);
const selectedCustomerModel = ref(props.client);

// Sincronizar localmente, pero validar tipo para no romper lógica
watch(() => props.client, (newVal) => {
    if (!newVal || typeof newVal === 'object') {
        selectedCustomerModel.value = newVal;
    }
}, { immediate: true });

const searchCustomer = async (event) => {
    try {
        const response = await axios.get(route('pos.customers.search'), { params: { query: event.query } });
        filteredCustomers.value = response.data;
    } catch (error) {
        console.error("Error buscando clientes:", error);
    }
};

const onCustomerSelect = (event) => {
    // Solo emitimos al padre cuando se selecciona un objeto
    emit('update:client', event.value);
};

const onCustomerClear = () => {
    emit('update:client', null);
};

// --- Tipos de Transacción ---
const transactionTypes = computed(() => {
    let types = [
        { id: 'contado', label: 'Pago al contado', image: '/images/contado.webp', bgColor: '#C5E0F7', textColor: '#3D5F9B', description: 'Se liquida el 100% de la venta.', disabled: false },
        { id: 'credito', label: 'A crédito / pagos', image: '/images/credito.webp', bgColor: '#FFCD87', textColor: '#603814', description: 'Se lo lleva y paga descpues.', disabled: !props.allowCredit || !props.client },
        { id: 'apartado', label: 'Sistema de apartado', image: '/images/apartado.webp', bgColor: '#FFC9E9', textColor: '#862384', description: 'Anticipo para reservar.', disabled: !props.allowLayaway || !props.client },
    ];
    if (!props.allowCredit) types = types.filter(t => t.id !== 'credito');
    if (!props.allowLayaway) types = types.filter(t => t.id !== 'apartado');
    return types;
});

const selectTransactionType = (type) => {
    transactionType.value = type;
    currentStep.value = 'processPayment';
};

// --- Cuentas Bancarias ---
const bankAccounts = ref([]);
const bankAccountOptions = ref([]);
const showAddBankAccountModal = ref(false);
const showCreateCustomerModal = ref(false);
const isLoadingAccounts = ref(false);

const fetchBankAccounts = async () => {
    try {
        const response = await axios.get(route('branch-bank-accounts'));
        bankAccounts.value = response.data;
        bankAccountOptions.value = response.data.map(acc => ({
            label: `${acc.bank_name} - ${acc.account_name}`,
            value: acc.id
        }));
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Error al cargar cuentas bancarias.' });
    }
};

// --- CORRECCIÓN: Función restaurada ---
const onBankAccountAdded = () => {
    fetchBankAccounts();
};
// --------------------------------------

const closeModal = () => emit('update:visible', false);
const handleSubmitFromProcessor = (paymentData) => emit('submit', { ...paymentData, transactionType: transactionType.value });

const resetModalState = () => {
    if (props.paymentMode === 'balance') { currentStep.value = 'processPayment'; transactionType.value = 'balance'; }
    else if (props.paymentMode === 'flexible') { currentStep.value = 'processPayment'; transactionType.value = 'flexible'; }
    else { currentStep.value = 'selecType'; transactionType.value = 'contado'; }
};

watch(() => props.visible, async (isVisible) => {
    if (isVisible) {
        isLoadingAccounts.value = true;
        resetModalState();
        await fetchBankAccounts();
        isLoadingAccounts.value = false;
    }
}, { immediate: true });
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Procesar pago" class="w-full max-w-4xl mx-4">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-0">
            <PaymentSummaryColumn :payment-mode="paymentMode" :total-amount="totalAmount" :client="client"
                :transaction-type="transactionType" />

            <div class="lg:col-span-2 p-4 lg:p-8">
                <!-- Selector de Tipo -->
                <div v-if="paymentMode === 'strict' && currentStep === 'selecType'">
                    <h3 class="text-xl font-semibold text-center mb-6 text-gray-800 dark:text-gray-200">¿Cómo deseas
                        registrar esta venta?</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <button v-for="type in transactionTypes" :key="type.id" @click="selectTransactionType(type.id)"
                            :disabled="type.disabled"
                            class="flex flex-col items-center justify-center p-4 border rounded-xl transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed hover:brightness-90"
                            :style="{ backgroundColor: type.bgColor, color: type.textColor }"
                            v-tooltip.bottom="type.disabled ? 'Selecciona un cliente primero' : ''">
                            <p class="font-bold text-center mb-1 text-sm">{{ type.label }}</p>
                            <img :src="type.image" :alt="type.label" class="size-16 object-contain mb-1">
                            <p class="text-xs m-0">{{ type.description }}</p>
                        </button>
                    </div>

                    <!-- AutoComplete para Cliente en Modal -->
                    <div v-if="!client && (allowCredit || allowLayaway)" class="mt-8">
                        <Message severity="info" :closable="false">
                            <span class="font-semibold">Se requiere un cliente</span> para ventas a crédito o apartado.
                        </Message>
                        <div class="flex items-center gap-2 mt-4">
                            <IconField iconPosition="left" class="w-full">
                                <InputIcon class="pi pi-search"></InputIcon>
                                <AutoComplete v-model="selectedCustomerModel" :suggestions="filteredCustomers"
                                @complete="searchCustomer" @item-select="onCustomerSelect" @clear="onCustomerClear"
                                optionLabel="name" forceSelection placeholder="Buscar cliente por nombre o teléfono..." class="w-full"
                                :delay="400" emptyMessage="No se encontraron clientes" fluid>
                                <template #option="slotProps">
                                    <div class="flex flex-col">
                                        <span class="font-bold">{{ slotProps.option.name }}</span>
                                        <span class="text-xs text-gray-500">{{ slotProps.option.phone }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                            </IconField>
                            <Button @click="showCreateCustomerModal = true" rounded icon="pi pi-plus"
                                severity="contrast" size="small" />
                        </div>
                    </div>
                </div>

                <!-- Procesador de Pagos -->
                <div v-else-if="currentStep === 'processPayment'">
                    <a v-if="paymentMode === 'strict'" @click="currentStep = 'selecType'"
                        class="text-sm text-primary cursor-pointer mb-5 block hover:underline">&larr; Volver</a>
                    <div v-if="isLoadingAccounts" class="flex items-center justify-center min-h-[400px]"><i
                            class="pi pi-spin pi-spinner !text-4xl text-gray-500"></i></div>
                    <MultiPaymentProcessor v-else :total-amount="totalAmount" :client="client"
                        :transaction-type="transactionType" :bank-accounts="bankAccounts"
                        :bank-account-options="bankAccountOptions" :loading="props.loading"
                        @submit="handleSubmitFromProcessor" @add-account="showAddBankAccountModal = true" />
                </div>
            </div>
        </div>
    </Dialog>
    <BankAccountModal :visible="showAddBankAccountModal" @update:visible="showAddBankAccountModal = $event"
        @success="onBankAccountAdded" />
    <CreateCustomerModal :visible="showCreateCustomerModal" @update:visible="showCreateCustomerModal = $event"
        @created="emit('customerCreated', $event)" />
</template>