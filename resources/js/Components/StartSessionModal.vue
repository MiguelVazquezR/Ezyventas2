<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    cashRegisters: Array,
    userBankAccounts: Array,
});

const emit = defineEmits(['update:visible']);

const user = usePage().props.auth.user;

const form = useForm({
    cash_register_id: null,
    user_id: user.id,
    opening_cash_balance: 0.00,
    bank_accounts: [],
});

watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        // La lógica ahora usa la nueva prop userBankAccounts
        if (props.userBankAccounts) {
            form.bank_accounts = props.userBankAccounts.map(account => ({
                id: account.id,
                bank_name: account.bank_name,
                account_name: account.account_name,
                balance: parseFloat(account.balance)
            }));
        } else {
            form.bank_accounts = [];
        }
    }
});


const submit = () => {
    form.post(route('cash-register-sessions.store'), {
        onSuccess: () => {
            closeModal();
        },
        preserveScroll: true,
    });
};

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" :modal="true" header="Iniciar sesión de caja" :style="{ width: '35rem' }">
        <div class="p-4 text-center">
            <div class="bg-blue-100 dark:bg-blue-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                <i class="pi pi-inbox !text-4xl text-blue-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">Apertura de caja requerida</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">
                Selecciona una caja, confirma los saldos iniciales y comienza a registrar ventas.
            </p>
        </div>

        <form v-if="cashRegisters && cashRegisters.length > 0" @submit.prevent="submit" class="p-2 space-y-6">
            <div>
                <InputLabel for="cash-register" value="Caja registradora *" />
                <Select v-model="form.cash_register_id" :options="cashRegisters" optionLabel="name" optionValue="id" placeholder="Selecciona una caja disponible" class="w-full mt-1" />
                <InputError :message="form.errors.cash_register_id" class="mt-1" />
            </div>
            
            <Divider />

            <div>
                <h5 class="font-semibold mb-3 text-gray-700 dark:text-gray-300">Saldos iniciales</h5>
                <div class="space-y-4 max-h-[40vh] overflow-y-auto pr-2">
                    <div>
                        <InputLabel for="opening-balance" value="Fondo de caja inicial (efectivo) *" />
                        <InputNumber id="opening-balance" v-model="form.opening_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                        <InputError :message="form.errors.opening_cash_balance" class="mt-1" />
                    </div>
                    <div v-if="form.bank_accounts && form.bank_accounts.length > 0">
                         <div v-for="(account, index) in form.bank_accounts" :key="account.id" class="mt-4">
                            <InputLabel :for="'bank-balance-' + account.id" :value="`Saldo en ${account.bank_name} (${account.account_name})`" />
                            <InputNumber :id="'bank-balance-' + account.id" v-model="account.balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                            <InputError :message="form.errors[`bank_accounts.${index}.balance`]" class="mt-1" />
                        </div>
                    </div>
                </div>
            </div>

             <div class="flex justify-end gap-2 pt-4 border-t dark:border-gray-700">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" label="Iniciar sesión" icon="pi pi-check" :loading="form.processing"></Button>
            </div>
        </form>
        <div v-else class="p-4">
             <Message severity="warn" :closable="false">No hay cajas registradoras disponibles en esta sucursal.</Message>
        </div>
    </Dialog>
</template>