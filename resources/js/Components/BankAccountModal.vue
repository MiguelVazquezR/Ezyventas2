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

const emit = defineEmits(['update:visible', 'success']);

const isEditMode = computed(() => !!props.account);
// --- NUEVO: Comprueba si se pasaron sucursales al modal ---
const hasBranches = computed(() => props.branches && props.branches.length > 0);

const form = useForm({
    bank_name: '',
    owner_name: '',
    account_name: '',
    account_number: '',
    card_number: '',
    clabe: '',
    branch_ids: [],
    favorite_branch_ids: [],
});

watch(() => props.visible, (newVal) => {
    if (newVal) {
        form.clearErrors();
        if (isEditMode.value) {
            form.bank_name = props.account.bank_name;
            form.owner_name = props.account.owner_name;
            form.account_name = props.account.account_name;
            form.account_number = props.account.account_number;
            form.card_number = props.account.card_number;
            form.clabe = props.account.clabe;
            if (hasBranches.value) {
                form.branch_ids = props.account.branches.map(b => b.id);
                form.favorite_branch_ids = props.account.branches
                    .filter(b => b.pivot.is_favorite)
                    .map(b => b.id);
            }
        } else {
            form.reset();
        }
    }
});

const selectedBranches = computed(() => {
    if (!hasBranches.value) return [];
    return props.branches.filter(branch => form.branch_ids.includes(branch.id));
});

const closeModal = () => emit('update:visible', false);

const submit = () => {
    form.transform((data) => {
        const payload = {
            bank_name: data.bank_name,
            owner_name: data.owner_name,
            account_name: data.account_name,
            account_number: data.account_number,
            card_number: data.card_number,
            clabe: data.clabe,
        };

        if (hasBranches.value && data.branch_ids.length > 0) {
            payload.branches = data.branch_ids.map(id => ({
                id: id,
                is_favorite: data.favorite_branch_ids.includes(id),
            }));
        }
        
        return payload;
    });

    const options = {
        onSuccess: () => {
            closeModal();
            // --- NUEVO: Emitir evento de éxito ---
            emit('success'); 
        },
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
            
            <!-- --- NUEVO: Condicional para mostrar solo si hay sucursales --- -->
            <div v-if="hasBranches">
                <div>
                    <InputLabel for="branch_ids" value="Asignar a Sucursal(es) *" />
                    <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id"
                        placeholder="Selecciona sucursales" class="w-full mt-1" />
                    <InputError :message="form.errors.branches" />
                </div>
                
                <div v-if="form.branch_ids.length > 0" class="space-y-2 border-t dark:border-gray-700 pt-4 mt-4">
                    <InputLabel value="Marcar como cuenta favorita en:" class="font-semibold" />
                    <div v-for="branch in selectedBranches" :key="branch.id" class="flex items-center">
                        <Checkbox v-model="form.favorite_branch_ids" :inputId="`fav_${branch.id}`" name="favorite_branches" :value="branch.id" />
                        <label :for="`fav_${branch.id}`" class="ml-2 cursor-pointer"> {{ branch.name }} </label>
                    </div>
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