<script setup>
import { ref, computed, watch } from 'vue';
import { useConfirm } from "primevue/useconfirm";
import CartItem from './CartItem.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';

const props = defineProps({
    items: Array,
    client: Object,
    customers: Array,
    defaultCustomer: Object,
    activePromotions: Array,
});

const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem', 'clearCart', 'selectCustomer', 'customerCreated', 'saveCart', 'checkout']);

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
const handleCustomerSelect = (event) => emit('selectCustomer', event.value);
const clearCustomer = () => emit('selectCustomer', null);
const displayedCustomer = computed(() => props.client || props.defaultCustomer);
const handleCustomerCreated = (newCustomer) => emit('customerCreated', newCustomer);


// --- LÓGICA DE CÁLCULO DE TOTALES Y PROMOCIONES ---

// Descuentos aplicados a nivel de ítem (ITEM_DISCOUNT)
const itemsDiscount = computed(() => {
    return props.items.reduce((total, item) => {
        const discountPerItem = (item.original_price || item.price) - item.price;
        return total + (discountPerItem * item.quantity);
    }, 0);
});

// Descuentos dinámicos aplicados a nivel de carrito (BOGO, BUNDLE)
const cartLevelDiscounts = computed(() => {
    const applied = [];
    if (!props.activePromotions || props.items.length === 0) return applied;

    props.activePromotions.forEach(promo => {
        // Lógica para BOGO (Compre X, lleve Y gratis)
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

        // Lógica para BUNDLE_PRICE (Paquete por precio fijo)
        if (promo.type === 'BUNDLE_PRICE') {
            // CORRECCIÓN: Se cambió 'REQUIRES_PRODUCT_QUANTITY' por 'REQUIRES_PRODUCT'
            const rules = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT');
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
            if (rules.length === 0 || !effect) return;

            const canApplyBundleTimes = rules.reduce((minTimes, rule) => {
                const itemInCart = props.items.find(cartItem => cartItem.id === rule.itemable_id);
                const requiredQty = parseInt(rule.value, 10);
                if (!itemInCart || itemInCart.quantity < requiredQty) {
                    return 0; // Si falta un producto o la cantidad no es suficiente, no se puede aplicar el paquete.
                }
                // Calcula cuántas veces se podría aplicar el paquete basado en este único producto.
                const possibleApplications = Math.floor(itemInCart.quantity / requiredQty);
                // Nos quedamos con el número mínimo de aplicaciones posibles entre todos los productos.
                return Math.min(minTimes, possibleApplications);
            }, Infinity);

            if (canApplyBundleTimes > 0 && canApplyBundleTimes !== Infinity) {
                const originalBundlePrice = rules.reduce((sum, rule) => {
                    const item = props.items.find(cartItem => cartItem.id === rule.itemable_id);
                    // El precio original se multiplica por la cantidad requerida para UN solo paquete.
                    return sum + (item.original_price || item.price) * parseInt(rule.value, 10);
                }, 0);

                const discountAmount = originalBundlePrice - parseFloat(effect.value);
                if (discountAmount > 0) {
                    // El descuento total es el ahorro de un paquete por el número de veces que se puede aplicar.
                    applied.push({ name: promo.name, amount: discountAmount * canApplyBundleTimes });
                }
            }
        }
    });
    return applied;
});

const appliedCartPromoNames = computed(() => {
    return new Set(cartLevelDiscounts.value.map(d => d.name));
});


const cartDiscountAmount = computed(() => cartLevelDiscounts.value.reduce((sum, promo) => sum + promo.amount, 0));
const subtotal = computed(() => props.items.reduce((total, item) => total + ((item.original_price || item.price) * item.quantity), 0));
const manualDiscount = ref(0);
const totalDiscount = computed(() => itemsDiscount.value + cartDiscountAmount.value + manualDiscount.value);
const total = computed(() => subtotal.value - totalDiscount.value);

const isPaymentModalVisible = ref(false);

const handlePaymentSubmit = (paymentData) => {
    isPaymentModalVisible.value = false;
    emit('checkout', {
        ...paymentData,
        subtotal: subtotal.value,
        total: total.value,
        total_discount: totalDiscount.value,
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};
</script>

<template>
    <div>
        <ConfirmPopup group="cart-actions"></ConfirmPopup>
        <div
            class="bg-[#E6E6E6] p-3 rounded-xl shadow-md border border-[#D9D9D9] h-full flex flex-col dark:bg-gray-800">
            <!-- Header -->
            <div class="flex justify-between items-center pb-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200 m-0">Carrito</h2>
                <div class="flex items-center gap-2">
                    <Button @click="$emit('saveCart', { total: total })" :disabled="items.length === 0"
                        icon="pi pi-save" rounded variant="outlined" severity="secondary"
                        v-tooltip.bottom="'Guardar para después'" size="small" class="!bg-white !size-7" />
                    <Button @click="requireConfirmation($event)" :disabled="items.length === 0" icon="pi pi-trash"
                        rounded variant="outlined" severity="danger" v-tooltip.bottom="'Limpiar carrito'" size="small"
                        class="!bg-white !size-7" />
                </div>
            </div>

            <!-- Selector de Cliente -->
            <div class="my-1">
                <div class="flex items-center gap-2 mb-3">
                    <Select :modelValue="client" @change="handleCustomerSelect" :options="customers" optionLabel="name"
                        placeholder="Seleccionar cliente" filter class="w-full" />
                    <Button @click="isCreateCustomerModalVisible = true" rounded icon="pi pi-plus" size="small"
                        severity="contrast" />
                </div>
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
                        <Button v-if="client" @click="clearCustomer" icon="pi pi-times" rounded variant="outlined"
                            severity="secondary" size="small" class="!size-6" />
                    </div>
                    <div v-if="client" class="py-2 border-t space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Saldo:</span>
                            <span :class="(client.balance || 0) >= 0 ? 'text-green-600' : 'text-red-600'"
                                class="font-bold">
                                {{ new Intl.NumberFormat('es-MX', {
                                    style: 'currency', currency: 'MXN'
                                }).format(client.balance
                                    || 0) }} {{ (client.balance || 0) > 0 ? '(a favor)' : '' }}
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Crédito Disponible:</span>
                            <span class="font-bold text-blue-600">
                                {{ new Intl.NumberFormat('es-MX', {
                                    style: 'currency', currency: 'MXN'
                                }).format(client.available_credit || 0) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lista de Items -->
            <p v-if="items.length > 0" class="my-2 text-sm text-[#1E1E1E] dark:text-gray-200 flex items-center space-x-2">
                <span>Detalles de venta</span>
                <Badge :value="items.length" class="!bg-white !text-black dark:!bg-black dark:!text-white"></Badge>
            </p>
            <div class="flex-grow py-1 overflow-y-auto space-y-2">
                <p v-if="items.length === 0" class="text-gray-500 dark:text-gray-400 text-center mt-8">El carrito está
                    vacío</p>
                <CartItem v-for="item in items" :key="item.cartItemId" :item="item"
                    :applied-cart-promo-names="appliedCartPromoNames" @update-quantity="$emit('updateQuantity', $event)"
                    @update-price="$emit('updatePrice', $event)" @remove-item="$emit('removeItem', $event)" />
            </div>

            <!-- Detalles del Pago -->
            <div class="mt-4 p-2 rounded-[10px] border border-[#D9D9D9] bg-white dark:bg-gray-900 space-y-1">
                <div class="flex justify-between items-center text-gray-600 dark:text-gray-300">
                    <span>Subtotal</span><span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                </div>

                <div v-if="totalDiscount > 0" class="text-red-500">
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
                
                <div
                    class="flex justify-between items-center font-bold text-lg text-gray-800 dark:text-gray-100 border-t border-dashed border-[#D9D9D9] pt-1">
                    <span>Total</span><span>{{ formatCurrency(total) }}</span>
                </div>

                <Button @click="isPaymentModalVisible = true" :disabled="items.length === 0"
                    :label="client && total <= (client.available_credit || 0) ? 'Finalizar' : 'Pagar'"
                    icon="pi pi-arrow-right" iconPos="right"
                    class="w-full mt-2 bg-orange-500 hover:bg-orange-600 border-none" />
            </div>
        </div>

        <CreateCustomerModal v-model:visible="isCreateCustomerModalVisible" @created="handleCustomerCreated" />

        <!-- INICIO DE CORRECCIÓN -->
        <PaymentModal 
            v-model:visible="isPaymentModalVisible" 
            :total-amount="total" 
            :client="client"
            :customers="customers"
            @update:client="$emit('selectCustomer', $event)"
            @customer-created="$emit('customerCreated', $event)"
            @submit="handlePaymentSubmit" 
        />
        <!-- FIN DE CORRECCIÓN -->
    </div>
</template>

