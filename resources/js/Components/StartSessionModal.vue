<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';
import Message from 'primevue/message';

const props = defineProps({
    visible: Boolean,
    cashRegisters: Array,
});

const emit = defineEmits(['update:visible']);

const user = usePage().props.auth.user;

const form = useForm({
    cash_register_id: null,
    user_id: user.id,
    opening_cash_balance: 0.00,
});

const submit = () => {
    form.post(route('cash-register-sessions.store'), {
        // En caso de éxito, Inertia seguirá el redirect del backend,
        // que recargará la página del TPV ya con la sesión activa.
        // El toast de éxito se mostrará automáticamente.
    });
};

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" :modal="true" header="Iniciar Sesión de Caja" :style="{ width: '30rem' }">
        <!-- <div class="p-4 text-center">
            <div class="bg-blue-100 dark:bg-blue-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                <i class="pi pi-box text-4xl text-blue-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">Apertura de Caja Requerida</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">
                Selecciona una caja e ingresa el monto inicial para comenzar a registrar ventas.
            </p>
        </div> -->

        <form v-if="cashRegisters && cashRegisters.length > 0" @submit.prevent="submit" class="p-2 space-y-4">
             <div>
                <InputLabel for="cash-register" value="Caja Registradora *" />
                <Dropdown v-model="form.cash_register_id" :options="cashRegisters" optionLabel="name" optionValue="id" placeholder="Selecciona una caja disponible" class="w-full mt-1" />
                <InputError :message="form.errors.cash_register_id" class="mt-1" />
            </div>
            <div>
                <InputLabel for="opening-balance" value="Fondo de Caja Inicial (MXN) *" />
                <InputNumber id="opening-balance" v-model="form.opening_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                <InputError :message="form.errors.opening_cash_balance" class="mt-1" />
            </div>
             <div class="flex justify-end gap-2 mt-6">
                <Button type="submit" label="Iniciar Sesión" icon="pi pi-check" :loading="form.processing" class="w-full"></Button>
            </div>
        </form>
        <div v-else class="p-4">
             <Message severity="warn" :closable="false">No hay cajas registradoras disponibles en esta sucursal.</Message>
        </div>
    </Dialog>
</template>
