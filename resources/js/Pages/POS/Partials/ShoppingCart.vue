<script setup>
import { ref, computed, watch } from 'vue';
import { useConfirm } from "primevue/useconfirm";
import CartItem from './CartItem.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import axios from 'axios'; 

const props = defineProps({
    items: Array,
    client: Object,
    customers: Array, 
    defaultCustomer: Object,
    activePromotions: Array,
    loading: { type: Boolean, default: false },
    paymentModalVisible: { type: Boolean, default: false },
    posMode: { type: String, default: 'retail' }
});

const emit = defineEmits([
    'updateQuantity', 'updatePrice', 'removeItem', 'clearCart', 
    'selectCustomer', 'customerCreated', 'saveCart', 'checkout', 
    'open-payment-modal', 'close-payment-modal',
    'open-order-modal'
]);

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
const isModeHelpVisible = ref(false); 
const customerInfoPopover = ref(); // <--- Referencia para el Popover del cliente

const toggleCustomerInfo = (event) => {
    customerInfoPopover.value.toggle(event);
};

// --- Lógica de Modo Comandas ---
const guestName = ref('');
watch(() => props.items, (newItems) => {
    if (newItems.length === 0) {
        guestName.value = ''; 
    }
});

// --- Lógica de AutoComplete ---
const selectedCustomerModel = ref(props.client);
const filteredCustomers = ref([]); 
const isSearchingCustomer = ref(false);

watch(() => props.client, (newVal) => {
    selectedCustomerModel.value = newVal;
}, { immediate: true });

const searchCustomer = async (event) => {
    const query = event.query;
    isSearchingCustomer.value = true;
    try {
        const response = await axios.get(route('pos.customers.search'), { params: { query } });
        filteredCustomers.value = response.data;
    } catch (error) {
        console.error("Error buscando clientes:", error);
        filteredCustomers.value = [];
    } finally {
        isSearchingCustomer.value = false;
    }
};

const onCustomerSelect = (event) => {
    emit('selectCustomer', event.value);
};

const clearCustomer = () => {
    selectedCustomerModel.value = null;
    emit('selectCustomer', null);
};

const displayedCustomer = computed(() => props.client || props.defaultCustomer);

const handleCustomerCreated = (newCustomer) => {
    emit('customerCreated', newCustomer);
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
        guest_name: guestName.value 
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};
</script>

<template>
    <div class="h-full">
        <ConfirmPopup group="cart-actions" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl' } }"></ConfirmPopup>
        
        <div class="bg-white dark:bg-[#232323] p-4 lg:px-5 lg:py-4 rounded-3xl shadow-sm border border-gray-100 dark:border-[#3a3a3a] h-full flex flex-col transition-colors">
            
            <!-- HEADER DEL CARRITO (Más compacto) -->
            <div class="flex justify-between items-center pb-3 mb-2 border-b border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center text-primary-500">
                        <i class="pi" :class="posMode === 'retail' ? 'pi-shopping-cart !text-sm' : 'pi-receipt !text-sm'"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                            {{ posMode === 'retail' ? 'Carrito' : 'Comanda' }}
                        </h2>
                        <p class="text-[8px] text-gray-400 uppercase tracking-widest m-0 flex items-center gap-1 cursor-pointer hover:text-primary-500 transition-colors" @click="isModeHelpVisible = true">
                            Modo activo <i class="pi pi-question-circle !text-[8px]"></i>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button @click="$emit('saveCart', { total: total })" :disabled="items.length === 0"
                        icon="pi pi-bookmark" rounded severity="secondary"
                        v-tooltip.bottom="'Poner en espera'"
                        class="!bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500 !w-8 !h-8" />
                    <Button @click="requireConfirmation($event)" :disabled="items.length === 0" 
                        icon="pi pi-trash" rounded severity="danger" 
                        v-tooltip.bottom="'Limpiar carrito'"
                        class="!bg-red-50 dark:!bg-red-900/20 !border-red-200 dark:!border-red-900/50 !text-red-500 hover:!bg-red-500 hover:!text-white !w-8 !h-8" />
                </div>
            </div>

            <!-- SELECTOR DE CLIENTE O COMANDA -->
            <div class="mb-3">
                <template v-if="posMode === 'retail'">
                    
                    <!-- Búsqueda si no hay cliente seleccionado -->
                    <div v-if="!client" class="flex items-center gap-2 mb-1">
                        <IconField iconPosition="left" class="w-full">
                            <InputIcon v-if="!isSearchingCustomer" class="pi pi-search text-gray-400"></InputIcon>
                            <InputIcon v-else class="pi pi-spin pi-spinner text-primary-500 font-bold"></InputIcon>
                            
                            <AutoComplete v-model="selectedCustomerModel" :suggestions="filteredCustomers"
                                @complete="searchCustomer" @item-select="onCustomerSelect" optionLabel="name" forceSelection
                                placeholder="Buscar cliente..." class="w-full" :delay="400"
                                emptyMessage="No se encontraron clientes" fluid
                                :pt="{
                                    input: { root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !pl-10 !text-sm' } },
                                    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
                                }">
                                <template #option="slotProps">
                                    <div class="flex flex-col py-1">
                                        <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ slotProps.option.name }}</span>
                                        <span class="text-[10px] text-gray-500 font-mono mt-0.5">{{ slotProps.option.phone }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                        </IconField>

                        <Button @click="isCreateCustomerModalVisible = true" rounded icon="pi pi-plus" 
                            severity="secondary" v-tooltip.bottom="'Nuevo cliente'" size="small"
                            class="!bg-gray-900 dark:!bg-white !text-white dark:!text-gray-900 !border-none" />
                    </div>

                    <!-- Cliente Seleccionado: Tarjeta Ultra Compacta -->
                    <div v-if="displayedCustomer && client" class="bg-purple-50 dark:bg-purple-900/10 p-2 rounded-2xl border border-purple-100 dark:border-purple-900/30 flex items-center justify-between group">
                        <div class="flex items-center gap-2 overflow-hidden">
                            <Avatar :label="displayedCustomer.name.substring(0, 1)" shape="circle"
                                class="!bg-purple-200 dark:!bg-purple-800 !text-purple-700 dark:!text-purple-300 font-bold !w-7 !h-7 !text-xs flex-shrink-0" />
                            <span class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate m-0">{{ displayedCustomer.name }}</span>
                        </div>
                        
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <!-- Botón para ver información detallada (Popover) -->
                            <button @click="toggleCustomerInfo" class="text-[10px] font-bold uppercase tracking-widest text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900/40 px-2 py-1.5 rounded-xl hover:bg-purple-200 dark:hover:bg-purple-900/60 transition-colors flex items-center gap-1">
                                Info <i class="pi pi-angle-down !text-[8px]"></i>
                            </button>
                            <!-- Botón para remover cliente -->
                            <button @click="clearCustomer" class="w-7 h-7 rounded-full bg-white dark:bg-[#232323] text-gray-400 hover:text-red-500 border border-transparent group-hover:border-gray-200 dark:group-hover:border-[#3a3a3a] flex items-center justify-center transition-colors">
                                <i class="pi pi-times !text-[10px]"></i>
                            </button>
                        </div>

                        <!-- Popover con Detalles Completos del Cliente -->
                        <Popover ref="customerInfoPopover" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl shadow-xl' } }">
                            <div class="p-4 w-64">
                                <div class="mb-3 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">
                                    <p class="font-medium text-base text-gray-900 dark:text-white m-0">{{ displayedCustomer.name }}</p>
                                    <p class="text-xs text-gray-500 font-mono m-0 mt-0.5"><i class="pi pi-phone !text-[10px] mr-1"></i>{{ displayedCustomer.phone }}</p>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex justify-between items-end">
                                        <span class="text-[10px] uppercase tracking-widest text-gray-500">Saldo a favor</span>
                                        <span class="font-light tracking-tight text-xl leading-none" :class="(client.balance || 0) >= 0 ? 'text-green-500' : 'text-red-500'">
                                            {{ formatCurrency(client.balance || 0) }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-end">
                                        <span class="text-[10px] uppercase tracking-widest text-gray-500">Crédito disp.</span>
                                        <span class="font-light tracking-tight text-xl leading-none text-blue-500">
                                            {{ formatCurrency(client.available_credit || 0) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-t border-gray-100 dark:border-[#3a3a3a]">
                                    <a :href="route('customers.show', client.id)" target="_blank" rel="noopener noreferrer"
                                        class="text-[10px] text-primary-500 uppercase tracking-widest font-bold flex items-center gap-1 hover:underline w-fit">
                                        Ver perfil completo <i class="pi pi-arrow-up-right !text-[8px]"></i>
                                    </a>
                                </div>
                            </div>
                        </Popover>
                    </div>
                </template>

                <template v-else>
                    <!-- MODO COMANDAS -->
                    <div v-if="!guestName" class="mb-1 mt-1">
                        <IconField iconPosition="left" class="w-full">
                            <InputIcon class="pi pi-tag text-gray-400"></InputIcon>
                            <InputText v-model="guestName" placeholder="Identificador (Ej. Mesa 3)..." 
                                class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !pl-10 !text-sm" />
                        </IconField>
                    </div>
                    <!-- Comanda Seleccionada: Tarjeta Ultra Compacta -->
                    <div v-if="guestName" class="bg-orange-50 dark:bg-orange-900/10 p-2 rounded-2xl border border-orange-100 dark:border-orange-900/30 flex justify-between items-center group">
                        <div class="flex items-center gap-2 overflow-hidden">
                            <div class="w-7 h-7 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-700 dark:text-orange-300 flex items-center justify-center flex-shrink-0">
                                <i class="pi pi-receipt !text-xs"></i>
                            </div>
                            <span class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate m-0">{{ guestName }}</span>
                        </div>
                        <button @click="guestName = ''" class="w-7 h-7 flex-shrink-0 rounded-full bg-white dark:bg-[#232323] text-gray-400 hover:text-red-500 border border-transparent group-hover:border-gray-200 dark:group-hover:border-[#3a3a3a] flex items-center justify-center transition-colors">
                            <i class="pi pi-times !text-[10px]"></i>
                        </button>
                    </div>
                </template>
            </div>

            <!-- LISTA DE ITEMS (Ahora toma más espacio visual) -->
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest m-0">Artículos en orden</span>
                <Badge v-if="items.length > 0" :value="items.length" class="!bg-gray-200 dark:!bg-[#3a3a3a] !text-gray-700 dark:!text-gray-300 !text-[10px]"></Badge>
            </div>
            
            <div class="flex-grow overflow-y-auto custom-scrollbar space-y-2 pr-1 -mr-1">
                <div v-if="items.length === 0" class="flex flex-col items-center justify-center h-full text-center py-10 opacity-50">
                    <i class="pi pi-shopping-cart !text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Tu orden está vacía.</p>
                </div>
                <!-- El componente CartItem mantendrá su propia lógica, pero vivirá dentro de este contenedor estilizado -->
                <CartItem v-for="item in items" :key="item.cartItemId" :item="item"
                    :applied-cart-promo-names="appliedCartPromoNames" @update-quantity="$emit('updateQuantity', $event)"
                    @update-price="$emit('updatePrice', $event)" @remove-item="$emit('removeItem', $event)" />
            </div>

            <!-- ZONA DE TOTALES Y PAGO -->
            <div class="mt-3 pt-3 border-t border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-1">
                
                <div class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest text-gray-500">Subtotal</span>
                    <span class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ formatCurrency(subtotal) }}</span>
                </div>
                
                <div v-if="totalDiscount > 0" class="flex flex-col gap-1">
                    <div class="flex justify-between items-center text-red-500">
                        <span class="text-[10px] uppercase tracking-widest font-bold">Descuentos</span>
                        <span class="font-mono text-sm font-bold">-{{ formatCurrency(totalDiscount) }}</span>
                    </div>
                    <div v-for="promo in cartLevelDiscounts" :key="promo.name" class="flex justify-between items-center pl-2 text-red-400/80">
                        <span class="text-[9px]"><i class="pi pi-tag !text-[8px] mr-1"></i>{{ promo.name }}</span>
                        <span class="font-mono text-[10px]">-{{ formatCurrency(promo.amount) }}</span>
                    </div>
                </div>
                
                <div v-else-if="totalDiscount < 0" class="flex justify-between items-center text-orange-500">
                    <span class="text-[10px] uppercase tracking-widest font-bold">Ajuste manual</span>
                    <span class="font-mono text-sm font-bold">+{{ formatCurrency(-totalDiscount) }}</span>
                </div>

                <div class="flex justify-between items-end mt-1 pt-2 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <span class="text-xs uppercase tracking-widest font-bold text-gray-400 mb-1">Total</span>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-none">
                        {{ formatCurrency(total) }}
                    </span>
                </div>
                
                <!-- BOTONES DE ACCIÓN -->
                <div class="flex gap-2 mt-4">
                    <Button 
                        label="Pedido" 
                        icon="pi pi-truck" 
                        severity="secondary" 
                        class="flex-1 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-700 dark:!text-gray-300 hover:!border-primary-500 transition-colors !text-[11px] !uppercase !tracking-widest !font-bold !py-3"
                        :disabled="items.length === 0"
                        @click="$emit('open-order-modal')"
                    />
                    
                    <Button 
                        @click="$emit('open-payment-modal')" 
                        :disabled="items.length === 0"
                        :label="(client && total <= 0 && client.balance >= total) || total === 0 ? 'Finalizar' : 'Cobrar'"
                        icon="pi pi-arrow-right" iconPos="right" 
                        class="flex-[2] !rounded-2xl !bg-primary-500 !border-none hover:!bg-primary-400 !text-white !text-sm !uppercase !tracking-widest !font-bold !py-3 shadow-[0_4px_14px_rgba(246,140,15,0.4)]" 
                    />
                </div>
            </div>
        </div>

        <!-- MODALES EXTRAS -->
        <CreateCustomerModal v-model:visible="isCreateCustomerModalVisible" @created="handleCustomerCreated" />
        <PaymentModal :visible="props.paymentModalVisible" @update:visible="$emit('close-payment-modal')"
            :total-amount="total" :client="client" :customers="customers" :allow-credit="true" :allow-layaway="true"
            :loading="props.loading" payment-mode="strict" @update:client="$emit('selectCustomer', $event)"
            @customer-created="handleCustomerCreated" @submit="handlePaymentSubmit" />

        <!-- MODAL DE AYUDA DE MODOS -->
        <Dialog v-model:visible="isModeHelpVisible" modal header="Modos de operación" 
            class="w-full max-w-2xl"
            :breakpoints="{ '960px': '75vw', '640px': '90vw' }"
            :pt="{
                root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
                header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
                title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
                content: { class: 'dark:bg-[#232323] px-8 py-6' },
                footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-8 py-4' }
            }">
            
            <div class="space-y-4">
                <!-- Tarjeta Retail -->
                <div class="p-5 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] group hover:border-purple-500/50 transition-colors">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-full bg-white dark:bg-[#232323] text-purple-500 flex items-center justify-center shadow-sm">
                            <i class="pi pi-shop text-lg"></i>
                        </div>
                        <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Modo tienda (Retail)</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mb-3 leading-relaxed">Configuración robusta ideal para tiendas físicas, boutiques o inventarios controlados.</p>
                    <ul class="text-[11px] uppercase tracking-wide space-y-2 text-gray-600 dark:text-gray-400 m-0 p-0 list-none">
                        <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Búsqueda de perfiles de clientes</li>
                        <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Uso de saldos a favor y créditos</li>
                        <li class="flex items-center gap-2"><i class="pi pi-check-circle text-purple-500"></i> Sistema de apartados activo</li>
                    </ul>
                </div>

                <!-- Tarjeta Comandas -->
                <div class="p-5 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] group hover:border-orange-500/50 transition-colors">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-full bg-white dark:bg-[#232323] text-orange-500 flex items-center justify-center shadow-sm">
                            <i class="pi pi-receipt text-lg"></i>
                        </div>
                        <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Modo comandas (Fast Food)</h3>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mb-3 leading-relaxed">Flujo de alta velocidad diseñado para comida rápida, cafeterías y servicios al paso.</p>
                    <ul class="text-[11px] uppercase tracking-wide space-y-2 text-gray-600 dark:text-gray-400 m-0 p-0 list-none">
                        <li class="flex items-center gap-2"><i class="pi pi-check-circle text-orange-500"></i> Sin registro obligatorio de datos</li>
                        <li class="flex items-center gap-2"><i class="pi pi-check-circle text-orange-500"></i> Uso de identificadores rápidos (Ej. "Mesa 3")</li>
                        <li class="flex items-center gap-2"><i class="pi pi-check-circle text-orange-500"></i> Impresión directa del identificador</li>
                    </ul>
                </div>

                <div class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/10 rounded-2xl mt-6 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-info-circle text-blue-500 text-lg mt-0.5"></i>
                    <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                        Puedes alternar entre estos modos al instante utilizando el botón de la parte superior del panel de productos.
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end">
                    <Button label="Entendido" icon="pi pi-check" @click="isModeHelpVisible = false" autofocus class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-8" />
                </div>
            </template>
        </Dialog>
    </div>
</template>