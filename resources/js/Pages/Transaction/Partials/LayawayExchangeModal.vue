<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const props = defineProps({
    visible: Boolean,
    transaction: Object,
    userBankAccounts: Array,
});

const emit = defineEmits(['update:visible', 'success']);
const toast = useToast();
const page = usePage();

// --- Estado ---
const step = ref(1); // 1: Edición, 2: Confirmación
const cart = ref([]);
const searchScanQuery = ref('');
const searchResults = ref([]);
const isSearching = ref(false);
const activeSession = computed(() => page.props.activeSession);

// Formulario principal
const form = useForm({
    cash_register_session_id: null,
    returned_items: [],
    new_items: [],
    subtotal: 0,
    total_discount: 0,
    payments: [],
    notes: '',
    // Campos auxiliares para pago
    payment_amount: 0,
    payment_method: 'efectivo',
    bank_account_id: null,
});

// --- Carga Inicial ---
watch(() => props.visible, (newVal) => {
    if (newVal) {
        initModal();
    }
});

const initModal = () => {
    step.value = 1;
    cart.value = props.transaction.items.map(item => ({
        // Datos para visualización y lógica
        product_id: item.itemable_type.includes('ProductAttribute')
            ? item.itemable.product_id
            : item.itemable_id,
        product_attribute_id: item.itemable_type.includes('ProductAttribute')
            ? item.itemable_id
            : null,
        name: item.description,
        quantity: parseFloat(item.quantity),
        price: parseFloat(item.unit_price),
        discount: parseFloat(item.discount_amount || 0),
        original_item_id: item.id, // Marca de que ya existía
        subtotal: parseFloat(item.line_total)
    }));

    // Limpiar form
    form.reset();
    form.payment_method = 'efectivo';

    if (activeSession.value) {
        form.cash_register_session_id = activeSession.value.id;
    }
    // Limpiar búsqueda
    searchScanQuery.value = '';
    searchResults.value = [];
};

// --- Lógica de Carrito ---
const activeCartItems = computed(() => cart.value.filter(item => item.quantity > 0));

const cartSubtotal = computed(() => {
    return activeCartItems.value.reduce((sum, item) => sum + (item.quantity * item.price), 0);
});

const cartTotal = computed(() => {
    return activeCartItems.value.reduce((sum, item) => sum + (item.quantity * (item.price - item.discount)), 0);
});

// --- Lógica de Búsqueda (Con Debounce) ---
let debounceTimeout = null;

const handleSearchInput = () => {
    if (debounceTimeout) clearTimeout(debounceTimeout);

    if (searchScanQuery.value.length < 2) {
        searchResults.value = [];
        return;
    }

    isSearching.value = true;
    debounceTimeout = setTimeout(() => {
        searchProducts();
    }, 300); // Esperar 300ms después de dejar de escribir
};

const searchProducts = async () => {
    if (searchScanQuery.value.length < 2) {
        isSearching.value = false;
        return;
    }

    try {
        const response = await axios.get(route('transactions.search-products'), {
            params: { query: searchScanQuery.value }
        });
        searchResults.value = response.data;
    } catch (error) {
        console.error(error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron buscar productos.' });
    } finally {
        isSearching.value = false;
    }
};

const addToCart = (product, variant = null) => {
    const price = variant ? (product.selling_price + variant.selling_price_modifier) : product.selling_price;
    const isVariant = !!variant;
    const attributeId = isVariant ? variant.id : null;
    const productId = product.id;

    // Buscar si ya existe
    const existingIndex = cart.value.findIndex(item =>
        item.product_id === productId && item.product_attribute_id === attributeId
    );

    if (existingIndex >= 0) {
        cart.value[existingIndex].quantity++;
    } else {
        const name = isVariant
            ? `${product.name} (${formatAttributes(variant.attributes)})`
            : product.name;

        cart.value.push({
            product_id: productId,
            product_attribute_id: attributeId,
            name: name,
            quantity: 1,
            price: parseFloat(price),
            discount: 0,
            original_item_id: null, // Nuevo item
        });
    }

    // Opcional: Limpiar búsqueda al agregar
    // searchScanQuery.value = '';
    // searchResults.value = [];
    toast.add({ severity: 'success', summary: 'Agregado', detail: 'Producto agregado al carrito', life: 1000 });
};

const removeFromCart = (index) => {
    cart.value.splice(index, 1);
};

const formatAttributes = (attrs) => {
    if (!attrs) return '';
    const attributes = typeof attrs === 'string' ? JSON.parse(attrs) : attrs;
    return Object.values(attributes).join(' / ');
};

// --- Cálculos Financieros (Step 2) ---
const previousPayments = computed(() => {
    return props.transaction.payments.reduce((sum, p) => sum + parseFloat(p.amount), 0);
});

const newPendingBalance = computed(() => {
    return Math.max(0, cartTotal.value - previousPayments.value - form.payment_amount);
});

const balanceDifference = computed(() => {
    return cartTotal.value - previousPayments.value;
});

const differenceStatus = computed(() => {
    if (balanceDifference.value > 0.01) return 'debt';
    if (balanceDifference.value < -0.01) return 'refund';
    return 'settled';
});

// --- Envío ---
const prepareSubmission = () => {
    if (!activeSession.value) {
        toast.add({ severity: 'error', summary: 'Caja Cerrada', detail: 'Necesitas una sesión de caja activa para realizar cambios.' });
        return;
    }

    if (activeCartItems.value.length === 0) {
        toast.add({ severity: 'warn', summary: 'Carrito vacío', detail: 'El apartado no puede quedar sin productos. Cancélalo si es necesario.' });
        return;
    }

    step.value = 2;
};

const submitExchange = () => {
    // Parseo explícito a enteros
    form.returned_items = props.transaction.items.map(item => ({
        item_id: item.id,
        quantity: parseInt(item.quantity)
    }));

    form.new_items = activeCartItems.value.map(item => ({
        id: item.product_id,
        quantity: parseInt(item.quantity),
        unit_price: item.price,
        description: item.name,
        discount: item.discount,
        product_attribute_id: item.product_attribute_id
    }));

    form.subtotal = cartSubtotal.value;
    form.total_discount = activeCartItems.value.reduce((sum, i) => sum + (i.quantity * i.discount), 0);

    form.payments = [];
    if (form.payment_amount > 0) {
        form.payments.push({
            amount: form.payment_amount,
            method: form.payment_method,
            bank_account_id: form.bank_account_id,
            notes: 'Abono extra al modificar apartado'
        });
    }

    form.post(route('transactions.exchange-layaway', props.transaction.id), {
        onSuccess: () => {
            emit('success');
            emit('update:visible', false);
        },
        onError: () => {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa los datos e intenta de nuevo.' });
        }
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="val => emit('update:visible', val)" modal
        header="Modificar productos del apartado" :style="{ width: '90vw', maxWidth: '1000px' }" :maximizable="true">
        <div v-if="step === 1" class="flex flex-col lg:flex-row gap-6 h-full min-h-[500px]">
            <!-- COLUMNA IZQUIERDA: Búsqueda -->
            <div class="lg:w-1/3 flex flex-col gap-4 border-r pr-4">
                <div class="w-full">
                    <IconField iconPosition="left" class="w-full">
                        <InputIcon class="pi pi-search" />
                        <InputText v-model="searchScanQuery" placeholder="Buscar productos..." class="w-full"
                            @input="handleSearchInput" autofocus />
                    </IconField>
                </div>

                <div class="flex-1 overflow-y-auto max-h-[400px]">
                    <div v-if="isSearching" class="flex justify-center p-4">
                        <ProgressSpinner style="width: 30px; height: 30px" />
                    </div>

                    <div v-else-if="searchResults.length > 0" class="flex flex-col gap-2">
                        <!-- Item Compacto -->
                        <div v-for="product in searchResults" :key="product.id"
                            class="border rounded p-2 hover:bg-gray-50 dark:hover:bg-gray-800 transition group">
                            <div class="flex justify-between items-center">
                                <div class="flex-1 min-w-0 pr-2">
                                    <p class="font-bold text-sm truncate text-gray-800 dark:text-gray-200"
                                        :title="product.name">{{ product.name }}</p>
                                    <div class="flex items-center gap-2 text-xs text-gray-500 leading-tight my-0.5">
                                        <span class="bg-gray-100 dark:bg-gray-700 px-1 rounded">SKU: {{ product.sku
                                            }}</span>
                                        <span>Stock: {{ product.current_stock }}</span>
                                    </div>
                                    <p class="font-semibold text-blue-600 text-sm">{{
                                        formatCurrency(product.selling_price) }}</p>
                                </div>
                                <Button v-if="product.variants.length === 0" icon="pi pi-plus" size="small" rounded
                                    class="!w-8 !h-8" @click="addToCart(product)" />
                            </div>

                            <!-- Variantes Compactas -->
                            <div v-if="product.variants.length > 0" class="mt-2 space-y-1">
                                <div v-for="variant in product.variants" :key="variant.id"
                                    class="flex justify-between items-center text-xs bg-gray-50 dark:bg-gray-700/50 p-1.5 rounded border border-gray-100 dark:border-gray-600">
                                    <div class="flex-1">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{
                                            formatAttributes(variant.attributes) }}</span>
                                        <div class="flex gap-2 text-[10px] text-gray-500">
                                            <span>Stock: {{ variant.current_stock }}</span>
                                            <span class="font-bold">{{ formatCurrency(product.selling_price +
                                                variant.selling_price_modifier) }}</span>
                                        </div>
                                    </div>
                                    <Button icon="pi pi-plus" size="small" rounded text class="!w-6 !h-6"
                                        @click="addToCart(product, variant)" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="searchScanQuery.length > 2" class="text-center text-gray-500 py-4 text-sm">
                        No se encontraron productos.
                    </div>
                </div>
            </div>

            <!-- COLUMNA DERECHA: Carrito de Edición -->
            <div class="lg:w-2/3 flex flex-col h-full">
                <h3 class="font-bold text-lg mb-2">Contenido final del apartado</h3>
                <p class="text-sm text-gray-500 mb-4">Ajusta las cantidades, elimina productos o agrega nuevos desde el
                    panel izquierdo.</p>

                <div class="flex-1 overflow-y-auto border rounded-lg bg-gray-50 dark:bg-gray-900">
                    <table class="w-full text-sm text-left">
                        <thead
                            class="text-xs text-gray-700 uppercase bg-gray-200 dark:bg-gray-700 dark:text-gray-400 sticky top-0">
                            <tr>
                                <th class="px-4 py-3">Producto</th>
                                <th class="px-4 py-3 text-center">Cant.</th>
                                <th class="px-4 py-3 text-right">Precio</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in cart" :key="index"
                                class="border-b bg-white dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">
                                    <div class="flex flex-col">
                                        <span>{{ item.name }}</span>
                                        <span v-if="item.original_item_id"
                                            class="w-fit text-[10px] bg-blue-100 text-blue-800 px-1 rounded border border-blue-200 mt-0.5">Original</span>
                                        <span v-else
                                            class="w-fit text-[10px] bg-green-100 text-green-800 px-1 rounded border border-green-200 mt-0.5">Nuevo</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-center w-24">
                                    <InputNumber v-model="item.quantity" showButtons buttonLayout="horizontal" :min="1"
                                        inputClass="w-12 text-center p-1 text-sm" class="h-8" />
                                </td>
                                <td class="px-4 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                <td class="px-4 py-2 text-right font-bold">{{ formatCurrency(item.quantity * item.price)
                                    }}</td>
                                <td class="px-4 py-2 text-center">
                                    <Button icon="pi pi-trash" severity="danger" text rounded size="small"
                                        class="!w-8 !h-8" @click="removeFromCart(index)" />
                                </td>
                            </tr>
                            <tr v-if="cart.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">El carrito está vacío.
                                    Agrega productos.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-4 bg-white dark:bg-gray-800 rounded shadow border flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500">Total productos: {{ activeCartItems.length }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500 uppercase">Nuevo total</p>
                        <p class="text-2xl font-bold text-gray-800 dark:text-white">{{ formatCurrency(cartTotal) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- PASO 2: Confirmación y Ajuste Financiero -->
        <div v-else class="flex flex-col gap-6 p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Resumen -->
                <div class="space-y-4">
                    <h3 class="text-xl font-bold border-b pb-2">Resumen financiero</h3>

                    <div class="flex justify-between items-center py-2">
                        <span class="text-gray-600">Nuevo total del apartado:</span>
                        <span class="text-lg font-bold">{{ formatCurrency(cartTotal) }}</span>
                    </div>

                    <div class="flex justify-between items-center py-2 text-green-700 bg-green-50 px-2 rounded">
                        <span>Abonado anteriormente (se transfiere):</span>
                        <span class="font-bold">- {{ formatCurrency(previousPayments) }}</span>
                    </div>

                    <Divider />

                    <div v-if="differenceStatus === 'debt'"
                        class="bg-orange-50 dark:bg-orange-900/20 p-4 rounded border border-orange-200">
                        <p class="text-orange-800 dark:text-orange-200 font-semibold mb-1">Saldo pendiente restante</p>
                        <p class="text-3xl font-bold text-orange-600">{{ formatCurrency(balanceDifference) }}</p>
                        <p class="text-xs text-orange-700 mt-2">Este monto quedará como deuda en el nuevo apartado.</p>
                    </div>

                    <div v-if="differenceStatus === 'refund'"
                        class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded border border-blue-200">
                        <p class="text-blue-800 dark:text-blue-200 font-semibold mb-1">Excedente a favor</p>
                        <p class="text-3xl font-bold text-blue-600">{{ formatCurrency(Math.abs(balanceDifference)) }}
                        </p>
                        <p class="text-xs text-blue-700 mt-2">El apartado se marcará como <strong>Completado</strong> y
                            el excedente se abonará al saldo a favor del cliente.</p>
                    </div>
                </div>

                <!-- Pago Adicional -->
                <div v-if="differenceStatus === 'debt'" class="space-y-4 border-l pl-8">
                    <h3 class="text-xl font-bold border-b pb-2">Abonar diferencia (opcional)</h3>
                    <p class="text-sm text-gray-500">Si el cliente desea pagar la diferencia ahora, regístralo aquí. Si
                        no, déjalo en 0 para mantener la deuda.</p>

                    <div class="flex flex-col gap-2">
                        <label class="font-bold text-sm">Monto a pagar ahora</label>
                        <InputNumber v-model="form.payment_amount" mode="currency" currency="MXN" locale="es-MX"
                            :max="balanceDifference" class="w-full" />
                    </div>

                    <div v-if="form.payment_amount > 0" class="space-y-4 animate-fade-in">
                        <div class="flex flex-col gap-2">
                            <label class="font-bold text-sm">Método de pago</label>
                            <SelectButton v-model="form.payment_method"
                                :options="['efectivo', 'tarjeta', 'transferencia']" class="w-full text-sm" />
                        </div>

                        <div v-if="form.payment_method !== 'efectivo'" class="flex flex-col gap-2">
                            <label class="font-bold text-sm">Cuenta de destino</label>
                            <Dropdown v-model="form.bank_account_id" :options="userBankAccounts" optionLabel="bank_name"
                                optionValue="id" placeholder="Selecciona cuenta" class="w-full text-sm" />
                        </div>
                    </div>

                    <div class="mt-4 p-3 bg-gray-100 rounded text-center">
                        <p class="text-sm text-gray-600">Nuevo saldo pendiente final</p>
                        <p class="text-xl font-bold">{{ formatCurrency(newPendingBalance) }}</p>
                    </div>
                </div>

                <div v-else class="flex items-center justify-center text-center text-gray-500 italic p-8">
                    <p>No se requiere pago adicional. <br>El cliente tiene saldo a favor o la cuenta está saldada.</p>
                </div>
            </div>

            <div class="flex flex-col gap-2 mt-4">
                <label class="text-sm font-bold">Notas del cambio</label>
                <Textarea v-model="form.notes" rows="2" placeholder="Motivo del cambio de productos..."
                    class="w-full" />
            </div>
        </div>

        <template #footer>
            <div class="flex justify-between w-full">
                <Button v-if="step === 2" label="Volver a editar" icon="pi pi-arrow-left" @click="step = 1" text
                    severity="secondary" />
                <div v-else></div> <!-- Spacer -->

                <div class="flex gap-2">
                    <Button label="Cancelar" severity="secondary" @click="emit('update:visible', false)" text />

                    <Button v-if="step === 1" label="Siguiente: revisar saldos" icon="pi pi-arrow-right" iconPos="right"
                        @click="prepareSubmission" />

                    <Button v-if="step === 2" label="Confirmar cambio" icon="pi pi-check" severity="success"
                        :loading="form.processing" @click="submitExchange" />
                </div>
            </div>
        </template>
    </Dialog>
</template>