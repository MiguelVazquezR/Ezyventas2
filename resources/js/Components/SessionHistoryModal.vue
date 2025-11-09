<script setup>
import { computed } from 'vue';

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

// --- COMPUTED PARA RESUMEN DE PAGOS (Sin cambios) ---
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

// --- INICIO: NUEVO COMPUTED PARA DESGLOSE DE EFECTIVO ---
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
// --- FIN: NUEVO COMPUTED PARA DESGLOSE DE EFECTIVO ---


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
                    statusText = 'Venta (credito / pagos)';
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
                status: statusText, // Texto corregido
                bgColor: statusColor, // Color corregido
                icon: statusIcon,   // Icono corregido/añadido
                iconColor: iconColor,   // Icono corregido/añadido
                data: tx,
                totalSale: parseFloat(tx.total),
                totalPaid: totalPaid,
                userName: tx.user?.name || 'N/A'
            };
        });

    // --- LÓGICA DE MOVIMIENTOS (Sin cambios) ---
    const movementEvents = (props.session.cash_movements || []).map(mv => ({
        type: 'movement',
        date: mv.created_at,
        status: mv.type === 'ingreso' ? 'Ingreso de efectivo' : 'Retiro de efectivo',
        color: mv.type === 'ingreso' ? '#3b82f6' : '#ef4444',
        icon: mv.type === 'ingreso' ? 'pi pi-arrow-down-left' : 'pi pi-arrow-up-right',
        data: mv,
        userName: mv.user?.name || 'N/A'
    }));

    // --- LÓGICA DE PAGOS EXTERNOS (Sin cambios) ---
    const sessionTransactionIds = new Set((props.session.transactions || []).map(tx => tx.id));
    const paymentEvents = (props.session.payments || [])
        .filter(p => p.status === 'completado' && !sessionTransactionIds.has(p.transaction_id))
        .map(p => {
            const tx = p.transaction;
            return {
                type: 'payment',
                date: p.payment_date || p.created_at,
                status: `Pago (${p.payment_method})`,
                color: '#8b5cf6',
                icon: 'pi pi-dollar',
                data: p,
                userName: tx?.user?.name || 'N/A',
                customerName: tx?.customer?.name || 'Público en general',
                folio: tx?.folio || 'N/A'
            };
        });

    // --- LÓGICA PARA ABONOS (Sin cambios) ---
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

    // Combinar todos los eventos y ordenar
    return [...salesEvents, ...movementEvents, ...paymentEvents, ...abonoEvents].sort((a, b) => new Date(b.date) - new Date(a.date));
});

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Historial de la sesión actual"
        :style="{ width: '50rem' }">
        <div v-if="session" class="p-1">
            <!-- Sección de Info y Apertura (Sin cambios) -->
            <div class="bg-gray-100 dark:bg-gray-800 p-3 rounded-lg mb-1">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="m-0 text-sm text-gray-500">Caja</p>
                        <p class="m-0 font-bold text-base">{{ session.cash_register?.name }}</p>
                    </div>
                    <div>
                        <p class="m-0 text-sm text-gray-500">Abierta por</p>
                        <p class="m-0 font-bold text-base">{{ session.opener?.name }}</p>
                    </div>
                    <div class="text-right">
                        <p class="m-0 text-sm text-gray-500">Fecha de Apertura</p>
                        <p class="m-0 font-bold text-base">{{ formatDateTime(session.opened_at) }}</p>
                    </div>
                </div>
            </div>

            <!-- --- INICIO: RESUMEN DE PAGOS (Convertido a Fieldset colapsable) --- -->
            <Fieldset legend="Resumen de ingresos" :toggleable="true" class="text-sm">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Columna de Efectivo -->
                    <div>
                        <h6 class="font-medium text-gray-800 dark:text-gray-200 m-0">Efectivo</h6>
                        <dl class="text-gray-600 dark:text-gray-400">
                            <div class="flex justify-between">
                                <dt>Ventas:</dt>
                                <dd class="font-mono text-green-500">{{ formatCurrency(paymentSummary.cash) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Ingresos:</dt>
                                <dd class="font-mono text-green-500">{{ formatCurrency(cashBreakdown.inflows) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Retiros:</dt>
                                <dd class="font-mono text-red-500">-{{ formatCurrency(cashBreakdown.outflows) }}</dd>
                            </div>
                            <div
                                class="flex justify-between font-bold text-gray-900 dark:text-white border-t mt-1 pt-1">
                                <dt>Total efectivo:</dt>
                                <dd class="font-mono">{{ formatCurrency(totalCash) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Columna de Tarjeta -->
                    <div>
                        <h6 class="font-medium text-gray-800 dark:text-gray-200 m-0">Tarjeta</h6>
                        <dl class="text-gray-600 dark:text-gray-400">
                            <div class="flex justify-between font-bold text-gray-900 dark:text-white">
                                <dd class="font-mono">{{ formatCurrency(paymentSummary.card) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Columna de Transferencia -->
                    <div>
                        <h6 class="font-medium text-gray-800 dark:text-gray-200 m-0">Transferencia</h6>
                        <dl class="text-gray-600 dark:text-gray-400">
                            <div class="flex justify-between font-bold text-gray-900 dark:text-white">
                                <dd class="font-mono">{{ formatCurrency(paymentSummary.transfer) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </Fieldset>
            <!-- --- FIN: RESUMEN DE PAGOS --- -->

            <!-- Historial de Timeline -->
            <div class="max-h-[51vh] overflow-y-auto pr-2 mt-3">
                <Timeline v-if="timelineEvents.length > 0" :value="timelineEvents" align="alternate"
                    class="customized-timeline">
                    <template #marker="slotProps">
                        <span class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                            :style="{ backgroundColor: slotProps.item.bgColor, color: slotProps.item.iconColor || '#ffffff' }">
                            <i :class="slotProps.item.icon"></i>
                        </span>
                    </template>
                    <template #content="slotProps">
                        <Card class="mt-0 mb-4">
                            <template #title>
                                <div class="flex justify-between items-center text-base">
                                    <span>{{ slotProps.item.status }}</span>
                                    <span class="font-normal text-sm">{{ formatTime(slotProps.item.date) }}</span>
                                </div>
                            </template>
                            <template #content>
                                <!-- Contenido para Ventas (type === 'sale') -->
                                <div v-if="slotProps.item.type === 'sale'" class="text-sm space-y-1">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Folio:</span>
                                        <span class="font-mono">{{ slotProps.item.data.folio }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Cliente:</span>
                                        <span class="font-semibold">
                                            {{
                                                slotProps.item.data.customer?.name || 'Público en general'
                                            }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Cajero:</span>
                                        <span class="font-semibold">{{ slotProps.item.userName }}</span>
                                    </div>
                                    <div class="pt-2 border-t mt-2 space-y-1">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Total Venta:</span>
                                            <span class="font-semibold">{{ formatCurrency(slotProps.item.totalSale)
                                            }}</span>
                                        </div>
                                        <div class="flex justify-between font-bold">
                                            <span>Total Pagado:</span>
                                            <span>{{ formatCurrency(slotProps.item.totalPaid) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contenido para Movimientos (type === 'movement') -->
                                <div v-if="slotProps.item.type === 'movement'" class="text-sm space-y-1">
                                    <div class="flex justify-between mb-2">
                                        <span class="text-gray-500">Realizado por:</span>
                                        <span class="font-semibold">{{ slotProps.item.userName }}</span>
                                    </div>
                                    <p class="text-gray-600 italic">"{{ slotProps.item.data.description }}"</p>
                                    <div class="flex justify-between font-bold text-base pt-2 border-t mt-2">
                                        <span>Monto:</span>
                                        <span
                                            :class="slotProps.item.data.type === 'ingreso' ? 'text-blue-500' : 'text-red-500'">
                                            {{ formatCurrency(slotProps.item.data.amount) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Contenido para Pagos Externos (type === 'payment') -->
                                <div v-if="slotProps.item.type === 'payment'" class="text-sm space-y-1">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Folio O.S.:</span>
                                        <span class="font-mono">{{ slotProps.item.folio }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Cliente:</span>
                                        <span class="font-semibold">{{ slotProps.item.customerName }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Registró O.S.:</span>
                                        <span class="font-semibold">{{ slotProps.item.userName }}</span>
                                    </div>
                                    <div class="pt-2 border-t mt-2 space-y-1">
                                        <div class="flex justify-between font-bold text-base">
                                            <span>Monto Pagado:</span>
                                            <span
                                                :class="slotProps.item.data.amount >= 0 ? 'text-green-500' : 'text-red-500'">
                                                {{ formatCurrency(slotProps.item.data.amount) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- --- INICIO: PLANTILLA PARA ABONOS (Simplificada) --- -->
                                <div v-if="slotProps.item.type === 'abono'" class="text-sm space-y-1">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Folio:</span>
                                        <span class="font-mono">{{ slotProps.item.data.folio }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Cliente:</span>
                                        <span class="font-semibold">{{ slotProps.item.customerName }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Cajero:</span>
                                        <span class="font-semibold">{{ slotProps.item.userName }}</span>
                                    </div>
                                    <div class="pt-2 border-t mt-2 space-y-1">
                                        <div class="flex justify-between font-bold text-base">
                                            <span>Monto Abonado:</span>
                                            <span class="text-green-500">
                                                {{ formatCurrency(slotProps.item.totalAbono) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <!-- --- FIN: PLANTILLA PARA ABONOS --- -->

                            </template>
                        </Card>
                    </template>
                </Timeline>
                <div v-else class="text-center py-12 text-gray-500">
                    <i class="pi pi-history !text-4xl mb-3"></i>
                    <p>No hay transacciones ni movimientos en esta sesión.</p>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Cerrar" icon="pi pi-times" @click="closeModal" text severity="secondary" />
        </template>
    </Dialog>
</template>