<script setup>
import { useForm } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    visible: Boolean,
    cashRegister: Object,
    branchUsers: Array,
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    cash_register_id: props.cashRegister?.id,
    opening_cash_balance: null,
    user_id: null,
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    form.post(route('cash-register-sessions.store'), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Abrir Caja Registradora" :style="{ width: '25rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Estás a punto de iniciar una nueva sesión para la caja <strong>{{ cashRegister.name }}</strong>.
                </p>
                <div>
                    <InputLabel for="user_id" value="Usuario de la sesión *" />
                    <Select 
                        id="user_id"
                        v-model="form.user_id"
                        :options="branchUsers"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Selecciona un usuario"
                        class="w-full mt-1"
                        :optionDisabled="(option) => option.is_busy"
                    >
                        <template #option="slotProps">
                            <div class="flex justify-between items-center">
                                <span>{{ slotProps.option.name }}</span>
                                <Tag v-if="slotProps.option.is_busy" value="Ocupado" severity="danger" />
                                <Tag v-else value="Libre" severity="success" />
                            </div>
                        </template>
                    </Select>
                    <InputError :message="form.errors.user_id" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="opening_cash_balance" value="Fondo de Caja Inicial *" />
                    <InputNumber id="opening_cash_balance" v-model="form.opening_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                    <InputError :message="form.errors.opening_cash_balance" class="mt-2" />
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Confirmar Apertura" :loading="form.processing" severity="success"></Button>
            </div>
        </form>
    </Dialog>
</template>