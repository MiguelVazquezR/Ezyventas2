<script setup>
import { computed, ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Textarea from 'primevue/textarea';
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

// --- Payment Calculations ---
const cashSales = computed(() => {
    if (!props.session?.payments) return 0;
    return (props.session.payments || [])
        .filter(p => p && p.payment_method === 'efectivo' && p.status === 'completado')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
});

// AÑADIDO: Propiedades computadas para ventas con tarjeta y transferencia
const cardSales = computed(() => {
    if (!props.session?.payments) return 0;
    return (props.session.payments || [])
        .filter(p => p && p.payment_method === 'tarjeta' && p.status === 'completado')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
});

const transferSales = computed(() => {
    if (!props.session?.payments) return 0;
    return (props.session.payments || [])
        .filter(p => p && p.payment_method === 'transferencia' && p.status === 'completado')
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
    // Se espera a que la transición del modal termine antes de resetear, para evitar parpadeos.
    setTimeout(() => {
        form.reset();
        view.value = 'initial';
    }, 300);
};

const leaveSession = () => {
    router.post(route('cash-register-sessions.leave', props.session.id), {}, {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};

const submitFinalClose = () => {
    form.put(route('cash-register-sessions.update', props.session.id), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Cerrar sesión de caja" :style="{ width: '35rem' }">
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
                <div class="mt-4 space-y-3">
                     <Button @click="view = 'confirmClose'" severity="danger" outlined class="w-full text-left p-button-lg" >
                        <div class="flex items-center">
                            <i class="pi pi-power-off text-xl"></i>
                            <div class="ml-4 text-left">
                                <span class="font-bold">Realizar corte de caja</span>
                                <p class="font-normal text-sm whitespace-normal">Esto cerrará la sesión para TODOS y requerirá el conteo de efectivo.</p>
                            </div>
                        </div>
                    </Button>
                    <Button @click="leaveSession" severity="secondary" class="w-full text-left p-button-lg">
                         <div class="flex items-center">
                            <i class="pi pi-sign-out text-xl"></i>
                            <div class="ml-4 text-left">
                                <span class="font-bold">Solo salir de la sesión</span>
                                <p class="font-normal text-sm whitespace-normal">La caja seguirá abierta para los demás. Podrás unirte a otra caja.</p>
                            </div>
                        </div>
                    </Button>
                </div>
            </div>

            <!-- VISTA 2: Confirmación antes del corte final -->
             <div v-if="!isLastUser && view === 'confirmClose'">
                 <div class="text-center p-3 bg-red-50 dark:bg-red-900/30 border-l-4 border-red-500 rounded">
                     <i class="pi pi-exclamation-triangle !text-3xl text-red-500 mb-3"></i>
                     <h3 class="text-lg font-bold">Confirmación de cierre total</h3>
                     <p>Estás a punto de hacer el corte de caja. Esta acción <strong>expulsará a todos los demás usuarios</strong> de la sesión actual.
                     </p>
                 </div>
                 <div class="flex justify-end gap-2 mt-4">
                     <Button label="Regresar" icon="pi pi-arrow-left" @click="view = 'initial'" text />
                     <Button label="Continuar con el corte" @click="view = 'finalClose'" severity="danger" />
                 </div>
             </div>

            <!-- VISTA 3: El Corte de Caja Final (para el último usuario o después de confirmar) -->
            <div v-if="isLastUser || view === 'finalClose'">
                <div class="space-y-2">
                    <!-- Resumen de Efectivo -->
                    <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
                        <h6 class="font-bold mb-2 text-center">Resumen de efectivo</h6>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span>Fondo inicial:</span> <span class="font-semibold">{{ formatCurrency(session.opening_cash_balance) }}</span></div>
                            <div class="flex justify-between"><span>(+) Ventas en efectivo:</span> <span class="font-semibold text-green-600">+ {{ formatCurrency(cashSales) }}</span></div>
                            <div class="flex justify-between"><span>(+) Entradas:</span> <span class="font-semibold text-green-600">+ {{ formatCurrency(inflows) }}</span></div>
                            <div class="flex justify-between"><span>(-) Salidas:</span> <span class="font-semibold text-red-500">- {{ formatCurrency(outflows) }}</span></div>
                            <div class="flex justify-between font-bold border-t pt-2 mt-2"><span>Total esperado:</span> <span>{{ formatCurrency(expectedCashTotal) }}</span></div>
                        </div>
                    </div>
                    
                    <!-- AÑADIDO: Resumen de otros métodos de pago -->
                    <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
                        <h6 class="font-semibold text-center text-md mb-2">Otros métodos de pago (informativo)</h6>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span><i class="pi pi-credit-card mr-2 text-blue-500"></i>Ventas con tarjeta:</span> 
                                <span class="font-semibold">{{ formatCurrency(cardSales) }}</span>
                            </div>
                             <div class="flex justify-between">
                                <span><i class="pi pi-arrows-h mr-2 text-orange-500"></i>Ventas por transferencia:</span> 
                                <span class="font-semibold">{{ formatCurrency(transferSales) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitFinalClose" class="space-y-2 mt-4">
                    <div>
                        <InputLabel for="closing-balance" value="Monto final en caja (conteo físico) *" />
                        <InputNumber id="closing-balance" v-model="form.closing_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                        <InputError :message="form.errors.closing_cash_balance" class="mt-1" />
                    </div>
                    <div v-if="form.closing_cash_balance !== null" class="flex justify-between font-bold text-sm p-3 rounded-lg" :class="{ 'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-800 dark:text-yellow-200': cashDifference !== 0, 'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200': cashDifference === 0 }">
                        <span>Diferencia:</span>
                        <span>{{ formatCurrency(cashDifference) }}</span>
                    </div>
                    <div>
                        <InputLabel for="notes" value="Notas de cierre" />
                        <Textarea id="notes" v-model="form.notes" rows="3" class="w-full mt-1" />
                    </div>
                     <div class="flex justify-end gap-2 mt-4">
                         <Button v-if="!isLastUser" type="button" label="Regresar" severity="secondary" @click="view = 'confirmClose'" text></Button>
                         <Button type="submit" label="Confirmar cierre" :loading="form.processing" severity="danger"></Button>
                     </div>
                </form>
            </div>
        </div>
         <template #footer v-if="!isLastUser && view === 'initial'">
             <Button label="Cancelar" text severity="secondary" @click="closeModal" />
         </template>
    </Dialog>
</template>