<script setup>
import { computed } from 'vue';
import { format } from 'date-fns';

const props = defineProps({
    detailedTransactions: Array,
    detailedPayments: Array,
    detailedExpenses: Array,
    paymentMethods: { type: Array, default: () => [] },
    expensesByMethod: { type: Object, default: () => ({ internal: [], external: [] }) },
});

const isSalesVisible = defineModel('isSalesVisible', { type: Boolean });
const isPaymentsVisible = defineModel('isPaymentsVisible', { type: Boolean });
const isExpensesVisible = defineModel('isExpensesVisible', { type: Boolean });
const isHelpVisible = defineModel('isHelpVisible', { type: Boolean });

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => format(new Date(dateString), 'dd/MM/yyyy');
const formatDateTime = (dateString) => format(new Date(dateString), 'dd/MM/yyyy HH:mm');

// Totals by money origin for the expenses modal. Derived from the backend
// aggregates (expensesByMethod), which have no row limit, so they always
// match the KPI values even when the detail list is truncated.
const internalExpensesTotal = computed(() => {
    return (props.expensesByMethod.internal || [])
        .reduce((sum, item) => sum + parseFloat(item.total), 0);
});

const externalExpensesTotal = computed(() => {
    return (props.expensesByMethod.external || [])
        .reduce((sum, item) => sum + parseFloat(item.total), 0);
});

const getOriginLabel = (expense) => {
    if (expense.is_external) {
        return { label: 'Dinero externo', icon: 'pi pi-wallet', severity: 'info', tooltip: 'Gasto con dinero externo. No afecta tu flujo de dinero.' };
    }
    if (expense.payment_method === 'efectivo') {
        return { label: 'Caja del negocio', icon: 'pi pi-inbox', severity: 'success', tooltip: 'Gasto con efectivo de la caja del negocio.' };
    }
    if (expense.bank_account) {
        return { label: 'Cuenta del negocio', icon: 'pi pi-building', severity: 'success', tooltip: 'Gasto con cuenta bancaria del negocio.' };
    }
    return { label: 'Cuenta del negocio', icon: 'pi pi-building', severity: 'success', tooltip: 'Gasto con cuenta bancaria del negocio.' };
};

const getPaymentMethodDetails = (method) => {
    const details = {
        efectivo: {
            name: 'Efectivo', icon: 'pi pi-money-bill', color: 'bg-green-500', textColor: 'text-green-500',
            cardClass: 'bg-green-50 dark:bg-green-900/10 border-green-100 dark:border-green-900/30'
        },
        tarjeta: {
            name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'bg-blue-500', textColor: 'text-blue-500',
            cardClass: 'bg-blue-50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/30'
        },
        transferencia: {
            name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'bg-orange-400', textColor: 'text-orange-400',
            cardClass: 'bg-orange-50 dark:bg-orange-900/10 border-orange-100 dark:border-orange-900/30'
        },
        saldo: {
            name: 'Saldo a favor', icon: 'pi pi-wallet', color: 'bg-purple-500', textColor: 'text-purple-500',
            cardClass: 'bg-purple-50 dark:bg-purple-900/10 border-purple-100 dark:border-purple-900/30'
        },
        default: {
            name: method || 'Otro', icon: 'pi pi-question-circle', color: 'bg-gray-500', textColor: 'text-gray-500',
            cardClass: 'bg-gray-50 dark:bg-[#1a1a1a] border-gray-100 dark:border-[#3a3a3a]'
        }
    };
    return details[method] || details.default;
};

// Ordena y enriquece los totales agrupados por método de pago
const orderPaymentMethodTotals = (totals) => {
    const preferredOrder = ['efectivo', 'tarjeta', 'transferencia'];

    return Object.entries(totals)
        .map(([method, total]) => ({ method, total, ...getPaymentMethodDetails(method) }))
        .sort((a, b) => {
            const aIndex = preferredOrder.indexOf(a.method);
            const bIndex = preferredOrder.indexOf(b.method);
            if (aIndex !== -1 && bIndex !== -1) return aIndex - bIndex;
            if (aIndex !== -1) return -1;
            if (bIndex !== -1) return 1;
            return b.total - a.total;
        });
};

// Totales por método de pago para el modal de pagos
const paymentMethodTotals = computed(() => {
    const totals = (props.paymentMethods || []).reduce((acc, payment) => {
        const method = payment.method || 'default';
        acc[method] = (acc[method] || 0) + parseFloat(payment.total);
        return acc;
    }, {});

    return orderPaymentMethodTotals(totals);
});

// Breakdown by payment method and money origin for the expenses modal.
// Uses the backend aggregates (no row limit) instead of the detailed list
// (truncated to 1000 rows) so the totals always match the KPIs.
const expensesByMethod = computed(() => {
    const toTotalsObject = (items) => {
        const totals = {};
        (items || []).forEach((item) => {
            const method = item.method || 'default';
            totals[method] = (totals[method] || 0) + parseFloat(item.total);
        });
        return totals;
    };

    return {
        internal: orderPaymentMethodTotals(toTotalsObject(props.expensesByMethod.internal)),
        external: orderPaymentMethodTotals(toTotalsObject(props.expensesByMethod.external)),
    };
});

const getChannelDetails = (channel) => {
    const details = {
        punto_de_venta: { name: 'Punto de venta', icon: 'pi pi-shopping-cart', verb: 'Ventas realizadas' },
        tienda_en_linea: { name: 'Tienda en línea', icon: 'pi pi-mobile', verb: 'Ventas realizadas' },
        orden_de_servicio: { name: 'Orden de servicio', icon: 'pi pi-wrench', verb: 'Órdenes completadas' },
        cotizacion: { name: 'Cotización', icon: 'pi pi-file', verb: 'Cotizaciones aceptadas' },
        manual: { name: 'Manual', icon: 'pi pi-pencil', verb: 'Ventas registradas' },
        abono_a_saldo: { name: 'Abono a saldo', icon: 'pi pi-wallet', verb: 'Abonos recibidos' }
    };
    return details[channel] || { name: channel || 'Desconocido', icon: 'pi pi-question-circle', verb: 'Transacciones' };
};

const getTransactionStatusTagSeverity = (status) => {
    switch (status) {
        case 'completada': return 'success';
        case 'pendiente': return 'warn';
        case 'cancelada': return 'danger';
        case 'reembolsada': return 'info';
        default: return 'secondary';
    }
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } }
};

const accordionPt = {
    root: { class: 'space-y-4' },
    panel: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] overflow-hidden' },
    header: { class: 'bg-transparent dark:text-white' },
    headerAction: { class: 'p-5 hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors flex items-center justify-between outline-none focus:ring-0 text-sm font-medium dark:text-gray-200' },
    content: { class: 'p-5 pt-0 bg-transparent dark:text-gray-400' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <!-- MODAL: Detalle de Ventas -->
    <Dialog v-model:visible="isSalesVisible" header="Detalle de ventas del periodo" modal class="w-full max-w-5xl mx-4" :pt="dialogPt">
        <DataTable :value="detailedTransactions" paginator :rows="15" sortMode="multiple"
            :multiSortMeta="[{ field: 'created_at', order: -1 }]" responsiveLayout="scroll" :pt="dataTablePt">
            <Column field="folio" header="Folio" sortable>
                <template #body="{ data }"> <span class="font-mono text-base dark:text-gray-400">{{ data.folio }}</span> </template>
            </Column>
            <Column field="created_at" header="Fecha" sortable> 
                <template #body="{ data }"> <span class="text-base">{{ formatDateTime(data.created_at) }}</span> </template> 
            </Column>
            <Column field="customer.name" header="Cliente" sortable> 
                <template #body="{ data }"> <span class="font-medium">{{ data.customer?.name || 'Público general' }}</span> </template> 
            </Column>
            <Column field="channel" header="Canal" sortable> 
                <template #body="{ data }">
                    <div class="flex items-center gap-2">
                        <i :class="getChannelDetails(data.channel).icon" class="!text-[10px] text-gray-400"></i>
                        <span class="text-base">{{ getChannelDetails(data.channel).name }}</span>
                    </div>
                </template> 
            </Column>
            <Column field="total" header="Total" sortable> 
                <template #body="{ data }"> <span class="text-lg font-light tracking-tight dark:text-white">{{ formatCurrency(data.total) }}</span> </template> 
            </Column>
            <Column field="status" header="Estado" sortable> 
                <template #body="{ data }"> <Tag :value="data.status" :severity="getTransactionStatusTagSeverity(data.status)" :pt="tagPt" /> </template> 
            </Column>
            <template #empty>
                <div class="py-10 flex flex-col items-center justify-center text-center">
                    <i class="pi pi-inbox !text-3xl text-gray-400 mb-3"></i>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin registros</p>
                    <p class="text-xs text-gray-400 mt-1">No hay ventas registradas en este periodo.</p>
                </div>
            </template>
        </DataTable>
    </Dialog>

    <!-- MODAL: Detalle de Pagos -->
    <Dialog v-model:visible="isPaymentsVisible" header="Detalle de pagos recibidos" modal class="w-full max-w-5xl mx-4" :pt="dialogPt">
        <!-- Indicadores: total por método de pago -->
        <div v-if="paymentMethodTotals.length > 0" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
            <div v-for="item in paymentMethodTotals" :key="item.method"
                class="border rounded-2xl p-5 flex flex-col gap-2"
                :class="item.cardClass">
                <div class="flex items-center gap-2">
                    <i :class="`${item.icon} ${item.textColor} !text-sm`"></i>
                    <span class="text-[10px] uppercase tracking-widest font-bold m-0" :class="item.textColor">{{ item.name }}</span>
                </div>
                <p class="text-3xl font-light tracking-tight m-0" :class="item.textColor">{{ formatCurrency(item.total) }}</p>
            </div>
        </div>

        <DataTable :value="detailedPayments" paginator :rows="15" sortMode="multiple"
            :multiSortMeta="[{ field: 'payment_date', order: -1 }]" responsiveLayout="scroll" :pt="dataTablePt">
            <Column field="payment_date" header="Fecha" sortable> 
                <template #body="{ data }"> <span class="text-base">{{ formatDateTime(data.payment_date) }}</span> </template> 
            </Column>
            <Column field="transaction.folio" header="Venta folio" sortable>
                <template #body="{ data }"> <span class="font-mono text-base dark:text-gray-400">{{ data.transaction?.folio }}</span> </template>
            </Column>
            <Column field="transaction.customer.name" header="Cliente" sortable> 
                <template #body="{ data }"> <span class="font-medium">{{ data.transaction?.customer?.name || 'Público general' }}</span> </template> 
            </Column>
            <Column field="payment_method" header="Método" sortable>
                <template #body="{ data }">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2"> 
                            <i :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor} !text-base`"></i>
                            <span class="text-base">{{ getPaymentMethodDetails(data.payment_method).name }}</span> 
                        </div>
                        <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account"
                            class="text-[10px] text-gray-500 dark:text-gray-500 uppercase tracking-widest flex items-center gap-1" v-tooltip.bottom="`${data.bank_account.bank_name}`"> 
                            <i class="pi pi-building !text-[9px]"></i> {{ data.bank_account.account_name }} 
                        </div>
                    </div>
                </template>
            </Column>
            <Column field="amount" header="Monto" sortable> 
                <template #body="{ data }"> <span class="text-lg font-light tracking-tight dark:text-white">{{ formatCurrency(data.amount) }}</span> </template> 
            </Column>
            <template #empty>
                <div class="py-10 flex flex-col items-center justify-center text-center">
                    <i class="pi pi-wallet !text-3xl text-gray-400 mb-3"></i>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin ingresos</p>
                    <p class="text-xs text-gray-400 mt-1">No hay pagos registrados en este periodo.</p>
                </div>
            </template>
        </DataTable>
    </Dialog>

    <!-- MODAL: Detalle de Gastos Totales -->
    <Dialog v-model:visible="isExpensesVisible" header="Detalle de gastos totales" modal class="w-full max-w-5xl mx-4" :pt="dialogPt">
        <!-- Resumen por origen del dinero -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/30 rounded-2xl p-5">
                <div class="flex items-center gap-2 mb-2">
                    <i class="pi pi-building !text-sm text-green-600 dark:text-green-400"></i>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-green-700 dark:text-green-400 m-0">Gastos de dinero del negocio</span>
                </div>
                <p class="text-3xl font-light tracking-tight text-green-700 dark:text-green-300 m-0 mb-3">{{ formatCurrency(internalExpensesTotal) }}</p>
                <div v-if="expensesByMethod.internal.length > 0" class="border-t border-green-200 dark:border-green-900/30 pt-3 space-y-2">
                    <div v-for="item in expensesByMethod.internal" :key="item.method" class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <i :class="`${item.icon} ${item.textColor} !text-xs`"></i>
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-600 dark:text-gray-400 m-0">{{ item.name }}</span>
                        </div>
                        <span class="text-sm font-medium tracking-tight text-gray-900 dark:text-white whitespace-nowrap">{{ formatCurrency(item.total) }}</span>
                    </div>
                </div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-2xl p-5">
                <div class="flex items-center gap-2 mb-2">
                    <i class="pi pi-wallet !text-sm text-blue-600 dark:text-blue-400"></i>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-blue-700 dark:text-blue-400 m-0">Gastos de dinero externo</span>
                </div>
                <p class="text-3xl font-light tracking-tight text-blue-700 dark:text-blue-300 m-0 mb-3">{{ formatCurrency(externalExpensesTotal) }}</p>
                <div v-if="expensesByMethod.external.length > 0" class="border-t border-blue-200 dark:border-blue-900/30 pt-3 space-y-2">
                    <div v-for="item in expensesByMethod.external" :key="item.method" class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <i :class="`${item.icon} ${item.textColor} !text-xs`"></i>
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-600 dark:text-gray-400 m-0">{{ item.name }}</span>
                        </div>
                        <span class="text-sm font-medium tracking-tight text-gray-900 dark:text-white whitespace-nowrap">{{ formatCurrency(item.total) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <DataTable :value="detailedExpenses" paginator :rows="10" sortMode="multiple"
            :multiSortMeta="[{ field: 'expense_date', order: -1 }]" responsiveLayout="scroll" :pt="dataTablePt">
            <Column field="folio" header="Folio" sortable>
                <template #body="{ data }"> <span class="font-mono text-base dark:text-gray-400">{{ data.folio }}</span> </template>
            </Column>
            <Column field="expense_date" header="Fecha" sortable> 
                <template #body="{ data }"> <span class="text-base">{{ formatDate(data.expense_date) }}</span> </template> 
            </Column>
            <Column field="category.name" header="Categoría" sortable>
                <template #body="{ data }"> <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">{{ data.category?.name }}</span> </template>
            </Column>
            <Column field="description" header="Descripción" style="max-width: 120px">
                <template #body="{ data }"> <span class="text-base dark:text-gray-300 truncate block max-w-[120px]" :title="data.description">{{ data.description }}</span> </template>
            </Column>
            <Column field="payment_method" header="Método de pago" sortable>
                <template #body="{ data }">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2"> 
                            <i :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor} !text-base`"></i>
                            <span class="text-base">{{ getPaymentMethodDetails(data.payment_method).name }}</span> 
                        </div>
                        <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account"
                            class="text-[10px] text-gray-500 dark:text-gray-500 uppercase tracking-widest flex items-center gap-1" v-tooltip.bottom="`${data.bank_account.bank_name}`"> 
                            <i class="pi pi-building !text-[9px]"></i> {{ data.bank_account.account_name }} 
                        </div>
                    </div>
                </template>
            </Column>
            <Column field="is_external" header="Origen de dinero" sortable>
                <template #body="{ data }">
                    <Tag :value="getOriginLabel(data).label" :severity="getOriginLabel(data).severity" :pt="tagPt" class="capitalize" v-tooltip.top="getOriginLabel(data).tooltip">
                        <i :class="getOriginLabel(data).icon" class="mr-1"></i>
                    </Tag>
                </template>
            </Column>
            <Column field="amount" header="Monto" sortable> 
                <template #body="{ data }"> <span class="text-lg font-light tracking-tight dark:text-white">{{ formatCurrency(data.amount) }}</span> </template> 
            </Column>
            <template #empty>
                <div class="py-10 flex flex-col items-center justify-center text-center">
                    <i class="pi pi-receipt !text-3xl text-gray-400 mb-3"></i>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin egresos</p>
                    <p class="text-xs text-gray-400 mt-1">No hay gastos registrados en este periodo.</p>
                </div>
            </template>
        </DataTable>
    </Dialog>

    <!-- MODAL DE AYUDA -->
    <Dialog v-model:visible="isHelpVisible" header="Glosario de métricas financieras" modal class="w-full max-w-3xl mx-4" :pt="dialogPt">
        <Accordion value="0" :pt="accordionPt">
            
            <AccordionPanel value="0">
                <AccordionHeader>Ganancia neta</AccordionHeader>
                <AccordionContent>
                    <div class="space-y-4 text-sm leading-relaxed">
                        <p class="m-0 dark:text-gray-300"> Mide la <span class="font-bold text-gray-900 dark:text-white">rentabilidad</span> de tu negocio después de restar todos los gastos de tus ventas totales. </p>
                        <div class="bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 text-center">
                            <p class="font-mono text-sm tracking-tight text-teal-600 dark:text-teal-400 m-0">
                                (Ventas totales) - (Total de gastos)
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Utilidad para el negocio</p>
                            <p class="m-0 mb-2">Responde a la pregunta: <span class="font-medium text-gray-900 dark:text-white">"¿Mi negocio es rentable?"</span>.</p>
                            <ul class="list-disc pl-5 m-0 space-y-1 dark:text-gray-400">
                                <li> Te dice si tus precios de venta son suficientes para cubrir tus costos operativos y aún dejar un margen de ganancia. </li>
                                <li> <span class="font-bold text-gray-900 dark:text-white">Importante:</span> Se basa en las <span class="text-[10px] font-bold uppercase tracking-widest text-purple-500">Ventas</span>, no en los pagos. Una venta a crédito cuenta aquí, aunque no hayas recibido el dinero. </li>
                            </ul>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>
            
            <AccordionPanel value="3">
                <AccordionHeader>% Margen de utilidad</AccordionHeader>
                <AccordionContent>
                    <div class="space-y-4 text-sm leading-relaxed">
                        <p class="m-0 dark:text-gray-300"> Indica qué porcentaje de tus ventas se convierte en ganancia real. </p>
                        <div class="bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 text-center">
                            <p class="font-mono text-sm tracking-tight text-orange-600 dark:text-orange-400 m-0">
                                (Ganancia neta / Ventas totales) * 100
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Ejemplo</p>
                            <p class="m-0"> Si vendes $1,000 y gastas $800, tu ganancia es $200. Tu margen es del <span class="font-bold text-gray-900 dark:text-white">20%</span>. Significa que de cada $1 peso que vendes, te quedas con 20 centavos de ganancia. </p>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>

            <AccordionPanel value="1">
                <AccordionHeader>Flujo de dinero neto</AccordionHeader>
                <AccordionContent>
                    <div class="space-y-4 text-sm leading-relaxed">
                        <p class="m-0 dark:text-gray-300"> Mide la <span class="font-bold text-gray-900 dark:text-white">liquidez</span> real de tu negocio. Es la cantidad de dinero que entró y salió. </p>
                        <div class="bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 text-center">
                            <p class="font-mono text-sm tracking-tight text-green-600 dark:text-green-400 m-0">
                                (Total de pagos recibidos) - (Total de gastos pagados)
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Utilidad para el negocio</p>
                            <p class="m-0 mb-2">Responde a la pregunta: <span class="font-medium text-gray-900 dark:text-white">"¿Tengo dinero para operar y pagar mis cuentas?"</span>.</p>
                            <ul class="list-disc pl-5 m-0 space-y-1 dark:text-gray-400">
                                <li> Un negocio puede ser "rentable" (Ganancia neta positiva) pero quebrar por falta de liquidez (Flujo de dinero negativo) si los clientes no pagan a tiempo. </li>
                                <li> Este indicador es vital para la operación diaria. Te aseguras de tener efectivo en tus cuentas bancarias. </li>
                            </ul>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>

            <AccordionPanel value="2">
                <AccordionHeader>Ticket promedio</AccordionHeader>
                <AccordionContent>
                    <div class="space-y-4 text-sm leading-relaxed">
                        <p class="m-0 dark:text-gray-300"> Mide cuánto gasta un cliente en promedio en cada transacción que realiza. </p>
                        <div class="bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 text-center">
                            <p class="font-mono text-sm tracking-tight text-blue-600 dark:text-blue-400 m-0">
                                (Ventas totales) / (Número total de ventas)
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Utilidad para el negocio</p>
                            <p class="m-0 mb-2">Responde a la pregunta: <span class="font-medium text-gray-900 dark:text-white">"¿Cuánto gastan mis clientes en promedio por compra?"</span>.</p>
                            <ul class="list-disc pl-5 m-0 space-y-1 dark:text-gray-400">
                                <li> Es un indicador clave para el crecimiento. Aumentar el ticket promedio (con estrategias de upselling o paquetes) puede ser más fácil que conseguir nuevos clientes. </li>
                                <li> Te ayuda a entender el poder adquisitivo de tus clientes y a probar el impacto de nuevas estrategias de precios o promociones. </li>
                            </ul>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>
        </Accordion>
    </Dialog>
</template>