<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
    movementToEdit: {
        type: Object,
        default: null
    }
});
const emit = defineEmits(['update:visible']);

const form = useForm({
    type: 'ingreso',
    amount: null,
    description: '',
});

// Computed properties para saber el modo y título
const isEditing = computed(() => !!props.movementToEdit);
const modalTitle = computed(() => isEditing.value ? 'Editar Movimiento de Efectivo' : 'Registrar Movimiento de Efectivo');

// Observador para llenar el formulario cuando se abre el modal
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        if (props.movementToEdit) {
            form.type = props.movementToEdit.type;
            form.amount = parseFloat(props.movementToEdit.amount);
            form.description = props.movementToEdit.description;
        } else {
            form.reset();
            form.clearErrors();
        }
    } else {
        form.reset();
        form.clearErrors();
    }
});

const typeOptions = [ 
    { label: 'Ingreso de Efectivo', value: 'ingreso' }, 
    { label: 'Egreso (Retiro)', value: 'egreso' } 
];

const closeModal = () => { 
    emit('update:visible', false); 
};

const submit = () => {
    if (isEditing.value) {
        // Enviar por PUT a la ruta de actualización
        form.put(route('session-cash-movements.update', props.movementToEdit.id), {
            onSuccess: () => closeModal(),
            preserveScroll: true,
        });
    } else {
        // Enviar por POST para crear uno nuevo
        form.post(route('session-cash-movements.store', props.session.id), {
            onSuccess: () => closeModal(),
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="modalTitle" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="type" value="Tipo de Movimiento *" />
                <Select id="type" v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" class="w-full mt-1" />
            </div>
            <div>
                <InputLabel for="amount" value="Monto *" />
                <InputNumber id="amount" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" :min="0.01" />
                <InputError :message="form.errors.amount" class="mt-2" />
            </div>
            <div>
                <InputLabel for="description" value="Descripción / Razón *" />
                <Textarea id="description" v-model="form.description" rows="3" class="w-full mt-1" placeholder="Ej: Pago a proveedor, cambio para cliente, etc." />
                <InputError :message="form.errors.description" class="mt-2" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <!-- Texto del botón dinámico -->
                <Button type="submit" :label="isEditing ? 'Guardar Cambios' : 'Registrar'" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>