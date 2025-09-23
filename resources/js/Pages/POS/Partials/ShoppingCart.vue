<script setup>
import { ref, computed, watch } from 'vue';
import { useConfirm } from "primevue/useconfirm";
import CartItem from './CartItem.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';

// PrimeVue components
import Button from 'primevue/button';
import Avatar from 'primevue/avatar';
import Dropdown from 'primevue/dropdown';
import ConfirmPopup from 'primevue/confirmpopup';
import Checkbox from 'primevue/checkbox';
import Divider from 'primevue/divider';


const props = defineProps({
    items: Array,
    client: Object,
    customers: Array,
    defaultCustomer: Object,
});

const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem', 'clearCart', 'selectCustomer', 'customerCreated', 'saveCart', 'checkout']);

const confirm = useConfirm();
const requireConfirmation = (event) => {
    confirm.require({
        target: event.currentTarget, group: 'cart-actions',
        message: '¿Estás seguro de que quieres limpiar el carrito?',
        icon: 'pi pi-exclamation-triangle', acceptLabel: 'Sí, limpiar', rejectLabel: 'Cancelar',
        accept: () => emit('clearCart'),
    });
};

const isCreateCustomerModalVisible = ref(false);
const handleCustomerSelect = (event) => emit('selectCustomer', event.value);
const clearCustomer = () => emit('selectCustomer', null);
const displayedCustomer = computed(() => props.client || props.defaultCustomer);
const handleCustomerCreated = (newCustomer) => emit('customerCreated', newCustomer);

const subtotal = computed(() => props.items.reduce((total, item) => total + (item.price * item.quantity), 0));
const discount = ref(0);
const total = computed(() => subtotal.value - discount.value);
const isPaymentModalVisible = ref(false);

const useBalance = ref(false);
const amountFromBalance = computed(() => {
    if (props.client && useBalance.value && props.client.balance > 0) {
        return Math.min(total.value, props.client.balance);
    }
    return 0;
});

const finalTotalToPay = computed(() => total.value - amountFromBalance.value);

watch(() => props.client, () => {
    useBalance.value = false;
});

const handlePaymentSubmit = (paymentData) => {
    isPaymentModalVisible.value = false;
    emit('checkout', {
        ...paymentData,
        subtotal: subtotal.value,
        total: total.value,
        discount: discount.value,
        use_balance: useBalance.value,
    });
};

</script>

<template>
    <div>
        <ConfirmPopup group="cart-actions"></ConfirmPopup>
        <div class="bg-white p-6 rounded-lg shadow-md h-full flex flex-col dark:bg-gray-800">
            <!-- Header -->
            <div class="flex justify-between items-center pb-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">Carrito</h2>
                <div class="flex items-center gap-2">
                    <Button @click="$emit('saveCart')" :disabled="items.length === 0" icon="pi pi-save" rounded text severity="secondary" v-tooltip.bottom="'Guardar para después'"/>
                    <Button @click="requireConfirmation($event)" :disabled="items.length === 0" icon="pi pi-trash" rounded text severity="danger" v-tooltip.bottom="'Limpiar carrito'"/>
                </div>
            </div>

            <!-- Selector de Cliente -->
            <div class="py-4">
                <div class="flex items-center gap-2 mb-3">
                    <Dropdown :modelValue="client" @change="handleCustomerSelect" :options="customers" optionLabel="name" placeholder="Seleccionar cliente" filter class="w-full"/>
                    <Button @click="isCreateCustomerModalVisible = true" icon="pi pi-plus" severity="secondary"/>
                </div>
                <div v-if="displayedCustomer" class="flex items-center justify-between bg-gray-50 dark:bg-gray-700 p-2 rounded-lg">
                    <div class="flex items-center gap-3">
                        <Avatar :label="displayedCustomer.name.substring(0, 1)" shape="circle" class="bg-blue-100 text-blue-600"/>
                        <div>
                            <p class="font-semibold text-sm text-gray-800 dark:text-gray-200">{{ displayedCustomer.name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ displayedCustomer.phone }}</p>
                        </div>
                    </div>
                    <Button v-if="client" @click="clearCustomer" icon="pi pi-times" rounded text severity="secondary" size="small"/>
                </div>
            </div>

            <!-- Info de Saldo y Crédito del Cliente -->
            <div v-if="client" class="py-4 border-t border-b border-gray-200 dark:border-gray-700 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600 dark:text-gray-300">Balance:</span>
                    <span :class="client.balance >= 0 ? 'text-green-600' : 'text-red-600'" class="font-bold">
                        ${{ client.balance.toFixed(2) }} {{ client.balance > 0 ? '(a favor)' : '' }}
                    </span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600 dark:text-gray-300">Crédito Disponible:</span>
                    <span class="font-bold text-blue-600">${{ client.available_credit.toFixed(2) }}</span>
                </div>
                 <div v-if="client.balance > 0" class="flex items-center pt-2">
                    <Checkbox v-model="useBalance" inputId="useBalance" :binary="true" />
                    <label for="useBalance" class="ml-2 text-sm text-gray-800 dark:text-gray-200">Usar saldo a favor en esta compra</label>
                </div>
            </div>


            <!-- Lista de Items -->
            <div class="flex-grow py-4 overflow-y-auto space-y-4">
                <p v-if="items.length === 0" class="text-gray-500 dark:text-gray-400 text-center mt-8">El carrito está vacío</p>
                <CartItem v-for="item in items" :key="item.cartItemId" :item="item" @update-quantity="$emit('updateQuantity', $event)" @update-price="$emit('updatePrice', $event)" @remove-item="$emit('removeItem', $event)"/>
            </div>

            <!-- Detalles del Pago -->
            <div class="pt-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
                <div class="flex justify-between items-center text-gray-600 dark:text-gray-300"><span>Subtotal</span><span class="font-medium">${{ subtotal.toFixed(2) }}</span></div>
                <div class="flex justify-between items-center text-red-500"><span>Descuento</span><span class="font-medium">-${{ discount.toFixed(2) }}</span></div>
                <div v-if="amountFromBalance > 0" class="flex justify-between items-center text-green-600"><span>Saldo Utilizado</span><span class="font-medium">-${{ amountFromBalance.toFixed(2) }}</span></div>
                <Divider v-if="amountFromBalance > 0" />
                <div class="flex justify-between items-center font-bold text-lg text-gray-800 dark:text-gray-100"><span>Total</span><span>${{ finalTotalToPay.toFixed(2) }}</span></div>
                
                <Button @click="isPaymentModalVisible = true" :disabled="items.length === 0" :label="client && finalTotalToPay <= 0.01 ? 'Finalizar (a Crédito)' : 'Pagar'" icon="pi pi-arrow-right" iconPos="right" class="w-full mt-2 bg-orange-500 hover:bg-orange-600 border-none"/>
            </div>
        </div>
        
        <CreateCustomerModal v-model:visible="isCreateCustomerModalVisible" @created="handleCustomerCreated"/>
        <!-- CAMBIO: Se pasa el 'client' como prop -->
        <PaymentModal 
            v-model:visible="isPaymentModalVisible" 
            :total-amount="finalTotalToPay"
            :client="client"
            @submit="handlePaymentSubmit"
        />
    </div>
</template>