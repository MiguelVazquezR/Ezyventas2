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
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Cierre de caja" 
        class="w-full max-w-2xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-5 md:py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-6 md:px-8 py-6' },
            footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-4 md:py-5' }
        }">
        <div v-if="session" class="p-2">

            <!-- VISTA 1: Elección para Múltiples Usuarios -->
            <div v-if="!isLastUser && view === 'initial'" class="flex flex-col items-center">
                <div class="w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-500 flex items-center justify-center mb-6 border border-blue-100 dark:border-blue-900/50 shadow-sm">
                    <i class="pi pi-users !text-2xl"></i>
                </div>
                <h3 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0 mb-3">Conexiones múltiples activas</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-8 max-w-md text-center leading-relaxed m-0">
                    Además de ti, <strong>{{ otherUsers.map(u => u.name).join(', ') }}</strong> está(n) conectado(s) a esta sesión. Elige una acción:
                </p>
                
                <div class="w-full space-y-4 max-w-lg">
                     <button @click="view = 'confirmClose'" 
                        class="w-full text-left p-5 rounded-3xl border-2 border-red-500 bg-red-50 dark:bg-red-900/10 hover:bg-red-100 dark:hover:bg-red-900/20 transition-all duration-300 flex items-start gap-5 group">
                        <div class="w-10 h-10 rounded-full bg-red-500 text-white flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                            <i class="pi pi-power-off !text-sm"></i>
                        </div>
                        <div>
                            <span class="block text-lg font-bold text-red-700 dark:text-red-400 mb-1">Corte total de caja</span>
                            <span class="block text-xs text-red-600 dark:text-red-300/80 font-medium leading-relaxed">Requiere conteo físico. Desconectará automáticamente a todos los usuarios.</span>
                        </div>
                    </button>
                    
                    <button @click="leaveSession" 
                        class="w-full text-left p-5 rounded-3xl border-2 border-gray-200 dark:border-[#3a3a3a] bg-white dark:bg-[#1a1a1a] hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/10 transition-all duration-300 flex items-start gap-5 group">
                         <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-[#2a2a2a] text-gray-500 group-hover:bg-blue-500 group-hover:text-white flex items-center justify-center flex-shrink-0 transition-colors">
                            <i class="pi pi-sign-out !text-sm"></i>
                        </div>
                        <div>
                            <span class="block text-lg font-bold text-gray-900 dark:text-white mb-1 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Desconexión personal</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 leading-relaxed">La caja seguirá operando. Podrás iniciar sesión en otro equipo si lo deseas.</span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- VISTA 2: Confirmación antes del corte final -->
             <div v-if="!isLastUser && view === 'confirmClose'" class="flex flex-col items-center py-4">
                 <div class="w-16 h-16 rounded-full bg-red-50 dark:bg-red-900/30 text-red-500 flex items-center justify-center mb-6 border border-red-100 dark:border-red-900/50 shadow-sm animate-pulse">
                     <i class="pi pi-exclamation-triangle !text-2xl"></i>
                 </div>
                 <h3 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0 mb-3 text-center">Advertencia de sistema</h3>
                 <p class="text-sm text-gray-600 dark:text-gray-400 mb-8 max-w-md text-center leading-relaxed m-0">
                    Estás a punto de iniciar el cierre definitivo. Esta acción cerrará los cobros y <strong>expulsará a todos</strong> de la sesión.
                 </p>
                 <div class="flex w-full max-w-sm gap-3">
                     <Button label="Atrás" icon="pi pi-arrow-left" @click="view = 'initial'" severity="secondary" text class="flex-1 !rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold" />
                     <Button label="Proceder al corte" @click="view = 'finalClose'" severity="danger" class="flex-1 !rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold shadow-[0_4px_10px_rgba(239,68,68,0.4)]" />
                 </div>
             </div>

            <!-- VISTA 3: El Corte de Caja Final -->
            <div v-if="isLastUser || view === 'finalClose'" class="space-y-6">
                
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-full bg-red-50 dark:bg-red-900/30 text-red-500 flex items-center justify-center border border-red-100 dark:border-red-900/50 shadow-sm flex-shrink-0">
                        <i class="pi pi-lock !text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Cierre y arqueo</h3>
                        <p class="text-xs text-gray-500 uppercase tracking-widest m-0">Sesión #{{ session.id }}</p>
                    </div>
                </div>

                <!-- Bloque: Resumen Efectivo -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <h6 class="text-sm uppercase tracking-widest font-bold text-gray-400 m-0 mb-4 flex items-center gap-2">
                        <i class="pi pi-wallet !text-sm"></i> Movimientos en Efectivo
                    </h6>
                    
                    <div class="space-y-3">
                        <!-- Fondo inicial -->
                        <div class="flex justify-between items-center bg-white dark:bg-[#232323] p-3 rounded-2xl border border-gray-100 dark:border-[#2a2a2a]">
                            <span class="text-base text-gray-600 dark:text-gray-400 font-medium">Apertura inicial</span> 
                            <span class="font-mono text-lg font-bold text-gray-900 dark:text-gray-100">{{ formatCurrency(session.opening_cash_balance) }}</span>
                        </div>
                        
                        <!-- Entradas (+) -->
                        <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#2a2a2a] overflow-hidden">
                            <button class="w-full flex justify-between items-center p-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" @click="showCashDetails = !showCashDetails">
                                <span class="text-base font-bold text-green-600 dark:text-green-500 flex items-center gap-2">
                                    <i :class="showCashDetails ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="text-sm"></i>
                                    Ingresos por ventas
                                </span>
                                <span class="font-mono text-lg font-bold text-green-600 dark:text-green-500">+ {{ formatCurrency(cashSales) }}</span>
                            </button>
                            <div v-show="showCashDetails" class="px-4 py-2 border-t border-gray-100 dark:border-[#2a2a2a] bg-gray-50/50 dark:bg-[#1a1a1a]/50 max-h-40 overflow-y-auto custom-scrollbar">
                                <div v-for="payment in cashPaymentsList" :key="payment.id" class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-[#2a2a2a] last:border-0">
                                    <div class="flex flex-col">
                                        <span class="font-mono text-sm text-gray-600 dark:text-gray-400">#{{ payment.transaction?.folio || 'N/A' }}</span>
                                    </div>
                                    <span class="font-mono text-base">{{ formatCurrency(payment.amount) }}</span>
                                </div>
                                <div v-if="!cashPaymentsList.length" class="text-sm italic text-gray-400 py-2">Sin registros.</div>
                            </div>
                        </div>

                        <!-- Otras Entradas (+) -->
                        <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#2a2a2a] overflow-hidden">
                            <button class="w-full flex justify-between items-center p-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" @click="showInflowDetails = !showInflowDetails">
                                <span class="text-base font-bold text-green-600 dark:text-green-500 flex items-center gap-2">
                                    <i :class="showInflowDetails ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="text-sm"></i>
                                    Entradas manuales
                                </span>
                                <span class="font-mono text-lg font-bold text-green-600 dark:text-green-500">+ {{ formatCurrency(inflows) }}</span>
                            </button>
                            <div v-show="showInflowDetails" class="px-4 py-2 border-t border-gray-100 dark:border-[#2a2a2a] bg-gray-50/50 dark:bg-[#1a1a1a]/50 max-h-40 overflow-y-auto custom-scrollbar">
                                <div v-for="mov in inflowList" :key="mov.id" class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-[#2a2a2a] last:border-0">
                                    <span class="text-[11px] text-gray-600 dark:text-gray-400 truncate pr-2">{{ mov.description || 'Ingreso manual' }}</span>
                                    <span class="font-mono text-base">{{ formatCurrency(mov.amount) }}</span>
                                </div>
                                <div v-if="!inflowList.length" class="text-sm italic text-gray-400 py-2">Sin registros.</div>
                            </div>
                        </div>

                        <!-- Salidas (-) -->
                        <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#2a2a2a] overflow-hidden">
                            <button class="w-full flex justify-between items-center p-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" @click="showOutflowDetails = !showOutflowDetails">
                                <span class="text-base font-bold text-red-500 flex items-center gap-2">
                                    <i :class="showOutflowDetails ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="text-sm"></i>
                                    Salidas manuales
                                </span>
                                <span class="font-mono text-lg font-bold text-red-500">- {{ formatCurrency(outflows) }}</span>
                            </button>
                            <div v-show="showOutflowDetails" class="px-4 py-2 border-t border-gray-100 dark:border-[#2a2a2a] bg-gray-50/50 dark:bg-[#1a1a1a]/50 max-h-40 overflow-y-auto custom-scrollbar">
                                <div v-for="mov in outflowList" :key="mov.id" class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-[#2a2a2a] last:border-0">
                                    <span class="text-[11px] text-gray-600 dark:text-gray-400 truncate pr-2">{{ mov.description || 'Retiro manual' }}</span>
                                    <span class="font-mono text-base text-red-400">-{{ formatCurrency(mov.amount) }}</span>
                                </div>
                                <div v-if="!outflowList.length" class="text-sm italic text-gray-400 py-2">Sin registros.</div>
                            </div>
                        </div>

                        <!-- Total Esperado -->
                        <div class="flex justify-between items-end mt-4 pt-4 border-t border-gray-200 dark:border-[#3a3a3a]">
                            <span class="text-sm font-bold uppercase tracking-widest text-gray-500 m-0 mb-1">Total del sistema</span> 
                            <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white leading-none">{{ formatCurrency(expectedCashTotal) }}</span>
                        </div>
                    </div>
                </div>
                
                <!-- Bloque: Otros Métodos -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <h6 class="text-sm uppercase tracking-widest font-bold text-gray-400 m-0 mb-4 flex items-center gap-2">
                        <i class="pi pi-credit-card !text-sm"></i> Ingresos Digitales
                    </h6>
                    
                    <div class="space-y-3">
                        <!-- Tarjeta -->
                        <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#2a2a2a] overflow-hidden">
                            <button class="w-full flex justify-between items-center p-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" @click="showCardDetails = !showCardDetails">
                                <span class="text-base font-bold text-blue-600 dark:text-blue-500 flex items-center gap-2">
                                    <i :class="showCardDetails ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="text-sm"></i>
                                    Cobros con tarjeta
                                </span>
                                <span class="font-mono text-lg font-bold text-blue-600 dark:text-blue-500">{{ formatCurrency(cardSales) }}</span>
                            </button>
                            <div v-show="showCardDetails" class="px-4 py-2 border-t border-gray-100 dark:border-[#2a2a2a] bg-gray-50/50 dark:bg-[#1a1a1a]/50 max-h-40 overflow-y-auto custom-scrollbar">
                                <div v-for="payment in cardPaymentsList" :key="payment.id" class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-[#2a2a2a] last:border-0">
                                    <span class="font-mono text-sm text-gray-600 dark:text-gray-400">#{{ payment.transaction?.folio || 'N/A' }}</span>
                                    <span class="font-mono text-base">{{ formatCurrency(payment.amount) }}</span>
                                </div>
                                <div v-if="!cardPaymentsList.length" class="text-sm italic text-gray-400 py-2">Sin registros.</div>
                            </div>
                        </div>

                        <!-- Transferencia -->
                        <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#2a2a2a] overflow-hidden">
                            <button class="w-full flex justify-between items-center p-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" @click="showTransferDetails = !showTransferDetails">
                                <span class="text-base font-bold text-orange-500 dark:text-orange-400 flex items-center gap-2">
                                    <i :class="showTransferDetails ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="text-sm"></i>
                                    Transferencias/SPEI
                                </span>
                                <span class="font-mono text-lg font-bold text-orange-500 dark:text-orange-400">{{ formatCurrency(transferSales) }}</span>
                            </button>
                            <div v-show="showTransferDetails" class="px-4 py-2 border-t border-gray-100 dark:border-[#2a2a2a] bg-gray-50/50 dark:bg-[#1a1a1a]/50 max-h-40 overflow-y-auto custom-scrollbar">
                                <div v-for="payment in transferPaymentsList" :key="payment.id" class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-[#2a2a2a] last:border-0">
                                    <span class="font-mono text-sm text-gray-600 dark:text-gray-400">#{{ payment.transaction?.folio || 'N/A' }}</span>
                                    <span class="font-mono text-xs">{{ formatCurrency(payment.amount) }}</span>
                                </div>
                                <div v-if="!transferPaymentsList.length" class="text-sm italic text-gray-400 py-2">Sin registros.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitFinalClose" class="space-y-6 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                    
                    <div class="bg-blue-50 dark:bg-blue-900/10 p-5 rounded-3xl border border-blue-100 dark:border-blue-900/30">
                        <InputLabel for="closing-balance" value="Monto final físico en caja *" class="!text-xs !font-bold !uppercase !tracking-widest !text-blue-800 dark:!text-blue-400 !mb-3" />
                        <InputNumber id="closing-balance" v-model="form.closing_cash_balance" mode="currency" currency="MXN" locale="es-MX" 
                            class="w-full" 
                            :pt="{ input: { root: { class: 'w-full !rounded-2xl !bg-white dark:!bg-[#232323] !border-blue-200 dark:!border-blue-800 focus:dark:!border-blue-500 transition-colors !py-4 !text-3xl !font-light !text-gray-900 dark:!text-white' } } }" 
                            placeholder="$0.00" />
                        <InputError :message="form.errors.closing_cash_balance" class="mt-2" />
                        
                        <!-- Aviso de Diferencia (Telemetría visual) -->
                        <div v-if="form.closing_cash_balance !== null" class="mt-4 flex justify-between items-center p-4 rounded-2xl border" 
                            :class="{ 
                                'bg-orange-100 dark:bg-orange-900/40 border-orange-200 dark:border-orange-800 text-orange-800 dark:text-orange-400': cashDifference !== 0, 
                                'bg-green-100 dark:bg-green-900/40 border-green-200 dark:border-green-800 text-green-800 dark:text-green-400': cashDifference === 0 
                            }">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
                                     :class="cashDifference === 0 ? 'bg-green-200 dark:bg-green-800' : 'bg-orange-200 dark:bg-orange-800 animate-pulse'">
                                    <i :class="cashDifference === 0 ? 'pi pi-check' : 'pi pi-exclamation-triangle'" class="!text-lg text-current"></i>
                                </div>
                                <span class="font-bold text-lg tracking-tight m-0">Descuadre / Diferencia</span>
                            </div>
                            <span class="text-xl font-mono font-bold">{{ formatCurrency(cashDifference) }}</span>
                        </div>
                    </div>

                    <div>
                        <InputLabel for="notes" value="Notas de arqueo (Opcional)" class="!text-sm !uppercase !tracking-widest !text-gray-500 mb-2" />
                        <Textarea id="notes" v-model="form.notes" rows="2" 
                            class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors resize-none !py-3" 
                            placeholder="Ej. Faltante por dar cambio incorrecto, se dejó fondo extra..." />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                         <Button v-if="!isLastUser" type="button" label="Regresar" severity="secondary" @click="view = 'confirmClose'" text class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold"></Button>
                         <Button type="submit" label="Finalizar turno" icon="pi pi-check" :loading="form.processing" severity="danger" class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold px-8 !py-3 shadow-[0_4px_10px_rgba(239,68,68,0.4)]"></Button>
                    </div>
                </form>
            </div>
        </div>
         <template #footer v-if="!isLastUser && view === 'initial'">
             <Button label="Cancelar" text severity="secondary" @click="closeModal" class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold" />
         </template>
    </Dialog>
</template>