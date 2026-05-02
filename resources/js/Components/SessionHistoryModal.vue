<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
});

const emit = defineEmits(['update:visible']);

const closeModal = () => {
    emit('update:visible', false);
};

const formatCurrency = (value) => {
    if (typeof value !== 'number') {
        value = parseFloat(value) || 0;
    }
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDateTime = (dateTimeString) => {
    if (!dateTimeString) return '';
    const options = { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(dateTimeString).toLocaleDateString('es-MX', options);
};

const formatTime = (dateTimeString) => {
    if (!dateTimeString) return '';
    const options = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
    return new Date(dateTimeString).toLocaleTimeString('es-MX', options);
}

// --- ESTADOS PARA PANELES OCULTOS ---
const showCashSummary = ref(false);
const showCardSummary = ref(false);
const showTransferSummary = ref(false);

// --- COMPUTED PARA RESUMEN DE PAGOS ---
const paymentSummary = computed(() => {
    if (!props.session || !props.session.payments) {
        return { cash: 0, card: 0, transfer: 0 };
    }

    return (props.session.payments || [])
        .filter(p => p.status === 'completado' && p.payment_method !== 'saldo') // Excluir 'saldo'
        .reduce((totals, p) => {
            const amount = parseFloat(p.amount) || 0;
            if (p.payment_method === 'efectivo') {
                totals.cash += amount;
            } else if (p.payment_method === 'tarjeta') {
                totals.card += amount;
            } else if (p.payment_method === 'transferencia') {
                totals.transfer += amount;
            }
            return totals;
        }, { cash: 0, card: 0, transfer: 0 });
});

// --- NUEVO COMPUTED PARA DESGLOSE DE EFECTIVO ---
const cashBreakdown = computed(() => {
    if (!props.session || !props.session.cash_movements) {
        return { inflows: 0, outflows: 0 };
    }

    // Suma todos los movimientos de 'ingreso'
    const inflows = (props.session.cash_movements || [])
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + (parseFloat(m.amount) || 0), 0);

    // Suma todos los movimientos de 'egreso'
    const outflows = (props.session.cash_movements || [])
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + (parseFloat(m.amount) || 0), 0);

    return { inflows, outflows };
});

// Calcula el total neto en efectivo
const totalCash = computed(() => {
    return (paymentSummary.value.cash || 0) + (cashBreakdown.value.inflows || 0) - (cashBreakdown.value.outflows || 0);
});

const timelineEvents = computed(() => {
    if (!props.session) return [];

    // --- LÓGICA DE VENTAS ---
    const salesEvents = (props.session.transactions || [])
        .filter(tx => !tx.folio.startsWith('ABONO-'))
        .map(tx => {
            const paymentsForTx = (props.session.payments || [])
                .filter(p => p && p.transaction_id === tx.id);
            const totalPaid = paymentsForTx.reduce((sum, p) => sum + parseFloat(p.amount), 0);

            // Determinar estado y color basado en tx.status
            let statusText = 'Venta (desconocido)';
            let statusColor = '#64748b'; // Gris por defecto
            let statusIcon = 'pi pi-shopping-cart'; // Icono por defecto
            let iconColor = 'ffffff'; // color de icono por defecto

            switch (tx.status) {
                case 'completado':
                    statusText = 'Venta';
                    statusColor = '#c5e0f7'; // azul
                    iconColor = '#3d5f9b';
                    break;
                case 'pendiente':
                    statusText = 'Venta (crédito / pagos)';
                    statusColor = '#ffcd87'; // naranja
                    iconColor = '#603814';
                    break;
                case 'cancelado':
                    statusText = 'Venta (cancelada)';
                    statusColor = '#ffd3d3'; // Rojo
                    iconColor = '#bf0202';
                    statusIcon = 'pi pi-times-circle'; // Icono de cancelación
                    break;
                case 'reembolsado':
                    statusText = 'Venta (reembolsada)';
                    statusColor = '#eee6ff'; // morado
                    iconColor = '#8c3de4';
                    statusIcon = 'pi pi-replay'; // Icono de reembolso/replay
                    break;
                case 'apartado':
                    statusText = 'Venta (apartada)';
                    statusColor = '#ffc9e9';
                    iconColor = '#862384';
                    statusIcon = 'pi pi-shopping-bag'; // Icono de apartado/shopping bag
                    break;
            }

            return {
                type: 'sale',
                date: tx.created_at,
                status: statusText, 
                bgColor: statusColor, 
                icon: statusIcon,   
                iconColor: iconColor,   
                data: tx,
                totalSale: parseFloat(tx.total),
                totalPaid: totalPaid,
                userName: tx.user?.name || 'N/A'
            };
        });

    // --- LÓGICA DE MOVIMIENTOS ---
    const movementEvents = (props.session.cash_movements || []).map(mv => ({
        type: 'movement',
        date: mv.created_at,
        status: mv.type === 'ingreso' ? 'Ingreso Efectivo' : 'Retiro Efectivo',
        bgColor: mv.type === 'ingreso' ? '#3b82f6' : '#ef4444',
        icon: mv.type === 'ingreso' ? 'pi pi-arrow-down-left' : 'pi pi-arrow-up-right',
        data: mv,
        userName: mv.user?.name || 'N/A'
    }));

    // --- LÓGICA DE PAGOS EXTERNOS ---
    const sessionTransactionIds = new Set((props.session.transactions || []).map(tx => tx.id));
    const paymentEvents = (props.session.payments || [])
        .filter(p => p.status === 'completado' && !sessionTransactionIds.has(p.transaction_id))
        .map(p => {
            const tx = p.transaction;
            return {
                type: 'payment',
                date: p.payment_date || p.created_at,
                status: `Pago (${p.payment_method})`,
                bgColor: '#8b5cf6',
                icon: 'pi pi-dollar',
                data: p,
                userName: tx?.user?.name || 'N/A',
                customerName: tx?.customer?.name || 'Público en general',
                folio: tx?.folio || 'N/A'
            };
        });

    // --- LÓGICA PARA ABONOS ---
    const abonoEvents = (props.session.transactions || [])
        .filter(tx => tx.folio.startsWith('ABONO-'))
        .map(tx => {
            return {
                type: 'abono',
                date: tx.created_at,
                status: 'Abono a saldo',
                bgColor: '#d3ebff',
                iconColor: '#009cdf',
                icon: 'pi pi-user-plus',
                data: tx,
                totalAbono: parseFloat(tx.total),
                userName: tx.user?.name || 'N/A',
                customerName: tx.customer?.name || 'N/A'
            };
        });

    // Combinar todos los eventos y ordenar: DE MÁS ANTIGUO A MÁS RECIENTE
    return [...salesEvents, ...movementEvents, ...paymentEvents, ...abonoEvents].sort((a, b) => new Date(a.date) - new Date(b.date));
});
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Historial de la sesión actual"
        class="w-full max-w-4xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-5 md:py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-6 md:px-8 py-6' },
            footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-4 md:py-5' }
        }">
        
        <div v-if="session" class="space-y-6">
            
            <!-- Sección de Info y Apertura -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 shadow-sm border border-blue-100 dark:border-blue-900/50">
                        <i class="pi pi-desktop !text-sm"></i>
                    </div>
                    <div>
                        <p class="text-[11px] text-gray-500 uppercase tracking-widest m-0 mb-0.5">Caja Activa</p>
                        <p class="m-0 font-bold text-sm text-gray-900 dark:text-white">{{ session.cash_register?.name }}</p>
                    </div>
                </div>
                
                <div class="flex flex-col md:flex-row gap-6 w-full md:w-auto border-t md:border-t-0 border-gray-200 dark:border-[#2a2a2a] pt-3 md:pt-0">
                    <div>
                        <p class="text-[11px] text-gray-500 uppercase tracking-widest m-0 mb-0.5">Operador</p>
                        <p class="m-0 font-medium text-xs text-gray-900 dark:text-white">{{ session.opener?.name }}</p>
                    </div>
                    <div class="md:text-right">
                        <p class="text-[11px] text-gray-500 uppercase tracking-widest m-0 mb-0.5">Fecha y hora inicio</p>
                        <p class="m-0 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ formatDateTime(session.opened_at) }}</p>
                    </div>
                </div>
            </div>

            <!-- --- RESUMEN DE INGRESOS (Paneles Colapsables Compactos) --- -->
            <div>
                <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 mb-3 flex items-center gap-2">
                    <i class="pi pi-chart-pie !text-[10px]"></i> Resumen de ingresos
                </h4>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <!-- Botón Efectivo -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden transition-all duration-300 shadow-sm">
                        <button @click="showCashSummary = !showCashSummary" class="w-full p-3 flex justify-between items-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            <span class="font-bold text-xs text-gray-900 dark:text-white flex items-center gap-2">
                                <i class="pi pi-money-bill text-green-500 !text-sm"></i> Efectivo
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-lg font-bold text-gray-900 dark:text-white">{{ formatCurrency(totalCash) }}</span>
                                <i :class="showCashSummary ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="!text-[10px] text-gray-400"></i>
                            </div>
                        </button>
                        <div v-show="showCashSummary" class="px-3 py-3 bg-white dark:bg-[#232323] border-t border-gray-100 dark:border-[#2a2a2a] space-y-2 text-xs text-gray-600 dark:text-gray-400">
                            <div class="flex justify-between items-center">
                                <span>Ventas:</span>
                                <span class="font-mono text-green-600 dark:text-green-500 font-medium text-lg">{{ formatCurrency(paymentSummary.cash) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Ingresos manuales:</span>
                                <span class="font-mono text-green-600 dark:text-green-500 font-medium text-lg">{{ formatCurrency(cashBreakdown.inflows) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Retiros:</span>
                                <span class="font-mono text-red-500 font-medium text-lg">-{{ formatCurrency(cashBreakdown.outflows) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Botón Tarjeta -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden transition-all duration-300 shadow-sm">
                        <button @click="showCardSummary = !showCardSummary" class="w-full p-3 flex justify-between items-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            <span class="font-bold text-xs text-gray-900 dark:text-white flex items-center gap-2">
                                <i class="pi pi-credit-card text-blue-500 !text-lg"></i> Tarjeta
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-lg font-bold text-gray-900 dark:text-white">{{ formatCurrency(paymentSummary.card) }}</span>
                                <i :class="showCardSummary ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="!text-[10px] text-gray-400"></i>
                            </div>
                        </button>
                        <div v-show="showCardSummary" class="px-3 py-3 bg-white dark:bg-[#232323] border-t border-gray-100 dark:border-[#2a2a2a] text-xs text-gray-600 dark:text-gray-400">
                            <div class="flex justify-between items-center">
                                <span>Total Digital:</span>
                                <span class="font-mono text-blue-600 dark:text-blue-500 font-medium text-lg">{{ formatCurrency(paymentSummary.card) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Botón Transferencia -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden transition-all duration-300 shadow-sm">
                        <button @click="showTransferSummary = !showTransferSummary" class="w-full p-3 flex justify-between items-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            <span class="font-bold text-xs text-gray-900 dark:text-white flex items-center gap-2">
                                <i class="pi pi-arrows-h text-orange-500 !text-sm"></i> Transf. / SPEI
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-lg font-bold text-gray-900 dark:text-white">{{ formatCurrency(paymentSummary.transfer) }}</span>
                                <i :class="showTransferSummary ? 'pi pi-angle-up' : 'pi pi-angle-down'" class="!text-[10px] text-gray-400"></i>
                            </div>
                        </button>
                        <div v-show="showTransferSummary" class="px-3 py-3 bg-white dark:bg-[#232323] border-t border-gray-100 dark:border-[#2a2a2a] text-xs text-gray-600 dark:text-gray-400">
                            <div class="flex justify-between items-center">
                                <span>Total Digital:</span>
                                <span class="font-mono text-orange-600 dark:text-orange-500 font-medium text-lg">{{ formatCurrency(paymentSummary.transfer) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historial Mosaico de 2 Columnas (Logs Ultracompactos y Cronológicos) -->
            <div>
                <div class="flex justify-between items-center mb-3">
                    <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 flex items-center gap-2">
                        <i class="pi pi-list !text-[10px]"></i> Registro de actividad (Cronológico)
                    </h4>
                    <span class="text-[11px] text-gray-500 bg-gray-100 dark:bg-[#1a1a1a] px-2 py-1 rounded-md border border-gray-200 dark:border-[#3a3a3a] flex items-center gap-1">
                        Más antiguo <i class="pi pi-arrow-right !text-[10px]"></i> Más reciente
                    </span>
                </div>
                
                <div class="max-h-[50vh] overflow-y-auto custom-scrollbar pr-3 pb-4">
                    <div v-if="timelineEvents.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 relative">
                        
                        <div v-for="(item, index) in timelineEvents" :key="index" class="relative group flex flex-col">
                            
                            <!-- Flecha conectora Horizontal (Solo Desktop, de Par a Impar: 0->1, 2->3) -->
                            <div v-if="index % 2 === 0 && index < timelineEvents.length - 1" class="hidden md:flex absolute top-1/2 -right-6 w-6 items-center justify-center text-gray-400 dark:text-[#4a4a4a] z-0">
                                <i class="pi pi-arrow-right !text-xs"></i>
                            </div>

                            <!-- Tarjeta de Log -->
                            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] h-full flex flex-col gap-2 transition-colors hover:border-gray-300 dark:hover:border-gray-500 relative z-10 shadow-sm">
                                
                                <!-- Cabecera del log -->
                                <div class="flex justify-between items-start pb-2 border-b border-gray-200 dark:border-[#2a2a2a]">
                                    <div class="flex items-center gap-2">
                                        <div class="flex w-6 h-6 items-center justify-center rounded-full shadow-sm border border-black/5 dark:border-white/10"
                                            :style="{ backgroundColor: item.bgColor, color: item.iconColor || '#ffffff' }">
                                            <i :class="item.icon" class="!text-[11px]"></i>
                                        </div>
                                        <span class="font-bold text-[12px] text-gray-900 dark:text-white leading-none">{{ item.status }}</span>
                                    </div>
                                    <span class="text-[10px] uppercase tracking-widest text-gray-500 bg-white dark:bg-[#232323] px-1.5 py-0.5 rounded border border-gray-200 dark:border-[#3a3a3a] flex items-center gap-1">
                                        <i class="pi pi-clock !text-[7px]"></i> {{ formatTime(item.date) }}
                                    </span>
                                </div>
                                
                                <!-- Cuerpo del log -->
                                <div class="flex flex-col gap-1.5 flex-grow">
                                    <!-- Metadatos unificados -->
                                    <div class="text-[11px] text-gray-500 flex flex-wrap items-center gap-1.5">
                                        <span v-if="item.data.folio || item.folio" class="font-mono text-gray-900 dark:text-gray-200 font-bold bg-gray-200/50 dark:bg-[#2a2a2a] px-1.5 py-0.5 rounded">
                                            {{ item.data.folio || item.folio }}
                                        </span>
                                        
                                        <span v-if="item.customerName || item.data.customer" class="flex items-center gap-1 truncate max-w-[120px]" :title="item.customerName || item.data.customer?.name || 'Público general'">
                                            <i class="pi pi-user !text-[10px]"></i> {{ item.customerName || item.data.customer?.name || 'Público general' }}
                                        </span>
                                        
                                        <span class="flex items-center gap-1 truncate max-w-[90px] ml-auto bg-white dark:bg-[#232323] px-1.5 py-0.5 rounded-md border border-gray-100 dark:border-[#2a2a2a]" :title="item.userName">
                                            <i class="pi pi-user-edit !text-[10px]"></i> {{ item.userName }}
                                        </span>
                                    </div>

                                    <p v-if="item.type === 'movement'" class="text-[11px] text-gray-600 dark:text-gray-400 italic m-0 line-clamp-1">"{{ item.data.description }}"</p>
                                </div>
                                
                                <!-- Montos (Pie de tarjeta alineado a la derecha) -->
                                <div class="flex justify-end items-end pt-1">
                                    <span v-if="item.type === 'sale'" class="font-mono font-bold text-lg text-gray-900 dark:text-white">{{ formatCurrency(item.totalPaid) }}</span>
                                    <span v-else-if="item.type === 'abono'" class="font-mono font-bold text-lg text-blue-600 dark:text-blue-500">+ {{ formatCurrency(item.totalAbono) }}</span>
                                    <span v-else-if="item.type === 'movement'" class="font-mono font-bold text-lg" :class="item.data.type === 'ingreso' ? 'text-blue-600 dark:text-blue-500' : 'text-red-600 dark:text-red-500'">
                                        {{ item.data.type === 'ingreso' ? '+' : '-' }}{{ formatCurrency(item.data.amount) }}
                                    </span>
                                    <span v-else-if="item.type === 'payment'" class="font-mono font-bold text-lg" :class="item.data.amount >= 0 ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500'">
                                        {{ formatCurrency(item.data.amount) }}
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Flecha conectora Vertical (Solo Mobile, hacia la siguiente tarjeta inferior) -->
                            <div v-if="index < timelineEvents.length - 1" class="md:hidden flex justify-center -mb-2 mt-1 z-0 text-gray-300 dark:text-[#4a4a4a]">
                                <i class="pi pi-arrow-down !text-[10px]"></i>
                            </div>
                        </div>

                    </div>
                    
                    <div v-else class="flex flex-col items-center justify-center text-center py-12 opacity-60">
                        <i class="pi pi-history text-3xl text-gray-400 mb-3"></i>
                        <p class="text-sm text-gray-500 m-0">No hay transacciones ni movimientos en esta sesión.</p>
                    </div>
                </div>
            </div>
        </div>
        
        <template #footer>
            <div class="flex justify-end w-full">
                <Button label="Cerrar" icon="pi pi-times" @click="closeModal" severity="secondary" class="!rounded-xl !uppercase !tracking-widest !text-[12px] !font-bold" />
            </div>
        </template>
    </Dialog>
</template>