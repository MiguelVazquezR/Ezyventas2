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
        { label: 'Ventas totales', data: props.chartData.sales, backgroundColor: '#a78bfa', borderRadius: 4 },
        { label: 'Total de pagos', data: props.chartData.payments, backgroundColor: '#7dd3fc', borderRadius: 4 },
        { label: 'Total de gastos', data: props.chartData.expenses, backgroundColor: '#fcd34d', borderRadius: 4 },
        { label: 'Flujo de dinero', data: props.chartData.payments.map((payment, index) => payment - props.chartData.expenses[index]), backgroundColor: '#1FAE07', borderRadius: 4 },
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
    
    const textColor = '#6b7280'; 
    const gridColor = '#3a3a3a'; // Adaptado al sistema oscuro Tesla UI
    
    mainChartOptions.value = {
        maintainAspectRatio: false, aspectRatio: 0.8,
        plugins: { legend: { position: 'bottom', labels: { color: textColor, usePointStyle: true, boxWidth: 8, font: { size: 11, family: 'system-ui' } } } },
        scales: { x: { ticks: { color: textColor, font: { size: 10 } }, grid: { display: false } }, y: { ticks: { color: textColor, font: { size: 10 } }, grid: { color: gridColor, drawBorder: false } } }
    };
});

// --- HELPERS ESTATICOS LOCALES ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const getPaymentMethodDetails = (method) => {
    const details = {
        efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'bg-green-500', textColor: 'text-green-500' },
        tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'bg-blue-500', textColor: 'text-blue-500' },
        transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'bg-orange-400', textColor: 'text-orange-400' },
        saldo: { name: 'Saldo a favor', icon: 'pi pi-wallet', color: 'bg-purple-500', textColor: 'text-purple-500' },
        default: { name: method || 'Otro', icon: 'pi pi-question-circle', color: 'bg-gray-500', textColor: 'text-gray-500' }
    };
    return details[method] || details.default;
};

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
        <div class="p-4 md:p-6 lg:p-8 space-y-6 max-w-[1600px] mx-auto">
            
            <!-- Encabezado estilo Tesla UI -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-8">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-4xl md:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">Control financiero</h1>
                        <Button icon="pi pi-question-circle" text rounded aria-label="Ayuda" @click="isHelpModalVisible = true" class="!w-10 !h-10 !text-gray-400 hover:!text-gray-600 dark:hover:!text-gray-200" v-tooltip.top="'¿Qué significan estas métricas?'" />
                    </div>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mt-2 m-0 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                        Métricas operativas y flujos
                    </p>
                </div>
                
                <div class="flex items-center gap-3 flex-wrap bg-white dark:bg-[#232323] p-2 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] shadow-sm">
                    <SelectButton v-model="selectedRange" :options="rangeOptions" optionLabel="label" optionValue="value" 
                        :pt="{ 
                            root: { class: 'bg-gray-100 dark:bg-[#1a1a1a] rounded-full p-1 border border-gray-200 dark:border-[#3a3a3a] flex' }, 
                            button: { class: 'rounded-full px-4 py-2 transition-colors focus:ring-0 !border-none text-xs font-medium' } 
                        }" 
                    />
                    
                    <DatePicker v-if="selectedRange === 'custom'" v-model="dates" selectionMode="range"
                        dateFormat="dd/mm/yy" class="!w-64" @update:modelValue="selectedRange = 'custom'" 
                        :pt="{ 
                            input: { root: { class: '!rounded-full !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors text-sm' } } 
                        }" 
                    />
                    
                    <Button label="Exportar reporte" icon="pi pi-file-excel" severity="secondary"
                        @click="handleExport" :loading="isExporting" class="!rounded-full !px-5 !text-xs !font-bold !uppercase !tracking-wider" />
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
            <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="mb-6 flex justify-between items-center">
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Resumen comparativo de operaciones</h2>
                    <i class="pi pi-chart-bar text-gray-400 !text-sm"></i>
                </div>
                <div class="h-[400px]">
                    <Chart type="bar" :data="barChartData" :options="mainChartOptions" class="h-full" />
                </div>
            </div>

            <!-- Paneles de Desglose -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Métodos de Pago -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="mb-6">
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Métodos de pago</h2>
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Distribución de ingresos por tipo</p>
                        </div>
                        
                        <div v-if="paymentMethods.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div v-for="pm in paymentMethods" :key="pm.method" class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all flex flex-col justify-center">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-2"> 
                                        <i :class="getPaymentMethodDetails(pm.method).icon" class="!text-sm text-gray-400"></i> 
                                        <span class="text-xs uppercase tracking-widest font-bold text-gray-600 dark:text-gray-300 m-0">{{ getPaymentMethodDetails(pm.method).name }}</span> 
                                    </div>
                                    <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ pm.percentage }}%</span>
                                </div>
                                <div class="w-full bg-gray-200 dark:bg-[#2a2a2a] rounded-full h-1.5 overflow-hidden shadow-inner">
                                    <div :class="getPaymentMethodDetails(pm.method).color" class="h-full rounded-full transition-all"
                                        :style="{ width: pm.percentage + '%' }"></div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-10 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <i class="pi pi-wallet !text-2xl text-gray-400 mb-2"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin ingresos registrados</p>
                        </div>
                    </div>

                    <!-- Gastos por Categoría -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="mb-6">
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Gastos por categoría</h2>
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Análisis de egresos operativos</p>
                        </div>

                        <div v-if="expensesByCategory.length > 0" class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="cat in expensesByCategory" :key="cat.category_name"
                                class="p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all flex flex-col gap-3">
                                <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-[#2a2a2a] flex items-center justify-center flex-shrink-0">
                                    <i :class="getExpenseCategoryIcon(cat.category_name)" class="!text-xs text-gray-600 dark:text-gray-400"></i>
                                </div>
                                <div>
                                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1 line-clamp-1" :title="cat.category_name">{{ cat.category_name }}</p>
                                    <p class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(cat.total) }}</p>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-10 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <i class="pi pi-receipt !text-2xl text-gray-400 mb-2"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin egresos registrados</p>
                        </div>
                    </div>

                    <!-- COMPONENTE: Panel de Cuentas Bancarias -->
                    <BankAccountsPanel 
                        :bank-accounts="bankAccounts"
                        :all-bank-accounts="allBankAccounts"
                    />
                </div>

                <!-- Ventas por Módulo -->
                <div class="lg:row-span-2 bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                    <div class="mb-6">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Ventas por módulo</h2>
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Desglose de canales de origen</p>
                    </div>
                    
                    <div v-if="salesByChannel.length > 0" class="space-y-4 flex-grow">
                        <div v-for="sc in salesByChannel" :key="sc.channel" class="p-5 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                                    <i :class="getChannelDetails(sc.channel).icon" class="!text-lg text-primary-500"></i> 
                                </div>
                                <div class="flex-grow">
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-0.5">{{ getChannelDetails(sc.channel).name }}</p>
                                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(sc.total) }}</p>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-[#3a3a3a] flex items-center justify-between">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 m-0">{{ getChannelDetails(sc.channel).verb }}</p>
                                <span class="bg-gray-200 dark:bg-[#2a2a2a] text-gray-700 dark:text-gray-300 px-3 py-1 rounded-full text-xs font-bold">{{ sc.count }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="flex flex-col items-center justify-center flex-grow text-center py-10 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-inbox !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin operaciones de venta</p>
                    </div>
                </div>
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