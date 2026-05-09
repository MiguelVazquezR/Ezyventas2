<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Chart from 'primevue/chart';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';

const props = defineProps({
    metrics: Object,
    chartData: Array,
    filters: Object
});

// --- ESTADO DE FILTROS (Manejando objetos Date para PrimeVue) ---
const parseDate = (dateString) => dateString ? new Date(dateString + 'T00:00:00') : null;

const startDate = ref(parseDate(props.filters.start_date));
const endDate = ref(parseDate(props.filters.end_date));
const isLoading = ref(false);

const applyFilters = () => {
    isLoading.value = true;

    // Formateamos las fechas de vuelta a YYYY-MM-DD para el backend
    const formatForBackend = (d) => {
        if (!d) return null;
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };

    router.get(route('admin.reports.index'), {
        start_date: formatForBackend(startDate.value),
        end_date: formatForBackend(endDate.value)
    }, { 
        preserveState: true, 
        replace: true,
        onFinish: () => isLoading.value = false
    });
};

// --- HELPER FUNCTIONS ---
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const displayPeriod = computed(() => {
    const start = startDate.value || new Date();
    const end = endDate.value || new Date();
    const options = { day: 'numeric', month: 'short', year: 'numeric' };
    return `${start.toLocaleDateString('es-MX', options)} - ${end.toLocaleDateString('es-MX', options)}`;
});

// --- KPIS CALCULADOS AL VUELO ---
const arpu = computed(() => {
    return props.metrics.activeSubscriptions > 0 
        ? props.metrics.periodRevenue / props.metrics.activeSubscriptions 
        : 0;
});

const averageTicket = computed(() => {
    return props.metrics.newSubscriptions > 0 
        ? props.metrics.periodRevenue / props.metrics.newSubscriptions 
        : 0;
});

// --- CONFIGURACIÓN DE GRÁFICAS (TESLA UI) ---
const chartOptions = computed(() => {
    const surfaceBorder = '#3a3a3a'; 
    const textColorSecondary = '#9ca3af'; 

    return {
        maintainAspectRatio: false,
        aspectRatio: 0.8,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1a1a1a',
                titleColor: '#ffffff',
                bodyColor: '#e5e7eb',
                borderColor: '#3a3a3a',
                borderWidth: 1,
                padding: 12,
                displayColors: false,
            }
        },
        scales: {
            x: {
                ticks: { color: textColorSecondary, font: { size: 10, family: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace' } },
                grid: { color: surfaceBorder, drawBorder: false, borderDash: [5, 5] }
            },
            y: {
                ticks: { color: textColorSecondary, font: { size: 10 } },
                grid: { color: surfaceBorder, drawBorder: false }
            }
        },
        interaction: { mode: 'index', intersect: false }
    };
});

const revenueChartData = computed(() => {
    return {
        labels: props.chartData.map(d => d.date),
        datasets: [{
            label: 'Ingresos (MXN)',
            data: props.chartData.map(d => d.revenue),
            borderColor: '#3b82f6', 
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 2,
            tension: 0.4, 
            fill: true,
            pointBackgroundColor: '#232323',
            pointBorderColor: '#3b82f6',
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5
        }]
    };
});

const subsChartData = computed(() => {
    return {
        labels: props.chartData.map(d => d.date),
        datasets: [{
            label: 'Nuevos Clientes',
            data: props.chartData.map(d => d.new_subs),
            backgroundColor: '#f97316', 
            borderRadius: 4,
            barPercentage: 0.6,
        }]
    };
});

// --- TESLA UI PT ---
const datePickerPt = {
    root: { class: 'w-full' },
    input: { class: 'w-full min-w-0 !rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !px-3 !text-sm text-gray-900 dark:text-white dark:[color-scheme:dark]' },
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !shadow-2xl' },
    header: { class: 'dark:!bg-[#1a1a1a] !border-b !border-gray-200 dark:!border-[#3a3a3a] !rounded-t-2xl !pt-3 !pb-3' },
    title: { class: 'text-gray-900 dark:text-white font-medium' },
    tableHeaderCell: { class: 'text-gray-500 dark:text-gray-400 text-xs font-medium pb-2' },
    day: { class: 'text-gray-900 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-[#3a3a3a] rounded-full transition-colors text-sm' },
    today: { class: 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 font-bold rounded-full' },
};
</script>

<template>
    <AppLayout title="Reportes y Métricas">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header (Tesla UI) con Filtros -->
                <div class="mb-8 flex flex-col xl:flex-row xl:items-end justify-between gap-6 border-b border-gray-100 dark:border-[#3a3a3a] pb-6">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Métricas del sistema</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                            Rendimiento analítico y telemetría financiera
                        </p>
                    </div>
                    
                    <!-- Controles de Filtro con DatePicker -->
                    <div class="flex flex-col sm:flex-row items-end gap-3 shrink-0">
                        <div class="flex flex-col gap-1.5 w-full sm:w-48">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha Inicial</label>
                            <DatePicker v-model="startDate" dateFormat="dd/mm/yy" :pt="datePickerPt" showIcon iconDisplay="input" @date-select="applyFilters" />
                        </div>
                        <div class="flex flex-col gap-1.5 w-full sm:w-48">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha Final</label>
                            <DatePicker v-model="endDate" dateFormat="dd/mm/yy" :pt="datePickerPt" showIcon iconDisplay="input" @date-select="applyFilters" />
                        </div>
                        <Button :icon="isLoading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" @click="applyFilters" 
                            severity="secondary" outlined class="!rounded-xl !w-10 !h-10 shrink-0" v-tooltip.top="'Refrescar datos'" />
                    </div>
                </div>

                <!-- Cuadrícula de KPIs -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    
                    <!-- KPI 1: Ingresos Totales -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-wallet !text-6xl text-primary-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Ingreso histórico (Acumulado)
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1">
                            {{ formatCurrency(metrics.totalRevenue) }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">Monto total de pagos aprobados</p>
                    </div>

                    <!-- KPI 2: Ingresos del Periodo -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-chart-line !text-6xl text-green-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2 truncate" :title="displayPeriod">
                            Ingresos del periodo
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1 text-green-600 dark:text-green-400 truncate">
                            {{ formatCurrency(metrics.periodRevenue) }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0 truncate">{{ displayPeriod }}</p>
                    </div>

                    <!-- KPI 3: Suscripciones Activas -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-check-circle !text-6xl text-blue-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Clientes activos
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1">
                            {{ metrics.activeSubscriptions }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">Con plan vigente al día de hoy</p>
                    </div>

                    <!-- KPI 4: Nuevas Altas del periodo -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-users !text-6xl text-orange-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Nuevas suscripciones
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1 text-orange-600 dark:text-orange-400">
                            +{{ metrics.newSubscriptions }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0 truncate">Creadas en: {{ displayPeriod }}</p>
                    </div>

                    <!-- KPI 5: ARPU (Nuevo) -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-star !text-6xl text-teal-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            ARPU (Promedio por Usuario)
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1 text-teal-600 dark:text-teal-400">
                            {{ formatCurrency(arpu) }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0 truncate">Ingreso promedio aportado por cliente</p>
                    </div>

                    <!-- KPI 6: Ticket Promedio (Nuevo) -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-ticket !text-6xl text-purple-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Ticket Promedio (Altas)
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1 text-purple-600 dark:text-purple-400">
                            {{ formatCurrency(averageTicket) }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0 truncate">Valor medio de las nuevas suscripciones</p>
                    </div>

                </div>

                <!-- MÓDULO GRÁFICO (TELEMETRÍA) -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- Gráfica de Ingresos -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                                <i class="pi pi-chart-line text-blue-500"></i> Evolución de Ingresos
                            </h2>
                        </div>
                        <div class="h-64 w-full">
                            <Chart type="line" :data="revenueChartData" :options="chartOptions" class="h-full w-full" />
                        </div>
                    </div>

                    <!-- Gráfica de Clientes Nuevos -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                                <i class="pi pi-chart-bar text-orange-500"></i> Nuevas Suscripciones (Altas)
                            </h2>
                        </div>
                        <div class="h-64 w-full">
                            <Chart type="bar" :data="subsChartData" :options="chartOptions" class="h-full w-full" />
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </AppLayout>
</template>