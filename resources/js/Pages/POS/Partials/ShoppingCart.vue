<script setup>
import { ref, computed, watch } from 'vue';
import { useConfirm } from "primevue/useconfirm";
import CartItem from './CartItem.vue'; // <-- Importado correctamente
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';

const props = defineProps({
    items: Array, // <-- Recibe los items con precio ya calculado y flags (isTierPrice, isManualPrice)
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


// Descuentos aplicados a nivel de ítem (ITEM_DISCOUNT o manual)
// Se calcula usando la diferencia entre el precio original base y el precio final del item
const itemsDiscount = computed(() => {
    return props.items.reduce((total, item) => {
        // Usar original_price del item si existe. Este es el precio "base" ANTES de cualquier descuento o ajuste manual.
        // Si original_price no está (aunque debería), usamos el precio actual como fallback.
        const basePrice = item.original_price ?? item.price;
        
        // discountPerItem será:
        // Positivo si hay descuento (base > final)
        // Negativo si hay aumento (base < final)
        const discountPerItem = basePrice - item.price;

        // Sumar el descuento (o restar el aumento)
        // ESTA ES LA LÍNEA CORREGIDA: Se eliminó el (discountPerItem > 0 ? ... : 0)
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
                    // Cantidad real gratuita no puede exceder la cantidad en carrito
                    const actualFreeQty = Math.min(timesApplied * parseInt(effect.value, 10), freeItemInCart.quantity);
                    if (actualFreeQty > 0) {
                         // El descuento es el precio actual del item gratuito por la cantidad gratuita
                        applied.push({ name: promo.name, amount: freeItemInCart.price * actualFreeQty });
                    }
                }
            }
        }

        // Lógica para BUNDLE_PRICE (Paquete por precio fijo)
        if (promo.type === 'BUNDLE_PRICE') {
            const rules = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT');
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
            if (rules.length === 0 || !effect) return;

            // Calcular cuántas veces se puede aplicar el paquete completo
            const canApplyBundleTimes = rules.reduce((minTimes, rule) => {
                const itemInCart = props.items.find(cartItem => cartItem.id === rule.itemable_id);
                const requiredQty = parseInt(rule.value, 10);
                if (!itemInCart || itemInCart.quantity < requiredQty) {
                    return 0; // No se puede aplicar si falta un item o cantidad
                }
                const possibleApplications = Math.floor(itemInCart.quantity / requiredQty);
                return Math.min(minTimes, possibleApplications);
            }, Infinity); // Empezar con infinito para que el primer min funcione

            // Si se puede aplicar al menos una vez
            if (canApplyBundleTimes > 0 && canApplyBundleTimes !== Infinity) {
                // Calcular el precio original SUMANDO los precios ORIGINALES BASE de los items del paquete
                const originalBundlePrice = rules.reduce((sum, rule) => {
                    const item = props.items.find(cartItem => cartItem.id === rule.itemable_id);
                    // Usar original_price del item (que es el precio base antes de promos/tiers)
                    const basePrice = item.original_price ?? item.price;
                    return sum + basePrice * parseInt(rule.value, 10); // Multiplicar por cantidad requerida para UN paquete
                }, 0);

                // Calcular el descuento por paquete
                const bundleSetPrice = parseFloat(effect.value);
                const discountAmountPerBundle = originalBundlePrice - bundleSetPrice;

                if (discountAmountPerBundle > 0) {
                     // El descuento total es el ahorro por paquete * número de veces aplicado
                    applied.push({ name: promo.name, amount: discountAmountPerBundle * canApplyBundleTimes });
                }
            }
        }
    });
    return applied;
});

// Nombres únicos de las promociones de carrito aplicadas
const appliedCartPromoNames = computed(() => {
    return new Set(cartLevelDiscounts.value.map(d => d.name));
});

// Suma de todos los descuentos de nivel carrito
const cartDiscountAmount = computed(() => cartLevelDiscounts.value.reduce((sum, promo) => sum + promo.amount, 0));

// Subtotal bruto ANTES de cualquier descuento (usa original_price)
const subtotal = computed(() => props.items.reduce((total, item) => total + ((item.original_price ?? item.price) * item.quantity), 0));

// Descuento manual (opcional, si lo implementas)
const manualDiscount = ref(0); // Podrías añadir un input para esto si quieres

// Descuento total (suma de descuentos de item + descuentos de carrito + manual)
const totalDiscount = computed(() => itemsDiscount.value + cartDiscountAmount.value + manualDiscount.value);

// Total final a pagar
const total = computed(() => subtotal.value - totalDiscount.value);


// --- Lógica Modal Pago ---
const isPaymentModalVisible = ref(false);

// Emitir datos de checkout (sin cambios)
const handlePaymentSubmit = (paymentData) => {
    isPaymentModalVisible.value = false;
    emit('checkout', {
        ...paymentData,
        subtotal: subtotal.value,
        total: total.value,
        total_discount: totalDiscount.value, // Asegurarse de enviar el total de descuentos
    });
};

// Formateador de moneda (sin cambios)
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
            class="bg-[#E6E6E6] p-3 rounded-xl shadow-md border border-[#D9D9D9] h-full flex flex-col dark:bg-gray-800 dark:border-gray-700">
            <!-- Header -->
            <div class="flex justify-between items-center pb-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200 m-0">Carrito</h2>
                <div class="flex items-center gap-2">
                    <Button @click="$emit('saveCart', { total: total })" :disabled="items.length === 0"
                        icon="pi pi-save" rounded variant="outlined" severity="secondary"
                        v-tooltip.bottom="'Guardar para después'" size="small" class="!bg-white dark:!bg-gray-700 !size-7" />
                    <Button @click="requireConfirmation($event)" :disabled="items.length === 0" icon="pi pi-trash"
                        rounded variant="outlined" severity="danger" v-tooltip.bottom="'Limpiar carrito'" size="small"
                        class="!bg-white dark:!bg-gray-700 !size-7" />
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
                        <Button v-if="client" @click="clearCustomer" icon="pi pi-times" rounded variant="outlined"
                            severity="secondary" size="small" class="!size-6" />
                    </div>
                    <!-- Saldo y Crédito (si hay cliente seleccionado) -->
                    <div v-if="client" class="py-2 border-t dark:border-gray-600 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Saldo:</span>
                            <span :class="(client.balance || 0) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                class="font-bold">
                                {{ formatCurrency(client.balance || 0) }} {{ (client.balance || 0) > 0 ? '(a favor)' : '' }}
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Crédito Disponible:</span>
                            <span class="font-bold text-blue-600 dark:text-blue-400">
                                {{ formatCurrency(client.available_credit || 0) }}
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
                <!-- Pasar el item completo a CartItem, incluyendo isTierPrice y isManualPrice -->
                <CartItem
                    v-for="item in items"
                    :key="item.cartItemId"
                    :item="item"
                    :applied-cart-promo-names="appliedCartPromoNames"
                    @update-quantity="$emit('updateQuantity', $event)"
                    @update-price="$emit('updatePrice', $event)"
                    @remove-item="$emit('removeItem', $event)" />
            </div>

            <!-- Detalles del Pago -->
            <div class="mt-4 p-2 rounded-[10px] border border-[#D9D9D9] bg-white dark:bg-gray-900 dark:border-gray-700 space-y-1">
                <!-- Subtotal -->
                <div class="flex justify-between items-center text-gray-600 dark:text-gray-300">
                    <span>Subtotal</span><span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                </div>

                <!-- Caso 1: Mostrar Descuentos (si totalDiscount es positivo) -->
                <div v-if="totalDiscount > 0" class="text-red-500 dark:text-red-400">
                    <div class="flex justify-between items-center">
                        <span>Descuentos</span>
                        <span class="font-medium">-{{ formatCurrency(totalDiscount) }}</span>
                    </div>
                    <!-- Detalle de descuentos de carrito -->
                    <div v-for="promo in cartLevelDiscounts" :key="promo.name"
                        class="flex justify-between items-center pl-4 text-xs">
                        <span>{{ promo.name }}</span>
                        <span>-{{ formatCurrency(promo.amount) }}</span>
                    </div>
                     <!-- Podrías añadir aquí un detalle del descuento manual si lo implementas -->
                </div>

                <!-- Caso 2: Mostrar Aumento (si totalDiscount es negativo) -->
                <div v-else-if="totalDiscount < 0" class="text-green-600 dark:text-green-400">
                    <div class="flex justify-between items-center">
                        <span>Aumento (edición)</span>
                        <!-- Usamos -totalDiscount para mostrar el valor absoluto (positivo) del aumento -->
                        <span class="font-medium">+{{ formatCurrency(-totalDiscount) }}</span>
                    </div>
                </div>
                <!-- Si totalDiscount es 0, no se mostrará nada, lo cual es correcto -->

                <!-- Total Final -->
                <div
                    class="flex justify-between items-center font-bold text-lg text-gray-800 dark:text-gray-100 border-t border-dashed border-[#D9D9D9] dark:border-gray-700 pt-1">
                    <span>Total</span><span>{{ formatCurrency(total) }}</span>
                </div>

                <!-- Botón Pagar/Finalizar -->
                <Button @click="isPaymentModalVisible = true" :disabled="items.length === 0"
                     :label="(client && total <= 0 && client.balance >= total) || total === 0 ? 'Finalizar' : 'Pagar'"
                    icon="pi pi-arrow-right" iconPos="right"
                    class="w-full mt-2 bg-orange-500 hover:bg-orange-600 border-none" />
            </div>
        </div>

        <!-- Modales -->
        <CreateCustomerModal v-model:visible="isCreateCustomerModalVisible" @created="handleCustomerCreated" />
        <PaymentModal
            v-model:visible="isPaymentModalVisible"
            :total-amount="total"
            :client="client"
            :customers="customers"
            @update:client="$emit('selectCustomer', $event)"
            @customer-created="$emit('customerCreated', $event)"
            @submit="handlePaymentSubmit"
        />
    </div>
</template>