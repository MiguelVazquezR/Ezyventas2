<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { startOfWeek, endOfWeek, startOfMonth, endOfMonth, startOfYear, endOfYear, isSameDay, isToday, format } from 'date-fns';
import axios from 'axios';
import BankAccountHistoryModal from '@/Components/BankAccountHistoryModal.vue';
import BankAccountTransferModal from '@/Components/BankAccountTransferModal.vue';

const props = defineProps({
    kpis: Object,
    chartData: Object,
    paymentMethods: Array,
    salesByChannel: Array,
    expensesByCategory: Array,
    detailedExpensesByCategory: Object,
    detailedTransactions: Array, // <-- Prop para modal de Ventas
    detailedPayments: Array,     // <-- Prop para modal de Pagos
    bankAccounts: Array,
    allBankAccounts: Array,
    filters: Object,
});

// --- STATE ---
const dates = ref();
const selectedRange = ref('day');
const mainChartOptions = ref();
const isExporting = ref(false);
const menu = ref();

// --- Lógica para modales de Cuentas Bancarias ---
const isHistoryModalVisible = ref(false);
const isTransferModalVisible = ref(false);
const selectedAccount = ref(null);

// --- Lógica para MODALES DE KPI ---
const isExpenseModalVisible = ref(false);
const isSalesModalVisible = ref(false);   // <-- Nuevo estado modal Ventas
const isPaymentsModalVisible = ref(false); // <-- Nuevo estado modal Pagos
const selectedCategoryData = ref({ name: '', expenses: [] });
// No necesitamos data seleccionada para Ventas/Pagos, usamos las props directamente

const openExpenseModal = (categoryName) => {
    selectedCategoryData.value = {
        name: categoryName,
        expenses: props.detailedExpensesByCategory[categoryName] || []
    };
    isExpenseModalVisible.value = true;
};
const openSalesModal = () => isSalesModalVisible.value = true;     // <-- Función abrir modal Ventas
const openPaymentsModal = () => isPaymentsModalVisible.value = true; // <-- Función abrir modal Pagos
// --- Fin Lógica MODALES DE KPI ---

const toggleMenu = (event, account) => {
    selectedAccount.value = account;
    menu.value.toggle(event);
};

const menuItems = ref([
    { label: 'Ver Historial', icon: 'pi pi-history', command: () => { isHistoryModalVisible.value = true; }},
    { label: 'Realizar Transferencia', icon: 'pi pi-arrows-h', command: () => { isTransferModalVisible.value = true; }}
]);

const onTransferSuccess = () => {
    isTransferModalVisible.value = false;
    router.reload({ preserveState: false });
};


// --- EXPORTACIÓN ---
const exportUrl = computed(() => {
    if (dates.value && dates.value[0] && dates.value[1]) {
        const startDate = format(dates.value[0], 'yyyy-MM-dd');
        const endDate = format(dates.value[1], 'yyyy-MM-dd');
        return route('financial-control.export', { start_date: startDate, end_date: endDate });
    }
    return '#';
});

const handleExport = async () => {
    if (exportUrl.value === '#') return;
    isExporting.value = true;
    try {
        const response = await axios.get(exportUrl.value, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        let fileName = 'ReporteFinanciero.xlsx';
        const contentDisposition = response.headers['content-disposition'];
        if (contentDisposition) {
            const fileNameMatch = contentDisposition.match(/filename="(.+)"/);
            if (fileNameMatch && fileNameMatch.length === 2) { fileName = fileNameMatch[1]; }
        }
        link.setAttribute('download', fileName);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) { console.error("Error al exportar el reporte:", error); }
    finally { isExporting.value = false; }
};


// --- RANGOS DE FECHA ---
const rangeOptions = ref([
    { label: 'Día (Hoy)', value: 'day' }, { label: 'Semana', value: 'week' },
    { label: 'Mes', value: 'month' }, { label: 'Año', value: 'year' },
    { label: 'Personalizado', value: 'custom' },
]);

const setDateRange = (period) => {
    const today = new Date();
    let startDate, endDate;
    switch (period) {
        case 'week': startDate = startOfWeek(today, { weekStartsOn: 1 }); endDate = endOfWeek(today, { weekStartsOn: 1 }); break;
        case 'month': startDate = startOfMonth(today); endDate = endOfMonth(today); break;
        case 'year': startDate = startOfYear(today); endDate = endOfYear(today); break;
        case 'day': default: startDate = today; endDate = today; break;
    }
    dates.value = [startDate, endDate];
};
watch(selectedRange, (newPeriod) => { if (newPeriod !== 'custom') { setDateRange(newPeriod); }});

// --- LÓGICA DE DATOS ---
const fetchData = () => {
    if (dates.value && dates.value[0] && dates.value[1]) {
        router.get(route('financial-control.index'), {
            start_date: format(dates.value[0], 'yyyy-MM-dd'),
            end_date: format(dates.value[1], 'yyyy-MM-dd'),
        }, { preserveState: true, replace: true, });
    }
};
watch(dates, (newDates, oldDates) => {
    if (newDates && newDates[0] && newDates[1]) {
        if (!oldDates || !isSameDay(newDates[0], oldDates[0]) || !isSameDay(newDates[1], oldDates[1])) { fetchData(); }
    }
}, { deep: true });


// --- GRÁFICAS ---
const barChartData = computed(() => ({
    labels: props.chartData.labels,
    datasets: [
        { label: 'Ventas totales', data: props.chartData.sales, backgroundColor: '#a78bfa', borderRadius: 6 },
        { label: 'Total de pagos', data: props.chartData.payments, backgroundColor: '#7dd3fc', borderRadius: 6 },
        { label: 'Total de gastos', data: props.chartData.expenses, backgroundColor: '#fcd34d', borderRadius: 6 },
        { label: 'Flujo de dinero', data: props.chartData.payments.map((payment, index) => payment - props.chartData.expenses[index]), backgroundColor: '#1FAE07', borderRadius: 6 },
    ]
}));

// --- CONFIGURACIÓN (al montar) ---
onMounted(() => {
    const initialStartDate = new Date(props.filters.startDate.replace(/-/g, '/'));
    const initialEndDate = new Date(props.filters.endDate.replace(/-/g, '/'));
    dates.value = [initialStartDate, initialEndDate];
    if (isSameDay(initialStartDate, initialEndDate) && isToday(initialStartDate)) { selectedRange.value = 'day'; }
    else if (isSameDay(initialStartDate, startOfWeek(initialStartDate, { weekStartsOn: 1 })) && isSameDay(initialEndDate, endOfWeek(initialStartDate, { weekStartsOn: 1 }))) { selectedRange.value = 'week'; }
    else if (isSameDay(initialStartDate, startOfMonth(initialStartDate)) && isSameDay(initialEndDate, endOfMonth(initialStartDate))) { selectedRange.value = 'month'; }
    else if (isSameDay(initialStartDate, startOfYear(initialStartDate)) && isSameDay(initialEndDate, endOfYear(initialStartDate))) { selectedRange.value = 'year'; }
    else { selectedRange.value = 'custom'; }
    const textColor = '#6b7280'; const gridColor = '#e5e7eb';
    mainChartOptions.value = {
        maintainAspectRatio: false, aspectRatio: 0.8,
        plugins: { legend: { position: 'bottom', labels: { color: textColor, usePointStyle: true, boxWidth: 8 } } },
        scales: { x: { ticks: { color: textColor }, grid: { display: false } }, y: { ticks: { color: textColor }, grid: { color: gridColor } } }
    };
});


// --- HELPERS ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => format(new Date(dateString), 'dd/MM/yyyy');
const formatDateTime = (dateString) => format(new Date(dateString), 'dd/MM/yyyy HH:mm'); // Helper fecha y hora

const getPaymentMethodDetails = (method) => {
    const details = {
        efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'bg-[#37672B]', textColor: 'text-green-600' },
        tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'bg-[#063C53]', textColor: 'text-blue-600' },
        transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'bg-[#D2D880]', textColor: 'text-orange-500' },
        saldo: { name: 'Saldo a favor', icon: 'pi pi-wallet', color: 'bg-purple-500', textColor: 'text-purple-500' },
        default: { name: method || 'Otro', icon: 'pi pi-question-circle', color: 'bg-gray-500', textColor: 'text-gray-500' }
    };
    return details[method] || details.default;
};
const getChannelDetails = (channel) => {
    const details = {
        punto_de_venta: { name: 'Punto de Venta', icon: 'pi pi-shopping-cart', verb: 'Ventas realizadas' },
        tienda_en_linea: { name: 'Tienda en Línea', icon: 'pi pi-mobile', verb: 'Ventas realizadas' },
        orden_de_servicio: { name: 'Orden de Servicio', icon: 'pi pi-wrench', verb: 'Órdenes completadas' },
        cotizacion: { name: 'Cotización', icon: 'pi pi-file', verb: 'Cotizaciones aceptadas' },
        manual: { name: 'Manual', icon: 'pi pi-pencil', verb: 'Ventas registradas' },
        abono_a_saldo: { name: 'Abono a Saldo', icon: 'pi pi-wallet', verb: 'Abonos recibidos' }
    };
    return details[channel] || { name: channel || 'Desconocido', icon: 'pi pi-question-circle', verb: 'Transacciones' };
};
const getExpenseCategoryIcon = (categoryName) => {
    if (!categoryName) return 'pi pi-tag'; const name = categoryName.toLowerCase();
    if (name.includes('servicio')) return 'pi pi-file'; if (name.includes('proveedor')) return 'pi pi-calculator';
    if (name.includes('renta')) return 'pi pi-shopping-bag'; if (name.includes('sueldo')) return 'pi pi-users';
    if (name.includes('publicidad')) return 'pi pi-megaphone'; if (name.includes('administrativo')) return 'pi pi-cog';
    if (name.includes('mantenimiento')) return 'pi pi-wrench'; if (name.includes('otro')) return 'pi pi-box';
    return 'pi pi-tag';
};
const getTransactionStatusTagSeverity = (status) => {
    switch (status) {
        case 'completada': return 'success';
        case 'pendiente': return 'warning';
        case 'cancelada': return 'danger';
        case 'reembolsada': return 'info';
        default: return 'secondary';
    }
};

</script>

<template>
    <Head title="Reporte Financiero" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 space-y-6">
            <!-- Header con Filtros -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Inicio</h1>
                <div class="flex items-center gap-2 flex-wrap">
                    <SelectButton v-model="selectedRange" :options="rangeOptions" optionLabel="label" optionValue="value" />
                    <DatePicker v-if="selectedRange === 'custom'" v-model="dates" selectionMode="range" dateFormat="dd/mm/yy" class="!w-64" @update:modelValue="selectedRange = 'custom'" />
                    <Button label="Crear Reporte" icon="pi pi-file-excel" severity="success" outlined @click="handleExport" :loading="isExporting" />
                </div>
            </div>

            <!-- KPIs - Fila 1 -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                 <Card @click="openSalesModal" class="cursor-pointer hover:shadow-lg transition-shadow duration-150"> <template #content>
                        <div class="flex items-center justify-between mb-2"> <span class="text-gray-500">Ventas totales</span> <i class="pi pi-shopping-cart p-2 bg-purple-100 text-purple-600 rounded-full"></i> </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.sales.current) }}</p>
                        <div class="flex items-center text-sm mt-1" :class="kpis.sales.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i class="pi" :class="kpis.sales.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> <span class="font-semibold mx-1">{{ Math.abs(kpis.sales.percentage_change) }}%</span> <span>({{ formatCurrency(kpis.sales.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.sales.previous) }} el periodo pasado</p>
                    </template> </Card>

                <Card @click="openPaymentsModal" class="cursor-pointer hover:shadow-lg transition-shadow duration-150"> <template #content>
                        <div class="flex items-center justify-between mb-2"> <span class="text-gray-500">Total de pagos</span> <i class="pi pi-dollar p-2 bg-cyan-100 text-cyan-600 rounded-full"></i> </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.payments.current) }}</p>
                        <div class="flex items-center text-sm mt-1" :class="kpis.payments.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i class="pi" :class="kpis.payments.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> <span class="font-semibold mx-1">{{ Math.abs(kpis.payments.percentage_change) }}%</span> <span>({{ formatCurrency(kpis.payments.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.payments.previous) }} el periodo pasado</p>
                    </template> </Card>

                 <!-- NUEVO KPI: Saldos Pendientes -->
                 <Card class="cursor-pointer hover:shadow-lg transition-shadow duration-150"> <!-- Podría abrir modal de Ventas filtrado por pendientes -->
                    <template #content>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-500">Saldos pendientes</span>
                            <i class="pi pi-exclamation-triangle p-2 bg-orange-100 text-orange-600 rounded-full"></i>
                        </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.pendingBalance.current) }}</p>
                         <div class="flex items-center text-sm mt-1" :class="kpis.pendingBalance.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                            <i class="pi" :class="kpis.pendingBalance.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                            <span class="font-semibold mx-1">{{ Math.abs(kpis.pendingBalance.percentage_change) }}%</span>
                            <span>({{ formatCurrency(kpis.pendingBalance.monetary_change) }})</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.pendingBalance.previous) }} el periodo pasado</p>
                    </template>
                </Card>
            </div>

             <!-- KPIs - Fila 2 -->
             <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                <!-- Gastos (clickable para abrir modal existente) -->
                <Card class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
                     <template #content>
                        <div class="flex items-center justify-between mb-2"> <span class="text-gray-500">Total de gastos</span> <i class="pi pi-arrow-up-right p-2 bg-yellow-100 text-yellow-600 rounded-full"></i> </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.expenses.current) }}</p>
                        <div class="flex items-center text-sm mt-1" :class="kpis.expenses.percentage_change <= 0 ? 'text-green-500' : 'text-red-500'"> <i class="pi" :class="kpis.expenses.percentage_change <= 0 ? 'pi-arrow-down' : 'pi-arrow-up'"></i> <span class="font-semibold mx-1">{{ Math.abs(kpis.expenses.percentage_change) }}%</span> <span>({{ formatCurrency(kpis.expenses.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.expenses.previous) }} el periodo pasado</p>
                    </template>
                </Card>

                <!-- KPI: Ganancia Neta -->
                <Card> <template #content>
                        <div class="flex items-center justify-between mb-2">
                             <span class="text-gray-500">Ganancia neta</span>
                             <i class="pi pi-info-circle text-gray-400 cursor-help" v-tooltip.top="'Mide la rentabilidad de las ventas.\nCalculado como: (Ventas Totales - Total de Gastos)'"></i>
                        </div>
                        <p class="text-2xl font-bold" :class="kpis.netProfit.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'"> {{ formatCurrency(kpis.netProfit.current) }} </p>
                        <div class="flex items-center text-sm mt-1" :class="kpis.netProfit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i class="pi" :class="kpis.netProfit.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> <span class="font-semibold mx-1">{{ Math.abs(kpis.netProfit.percentage_change) }}%</span> <span>({{ formatCurrency(kpis.netProfit.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.netProfit.previous) }} el periodo pasado</p>
                    </template> </Card>

                <!-- KPI: Flujo de Dinero Neto -->
                <Card> <template #content>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-500">Flujo de dinero neto</span>
                             <i class="pi pi-info-circle text-gray-400 cursor-help" v-tooltip.top="'Mide el dinero real que entró y salió.\nCalculado como: (Total de Pagos - Total de Gastos)'"></i>
                        </div>
                        <p class="text-2xl font-bold" :class="kpis.profit.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">{{ formatCurrency(kpis.profit.current) }}</p>
                        <div class="flex items-center text-sm mt-1" :class="kpis.profit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i class="pi" :class="kpis.profit.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> <span class="font-semibold mx-1">{{ Math.abs(kpis.profit.percentage_change) }}%</span> <span>({{ formatCurrency(kpis.profit.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.profit.previous) }} el periodo pasado</p>
                    </template> </Card>
            </div>

            <!-- Gráfica Principal -->
            <Card class="!bg-[#2A2A2A]">
                <template #title> <p class="text-white font-bold">Resumen comparativo de operaciones</p> </template>
                <template #content> <Chart type="bar" :data="barChartData" :options="mainChartOptions" class="h-[400px]" /> </template>
            </Card>

            <!-- Paneles de Desglose -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <!-- Métodos de Pago -->
                     <Card>
                        <template #title>Métodos de pago</template>
                        <template #subtitle>Visualiza los métodos de pago más usados.</template>
                        <template #content>
                            <div v-if="paymentMethods.length > 0" class="space-y-4">
                                <div v-for="pm in paymentMethods" :key="pm.method">
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="flex items-center"> <i :class="getPaymentMethodDetails(pm.method).icon" class="mr-2 text-gray-500"></i> <span class="font-semibold text-sm">{{ getPaymentMethodDetails(pm.method).name }}</span> </div>
                                        <span class="text-sm font-semibold">{{ pm.percentage }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 dark:bg-gray-700"> <div :class="getPaymentMethodDetails(pm.method).color" class="h-2 rounded-full" :style="{ width: pm.percentage + '%' }"></div> </div>
                                </div>
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No hay pagos registrados.</p>
                        </template>
                    </Card>

                    <!-- Gastos por Categoría (Interactivo) -->
                    <Card>
                        <template #title>Gastos por categoría</template>
                        <template #subtitle>Resumen de gastos. (Haz clic para ver detalle)</template>
                        <template #content>
                            <div v-if="expensesByCategory.length > 0" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <div v-for="cat in expensesByCategory" :key="cat.category_name" @click="openExpenseModal(cat.category_name)" class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg flex items-center gap-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-150">
                                    <i :class="getExpenseCategoryIcon(cat.category_name)" class="text-2xl p-2 bg-gray-200 dark:bg-gray-700 rounded-full"></i>
                                    <div> <p class="text-sm text-gray-500 m-0">{{ cat.category_name }}</p> <p class="font-bold m-0">{{ formatCurrency(cat.total) }}</p> </div>
                                </div>
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No hay gastos registrados.</p>
                        </template>
                    </Card>

                    <!-- Panel de Cuentas Bancarias -->
                    <Card>
                        <template #title>Cuentas bancarias</template>
                        <template #subtitle>Balance actual y acciones rápidas.</template>
                        <template #content>
                            <div v-if="bankAccounts && bankAccounts.length > 0">
                                <div class="flex justify-between items-center mb-4 pb-2 border-b border-dashed dark:border-gray-700"> <span class="font-bold">Balance Total</span> <span class="font-bold text-lg">{{ formatCurrency(totalBalance) }}</span> </div>
                                <ul class="space-y-3 max-h-60 overflow-y-auto pr-2">
                                    <li v-for="account in bankAccounts" :key="account.id" class="flex justify-between items-center">
                                        <div> <p class="font-semibold">{{ account.account_name }}</p> <p class="text-sm text-gray-500">{{ account.bank_name }}</p> </div>
                                        <div class="flex items-center gap-2"> <span class="font-mono font-bold">{{ formatCurrency(account.balance) }}</span> <Button icon="pi pi-ellipsis-v" text rounded @click="toggleMenu($event, account)" /> </div>
                                    </li>
                                </ul>
                                <Menu ref="menu" :model="menuItems" :popup="true" />
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No hay cuentas bancarias.</p>
                        </template>
                    </Card>
                </div>

                <!-- Ventas por Módulo -->
                <Card class="lg:row-span-2 !bg-[#E6E6E6] border border-[#d9d9d9]">
                    <template #title>Ventas por módulo</template>
                    <template #subtitle>Desglose de ventas por origen.</template>
                    <template #content>
                        <div v-if="salesByChannel.length > 0" class="space-y-4">
                            <div v-for="sc in salesByChannel" :key="sc.channel" class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                                <div class="flex items-center gap-3">
                                    <span class="bg-[#EFD5FF] text-[#8C2FFE] border border-[#BE89FF] rounded-full size-8 flex items-center justify-center flex-shrink-0"> <i :class="getChannelDetails(sc.channel).icon" class="!text-lg"></i> </span>
                                    <div class="text-center text-lg w-full"> <p class="font-bold text-[#373737] m-0">{{ getChannelDetails(sc.channel).name }}</p> <p class="font-semibold text-black m-0">{{ formatCurrency(sc.total) }}</p> </div>
                                </div>
                                <div class="mt-1 pt-1 border-t border-dashed border-[#d9d9d9] text-center"> <p class="text-sm text-gray-500 m-0">{{ getChannelDetails(sc.channel).verb }}</p> <p class="font-bold text-lg m-0 bg-[#F2F2F2] rounded-md">{{ sc.count }}</p> </div>
                            </div>
                        </div>
                        <p v-else class="text-center text-gray-500 py-8">No hay ventas registradas.</p>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modales -->
        <BankAccountHistoryModal v-if="selectedAccount" v-model:visible="isHistoryModalVisible" :account="selectedAccount" />
        <BankAccountTransferModal v-if="selectedAccount" v-model:visible="isTransferModalVisible" :account="selectedAccount" :all-accounts="allBankAccounts" @transfer-success="onTransferSuccess" />

        <!-- MODAL: Detalle de Gastos por Categoría -->
        <Dialog v-model:visible="isExpenseModalVisible" :header="`Detalle de Gastos: ${selectedCategoryData.name}`" modal class="w-full max-w-4xl mx-4">
            <DataTable :value="selectedCategoryData.expenses" paginator :rows="10" class="p-datatable-sm" sortMode="multiple" :multiSortMeta="[{field: 'expense_date', order: -1}]" emptyMessage="No hay gastos para esta categoría." responsiveLayout="scroll">
                <Column field="folio" header="Folio" sortable></Column>
                <Column field="expense_date" header="Fecha" sortable> <template #body="{ data }"> {{ formatDate(data.expense_date) }} </template> </Column>
                <Column field="description" header="Descripción"></Column>
                <Column field="payment_method" header="Método de Pago" sortable>
                    <template #body="{ data }">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2"> <i :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor}`"></i> <span>{{ getPaymentMethodDetails(data.payment_method).name }}</span> </div>
                            <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account" class="text-xs text-gray-500 dark:text-gray-400 pl-6" v-tooltip.bottom="`${data.bank_account.bank_name}`"> ↳ {{ data.bank_account.account_name }} </div>
                        </div>
                    </template>
                </Column>
                <Column field="amount" header="Monto" sortable> <template #body="{ data }"> <span class="font-mono font-semibold">{{ formatCurrency(data.amount) }}</span> </template> </Column>
            </DataTable>
        </Dialog>

        <!-- NUEVO MODAL: Detalle de Ventas (Transacciones) -->
        <Dialog v-model:visible="isSalesModalVisible" header="Detalle de Ventas del Periodo" modal class="w-full max-w-5xl mx-4">
             <DataTable :value="detailedTransactions" paginator :rows="15" class="p-datatable-sm"
                 sortMode="multiple" :multiSortMeta="[{field: 'created_at', order: -1}]"
                 emptyMessage="No hay ventas registradas en este periodo."
                 responsiveLayout="scroll">
                 <Column field="folio" header="Folio" sortable></Column>
                 <Column field="created_at" header="Fecha" sortable> <template #body="{ data }"> {{ formatDateTime(data.created_at) }} </template> </Column>
                 <Column field="customer.name" header="Cliente" sortable> <template #body="{ data }"> {{ data.customer?.name || 'Público General' }} </template> </Column>
                 <Column field="channel" header="Canal" sortable> <template #body="{ data }"> {{ getChannelDetails(data.channel).name }} </template> </Column>
                 <Column field="total" header="Total" sortable> <template #body="{ data }"> <span class="font-mono font-semibold">{{ formatCurrency(data.total) }}</span> </template> </Column>
                 <Column field="status" header="Estado" sortable> <template #body="{ data }"> <Tag :value="data.status" :severity="getTransactionStatusTagSeverity(data.status)" /> </template> </Column>
             </DataTable>
        </Dialog>

         <!-- NUEVO MODAL: Detalle de Pagos -->
        <Dialog v-model:visible="isPaymentsModalVisible" header="Detalle de Pagos Recibidos" modal class="w-full max-w-5xl mx-4">
            <DataTable :value="detailedPayments" paginator :rows="15" class="p-datatable-sm"
                sortMode="multiple" :multiSortMeta="[{field: 'payment_date', order: -1}]"
                emptyMessage="No hay pagos registrados en este periodo."
                responsiveLayout="scroll">
                <Column field="payment_date" header="Fecha" sortable> <template #body="{ data }"> {{ formatDateTime(data.payment_date) }} </template> </Column>
                <Column field="transaction.folio" header="Venta Folio" sortable></Column>
                <Column field="transaction.customer.name" header="Cliente" sortable> <template #body="{ data }"> {{ data.transaction?.customer?.name || 'Público General' }} </template> </Column>
                <Column field="payment_method" header="Método" sortable>
                     <template #body="{ data }">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2"> <i :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor}`"></i> <span>{{ getPaymentMethodDetails(data.payment_method).name }}</span> </div>
                            <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account" class="text-xs text-gray-500 dark:text-gray-400 pl-6" v-tooltip.bottom="`${data.bank_account.bank_name}`"> ↳ {{ data.bank_account.account_name }} </div>
                        </div>
                    </template>
                </Column>
                <Column field="amount" header="Monto" sortable> <template #body="{ data }"> <span class="font-mono font-semibold">{{ formatCurrency(data.amount) }}</span> </template> </Column>
            </DataTable>
        </Dialog>

    </AppLayout>
</template>

