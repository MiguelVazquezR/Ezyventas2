<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    transactionId: {
        type: Number,
        required: true
    },
    payment: {
        type: Object,
        default: null
    },
    bankAccounts: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:visible', 'success']);

const isVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value)
});

const editForm = useForm({
    amount: 0,
    payment_method: '',
    bank_account_id: null,
    notes: ''
});

const paymentMethods = [
    { label: 'Efectivo', value: 'efectivo' },
    { label: 'Tarjeta', value: 'tarjeta' },
    { label: 'Transferencia', value: 'transferencia' },
    { label: 'Saldo de Cliente', value: 'saldo' },
];

watch(() => props.visible, (newVal) => {
    if (newVal && props.payment) {
        editForm.clearErrors();
        editForm.amount = Math.abs(parseFloat(props.payment.amount));
        editForm.payment_method = typeof props.payment.payment_method === 'object' && props.payment.payment_method !== null 
            ? props.payment.payment_method.value 
            : props.payment.payment_method;
        editForm.bank_account_id = props.payment.bank_account_id;
        editForm.notes = props.payment.notes || '';
    }
});

const submit = () => {
    if (!props.payment) return;
    
    editForm.put(route('transactions.updatePayment', { 
        transaction: props.transactionId, 
        payment: props.payment.id 
    }), {
        preserveScroll: true,
        onSuccess: () => {
            isVisible.value = false;
            emit('success');
        }
    });
};
</script>

<template>
    <Dialog v-model:visible="isVisible" modal header="Editar Pago Realizado" :style="{ width: '32rem' }">
        <div class="flex flex-col gap-6 pt-2">
            <div class="flex flex-col gap-2">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Monto del pago</label>
                <InputNumber v-model="editForm.amount" mode="currency" currency="MXN" locale="es-MX" :min="0.01" autofocus class="w-full" />
                <small v-if="editForm.errors.amount" class="text-red-500 font-medium">{{ editForm.errors.amount }}</small>
            </div>
            <div class="flex flex-col gap-2">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Método de pago</label>
                <Select v-model="editForm.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" placeholder="Seleccione método" class="w-full" />
                <small v-if="editForm.errors.payment_method" class="text-red-500 font-medium">{{ editForm.errors.payment_method }}</small>
            </div>
            <div v-if="editForm.payment_method !== 'efectivo' && editForm.payment_method !== 'saldo'" class="flex flex-col gap-2">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Cuenta bancaria de destino</label>
                <Select 
                    v-model="editForm.bank_account_id" 
                    :options="bankAccounts" 
                    optionLabel="bank_name" 
                    optionValue="id" 
                    placeholder="Seleccione cuenta"  
                    class="w-full"
                >
                    <template #option="slotProps">
                        <div class="flex flex-col py-0.5">
                            <span class="font-semibold text-sm">{{ slotProps.option.bank_name }}</span>
                            <span class="text-xs text-gray-500 italic">{{ slotProps.option.owner_name }} ({{ slotProps.option.account_name }})</span>
                        </div>
                    </template>
                </Select>
                <small v-if="editForm.errors.bank_account_id" class="text-red-500 font-medium">{{ editForm.errors.bank_account_id }}</small>
            </div>
            <div class="flex flex-col gap-2">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Notas internas / Referencia</label>
                <Textarea v-model="editForm.notes" rows="3" placeholder="Ej. Folio de transferencia, terminal usada, etc." class="w-full" />
                <small v-if="editForm.errors.notes" class="text-red-500 font-medium">{{ editForm.errors.notes }}</small>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end items-center gap-3">
                <Button label="Cancelar" severity="secondary" @click="isVisible = false" text />
                <Button label="Guardar Cambios" icon="pi pi-save" @click="submit" :loading="editForm.processing" severity="primary" />
            </div>
        </template>
    </Dialog>
</template>