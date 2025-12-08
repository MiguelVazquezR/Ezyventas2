<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue';
import { useToast } from 'primevue/usetoast';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue'; // <--- Importante

const props = defineProps({
    visible: Boolean,
    transaction: Object,
});

const emit = defineEmits(['update:visible', 'success']);
const toast = useToast();

const currentStepValue = ref('1');
const isLoading = ref(false);

// --- Datos del Paso 2 ---
const returnableItems = ref([]); 

// --- Datos del Paso 3 ---
const productSearchQuery = ref('');
const productSuggestions = ref([]);
const newItems = ref([]); 

// --- Datos del Paso 4 ---
const paymentMethod = ref('efectivo');
const paymentAmount = ref(0);
const paymentNotes = ref('');

// --- NUEVO: Gestión de Cliente y Reembolso ---
const showCreateCustomerModal = ref(false);
const assignedCustomer = ref(null); // Para guardar cliente recién creado
const exchangeRefundType = ref('cash'); // 'balance' o 'cash'

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
        assignedCustomer.value = props.transaction.customer; // Inicializar con el actual
        exchangeRefundType.value = 'cash'; // Default
    }
});

// --- Lógica Paso 2 ---
const totalRefundAmount = computed(() => {
    return returnableItems.value.reduce((sum, item) => sum + (item.return_quantity * parseFloat(item.unit_price)), 0);
});

const validateStep2 = () => {
    if (totalRefundAmount.value <= 0) {
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'Debes seleccionar al menos un producto para devolver.', life: 3000 });
        return false;
    }
    return true;
};

// --- Lógica Paso 3 ---
const searchProducts = async (event) => {
    try {
        const response = await axios.get(route('transactions.search-products'), { params: { query: event.query } });
        productSuggestions.value = response.data;
    } catch (error) { console.error(error); }
};

const addProduct = (event) => {
    const product = event.value;
    const existing = newItems.value.find(i => i.id === product.id);
    if (existing) { existing.quantity++; } 
    else {
        newItems.value.push({
            id: product.id, name: product.name, sku: product.sku, unit_price: parseFloat(product.selling_price), quantity: 1,
            description: product.name, discount: 0, product_attribute_id: product.product_attribute_id || null,
        });
    }
    productSearchQuery.value = ''; 
};

const removeNewItem = (index) => newItems.value.splice(index, 1);
const totalNewSaleAmount = computed(() => newItems.value.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0));

const validateStep3 = () => {
    if (newItems.value.length === 0) {
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'Selecciona los productos que el cliente se llevará.', life: 3000 });
        return false;
    }
    return true;
};

// --- Lógica Paso 4 ---
const balanceDifference = computed(() => totalNewSaleAmount.value - totalRefundAmount.value);

watch(balanceDifference, (newDiff) => {
    if (newDiff > 0) { paymentAmount.value = newDiff; } else { paymentAmount.value = 0; }
});

// Manejador para creación de cliente
const handleCustomerCreated = (newCustomer) => {
    assignedCustomer.value = newCustomer;
    exchangeRefundType.value = 'balance'; // Auto-seleccionar saldo al crear cliente
    toast.add({ severity: 'success', summary: 'Cliente Creado', detail: 'Cliente asignado al intercambio.', life: 3000 });
};

// --- Enviar Formulario ---
const form = useForm({
    cash_register_session_id: null, returned_items: [], new_items: [], subtotal: 0, total_discount: 0, payments: [], notes: '',
    new_customer_id: null, exchange_refund_type: null // Nuevos campos
});

const submitExchange = () => {
    isLoading.value = true;

    // Validación extra: Si sobra dinero, hay que decidir qué hacer
    if (balanceDifference.value < -0.01) {
        // Si eligió saldo pero no hay cliente, error (aunque la UI debería bloquearlo)
        if (exchangeRefundType.value === 'balance' && !assignedCustomer.value) {
            toast.add({ severity: 'error', summary: 'Falta Cliente', detail: 'Debes registrar un cliente para abonar a saldo.', life: 4000 });
            isLoading.value = false;
            return;
        }
    }

    const itemsToReturn = returnableItems.value.filter(i => i.return_quantity > 0).map(i => ({ item_id: i.id, quantity: i.return_quantity }));
    const paymentsPayload = [];
    if (balanceDifference.value > 0.01) {
        if (paymentAmount.value < balanceDifference.value - 0.01) { 
             toast.add({ severity: 'error', summary: 'Error', detail: 'El pago debe cubrir la diferencia total.', life: 3000 });
             isLoading.value = false;
             return;
        }
        paymentsPayload.push({ amount: paymentAmount.value, method: paymentMethod.value, notes: 'Diferencia por cambio. ' + paymentNotes.value, bank_account_id: null });
    }

    form.cash_register_session_id = props.transaction.cash_register_session_id; 
    form.returned_items = itemsToReturn;
    form.new_items = newItems.value;
    form.subtotal = totalNewSaleAmount.value;
    form.total_discount = 0; 
    form.payments = paymentsPayload;
    form.notes = paymentNotes.value;
    
    // Enviar datos nuevos
    form.new_customer_id = assignedCustomer.value ? assignedCustomer.value.id : null;
    form.exchange_refund_type = exchangeRefundType.value;

    form.post(route('transactions.exchange', props.transaction.id), {
        onSuccess: () => {
            emit('success');
            emit('update:visible', false);
        },
        onError: (errors) => {
            console.error(errors);
            toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa los datos e intenta de nuevo.', life: 5000 });
        },
        onFinish: () => isLoading.value = false
    });
};

const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(val);
const nextStep2 = (cb) => { if (validateStep2()) cb('3'); };
const nextStep3 = (cb) => { if (validateStep3()) cb('4'); };

</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Asistente de intercambio" :style="{ width: '55rem' }" :breakpoints="{ '960px': '85vw', '641px': '100vw' }">
        
        <div class="card flex justify-center">
            <Stepper v-model:value="currentStepValue" linear class="w-full">
                <StepList>
                    <Step value="1">Inicio</Step>
                    <Step value="2">Devolución</Step>
                    <Step value="3">Nuevos</Step>
                    <Step value="4">Pago</Step>
                </StepList>
                <StepPanels>
                    
                    <!-- PASO 1 (Sin cambios) -->
                    <StepPanel v-slot="{ activateCallback }" value="1">
                        <div class="flex flex-col h-auto min-h-[300px] items-center justify-center text-center p-4">
                            <i class="pi pi-sync !text-6xl text-orange-500 mb-6"></i>
                            <h3 class="text-2xl font-bold mb-2 text-gray-800 dark:text-white">Proceso de cambio</h3>
                            <p class="text-gray-600 dark:text-gray-300 mb-6 max-w-lg text-lg">
                                Este asistente te guiará para registrar un cambio.
                                Se devolverán productos al inventario y se descontarán los nuevos.
                                La diferencia de saldo se ajustará automáticamente.
                            </p>
                            <div class="bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 p-3 rounded-md inline-block border border-blue-100 dark:border-blue-800">
                                <strong>Nota:</strong> Se generará una nueva venta vinculada a esta transacción.
                            </div>
                        </div>
                        <div class="flex pt-4 justify-end">
                            <Button label="Comenzar" icon="pi pi-arrow-right" iconPos="right" @click="activateCallback('2')" />
                        </div>
                    </StepPanel>

                    <!-- PASO 2 (Sin cambios) -->
                    <StepPanel v-slot="{ activateCallback }" value="2">
                        <div class="flex flex-col min-h-[350px]">
                            <h4 class="font-bold mb-3 text-lg text-gray-800 dark:text-white">¿Qué productos devuelve el cliente?</h4>
                            <div class="border dark:border-gray-700 rounded-lg overflow-hidden flex-grow">
                                <table class="w-full text-sm text-left">
                                    <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold">
                                        <tr>
                                            <th class="p-3">Producto</th>
                                            <th class="p-3 text-center">Comprado</th>
                                            <th class="p-3 text-center">Precio Unit.</th>
                                            <th class="p-3 text-center">Devolver</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y dark:divide-gray-700">
                                        <tr v-for="item in returnableItems" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <td class="p-3">
                                                <p class="font-semibold text-gray-800 dark:text-gray-200">{{ item.description }}</p>
                                            </td>
                                            <td class="p-3 text-center text-gray-600 dark:text-gray-400">{{ item.quantity }}</td>
                                            <td class="p-3 text-center text-gray-600 dark:text-gray-400">{{ formatCurrency(item.unit_price) }}</td>
                                            <td class="p-3 text-center w-36">
                                                <InputNumber v-model="item.return_quantity" showButtons :min="0" :max="item.max_quantity" 
                                                    buttonLayout="horizontal" inputClass="w-12 text-center !p-1" class="h-8 w-full" >
                                                    <template #incrementbuttonicon> <span class="pi pi-plus" /> </template>
                                                    <template #decrementbuttonicon> <span class="pi pi-minus" /> </template>
                                                </InputNumber>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4 text-right bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-100 dark:border-green-800">
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Valor total de devolución:</p>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(totalRefundAmount) }}</p>
                            </div>
                        </div>
                        <div class="flex pt-4 justify-between">
                            <Button label="Atrás" severity="secondary" icon="pi pi-arrow-left" @click="activateCallback('1')" />
                            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right" @click="nextStep2(activateCallback)" />
                        </div>
                    </StepPanel>

                    <!-- PASO 3 (Sin cambios) -->
                    <StepPanel v-slot="{ activateCallback }" value="3">
                        <div class="flex flex-col min-h-[350px]">
                            <h4 class="font-bold mb-3 text-lg text-gray-800 dark:text-white">¿Qué productos se lleva?</h4>
                            
                            <div class="mb-4 relative">
                                <span class="p-input-icon-left w-full">
                                    <AutoComplete v-model="productSearchQuery" :suggestions="productSuggestions" @complete="searchProducts"
                                        @item-select="addProduct" optionLabel="name" placeholder="Buscar producto por nombre o SKU..."
                                        class="w-full" :delay="400" inputClass="pl-10" fluid>
                                        <template #option="slotProps">
                                            <div class="flex justify-between items-center w-full gap-4">
                                                <span class="truncate">{{ slotProps.option.name }}</span>
                                                <span class="font-mono font-bold text-blue-600 whitespace-nowrap">{{ formatCurrency(slotProps.option.selling_price) }}</span>
                                            </div>
                                        </template>
                                    </AutoComplete>
                                </span>
                            </div>

                            <div v-if="newItems.length > 0" class="border dark:border-gray-700 rounded-lg overflow-hidden max-h-60 overflow-y-auto flex-grow">
                                <table class="w-full text-sm text-left">
                                    <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold sticky top-0 z-10">
                                        <tr>
                                            <th class="p-3">Producto</th>
                                            <th class="p-3 text-center">Precio</th>
                                            <th class="p-3 text-center">Cant.</th>
                                            <th class="p-3 text-center">Total</th>
                                            <th class="p-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y dark:divide-gray-700">
                                        <tr v-for="(item, index) in newItems" :key="index" class="bg-white dark:bg-gray-900">
                                            <td class="p-3 text-gray-800 dark:text-gray-200">{{ item.name }}</td>
                                            <td class="p-3 text-center text-gray-600 dark:text-gray-400">{{ formatCurrency(item.unit_price) }}</td>
                                            <td class="p-3 text-center w-24">
                                                <InputNumber v-model="item.quantity" :min="1" inputClass="w-12 text-center !p-1" class="h-8 w-full" showButtons buttonLayout="horizontal">
                                                    <template #incrementbuttonicon> <span class="pi pi-plus" /> </template>
                                                    <template #decrementbuttonicon> <span class="pi pi-minus" /> </template>
                                                </InputNumber>
                                            </td>
                                            <td class="p-3 text-center font-bold text-gray-800 dark:text-gray-200">{{ formatCurrency(item.unit_price * item.quantity) }}</td>
                                            <td class="p-3 text-center w-10">
                                                <Button icon="pi pi-trash" text rounded severity="danger" @click="removeNewItem(index)" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="text-center py-12 text-gray-400 dark:text-gray-500 border-2 border-dashed dark:border-gray-700 rounded-lg flex-grow flex items-center justify-center flex-col">
                                <i class="pi pi-search text-4xl mb-3 opacity-50"></i>
                                <p>Busca y agrega productos para el intercambio</p>
                            </div>

                            <div class="mt-4 text-right bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-800">
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Total nueva venta:</p>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(totalNewSaleAmount) }}</p>
                            </div>
                        </div>
                        <div class="flex pt-4 justify-between">
                            <Button label="Atrás" severity="secondary" icon="pi pi-arrow-left" @click="activateCallback('2')" />
                            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right" @click="nextStep3(activateCallback)" />
                        </div>
                    </StepPanel>

                    <!-- PASO 4: RESUMEN Y PAGO (MODIFICADO) -->
                    <StepPanel v-slot="{ activateCallback }" value="4">
                        <div class="flex flex-col min-h-[350px]">
                            <h4 class="font-bold mb-4 text-center text-xl text-gray-800 dark:text-white">Resumen del intercambio</h4>
                            
                            <div class="grid grid-cols-2 gap-4 mb-6">
                                <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg text-center border border-green-200 dark:border-green-800">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Valor devolución</p>
                                    <p class="text-xl font-bold text-green-700 dark:text-green-400">- {{ formatCurrency(totalRefundAmount) }}</p>
                                </div>
                                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg text-center border border-blue-200 dark:border-blue-800">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Nueva venta</p>
                                    <p class="text-xl font-bold text-blue-700 dark:text-blue-400">+ {{ formatCurrency(totalNewSaleAmount) }}</p>
                                </div>
                            </div>

                            <div class="text-center mb-6 py-4 rounded-xl" :class="balanceDifference > 0 ? 'bg-red-50 dark:bg-red-900/10' : 'bg-green-50 dark:bg-green-900/10'">
                                <p class="text-lg font-medium text-gray-600 dark:text-gray-400">Diferencia final:</p>
                                <p class="text-4xl font-black my-1" :class="balanceDifference > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                                    {{ formatCurrency(Math.abs(balanceDifference)) }}
                                </p>
                                <p class="text-sm font-bold tracking-wider uppercase" :class="balanceDifference > 0 ? 'text-red-500' : 'text-green-500'">
                                    {{ balanceDifference > 0 ? 'EL CLIENTE DEBE PAGAR' : 'SALDO A FAVOR DEL CLIENTE' }}
                                </p>
                            </div>

                            <!-- Caso: SOBRA DINERO (Balance Negativo) -->
                            <div v-if="balanceDifference < -0.01">
                                <!-- Opción 1: Cliente Asignado -> Abonar Saldo -->
                                <div v-if="assignedCustomer" class="bg-green-100 dark:bg-green-900/30 p-4 rounded-lg border border-green-300 dark:border-green-700 text-green-800 dark:text-green-300 flex flex-col gap-3 mb-4">
                                    <div class="flex items-center gap-3">
                                        <i class="pi pi-check-circle text-2xl"></i>
                                        <div>
                                            <p class="font-bold m-0">Cliente identificado: {{ assignedCustomer.name }}</p>
                                            <p class="text-sm opacity-90 m-0">El excedente se abonará a su saldo a favor.</p>
                                        </div>
                                    </div>
                                    <!-- Selector oculto pero lógico: siempre balance si hay cliente -->
                                    <input type="hidden" :value="exchangeRefundType = 'balance'">
                                </div>

                                <!-- Opción 2: Sin Cliente (Público General) -->
                                <div v-else class="bg-orange-50 dark:bg-orange-900/20 p-4 rounded-lg border border-orange-200 dark:border-orange-800 mb-4">
                                    <div class="flex items-start gap-3 mb-4">
                                        <i class="pi pi-exclamation-triangle !text-lg text-orange-500"></i>
                                        <div>
                                            <p class="font-bold text-orange-700 dark:text-orange-300">Cliente no registrado</p>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                Para guardar este dinero como saldo a favor, debes asignar un cliente. 
                                                De lo contrario, deberás entregar la diferencia en efectivo.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex flex-col gap-3">
                                        <!-- Selector de Acción -->
                                        <div class="flex items-center gap-4 bg-white dark:bg-gray-800 p-2 rounded border dark:border-gray-700">
                                            <div class="flex items-center">
                                                <RadioButton v-model="exchangeRefundType" inputId="refundTypeCash" name="refundType" value="cash" />
                                                <label for="refundTypeCash" class="ml-2 text-sm cursor-pointer">Devolver efectivo</label>
                                            </div>
                                            <div class="flex items-center">
                                                <RadioButton v-model="exchangeRefundType" inputId="refundTypeBalance" name="refundType" value="balance" :disabled="!assignedCustomer" />
                                                <label for="refundTypeBalance" class="ml-2 text-sm cursor-pointer" :class="{'text-gray-400': !assignedCustomer}">Abonar a saldo (Requiere Cliente)</label>
                                            </div>
                                        </div>

                                        <Button label="Registrar nuevo cliente" icon="pi pi-user-plus" severity="primary" outlined 
                                            @click="showCreateCustomerModal = true" class="w-full" />
                                    </div>
                                </div>
                            </div>

                            <!-- Caso: PAGO REQUERIDO (Balance Positivo) -->
                            <div v-if="balanceDifference > 0.01" class="bg-white dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm mb-4">
                                <h5 class="font-bold mb-3 text-sm text-gray-700 dark:text-gray-200 border-b pb-2 dark:border-gray-600">Registrar pago de diferencia</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel value="Método de pago" class="mb-1" />
                                        <SelectButton v-model="paymentMethod" :options="['efectivo', 'tarjeta', 'transferencia']" 
                                            class="w-full text-xs" :allowEmpty="false" />
                                    </div>
                                    <div>
                                        <InputLabel value="Monto recibido" class="mb-1" />
                                        <InputNumber v-model="paymentAmount" mode="currency" currency="MXN" locale="es-MX" class="w-full" :disabled="true" />
                                    </div>
                                </div>
                            </div>

                            <div class="mt-auto">
                                <InputLabel value="Notas internas (opcional)" />
                                <Textarea v-model="paymentNotes" rows="2" class="w-full mt-1" placeholder="Razón del cambio, estado del producto devuelto, etc." />
                            </div>
                        </div>
                        <div class="flex pt-4 justify-between">
                            <Button label="Atrás" severity="secondary" icon="pi pi-arrow-left" @click="activateCallback('3')" />
                            <Button label="Confirmar intercambio" icon="pi pi-check" iconPos="right" severity="success" 
                                @click="submitExchange" :loading="isLoading" />
                        </div>
                    </StepPanel>

                </StepPanels>
            </Stepper>
        </div>
    </Dialog>

    <CreateCustomerModal v-model:visible="showCreateCustomerModal" @created="handleCustomerCreated" />
</template>