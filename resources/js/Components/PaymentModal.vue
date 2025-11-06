<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import BankAccountModal from '@/Components/BankAccountModal.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
// Importamos la columna de resumen, que seguiremos usando
import PaymentSummaryColumn from './PaymentModalPartials/PaymentSummaryColumn.vue';
// --- AÑADIR IMPORT ---
import MultiPaymentProcessor from './PaymentModalPartials/MultiPaymentProcessor.vue';

// --- Props ---
const props = defineProps({
    visible: Boolean,
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    customers: { type: Array, default: () => [] },

    // Prop para diferenciar flujos (venta vs. abono)
    paymentMode: {
        type: String,
        default: 'strict', // 'strict', 'flexible', 'balance'
    },

    // --- NUEVOS Props Contextuales ---
    allowCredit: { type: Boolean, default: true },
    allowLayaway: { type: Boolean, default: true },
});

const emit = defineEmits(['update:visible', 'submit', 'update:client', 'customerCreated']);
const toast = useToast();

// --- NUEVO Estado de Flujo ---
// 'selectType' = Muestra los 3 botones
// 'processPayment' = Muestra el procesador de pagos (el futuro MultiPaymentProcessor.vue)
const currentStep = ref('selectType');
// 'contado', 'credito', 'apartado', 'balance'
const transactionType = ref(null);

// --- Estado General del Modal ---
const payments = ref([]); // El procesador de pagos llenará esto
const bankAccounts = ref([]);
const bankAccountOptions = ref([]);
const showAddBankAccountModal = ref(false);
const showCreateCustomerModal = ref(false);

// --- Lógica de Carga y Reseteo ---
const fetchBankAccounts = async () => {
    try {
        const response = await axios.get(route('branch-bank-accounts'));
        bankAccounts.value = response.data;
        bankAccountOptions.value = response.data.map(acc => ({
            label: `${acc.bank_name} - ${acc.account_name} (...${(acc.card_number || acc.account_number || 'N/A').slice(-4)})`,
            value: acc.id
        }));
    } catch (error) { console.error("Error fetching bank accounts:", error); }
};

const resetState = () => {
    payments.value = [];
    // Define el estado inicial basado en el modo
    if (props.paymentMode === 'balance') {
        // Si es un abono, salta la selección y va directo al pago
        transactionType.value = 'balance';
        currentStep.value = 'processPayment';
    } else {
        // Si es una venta, inicia en el selector de tipo
        transactionType.value = null;
        currentStep.value = 'selectType';
    }
};

watch(() => props.visible, (newVal) => {
    if (newVal) {
        fetchBankAccounts();
        resetState();
    }
}, { immediate: true });

// --- Lógica de Navegación del Modal ---
const selectTransactionType = (type) => {
    if ((type === 'credito' || type === 'apartado') && !props.client) {
        toast.add({
            severity: 'warn',
            summary: 'Cliente requerido',
            detail: 'Debes seleccionar un cliente para esta operación.',
            life: 4000
        });
        return;
    }
    transactionType.value = type;
    currentStep.value = 'processPayment';
};

const goBackToSelect = () => {
    transactionType.value = null;
    currentStep.value = 'selectType';
};

// --- Lógica de Emisión ---
const onBankAccountAdded = () => {
    showAddBankAccountModal.value = false;
    toast.add({ severity: 'success', summary: 'Éxito', detail: 'Cuenta bancaria registrada.', life: 3000 });
    fetchBankAccounts();
};

/**
 * Esta función será llamada por el futuro componente MultiPaymentProcessor
 * cuando el usuario termine de agregar pagos y haga clic en "Finalizar".
 */
const handleSubmitFromProcessor = (paymentData) => {
    // paymentData debería ser: { payments: [...], use_balance: bool }
    emit('submit', {
        ...paymentData,
        transactionType: transactionType.value, // Añadimos el tipo de transacción
    });
    emit('update:visible', false);
};

// --- Valores Computados ---
const modalTitle = computed(() => {
    if (props.paymentMode === 'balance') return 'Abonar a Saldo';
    if (transactionType.value === 'apartado') return 'Crear Apartado';
    if (transactionType.value === 'credito') return 'Pago a Crédito / Parcialidades';
    return 'Procesar Pago';
});

// Opciones para el selector de tipo de transacción
const transactionOptions = computed(() => [
    {
        id: 'contado',
        label: 'Pago al Contado',
        icon: 'pi pi-check-circle',
        description: 'Se liquida el 100% de la venta en este momento.',
        visible: true,
        disabled: false
    },
    {
        id: 'credito',
        label: 'A crédito / Pagos',
        icon: 'pi pi-users',
        description: 'Paga un anticipo y el resto se va al saldo deudor del cliente.',
        visible: props.allowCredit,
        disabled: !props.client
    },
    {
        id: 'apartado',
        label: 'Sistema de Apartado',
        icon: 'pi pi-box',
        description: 'Paga un anticipo para reservar los productos. No afecta el saldo.',
        visible: props.allowLayaway,
        disabled: !props.client
    }
]);
</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', false)" modal :header="modalTitle"
        :style="{ width: '50rem' }">
        <Toast />
        <div class="grid grid-cols-2">
            <!-- Columna Izquierda: Resumen de Pago -->
            <PaymentSummaryColumn :payment-mode="paymentMode" :total-amount="totalAmount" :client="client"
                :transaction-type="transactionType" :amount-to-pay="0" />

            <!-- Columna Derecha: Flujo de Pasos -->
            <div class="col-span-1 p-8">

                <!-- CASO 1: FLUJO DE VENTA (Contado, Crédito, Apartado) -->
                <div v-if="paymentMode !== 'balance'">

                    <!-- PASO 1: Selector de Tipo de Transacción -->
                    <div v-if="currentStep === 'selectType'" class="min-h-[350px] flex flex-col justify-center">
                        <h3 class="text-lg lg:text-xl font-semibold text-center mb-6">¿Cómo será esta transacción?</h3>
                        <div class="space-y-3">
                            <button v-for="option in transactionOptions.filter(o => o.visible)" :key="option.id"
                                @click="selectTransactionType(option.id)" :disabled="option.disabled"
                                class="w-full p-4 border rounded-lg flex items-start text-left hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                v-tooltip.bottom="option.disabled ? 'Debes seleccionar un cliente para esta opción' : ''">
                                <i :class="option.icon" class="!text-xl text-primary mr-4 mt-1"></i>
                                <div>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ option.label
                                        }}</span>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 m-0">{{ option.description }}</p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- PASO 2: Procesador de Pagos (Multi-pago) -->
                    <div v-else-if="currentStep === 'processPayment'">
                        <a @click="goBackToSelect" class="text-sm text-primary cursor-pointer mb-6 block">&larr; Cambiar
                            tipo de transacción</a>

                        <!-- 
                            ***************************************************
                            AQUÍ IRÁ EL NUEVO MultiPaymentProcessor.vue
                            ***************************************************
                        -->

                        <!-- Placeholder temporal: -->
                        <MultiPaymentProcessor :total-amount="totalAmount" :client="client"
                            :transaction-type="transactionType" :bank-accounts="bankAccounts"
                            :bank-account-options="bankAccountOptions" @submit="handleSubmitFromProcessor"
                            @add-account="showAddBankAccountModal = true" />

                    </div>
                </div>

                <!-- CASO 2: FLUJO DE ABONO A SALDO -->
                <div v-else-if="paymentMode === 'balance'">
                    <!-- 
                        Este flujo salta el selector y va directo al procesador
                        de pagos en modo "abono".
                        
                        AQUÍ TAMBIÉN IRÁ EL MultiPaymentProcessor.vue
                    -->

                    <!-- Placeholder temporal: -->
                    <MultiPaymentProcessor :total-amount="totalAmount" :client="client"
                        :transaction-type="transactionType" :bank-accounts="bankAccounts"
                        :bank-account-options="bankAccountOptions" @submit="handleSubmitFromProcessor"
                        @add-account="showAddBankAccountModal = true" />
                </div>

            </div>
        </div>

        <!-- El footer se deja vacío, ya que el botón de "Finalizar"
             estará dentro del MultiPaymentProcessor -->
        <template #footer>
            <div></div>
        </template>
    </Dialog>

    <!-- Modales auxiliares (sin cambios) -->
    <BankAccountModal :visible="showAddBankAccountModal" @update:visible="showAddBankAccountModal = $event"
        @success="onBankAccountAdded" />
    <CreateCustomerModal :visible="showCreateCustomerModal" @update:visible="showCreateCustomerModal = $event"
        @created="emit('customerCreated', $event)" />
</template>