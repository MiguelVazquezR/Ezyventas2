<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue'; // Se importa 'watch'
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    cashRegisters: Array,
    branchBankAccounts: Array,
});

const emit = defineEmits(['update:visible']);

const user = usePage().props.auth.user;

const form = useForm({
    cash_register_id: null,
    user_id: user.id,
    opening_cash_balance: 0.00,
    bank_accounts: [],
});

// CORRECCIÓN: Se reemplaza watchEffect por un watch en la visibilidad del modal.
// Esto asegura que los datos se carguen solo una vez al abrir el modal,
// evitando que se sobrescriban los cambios del usuario.
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        if (props.branchBankAccounts) {
            // Se cargan los datos de las cuentas bancarias en el formulario.
            // Se incluyen los nombres para usarlos en las etiquetas del template.
            form.bank_accounts = props.branchBankAccounts.map(account => ({
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
    form.reset(); // reset() limpia el formulario al cerrar.
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" :modal="true" header="Iniciar sesión de caja" :style="{ width: '35rem' }">
        <div class="p-4 text-center">
            <div class="bg-blue-100 dark:bg-blue-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                <i class="pi pi-inbox !text-4xl text-blue-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">Apertura de Caja Requerida</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">
                Selecciona una caja, confirma los saldos iniciales y comienza a registrar ventas.
            </p>
        </div>

        <form v-if="cashRegisters && cashRegisters.length > 0" @submit.prevent="submit" class="p-2 space-y-6">
            <div>
                <InputLabel for="cash-register" value="Caja Registradora *" />
                <Dropdown v-model="form.cash_register_id" :options="cashRegisters" optionLabel="name" optionValue="id" placeholder="Selecciona una caja disponible" class="w-full mt-1" />
                <InputError :message="form.errors.cash_register_id" class="mt-1" />
            </div>
            
            <Divider />

            <div>
                <h5 class="font-semibold mb-3 text-gray-700 dark:text-gray-300">Saldos Iniciales</h5>
                <div class="space-y-4 max-h-[40vh] overflow-y-auto pr-2">
                    <div>
                        <InputLabel for="opening-balance" value="Fondo de Caja Inicial (Efectivo) *" />
                        <InputNumber id="opening-balance" v-model="form.opening_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                        <InputError :message="form.errors.opening_cash_balance" class="mt-1" />
                    </div>

                    <!-- Sección de Cuentas Bancarias -->
                    <!-- <div v-if="form.bank_accounts && form.bank_accounts.length > 0">
                         <div v-for="(account, index) in form.bank_accounts" :key="account.id" class="mt-4">
                            <InputLabel :for="'bank-balance-' + account.id" :value="`Saldo en ${account.bank_name} (${account.account_name})`" />
                            <InputNumber :id="'bank-balance-' + account.id" v-model="account.balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                            <InputError :message="form.errors[`bank_accounts.${index}.balance`]" class="mt-1" />
                        </div>
                    </div> -->
                </div>
            </div>

             <div class="flex justify-end gap-2 pt-4 border-t dark:border-gray-700">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" label="Iniciar Sesión" icon="pi pi-check" :loading="form.processing"></Button>
            </div>
        </form>
        <div v-else class="p-4">
             <Message severity="warn" :closable="false">No hay cajas registradoras disponibles en esta sucursal.</Message>
        </div>
    </Dialog>
</template>