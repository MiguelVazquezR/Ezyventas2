<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import BankAccountModal from '@/Components/BankAccountModal.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentSummaryColumn from './PaymentModalPartials/PaymentSummaryColumn.vue';
import MultiPaymentProcessor from './PaymentModalPartials/MultiPaymentProcessor.vue';

// --- Props (sin cambios) ---
const props = defineProps({
    visible: Boolean,
    totalAmount: { type: Number, required: true },
    client: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    paymentMode: {
        type: String,
        default: 'strict', // 'strict', 'flexible', 'balance'
    },
    allowCredit: { type: Boolean, default: true },
    allowLayaway: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'submit', 'update:client', 'customerCreated']);
const toast = useToast();

// --- Estado de Flujo (sin cambios) ---
const currentStep = ref('selecType');
const transactionType = ref('contado');

// --- 1. Estado: Selectores de tipo de transacción (sin cambios) ---
const transactionTypes = computed(() => {
    let types = [
        {
            id: 'contado',
            label: 'Pago al contado',
            image: '/images/contado.webp',
            bgColor: '#C5E0F7',
            textColor: '#3D5F9B',
            description: 'Se liquida el 100% de la venta en este momento.',
            disabled: false
        },
        {
            id: 'credito',
            label: 'A crédito / pagos',
            image: '/images/credito.webp',
            bgColor: '#FFCD87',
            textColor: '#603814',
            description: 'Paga un anticipo y el resto se va al saldo deudor del cliente.',
            disabled: !props.allowCredit || !props.client
        },
        {
            id: 'apartado',
            label: 'Sistema de apartado',
            image: '/images/apartado.webp',
            bgColor: '#FFC9E9',
            textColor: '#862384',
            description: 'Paga un anticipo para reservar los productos.',
            disabled: !props.allowLayaway || !props.client
        },
    ];

    if (!props.allowCredit) {
        types = types.filter(t => t.id !== 'credito');
    }
    if (!props.allowLayaway) {
        types = types.filter(t => t.id !== 'apartado');
    }
    return types;
});

const selectTransactionType = (type) => {
    transactionType.value = type;
    currentStep.value = 'processPayment';
};

// --- 2. Estado: Procesador de Pagos (sin cambios) ---
const bankAccounts = ref([]);
const bankAccountOptions = ref([]);
const showAddBankAccountModal = ref(false);
const showCreateCustomerModal = ref(false);

const isLoadingAccounts = ref(false); // Nuevo estado de carga

const fetchBankAccounts = async () => { // Convertido a async
    try {
        const response = await axios.get(route('branch-bank-accounts'));
        bankAccounts.value = response.data;
        bankAccountOptions.value = response.data.map(acc => ({
            label: `${acc.bank_name} - ${acc.account_name} (...${(acc.card_number || acc.account_number || 'N/A').slice(-4)})`,
            value: acc.id
        }));
    } catch (error) { 
        console.error("Error fetching bank accounts:", error); 
        toast.add({ severity: 'error', summary: 'Error de Carga', detail: 'No se pudieron cargar las cuentas bancarias.', life: 5000 });
    }
};

// Esta función ahora no espera ningún argumento (newAccount), solo recarga la lista.
const onBankAccountAdded = () => {
    // Vuelve a cargar la lista de cuentas para obtener la nueva
    fetchBankAccounts(); 
};

// --- Lógica de Envío y Cierre (sin cambios) ---
const closeModal = () => {
    emit('update:visible', false);
};

const handleSubmitFromProcessor = (paymentData) => {
    emit('submit', {
        ...paymentData,
        transactionType: transactionType.value,
    });
    // No cerramos el modal aquí, esperamos a que el padre (Index.vue) lo haga
};


// Actualizamos esta función para manejar el 'paymentMode' flexible
const resetModalState = () => {
    
    // Caso 1: Abono a Saldo (desde CustomerShow.vue)
    // Salta al paso de pago y usa el tipo 'balance'
    if (props.paymentMode === 'balance') {
        currentStep.value = 'processPayment';
        transactionType.value = 'balance';
    } 
    
    // Caso 2: Pago Flexible (desde ServiceOrderShow.vue)
    // Salta al paso de pago y usa el tipo 'flexible'
    else if (props.paymentMode === 'flexible') {
        currentStep.value = 'processPayment';
        transactionType.value = 'flexible'; // <-- Este es el nuevo tipo
    } 
    
    // Caso 3: Venta Estricta (desde POS/Index.vue)
    // Inicia en el paso de selección de tipo
    else {
        currentStep.value = 'selecType';
        transactionType.value = 'contado'; // Valor inicial por defecto
    }
};

watch(() => props.visible, async (isVisible) => {
    if (isVisible) {
        isLoadingAccounts.value = true;
        resetModalState(); // <-- Esta función AHORA SÍ se ejecutará
        await fetchBankAccounts(); // Espera a que las cuentas carguen
        isLoadingAccounts.value = false; // Oculta el spinner
    }
}, { immediate: true });
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Procesar pago" class="w-full max-w-4xl mx-4">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-0">
            <!-- Columna Izquierda: Resumen -->
            <PaymentSummaryColumn :payment-mode="paymentMode" :total-amount="totalAmount" :client="client"
                :transaction-type="transactionType" />

            <!-- Columna Derecha: Contenido Dinámico -->
            <div class="lg:col-span-2 p-4 lg:p-8">
                <!-- 
                  CASO 1: FLUJO DE VENTA (Selección de tipo)
                  Esto ahora solo se muestra si el modo NO es 'balance' Y NO es 'flexible',
                  Y si el paso actual es 'selecType'.
                -->
                <div v-if="paymentMode === 'strict' && currentStep === 'selecType'">
                    <h3 class="text-xl font-semibold text-center mb-6 text-gray-800 dark:text-gray-200">
                        ¿Cómo deseas registrar esta venta?
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <button v-for="type in transactionTypes" :key="type.id" @click="selectTransactionType(type.id)"
                            :disabled="type.disabled"
                            class="flex flex-col items-center justify-center p-4 border rounded-xl transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed hover:brightness-90"
                            :style="{ backgroundColor: type.bgColor, color: type.textColor }"
                            v-tooltip.bottom="type.disabled ? 'Debes seleccionar un cliente para esta opción' : ''">
                            <p class="font-bold text-center mb-1 text-sm">{{ type.label }}</p>
                            <img :src="type.image" :alt="type.label" class="size-16 object-contain mb-1">
                            <p class="text-xs m-0">{{ type.description }}</p>
                        </button>
                    </div>

                    <!-- Selector de Cliente (solo visible si no hay cliente) -->
                    <div v-if="!client && (allowCredit || allowLayaway)" class="mt-8">
                        <Message severity="info" :closable="false">
                            <span class="font-semibold">Se requiere un cliente</span> para ventas a crédito o sistema de
                            apartado.
                        </Message>
                        <div class="flex items-center gap-2 mt-4">
                            <Select :modelValue="client" @update:modelValue="emit('update:client', $event)"
                                :options="customers" optionLabel="name" placeholder="Seleccionar cliente existente..."
                                filter class="w-11/12" />
                            <Button @click="showCreateCustomerModal = true" rounded icon="pi pi-plus"
                                severity="contrast" size="small" v-tooltip.bottom="'Crear nuevo cliente'" />
                        </div>
                    </div>

                </div>

                <!-- 
                  CASO 2: PROCESADOR DE PAGOS
                  Esto se muestra si estamos en el paso 'processPayment' (para 'strict')
                  O si el modo es 'balance' o 'flexible' (que saltan directo aquí).
                -->
                <div v-else-if="currentStep === 'processPayment'">
                    <a v-if="paymentMode === 'strict'" @click="currentStep = 'selecType'"
                        class="text-sm text-primary cursor-pointer mb-5 block hover:underline">
                        &larr; Volver a seleccionar tipo
                    </a>
                    <!-- El 'v-else' asegura que el enlace "Volver" no aparezca en modo balance o flexible -->

                    <!-- Mostrar spinner mientras cargan las cuentas -->
                    <div v-if="isLoadingAccounts" class="flex items-center justify-center min-h-[400px]">
                        <i class="pi pi-spin pi-spinner !text-4xl text-gray-500"></i>
                    </div>
                    
                    <!-- Mostrar el procesador solo cuando las cuentas estén listas -->
                    <MultiPaymentProcessor 
                        v-else
                        :total-amount="totalAmount" 
                        :client="client"
                        :transaction-type="transactionType" 
                        :bank-accounts="bankAccounts"
                        :bank-account-options="bankAccountOptions"
                        :loading="props.loading"
                        @submit="handleSubmitFromProcessor"
                        @add-account="showAddBankAccountModal = true" 
                    />
                </div>

            </div>
        </div>

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