<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch, computed } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    branch: {
        type: Object,
        default: null
    },
});

const emit = defineEmits(['update:visible']);

const isEditMode = computed(() => !!props.branch);

const form = useForm({
    name: '',
    contact_email: '',
    contact_phone: '',
});

// SOLUCIÓN: Se observa la prop 'visible'. Cuando se abre el modal...
watch(() => props.visible, (newVal) => {
    if (newVal) {
        form.clearErrors();
        // Si es para editar, se llenan los campos.
        if (isEditMode.value) {
            form.name = props.branch.name;
            form.contact_email = props.branch.contact_email;
            form.contact_phone = props.branch.contact_phone;
        } else {
            // Si es para crear, se resetea el formulario a su estado inicial vacío.
            form.reset();
        }
    }
});

const closeModal = () => {
    emit('update:visible', false);
};

const submit = () => {
    if (isEditMode.value) {
        form.put(route('branches.update', props.branch.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('branches.store'), {
            onSuccess: () => closeModal(),
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="isEditMode ? 'Editar Sucursal' : 'Nueva Sucursal'" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="branch_name" value="Nombre de la Sucursal *" />
                <InputText id="branch_name" v-model="form.name" class="w-full mt-1" />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>
             <div>
                <InputLabel for="branch_email" value="Email de Contacto *" />
                <InputText id="branch_email" v-model="form.contact_email" class="w-full mt-1" />
                <InputError :message="form.errors.contact_email" class="mt-1" />
            </div>
             <div>
                <InputLabel for="branch_phone" value="Teléfono de Contacto" />
                <InputText id="branch_phone" v-model="form.contact_phone" class="w-full mt-1" />
                <InputError :message="form.errors.contact_phone" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" :label="isEditMode ? 'Guardar Cambios' : 'Crear Sucursal'" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>