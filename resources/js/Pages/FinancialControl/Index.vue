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
    // detailedExpensesByCategory: Object, // Ya no se necesita
    detailedExpenses: Array,
    detailedTransactions: Array,
    detailedPayments: Array,
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
// const isExpenseModalVisible = ref(false); // Modal de categoría eliminado
const isAllExpensesModalVisible = ref(false);
const isSalesModalVisible = ref(false);
const isPaymentsModalVisible = ref(false);
// const selectedCategoryData = ref({ name: '', expenses: [] }); // Ya no se necesita

// +++ NUEVA LÓGICA PARA MODAL DE AYUDA +++
const isHelpModalVisible = ref(false);
const openHelpModal = () => { isHelpModalVisible.value = true; };
// +++ FIN NUEVA LÓGICA +++


// Funciones para modales de KPIs
const openSalesModal = () => isSalesModalVisible.value = true;
const openPaymentsModal = () => isPaymentsModalVisible.value = true;
const openAllExpensesModal = () => isAllExpensesModalVisible.value = true;
// --- Fin Lógica MODALES DE KPI ---

const toggleMenu = (event, account) => {
    selectedAccount.value = account;
    menu.value.toggle(event);
};

const menuItems = ref([
    { label: 'Ver Historial', icon: 'pi pi-history', command: () => { isHistoryModalVisible.value = true; } },
    { label: 'Realizar Transferencia', icon: 'pi pi-arrows-h', command: () => { isTransferModalVisible.value = true; } }
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
watch(selectedRange, (newPeriod) => { if (newPeriod !== 'custom') { setDateRange(newPeriod); } });

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

// *** CORRECCIÓN: Cálculo de Balance Total ***
const totalBalance = computed(() => {
    if (!props.bankAccounts || props.bankAccounts.length === 0) {
        return 0;
    }
    // Asegurarse de sumar números, convirtiendo explícitamente a float
    return props.bankAccounts.reduce((sum, account) => sum + parseFloat(account.balance || 0), 0);
});
// *** FIN CORRECCIÓN ***

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
const formatDateTime = (dateString) => format(new Date(dateString), 'dd/MM/yyyy HH:mm');

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
                <!-- +++ TÍTULO CON BOTÓN DE AYUDA +++ -->
                <div class="flex items-center gap-2">
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200 m-0">Inicio</h1>
                    <Button icon="pi pi-question-circle" text aria-label="Ayuda" @click="openHelpModal"
                        label="¿Qué significan estas métricas?" />
                </div>
                <!-- +++ FIN TÍTULO CON BOTÓN DE AYUDA +++ -->
                <div class="flex items-center gap-2 flex-wrap">
                    <SelectButton v-model="selectedRange" :options="rangeOptions" optionLabel="label"
                        optionValue="value" />
                    <DatePicker v-if="selectedRange === 'custom'" v-model="dates" selectionMode="range"
                        dateFormat="dd/mm/yy" class="!w-64" @update:modelValue="selectedRange = 'custom'" />
                    <Button label="Crear reporte" icon="pi pi-file-excel" severity="success" outlined
                        @click="handleExport" :loading="isExporting" />
                </div>
            </div>

            <!-- KPIs - Ahora 5 KPIs en una fila (o adaptable) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- Ventas -->
                <Card @click="openSalesModal" class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
                    <template #content>
                        <div class="flex items-center justify-between mb-2"> <span class="text-gray-500">Ventas
                                totales (clic para detalles)</span> <i
                                class="pi pi-shopping-cart p-2 bg-purple-100 text-purple-600 rounded-full"></i> </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.sales.current) }}</p>
                        <div class="flex items-center text-sm mt-1"
                            :class="kpis.sales.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i
                                class="pi"
                                :class="kpis.sales.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> <span
                                class="font-semibold mx-1">{{ Math.abs(kpis.sales.percentage_change) }}%</span>
                            <span>({{ formatCurrency(kpis.sales.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.sales.previous) }} periodo ant.
                        </p>
                    </template> </Card>

                <!-- Pagos -->
                <Card @click="openPaymentsModal" class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
                    <template #content>
                        <div class="flex items-center justify-between mb-2"> <span class="text-gray-500">Total de
                                pagos (clic para detalles)</span> <i class="pi pi-dollar p-2 bg-cyan-100 text-cyan-600 rounded-full"></i>
                        </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.payments.current) }}</p>
                        <div class="flex items-center text-sm mt-1"
                            :class="kpis.payments.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i
                                class="pi"
                                :class="kpis.payments.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                            <span class="font-semibold mx-1">{{ Math.abs(kpis.payments.percentage_change) }}%</span>
                            <span>({{ formatCurrency(kpis.payments.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.payments.previous) }} periodo
                            ant.</p>
                    </template> </Card>

                <!-- Gastos -->
                <Card @click="openAllExpensesModal"
                    class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
                    <template #content>
                        <div class="flex items-center justify-between mb-2"> <span class="text-gray-500">Total de
                                gastos (clic para detalles)</span> <i
                                class="pi pi-arrow-up-right p-2 bg-yellow-100 text-yellow-600 rounded-full"></i> </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.expenses.current) }}</p>
                        <div class="flex items-center text-sm mt-1"
                            :class="kpis.expenses.percentage_change <= 0 ? 'text-green-500' : 'text-red-500'"> <i
                                class="pi"
                                :class="kpis.expenses.percentage_change <= 0 ? 'pi-arrow-down' : 'pi-arrow-up'"></i>
                            <span class="font-semibold mx-1">{{ Math.abs(kpis.expenses.percentage_change) }}%</span>
                            <span>({{ formatCurrency(kpis.expenses.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.expenses.previous) }} periodo
                            ant.</p>
                    </template>
                </Card>

                <!-- Ganancia Neta -->
                <Card> <template #content>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-500">Ganancia neta (ventas totales - gastos)</span>
                            <!-- +++ ICONO ORIGINAL CAMBIADO POR EL DEL MODAL DE AYUDA GENERAL +++ -->
                            <i class="pi pi-chart-line p-2 bg-teal-100 text-teal-600 rounded-full"></i>
                        </div>
                        <p class="text-2xl font-bold"
                            :class="kpis.netProfit.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">
                            {{ formatCurrency(kpis.netProfit.current) }} </p>
                        <div class="flex items-center text-sm mt-1"
                            :class="kpis.netProfit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i
                                class="pi"
                                :class="kpis.netProfit.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                            <span class="font-semibold mx-1">{{ Math.abs(kpis.netProfit.percentage_change) }}%</span>
                            <span>({{ formatCurrency(kpis.netProfit.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.netProfit.previous) }} periodo
                            ant.</p>
                    </template> </Card>

                <!-- Flujo de Dinero Neto -->
                <Card> <template #content>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-500">Flujo de dinero neto (total de pagos - gastos)</span>
                             <!-- +++ ICONO ORIGINAL CAMBIADO POR EL DEL MODAL DE AYUDA GENERAL +++ -->
                            <i class="pi pi-wallet p-2 bg-green-100 text-green-600 rounded-full"></i>
                        </div>
                        <p class="text-2xl font-bold"
                            :class="kpis.profit.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">{{
                                formatCurrency(kpis.profit.current) }}</p>
                        <div class="flex items-center text-sm mt-1"
                            :class="kpis.profit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> <i
                                class="pi"
                                :class="kpis.profit.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> <span
                                class="font-semibold mx-1">{{ Math.abs(kpis.profit.percentage_change) }}%</span>
                            <span>({{ formatCurrency(kpis.profit.monetary_change) }})</span> </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.profit.previous) }} periodo ant.
                        </p>
                    </template> </Card>

                <!-- KPI: Ticket Promedio (Nuevo) -->
                <Card> <template #content>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-500">Monto promedio por venta</span>
                            <i class="pi pi-receipt p-2 bg-blue-100 text-blue-600 rounded-full"></i>
                        </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(kpis.averageTicket.current) }}</p>
                        <div class="flex items-center text-sm mt-1"
                            :class="kpis.averageTicket.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                            <i class="pi"
                                :class="kpis.averageTicket.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                            <span class="font-semibold mx-1">{{ Math.abs(kpis.averageTicket.percentage_change)
                                }}%</span>
                            <span>({{ formatCurrency(kpis.averageTicket.monetary_change) }})</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.averageTicket.previous) }}
                            periodo ant.</p>
                    </template>
                </Card>
            </div>

            <!-- Gráfica Principal -->
            <Card class="!bg-[#2A2A2A]">
                <template #title>
                    <p class="text-white font-bold">Resumen comparativo de operaciones</p>
                </template>
                <template #content>
                    <Chart type="bar" :data="barChartData" :options="mainChartOptions" class="h-[400px]" />
                </template>
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
                                        <div class="flex items-center"> <i
                                                :class="getPaymentMethodDetails(pm.method).icon"
                                                class="mr-2 text-gray-500"></i> <span class="font-semibold text-sm">{{
                                                getPaymentMethodDetails(pm.method).name }}</span> </div>
                                        <span class="text-sm font-semibold">{{ pm.percentage }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 dark:bg-gray-700">
                                        <div :class="getPaymentMethodDetails(pm.method).color" class="h-2 rounded-full"
                                            :style="{ width: pm.percentage + '%' }"></div>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No hay pagos registrados.</p>
                        </template>
                    </Card>

                    <!-- Gastos por Categoría (Ya no es Interactivo) -->
                    <Card>
                        <template #title>Gastos por categoría</template>
                        <template #subtitle>Resumen de gastos por cada categoría.</template>
                        <template #content>
                            <div v-if="expensesByCategory.length > 0" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <div v-for="cat in expensesByCategory" :key="cat.category_name"
                                    class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg flex items-center gap-3">
                                    <i :class="getExpenseCategoryIcon(cat.category_name)"
                                        class="text-2xl p-2 bg-gray-200 dark:bg-gray-700 rounded-full"></i>
                                    <div>
                                        <p class="text-sm text-gray-500 m-0">{{ cat.category_name }}</p>
                                        <p class="font-bold m-0">{{ formatCurrency(cat.total) }}</p>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No hay gastos registrados.</p>
                        </template>
                    </Card>

                    <!-- Panel de Cuentas Bancarias -->
                    <Card>
                        <template #title>Cuentas bancarias</template>
                        <template #subtitle>Balance actual y acciones rápidas (Solo cuentas de esta
                            sucursal).</template>
                        <template #content>
                            <div v-if="bankAccounts && bankAccounts.length > 0">
                                <div
                                    class="flex justify-between items-center mb-4 pb-2 border-b border-dashed dark:border-gray-700">
                                    <span class="font-bold">Balance total (Sucursal)</span> <span
                                        class="font-bold text-lg">{{ formatCurrency(totalBalance) }}</span> </div>
                                <ul class="space-y-3 max-h-60 overflow-y-auto pr-2">
                                    <li v-for="account in bankAccounts" :key="account.id"
                                        class="flex justify-between items-center">
                                        <div>
                                            <p class="font-semibold">{{ account.account_name }}</p>
                                            <p class="text-sm text-gray-500">{{ account.bank_name }}</p>
                                        </div>
                                        <div class="flex items-center gap-2"> <span class="font-mono font-bold">{{
                                                formatCurrency(account.balance) }}</span> <Button
                                                icon="pi pi-ellipsis-v" text rounded
                                                @click="toggleMenu($event, account)" /> </div>
                                    </li>
                                </ul>
                                <Menu ref="menu" :model="menuItems" :popup="true" />
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No hay cuentas bancarias asignadas a esta
                                sucursal.</p>
                        </template>
                    </Card>
                </div>

                <!-- Ventas por Módulo -->
                <Card class="lg:row-span-2 !bg-[#E6E6E6] border border-[#d9d9d9]">
                    <template #title>Ventas por módulo</template>
                    <template #subtitle>Desglose de ventas por origen.</template>
                    <template #content>
                        <div v-if="salesByChannel.length > 0" class="space-y-4">
                            <div v-for="sc in salesByChannel" :key="sc.channel"
                                class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="bg-[#EFD5FF] text-[#8C2FFE] border border-[#BE89FF] rounded-full size-8 flex items-center justify-center flex-shrink-0">
                                        <i :class="getChannelDetails(sc.channel).icon" class="!text-lg"></i> </span>
                                    <div class="text-center text-lg w-full">
                                        <p class="font-bold text-[#373737] m-0">{{ getChannelDetails(sc.channel).name }}
                                        </p>
                                        <p class="font-semibold text-black m-0">{{ formatCurrency(sc.total) }}</p>
                                    </div>
                                </div>
                                <div class="mt-1 pt-1 border-t border-dashed border-[#d9d9d9] text-center">
                                    <p class="text-sm text-gray-500 m-0">{{ getChannelDetails(sc.channel).verb }}</p>
                                    <p class="font-bold text-lg m-0 bg-[#F2F2F2] rounded-md">{{ sc.count }}</p>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-center text-gray-500 py-8">No hay ventas registradas.</p>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modales -->
        <BankAccountHistoryModal v-if="selectedAccount" v-model:visible="isHistoryModalVisible"
            :account="selectedAccount" />
        <BankAccountTransferModal v-if="selectedAccount" v-model:visible="isTransferModalVisible"
            :account="selectedAccount" :all-accounts="allBankAccounts" @transfer-success="onTransferSuccess" />

        <!-- MODAL: Detalle de Ventas (Transacciones) -->
        <Dialog v-model:visible="isSalesModalVisible" header="Detalle de ventas del periodo" modal
            class="w-full max-w-5xl mx-4">
            <DataTable :value="detailedTransactions" paginator :rows="15" class="p-datatable-sm" sortMode="multiple"
                :multiSortMeta="[{ field: 'created_at', order: -1 }]"
                emptyMessage="No hay ventas registradas en este periodo." responsiveLayout="scroll">
                <Column field="folio" header="Folio" sortable></Column>
                <Column field="created_at" header="Fecha" sortable> <template #body="{ data }"> {{
                    formatDateTime(data.created_at) }} </template> </Column>
                <Column field="customer.name" header="Cliente" sortable> <template #body="{ data }"> {{
                    data.customer?.name || 'Público General' }} </template> </Column>
                <Column field="channel" header="Canal" sortable> <template #body="{ data }"> {{
                    getChannelDetails(data.channel).name }} </template> </Column>
                <Column field="total" header="Total" sortable> <template #body="{ data }"> <span
                            class="font-mono font-semibold">{{ formatCurrency(data.total) }}</span> </template>
                </Column>
                <Column field="status" header="Estado" sortable> <template #body="{ data }">
                        <Tag :value="data.status" :severity="getTransactionStatusTagSeverity(data.status)" />
                    </template>
                </Column>
                <template #empty>
                    <div class="p-4 text-center text-gray-500"> No hay ventas registradas en este periodo. </div>
                </template>
            </DataTable>
        </Dialog>

        <!-- MODAL: Detalle de Pagos -->
        <Dialog v-model:visible="isPaymentsModalVisible" header="Detalle de pagos recibidos"
            modal class="w-full max-w-5xl mx-4">
            <DataTable :value="detailedPayments" paginator :rows="15" class="p-datatable-sm" sortMode="multiple"
                :multiSortMeta="[{ field: 'payment_date', order: -1 }]"
                emptyMessage="No hay pagos (excepto saldo) registrados en este periodo." responsiveLayout="scroll">
                <Column field="payment_date" header="Fecha" sortable> <template #body="{ data }"> {{
                    formatDateTime(data.payment_date) }} </template> </Column>
                <Column field="transaction.folio" header="Venta folio" sortable></Column>
                <Column field="transaction.customer.name" header="Cliente" sortable> <template #body="{ data }"> {{
                    data.transaction?.customer?.name || 'Público General' }} </template> </Column>
                <Column field="payment_method" header="Método" sortable>
                    <template #body="{ data }">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2"> <i
                                    :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor}`"></i>
                                <span>{{ getPaymentMethodDetails(data.payment_method).name }}</span> </div>
                            <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account"
                                class="text-xs text-gray-500 dark:text-gray-400 pl-6"
                                v-tooltip.bottom="`${data.bank_account.bank_name}`"> ↳ {{ data.bank_account.account_name
                                }} </div>
                        </div>
                    </template>
                </Column>
                <Column field="amount" header="Monto" sortable> <template #body="{ data }"> <span
                            class="font-mono font-semibold">{{ formatCurrency(data.amount) }}</span> </template>
                </Column>
                 <template #empty>
                    <div class="p-4 text-center text-gray-500"> No hay pagos registrados en este periodo. </div>
                </template>
            </DataTable>
        </Dialog>

        <!-- MODAL: Detalle de Gastos Totales -->
        <Dialog v-model:visible="isAllExpensesModalVisible" header="Detalle de gastos totales del periodo" modal
            class="w-full max-w-5xl mx-4">
            <DataTable :value="detailedExpenses" paginator :rows="10" class="p-datatable-sm" sortMode="multiple"
                :multiSortMeta="[{ field: 'expense_date', order: -1 }]"
                emptyMessage="No hay gastos registrados en este periodo." responsiveLayout="scroll">
                <Column field="folio" header="Folio" sortable></Column>
                <Column field="expense_date" header="Fecha" sortable> <template #body="{ data }"> {{
                    formatDate(data.expense_date) }} </template> </Column>
                <Column field="category.name" header="Categoría" sortable></Column> <!-- Añadida columna categoría -->
                <Column field="description" header="Descripción"></Column>
                <Column field="payment_method" header="Método de Pago" sortable>
                    <template #body="{ data }">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2"> <i
                                    :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor}`"></i>
                                <span>{{ getPaymentMethodDetails(data.payment_method).name }}</span> </div>
                            <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account"
                                class="text-xs text-gray-500 dark:text-gray-400 pl-6"
                                v-tooltip.bottom="`${data.bank_account.bank_name}`"> ↳ {{ data.bank_account.account_name
                                }} </div>
                        </div>
                    </template>
                </Column>
                <Column field="amount" header="Monto" sortable> <template #body="{ data }"> <span
                            class="font-mono font-semibold">{{ formatCurrency(data.amount) }}</span> </template>
                </Column>
                 <template #empty>
                    <div class="p-4 text-center text-gray-500"> No hay gastos registrados en este periodo. </div>
                </template>
            </DataTable>
        </Dialog>

        <!-- +++ NUEVO MODAL DE AYUDA (Corregido) +++ -->
        <Dialog v-model:visible="isHelpModalVisible" header="Glosario de métricas financieras" modal
            class="w-full max-w-3xl mx-4">
            <Accordion value="0">
                <AccordionPanel value="0">
                    <AccordionHeader>Ganancia neta</AccordionHeader>
                    <AccordionContent>
                        <div class="p-4 space-y-3">
                            <p class="text-lg m-0">
                                Mide la <strong>rentabilidad</strong> de tu negocio después de restar todos los gastos de tus
                                ventas totales.
                            </p>
                            <div class="text-center">
                                <Tag severity="warn" class="!text-lg !bg-teal-100 !text-teal-600 font-mono">
                                    (Ventas Totales) - (Total de Gastos)
                                </Tag>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Utilidad para el negocio:</p>
                                <p>
                                    Responde a la pregunta: <strong>"¿Mi negocio es rentable?"</strong>.
                                </p>
                                <ul class="list-disc pl-5 mt-2 space-y-1">
                                    <li>
                                        Te dice si tus precios de venta son suficientes para cubrir tus costos operativos y
                                        aún dejar un margen de ganancia.
                                    </li>
                                    <li>
                                        <strong>Importante:</strong> Se basa en las <Tag class="!bg-purple-100 !text-purple-600">Ventas</Tag>, no en los pagos. Una
                                        venta a crédito cuenta aquí, aunque no hayas recibido el dinero.
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </AccordionContent>
                </AccordionPanel>
                <AccordionPanel value="1">
                    <AccordionHeader>Flujo de dinero neto</AccordionHeader>
                    <AccordionContent>
                        <div class="p-4 space-y-3">
                            <p class="text-lg m-0">
                                Mide la <strong>liquidez</strong> real de tu negocio. Es la cantidad de dinero
                                que entró y salió.
                            </p>
                            <div class="text-center">
                                <Tag severity="success" class="!text-lg font-mono">
                                    (Total de Pagos Recibidos) - (Total de Gastos Pagados)
                                </Tag>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Utilidad para el negocio:</p>
                                <p>
                                    Responde a la pregunta: <strong>"¿Tengo dinero para operar y pagar mis
                                        cuentas?"</strong>.
                                </p>
                                <ul class="list-disc pl-5 mt-2 space-y-1">
                                    <li>
                                        Un negocio puede ser "rentable" (Ganancia Neta positiva) pero quebrar por falta de
                                        liquidez (Flujo de Dinero negativo) si los clientes no pagan a tiempo.
                                    </li>
                                    <li>
                                        Este indicador es vital para la operación diaria. Te aseguras de tener efectivo en
                                        tus cuentas bancarias.
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </AccordionContent>
                </AccordionPanel>
                <AccordionPanel value="2">
                    <AccordionHeader>Monto promedio por venta (Ticket promedio)</AccordionHeader>
                    <AccordionContent>
                        <div class="p-4 space-y-3">
                            <p class="text-lg m-0">
                                Mide cuánto gasta un cliente en promedio en cada transacción que realiza.
                            </p>
                            <div class="text-center">
                                <Tag severity="info" class="!text-lg font-mono">
                                    (Ventas Totales) / (Número Total de Ventas)
                                </Tag>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Utilidad para el negocio:</p>
                                <p>
                                    Responde a la pregunta: <strong>"¿Cuánto gastan mis clientes en promedio por
                                        compra?"</strong>.
                                </p>
                                <ul class="list-disc pl-5 mt-2 space-y-1">
                                    <li>
                                        Es un indicador clave para el crecimiento. Aumentar el ticket promedio (con
                                        estrategias de *upselling* o paquetes) puede ser más fácil que conseguir nuevos
                                        clientes.
                                    </li>
                                    <li>
                                        Te ayuda a entender el poder adquisitivo de tus clientes y a probar el impacto de
                                        nuevas estrategias de precios o promociones.
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </AccordionContent>
                </AccordionPanel>
            </Accordion>
        </Dialog>
        <!-- +++ FIN NUEVO MODAL DE AYUDA +++ -->

    </AppLayout>
</template>
