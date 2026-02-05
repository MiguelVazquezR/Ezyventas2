<script setup>
import { ref, watch, computed, onMounted } from 'vue'; 
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
import OrderFormModal from './Partials/OrderFormModal.vue'; 
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
const isPaymentModalVisible = ref(false);
const isOrderModalVisible = ref(false);

const isCartDrawerVisible = ref(false);
const cartItemCount = computed(() => cartItems.value.reduce((acc, item) => acc + item.quantity, 0));

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

// --- PERSISTENCIA (LOCALSTORAGE) ---
const userBranchKey = computed(() => {
    const u = page.props.auth.user;
    return `pos_data_${u.id}_${u.branch_id}`;
});

onMounted(() => {
    const savedCart = localStorage.getItem(`${userBranchKey.value}_cart`);
    const savedClient = localStorage.getItem(`${userBranchKey.value}_client`);
    const savedPending = localStorage.getItem(`${userBranchKey.value}_pending`);

    if (savedCart) {
        try { cartItems.value = JSON.parse(savedCart); } catch (e) { console.error('Error restaurando carrito', e); }
    }
    if (savedClient) {
        try { selectedClient.value = JSON.parse(savedClient); } catch (e) { console.error('Error restaurando cliente', e); }
    }
    if (savedPending) {
        try { pendingCarts.value = JSON.parse(savedPending); } catch (e) { console.error('Error restaurando carritos pendientes', e); }
    }
});

watch(cartItems, (newVal) => {
    localStorage.setItem(`${userBranchKey.value}_cart`, JSON.stringify(newVal));
}, { deep: true });

watch(selectedClient, (newVal) => {
    if (newVal) localStorage.setItem(`${userBranchKey.value}_client`, JSON.stringify(newVal));
    else localStorage.removeItem(`${userBranchKey.value}_client`);
});

const pendingCarts = ref([]);
watch(pendingCarts, (newVal) => {
    localStorage.setItem(`${userBranchKey.value}_pending`, JSON.stringify(newVal));
}, { deep: true });
// -----------------------------------

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
        price: basePriceAfterDirectPromo,
        original_price_base: absoluteOriginalPrice,
        isTierPrice: false
    };
};

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
            selling_price: baseOriginalPrice, 
            price_qty_1_promo: product.price, 
            price: calculatedPrice, 
            original_price: baseOriginalPrice, 
            isTierPrice: isTierPrice,
            isManualPrice: false,
            ...(variant && {
                price: calculatedPrice + variant.price_modifier, 
                original_price: baseOriginalPrice + variant.price_modifier, 
                sku: `${product.sku}-${variant.sku_suffix}`,
                stock: variant.stock,
                selectedVariant: variant.attributes,
                product_attribute_id: variant.id,
                image: variant.image_url || product.image,
            })
        };
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
        const oldQuantity = item.quantity;
        item.quantity = validQuantity; 

        if (!item.isManualPrice) {
            const itemBaseDataForCalc = {
                selling_price: item.selling_price,         
                price: item.price_qty_1_promo, 
                price_tiers: item.price_tiers            
            };

            if (typeof itemBaseDataForCalc.selling_price !== 'undefined' &&
                typeof itemBaseDataForCalc.price !== 'undefined') {

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


const updateCartPrice = ({ itemId, price }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) {
        item.price = Math.max(0, price || 0);
        item.isManualPrice = true;
        item.isTierPrice = false; 
    }
};


const removeCartItem = (itemId) => {
    cartItems.value = cartItems.value.filter(i => i.cartItemId !== itemId);
};

const clearCart = () => {
    cartItems.value = [];
    selectedClient.value = null;
    isPaymentModalVisible.value = false;
};

const localCustomers = ref([...props.customers]);
const handleSelectCustomer = (customer) => selectedClient.value = customer;
watch(() => props.customers, (newCustomers) => { localCustomers.value = [...newCustomers]; });
const handleCustomerCreated = (newCustomer) => {
    localCustomers.value.push(newCustomer);
    selectedClient.value = newCustomer;
    toast.add({ severity: 'success', summary: 'Cliente Creado', detail: 'El nuevo cliente ha sido seleccionado.', life: 3000 });
};

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
    if (cartItems.value.length > 0) {
        pendingCarts.value.push({
            id: uuidv4(),
            client: selectedClient.value || props.defaultCustomer,
            items: JSON.parse(JSON.stringify(cartItems.value)),
            time: new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }),
            total: cartItems.value.reduce((acc, item) => acc + item.price * item.quantity, 0),
        });
    }
    
    cartItems.value = cartToResume.items;
    selectedClient.value = cartToResume.client.id === props.defaultCustomer.id ? null : cartToResume.client;
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
    isCartDrawerVisible.value = true;
};
const deletePendingCart = (cartId) => {
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
    toast.add({ severity: 'warn', summary: 'Carrito Descartado', detail: 'Se ha eliminado un carrito de la lista de espera.', life: 3000 });
};

const handleRefreshSessionData = () => {
    router.reload({
        preserveState: true,
        preserveScroll: true,
    });
};

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
        price_tiers: newProduct.price_tiers || [], 
        variants: {},
        variant_combinations: [],
        promotions: [],
    };
    addToCart({ product: formattedProduct });
    router.reload({ preserveState: true, only: ['products'] });
};

const form = useForm({
    cartItems: [], customerId: null,
    subtotal: 0, total_discount: 0, total: 0,
    payments: [], use_balance: false,
    cash_register_session_id: null,
    layaway_expiration_date: null,
    is_order: false,
    contact_info: null,
    delivery_date: null,
    shipping_address: null,
    shipping_cost: 0,
    notes: null
});

const mapCartItems = () => {
    return cartItems.value.map(item => ({
        id: item.id,
        product_attribute_id: item.product_attribute_id || null,
        quantity: item.quantity,
        unit_price: item.price,
        description: item.name + (item.selectedVariant ? ` (${Object.values(item.selectedVariant).join('/')})` : ''),
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
                return 'Promoción de producto';
            }
            return null; 
        })()
    }));
};

const handleOrderSubmit = (orderData) => {
    if (!props.activeSession) {
        toast.add({ severity: 'error', summary: 'Caja Cerrada', detail: 'Debes tener una sesión de caja activa para registrar pedidos.', life: 5000 });
        return;
    }

    const currentSubtotal = cartItems.value.reduce((acc, item) => acc + (item.price * item.quantity), 0);
    const itemsDiscountTotal = cartItems.value.reduce((acc, item) => {
        const base = item.original_price ?? item.price;
        return acc + ((base - item.price) * item.quantity);
    }, 0);

    form.reset();
    form.cartItems = mapCartItems();
    form.customerId = selectedClient.value ? selectedClient.value.id : null;
    form.subtotal = currentSubtotal;
    form.total_discount = itemsDiscountTotal; 
    
    form.is_order = true;
    form.contact_info = { 
        name: orderData.contact_name, 
        phone: orderData.contact_phone 
    };
    form.delivery_date = orderData.delivery_date;
    form.shipping_address = orderData.shipping_address;
    form.shipping_cost = orderData.shipping_cost;
    form.notes = orderData.notes;
    form.cash_register_session_id = props.activeSession.id;
    form.total = currentSubtotal + parseFloat(orderData.shipping_cost);

    form.post(route('pos.store-order'), { 
        onSuccess: () => {
            clearCart();
            isOrderModalVisible.value = false;
            toast.add({ severity: 'success', summary: 'Pedido Creado', detail: 'El pedido ha sido registrado correctamente.', life: 3000 });
            router.reload({ only: ['products'], preserveState: true });
        },
        onError: (errors) => {
            console.error(errors);
            toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa los datos del pedido.', life: 5000 });
        }
    });
};

const handleCheckout = (checkoutData) => {
    if (!props.activeSession) {
        toast.add({ severity: 'error', summary: 'Caja Cerrada', detail: 'Debes tener una sesión de caja activa para registrar una venta.', life: 5000 });
        return;
    }

    form.reset();
    form.is_order = false;
    form.cartItems = mapCartItems();
    form.customerId = selectedClient.value ? selectedClient.value.id : null;
    form.subtotal = checkoutData.subtotal;
    form.total_discount = checkoutData.total_discount;
    form.total = checkoutData.total;
    form.cash_register_session_id = props.activeSession.id;
    form.payments = checkoutData.payments;
    form.use_balance = checkoutData.use_balance;
    form.layaway_expiration_date = checkoutData.layaway_expiration_date;

    let routeName;
    const transactionType = checkoutData.transactionType;

    switch (transactionType) {
        case 'contado':
        case 'credito':
            routeName = 'pos.checkout';
            break;
        case 'apartado':
            routeName = 'pos.layaway';
            break;
        default:
            return;
    }

    form.post(route(routeName), {
        onSuccess: () => {
            clearCart();
            page.props.flash.success = null;
            router.reload({ only: ['products'], preserveState: true });
        },
        onError: (errors) => {
            const errorMessage = errors.default || errors.message || Object.values(errors).flat().join(' ');
            toast.add({ severity: 'error', summary: 'Error al Procesar', detail: errorMessage, life: 7000 });
        }
    });
};

const currentCartTotal = computed(() => {
    return cartItems.value.reduce((acc, item) => acc + (item.price * item.quantity), 0);
});
</script>

<template>

    <Head title="Punto de Venta" />
    <AppLayout>
        <div class="relative h-[calc(100vh-100px)]">
            <template v-if="activeSession">
                <PosLeftPanel :products="products" :categories="categories" :pending-carts="pendingCarts"
                    :filters="filters" :active-session="activeSession" :cart-items="cartItems" @add-to-cart="addToCart"
                    @resume-cart="resumePendingCart" @delete-cart="deletePendingCart"
                    @product-created-and-add-to-cart="handleProductCreatedAndAddToCart"
                    @refresh-session-data="handleRefreshSessionData" @open-history-modal="isHistoryModalVisible = true"
                    @open-close-session-modal="isCloseSessionModalVisible = true" class="h-full" />

                <div class="fixed bottom-6 right-6 z-50">
                    <Button @click="isCartDrawerVisible = true" rounded
                        class="!size-16 shadow-lg !bg-white dark:!bg-gray-700 !border !border-[#D9D9D9] dark:!border-gray-600">
                        <i class="pi pi-shopping-cart !text-2xl text-black dark:text-white"></i>
                        <Badge v-if="cartItemCount > 0" :value="cartItemCount" severity="contrast"
                            class="absolute top-1 right-3"></Badge>
                    </Button>
                </div>

                <Drawer v-model:visible="isCartDrawerVisible" position="right" class="!w-full md:!w-[450px]">
                    <template #header>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200 m-0">Resumen de venta</h2>
                    </template>
                    <ShoppingCart 
                        :items="cartItems" 
                        :client="selectedClient" 
                        :customers="localCustomers"
                        :default-customer="defaultCustomer" 
                        :active-promotions="activePromotions"
                        :loading="form.processing"
                        :payment-modal-visible="isPaymentModalVisible"
                        @update-quantity="updateCartQuantity" 
                        @update-price="updateCartPrice"
                        @remove-item="removeCartItem" 
                        @clear-cart="clearCart" 
                        @select-customer="handleSelectCustomer"
                        @customer-created="handleCustomerCreated" 
                        @save-cart="saveCartToPending"
                        @checkout="handleCheckout" 
                        @open-payment-modal="isPaymentModalVisible = true"
                        @close-payment-modal="isPaymentModalVisible = false"
                        @open-order-modal="isOrderModalVisible = true"
                        class="h-full" 
                    />
                </Drawer>

            </template>
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
        
        <!-- NUEVO MODAL DE PEDIDO -->
        <OrderFormModal 
            v-model:visible="isOrderModalVisible"
            :cart-total="currentCartTotal"
            :client="selectedClient"
            :loading="form.processing"
            @submit="handleOrderSubmit"
        />
    </AppLayout>
</template>