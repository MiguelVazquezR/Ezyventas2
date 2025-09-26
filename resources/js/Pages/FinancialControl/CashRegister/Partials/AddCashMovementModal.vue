<script setup>
import { useForm } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
});
const emit = defineEmits(['update:visible']);

const form = useForm({
    type: 'ingreso',
    amount: null,
    description: '',
});

const typeOptions = [ { label: 'Ingreso de Efectivo', value: 'ingreso' }, { label: 'Egreso (Retiro)', value: 'egreso' } ];
const closeModal = () => { emit('update:visible', false); form.reset(); };

const submit = () => {
    form.post(route('session-cash-movements.store', props.session.id), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Registrar Movimiento de Efectivo" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="type" value="Tipo de Movimiento *" />
                <Select id="type" v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" class="w-full mt-1" />
            </div>
            <div>
                <InputLabel for="amount" value="Monto *" />
                <InputNumber id="amount" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                <InputError :message="form.errors.amount" class="mt-2" />
            </div>
            <div>
                <InputLabel for="description" value="Descripción / Razón *" />
                <Textarea id="description" v-model="form.description" rows="3" class="w-full mt-1" placeholder="Ej: Pago a proveedor, cambio para cliente, etc." />
                <InputError :message="form.errors.description" class="mt-2" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Registrar" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>