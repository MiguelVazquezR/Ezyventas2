<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue';
import { useToast } from 'primevue/usetoast';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';

const props = defineProps({
    visible: Boolean,
    transaction: Object,
    userBankAccounts: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:visible', 'success']);
const toast = useToast();

const currentStepValue = ref('1');
const isLoading = ref(false);

// --- Datos del Paso 2: Devolución ---
const returnableItems = ref([]);

// --- Datos del Paso 3: Nuevos Productos ---
const productSearchQuery = ref('');
const productSuggestions = ref([]);
const newItems = ref([]);

// --- Selección de Variantes ---
const variantSelectorVisible = ref(false);
const currentProductForVariants = ref(null);
const availableVariants = ref([]);

// --- Datos del Paso 4: Pago ---
const paymentMethod = ref('efectivo');
const paymentAmount = ref(0);
const paymentNotes = ref('');
const selectedBankAccountId = ref(null);
const pendingDebts = ref([]);
const selectedDebtsIds = ref([]);
const useBalance = ref(true); // Por defecto sugerimos usar el saldo

// --- Gestión de Cliente y Reembolso ---
const showCreateCustomerModal = ref(false);
const assignedCustomer = ref(null);
const exchangeRefundType = ref('cash'); 

// --- Popover de Detalles de Deuda ---
const op = ref();
const selectedDebtForDetails = ref(null);
const selectedDebtItems = ref([]);
const isLoadingDebtDetails = ref(false);

// --- Inicialización ---
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        currentStepValue.value = '1';
        returnableItems.value = props.transaction.items.map(item => ({
            ...item,
            return_quantity: 0,
            max_quantity: item.quantity
        }));
        newItems.value = [];
        paymentAmount.value = 0;
        paymentNotes.value = '';
        assignedCustomer.value = props.transaction.customer;
        exchangeRefundType.value = 'cash';
        paymentMethod.value = 'efectivo';
        selectedBankAccountId.value = null;
        pendingDebts.value = [];
        selectedDebtsIds.value = [];
        useBalance.value = true;
        
        // Cargar deudas si hay cliente
        if (assignedCustomer.value) {
            fetchPendingDebts(assignedCustomer.value.id);
        }
    }
});

const fetchPendingDebts = async (customerId) => {
    try {
        const response = await axios.get(route('customers.pending-debts', customerId));
        pendingDebts.value = response.data.filter(d => d.id !== props.transaction.id);
    } catch (e) {
        console.warn('No se pudieron cargar las deudas del cliente', e);
        pendingDebts.value = [];
    }
};

const toggleDebtDetails = async (event, debt) => {
    selectedDebtForDetails.value = debt;
    selectedDebtItems.value = [];
    isLoadingDebtDetails.value = true;
    op.value.toggle(event);

    try {
        const response = await axios.get(route('transactions.show', debt.id), {
            headers: { 'Accept': 'application/json' }
        });
        selectedDebtItems.value = response.data.items || response.data.transaction?.items || [];
    } catch (e) {
        console.error("Error al cargar detalles de la venta", e);
        toast.add({ severity: 'info', summary: 'Info', detail: 'No se pudieron cargar los detalles detallados.', life: 2000 });
    } finally {
        isLoadingDebtDetails.value = false;
    }
};

// --- Lógica Paso 2 (Devolución) ---
const totalRefundAmount = computed(() => {
    return returnableItems.value.reduce((sum, item) => {
        return sum + (item.return_quantity * parseFloat(item.unit_price));
    }, 0);
});

const validateStep2 = () => {
    if (totalRefundAmount.value <= 0) {
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'Debes seleccionar al menos un producto para devolver.', life: 3000 });
        return false;
    }
    return true;
};

// --- Lógica Paso 3 (Nuevos Productos y Variantes) ---
const searchProducts = async (event) => {
    try {
        const response = await axios.get(route('transactions.search-products'), {
            params: { query: event.query }
        });
        productSuggestions.value = response.data;
    } catch (error) {
        console.error("Error buscando productos", error);
    }
};

const addProduct = (event) => {
    const product = event.value;

    // Si el producto tiene variantes, abrimos el selector en lugar de agregarlo directo
    if (product.variants && product.variants.length > 0) {
        currentProductForVariants.value = product;
        availableVariants.value = product.variants;
        variantSelectorVisible.value = true;
        // Limpiamos el query para que el usuario pueda buscar otro producto si cancela
        productSearchQuery.value = ''; 
        return;
    }

    // Flujo normal para producto simple
    insertProductToCart(product);
};

const selectVariant = (variant) => {
    if (!currentProductForVariants.value) return;

    // Construimos un objeto que mezcla datos del padre y la variante
    const productToAdd = {
        id: currentProductForVariants.value.id, // ID del padre (para referencia)
        product_attribute_id: variant.id,       // ID de la variante (CRÍTICO)
        name: currentProductForVariants.value.name,
        sku: variant.sku_suffix ? `${currentProductForVariants.value.sku}-${variant.sku_suffix}` : currentProductForVariants.value.sku,
        // El precio de la variante es: precio base + modificador
        selling_price: parseFloat(currentProductForVariants.value.selling_price) + parseFloat(variant.selling_price_modifier || 0),
        description: formatVariantAttributes(variant.attributes), // Descripción extra (ej. Talla: M)
    };

    insertProductToCart(productToAdd);
    
    // Cerrar modal y limpiar
    variantSelectorVisible.value = false;
    currentProductForVariants.value = null;
    availableVariants.value = [];
};

const insertProductToCart = (product) => {
    // Buscamos si ya existe el item en el carrito (mismo producto Y misma variante)
    const existing = newItems.value.find(i => 
        i.id === product.id && i.product_attribute_id === (product.product_attribute_id || null)
    );

    if (existing) {
        existing.quantity++;
    } else {
        newItems.value.push({
            id: product.id, // ID del Product padre
            product_attribute_id: product.product_attribute_id || null, // ID de ProductAttribute (si existe)
            name: product.name,
            sku: product.sku,
            unit_price: parseFloat(product.selling_price),
            quantity: 1,
            description: product.description || product.name, // Descripción variante o nombre
            discount: 0,
        });
    }
    productSearchQuery.value = '';
};

// Helper para formatear atributos JSON (ej: {"Color":"Rojo"} -> "Color: Rojo")
const formatVariantAttributes = (attributes) => {
    if (!attributes) return '';
    if (typeof attributes === 'string') return attributes;
    return Object.entries(attributes)
        .map(([key, value]) => `${key}: ${value}`)
        .join(', ');
};

const handleCustomerCreated = (newCustomer) => {
    assignedCustomer.value = newCustomer;
    exchangeRefundType.value = 'balance';
    fetchPendingDebts(newCustomer.id);
    toast.add({ severity: 'success', summary: 'Cliente Creado', detail: 'Cliente asignado al intercambio.', life: 3000 });
};

const removeNewItem = (index) => {
    newItems.value.splice(index, 1);
};

const totalNewSaleAmount = computed(() => {
    return newItems.value.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
});

const validateStep3 = () => {
    if (newItems.value.length === 0) {
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'Selecciona los productos que el cliente se llevará.', life: 3000 });
        return false;
    }
    return true;
};

// --- Lógica Paso 4 (Resumen, Deudas y Pago) ---
const originalTransactionDebt = computed(() => {
    if (!props.transaction) return 0;
    const totalPaid = props.transaction.paid_amount !== undefined 
        ? parseFloat(props.transaction.paid_amount) 
        : (props.transaction.payments || []).reduce((acc, p) => acc + parseFloat(p.amount), 0);
    
    const originalTotal = parseFloat(props.transaction.total) || 
        (parseFloat(props.transaction.subtotal) - parseFloat(props.transaction.total_discount) + parseFloat(props.transaction.total_tax));

    return Math.max(0, originalTotal - totalPaid);
});

const debtCancellationAmount = computed(() => {
    return Math.min(totalRefundAmount.value, originalTransactionDebt.value);
});

const effectiveRefundCredit = computed(() => {
    return totalRefundAmount.value - debtCancellationAmount.value;
});

// NUEVO: Saldo del cliente
const customerBalance = computed(() => {
    return assignedCustomer.value ? parseFloat(assignedCustomer.value.balance || 0) : 0;
});

// NUEVO: Diferencia Bruta (antes de usar saldo a favor)
const grossBalanceDifference = computed(() => {
    return totalNewSaleAmount.value - effectiveRefundCredit.value;
});

// NUEVO: Monto de Saldo a Favor que se usará
const balanceToUse = computed(() => {
    // Solo usamos saldo si la diferencia es positiva (hay deuda) Y el usuario quiere usarlo Y hay saldo
    if (!useBalance.value || grossBalanceDifference.value <= 0.01 || customerBalance.value <= 0) return 0;
    
    return Math.min(grossBalanceDifference.value, customerBalance.value);
});

// NUEVO: Diferencia Neta (reemplaza la lógica anterior de balanceDifference)
const balanceDifference = computed(() => {
    return grossBalanceDifference.value - balanceToUse.value;
});

const surplusToDistribute = computed(() => {
    // Si la diferencia es negativa, es excedente
    if (balanceDifference.value >= -0.01) return 0;
    return Math.abs(balanceDifference.value);
});

watch(balanceDifference, (newDiff) => {
    if (newDiff > 0) {
        paymentAmount.value = newDiff;
    } else {
        paymentAmount.value = 0;
    }
    if (newDiff <= 0.01) {
        selectedDebtsIds.value = [];
    }
});

const availableCredit = computed(() => assignedCustomer.value?.available_credit || 0);

const debtsDistribution = computed(() => {
    if (surplusToDistribute.value <= 0.01) return [];
    
    let remaining = surplusToDistribute.value;
    const distribution = [];
    
    for (const debt of pendingDebts.value) {
        if (selectedDebtsIds.value.includes(debt.id)) {
            const amountToPay = Math.min(debt.pending_amount, remaining);
            if (amountToPay > 0) {
                const isFullyPaid = (debt.pending_amount - amountToPay) <= 0.01;
                distribution.push({
                    id: debt.id,
                    folio: debt.folio,
                    pending_amount: debt.pending_amount,
                    pay_amount: amountToPay,
                    remaining_after: debt.pending_amount - amountToPay,
                    is_fully_paid: isFullyPaid
                });
                remaining -= amountToPay;
            }
        }
        if (remaining <= 0.001) break;
    }
    return distribution;
});

const remainingSurplusAfterDebts = computed(() => {
    if (surplusToDistribute.value <= 0.01) return 0;
    const totalAllocated = debtsDistribution.value.reduce((sum, item) => sum + item.pay_amount, 0);
    return surplusToDistribute.value - totalAllocated;
});

// --- Enviar Formulario ---
const form = useForm({
    cash_register_session_id: null,
    returned_items: [],
    new_items: [],
    subtotal: 0,
    total_discount: 0,
    payments: [],
    notes: '',
    new_customer_id: null,
    exchange_refund_type: null,
    use_credit_for_shortage: false,
    debts_to_pay: [], 
    use_balance: false, // NUEVO CAMPO
});

const submitExchange = () => {
    isLoading.value = true;

    if (balanceDifference.value > 0.01) {
        if (paymentMethod.value === 'credito') {
            if (!assignedCustomer.value) {
                toast.add({ severity: 'error', summary: 'Error', detail: 'Se requiere un cliente registrado para dejar a crédito.', life: 4000 });
                isLoading.value = false;
                return;
            }
             if (balanceDifference.value > availableCredit.value) {
                toast.add({ severity: 'error', summary: 'Crédito insuficiente', detail: 'El cliente no tiene suficiente crédito disponible.', life: 4000 });
                isLoading.value = false;
                return;
            }
        } else if (['tarjeta', 'transferencia'].includes(paymentMethod.value) && !selectedBankAccountId.value) {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Selecciona una cuenta bancaria destino.', life: 3000 });
            isLoading.value = false;
            return;
        }
    }

    const itemsToReturn = returnableItems.value
        .filter(i => i.return_quantity > 0)
        .map(i => ({
            item_id: i.id,
            quantity: i.return_quantity
        }));

    const paymentsPayload = [];
    let useCredit = false;

    if (balanceDifference.value > 0.01) {
        if (paymentMethod.value === 'credito') {
            useCredit = true;
        } else {
            if (paymentAmount.value < balanceDifference.value - 0.01) {
                toast.add({ severity: 'error', summary: 'Error', detail: 'El pago debe cubrir la diferencia total.', life: 3000 });
                isLoading.value = false;
                return;
            }
            paymentsPayload.push({
                amount: paymentAmount.value,
                method: paymentMethod.value,
                notes: 'Diferencia por cambio. ' + paymentNotes.value,
                bank_account_id: selectedBankAccountId.value,
            });
        }
    }

    form.cash_register_session_id = props.transaction.cash_register_session_id;
    form.returned_items = itemsToReturn;
    form.new_items = newItems.value;
    form.subtotal = totalNewSaleAmount.value;
    form.total_discount = 0;
    form.payments = paymentsPayload;
    form.notes = paymentNotes.value;
    form.new_customer_id = assignedCustomer.value ? assignedCustomer.value.id : null;
    form.exchange_refund_type = exchangeRefundType.value;
    form.use_credit_for_shortage = useCredit;
    form.use_balance = useBalance.value; // ENVIAR INDICADOR DE USO DE SALDO

    form.debts_to_pay = debtsDistribution.value.map(d => ({
        id: d.id,
        amount: d.pay_amount
    }));

    form.post(route('transactions.exchange', props.transaction.id), {
        onSuccess: () => {
            emit('success');
            emit('update:visible', false);
        },
        onError: (errors) => {
            console.error(errors);
            const msg = errors.message || Object.values(errors)[0] || 'Revisa los datos e intenta de nuevo.';
            toast.add({ severity: 'error', summary: 'Error', detail: msg, life: 5000 });
        },
        onFinish: () => isLoading.value = false
    });
};

const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(val);
const formatDate = (dateStr) => new Date(dateStr).toLocaleDateString('es-MX');

const nextStep2 = (activateCallback) => { if (validateStep2()) activateCallback('3'); };
const nextStep3 = (activateCallback) => { if (validateStep3()) activateCallback('4'); };

</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Asistente de intercambio"
        :style="{ width: '60rem' }" :breakpoints="{ '960px': '90vw', '641px': '100vw' }">

        <div class="card flex justify-center">
            <Stepper v-model:value="currentStepValue" linear class="w-full">
                <StepList>
                    <Step value="1">Inicio</Step>
                    <Step value="2">Devolución</Step>
                    <Step value="3">Nuevos</Step>
                    <Step value="4">Confirmación</Step>
                </StepList>
                <StepPanels>

                    <!-- PASO 1: Inicio -->
                    <StepPanel v-slot="{ activateCallback }" value="1">
                        <div class="flex flex-col h-auto min-h-[300px] items-center justify-center text-center p-4">
                            <i class="pi pi-sync !text-6xl text-orange-500 mb-6"></i>
                            <h3 class="text-2xl font-bold mb-2 text-gray-800 dark:text-white m-0">Proceso de cambio</h3>
                            <p class="text-gray-600 dark:text-gray-300 mb-6 max-w-lg text-lg">
                                Este asistente te guiará para registrar un cambio.
                                Se devolverán productos al inventario y se descontarán los nuevos.
                                La diferencia de saldo se ajustará automáticamente.
                            </p>
                            <div
                                class="bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 p-3 rounded-md inline-block border border-blue-100 dark:border-blue-800">
                                <strong>Nota:</strong> Se generará una nueva venta vinculada a esta transacción.
                            </div>
                        </div>
                        <div class="flex pt-4 justify-end">
                            <Button label="Comenzar" icon="pi pi-arrow-right" iconPos="right"
                                @click="activateCallback('2')" />
                        </div>
                    </StepPanel>

                    <!-- PASO 2: Devolución -->
                    <StepPanel v-slot="{ activateCallback }" value="2">
                        <div class="flex flex-col min-h-[350px]">
                            <h4 class="font-bold mb-3 text-lg text-gray-800 dark:text-white">¿Qué productos devuelve el
                                cliente?</h4>
                            <div class="border dark:border-gray-700 rounded-lg overflow-hidden flex-grow">
                                <table class="w-full text-sm text-left">
                                    <thead
                                        class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold">
                                        <tr>
                                            <th class="p-3">Producto</th>
                                            <th class="p-3 text-center">Comprado</th>
                                            <th class="p-3 text-center">Precio Unit.</th>
                                            <th class="p-3 text-center">Devolver</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y dark:divide-gray-700">
                                        <tr v-for="item in returnableItems" :key="item.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <td class="p-3">
                                                <p class="font-semibold text-gray-800 dark:text-gray-200">{{
                                                    item.description }}</p>
                                            </td>
                                            <td class="p-3 text-center text-gray-600 dark:text-gray-400">{{
                                                item.quantity }}</td>
                                            <td class="p-3 text-center text-gray-600 dark:text-gray-400">{{
                                                formatCurrency(item.unit_price) }}</td>
                                            <td class="p-3 text-center w-36">
                                                <InputNumber v-model="item.return_quantity" showButtons :min="0"
                                                    :max="item.max_quantity" buttonLayout="horizontal"
                                                    inputClass="w-12 text-center !p-1" class="h-8 w-full">
                                                    <template #incrementbuttonicon> <span class="pi pi-plus" />
                                                    </template>
                                                    <template #decrementbuttonicon> <span class="pi pi-minus" />
                                                    </template>
                                                </InputNumber>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div
                                class="mt-4 text-right bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-100 dark:border-green-800">
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Valor total de devolución:</p>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{
                                    formatCurrency(totalRefundAmount) }}</p>
                            </div>
                        </div>
                        <div class="flex pt-4 justify-between">
                            <Button label="Atrás" severity="secondary" icon="pi pi-arrow-left"
                                @click="activateCallback('1')" />
                            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                                @click="nextStep2(activateCallback)" />
                        </div>
                    </StepPanel>

                    <!-- PASO 3: Nuevos Productos -->
                    <StepPanel v-slot="{ activateCallback }" value="3">
                        <div class="flex flex-col min-h-[350px]">
                            <h4 class="font-bold mb-3 text-lg text-gray-800 dark:text-white">¿Qué productos se lleva?
                            </h4>

                            <div class="mb-4 relative">
                                <IconField iconPosition="left" class="w-full md:w-2/3">
                                     <InputIcon class="pi pi-search"></InputIcon>
                                    <AutoComplete v-model="productSearchQuery" :suggestions="productSuggestions"
                                        @complete="searchProducts" @item-select="addProduct" optionLabel="name"
                                        placeholder="Buscar producto por nombre o SKU..." class="w-full" :delay="400"
                                        inputClass="pl-10" fluid>
                                        <template #option="slotProps">
                                            <div class="flex justify-between items-center w-full gap-4">
                                                <span class="truncate">{{ slotProps.option.name }}</span>
                                                <span class="font-mono font-bold text-blue-600 whitespace-nowrap">{{
                                                    formatCurrency(slotProps.option.selling_price) }}</span>
                                            </div>
                                        </template>
                                    </AutoComplete>
                                </IconField>
                            </div>

                            <div v-if="newItems.length > 0"
                                class="border dark:border-gray-700 rounded-lg overflow-hidden max-h-60 overflow-y-auto flex-grow">
                                <table class="w-full text-sm text-left">
                                    <thead
                                        class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold sticky top-0 z-10">
                                        <tr>
                                            <th class="p-3">Producto</th>
                                            <th class="p-3 text-center">Precio</th>
                                            <th class="p-3 text-center">Cant.</th>
                                            <th class="p-3 text-center">Total</th>
                                            <th class="p-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y dark:divide-gray-700">
                                        <tr v-for="(item, index) in newItems" :key="index"
                                            class="bg-white dark:bg-gray-900">
                                            <td class="p-3 text-gray-800 dark:text-gray-200">
                                                <div>{{ item.name }}</div>
                                                <div v-if="item.description !== item.name" class="text-xs text-gray-500">{{ item.description }}</div>
                                            </td>
                                            <td class="p-3 text-center text-gray-600 dark:text-gray-400">{{
                                                formatCurrency(item.unit_price)
                                            }}</td>
                                            <td class="p-3 text-center w-24">
                                                <InputNumber v-model="item.quantity" :min="1"
                                                    inputClass="w-12 text-center !p-1" class="h-8 w-full" showButtons
                                                    buttonLayout="horizontal">
                                                    <template #incrementbuttonicon> <span class="pi pi-plus" />
                                                    </template>
                                                    <template #decrementbuttonicon> <span class="pi pi-minus" />
                                                    </template>
                                                </InputNumber>
                                            </td>
                                            <td class="p-3 text-center font-bold text-gray-800 dark:text-gray-200">{{
                                                formatCurrency(item.unit_price * item.quantity) }}</td>
                                            <td class="p-3 text-center w-10">
                                                <Button icon="pi pi-trash" text rounded severity="danger"
                                                    @click="removeNewItem(index)" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else
                                class="text-center py-12 text-gray-400 dark:text-gray-500 border-2 border-dashed dark:border-gray-700 rounded-lg flex-grow flex items-center justify-center flex-col">
                                <i class="pi pi-search text-4xl mb-3 opacity-50"></i>
                                <p>Busca y agrega productos para el intercambio</p>
                            </div>

                            <div
                                class="mt-4 text-right bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-800">
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Total nueva venta:</p>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{
                                    formatCurrency(totalNewSaleAmount) }}</p>
                            </div>
                        </div>
                        <div class="flex pt-4 justify-between">
                            <Button label="Atrás" severity="secondary" icon="pi pi-arrow-left"
                                @click="activateCallback('2')" />
                            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                                @click="nextStep3(activateCallback)" />
                        </div>
                    </StepPanel>

                    <!-- PASO 4: RESUMEN Y PAGO -->
                    <StepPanel v-slot="{ activateCallback }" value="4">
                        <div class="flex flex-col min-h-[350px]">
                            
                            <!-- DESGLOSE MATEMÁTICO -->
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mb-6 text-sm">
                                <h5 class="font-bold text-gray-700 dark:text-gray-200 mb-3 border-b dark:border-gray-700 pb-1">Desglose de la operación</h5>
                                
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-gray-600 dark:text-gray-400">Valor de productos devueltos</span>
                                    <span class="font-medium">{{ formatCurrency(totalRefundAmount) }}</span>
                                </div>

                                <div v-if="debtCancellationAmount > 0" class="flex justify-between items-center mb-1 text-orange-600 dark:text-orange-400">
                                    <span class="flex items-center">
                                        <i class="pi pi-minus-circle mr-1 text-xs"></i> 
                                        Menos: Cancelación de deuda anterior
                                    </span>
                                    <span>- {{ formatCurrency(debtCancellationAmount) }}</span>
                                </div>
                                <div v-else-if="originalTransactionDebt > 0" class="flex justify-between items-center mb-1 text-gray-400 italic text-xs">
                                    <span>(Esta venta no tenía deuda pendiente)</span>
                                    <span>$0.00</span>
                                </div>

                                <div class="border-t border-gray-300 dark:border-gray-600 my-2"></div>
                                
                                <div class="flex justify-between items-center mb-1 font-bold">
                                    <span class="text-green-700 dark:text-green-400">Crédito efectivo disponible por pagos de venta original</span>
                                    <span class="text-green-700 dark:text-green-400">{{ formatCurrency(effectiveRefundCredit) }}</span>
                                </div>

                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-blue-600 dark:text-blue-400">Menos: Valor nueva venta</span>
                                    <span class="font-medium text-blue-600 dark:text-blue-400">- {{ formatCurrency(totalNewSaleAmount) }}</span>
                                </div>

                                <!-- NUEVA LÓGICA: USO DE SALDO A FAVOR -->
                                <div v-if="grossBalanceDifference > 0.01 && customerBalance > 0" class="flex justify-between items-center mt-2 mb-1 bg-blue-100 dark:bg-blue-900/40 p-2 rounded border border-blue-200 dark:border-blue-800">
                                    <div class="flex items-center">
                                        <Checkbox v-model="useBalance" :binary="true" disabled inputId="useBalanceCheck" class="mr-2" />
                                        <label for="useBalanceCheck" class="cursor-pointer select-none text-blue-800 dark:text-blue-200 font-semibold">
                                            Usar saldo a favor ({{ formatCurrency(customerBalance) }})
                                        </label>
                                    </div>
                                    <span class="font-bold text-blue-800 dark:text-blue-200">- {{ formatCurrency(balanceToUse) }}</span>
                                </div>
                                <!-- ----------------------------------- -->

                                <div class="border-t-2 border-gray-300 dark:border-gray-600 my-2"></div>
                                
                                <div class="flex justify-between items-center text-lg font-black">
                                    <span>Diferencia final</span>
                                    <span :class="balanceDifference > 0 ? 'text-red-600' : 'text-green-600'">
                                        {{ balanceDifference > 0 ? 'A PAGAR: ' : 'A FAVOR: ' }} {{ formatCurrency(Math.abs(balanceDifference)) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Lógica Visual del Balance: 3 Estados -->

                            <!-- ESTADO 1: PAGO REQUERIDO (Diferencia > 0) -->
                            <div v-if="balanceDifference > 0.01">
                                <!-- Formulario de Pago -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm mb-4">
                                    <h5 class="font-bold mb-3 text-sm text-gray-700 dark:text-gray-200 border-b pb-2 dark:border-gray-600">
                                        Registrar pago de diferencia
                                    </h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel value="Método de pago" class="mb-1" />
                                            <SelectButton v-model="paymentMethod"
                                                :options="['efectivo', 'tarjeta', 'transferencia', 'credito']"
                                                class="w-full text-xs" :allowEmpty="false" />
                                        </div>
                                        <div>
                                            <InputLabel value="Monto a cobrar" class="mb-1" />
                                            <InputNumber v-model="paymentAmount" mode="currency" currency="MXN"
                                                locale="es-MX" class="w-full" :disabled="paymentMethod === 'credito'" />
                                        </div>
                                    </div>
                                    
                                    <div v-if="paymentMethod === 'credito'" class="mt-3 bg-blue-50 dark:bg-blue-900/20 p-3 rounded border border-blue-100 dark:border-blue-800">
                                        <div v-if="assignedCustomer">
                                            <div class="flex justify-between text-sm mb-1">
                                                <span class="text-gray-600 dark:text-gray-300">Crédito Disponible:</span>
                                                <span class="font-bold text-blue-700 dark:text-blue-300">{{ formatCurrency(availableCredit) }}</span>
                                            </div>
                                            <div class="flex justify-between text-sm mb-2">
                                                <span class="text-gray-600 dark:text-gray-300">Nuevo Saldo tras compra:</span>
                                                <span class="font-bold text-orange-600">{{ formatCurrency(availableCredit - paymentAmount) }}</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                                <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: Math.min(100, (paymentAmount / availableCredit) * 100) + '%' }"></div>
                                            </div>
                                            <p v-if="paymentAmount > availableCredit" class="text-xs text-red-500 mt-1 font-bold">
                                                <i class="pi pi-exclamation-circle"></i> Crédito insuficiente
                                            </p>
                                        </div>
                                        <div v-else class="text-red-500 text-sm flex items-center">
                                            <i class="pi pi-times-circle mr-2"></i>
                                            Selecciona o crea un cliente para usar crédito.
                                        </div>
                                    </div>

                                    <div v-if="['tarjeta', 'transferencia'].includes(paymentMethod)" class="mt-3">
                                        <InputLabel value="Cuenta destino" class="mb-1" />
                                        <Select v-model="selectedBankAccountId" :options="userBankAccounts"
                                            optionLabel="account_name" optionValue="id" placeholder="Selecciona una cuenta"
                                            class="w-full">
                                            <template #option="slotProps">
                                                <div class="flex flex-col">
                                                    <span>{{ slotProps.option.account_name }}</span>
                                                    <span class="text-xs text-gray-500">{{ slotProps.option.bank_name }} - ...{{ slotProps.option.account_number.slice(-4) }}</span>
                                                </div>
                                            </template>
                                        </Select>
                                    </div>
                                </div>
                            </div>

                            <!-- ESTADO 2: SOLO REDUCCIÓN DE DEUDA -->
                            <div v-else-if="balanceDifference <= -0.01 && surplusToDistribute <= 0.01" class="text-center mb-6 py-4 rounded-xl bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30">
                                <p class="text-sm font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">Ajuste de Saldo</p>
                                <div class="mt-2 bg-white dark:bg-gray-800 rounded p-2 inline-block shadow-sm">
                                    <p class="text-xs text-gray-600 dark:text-gray-300">
                                        <i class="pi pi-info-circle mr-1"></i>
                                        El saldo a favor se aplicará automáticamente para reducir la deuda de esta venta.
                                    </p>
                                </div>
                            </div>

                            <!-- ESTADO 3: EXCEDENTE REAL (Surplus > 0) -->
                            <div v-else-if="surplusToDistribute > 0.01" class="animate-fade-in">
                                <div class="text-center mb-4 py-2 rounded bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/30">
                                    <p class="text-sm font-medium text-green-600 dark:text-green-400 uppercase tracking-wide">
                                        Excedente a Favor: <strong>{{ formatCurrency(surplusToDistribute) }}</strong>
                                    </p>
                                </div>
                                
                                <!-- SECCIÓN DE DEUDAS -->
                                <div v-if="assignedCustomer && pendingDebts.length > 0" class="mb-5 border rounded-lg overflow-hidden dark:border-gray-700 shadow-sm">
                                    <div class="bg-gray-100 dark:bg-gray-800 px-4 py-3 border-b dark:border-gray-700 flex justify-between items-center">
                                        <h5 class="text-sm font-bold text-gray-700 dark:text-gray-300 m-0">
                                            <i class="pi pi-list-check mr-2 text-blue-500"></i>Pagar Deudas Pendientes
                                        </h5>
                                        <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Recomendado</span>
                                    </div>
                                    
                                    <div class="p-3 bg-white dark:bg-gray-900">
                                        <p class="text-xs text-gray-500 mb-3">
                                            Selecciona las deudas que se pagarán con el excedente.
                                        </p>
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-xs">
                                                <thead class="bg-gray-50 dark:bg-gray-800 text-gray-500">
                                                    <tr>
                                                        <th class="p-2 w-8 text-center"></th>
                                                        <th class="p-2 text-left">Folio</th>
                                                        <th class="p-2 text-right">Saldo Orig.</th>
                                                        <th class="p-2 text-right">A Abonar</th>
                                                        <th class="p-2 text-right text-gray-800 dark:text-white font-bold bg-gray-50 dark:bg-gray-800">Nuevo Saldo</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y dark:divide-gray-700">
                                                    <tr v-for="debt in pendingDebts" :key="debt.id" 
                                                        :class="{'bg-green-50/50 dark:bg-green-900/10': selectedDebtsIds.includes(debt.id)}">
                                                        <td class="p-2 text-center align-middle">
                                                            <Checkbox v-model="selectedDebtsIds" :value="debt.id" />
                                                        </td>
                                                        <td class="p-2 align-middle">
                                                            <div class="flex flex-col">
                                                                <button @click="toggleDebtDetails($event, debt)" 
                                                                    class="text-blue-600 hover:text-blue-800 hover:underline font-bold text-left flex items-center gap-1">
                                                                    {{ debt.folio }}
                                                                    <i class="pi pi-info-circle text-[10px]"></i>
                                                                </button>
                                                                <span class="text-gray-400 text-[10px]">{{ formatDate(debt.created_at) }}</span>
                                                            </div>
                                                        </td>
                                                        <td class="p-2 text-right align-middle text-gray-600">
                                                            {{ formatCurrency(debt.pending_amount) }}
                                                        </td>
                                                        <td class="p-2 text-right align-middle font-bold">
                                                            <div v-if="selectedDebtsIds.includes(debt.id)">
                                                                <span class="text-green-600 block">
                                                                    {{ formatCurrency(debtsDistribution.find(d => d.id === debt.id)?.pay_amount || 0) }}
                                                                </span>
                                                            </div>
                                                            <span v-else class="text-gray-300">-</span>
                                                        </td>
                                                        <td class="p-2 text-right align-middle font-bold bg-gray-50 dark:bg-gray-800/50">
                                                            <span v-if="selectedDebtsIds.includes(debt.id)" class="text-blue-600">
                                                                {{ formatCurrency(debtsDistribution.find(d => d.id === debt.id)?.remaining_after || debt.pending_amount) }}
                                                            </span>
                                                            <span v-else class="text-gray-500">{{ formatCurrency(debt.pending_amount) }}</span>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <OverlayPanel ref="op" appendTo="body" showCloseIcon>
                                    <div class="w-80 p-1">
                                        <div v-if="isLoadingDebtDetails" class="flex justify-center p-4">
                                            <i class="pi pi-spin pi-spinner text-2xl text-blue-500"></i>
                                        </div>
                                        <div v-else-if="selectedDebtForDetails">
                                            <h6 class="font-bold text-sm mb-2 border-b pb-1">
                                                Detalle de venta {{ selectedDebtForDetails.folio }}
                                            </h6>
                                            <div v-if="selectedDebtItems.length > 0" class="max-h-60 overflow-y-auto">
                                                <ul class="list-none p-0 m-0 text-xs">
                                                    <li v-for="item in selectedDebtItems" :key="item.id" class="flex justify-between py-1 border-b border-dashed last:border-0">
                                                        <span class="truncate w-2/3">{{ item.description }}</span>
                                                        <span class="font-mono">x{{ item.quantity }}</span>
                                                    </li>
                                                </ul>
                                            </div>
                                            <div v-else class="text-center text-xs text-gray-500 py-2">
                                                No se encontraron items.
                                            </div>
                                            <div class="mt-2 text-right">
                                                <span class="text-xs font-bold">Total: {{ formatCurrency(selectedDebtForDetails.total || selectedDebtForDetails.amount || 0) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </OverlayPanel>

                                <!-- Lógica de Remanente (Saldo o Caja) -->
                                <div v-if="remainingSurplusAfterDebts > 0.01" class="border rounded-lg p-4 bg-gray-50 dark:bg-gray-800 dark:border-gray-700">
                                    <div class="flex justify-between items-center mb-3">
                                        <span class="text-sm font-bold text-gray-700 dark:text-gray-300">Excedente Restante</span>
                                        <span class="text-lg font-black text-green-600">{{ formatCurrency(remainingSurplusAfterDebts) }}</span>
                                    </div>

                                    <div v-if="assignedCustomer">
                                        <div class="flex items-center gap-3 p-3 bg-green-100 dark:bg-green-900/30 rounded-md text-green-800 dark:text-green-300 border border-green-200 dark:border-green-800">
                                            <i class="pi pi-check-circle text-xl"></i>
                                            <div>
                                                <p class="text-sm font-bold m-0">Se abonará al saldo del cliente</p>
                                                <p class="text-xs opacity-90 m-0">{{ assignedCustomer.name }}</p>
                                            </div>
                                        </div>
                                        <input type="hidden" :value="exchangeRefundType = 'balance'">
                                    </div>

                                    <div v-else class="bg-white dark:bg-gray-900 p-3 rounded border dark:border-gray-700">
                                        <div class="flex items-start gap-2 mb-3">
                                            <i class="pi pi-exclamation-triangle text-orange-500 mt-0.5"></i>
                                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                                Sin cliente asignado. ¿Qué deseas hacer con el sobrante?
                                            </p>
                                        </div>
                                        
                                        <div class="flex flex-col gap-2">
                                            <div class="flex items-center p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer border border-transparent hover:border-gray-200">
                                                <RadioButton v-model="exchangeRefundType" inputId="refundTypeCash" name="refundType" value="cash" />
                                                <label for="refundTypeCash" class="ml-2 text-sm cursor-pointer w-full">Devolver efectivo al cliente</label>
                                            </div>
                                            
                                            <div class="border-t border-dashed my-1"></div>

                                            <p class="text-xs text-blue-600 font-bold mb-1 ml-1">O guárdalo como saldo:</p>
                                            <Button label="Registrar cliente ahora" icon="pi pi-user-plus" size="small" outlined 
                                                @click="showCreateCustomerModal = true" class="w-full" />
                                        </div>
                                    </div>
                                </div>
                                
                                <div v-else class="text-center text-xs text-green-600 bg-green-50 p-2 rounded mt-3 border border-green-100">
                                    <i class="pi pi-check mr-1"></i> Todo el excedente ha sido asignado a deudas.
                                </div>
                            </div>

                            <div class="mt-auto pt-4">
                                <InputLabel value="Notas internas (opcional)" />
                                <Textarea v-model="paymentNotes" rows="2" class="w-full mt-1"
                                    placeholder="Razón del cambio, estado del producto devuelto, etc." />
                            </div>
                        </div>
                        <div class="flex pt-4 justify-between">
                            <Button label="Atrás" severity="secondary" icon="pi pi-arrow-left"
                                @click="activateCallback('3')" />
                            <Button label="Confirmar intercambio" icon="pi pi-check" iconPos="right" severity="success"
                                @click="submitExchange" :loading="isLoading" />
                        </div>
                    </StepPanel>

                </StepPanels>
            </Stepper>
        </div>
    </Dialog>
    <CreateCustomerModal v-model:visible="showCreateCustomerModal" @created="handleCustomerCreated" />

    <!-- NUEVO: Selector de Variantes (Dialog) -->
    <Dialog v-model:visible="variantSelectorVisible" :header="'Seleccionar variante de ' + (currentProductForVariants?.name || '')" 
        modal :style="{ width: '500px' }">
        <div class="py-2">
            <p class="text-sm text-gray-500 mb-4">Este producto tiene múltiples opciones. Selecciona una para continuar.</p>
            
            <div class="border rounded-md overflow-hidden dark:border-gray-700">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-3 font-bold">Variante</th>
                            <th class="p-3 text-center font-bold">Stock</th>
                            <th class="p-3 text-right font-bold">Precio</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-gray-700">
                        <tr v-for="variant in availableVariants" :key="variant.id" class="hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="p-3">
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ formatVariantAttributes(variant.attributes) }}
                                </span>
                            </td>
                            <td class="p-3 text-center">
                                <span :class="{'text-red-500 font-bold': variant.current_stock <= 0, 'text-green-600': variant.current_stock > 0}">
                                    {{ variant.current_stock }}
                                </span>
                            </td>
                            <td class="p-3 text-right">
                                {{ formatCurrency(parseFloat(currentProductForVariants.selling_price) + parseFloat(variant.selling_price_modifier)) }}
                            </td>
                            <td class="p-3 text-center">
                                <Button label="Seleccionar" size="small" :disabled="variant.current_stock <= 0" 
                                    @click="selectVariant(variant)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </Dialog>
</template>