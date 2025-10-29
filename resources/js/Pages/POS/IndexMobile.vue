<script setup>
import { ref, watch, computed } from 'vue'; // <-- Asegurar computed
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import PosLeftPanel from './Partials/PosLeftPanel.vue';
import ShoppingCart from './Partials/ShoppingCart.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import CloseSessionModal from '@/Components/CloseSessionModal.vue';
import SessionHistoryModal from '@/Components/SessionHistoryModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
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

// --- Lógica para Drawer del Carrito ---
const isCartDrawerVisible = ref(false);
const cartItemCount = computed(() => cartItems.value.reduce((acc, item) => acc + item.quantity, 0));

// --- Lógica para Modales ---
const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const isCloseSessionModalVisible = ref(false);
const isHistoryModalVisible = ref(false);
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

watch(() => page.props.flash.print_data, (newPrintData) => {
    if (newPrintData) {
        printDataSource.value = newPrintData;
        isPrintModalVisible.value = true;
        page.props.flash.print_data = null;
    }
}, { immediate: true });

// --- Helper CORREGIDO para calcular precio por volumen ---
const getPriceForQuantity = (productData, quantity) => {
    // Precio base ANTES de promos directas (precio original de 1 pieza)
    // Usamos selling_price que viene del backend y representa el precio base original
    const absoluteOriginalPrice = parseFloat(productData.selling_price);

    // Precio base DESPUÉS de promos directas (precio de 1 pieza con promo si aplica)
    // Usamos productData.price que ya calculó el backend (considerando promos ITEM_DISCOUNT)
    const basePriceAfterDirectPromo = parseFloat(productData.price);

    // Si no hay tiers, la cantidad es 1, o los tiers están vacíos
    if (!productData.price_tiers || productData.price_tiers.length === 0 || quantity <= 1) {
        // Devolver el precio base (con promo directa si aplica) y el original puro
        return {
            price: basePriceAfterDirectPromo,
            original_price_base: absoluteOriginalPrice, // El precio original sin promos ni tiers
            isTierPrice: false
        };
    }

    // Ordenar tiers por cantidad mínima DESCENDENTE para encontrar el mejor aplicable
    // Asegurarse de que min_quantity sea numérico
    const sortedTiers = [...productData.price_tiers]
        .map(t => ({ ...t, min_quantity: parseInt(t.min_quantity, 10) })) // Convertir a número
        .sort((a, b) => b.min_quantity - a.min_quantity);

    // Buscar el primer tier (el más alto) cuya cantidad mínima sea <= a la cantidad actual
    for (const tier of sortedTiers) {
        // Asegurarse de que min_quantity es un número válido antes de comparar
        if (!isNaN(tier.min_quantity) && quantity >= tier.min_quantity) {
            // Precio del tier encontrado
            const tierPrice = parseFloat(tier.price);
            // Devolver el precio del tier y el precio original puro
            return {
                price: tierPrice,
                original_price_base: absoluteOriginalPrice,
                isTierPrice: true
            };
        }
    }

    // Si la cantidad es > 1 pero NO alcanzó ningún tier, DEBE volver al precio base (con promo directa)
    return {
        price: basePriceAfterDirectPromo, // <- ESTA ES LA CLAVE DE LA CORRECCIÓN
        original_price_base: absoluteOriginalPrice,
        isTierPrice: false
    };
};
// --- FIN: Helper CORREGIDO ---


const addToCart = (data) => {
    const { product, variant } = data;
    const cartItemId = variant ? `prod-${product.id}-variant-${variant.id}` : `prod-${product.id}`;
    const existingItem = cartItems.value.find(item => item.cartItemId === cartItemId);
    const stock = variant ? variant.stock : product.stock;
    const quantityToAdd = 1;
    const targetQuantity = existingItem ? existingItem.quantity + quantityToAdd : 1;

    // Usar datos del producto base para calcular el precio inicial
    const productBaseDataForCalc = {
        selling_price: product.selling_price, // Precio original base
        price: product.price,               // Precio base con promo directa (para qty 1)
        price_tiers: product.price_tiers
    };
    const { price: calculatedPrice, original_price_base: baseOriginalPrice, isTierPrice } = getPriceForQuantity(productBaseDataForCalc, targetQuantity);

    if (existingItem) {
        if (targetQuantity <= stock || stock < 0) {
            existingItem.quantity = targetQuantity;
            // Recalcular solo si no es manual
            if (!existingItem.isManualPrice) {
                // Usar los datos base guardados en el item existente para recalcular
                const itemBaseDataForCalc = {
                    selling_price: existingItem.selling_price, // Original base guardado
                    price: existingItem.price_qty_1_promo, // Precio qty 1 con promo guardado
                    price_tiers: existingItem.price_tiers
                };
                const { price: updatedPrice, original_price_base: updatedOriginalBase, isTierPrice: updatedIsTier } = getPriceForQuantity(itemBaseDataForCalc, existingItem.quantity);

                // Calcular modificador de variante basado en los originales guardados
                let variantModifier = 0;
                if (existingItem.product_attribute_id) {
                    variantModifier = (existingItem.original_price ?? existingItem.selling_price) - existingItem.selling_price;
                }

                existingItem.price = updatedPrice + variantModifier; // Aplicar modificador DESPUÉS
                // original_price_base no cambia, ya lo tenemos en existingItem.selling_price
                existingItem.isTierPrice = updatedIsTier;
            }
        } else {
            toast.add({ severity: 'warn', summary: 'Stock Insuficiente', detail: `No puedes agregar más de ${stock} unidades.`, life: 3000 });
        }
    } else {
        // Guardar los precios base importantes en el nuevo item
        const newItem = {
            ...product, // Copia datos del producto (incluye price_tiers)
            cartItemId: cartItemId,
            quantity: quantityToAdd,
            selling_price: baseOriginalPrice, // Guardar original base (sin promo, sin tier)
            price_qty_1_promo: product.price, // Guardar precio qty 1 (con promo directa si aplica)
            price: calculatedPrice, // Precio calculado inicial (tier o promo directa)
            original_price: baseOriginalPrice, // Usado para calcular subtotal bruto y descuentos
            isTierPrice: isTierPrice,
            isManualPrice: false,
            ...(variant && {
                price: calculatedPrice + variant.price_modifier, // Aplicar modificador
                original_price: baseOriginalPrice + variant.price_modifier, // Base + modificador
                sku: `${product.sku}-${variant.sku_suffix}`,
                stock: variant.stock,
                selectedVariant: variant.attributes,
                product_attribute_id: variant.id,
                image: variant.image_url || product.image,
                // selling_price y price_qty_1_promo se mantienen del producto base
            })
        };
        // CORRECCIÓN: Asegurarse de que original_price en variante también sume el modificador
        if (variant) {
            newItem.original_price = baseOriginalPrice + variant.price_modifier;
        }

        cartItems.value.push(newItem);
        if (stock <= 0) {
            toast.add({ severity: 'warn', summary: 'Sin Stock', detail: `El producto se agregó al carrito pero no tiene stock disponible.`, life: 6000 });
        }
    }
};

const updateCartQuantity = ({ itemId, quantity }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) {
        const validQuantity = Math.max(1, quantity || 1);
        const oldQuantity = item.quantity; // Guardar cantidad anterior por si hay error
        item.quantity = validQuantity; // Actualizar cantidad primero

        // Recalcular precio basado en la nueva cantidad, *solo si no es manual*
        if (!item.isManualPrice) {
            // Usar los datos base guardados en el item para recalcular
            const itemBaseDataForCalc = {
                selling_price: item.selling_price,         // Original base guardado
                price: item.price_qty_1_promo, // Precio qty 1 con promo guardado
                price_tiers: item.price_tiers            // Tiers guardados
            };

            // Verificar que los datos base existen antes de calcular
            if (typeof itemBaseDataForCalc.selling_price !== 'undefined' &&
                typeof itemBaseDataForCalc.price !== 'undefined') {

                const { price: updatedPrice, isTierPrice: updatedIsTier } = getPriceForQuantity(itemBaseDataForCalc, validQuantity);

                // Calcular modificador de variante basado en los originales guardados
                let variantModifier = 0;
                if (item.product_attribute_id) {
                    // Usar original_price (base + modif) y selling_price (base) para obtener modificador
                    variantModifier = (item.original_price ?? item.selling_price) - item.selling_price;
                }

                item.price = updatedPrice + variantModifier; // Aplicar modificador DESPUÉS
                item.isTierPrice = updatedIsTier; // Actualizar flag
            } else {
                console.error("Faltan datos base en el item para recalcular precio:", item);
                item.quantity = oldQuantity; // Revertir cantidad si hubo error
                toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo recalcular el precio.', life: 3000 });
            }
        }
        // Si es precio manual, solo se actualiza la cantidad, el precio no cambia
    }
};


const updateCartPrice = ({ itemId, price }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) {
        item.price = Math.max(0, price || 0);
        item.isManualPrice = true;
        item.isTierPrice = false; // Precio manual anula tier
    }
};


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
    isCartDrawerVisible.value = false;
    toast.add({ severity: 'success', summary: 'Carrito Guardado', detail: 'El carrito actual se movió a la lista de espera.', life: 3000 });
};
const resumePendingCart = (cartId) => {
    const cartToResume = pendingCarts.value.find(c => c.id === cartId);
    if (!cartToResume) return;
    if (cartItems.value.length > 0) saveCartToPending({ total: cartItems.value.reduce((acc, item) => acc + item.price * item.quantity, 0) });
    cartItems.value = cartToResume.items;
    selectedClient.value = cartToResume.client.id === props.defaultCustomer.id ? null : cartToResume.client;
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
    isCartDrawerVisible.value = true;
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
        selling_price: parseFloat(newProduct.selling_price), // Precio base original
        price: parseFloat(newProduct.selling_price),        // Precio para qty 1 (sin promo directa al crear)
        original_price: parseFloat(newProduct.selling_price),// Original base para cálculos
        stock: newProduct.current_stock || 0,
        category: 'Sin categoría',
        image: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' + encodeURIComponent(newProduct.name),
        description: newProduct.description || '',
        sku: newProduct.sku || '',
        price_tiers: newProduct.price_tiers || [], // Asegurar que price_tiers se pasa
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
        unit_price: item.price, // Precio final unitario
        description: item.name + (item.selectedVariant ? ` (${Object.values(item.selectedVariant).join('/')})` : ''),
        // Usar original_price (base + modif) para calcular descuento
        discount: (item.original_price ?? item.price) - item.price,
        discount_reason: (() => {
            const originalPrice = item.original_price ?? item.price;
            if (item.isManualPrice) {
                return item.price < originalPrice ? 'Descuento manual' : (item.price > originalPrice ? 'Aumento manual' : null);
            }
            if (item.isTierPrice) {
                return 'Precio de mayoreo';
            }
            if (item.price < originalPrice) {
                return 'Promoción de item';
            }
            return null; // Sin descuento o motivo específico
        })()
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
            isCartDrawerVisible.value = false;
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

    <Head title="Punto de Venta" />
    <AppLayout>
        <div class="relative h-[calc(100vh-100px)]">
            <!-- Vista principal del POS (si hay sesión activa) -->
            <template v-if="activeSession">
                <PosLeftPanel :products="products" :categories="categories" :pending-carts="pendingCarts"
                    :filters="filters" :active-session="activeSession" @add-to-cart="addToCart"
                    @resume-cart="resumePendingCart" @delete-cart="deletePendingCart"
                    @product-created-and-add-to-cart="handleProductCreatedAndAddToCart"
                    @refresh-session-data="handleRefreshSessionData" @open-history-modal="isHistoryModalVisible = true"
                    @open-close-session-modal="isCloseSessionModalVisible = true" class="h-full" />

                <!-- Botón Flotante del Carrito -->
                <div class="fixed bottom-6 right-6 z-50">
                    <Button @click="isCartDrawerVisible = true" rounded
                        class="!size-16 shadow-lg !bg-white dark:!bg-gray-700 !border !border-[#D9D9D9] dark:!border-gray-600">
                        <i class="pi pi-shopping-cart !text-2xl text-black dark:text-white"></i>
                        <Badge v-if="cartItemCount > 0" :value="cartItemCount" severity="contrast"
                            class="absolute top-1 right-3"></Badge>
                    </Button>
                </div>

                <!-- Drawer del Carrito -->
                <Drawer v-model:visible="isCartDrawerVisible" position="right" class="!w-full md:!w-[450px]">
                    <template #header>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200 m-0">Resumen de Venta</h2>
                    </template>
                    <ShoppingCart :items="cartItems" :client="selectedClient" :customers="localCustomers"
                        :default-customer="defaultCustomer" :active-promotions="activePromotions"
                        @update-quantity="updateCartQuantity" @update-price="updateCartPrice"
                        @remove-item="removeCartItem" @clear-cart="clearCart" @select-customer="handleSelectCustomer"
                        @customer-created="handleCustomerCreated" @save-cart="saveCartToPending"
                        @checkout="handleCheckout" class="h-full" />
                </Drawer>

            </template>
            <!-- Lobby cuando no hay sesión activa -->
            <template v-else>
                <div class="flex items-center justify-center h-full dark:bg-gray-900 rounded-lg">
                    <div class="text-center p-8">
                        <div
                            class="bg-blue-100 dark:bg-blue-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                            <i class="pi pi-inbox !text-4xl text-blue-500"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Punto de Venta</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-2 max-w-md">
                            No tienes una sesión de caja activa. Únete a una existente o abre una nueva para empezar.
                        </p>
                        <Button v-if="joinableSessions && joinableSessions.length > 0"
                            @click="isJoinSessionModalVisible = true" label="Unirse a una sesión activa"
                            icon="pi pi-users" class="w-full max-w-xs mt-8" />
                        <Button v-else-if="availableCashRegisters && availableCashRegisters.length > 0"
                            @click="isStartSessionModalVisible = true" label="Abrir una Caja" icon="pi pi-lock-open"
                            class="w-full max-w-xs mt-8" />
                        <div v-else class="text-sm text-gray-500 pt-4 mt-8">
                            <p>No hay cajas disponibles para unirse o abrir en esta sucursal.</p>
                            <Button @click="$inertia.visit(route('cash-registers.create'))" label="Crear una caja"
                                icon="pi pi-inbox" />
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Modales -->
        <StartSessionModal :visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts" @update:visible="isStartSessionModalVisible = $event" />
        <JoinSessionModal :visible="isJoinSessionModalVisible" :sessions="joinableSessions"
            @update:visible="isJoinSessionModalVisible = $event" />
        <CloseSessionModal v-if="activeSession" :visible="isCloseSessionModalVisible" :session="activeSession"
            @update:visible="isCloseSessionModalVisible = $event" />
        <SessionHistoryModal v-if="activeSession" :visible="isHistoryModalVisible" :session="activeSession"
            @update:visible="isHistoryModalVisible = $event" />
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
    </AppLayout>
</template>