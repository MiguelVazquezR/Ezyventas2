<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Button from 'primevue/button';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    closing_cash_balance: null,
    notes: '',
});

// CORRECCIÓN: Usar 'session.payments' como la única fuente de verdad para las ventas en efectivo.
const cashSales = computed(() => {
    if (!props.session?.payments) return 0;
    return props.session.payments
        .filter(p => p && p.payment_method === 'efectivo' && p.status === 'completado')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
});

const inflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements.filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const outflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements.filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const expectedCashTotal = computed(() => {
    if (!props.session) return 0;
    return (parseFloat(props.session.opening_cash_balance) || 0) + cashSales.value + inflows.value - outflows.value;
});

const cashDifference = computed(() => {
    if (form.closing_cash_balance === null) return 0;
    return form.closing_cash_balance - expectedCashTotal.value;
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    if (props.session) {
        form.put(route('cash-register-sessions.update', props.session.id), {
            onSuccess: () => closeModal(),
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Realizar Corte de Caja" :style="{ width: '35rem' }">
        <div v-if="session" class="p-2">
            <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg mb-4">
                <h3 class="font-bold text-lg mb-2 text-center">Resumen de Caja (Efectivo)</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span>Fondo Inicial:</span> <span class="font-semibold">${{ parseFloat(session.opening_cash_balance || 0).toFixed(2) }}</span></div>
                    <div class="flex justify-between"><span>(+) Ventas en Efectivo:</span> <span class="font-semibold text-green-600">+ ${{ cashSales.toFixed(2) }}</span></div>
                    <div class="flex justify-between"><span>(+) Entradas de Efectivo:</span> <span class="font-semibold text-green-600">+ ${{ inflows.toFixed(2) }}</span></div>
                    <div class="flex justify-between"><span>(-) Salidas de Efectivo:</span> <span class="font-semibold text-red-500">- ${{ outflows.toFixed(2) }}</span></div>
                    <div class="flex justify-between font-bold border-t pt-2 mt-2"><span>Total Esperado en Caja:</span> <span>${{ expectedCashTotal.toFixed(2) }}</span></div>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="closing-balance" value="Monto Final en Caja (Conteo Físico) *" />
                    <InputNumber id="closing-balance" v-model="form.closing_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                    <InputError :message="form.errors.closing_cash_balance" class="mt-1" />
                </div>
                <div v-if="form.closing_cash_balance !== null" class="flex justify-between font-bold text-sm p-3 rounded-lg"
                     :class="{ 'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-800 dark:text-yellow-200': cashDifference !== 0, 'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200': cashDifference === 0 }">
                    <span>Diferencia (Sobrante/Faltante):</span>
                    <span>${{ cashDifference.toFixed(2) }}</span>
                </div>
                 <div>
                    <InputLabel for="notes" value="Notas de Cierre" />
                    <Textarea id="notes" v-model="form.notes" rows="3" class="w-full mt-1" />
                    <InputError :message="form.errors.notes" class="mt-1" />
                </div>
            </form>
        </div>
         <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="closeModal" />
            <Button label="Cerrar Caja" icon="pi pi-check" @click="submit" :loading="form.processing" severity="danger"/>
        </template>
    </Dialog>
</template>
