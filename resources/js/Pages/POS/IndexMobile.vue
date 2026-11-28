<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount, defineAsyncComponent, reactive, shallowRef } from 'vue'; 
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PosLeftPanel from './Partials/PosLeftPanel.vue';
import ShoppingCart from './Partials/ShoppingCart.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import CloseSessionModal from '@/Components/CloseSessionModal.vue';
import SessionHistoryModal from '@/Components/SessionHistoryModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import OrderFormModal from './Partials/OrderFormModal.vue';
import VirtualNumpad from '@/Components/VirtualNumpad.vue';
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
    hasOnlineStore: Boolean,
    // Weighing (bulk products). Null when the scale is disabled for the business.
    scale: Object,
    // "Modo Caja Inteligente" (recognition). Null when disabled for the business.
    smartCheckout: Object,
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
// Sale id of the last checkout, used by the smart checkout session to report it.
const lastCheckoutSaleId = ref(null);

// --- Lógica de Modo POS (Retail vs Comandas) ---
const posMode = ref(localStorage.getItem('pos_mode') || 'retail');
watch(posMode, (newVal) => {
    localStorage.setItem('pos_mode', newVal);
});

watch(() => page.props.flash.print_data, (newPrintData) => {
    if (newPrintData) {
        printDataSource.value = newPrintData;
        // Keep the sale id: the smart checkout session reports it when the sale closes.
        lastCheckoutSaleId.value = newPrintData.id ?? null;
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

    // The mode is remembered per terminal (decision D2). Only when the cashier
    // left it on do we load the session and start the engine again.
    if (smartCheckoutEnabled.value) {
        (async () => {
            const { readTerminalMode } = await import('./SmartCheckout/useSmartCheckout.js');
            if (!readTerminalMode(terminalUser(), terminalBranch()).active) return;
            const session = await loadSmartSession();
            await session.activate();
        })();
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
    const { product, variant, quantity } = data;
    const cartItemId = variant ? `prod-${product.id}-variant-${variant.id}` : `prod-${product.id}`;
    const existingItem = cartItems.value.find(item => item.cartItemId === cartItemId);
    const stock = variant ? variant.stock : product.stock;
    // Bulk products arrive with a decimal weight; everything else keeps adding 1.
    const quantityToAdd = Number(quantity) > 0 ? roundQuantity(Number(quantity)) : 1;
    const targetQuantity = existingItem ? roundQuantity(existingItem.quantity + quantityToAdd) : quantityToAdd;

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
            toast.add({ severity: 'warn', summary: 'Stock insuficiente', detail: `No puedes agregar más de ${stock} unidades.`, life: 3000 });
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
            toast.add({ severity: 'warn', summary: 'Sin stock', detail: `El producto se agregó al carrito pero no tiene stock disponible.`, life: 6000 });
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
    const item = cartItems.value.find(i => i.cartItemId === itemId);
    cartItems.value = cartItems.value.filter(i => i.cartItemId !== itemId);
    // Fase 4: si esa línea la había puesto el Modo Caja Inteligente, borrarla es
    // una corrección del cajero y se registra (y aprende) desde la sesión.
    smartSession.value?.reportCartRemoval?.(item);
};

const clearCart = () => {
    cartItems.value = [];
    selectedClient.value = null;
    isPaymentModalVisible.value = false;
};

// --- Báscula (productos a granel) ---
// Everything here is inert unless the business enabled the scale: the panel and
// its settings are loaded with a dynamic import on demand, so a POS with the
// scale off never downloads this code.
const scaleEnabled = computed(() => Boolean(props.scale?.enabled));
const canUseScale = computed(() => scaleEnabled.value && Boolean(props.scale?.can_use));
const canConfigureScale = computed(() => Boolean(props.scale?.can_configure));

// Async components: Vite emits a separate chunk and it is fetched only when the
// component is actually rendered, so a POS with the scale off never downloads it.
const ScalePanel = defineAsyncComponent(() => import('./SmartCheckout/ScalePanel.vue'));
const ScaleSettingsModal = defineAsyncComponent(() => import('./SmartCheckout/ScaleSettingsModal.vue'));
const ScalePreferencesModal = defineAsyncComponent(() => import('./SmartCheckout/ScalePreferencesModal.vue'));

const isScalePanelVisible = ref(false);
const isScaleSettingsVisible = ref(false);
const isScalePreferencesVisible = ref(false);
const scaleProduct = ref(null);
const scaleProducts = ref([]);
const scaleProductsLoading = ref(false);

/** A product is weighable when it is bulk AND sold by weight (kg / grams). */
const isWeighableProduct = (product) => {
    if (!product?.is_bulk) return false;
    const unit = String(product.measure_unit ?? '').trim().toLowerCase();
    return ['kg', 'kgs', 'g', 'gr', 'grs', 'gramo', 'gramos'].includes(unit);
};

/** Money and weights use 2 and 3 decimals respectively across the POS. */
const roundQuantity = (value) => Math.round((Number(value) || 0) * 1000) / 1000;

const loadWeighableProducts = async (search = '') => {
    if (!canUseScale.value) return;
    scaleProductsLoading.value = true;
    try {
        const { data } = await axios.get(route('pos.smart-checkout.scale.products'), {
            params: search ? { search } : {},
        });
        scaleProducts.value = Array.isArray(data?.data) ? data.data : [];
    } catch (error) {
        scaleProducts.value = [];
        toast.add({
            severity: 'error',
            summary: 'Báscula no disponible',
            detail: error?.response?.data?.message || 'No se pudieron cargar los productos a granel.',
            life: 4000,
        });
    } finally {
        scaleProductsLoading.value = false;
    }
};

const openScalePanel = async (product = null) => {
    scaleProduct.value = product;
    isScalePanelVisible.value = true;
    if (!product && !scaleProducts.value.length) await loadWeighableProducts();
};

/**
 * Single entry point for every "add to cart" in this page: bulk products go to
 * the scale first, everything else keeps the untouched POS behaviour.
 */
const handleAddToCart = (data) => {
    if (canUseScale.value && !data?.quantity && isWeighableProduct(data?.product)) {
        openScalePanel(data.product);
        return;
    }
    addToCart(data);
};

const handleScaleAdd = (payload) => {
    if (!payload?.product) return;
    if (payload.pick) {
        // Selected from the "granel" list: keep the panel open to weigh it.
        scaleProduct.value = payload.product;
        return;
    }
    // Same POS action the product cards use, now carrying the weighed quantity.
    addToCart({ product: payload.product, quantity: payload.quantity });
    toast.add({
        severity: 'success',
        summary: 'Producto agregado',
        detail: `${payload.product.name} · ${payload.quantity} ${payload.unit || payload.product.measure_unit || 'kg'}`,
        life: 2500,
    });
};

const openScaleSettings = () => {
    isScalePanelVisible.value = false;
    isScaleSettingsVisible.value = true;
};

// --- Modo Caja Inteligente (reconocimiento) ---
// The session (and with it the engine) is imported dynamically the first time the
// cashier turns the mode on: with the mode off nothing new is downloaded.
const smartCheckoutEnabled = computed(() => Boolean(props.smartCheckout?.enabled));
const smartSession = shallowRef(null);
const SmartCheckoutPanel = defineAsyncComponent(() => import('./SmartCheckout/SmartCheckoutPanel.vue'));
const SmartCheckoutIndicators = defineAsyncComponent(() => import('./SmartCheckout/SmartCheckoutIndicators.vue'));

const terminalUser = () => page.props.auth?.user?.id ?? null;
const terminalBranch = () => page.props.auth?.current_branch?.id ?? null;

/** Everything the engine detects ends up here, on the same action the cards use. */
const handleSmartCheckoutAdd = (product, meta = {}) => {
    if (!product) return;
    if (canUseScale.value && isWeighableProduct(product)) {
        // Bulk products are weighed before they reach the cart.
        openScalePanel(product);
        return;
    }
    // `quantity` comes from the camera fusion (1 unless the cameras counted more).
    addToCart({ product, quantity: Number(meta.quantity) > 1 ? Number(meta.quantity) : undefined });
    if (meta.source === 'auto') {
        toast.add({ severity: 'success', summary: 'Agregado automático', detail: product.name, life: 1800 });
    }
};

const loadSmartSession = async () => {
    if (smartSession.value) return smartSession.value;
    const { useSmartCheckout } = await import('./SmartCheckout/useSmartCheckout.js');
    smartSession.value = reactive(useSmartCheckout({
        settings: props.smartCheckout?.settings,
        products: props.products?.data ?? [],
        userId: terminalUser(),
        branchId: terminalBranch(),
        // La galería de vectores es de la suscripción; el POS no la recibe como
        // prop, pero sí la sucursal actual (con su subscription_id).
        subscriptionId: page.props.auth?.current_branch?.subscription_id ?? null,
        onAdd: handleSmartCheckoutAdd,
    }));
    return smartSession.value;
};

const toggleSmartCheckout = async () => {
    if (!smartCheckoutEnabled.value) return;
    const session = await loadSmartSession();
    if (session.active) {
        await session.deactivate();
        return;
    }
    const started = await session.activate();
    if (!started) {
        toast.add({
            severity: 'error',
            summary: 'No se pudo activar',
            detail: session.error || 'El motor de reconocimiento no arrancó.',
            life: 5000,
        });
    }
};

/**
 * Un producto se dio de alta desde el panel del modo (alta rápida).
 *
 * El panel **ya lo agregó al carrito** al resolver el producto desconocido
 * (`session.resolveUnknown` → `onAdd` → `addToCart`), así que aquí sólo se refresca
 * el catálogo. Volver a llamar a `addToCart` lo agregaba dos veces: era un fallo
 * real de la Fase 3 que se corrigió al revisar los caminos de error de la Fase 5.
 */
const handleSmartProductCreated = (product) => {
    void product;
    router.get(route('pos.index'), props.filters, { preserveState: true, only: ['products'] });
};

/** The cashier closed a sale: record it so the daily indicators stay honest. */
const notifySaleClosed = () => {
    if (!smartSession.value?.active) return;
    const saleId = lastCheckoutSaleId.value ?? page.props.flash?.print_data?.id ?? null;
    lastCheckoutSaleId.value = null;
    void smartSession.value.onSaleClosed(saleId);
};

// Leaving the POS stops the engine and flushes the pending metrics.
onBeforeUnmount(() => {
    void smartSession.value?.deactivate();
});

const openScalePreferences = () => {
    isScalePreferencesVisible.value = true;
};

/** The business preferences live in the backend: reload only those props. */
const handlePreferencesSaved = (payload = {}) => {
    scaleProducts.value = [];
    router.reload({
        only: ['scale', 'smartCheckout'],
        onSuccess: () => {
            if (payload.configure && canUseScale.value) openScaleSettings();
        },
    });
};

const localCustomers = ref([...props.customers]);
const handleSelectCustomer = (customer) => selectedClient.value = customer;
watch(() => props.customers, (newCustomers) => { localCustomers.value = [...newCustomers]; });
const handleCustomerCreated = (newCustomer) => {
    localCustomers.value.push(newCustomer);
    selectedClient.value = newCustomer;
    toast.add({ severity: 'success', summary: 'Cliente creado', detail: 'El nuevo cliente ha sido seleccionado.', life: 3000 });
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
    toast.add({ severity: 'success', summary: 'Carrito guardado', detail: 'El carrito actual se movió a la lista de espera.', life: 3000 });
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
    toast.add({ severity: 'warn', summary: 'Carrito descartado', detail: 'Se ha eliminado un carrito de la lista de espera.', life: 3000 });
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
    notes: null,
    guest_name: null
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
        toast.add({ severity: 'error', summary: 'Caja cerrada', detail: 'Debes tener una sesión de caja activa para registrar pedidos.', life: 5000 });
        return;
    }

    // Subtotal with the list price of every line and the discount of the cart:
    // the same rule ShoppingCart uses for a sale, so the three buttons charge
    // the same (the server recalculates it anyway).
    const currentSubtotal = cartSubtotal.value;
    const itemsDiscountTotal = cartSubtotal.value - currentCartTotal.value;

    form.reset();
    form.cartItems = mapCartItems();
    form.customerId = selectedClient.value ? selectedClient.value.id : null;
    form.subtotal = currentSubtotal;
    form.total_discount = itemsDiscountTotal; 
    
    form.is_order = true;
    form.contact_info = { 
        name: orderData.contact_name, 
        phone: orderData.contact_phone,
        type: posMode.value === 'comandas' ? 'comanda' : 'pedido'
    };
    form.delivery_date = orderData.delivery_date;
    form.shipping_address = orderData.shipping_address;
    form.shipping_cost = orderData.shipping_cost;
    form.notes = orderData.notes;
    form.cash_register_session_id = props.activeSession.id;
    form.total = currentSubtotal - itemsDiscountTotal + parseFloat(orderData.shipping_cost);

    form.post(route('pos.store-order'), { 
        onSuccess: () => {
            clearCart();
            isOrderModalVisible.value = false;
            toast.add({ severity: 'success', summary: 'Pedido creado', detail: 'El pedido ha sido registrado correctamente.', life: 3000 });
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
        toast.add({ severity: 'error', summary: 'Caja cerrada', detail: 'Debes tener una sesión de caja activa para registrar una venta.', life: 5000 });
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
    form.guest_name = checkoutData.guest_name || null;

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
            // Smart checkout: close the session of the sale that was just registered.
            if (transactionType === 'contado' || transactionType === 'credito') {
                notifySaleClosed();
            }
            router.reload({ only: ['products'], preserveState: true });
        },
        onError: (errors) => {
            const errorMessage = errors.default || errors.message || Object.values(errors).flat().join(' ');
            toast.add({ severity: 'error', summary: 'Error al procesar', detail: errorMessage, life: 7000 });
        }
    });
};

const currentCartTotal = computed(() => {
    return cartItems.value.reduce((acc, item) => acc + (item.price * item.quantity), 0);
});

// Subtotal with list prices (what the cart and the server call `subtotal`).
const cartSubtotal = computed(() => {
    return cartItems.value.reduce((acc, item) => acc + ((item.original_price ?? item.price) * item.quantity), 0);
});
</script>

<template>
    <Head title="Punto de venta" />
    <AppLayout>
        <div class="relative h-[calc(100vh-100px)]">
            <template v-if="activeSession">

                <!-- Indicadores del Modo Caja Inteligente (solo con el modo activo) -->
                <component v-if="smartSession?.active && SmartCheckoutIndicators" :is="SmartCheckoutIndicators"
                    :session="smartSession" :scale-enabled="canUseScale" />

                <!-- PANEL IZQUIERDO DE PRODUCTOS (Ocupa toda la pantalla en móvil) -->
                <PosLeftPanel :products="products" :categories="categories" :pending-carts="pendingCarts"
                    :filters="filters" :active-session="activeSession" :cart-items="cartItems"
                    :pos-mode="posMode" :has-online-store="hasOnlineStore" :scale="scale"
                    :smart-checkout="{ enabled: smartCheckoutEnabled, active: Boolean(smartSession?.active) }"
                    @add-to-cart="handleAddToCart"
                    @resume-cart="resumePendingCart" @delete-cart="deletePendingCart"
                    @product-created-and-add-to-cart="handleProductCreatedAndAddToCart"
                    @refresh-session-data="handleRefreshSessionData" @open-history-modal="isHistoryModalVisible = true"
                    @open-close-session-modal="isCloseSessionModalVisible = true" 
                    @open-scale="openScalePanel()"
                    @open-preferences="openScalePreferences"
                    @toggle-smart-checkout="toggleSmartCheckout"
                    @update:posMode="posMode = $event" class="h-full" />

                <!-- BOTÓN FLOTANTE DEL CARRITO (FAB) -->
                <div class="fixed bottom-6 right-6 z-40">
                    <button @click="isCartDrawerVisible = true" 
                        class="w-16 h-16 rounded-full bg-gray-900 dark:bg-[#232323] text-white flex items-center justify-center shadow-2xl border border-gray-700 dark:border-[#3a3a3a] transition-transform active:scale-95 group relative">
                        <i class="pi pi-shopping-cart text-2xl group-hover:text-primary-500 transition-colors"></i>
                        
                        <!-- Badge de cantidad -->
                        <span v-if="cartItemCount > 0" 
                            class="absolute top-0 right-0 -mt-1 -mr-1 bg-primary-500 text-white text-[10px] font-bold w-6 h-6 flex items-center justify-center rounded-full border-2 border-gray-900 dark:border-[#232323] shadow-sm animate-in zoom-in">
                            {{ cartItemCount }}
                        </span>
                    </button>
                </div>

                <!-- PANEL LATERAL DEL CARRITO (DRAWER) -->
                <Drawer v-model:visible="isCartDrawerVisible" position="right" class="!w-full md:!w-[450px]"
                    :pt="{
                        root: { class: 'dark:bg-[#232323] border-none shadow-2xl' },
                        header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-4' },
                        title: { class: 'text-xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
                        content: { class: 'dark:bg-[#232323] p-0' } // Eliminado el padding nativo para que el ShoppingCart llene el espacio
                    }">
                    <template #header>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">Resumen de venta</h2>
                    </template>
                    
                    <ShoppingCart 
                        :items="cartItems" 
                        :client="selectedClient" 
                        :customers="localCustomers"
                        :default-customer="defaultCustomer" 
                        :active-promotions="activePromotions"
                        :loading="form.processing"
                        :payment-modal-visible="isPaymentModalVisible"
                        :pos-mode="posMode"
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
                <!-- ESTADO VACÍO (ESTILO PANTALLA DE SISTEMA / TELEMETRÍA) -->
                <div class="flex items-center justify-center min-h-[80vh] px-4">
                    <div class="bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl p-8 max-w-md w-full text-center shadow-2xl relative overflow-hidden group">
                        
                        <!-- Glow effect interactivo -->
                        <div class="absolute -top-32 -left-32 w-72 h-72 bg-primary-500/10 rounded-full blur-3xl group-hover:bg-primary-500/20 transition-all duration-700 pointer-events-none"></div>

                        <div class="relative z-10">
                            <div class="w-16 h-16 bg-gray-50 dark:bg-[#1a1a1a] rounded-full flex items-center justify-center mx-auto mb-6 border border-gray-100 dark:border-[#3a3a3a] shadow-inner">
                                <i class="pi" :class="(joinableSessions?.length || availableCashRegisters?.length) ? 'pi-desktop text-primary-500' : 'pi-lock text-gray-400'" style="font-size: 1.5rem;"></i>
                            </div>

                            <h2 class="text-2xl font-light text-gray-900 dark:text-white tracking-tight mb-3 m-0">
                                {{ (joinableSessions?.length || availableCashRegisters?.length) ? 'Terminal en espera' : 'Terminal bloqueada' }}
                            </h2>

                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-8 mx-auto leading-relaxed m-0">
                                <span v-if="joinableSessions?.length || availableCashRegisters?.length">
                                    El sistema requiere inicializar una sesión de caja para comenzar a procesar transacciones.
                                </span>
                                <span v-else>
                                    No hay cajas disponibles para operar en esta sucursal. Contacta al administrador de la red.
                                </span>
                            </p>

                            <div class="flex flex-col gap-3 justify-center">
                                <Button v-if="joinableSessions?.length"
                                    @click="isJoinSessionModalVisible = true" 
                                    label="Vincular a sesión" 
                                    icon="pi pi-link"
                                    class="w-full !rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold py-3" />

                                <Button v-if="availableCashRegisters?.length"
                                    @click="isStartSessionModalVisible = true" 
                                    label="Inicializar caja"
                                    icon="pi pi-power-off" 
                                    severity="contrast"
                                    class="w-full !rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold py-3" />
                            </div>

                            <div v-if="!joinableSessions?.length && !availableCashRegisters?.length"
                                class="mt-6 pt-5 border-t border-gray-100 dark:border-[#3a3a3a]">
                                <Button @click="$inertia.visit(route('cash-registers.create'))" 
                                    label="Configurar caja"
                                    icon="pi pi-cog" 
                                    text 
                                    severity="secondary"
                                    class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                            </div>
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
        
        <OrderFormModal 
            v-model:visible="isOrderModalVisible"
            :cart-total="currentCartTotal"
            :client="selectedClient"
            :loading="form.processing"
            @submit="handleOrderSubmit"
        />

        <!-- Báscula: solo se monta (y solo entonces se descarga su código) si el negocio la tiene activa -->
        <component v-if="canUseScale" :is="ScalePanel" v-model:visible="isScalePanelVisible" :scale="scale"
            :product="scaleProduct" :products="scaleProducts" :loading-products="scaleProductsLoading"
            :user-id="page.props.auth?.user?.id" :branch-id="page.props.auth?.current_branch?.id"
            @add="handleScaleAdd" @open-settings="openScaleSettings" @search="loadWeighableProducts" />
        <component v-if="canUseScale && canConfigureScale" :is="ScaleSettingsModal" v-model:visible="isScaleSettingsVisible"
            :can-configure="canConfigureScale" />
        <component v-if="canConfigureScale" :is="ScalePreferencesModal" v-model:visible="isScalePreferencesVisible"
            :scale="scale" @saved="handlePreferencesSaved" />

        <!-- Modo Caja Inteligente: el panel solo se monta con el modo activo -->
        <component v-if="smartSession?.active && SmartCheckoutPanel" :is="SmartCheckoutPanel"
            :session="smartSession" :smart-checkout="smartCheckout" :products="products?.data ?? []"
            :scale-enabled="canUseScale" :user-id="page.props.auth?.user?.id"
            :branch-id="page.props.auth?.current_branch?.id"
            @close="toggleSmartCheckout" @product-created="handleSmartProductCreated" />

        <VirtualNumpad />
    </AppLayout>
</template>