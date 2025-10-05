<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PaymentModal from '@/Components/PaymentModal.vue';

const props = defineProps({
    visible: Boolean,
    customer: Object,
});

const emit = defineEmits(['update:visible']);

// Se añade 'cash_register_session_id' al formulario
const form = useForm({
    payments: [],
    cash_register_session_id: null,
});

const isPaymentModalVisible = ref(false);
const paymentAmount = ref(0);

const openPaymentModal = () => {
    paymentAmount.value = props.customer.balance < 0 ? Math.abs(props.customer.balance) : 100;
    isPaymentModalVisible.value = true;
};

const handlePaymentSubmit = (paymentData) => {
    // Se asignan ambos valores recibidos desde PaymentModal
    form.payments = paymentData.payments;
    form.cash_register_session_id = paymentData.cash_register_session_id;

    // Se apunta a la nueva ruta estandarizada
    form.post(route('customers.payments.store', props.customer.id), {
        onSuccess: () => {
            closeAllModals();
        },
        preserveScroll: true,
    });
};

const closeAllModals = () => {
    isPaymentModalVisible.value = false;
    emit('update:visible', false);
};
</script>

<template>
    <div>
        <Dialog :visible="visible" @update:visible="$emit('update:visible', false)" modal header="Abonar / Agregar Saldo" :style="{ width: '28rem' }">
            <div class="p-4 text-center">
                <i class="pi pi-dollar !text-4xl text-green-500 mb-4"></i>
                <h3 class="text-xl font-bold mb-2">Registrar pago para {{ customer.name }}</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Puedes abonar a una deuda existente o agregar saldo a favor para futuras compras.
                </p>
            </div>

            <template #footer>
                <Button label="Cancelar" text severity="secondary" @click="$emit('update:visible', false)" />
                <Button label="Continuar al Pago" icon="pi pi-arrow-right" @click="openPaymentModal" :loading="form.processing" />
            </template>
        </Dialog>
        
        <PaymentModal 
            v-if="isPaymentModalVisible"
            v-model:visible="isPaymentModalVisible"
            :total-amount="paymentAmount"
            :client="customer"
            payment-mode="flexible"
            @submit="handlePaymentSubmit"
        />
    </div>
</template>