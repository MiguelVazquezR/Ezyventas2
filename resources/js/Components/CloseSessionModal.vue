<script setup>
import { computed, ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import InputNumber from 'primevue/inputnumber';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
});

const emit = defineEmits(['update:visible']);
const page = usePage();

// --- STATE MANAGEMENT ---
const view = ref('initial'); // 'initial', 'confirmClose', 'finalClose'

// --- COMPUTED PROPERTIES ---
const isLastUser = computed(() => props.session?.users?.length <= 1);

const currentUser = computed(() => page.props.auth.user);

const otherUsers = computed(() => {
    if (!props.session?.users) return [];
    return props.session.users.filter(user => user.id !== currentUser.value.id);
});

// --- FORM for Final Close ---
const form = useForm({
    closing_cash_balance: null,
    notes: '',
});

const cashSales = computed(() => {
    if (!props.session?.payments) return 0;
    return (props.session.payments || [])
        .filter(p => p && p.payment_method === 'efectivo' && p.status === 'completado')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
});

const inflows = computed(() => (props.session?.cash_movements || []).filter(m => m.type === 'ingreso').reduce((sum, m) => sum + parseFloat(m.amount), 0));
const outflows = computed(() => (props.session?.cash_movements || []).filter(m => m.type === 'egreso').reduce((sum, m) => sum + parseFloat(m.amount), 0));
const expectedCashTotal = computed(() => (parseFloat(props.session?.opening_cash_balance) || 0) + cashSales.value + inflows.value - outflows.value);
const cashDifference = computed(() => (form.closing_cash_balance === null) ? 0 : form.closing_cash_balance - expectedCashTotal.value);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};

// --- ACTIONS ---
const closeModal = () => {
    emit('update:visible', false);
    form.reset();
    view.value = 'initial'; // Reset view on close
};

const leaveSession = () => {
    router.post(route('cash-register-sessions.leave', props.session.id), {}, {
        onSuccess: () => closeModal(),
    });
};

const submitFinalClose = () => {
    form.put(route('cash-register-sessions.update', props.session.id), {
        onSuccess: () => closeModal(),
    });
};

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Cerrar Sesión de Caja" :style="{ width: '35rem' }">
        <div v-if="session" class="p-2">

            <!-- VISTA 1: Elección para Múltiples Usuarios -->
            <div v-if="!isLastUser && view === 'initial'">
                <div class="text-center">
                    <i class="pi pi-users !text-5xl text-blue-500 mb-4"></i>
                    <h3 class="text-xl font-bold mb-2">Hay más usuarios en esta caja</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-4">
                        Además de ti, <strong>{{ otherUsers.map(u => u.name).join(', ') }}</strong> también está(n) en esta sesión. ¿Qué deseas hacer?
                    </p>
                </div>
                <div class="mt-6 space-y-3">
                    <Button @click="view = 'confirmClose'" label="1. Realizar Corte de Caja" icon="pi pi-power-off" severity="danger" outlined class="w-full text-left p-button-lg" >
                        <div class="ml-4 text-left">
                            <span class="font-bold">Realizar Corte de Caja</span>
                            <p class="font-normal text-sm whitespace-normal">Esto cerrará la sesión para TODOS y requerirá el conteo de efectivo.</p>
                        </div>
                    </Button>
                    <Button @click="leaveSession" label="2. Solo Salir de la Sesión" icon="pi pi-sign-out" severity="secondary" class="w-full text-left p-button-lg">
                        <div class="ml-4 text-left">
                            <span class="font-bold">Solo Salir de la Sesión</span>
                            <p class="font-normal text-sm whitespace-normal">La caja seguirá abierta para los demás. Podrás unirte a otra caja.</p>
                        </div>
                    </Button>
                </div>
            </div>

            <!-- VISTA 2: Confirmación antes del corte final -->
             <div v-if="!isLastUser && view === 'confirmClose'">
                 <div class="text-center p-4 bg-red-50 dark:bg-red-900/30 border-l-4 border-red-500 rounded">
                    <i class="pi pi-exclamation-triangle !text-3xl text-red-500 mb-3"></i>
                    <h3 class="text-lg font-bold">Confirmación de Cierre Total</h3>
                    <p>Estás a punto de hacer el corte de caja. Esta acción <strong>expulsará a todos los demás usuarios</strong> de la sesión actual.
                    </p>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <Button label="Regresar" icon="pi pi-arrow-left" @click="view = 'initial'" text />
                    <Button label="Continuar con el Corte" @click="view = 'finalClose'" severity="danger" />
                </div>
            </div>

            <!-- VISTA 3: El Corte de Caja Final (para el último usuario o después de confirmar) -->
            <div v-if="isLastUser || view === 'finalClose'">
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg mb-4">
                    <h3 class="font-bold text-lg mb-2 text-center">Resumen de Efectivo</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span>Fondo Inicial:</span> <span class="font-semibold">{{ formatCurrency(session.opening_cash_balance) }}</span></div>
                        <div class="flex justify-between"><span>(+) Ventas en Efectivo:</span> <span class="font-semibold text-green-600">+ {{ formatCurrency(cashSales) }}</span></div>
                        <div class="flex justify-between"><span>(+) Entradas:</span> <span class="font-semibold text-green-600">+ {{ formatCurrency(inflows) }}</span></div>
                        <div class="flex justify-between"><span>(-) Salidas:</span> <span class="font-semibold text-red-500">- {{ formatCurrency(outflows) }}</span></div>
                        <div class="flex justify-between font-bold border-t pt-2 mt-2"><span>Total Esperado:</span> <span>{{ formatCurrency(expectedCashTotal) }}</span></div>
                    </div>
                </div>

                <form @submit.prevent="submitFinalClose" class="space-y-4">
                    <div>
                        <InputLabel for="closing-balance" value="Monto Final en Caja (Conteo Físico) *" />
                        <InputNumber id="closing-balance" v-model="form.closing_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                        <InputError :message="form.errors.closing_cash_balance" class="mt-1" />
                    </div>
                    <div v-if="form.closing_cash_balance !== null" class="flex justify-between font-bold text-sm p-3 rounded-lg" :class="{ 'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-800 dark:text-yellow-200': cashDifference !== 0, 'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200': cashDifference === 0 }">
                        <span>Diferencia:</span>
                        <span>{{ formatCurrency(cashDifference) }}</span>
                    </div>
                    <div>
                        <InputLabel for="notes" value="Notas de Cierre" />
                        <Textarea id="notes" v-model="form.notes" rows="3" class="w-full mt-1" />
                    </div>
                     <div class="flex justify-end gap-2 mt-4">
                         <Button v-if="!isLastUser" type="button" label="Regresar" severity="secondary" @click="view = 'confirmClose'" text></Button>
                         <Button type="submit" label="Confirmar Cierre" :loading="form.processing" severity="danger"></Button>
                     </div>
                </form>
            </div>
        </div>
         <template #footer v-if="!isLastUser && view === 'initial'">
             <Button label="Cancelar" text severity="secondary" @click="closeModal" />
         </template>
    </Dialog>
</template>

