<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Chart from 'primevue/chart';
import SelectButton from 'primevue/selectbutton';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';

const props = defineProps({
    period: String,
    metrics: Object,
    chartData: Array,
    topPages: Array,
});

const selectedPeriod = ref(props.period || 'week');

const periodOptions = [
    { label: 'Día', value: 'day' },
    { label: 'Semana', value: 'week' },
    { label: 'Mes', value: 'month' },
];

const applyPeriod = () => {
    router.get(route('admin.visits.index'), { period: selectedPeriod.value }, {
        preserveState: true,
        replace: true,
    });
};

const kpis = computed(() => [
    { label: 'Visitas del periodo', value: props.metrics.periodVisits, hint: props.metrics.periodLabel, icon: 'pi pi-eye', color: 'text-blue-500' },
    { label: 'Visitantes únicos', value: props.metrics.periodVisitors, hint: props.metrics.periodLabel, icon: 'pi pi-users', color: 'text-green-500' },
    { label: 'Visitas totales', value: props.metrics.totalVisits, hint: 'Histórico acumulado', icon: 'pi pi-chart-line', color: 'text-primary-500' },
    { label: 'Visitantes totales', value: props.metrics.totalVisitors, hint: 'Histórico acumulado', icon: 'pi pi-id-card', color: 'text-orange-500' },
]);

// --- CONFIGURACIÓN DE GRÁFICAS (TESLA UI) ---
const chartOptions = computed(() => {
    const surfaceBorder = '#3a3a3a';
    const textColorSecondary = '#9ca3af';

    return {
        maintainAspectRatio: false,
        aspectRatio: 0.8,
        plugins: {
            legend: { display: true, labels: { color: textColorSecondary, boxWidth: 10, font: { size: 10 } } },
            tooltip: {
                backgroundColor: '#1a1a1a',
                titleColor: '#ffffff',
                bodyColor: '#e5e7eb',
                borderColor: '#3a3a3a',
                borderWidth: 1,
                padding: 12,
            },
        },
        scales: {
            x: {
                ticks: { color: textColorSecondary, font: { size: 10, family: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace' } },
                grid: { color: surfaceBorder, drawBorder: false, borderDash: [5, 5] },
            },
            y: {
                ticks: { color: textColorSecondary, font: { size: 10 }, precision: 0 },
                grid: { color: surfaceBorder, drawBorder: false },
            },
        },
        interaction: { mode: 'index', intersect: false },
    };
});

const visitsChartData = computed(() => ({
    labels: props.chartData.map((item) => item.label),
    datasets: [
        {
            label: 'Visitas',
            data: props.chartData.map((item) => item.visits),
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#232323',
            pointBorderColor: '#3b82f6',
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5,
        },
        {
            label: 'Visitantes únicos',
            data: props.chartData.map((item) => item.visitors),
            borderColor: '#22c55e',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            pointBackgroundColor: '#232323',
            pointBorderColor: '#22c55e',
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5,
        },
    ],
}));
</script>


<template>
    <AppLayout title="Visitas del sitio">
        <Head title="Visitas del sitio" />

        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">

                <!-- Header -->
                <div class="mb-8 flex flex-col xl:flex-row xl:items-end justify-between gap-6 border-b border-gray-100 dark:border-[#3a3a3a] pb-6">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Visitas del sitio</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                            Tráfico del sitio público · {{ metrics.periodLabel }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5 w-full sm:w-72 shrink-0">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Periodo</label>
                        <SelectButton v-model="selectedPeriod" :options="periodOptions" optionLabel="label" optionValue="value"
                            :allowEmpty="false" @change="applyPeriod"
                            :pt="{ root: { class: '!rounded-full' } }" />
                    </div>
                </div>

                <!-- KPIs -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div v-for="kpi in kpis" :key="kpi.label"
                        class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i :class="[kpi.icon, '!text-6xl', kpi.color]"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ kpi.label }}</h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1">
                            {{ kpi.value.toLocaleString() }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">{{ kpi.hint }}</p>
                    </div>
                </div>

                <!-- Gráfica -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-8">
                    <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-6 flex items-center gap-2">
                        <i class="pi pi-chart-line text-blue-500"></i> Evolución del tráfico
                    </h2>
                    <div class="h-72 w-full">
                        <Chart type="line" :data="visitsChartData" :options="chartOptions" class="h-full w-full" />
                    </div>
                </div>

                <!-- Páginas más visitadas -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                    <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                        <i class="pi pi-map-marker text-orange-500"></i> Páginas más visitadas
                    </h2>
                    <DataTable :value="topPages" dataKey="path" size="small"
                        class="!rounded-2xl overflow-hidden"
                        :pt="{ root: { class: '!bg-transparent' } }">
                        <Column field="path" header="Página" style="min-width: 16rem">
                            <template #body="{ data }">
                                <span class="text-sm font-mono text-gray-900 dark:text-white">{{ data.path }}</span>
                            </template>
                        </Column>
                        <Column field="visits" header="Visitas" style="width: 8rem">
                            <template #body="{ data }">
                                <span class="text-sm font-light text-gray-900 dark:text-white">{{ data.visits.toLocaleString() }}</span>
                            </template>
                        </Column>
                        <Column field="visitors" header="Visitantes únicos" style="width: 10rem">
                            <template #body="{ data }">
                                <span class="text-sm font-light text-green-600 dark:text-green-400">{{ data.visitors.toLocaleString() }}</span>
                            </template>
                        </Column>
                        <template #empty>
                            <p class="text-sm text-gray-400 m-0 py-4">Aún no hay visitas registradas en este periodo.</p>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
