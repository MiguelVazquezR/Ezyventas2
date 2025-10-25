<script setup>
import { ref, watch, computed } from 'vue'; // <-- Asegurar computed
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import PosLeftPanel from './Partials/PosLeftPanel.vue';
import ShoppingCart from './Partials/ShoppingCart.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import CloseSessionModal from '@/Components/CloseSessionModal.vue';
import SessionHistoryModal from '@/Components/SessionHistoryModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import { v4 as uuidv4 } from 'uuid';

const props = defineProps({
    products: Object,
    categories: Array,
    customers: Array,
    defaultCustomer: Object,
    filters: Object,
    activePromotions: Array,
    activeSession: Object,
    availableCashRegisters: Array,
    availableTemplates: Array,
    joinableSessions: Array,
    userBankAccounts: Array,
});

const page = usePage();
const toast = useToast();

const cartItems = ref([]);
const selectedClient = ref(null);

// --- Lógica para Modales ---
const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const isCloseSessionModalVisible = ref(false);
const isHistoryModalVisible = ref(false);
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

// Observa el flash message del backend para activar el modal de impresión
watch(() => page.props.flash.print_data, (newPrintData) => {
    if (newPrintData) {
        printDataSource.value = newPrintData;
        isPrintModalVisible.value = true;
        page.props.flash.print_data = null; // Limpiar para evitar reactivación
    }
}, { immediate: true });

// --- Helper CORREGIDO para calcular precio por volumen ---
const getPriceForQuantity = (productData, quantity) => {
    const absoluteOriginalPrice = parseFloat(productData.selling_price);
    const basePriceAfterDirectPromo = parseFloat(productData.price);

    if (!productData.price_tiers || productData.price_tiers.length === 0 || quantity <= 1) {
         return {
            price: basePriceAfterDirectPromo,
            original_price_base: absoluteOriginalPrice,
            isTierPrice: false
        };
    }
    const sortedTiers = [...productData.price_tiers]
        .map(t => ({ ...t, min_quantity: parseInt(t.min_quantity, 10) }))
        .sort((a, b) => b.min_quantity - a.min_quantity);

    for (const tier of sortedTiers) {
        if (!isNaN(tier.min_quantity) && quantity >= tier.min_quantity) {
            const tierPrice = parseFloat(tier.price);
            return {
                price: tierPrice,
                original_price_base: absoluteOriginalPrice,
                isTierPrice: true
            };
        }
    }
    return {
        price: basePriceAfterDirectPromo, // <- CORRECCIÓN: Volver al precio base (con promo)
        original_price_base: absoluteOriginalPrice,
        isTierPrice: false
    };
};
// --- FIN: Helper CORREGIDO ---


// --- addToCart CORREGIDO ---
const addToCart = (data) => {
    const { product, variant } = data;
    const cartItemId = variant ? `prod-${product.id}-variant-${variant.id}` : `prod-${product.id}`;
    const existingItem = cartItems.value.find(item => item.cartItemId === cartItemId);
    const stock = variant ? variant.stock : product.stock;
    const quantityToAdd = 1;
    const targetQuantity = existingItem ? existingItem.quantity + quantityToAdd : 1;

    const productBaseDataForCalc = {
        selling_price: product.selling_price,
        price: product.price,
        price_tiers: product.price_tiers
    };
    const { price: calculatedPrice, original_price_base: baseOriginalPrice, isTierPrice } = getPriceForQuantity(productBaseDataForCalc, targetQuantity);

    if (existingItem) {
        if (targetQuantity <= stock || stock < 0) {
             existingItem.quantity = targetQuantity;
             if (!existingItem.isManualPrice) {
                 const itemBaseDataForCalc = {
                     selling_price: existingItem.selling_price,
                     price: existingItem.price_qty_1_promo,
                     price_tiers: existingItem.price_tiers
                 };
                 const { price: updatedPrice, isTierPrice: updatedIsTier } = getPriceForQuantity(itemBaseDataForCalc, existingItem.quantity);
                 let variantModifier = 0;
                 if (existingItem.product_attribute_id) {
                     variantModifier = (existingItem.original_price ?? existingItem.selling_price) - existingItem.selling_price;
                 }
                 existingItem.price = updatedPrice + variantModifier;
                 existingItem.isTierPrice = updatedIsTier;
             }
        } else {
             toast.add({ severity: 'warn', summary: 'Stock Insuficiente', detail: `No puedes agregar más de ${stock} unidades.`, life: 3000 });
        }
    } else {
         const newItem = {
            ...product,
            cartItemId: cartItemId,
            quantity: quantityToAdd,
            selling_price: baseOriginalPrice, // Original base (sin promo, sin tier)
            price_qty_1_promo: product.price, // Precio qty 1 (con promo directa si aplica)
            price: calculatedPrice, // Precio calculado inicial
            original_price: baseOriginalPrice, // Para calcular subtotal bruto y descuentos
            isTierPrice: isTierPrice,
            isManualPrice: false,
            ...(variant && {
                price: calculatedPrice + variant.price_modifier,
                original_price: baseOriginalPrice + variant.price_modifier, // Base + modificador
                sku: `${product.sku}-${variant.sku_suffix}`,
                stock: variant.stock,
                selectedVariant: variant.attributes,
                product_attribute_id: variant.id,
                image: variant.image_url || product.image,
            })
        };
         // Asegurar que original_price en variante sume el modificador
        if (variant) {
            newItem.original_price = baseOriginalPrice + variant.price_modifier;
        }

        cartItems.value.push(newItem);
        if (stock <= 0) {
            toast.add({ severity: 'warn', summary: 'Sin Stock', detail: `El producto se agregó al carrito pero no tiene stock disponible.`, life: 6000 });
        }
    }
};
// --- FIN: addToCart CORREGIDO ---

// --- updateCartQuantity CORREGIDO ---
const updateCartQuantity = ({ itemId, quantity }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) {
        const validQuantity = Math.max(1, quantity || 1);
        const oldQuantity = item.quantity;
        item.quantity = validQuantity;

        if (!item.isManualPrice) {
            const itemBaseDataForCalc = {
                selling_price: item.selling_price,
                price: item.price_qty_1_promo,
                price_tiers: item.price_tiers
            };
            if (typeof itemBaseDataForCalc.selling_price !== 'undefined' && typeof itemBaseDataForCalc.price !== 'undefined') {
                const { price: updatedPrice, isTierPrice: updatedIsTier } = getPriceForQuantity(itemBaseDataForCalc, validQuantity);
                let variantModifier = 0;
                if (item.product_attribute_id) {
                     variantModifier = (item.original_price ?? item.selling_price) - item.selling_price;
                }
                item.price = updatedPrice + variantModifier;
                item.isTierPrice = updatedIsTier;
            } else {
                 console.error("Faltan datos base en el item para recalcular precio:", item);
                 item.quantity = oldQuantity;
                 toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo recalcular el precio.', life: 3000 });
            }
        }
    }
};
// --- FIN: updateCartQuantity CORREGIDO ---

// --- updateCartPrice CORREGIDO ---
const updateCartPrice = ({ itemId, price }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) {
        item.price = Math.max(0, price || 0);
        item.isManualPrice = true;
        item.isTierPrice = false;
    }
};
// --- FIN: updateCartPrice CORREGIDO ---


const removeCartItem = (itemId) => {
    cartItems.value = cartItems.value.filter(i => i.cartItemId !== itemId);
};

const clearCart = () => {
    cartItems.value = [];
    selectedClient.value = null;
};

// --- Clientes y Carritos Pendientes ---
const localCustomers = ref([...props.customers]);
const handleSelectCustomer = (customer) => selectedClient.value = customer;
watch(() => props.customers, (newCustomers) => { localCustomers.value = [...newCustomers]; });
const handleCustomerCreated = (newCustomer) => {
    localCustomers.value.push(newCustomer);
    selectedClient.value = newCustomer;
    toast.add({ severity: 'success', summary: 'Cliente Creado', detail: 'El nuevo cliente ha sido seleccionado.', life: 3000 });
};
const pendingCarts = ref([]);
const saveCartToPending = (payload) => {
    if (cartItems.value.length === 0) return;
    pendingCarts.value.push({
        id: uuidv4(),
        client: selectedClient.value || props.defaultCustomer,
        items: JSON.parse(JSON.stringify(cartItems.value)),
        time: new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }),
        total: payload.total,
    });
    clearCart();
    toast.add({ severity: 'success', summary: 'Carrito Guardado', detail: 'El carrito actual se movió a la lista de espera.', life: 3000 });
};
const resumePendingCart = (cartId) => {
    const cartToResume = pendingCarts.value.find(c => c.id === cartId);
    if (!cartToResume) return;
    if (cartItems.value.length > 0) saveCartToPending({ total: cartItems.value.reduce((acc, item) => acc + item.price * item.quantity, 0) });
    cartItems.value = cartToResume.items;
    selectedClient.value = cartToResume.client.id === props.defaultCustomer.id ? null : cartToResume.client;
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
};
const deletePendingCart = (cartId) => {
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
    toast.add({ severity: 'warn', summary: 'Carrito Descartado', detail: 'Se ha eliminado un carrito de la lista de espera.', life: 3000 });
};

// --- Sesión de Caja ---
const handleRefreshSessionData = () => {
    router.reload({
        preserveState: true,
        preserveScroll: true,
    });
};

// --- Creación Rápida de Producto ---
const handleProductCreatedAndAddToCart = (newProduct) => {
    const formattedProduct = {
        id: newProduct.id,
        name: newProduct.name,
        selling_price: parseFloat(newProduct.selling_price),
        price: parseFloat(newProduct.selling_price),
        original_price: parseFloat(newProduct.selling_price),
        stock: newProduct.current_stock || 0,
        category: 'Sin categoría',
        image: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' + encodeURIComponent(newProduct.name),
        description: newProduct.description || '',
        sku: newProduct.sku || '',
        price_tiers: newProduct.price_tiers || [], // Asegurar que se pasan los tiers
        variants: {},
        variant_combinations: [],
        promotions: [],
    };
    addToCart({ product: formattedProduct });
    router.reload({ preserveState: true, only: ['products'] });
};

// --- Checkout ---
const form = useForm({
    cartItems: [], customerId: null,
    subtotal: 0, total_discount: 0, total: 0,
    payments: [], use_balance: false,
    cash_register_session_id: null,
});

const handleCheckout = (checkoutData) => {
    if (!props.activeSession) {
        toast.add({ severity: 'error', summary: 'Caja Cerrada', detail: 'Debes tener una sesión de caja activa para registrar una venta.', life: 5000 });
        return;
    }
    form.cartItems = cartItems.value.map(item => ({
        id: item.id,
        product_attribute_id: item.product_attribute_id || null,
        quantity: item.quantity,
        unit_price: item.price,
        description: item.name + (item.selectedVariant ? ` (${Object.values(item.selectedVariant).join('/')})` : ''),
        discount: Math.max(0, (item.original_price ?? item.price) - item.price),
    }));
    form.customerId = selectedClient.value ? selectedClient.value.id : null;
    form.subtotal = checkoutData.subtotal;
    form.total_discount = checkoutData.total_discount;
    form.total = checkoutData.total;
    form.payments = checkoutData.payments;
    form.use_balance = checkoutData.use_balance;
    form.cash_register_session_id = props.activeSession.id;

    form.post(route('pos.checkout'), {
        onSuccess: () => {
            clearCart();
            page.props.flash.success = null;
            router.reload({ only: ['products'], preserveState: true });
        },
        onError: (errors) => {
            console.error("Error de validación:", errors);
            const errorMessage = errors.default || errors.message || Object.values(errors).flat().join(' ');
            toast.add({ severity: 'error', summary: 'Error al Procesar', detail: errorMessage || 'Ocurrió un error inesperado.', life: 7000 });
        }
    });
};
</script>

<template>

    <Head title="Punto de venta" />
    <AppLayout>
        <!-- Vista principal del POS (cuando la sesión está activa) -->
        <template v-if="activeSession">
            <div class="flex flex-col lg:flex-row gap-4 h-[calc(86vh)]">
                <div class="lg:w-2/3 xl:w-3/4 h-full overflow-hidden">
                    <PosLeftPanel :products="products" :categories="categories" :pending-carts="pendingCarts"
                        :filters="filters" :active-session="activeSession" @add-to-cart="addToCart"
                        @resume-cart="resumePendingCart" @delete-cart="deletePendingCart"
                        @product-created-and-add-to-cart="handleProductCreatedAndAddToCart"
                        @refresh-session-data="handleRefreshSessionData"
                        @open-history-modal="isHistoryModalVisible = true"
                        @open-close-session-modal="isCloseSessionModalVisible = true" class="h-full" />
                </div>
                <div class="lg:w-1/3 xl:w-1/4 h-full overflow-hidden">
                    <ShoppingCart :items="cartItems" :client="selectedClient" :customers="localCustomers"
                        :default-customer="defaultCustomer" :active-promotions="activePromotions"
                        @update-quantity="updateCartQuantity" @update-price="updateCartPrice"
                        @remove-item="removeCartItem" @clear-cart="clearCart" @select-customer="handleSelectCustomer"
                        @customer-created="handleCustomerCreated" @save-cart="saveCartToPending"
                        @checkout="handleCheckout" class="h-full" />
                </div>
            </div>
        </template>

        <!-- Pantalla de "Lobby" cuando no hay sesión activa -->
        <template v-else>
            <div class="flex items-center justify-center h-[calc(100vh-150px)] dark:bg-gray-900 rounded-lg">
                <div class="text-center p-8">
                    <div
                        class="bg-sky-100 dark:bg-sky-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                        <i class="pi pi-inbox !text-4xl text-sky-500"></i>
                    </div>
                    <!-- Mensaje cambia dependiendo de si hay sesiones para unirse o para crear -->
                    <h2 v-if="joinableSessions && joinableSessions.length > 0"
                        class="text-2xl font-bold text-gray-800 dark:text-gray-200">
                        Sesiones de caja activas
                    </h2>
                    <h2 v-else class="text-2xl font-bold text-gray-800 dark:text-gray-200">
                        Punto de venta bloqueado
                    </h2>

                    <p class="text-gray-600 dark:text-gray-400 mt-2 max-w-md">
                        <span v-if="joinableSessions && joinableSessions.length > 0">
                            Hay cajas abiertas por otros usuarios. Únete a una sesión para empezar a vender.
                        </span>
                        <span v-else>
                            Necesitas abrir una nueva sesión de caja para poder registrar ventas.
                        </span>
                    </p>

                    <!-- Botones cambian según el contexto -->
                    <Button v-if="joinableSessions && joinableSessions.length > 0"
                        @click="isJoinSessionModalVisible = true" label="Unirse a una sesión" icon="pi pi-users"
                        class="mt-6" />
                    <Button v-else-if="availableCashRegisters && availableCashRegisters.length > 0"
                         @click="isStartSessionModalVisible = true" label="Abrir una caja"
                        icon="pi pi-lock-open" class="mt-6" />
                     <p v-else class="text-sm text-gray-500 pt-4 mt-8">
                                No hay cajas disponibles para unirse o abrir en esta sucursal.
                    </p>
                </div>
            </div>
        </template>

        <!-- Modales -->
        <StartSessionModal
            :visible="isStartSessionModalVisible"
            :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts"
            @update:visible="isStartSessionModalVisible = $event"
        />
        <JoinSessionModal
            :visible="isJoinSessionModalVisible"
            :sessions="joinableSessions"
            @update:visible="isJoinSessionModalVisible = $event"
        />
        <CloseSessionModal
            v-if="activeSession"
            :visible="isCloseSessionModalVisible"
            :session="activeSession"
            @update:visible="isCloseSessionModalVisible = $event"
        />
        <SessionHistoryModal
            v-if="activeSession"
            :visible="isHistoryModalVisible"
            :session="activeSession"
            @update:visible="isHistoryModalVisible = $event"
        />
        <PrintModal
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />
    </AppLayout>
</template>