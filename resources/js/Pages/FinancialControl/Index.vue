<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { startOfWeek, endOfWeek, startOfMonth, endOfMonth, startOfYear, endOfYear, isSameDay, isToday, format } from 'date-fns';
import axios from 'axios';

// Partials
import KpiCards from './Partials/KpiCards.vue';
import BankAccountsPanel from './Partials/BankAccountsPanel.vue';
import ReportModals from './Partials/ReportModals.vue';

const props = defineProps({
    kpis: Object,
    chartData: Object,
    paymentMethods: Array,
    salesByChannel: Array,
    expensesByCategory: Array,
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

// --- Modales State ---
const isSalesModalVisible = ref(false);
const isPaymentsModalVisible = ref(false);
const isAllExpensesModalVisible = ref(false);
const isHelpModalVisible = ref(false);

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


// --- HELPERS ESTATICOS LOCALES ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

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
</script>

<template>
    <Head title="Reporte Financiero" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 space-y-6">
            <!-- Header con Filtros -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div class="flex items-center gap-2">
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200 m-0">Inicio</h1>
                    <Button icon="pi pi-question-circle" text aria-label="Ayuda" @click="isHelpModalVisible = true"
                        label="¿Qué significan estas métricas?" />
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <SelectButton v-model="selectedRange" :options="rangeOptions" optionLabel="label" optionValue="value" />
                    <DatePicker v-if="selectedRange === 'custom'" v-model="dates" selectionMode="range"
                        dateFormat="dd/mm/yy" class="!w-64" @update:modelValue="selectedRange = 'custom'" />
                    <Button label="Crear reporte" icon="pi pi-file-excel" severity="success" outlined
                        @click="handleExport" :loading="isExporting" />
                </div>
            </div>

            <!-- COMPONENTE: KPIs -->
            <KpiCards 
                :kpis="kpis" 
                @open-sales="isSalesModalVisible = true"
                @open-payments="isPaymentsModalVisible = true"
                @open-expenses="isAllExpensesModalVisible = true"
            />

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
                                        <div class="flex items-center"> 
                                            <i :class="getPaymentMethodDetails(pm.method).icon" class="mr-2 text-gray-500"></i> 
                                            <span class="font-semibold text-sm">{{ getPaymentMethodDetails(pm.method).name }}</span> 
                                        </div>
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

                    <!-- Gastos por Categoría -->
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

                    <!-- COMPONENTE: Panel de Cuentas Bancarias -->
                    <BankAccountsPanel 
                        :bank-accounts="bankAccounts"
                        :all-bank-accounts="allBankAccounts"
                    />
                </div>

                <!-- Ventas por Módulo -->
                <Card class="lg:row-span-2 !bg-[#E6E6E6] border border-[#d9d9d9]">
                    <template #title>Ventas por módulo</template>
                    <template #subtitle>Desglose de ventas por origen.</template>
                    <template #content>
                        <div v-if="salesByChannel.length > 0" class="space-y-4">
                            <div v-for="sc in salesByChannel" :key="sc.channel" class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                                <div class="flex items-center gap-3">
                                    <span class="bg-[#EFD5FF] text-[#8C2FFE] border border-[#BE89FF] rounded-full size-8 flex items-center justify-center flex-shrink-0">
                                        <i :class="getChannelDetails(sc.channel).icon" class="!text-lg"></i> 
                                    </span>
                                    <div class="text-center text-lg w-full">
                                        <p class="font-bold text-[#373737] m-0">{{ getChannelDetails(sc.channel).name }}</p>
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

        <!-- COMPONENTE: Modales de Detalles y Ayuda -->
        <ReportModals 
            v-model:isSalesVisible="isSalesModalVisible"
            v-model:isPaymentsVisible="isPaymentsModalVisible"
            v-model:isExpensesVisible="isAllExpensesModalVisible"
            v-model:isHelpVisible="isHelpModalVisible"
            :detailed-transactions="detailedTransactions"
            :detailed-payments="detailedPayments"
            :detailed-expenses="detailedExpenses"
        />

    </AppLayout>
</template>