<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    transaction: Object,
    pendingAmount: Number,
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    amount: 0,
    payment_method: 'efectivo',
    payment_date: new Date(),
    status: 'completado',
    notes: '',
});

// SOLUCIÓN 1: Usar un watcher para actualizar el monto por defecto cuando el modal se abre
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        form.amount = props.pendingAmount;
    }
});

const paymentMethods = ref([
    { label: 'Efectivo', value: 'efectivo' },
    { label: 'Tarjeta', value: 'tarjeta' },
    { label: 'Transferencia', value: 'transferencia' },
]);

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    // Usar form.post de Inertia para que la página se actualice automáticamente
    form.post(route('transactions.payments.store', props.transaction.id), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Registrar Abono a Venta" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="amount" value="Monto a abonar *" />
                <InputNumber id="amount" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" :max="pendingAmount" />
                <InputError :message="form.errors.amount" class="mt-2" />
            </div>
            <div>
                <InputLabel for="payment_method" value="Método de Pago *" />
                <Select id="payment_method" v-model="form.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" class="w-full mt-1" />
            </div>
             <div>
                <InputLabel for="payment_date" value="Fecha del Abono *" />
                <DatePicker id="payment_date" v-model="form.payment_date" showTime hourFormat="12" class="w-full mt-1" />
             </div>
            <div>
                <InputLabel for="notes" value="Notas (Opcional)" />
                <Textarea id="notes" v-model="form.notes" rows="2" class="w-full mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Registrar Abono" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>

