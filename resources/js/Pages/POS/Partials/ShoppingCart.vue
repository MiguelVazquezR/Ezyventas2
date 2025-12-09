<script setup>
import { ref, computed, watch } from 'vue';
import { useConfirm } from "primevue/useconfirm";
import CartItem from './CartItem.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import axios from 'axios'; // Importamos Axios para la búsqueda

const props = defineProps({
    items: Array, 
    client: Object,
    customers: Array, // Lista inicial (limitada)
    defaultCustomer: Object,
    activePromotions: Array,
    loading: { type: Boolean, default: false },
    paymentModalVisible: { type: Boolean, default: false }
});

const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem', 'clearCart', 'selectCustomer', 'customerCreated', 'saveCart', 'checkout', 'open-payment-modal', 'close-payment-modal']);

const confirm = useConfirm();
const requireConfirmation = (event) => {
    confirm.require({
        target: event.currentTarget,
        group: 'cart-actions',
        message: '¿Estás seguro de que quieres limpiar el carrito?',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, limpiar',
        rejectLabel: 'Cancelar',
        accept: () => emit('clearCart'),
    });
};

const isCreateCustomerModalVisible = ref(false);

// --- Lógica de AutoComplete ---
const selectedCustomerModel = ref(props.client);
const filteredCustomers = ref([]); // Lista dinámica para sugerencias

// Sincronizar selectedCustomerModel con props.client
watch(() => props.client, (newVal) => {
    selectedCustomerModel.value = newVal;
}, { immediate: true });

// Función de búsqueda asíncrona
const searchCustomer = async (event) => {
    const query = event.query;
    try {
        // Asegúrate de que esta ruta existe en tu backend (ver instrucciones abajo)
        const response = await axios.get(route('pos.customers.search'), { params: { query } });
        filteredCustomers.value = response.data;
    } catch (error) {
        console.error("Error buscando clientes:", error);
        filteredCustomers.value = [];
    }
};

// Al seleccionar un cliente del autocomplete
const onCustomerSelect = (event) => {
    emit('selectCustomer', event.value);
};

// Limpiar cliente seleccionado
const clearCustomer = () => {
    selectedCustomerModel.value = null;
    emit('selectCustomer', null);
};

const displayedCustomer = computed(() => props.client || props.defaultCustomer);

const handleCustomerCreated = (newCustomer) => {
    // Ya no dependemos de localCustomers para todo, solo emitimos y seleccionamos
    emit('customerCreated', newCustomer);
    // Establecemos el nuevo cliente en el modelo local para que aparezca seleccionado
    selectedCustomerModel.value = newCustomer;
};

// --- Cálculos del Carrito ---
const itemsDiscount = computed(() => {
    return props.items.reduce((total, item) => {
        const basePrice = item.original_price ?? item.price;
        const discountPerItem = basePrice - item.price;
        return total + (discountPerItem * item.quantity);
    }, 0);
});

const cartLevelDiscounts = computed(() => {
    const applied = [];
    if (!props.activePromotions || props.items.length === 0) return applied;

    props.activePromotions.forEach(promo => {
        // Lógica BOGO
        if (promo.type === 'BOGO') {
            const rule = promo.rules.find(r => r.type === 'REQUIRES_PRODUCT_QUANTITY');
            const effect = promo.effects.find(e => e.type === 'FREE_ITEM');
            if (!rule || !effect) return;

            const itemInCart = props.items.find(i => i.id === rule.itemable_id);
            if (itemInCart && itemInCart.quantity >= parseInt(rule.value, 10)) {
                const freeItemInCart = props.items.find(i => i.id === effect.itemable_id);
                if (freeItemInCart) {
                    const timesApplied = Math.floor(itemInCart.quantity / parseInt(rule.value, 10));
                    const actualFreeQty = Math.min(timesApplied * parseInt(effect.value, 10), freeItemInCart.quantity);
                    if (actualFreeQty > 0) {
                        applied.push({ name: promo.name, amount: freeItemInCart.price * actualFreeQty });
                    }
                }
            }
        }
        // Lógica BUNDLE
        if (promo.type === 'BUNDLE_PRICE') {
            const rules = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT');
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
            if (rules.length > 0 && effect) {
                const canApplyBundleTimes = rules.reduce((minTimes, rule) => {
                    const itemInCart = props.items.find(cartItem => cartItem.id === rule.itemable_id);
                    const requiredQty = parseInt(rule.value, 10);
                    if (!itemInCart || itemInCart.quantity < requiredQty) return 0;
                    return Math.min(minTimes, Math.floor(itemInCart.quantity / requiredQty));
                }, Infinity);

                if (canApplyBundleTimes > 0 && canApplyBundleTimes !== Infinity) {
                    const originalBundlePrice = rules.reduce((sum, rule) => {
                        const item = props.items.find(cartItem => cartItem.id === rule.itemable_id);
                        const basePrice = item.original_price ?? item.price;
                        return sum + basePrice * parseInt(rule.value, 10);
                    }, 0);
                    const bundleSetPrice = parseFloat(effect.value);
                    const discountAmountPerBundle = originalBundlePrice - bundleSetPrice;
                    if (discountAmountPerBundle > 0) {
                        applied.push({ name: promo.name, amount: discountAmountPerBundle * canApplyBundleTimes });
                    }
                }
            }
        }
    });
    return applied;
});

const appliedCartPromoNames = computed(() => new Set(cartLevelDiscounts.value.map(d => d.name)));
const cartDiscountAmount = computed(() => cartLevelDiscounts.value.reduce((sum, promo) => sum + promo.amount, 0));
const subtotal = computed(() => props.items.reduce((total, item) => total + ((item.original_price ?? item.price) * item.quantity), 0));
const manualDiscount = ref(0);
const totalDiscount = computed(() => itemsDiscount.value + cartDiscountAmount.value + manualDiscount.value);
const total = computed(() => subtotal.value - totalDiscount.value);

const handlePaymentSubmit = (paymentData) => {
    emit('checkout', {
        ...paymentData,
        subtotal: subtotal.value,
        total: total.value,
        total_discount: totalDiscount.value,
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};
</script>

<template>
    <div>
        <ConfirmPopup group="cart-actions"></ConfirmPopup>
        <div class="bg-[#E6E6E6] p-3 rounded-xl shadow-md border border-[#D9D9D9] h-full flex flex-col dark:bg-gray-800 dark:border-gray-700">
            <!-- Header -->
            <div class="flex justify-between items-center pb-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200 m-0">Carrito</h2>
                <div class="flex items-center gap-2">
                    <Button @click="$emit('saveCart', { total: total })" :disabled="items.length === 0"
                        icon="pi pi-save" rounded variant="outlined" severity="secondary"
                        v-tooltip.bottom="'Guardar para después'" size="small"
                        class="!bg-white dark:!bg-gray-700 !size-7" />
                    <Button @click="requireConfirmation($event)" :disabled="items.length === 0" icon="pi pi-trash"
                        rounded variant="outlined" severity="danger" v-tooltip.bottom="'Limpiar carrito'" size="small"
                        class="!bg-white dark:!bg-gray-700 !size-7" />
                </div>
            </div>

            <!-- Selector de Cliente con AutoComplete -->
            <div class="my-1">
                <div class="flex items-center gap-2 mb-3">
                    <AutoComplete 
                        v-model="selectedCustomerModel" 
                        :suggestions="filteredCustomers" 
                        @complete="searchCustomer" 
                        @item-select="onCustomerSelect"
                        optionLabel="name" 
                        forceSelection
                        placeholder="Buscar cliente (nombre o teléfono)..." 
                        class="w-full"
                        :delay="400"
                        emptyMessage="No se encontraron clientes"
                        fluid
                    >
                        <template #option="slotProps">
                            <div class="flex flex-col">
                                <span class="font-bold">{{ slotProps.option.name }}</span>
                                <span class="text-xs text-gray-500">{{ slotProps.option.phone }}</span>
                            </div>
                        </template>
                    </AutoComplete>
                    
                    <Button @click="isCreateCustomerModalVisible = true" rounded icon="pi pi-plus" size="small"
                        severity="contrast" v-tooltip.bottom="'Crear nuevo cliente'" />
                </div>

                <!-- Detalles del Cliente Seleccionado -->
                <div class="bg-white dark:bg-gray-700 p-2 rounded-[10px]">
                    <div v-if="displayedCustomer" class="flex items-center justify-between pb-2">
                        <div class="flex items-center gap-3">
                            <Avatar :label="displayedCustomer.name.substring(0, 1)" shape="circle"
                                class="!bg-[#F2E2FF] border border-[#D3A9FF] !text-[#5110A1]" />
                            <div>
                                <p class="font-semibold text-sm text-gray-800 dark:text-gray-200 m-0">{{
                                    displayedCustomer.name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 m-0">{{ displayedCustomer.phone }}
                                </p>
                            </div>
                        </div>
                        <!-- Botón para quitar cliente seleccionado (solo si no es el default) -->
                        <Button v-if="client" @click="clearCustomer" icon="pi pi-times" rounded variant="outlined"
                            severity="secondary" size="small" class="!size-6" />
                    </div>
                    <!-- Saldo y Crédito -->
                    <div v-if="client" class="py-2 border-t dark:border-gray-600 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Saldo:</span>
                            <span
                                :class="(client.balance || 0) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                class="font-bold">
                                {{ formatCurrency(client.balance || 0) }} {{ (client.balance || 0) > 0 ? '(a favor)' : '' }}
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Crédito disponible:</span>
                            <span class="font-bold text-blue-600 dark:text-blue-400">
                                {{ formatCurrency(client.available_credit || 0) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lista de Items -->
            <p v-if="items.length > 0"
                class="my-2 text-sm text-[#1E1E1E] dark:text-gray-200 flex items-center space-x-2">
                <span>Detalles de venta</span>
                <Badge :value="items.length" class="!bg-white !text-black dark:!bg-black dark:!text-white"></Badge>
            </p>
            <div class="flex-grow py-1 overflow-y-auto space-y-2">
                <p v-if="items.length === 0" class="text-gray-500 dark:text-gray-400 text-center mt-8">El carrito está vacío</p>
                <CartItem v-for="item in items" :key="item.cartItemId" :item="item"
                    :applied-cart-promo-names="appliedCartPromoNames" @update-quantity="$emit('updateQuantity', $event)"
                    @update-price="$emit('updatePrice', $event)" @remove-item="$emit('removeItem', $event)" />
            </div>

            <!-- Detalles del Pago -->
            <div class="mt-4 p-2 rounded-[10px] border border-[#D9D9D9] bg-white dark:bg-gray-900 dark:border-gray-700 space-y-1">
                <div class="flex justify-between items-center text-gray-600 dark:text-gray-300">
                    <span>Subtotal</span><span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                </div>
                <div v-if="totalDiscount > 0" class="text-red-500 dark:text-red-400">
                    <div class="flex justify-between items-center">
                        <span>Descuentos</span>
                        <span class="font-medium">-{{ formatCurrency(totalDiscount) }}</span>
                    </div>
                    <div v-for="promo in cartLevelDiscounts" :key="promo.name"
                        class="flex justify-between items-center pl-4 text-xs">
                        <span>{{ promo.name }}</span>
                        <span>-{{ formatCurrency(promo.amount) }}</span>
                    </div>
                </div>
                <div v-else-if="totalDiscount < 0" class="text-green-600 dark:text-green-400">
                    <div class="flex justify-between items-center">
                        <span>Aumento (edición)</span>
                        <span class="font-medium">+{{ formatCurrency(-totalDiscount) }}</span>
                    </div>
                </div>
                <div class="flex justify-between items-center font-bold text-lg text-gray-800 dark:text-gray-100 border-t border-dashed border-[#D9D9D9] dark:border-gray-700 pt-1">
                    <span>Total</span><span>{{ formatCurrency(total) }}</span>
                </div>
                <Button @click="$emit('open-payment-modal')" :disabled="items.length === 0"
                    :label="(client && total <= 0 && client.balance >= total) || total === 0 ? 'Finalizar' : 'Pagar'"
                    icon="pi pi-arrow-right" iconPos="right"
                    class="w-full mt-2 bg-orange-500 border-none" />
            </div>
        </div>

        <!-- Modales -->
        <CreateCustomerModal v-model:visible="isCreateCustomerModalVisible" @created="handleCustomerCreated" />
        <PaymentModal
            :visible="props.paymentModalVisible"
            @update:visible="$emit('close-payment-modal')"
            :total-amount="total"
            :client="client"
            :customers="customers" 
            :allow-credit="true"
            :allow-layaway="true"
            :loading="props.loading"
            payment-mode="strict"
            @update:client="$emit('selectCustomer', $event)"
            @customer-created="handleCustomerCreated"
            @submit="handlePaymentSubmit"
        />
    </div>
</template>