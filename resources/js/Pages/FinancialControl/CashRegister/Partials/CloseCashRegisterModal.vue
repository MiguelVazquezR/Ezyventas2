<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
});

const cashSales = computed(() => {
    return props.session?.transactions
        .filter(t => t.status === 'completado')
        .flatMap(t => t.payments)
        .filter(p => p.payment_method === 'efectivo')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
});
const inflows = computed(() => props.session?.cash_movements.filter(m => m.type === 'ingreso').reduce((sum, m) => sum + parseFloat(m.amount), 0));
const outflows = computed(() => props.session?.cash_movements.filter(m => m.type === 'egreso').reduce((sum, m) => sum + parseFloat(m.amount), 0));
const calculatedTotal = computed(() => (parseFloat(props.session?.opening_cash_balance) || 0) + cashSales.value + inflows.value - outflows.value);

const emit = defineEmits(['update:visible']);

const form = useForm({
    closing_cash_balance: null,
    notes: '',
});

const difference = computed(() => {
    if (form.closing_cash_balance === null) return 0;
    return form.closing_cash_balance - calculatedTotal.value;
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    form.put(route('cash-register-sessions.update', props.session.id), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Realizar Corte de Caja" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div class="text-sm">
                <p>Estás a punto de cerrar la sesión. Cuenta el efectivo en caja e ingresa el monto total.</p>
                <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-md space-y-2">
                    <div class="flex justify-between"><span>Fondo Inicial:</span> <span>{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(session.opening_cash_balance) }}</span></div>
                    <div class="flex justify-between"><span>(+) Ventas en Efectivo:</span> <span class="text-green-500">{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(cashSales) }}</span></div>
                    <div class="flex justify-between"><span>(+) Otros Ingresos:</span> <span class="text-green-500">{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(inflows) }}</span></div>
                    <div class="flex justify-between"><span>(-) Egresos / Retiros:</span> <span class="text-red-500">{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(outflows) }}</span></div>
                    <div class="flex justify-between font-semibold border-t pt-2 mt-2"><span>Total Esperado en Caja:</span> <span>{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(calculatedTotal) }}</span></div>
                </div>
            </div>
            <div>
                <InputLabel for="closing_cash_balance" value="Monto Contado en Caja *" />
                <InputNumber id="closing_cash_balance" v-model="form.closing_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                <InputError :message="form.errors.closing_cash_balance" class="mt-2" />
            </div>
             <div v-if="form.closing_cash_balance !== null" class="p-3 rounded-md" :class="difference === 0 ? 'bg-gray-100 dark:bg-gray-600' : (difference > 0 ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20')">
                <div class="flex justify-between font-semibold" :class="difference === 0 ? '' : (difference > 0 ? 'text-green-600' : 'text-red-600')">
                    <span>Diferencia (Sobrante/Faltante):</span>
                    <span>{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(difference) }}</span>
                </div>
            </div>
            <div>
                <InputLabel for="notes" value="Notas (Opcional)" />
                <Textarea id="notes" v-model="form.notes" rows="3" class="w-full mt-1" placeholder="Justifica cualquier diferencia aquí..." />
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Confirmar Cierre" :loading="form.processing" severity="danger"></Button>
            </div>
        </form>
    </Dialog>
</template>