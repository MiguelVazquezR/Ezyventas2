<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    account: Object, // La cuenta de ORIGEN
    allAccounts: Array, // Todas las cuentas disponibles para ser DESTINO
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    from_account_id: props.account?.id || null,
    to_account_id: null,
    amount: null,
    notes: '',
});

// Filtra las cuentas de destino para no incluir la de origen
const destinationAccounts = computed(() => {
    return props.allAccounts.filter(acc => acc.id !== props.account?.id);
});

const submit = () => {
    form.from_account_id = props.account.id;
    form.post(route('bank-accounts.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
            form.reset();
        },
    });
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', $event)" modal header="Realizar Transferencia"
        class="w-full max-w-lg">
        <form @submit.prevent="submit" v-if="account">
            <div class="p-4 space-y-4">
                <div>
                    <InputLabel value="Desde la cuenta" />
                    <div class="p-3 bg-gray-100 dark:bg-gray-700 rounded-md mt-1">
                        <p class="font-semibold">{{ account.account_name }}</p>
                        <p class="text-sm text-gray-500">{{ account.bank_name }} - Saldo: {{ formatCurrency(account.balance) }}</p>
                    </div>
                </div>

                <div>
                    <InputLabel for="to_account" value="Hacia la cuenta *" />
                    <Select v-model="form.to_account_id" :options="destinationAccounts" optionLabel="account_name"
                        optionValue="id" placeholder="Selecciona una cuenta destino" class="w-full mt-1" />
                    <InputError :message="form.errors.to_account_id" />
                </div>

                <div>
                    <InputLabel for="amount" value="Monto a transferir *" />
                    <InputNumber v-model="form.amount" inputId="amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                    <InputError :message="form.errors.amount" />
                </div>
                 <div>
                    <InputLabel for="notes" value="Notas (Opcional)" />
                    <Textarea v-model="form.notes" id="notes" class="w-full mt-1" rows="3" />
                    <InputError :message="form.errors.notes" />
                </div>
            </div>
             <div class="flex items-center justify-end gap-2 p-4 bg-gray-50 dark:bg-gray-900/50 rounded-b-lg">
                <Button type="button" label="Cancelar" severity="secondary" @click="$emit('update:visible', false)" text />
                <Button type="submit" label="Confirmar Transferencia" :loading="form.processing" />
            </div>
        </form>
    </Dialog>
</template>