<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Button from 'primevue/button';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    type: {
        type: String, // 'ingreso' or 'egreso'
        required: true,
    },
    sessionId: {
        type: Number,
        required: true,
    }
});

const emit = defineEmits(['update:visible', 'submitted']);

const form = useForm({
    amount: null,
    description: '',
    type: props.type,
});

// Actualiza el tipo en el formulario si la prop cambia
watch(() => props.type, (newType) => {
    form.type = newType;
});

const modalTitle = computed(() => props.type === 'ingreso' ? 'Registrar Ingreso de Efectivo' : 'Registrar Retiro de Efectivo');
const amountLabel = computed(() => props.type === 'ingreso' ? 'Monto a Ingresar' : 'Monto a Retirar');

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    if (props.sessionId) {
        form.post(route('session-cash-movements.store', props.sessionId), {
            preserveScroll: true,
            onSuccess: () => {
                emit('submitted');
                closeModal();
            },
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="modalTitle" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel :for="`movement-amount-${type}`" :value="`${amountLabel} *`" />
                <InputNumber :id="`movement-amount-${type}`" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                <InputError :message="form.errors.amount" class="mt-1" />
            </div>
             <div>
                <InputLabel :for="`movement-description-${type}`" value="Descripción / Motivo *" />
                <Textarea :id="`movement-description-${type}`" v-model="form.description" rows="3" class="w-full mt-1" />
                <InputError :message="form.errors.description" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" label="Registrar Movimiento" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>