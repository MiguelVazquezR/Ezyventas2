<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    visible: Boolean,
    cashRegister: Object,
    branchUsers: Array,
    userBankAccounts: Array,
});

const emit = defineEmits(['update:visible']);

// composables
const { hasPermission } = usePermissions();

const form = useForm({
    cash_register_id: props.cashRegister?.id,
    opening_cash_balance: 0.0,
    user_id: null,
    // AÑADIDO: Se añade el array para los saldos bancarios.
    bank_accounts: [],
});

// AÑADIDO: Lógica para poblar el formulario cuando el modal se hace visible.
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        form.cash_register_id = props.cashRegister?.id;

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
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Abrir Caja Registradora"
        :style="{ width: '35rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Estás a punto de iniciar una nueva sesión para la caja <strong>{{ cashRegister.name }}</strong>.
                </p>
                <div>
                    <InputLabel for="user_id" value="Usuario de la sesión *" />
                    <Select id="user_id" v-model="form.user_id" :options="branchUsers" optionLabel="name"
                        optionValue="id" placeholder="Selecciona un usuario" class="w-full mt-1"
                        :optionDisabled="(option) => option.is_busy">
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

                <Divider />

                <h5 class="font-semibold text-gray-700 dark:text-gray-300">Saldos iniciales</h5>

                <div>
                    <InputLabel for="opening_cash_balance" value="Fondo de caja inicial (Efectivo) *" />
                    <InputNumber id="opening_cash_balance" v-model="form.opening_cash_balance" mode="currency"
                        currency="MXN" locale="es-MX" class="w-full mt-1" />
                    <InputError :message="form.errors.opening_cash_balance" class="mt-2" />
                </div>

                <!--  Sección para Cuentas Bancarias -->
                <div v-if="form.bank_accounts && form.bank_accounts.length > 0" class="space-y-4">
                    <div v-for="(account, index) in form.bank_accounts" :key="account.id">
                        <InputLabel :for="'bank-balance-' + account.id"
                            :value="`Saldo en ${account.bank_name} (${account.account_name})`" />
                        <InputNumber :id="'bank-balance-' + account.id" v-model="account.balance" mode="currency"
                            currency="MXN" locale="es-MX" class="w-full mt-1" />
                        <InputError :message="form.errors[`bank_accounts.${index}.balance`]" class="mt-1" />
                    </div>
                </div>

            </div>
            <div class="flex justify-end gap-2 mt-6 border-t dark:border-gray-700 pt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" label="Confirmar apertura" :loading="form.processing" severity="success"></Button>
            </div>
        </form>
    </Dialog>
</template>