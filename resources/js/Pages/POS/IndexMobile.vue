<script setup>
import { ref, watch, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import PosLeftPanel from './Partials/PosLeftPanel.vue';
import ShoppingCart from './Partials/ShoppingCart.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
// --- NUEVO: Importar el modal para unirse a sesiones ---
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
    // --- NUEVO: Recibir las sesiones a las que se puede unir ---
    joinableSessions: Array,
});

const page = usePage();
const toast = useToast();

const cartItems = ref([]);
const selectedClient = ref(null);

const isCartDrawerVisible = ref(false);
const cartItemCount = computed(() => cartItems.value.reduce((acc, item) => acc + item.quantity, 0));

// --- Lógica para Modales ---
const isStartSessionModalVisible = ref(false);
// --- NUEVO: Estado para el nuevo modal ---
const isJoinSessionModalVisible = ref(false);
const isCloseSessionModalVisible = ref(false);
const isHistoryModalVisible = ref(false);
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

// ... (El resto del script setup se mantiene sin cambios)
watch(() => page.props.flash.print_data, (newPrintData) => {
    if (newPrintData) {
        printDataSource.value = newPrintData;
        isPrintModalVisible.value = true;
        page.props.flash.print_data = null;
    }
}, { immediate: true });


const addToCart = (data) => {
    const { product, variant } = data;
    const cartItemId = variant ? `prod-${product.id}-variant-${variant.id}` : `prod-${product.id}`;
    const existingItem = cartItems.value.find(item => item.cartItemId === cartItemId);

    const stock = variant ? variant.stock : product.stock;

    if (existingItem) {
        if (existingItem.quantity < stock) {
            existingItem.quantity++;
        } else {
            if (stock > 0) {
                toast.add({ severity: 'warn', summary: 'Stock Insuficiente', detail: `No puedes agregar más de ${stock} unidades.`, life: 3000 });
            } else {
                toast.add({ severity: 'warn', summary: 'Sin Stock', detail: `El producto no tiene stock, pero se agregó al carrito.`, life: 6000 });
            }
        }
    } else {
        const newItem = {
            ...product,
            cartItemId: cartItemId,
            quantity: 1,
            original_price: product.original_price,
            ...(variant && {
                price: product.price + variant.price_modifier,
                original_price: product.original_price + variant.price_modifier,
                sku: `${product.sku}-${variant.sku_suffix}`,
                stock: variant.stock,
                selectedVariant: variant.attributes,
                product_attribute_id: variant.id,
                name: product.name,
                image: variant.image_url || product.image,
            })
        };
        cartItems.value.push(newItem);
        if (stock <= 0) {
            toast.add({ severity: 'warn', summary: 'Sin Stock', detail: `El producto se agregó al carrito pero no tiene stock disponible.`, life: 6000 });
        }
    }
};

const updateCartQuantity = ({ itemId, quantity }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) item.quantity = quantity;
};

const updateCartPrice = ({ itemId, price }) => {
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    if (item) item.price = price;
};

const removeCartItem = (itemId) => {
    cartItems.value = cartItems.value.filter(i => i.cartItemId !== itemId);
};

const clearCart = () => {
    cartItems.value = [];
    selectedClient.value = null;
};

const localCustomers = ref([...props.customers]);
const handleSelectCustomer = (customer) => selectedClient.value = customer;

watch(() => props.customers, (newCustomers) => {
    localCustomers.value = [...newCustomers];
});

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
    isCartDrawerVisible.value = false; // Ocultar drawer al guardar
    toast.add({ severity: 'success', summary: 'Carrito Guardado', detail: 'El carrito actual se movió a la lista de espera.', life: 3000 });
};

const handleRefreshSessionData = () => {
    router.reload({
        preserveState: true,
        preserveScroll: true,
    });
};

const resumePendingCart = (cartId) => {
    const cartToResume = pendingCarts.value.find(c => c.id === cartId);
    if (!cartToResume) return;
    if (cartItems.value.length > 0) saveCartToPending({ total: cartItems.value.reduce((acc, item) => acc + item.price * item.quantity, 0) });
    cartItems.value = cartToResume.items;
    selectedClient.value = cartToResume.client.id === props.defaultCustomer.id ? null : cartToResume.client;
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
    isCartDrawerVisible.value = true; // Mostrar carrito al reanudar
};

const deletePendingCart = (cartId) => {
    pendingCarts.value = pendingCarts.value.filter(c => c.id !== cartId);
    toast.add({ severity: 'warn', summary: 'Carrito Descartado', detail: 'Se ha eliminado un carrito de la lista de espera.', life: 3000 });
};

const form = useForm({
    cartItems: [], customerId: null,
    subtotal: 0, total_discount: 0, total: 0,
    payments: [], use_balance: false,
    cash_register_session_id: null,
});

const handleProductCreatedAndAddToCart = (newProduct) => {
    const formattedProduct = {
        id: newProduct.id,
        name: newProduct.name,
        price: parseFloat(newProduct.selling_price),
        original_price: parseFloat(newProduct.selling_price),
        stock: newProduct.current_stock || 0,
        category: 'Sin categoría',
        image: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' + encodeURIComponent(newProduct.name),
        description: newProduct.description || '',
        sku: newProduct.sku || '',
        variants: {},
        variant_combinations: [],
        promotions: [],
    };
    addToCart({ product: formattedProduct });
    router.reload({ preserveState: true, only: ['products'] });
};

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
        description: item.name,
        discount: (item.original_price || item.price) - item.price,
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
            const errorMessage = errors.default || Object.values(errors).flat().join(' ');
            toast.add({ severity: 'error', summary: 'Error al Procesar', detail: errorMessage, life: 7000 });
        }
    });
};
</script>

<template>
    <Head title="Punto de Venta" />
    <AppLayout>
        <div class="relative h-[calc(100vh-100px)]">
            <template v-if="activeSession">
                <PosLeftPanel 
                    :products="products" 
                    :categories="categories" 
                    :pending-carts="pendingCarts"
                    :filters="filters" 
                    :active-session="activeSession" 
                    @add-to-cart="addToCart"
                    @resume-cart="resumePendingCart" 
                    @delete-cart="deletePendingCart"
                    @product-created-and-add-to-cart="handleProductCreatedAndAddToCart"
                    @refresh-session-data="handleRefreshSessionData" 
                    @open-history-modal="isHistoryModalVisible = true"
                    @open-close-session-modal="isCloseSessionModalVisible = true" 
                />

                <!-- Botón Flotante del Carrito -->
                <div class="fixed bottom-6 right-6 z-50">
                    <Button @click="isCartDrawerVisible = true" rounded
                        class="!size-16 shadow-lg !bg-white !border !border-[#D9D9D9]">
                        <i class="pi pi-shopping-cart !text-2xl text-black"></i>
                        <Badge v-if="cartItemCount > 0" :value="cartItemCount" severity="contrast"
                            class="absolute top-1 right-3"></Badge>
                    </Button>
                </div>

                <!-- Drawer del Carrito -->
                <Drawer v-model:visible="isCartDrawerVisible" position="right" class="!w-full">
                    <template #header>
                        <h2 class="text-xl font-bold">Resumen de Venta</h2>
                    </template>
                    <ShoppingCart 
                        :items="cartItems" 
                        :client="selectedClient" 
                        :customers="localCustomers"
                        :default-customer="defaultCustomer" 
                        :active-promotions="activePromotions"
                        @update-quantity="updateCartQuantity" 
                        @update-price="updateCartPrice"
                        @remove-item="removeCartItem" 
                        @clear-cart="clearCart" 
                        @select-customer="handleSelectCustomer"
                        @customer-created="handleCustomerCreated" 
                        @save-cart="saveCartToPending"
                        @checkout="handleCheckout" 
                        class="h-full" 
                    />
                </Drawer>

            </template>
            <!-- --- NUEVO: Lógica del Lobby para cuando no hay sesión activa --- -->
            <template v-else>
                <div class="flex items-center justify-center h-full dark:bg-gray-900 rounded-lg">
                    <div class="text-center p-8">
                        <div class="bg-blue-100 dark:bg-blue-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                            <i class="pi pi-inbox !text-4xl text-blue-500"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Punto de Venta</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-2 max-w-md">
                            No tienes una sesión de caja activa. Únete a una existente o abre una nueva para empezar.
                        </p>
                        
                        <div class="mt-8 space-y-3 flex flex-col items-center">
                            <!-- Botón para Unirse a Sesión -->
                            <Button 
                                v-if="joinableSessions && joinableSessions.length > 0"
                                @click="isJoinSessionModalVisible = true" 
                                label="Unirse a una sesión activa" 
                                icon="pi pi-users"
                                class="w-full max-w-xs" 
                            />
                            <!-- Botón para Abrir Caja -->
                            <Button 
                                v-if="availableCashRegisters && availableCashRegisters.length > 0"
                                @click="isStartSessionModalVisible = true" 
                                label="Abrir una Caja" 
                                icon="pi pi-lock-open"
                                class="w-full max-w-xs" 
                            />
                            <!-- Mensaje si no hay opciones -->
                            <p v-if="(!joinableSessions || joinableSessions.length === 0) && (!availableCashRegisters || availableCashRegisters.length === 0)" class="text-sm text-gray-500 pt-4">
                                No hay cajas disponibles para unirse o abrir en esta sucursal.
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <StartSessionModal 
            :visible="isStartSessionModalVisible" 
            :cash-registers="availableCashRegisters"
            @update:visible="isStartSessionModalVisible = $event" 
        />
        <!-- --- NUEVO: Instancia del nuevo modal --- -->
        <JoinSessionModal 
            :visible="isJoinSessionModalVisible"
            :sessions="joinableSessions"
            @update:visible="isJoinSessionModalVisible = $event"
        />
        <CloseSessionModal 
            :visible="isCloseSessionModalVisible" 
            :session="activeSession"
            @update:visible="isCloseSessionModalVisible = $event" 
        />
        <SessionHistoryModal 
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