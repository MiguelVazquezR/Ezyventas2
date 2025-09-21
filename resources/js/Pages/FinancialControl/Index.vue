<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { startOfWeek, endOfWeek, subWeeks, startOfMonth, endOfMonth, subMonths, startOfYear, endOfYear, subYears } from 'date-fns';

const props = defineProps({
    kpis: Object,
    chartData: Object,
    incomeByMethod: Object,
    cashRegisters: Array,
    bankAccounts: Array,
    recentSessions: Array,
    filters: Object,
});

const dates = ref();
const lineChartOptions = ref();
const pieChartOptions = ref();

onMounted(() => {
    dates.value = [new Date(props.filters.startDate), new Date(props.filters.endDate)];
    
    // Configuración de la gráfica de líneas
    lineChartOptions.value = {
        maintainAspectRatio: false, aspectRatio: 0.6,
        plugins: { legend: { labels: { color: '#4B5563' } } },
        scales: {
            x: { ticks: { color: '#6B7280' }, grid: { color: '#E5E7EB' } },
            y: { ticks: { color: '#6B7280' }, grid: { color: '#E5E7EB' } }
        }
    };
    
    // Configuración de la gráfica de dona
    pieChartOptions.value = {
        plugins: {
            legend: {
                labels: { color: '#4B5563' },
                position: 'bottom'
            }
        }
    };
});

const lineChartData = computed(() => ({
    labels: props.chartData.labels,
    datasets: [
        { label: 'Ingresos', data: props.chartData.income, fill: false, borderColor: '#10B981', tension: 0.4 },
        { label: 'Gastos', data: props.chartData.expenses, fill: false, borderColor: '#EF4444', tension: 0.4 }
    ]
}));

const incomeByMethodChartData = computed(() => ({
    labels: props.incomeByMethod.labels,
    datasets: [{
        data: props.incomeByMethod.data,
        backgroundColor: ['#10B981', '#3B82F6', '#F97316', '#8B5CF6'],
    }]
}));

const fetchData = () => {
    // Asegurarse de que ambas fechas están seleccionadas para evitar errores
    if (dates.value && dates.value[0] && dates.value[1]) {
        router.get(route('financial-control.index'), {
            start_date: dates.value[0].toISOString().split('T')[0],
            end_date: dates.value[1].toISOString().split('T')[0],
        }, {
            preserveState: true,
            replace: true,
        });
    }
};

// Observar cambios en el DatePicker para recargar los datos
watch(dates, fetchData);

// --- Funciones para botones de rango rápido ---
const setDateRange = (period) => {
    const today = new Date();
    let startDate, endDate;
    switch (period) {
        case 'today':
            startDate = today; endDate = today; break;
        case 'this_week':
            startDate = startOfWeek(today, { weekStartsOn: 1 }); endDate = endOfWeek(today, { weekStartsOn: 1 }); break;
        case 'last_week':
            const lastWeek = subWeeks(today, 1);
            startDate = startOfWeek(lastWeek, { weekStartsOn: 1 }); endDate = endOfWeek(lastWeek, { weekStartsOn: 1 }); break;
        case 'this_month':
            startDate = startOfMonth(today); endDate = endOfMonth(today); break;
        case 'last_month':
            const lastMonth = subMonths(today, 1);
            startDate = startOfMonth(lastMonth); endDate = endOfMonth(lastMonth); break;
        case 'this_year':
            startDate = startOfYear(today); endDate = endOfYear(today); break;
        case 'last_year':
            const lastYear = subYears(today, 1);
            startDate = startOfYear(lastYear); endDate = endOfYear(lastYear); break;
    }
    dates.value = [startDate, endDate];
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>
    <Head title="Control Financiero" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 space-y-6">
            <!-- Header con Filtros -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Control Financiero</h1>
                <div class="flex items-center gap-2 flex-wrap">
                    <DatePicker v-model="dates" selectionMode="range" placeholder="Selecciona un rango" dateFormat="dd/mm/yy" class="w-full md:w-auto" />
                    <Button @click="setDateRange('today')" label="Hoy" text size="small" />
                    <Menu ref="menu" :model="[
                        { label: 'Esta Semana', command: () => setDateRange('this_week') },
                        { label: 'Semana Pasada', command: () => setDateRange('last_week') },
                        { label: 'Este Mes', command: () => setDateRange('this_month') },
                        { label: 'Mes Pasado', command: () => setDateRange('last_month') },
                        { label: 'Este Año', command: () => setDateRange('this_year') },
                        { label: 'Año Pasado', command: () => setDateRange('last_year') },
                    ]" :popup="true" />
                    <Button @click="$refs.menu.toggle($event)" label="Más Rangos" icon="pi pi-calendar" outlined severity="secondary" size="small" />
                    <Button label="Crear Reporte" icon="pi pi-file-pdf" severity="danger" outlined />
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card><template #title>Ingresos</template><template #subtitle>Periodo seleccionado</template><template #content><p class="text-2xl font-bold text-green-600">{{ formatCurrency(kpis.totalIncome) }}</p></template></Card>
                <Card><template #title>Gastos</template><template #subtitle>Periodo seleccionado</template><template #content><p class="text-2xl font-bold text-red-600">{{ formatCurrency(kpis.totalExpenses) }}</p></template></Card>
                <Card><template #title>Beneficio Neto</template><template #subtitle>Periodo seleccionado</template><template #content><p class="text-2xl font-bold" :class="kpis.netProfit >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">{{ formatCurrency(kpis.netProfit) }}</p></template></Card>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Gráfica Principal -->
                <div class="lg:col-span-2">
                    <Card class="h-full">
                         <template #title>Resumen de Ingresos vs. Gastos</template>
                         <template #content><Chart type="line" :data="lineChartData" :options="lineChartOptions" class="h-96" /></template>
                    </Card>
                </div>
                
                <!-- Columna Derecha -->
                <div class="space-y-6">
                    <!-- Desglose de Ingresos -->
                    <Card>
                        <template #title>Ingresos por Método de Pago</template>
                        <template #content>
                            <div class="h-64 flex items-center justify-center">
                                <Chart type="doughnut" :data="incomeByMethodChartData" :options="pieChartOptions" />
                            </div>
                            {{ incomeByMethodChartData }} <br>
                            {{ pieChartOptions }}
                        </template>
                    </Card>
                    <!-- Cuentas Bancarias -->
                    <Card>
                        <template #title>Cuentas Bancarias</template>
                        <template #content>
                            <ul class="space-y-3">
                                <li v-for="account in bankAccounts" :key="account.id" class="flex justify-between items-center">
                                    <div><p class="font-semibold">{{ account.account_name }}</p><p class="text-sm text-gray-500">{{ account.bank_name }}</p></div>
                                    <span class="font-mono font-bold">{{ formatCurrency(account.balance) }}</span>
                                </li>
                            </ul>
                        </template>
                    </Card>
                </div>
            </div>

             <!-- Historial de Cortes y Cajas -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Card>
                    <template #title>Cajas Registradas</template>
                    <template #content>
                        <ul class="space-y-3">
                            <li v-for="register in cashRegisters" :key="register.id" class="flex justify-between items-center">
                                <p class="font-semibold">{{ register.name }}</p>
                                <Tag :value="register.in_use ? 'En Uso' : 'Libre'" :severity="register.in_use ? 'warning' : 'success'" />
                            </li>
                        </ul>
                    </template>
                    <template #footer><Button label="Gestionar Cajas" severity="secondary" text class="w-full" /></template>
                </Card>
                <Card>
                    <template #title>Últimos Cortes de Caja</template>
                    <template #content>
                        <DataTable :value="recentSessions" class="p-datatable-sm">
                            <Column field="id" header="ID Sesión"></Column>
                            <Column field="closed_at" header="Fecha de Cierre"></Column>
                            <Column field="opening_cash_balance" header="Fondo Inicial"><template #body="{data}">{{ formatCurrency(data.opening_cash_balance) }}</template></Column>
                            <Column field="cash_difference" header="Diferencia"><template #body="{data}"><span :class="data.cash_difference < 0 ? 'text-red-500' : 'text-green-500'">{{ formatCurrency(data.cash_difference) }}</span></template></Column>
                        </DataTable>
                    </template>
                    <template #footer><Button label="Ver Todos los Cortes" severity="secondary" text class="w-full" /></template>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>