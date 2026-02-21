<script setup>
import { computed, ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
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

// Controladores para abrir/cerrar detalles (Acordeones)
const showCashDetails = ref(false);
const showCardDetails = ref(false);
const showTransferDetails = ref(false);
const showInflowDetails = ref(false);
const showOutflowDetails = ref(false);

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

// --- LISTAS DETALLADAS ---
// Filtramos los pagos por método (Abonos o Ventas es igual para la caja si es dinero entrante)
const cashPaymentsList = computed(() => (props.session?.payments || []).filter(p => p && p.payment_method === 'efectivo' && p.status === 'completado'));
const cardPaymentsList = computed(() => (props.session?.payments || []).filter(p => p && p.payment_method === 'tarjeta' && p.status === 'completado'));
const transferPaymentsList = computed(() => (props.session?.payments || []).filter(p => p && p.payment_method === 'transferencia' && p.status === 'completado'));

// Filtramos los movimientos de caja manuales
const inflowList = computed(() => (props.session?.cash_movements || []).filter(m => m.type === 'ingreso'));
const outflowList = computed(() => (props.session?.cash_movements || []).filter(m => m.type === 'egreso'));

// --- CALCULOS TOTALES (Basados en las listas) ---
const cashSales = computed(() => cashPaymentsList.value.reduce((sum, p) => sum + parseFloat(p.amount), 0));
const cardSales = computed(() => cardPaymentsList.value.reduce((sum, p) => sum + parseFloat(p.amount), 0));
const transferSales = computed(() => transferPaymentsList.value.reduce((sum, p) => sum + parseFloat(p.amount), 0));

const inflows = computed(() => inflowList.value.reduce((sum, m) => sum + parseFloat(m.amount), 0));
const outflows = computed(() => outflowList.value.reduce((sum, m) => sum + parseFloat(m.amount), 0));

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
        showCashDetails.value = false;
        showCardDetails.value = false;
        showTransferDetails.value = false;
        showInflowDetails.value = false;
        showOutflowDetails.value = false;
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

            <!-- VISTA 3: El Corte de Caja Final -->
            <div v-if="isLastUser || view === 'finalClose'">
                <div class="space-y-4">
                    
                    <!-- Resumen de Efectivo Desplegable -->
                    <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg border dark:border-gray-700">
                        <h6 class="font-bold mb-3 text-center border-b dark:border-gray-700 pb-2">Resumen de efectivo en caja</h6>
                        
                        <div class="space-y-1 text-sm select-none">
                            <!-- Fondo inicial -->
                            <div class="flex justify-between p-1">
                                <span>Fondo inicial:</span> 
                                <span class="font-semibold">{{ formatCurrency(session.opening_cash_balance) }}</span>
                            </div>
                            
                            <!-- Pagos en Efectivo -->
                            <div class="flex flex-col">
                                <div class="flex justify-between items-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 p-1 -mx-1 rounded transition-colors" @click="showCashDetails = !showCashDetails">
                                    <span class="flex items-center gap-2">
                                        <i :class="showCashDetails ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-[10px] text-gray-500 w-3 text-center"></i>
                                        (+) Pagos y abonos en efectivo:
                                    </span>
                                    <span class="font-semibold text-green-600">+ {{ formatCurrency(cashSales) }}</span>
                                </div>
                                <div v-show="showCashDetails" class="pl-6 pr-2 py-1 text-xs space-y-2 border-l-2 border-gray-200 dark:border-gray-600 ml-1.5 my-1 max-h-40 overflow-y-auto custom-scrollbar">
                                    <div v-for="payment in cashPaymentsList" :key="payment.id" class="flex justify-between items-center text-gray-600 dark:text-gray-400">
                                        <div class="flex flex-col truncate pr-2">
                                            <span class="font-medium text-gray-800 dark:text-gray-200">Folio #{{ payment.transaction?.folio || 'N/A' }}</span>
                                            <span v-if="payment.notes" class="italic opacity-75 text-[10px]">{{ payment.notes }}</span>
                                        </div>
                                        <span class="font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                    </div>
                                    <div v-if="!cashPaymentsList.length" class="text-gray-400 italic">No hay pagos en efectivo.</div>
                                </div>
                            </div>

                            <!-- Otras Entradas -->
                            <div class="flex flex-col">
                                <div class="flex justify-between items-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 p-1 -mx-1 rounded transition-colors" @click="showInflowDetails = !showInflowDetails">
                                    <span class="flex items-center gap-2">
                                        <i :class="showInflowDetails ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-[10px] text-gray-500 w-3 text-center"></i>
                                        (+) Otras entradas (manual):
                                    </span>
                                    <span class="font-semibold text-green-600">+ {{ formatCurrency(inflows) }}</span>
                                </div>
                                <div v-show="showInflowDetails" class="pl-6 pr-2 py-1 text-xs space-y-2 border-l-2 border-gray-200 dark:border-gray-600 ml-1.5 my-1 max-h-40 overflow-y-auto custom-scrollbar">
                                    <div v-for="mov in inflowList" :key="mov.id" class="flex justify-between items-center text-gray-600 dark:text-gray-400">
                                        <span class="truncate pr-2 font-medium text-gray-800 dark:text-gray-200">{{ mov.description || 'Ingreso manual' }}</span>
                                        <span class="font-semibold">{{ formatCurrency(mov.amount) }}</span>
                                    </div>
                                    <div v-if="!inflowList.length" class="text-gray-400 italic">No hay entradas adicionales registradas.</div>
                                </div>
                            </div>

                            <!-- Salidas -->
                            <div class="flex flex-col">
                                <div class="flex justify-between items-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 p-1 -mx-1 rounded transition-colors" @click="showOutflowDetails = !showOutflowDetails">
                                    <span class="flex items-center gap-2">
                                        <i :class="showOutflowDetails ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-[10px] text-gray-500 w-3 text-center"></i>
                                        (-) Salidas / Retiros:
                                    </span>
                                    <span class="font-semibold text-red-500">- {{ formatCurrency(outflows) }}</span>
                                </div>
                                <div v-show="showOutflowDetails" class="pl-6 pr-2 py-1 text-xs space-y-2 border-l-2 border-gray-200 dark:border-gray-600 ml-1.5 my-1 max-h-40 overflow-y-auto custom-scrollbar">
                                    <div v-for="mov in outflowList" :key="mov.id" class="flex justify-between items-center text-gray-600 dark:text-gray-400">
                                        <span class="truncate pr-2 font-medium text-gray-800 dark:text-gray-200">{{ mov.description || 'Retiro manual' }}</span>
                                        <span class="font-semibold text-red-500">-{{ formatCurrency(mov.amount) }}</span>
                                    </div>
                                    <div v-if="!outflowList.length" class="text-gray-400 italic">No hay salidas registradas.</div>
                                </div>
                            </div>

                            <!-- Total Esperado -->
                            <div class="flex justify-between items-center font-bold text-lg border-t border-gray-300 dark:border-gray-600 pt-2 mt-2 p-1 bg-gray-100 dark:bg-gray-700/50 rounded">
                                <span>Total esperado en caja:</span> 
                                <span class="text-primary-600 dark:text-primary-400">{{ formatCurrency(expectedCashTotal) }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Resumen de otros métodos de pago -->
                    <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg border dark:border-gray-700">
                        <h6 class="font-semibold text-center text-sm mb-2 text-gray-600 dark:text-gray-300">Resumen de otros métodos de pago (informativo)</h6>
                        
                        <div class="space-y-1 text-sm select-none">
                            <!-- Pagos con Tarjeta -->
                            <div class="flex flex-col">
                                <div class="flex justify-between items-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 p-1 -mx-1 rounded transition-colors" @click="showCardDetails = !showCardDetails">
                                    <span class="flex items-center gap-2">
                                        <i :class="showCardDetails ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-[10px] text-gray-500 w-3 text-center"></i>
                                        <i class="pi pi-credit-card text-blue-500 w-4"></i>
                                        Pagos con tarjeta:
                                    </span>
                                    <span class="font-semibold">{{ formatCurrency(cardSales) }}</span>
                                </div>
                                <div v-show="showCardDetails" class="pl-8 pr-2 py-1 text-xs space-y-2 border-l-2 border-gray-200 dark:border-gray-600 ml-1.5 my-1 max-h-40 overflow-y-auto custom-scrollbar">
                                    <div v-for="payment in cardPaymentsList" :key="payment.id" class="flex justify-between items-center text-gray-600 dark:text-gray-400">
                                        <div class="flex flex-col truncate pr-2">
                                            <span class="font-medium text-gray-800 dark:text-gray-200">Folio #{{ payment.transaction?.folio || 'N/A' }}</span>
                                        </div>
                                        <span class="font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                    </div>
                                    <div v-if="!cardPaymentsList.length" class="text-gray-400 italic">No hay pagos con tarjeta.</div>
                                </div>
                            </div>

                            <!-- Pagos con Transferencia -->
                            <div class="flex flex-col mt-1">
                                <div class="flex justify-between items-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 p-1 -mx-1 rounded transition-colors" @click="showTransferDetails = !showTransferDetails">
                                    <span class="flex items-center gap-2">
                                        <i :class="showTransferDetails ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-[10px] text-gray-500 w-3 text-center"></i>
                                        <i class="pi pi-arrows-h text-orange-500 w-4"></i>
                                        Transferencias:
                                    </span>
                                    <span class="font-semibold">{{ formatCurrency(transferSales) }}</span>
                                </div>
                                <div v-show="showTransferDetails" class="pl-8 pr-2 py-1 text-xs space-y-2 border-l-2 border-gray-200 dark:border-gray-600 ml-1.5 my-1 max-h-40 overflow-y-auto custom-scrollbar">
                                    <div v-for="payment in transferPaymentsList" :key="payment.id" class="flex justify-between items-center text-gray-600 dark:text-gray-400">
                                        <div class="flex flex-col truncate pr-2">
                                            <span class="font-medium text-gray-800 dark:text-gray-200">Folio #{{ payment.transaction?.folio || 'N/A' }}</span>
                                        </div>
                                        <span class="font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                    </div>
                                    <div v-if="!transferPaymentsList.length" class="text-gray-400 italic">No hay transferencias registradas.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitFinalClose" class="space-y-4 mt-6 border-t dark:border-gray-700 pt-4">
                    <div>
                        <InputLabel for="closing-balance" value="Monto final en caja (conteo físico) *" class="!font-bold !text-base" />
                        <InputNumber id="closing-balance" v-model="form.closing_cash_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-2" inputClass="w-full !text-xl !font-bold !py-3" placeholder="$0.00" />
                        <InputError :message="form.errors.closing_cash_balance" class="mt-1" />
                    </div>
                    
                    <!-- Aviso de Diferencia -->
                    <div v-if="form.closing_cash_balance !== null" class="flex justify-between items-center font-bold text-base p-4 rounded-lg shadow-inner" 
                        :class="{ 
                            'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-800 dark:text-yellow-200': cashDifference !== 0, 
                            'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200': cashDifference === 0 
                        }">
                        <div class="flex items-center gap-2">
                            <i :class="cashDifference === 0 ? 'pi pi-check-circle' : 'pi pi-exclamation-triangle'"></i>
                            <span>Diferencia de caja:</span>
                        </div>
                        <span class="text-xl">{{ formatCurrency(cashDifference) }}</span>
                    </div>

                    <div>
                        <InputLabel for="notes" value="Notas de cierre (opcional)" />
                        <Textarea id="notes" v-model="form.notes" rows="2" class="w-full mt-1" placeholder="Ej. Hubo un sobrante de $50..." />
                    </div>

                    <div class="flex justify-end gap-3 mt-6 pt-2">
                         <Button v-if="!isLastUser" type="button" label="Regresar" severity="secondary" @click="view = 'confirmClose'" text></Button>
                         <Button type="submit" label="Confirmar cierre definitivo" icon="pi pi-lock" :loading="form.processing" severity="danger" class="w-full md:w-auto p-button-lg"></Button>
                    </div>
                </form>
            </div>
        </div>
         <template #footer v-if="!isLastUser && view === 'initial'">
             <Button label="Cancelar" text severity="secondary" @click="closeModal" />
         </template>
    </Dialog>
</template>