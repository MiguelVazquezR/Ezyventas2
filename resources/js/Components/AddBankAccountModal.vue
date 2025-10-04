<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
});

const emit = defineEmits(['update:visible', 'success']);

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    bank_name: '',
    owner_name: '',
    account_name: '',
    account_number: '',
    card_number: '',
    clabe: '',
    branch_ids: [],
});

const submit = () => {
    // Asigna la cuenta a la sucursal actual por defecto.
    form.branch_ids = [{
        id: user.value.branch_id,
        is_favorite: false
    }];
    
    form.post(route('bank-accounts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            emit('success');
        },
    });
};

const closeModal = () => {
    form.reset();
    form.clearErrors();
    emit('update:visible', false);
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Registrar nueva cuenta bancaria" :style="{ width: '30rem' }" :draggable="false">
        <form @submit.prevent="submit">
            <div class="p-fluid space-y-4">
                <div>
                    <InputLabel value="Nombre del banco *" for="bank_name" />
                    <InputText fluid id="bank_name" v-model="form.bank_name" :class="{'p-invalid': form.errors.bank_name}" />
                    <small v-if="form.errors.bank_name" class="text-red-600">{{ form.errors.bank_name }}</small>
                </div>
                <div>
                    <InputLabel value="Nombre de la cuenta o alias *" for="account_name" />
                    <InputText fluid id="account_name" v-model="form.account_name" :class="{'p-invalid': form.errors.account_name}" />
                    <small v-if="form.errors.account_name" class="text-red-600">{{ form.errors.account_name }}</small>
                </div>
                <div>
                    <InputLabel value="Nombre de beneficiario *" for="owner_name" />
                    <InputText fluid id="owner_name" v-model="form.owner_name" :class="{'p-invalid': form.errors.owner_name}" />
                    <small v-if="form.errors.owner_name" class="text-red-600">{{ form.errors.owner_name }}</small>
                </div>
                <div>
                    <InputLabel value="Número de tarjeta (opcional)" for="card_number" />
                    <InputText fluid id="card_number" v-model="form.card_number" :class="{'p-invalid': form.errors.card_number}" />
                    <small v-if="form.errors.card_number" class="text-red-600">{{ form.errors.card_number }}</small>
                </div>
                <div>
                    <InputLabel value="Número de cuenta (opcional)" for="account_number" />
                    <InputText fluid id="account_number" v-model="form.account_number" :class="{'p-invalid': form.errors.account_number}" />
                    <small v-if="form.errors.account_number" class="text-red-600">{{ form.errors.account_number }}</small>
                </div>
                 <div>
                    <InputLabel value="CLABE (opcional)" for="clabe" />
                    <InputText fluid id="clabe" v-model="form.clabe" :class="{'p-invalid': form.errors.clabe}" />
                    <small v-if="form.errors.clabe" class="text-red-600">{{ form.errors.clabe }}</small>
                </div>
            </div>
        </form>
         <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="closeModal" />
            <Button label="Guardar Cuenta" icon="pi pi-check" @click="submit" :loading="form.processing" />
        </template>
    </Dialog>
</template>