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
    bank_name: '',
    owner_name: '',
    account_name: '',
    account_number: '',
    card_number: '',
    clabe: '',
    branch_ids: [], // Para el v-model del MultiSelect, ej: [1, 2]
    favorite_branch_ids: [], // Para el v-model de los Checkboxes, ej: [1]
});

// Cuando se abre el modal, se llena o resetea el formulario.
watch(() => props.visible, (newVal) => {
    if (newVal) {
        form.clearErrors();
        if (isEditMode.value) {
            // Llenar formulario para edición
            form.bank_name = props.account.bank_name;
            form.owner_name = props.account.owner_name;
            form.account_name = props.account.account_name;
            form.account_number = props.account.account_number;
            form.card_number = props.account.card_number;
            form.clabe = props.account.clabe;
            form.branch_ids = props.account.branches.map(b => b.id);
            form.favorite_branch_ids = props.account.branches
                .filter(b => b.pivot.is_favorite)
                .map(b => b.id);
        } else {
            // Resetear para creación
            form.reset();
        }
    }
});

// Filtra las sucursales completas que han sido seleccionadas en el MultiSelect
const selectedBranches = computed(() => {
    return props.branches.filter(branch => form.branch_ids.includes(branch.id));
});

const closeModal = () => emit('update:visible', false);

const submit = () => {
    // Transforma los datos del formulario a la estructura que espera el backend
    form.transform((data) => {
        // Crea el array de objetos {id, is_favorite}
        const transformedBranches = data.branch_ids.map(id => ({
            id: id,
            is_favorite: data.favorite_branch_ids.includes(id),
        }));
        
        // Retorna el nuevo objeto de datos para la petición
        return {
            ...data,
            branches: transformedBranches, // El campo que el backend espera
            branch_ids: undefined, // Elimina los campos que ya no son necesarios
            favorite_branch_ids: undefined,
        };
    });

    const options = {
        onSuccess: () => closeModal(),
        // Resetea la transformación después de que la petición finalice (éxito o error)
        onFinish: () => form.transform(), 
    };

    if (isEditMode.value) {
        form.put(route('bank-accounts.update', props.account.id), options);
    } else {
        form.post(route('bank-accounts.store'), options);
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal
        :header="isEditMode ? 'Editar Cuenta Bancaria' : 'Nueva Cuenta Bancaria'" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="bank_name" value="Nombre del Banco *" />
                <InputText id="bank_name" v-model="form.bank_name" class="w-full mt-1" />
                <InputError :message="form.errors.bank_name" />
            </div>
            <div>
                <InputLabel for="account_name" value="Nombre de la Cuenta *" />
                <InputText id="account_name" v-model="form.account_name" class="w-full mt-1" />
                <InputError :message="form.errors.account_name" />
            </div>
            <div>
                <InputLabel for="owner_name" value="Nombre de beneficiario *" />
                <InputText id="owner_name" v-model="form.owner_name" class="w-full mt-1" />
                <InputError :message="form.errors.owner_name" />
            </div>
            <div>
                <InputLabel for="card_number" value="Número de tarjeta (opcional)" />
                <InputText id="card_number" v-model="form.card_number" class="w-full mt-1" />
                <InputError :message="form.errors.card_number" />
            </div>
            <div>
                <InputLabel for="clabe" value="CLABE (opcional)" />
                <InputText id="clabe" v-model="form.clabe" class="w-full mt-1" />
                <InputError :message="form.errors.clabe" />
            </div>
            <div>
                <InputLabel for="account_number" value="Número de cuenta (opcional)" />
                <InputText id="account_number" v-model="form.account_number" class="w-full mt-1" />
                <InputError :message="form.errors.account_number" />
            </div>
            <div>
                <InputLabel for="branch_ids" value="Asignar a Sucursal(es) *" />
                <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id"
                    placeholder="Selecciona sucursales" class="w-full mt-1" />
                <InputError :message="form.errors.branches" />
            </div>
            
            <!-- Nueva sección para marcar favoritas -->
            <div v-if="form.branch_ids.length > 0" class="space-y-2 border-t dark:border-gray-700 pt-4 mt-4">
                <InputLabel value="Marcar como cuenta favorita en:" class="font-semibold" />
                <div v-for="branch in selectedBranches" :key="branch.id" class="flex items-center">
                    <Checkbox v-model="form.favorite_branch_ids" :inputId="`fav_${branch.id}`" name="favorite_branches" :value="branch.id" />
                    <label :for="`fav_${branch.id}`" class="ml-2 cursor-pointer"> {{ branch.name }} </label>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" :label="isEditMode ? 'Guardar cambios' : 'Crear cuenta'"
                    :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>