<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch, computed } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    account: Object,
    branches: Array,
});

const emit = defineEmits(['update:visible']);

const isEditMode = computed(() => !!props.account);

const form = useForm({
    branch_ids: [],
    bank_name: '',
    account_name: '',
    account_number: '',
});

// SOLUCIÓN: Se observa la prop 'visible'. Cuando se abre el modal...
watch(() => props.visible, (newVal) => {
    if (newVal) {
        form.clearErrors();
        // Si es para editar, se llenan los campos.
        if (isEditMode.value) {
            form.branch_ids = props.account.branches.map(b => b.id);
            form.bank_name = props.account.bank_name;
            form.account_name = props.account.account_name;
            form.account_number = props.account.account_number;
        } else {
            // Si es para crear, se resetea el formulario a su estado inicial vacío.
            form.reset();
        }
    }
});

const closeModal = () => emit('update:visible', false);

const submit = () => {
    if (isEditMode.value) {
        form.put(route('bank-accounts.update', props.account.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('bank-accounts.store'), {
            onSuccess: () => closeModal(),
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="isEditMode ? 'Editar Cuenta Bancaria' : 'Nueva Cuenta Bancaria'" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="bank_name" value="Nombre del Banco *" />
                <InputText id="bank_name" v-model="form.bank_name" class="w-full mt-1" />
                <InputError :message="form.errors.bank_name" class="mt-1" />
            </div>
             <div>
                <InputLabel for="account_name" value="Nombre de la Cuenta *" />
                <InputText id="account_name" v-model="form.account_name" class="w-full mt-1" />
                <InputError :message="form.errors.account_name" class="mt-1" />
            </div>
             <div>
                <InputLabel for="account_number" value="Número de Cuenta / CLABE" />
                <InputText id="account_number" v-model="form.account_number" class="w-full mt-1" />
                <InputError :message="form.errors.account_number" class="mt-1" />
            </div>
            <div>
                <InputLabel for="branch_ids" value="Asignar a Sucursal(es) *" />
                <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id" placeholder="Selecciona una o más sucursales" class="w-full mt-1" />
                <InputError :message="form.errors.branch_ids" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" :label="isEditMode ? 'Guardar Cambios' : 'Crear Cuenta'" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>