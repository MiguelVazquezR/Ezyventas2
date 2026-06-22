<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    categories: Array,
    report: Object,
    filters: Object,
});

// Report type definitions
const reportTypes = [
    {
        value: 'dead_stock',
        label: 'Mercancía sin movimiento',
        icon: 'pi pi-ban',
        description: 'Productos con stock que no han tenido salidas en el período seleccionado.',
        needsDateRange: true,
        needsOrderBy: false,
        needsGroupBy: false,
        needsMinStock: true,
    },
    {
        value: 'top_sellers',
        label: 'Productos más vendidos',
        icon: 'pi pi-chart-bar',
        description: 'Ranking de productos por cantidad vendida, frecuencia de venta o ingreso generado.',
        needsDateRange: true,
        needsOrderBy: true,
        needsGroupBy: false,
        needsMinStock: false,
    },
    {
        value: 'inventory_turnover',
        label: 'Rotación de inventario',
        icon: 'pi pi-sync',
        description: 'Cuántas veces se "voltea" el stock en el período (ventas / inventario promedio).',
        needsDateRange: true,
        needsOrderBy: false,
        needsGroupBy: false,
        needsMinStock: false,
    },
    {
        value: 'stockouts',
        label: 'Quiebres de stock',
        icon: 'pi pi-exclamation-triangle',
        description: 'Productos que llegaron a cero durante el período. Ventas potencialmente perdidas.',
        needsDateRange: true,
        needsOrderBy: false,
        needsGroupBy: false,
        needsMinStock: false,
    },
    {
        value: 'inventory_valuation',
        label: 'Valorización de inventario',
        icon: 'pi pi-dollar',
        description: 'Valor actual del inventario (stock × costo) por producto o categoría.',
        needsDateRange: false,
        needsOrderBy: false,
        needsGroupBy: true,
        needsMinStock: false,
    },
    {
        value: 'high_value_stagnant',
        label: 'Mayor valor inmovilizado',
        icon: 'pi pi-lock',
        description: 'Productos sin movimiento con alto valor en stock. Prioriza qué liquidar primero.',
        needsDateRange: true,
        needsOrderBy: false,
        needsGroupBy: false,
        needsMinStock: false,
    },
    {
        value: 'margin_by_product',
        label: 'Margen por producto',
        icon: 'pi pi-percentage',
        description: 'Cruza ventas con costo para ver qué productos generan mayor utilidad real.',
        needsDateRange: true,
        needsOrderBy: false,
        needsGroupBy: false,
        needsMinStock: false,
    },
];

const selectedReportType = ref(props.filters?.report_type || '');
const startDate = ref(props.filters?.start_date || '');
const endDate = ref(props.filters?.end_date || '');
const categoryId = ref(props.filters?.category_id || null);
const orderBy = ref(props.filters?.order_by || 'quantity');
const groupBy = ref(props.filters?.group_by || 'product');
const minStock = ref(props.filters?.min_stock || 1);
const limit = ref(props.filters?.limit || 50);
const isLoading = ref(false);
const showReportInline = ref(false);
const generatedReport = ref(props.report || null);

const selectedReport = computed(() => {
    return reportTypes.find(r => r.value === selectedReportType.value) || null;
});

const orderByOptions = [
    { label: 'Por cantidad vendida', value: 'quantity' },
    { label: 'Por frecuencia (transacciones)', value: 'transactions' },
    { label: 'Por ingreso generado', value: 'revenue' },
];

const groupByOptions = [
    { label: 'Por producto', value: 'product' },
    { label: 'Por categoría', value: 'category' },
];

function formatDateForServer(date) {
    if (!date) return undefined;
    const d = new Date(date);
    if (isNaN(d.getTime())) return undefined;
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function generateReport() {
    if (!selectedReportType.value) return;

    isLoading.value = true;

    router.get(route('products.reports'), {
        report_type: selectedReportType.value,
        start_date: formatDateForServer(startDate.value),
        end_date: formatDateForServer(endDate.value),
        category_id: categoryId.value || undefined,
        order_by: selectedReport.value?.needsOrderBy ? orderBy.value : undefined,
        group_by: selectedReport.value?.needsGroupBy ? groupBy.value : undefined,
        min_stock: selectedReport.value?.needsMinStock ? minStock.value : undefined,
        limit: limit.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['report', 'filters'],
        onSuccess: (page) => {
            generatedReport.value = page.props.report;
            showReportInline.value = true;
            isLoading.value = false;
        },
        onError: () => {
            isLoading.value = false;
        },
    });
}

function openPrintableReport() {
    if (!selectedReportType.value) return;

    const params = new URLSearchParams({
        report_type: selectedReportType.value,
        ...(startDate.value && { start_date: formatDateForServer(startDate.value) }),
        ...(endDate.value && { end_date: formatDateForServer(endDate.value) }),
        ...(categoryId.value && { category_id: categoryId.value }),
        ...(selectedReport.value?.needsOrderBy && { order_by: orderBy.value }),
        ...(selectedReport.value?.needsGroupBy && { group_by: groupBy.value }),
        ...(selectedReport.value?.needsMinStock && { min_stock: minStock.value }),
        limit: limit.value,
    });

    window.open(route('products.reports.print') + '?' + params.toString(), '_blank');
}

const formatCurrency = (value) => {
    if (value === undefined || value === null) return '—';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatNumber = (value) => {
    if (value === undefined || value === null) return '—';
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 }).format(value);
};

// Maps a Spanish header to the row object key
const headerToKeyMap = {
    'Producto': 'name',
    'SKU': 'sku',
    'Categoría': 'category',
    'Marca': 'brand',
    'Stock actual': 'current_stock',
    'Stock': 'current_stock',
    'Costo unit.': 'cost_price',
    'Costo total': 'total_cost',
    'Precio venta': 'selling_price',
    'Valor total': 'total_value',
    'Valor inmovilizado': 'total_value',
    'Valor de costo': 'total_value',
    'Última venta': 'last_sale_date',
    'Días sin venta': 'days_without_sale',
    'Cantidad vendida': 'total_quantity',
    'Vendido': 'total_quantity',
    'Ventas período': 'total_sold',
    'Transacciones': 'transaction_count',
    'Ingreso total': 'total_revenue',
    'Ingreso': 'total_revenue',
    'Ingreso potencial': 'potential_revenue',
    'Margen bruto': 'margin',
    'Margen': 'margin',
    'Margen %': 'margin_pct',
    'Rotación (veces)': 'turnover',
    'Veces en cero': 'stockout_count',
    'Primer quiebre': 'first_stockout',
    'Último quiebre': 'last_stockout',
    'Productos': 'product_count',
    'Unidades totales': 'total_stock',
};

const moneyHeaders = [
    'Costo unit.', 'Costo total', 'Precio venta', 'Valor total', 'Valor inmovilizado',
    'Valor de costo', 'Ingreso total', 'Ingreso', 'Ingreso potencial',
    'Margen bruto', 'Margen',
];

const pctHeaders = ['Margen %'];

function getRowValue(row, header) {
    const key = headerToKeyMap[header] || header.toLowerCase().replace(/ /g, '_');
    return row[key];
}

function formatRowValue(row, header) {
    const value = getRowValue(row, header);
    if (value === undefined || value === null) return '—';
    if (moneyHeaders.includes(header)) return formatCurrency(value);
    if (pctHeaders.includes(header)) return value + '%';
    if (typeof value === 'number') return formatNumber(value);
    return value;
}
</script>

<template>
    <AppLayout title="Reportes de inventario">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center">
                <Link :href="route('products.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al catálogo de productos
                </Link>
            </div>

            <!-- Header -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Reportes de inventario</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 m-0">Selecciona el tipo de reporte, ajusta los filtros y genera un análisis detallado de tu inventario.</p>
            </div>

            <!-- Report Selector -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4 m-0 flex items-center gap-2">
                    <i class="pi pi-sliders-h text-gray-400"></i> Configuración del reporte
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                    <div v-for="rt in reportTypes" :key="rt.value" 
                        class="cursor-pointer rounded-2xl border-2 p-4 transition-all hover:border-primary-400"
                        :class="selectedReportType === rt.value 
                            ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10 dark:border-primary-400' 
                            : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a]'"
                        @click="selectedReportType = rt.value">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                                :class="selectedReportType === rt.value 
                                    ? 'bg-primary-500 text-white' 
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'">
                                <i :class="rt.icon" class="!text-sm"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white m-0">{{ rt.label }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">{{ rt.description }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div v-if="selectedReport" class="border-t border-gray-100 dark:border-[#3a3a3a] pt-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Date Range -->
                        <template v-if="selectedReport.needsDateRange">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha inicio</label>
                                <DatePicker v-model="startDate" dateFormat="dd/mm/yy" showIcon class="w-full"
                                    :pt="{ input: { class: 'w-full' } }" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha fin</label>
                                <DatePicker v-model="endDate" dateFormat="dd/mm/yy" showIcon class="w-full"
                                    :pt="{ input: { class: 'w-full' } }" />
                            </div>
                        </template>

                        <!-- Category -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Categoría</label>
                            <Select v-model="categoryId" :options="categories" optionLabel="name" optionValue="id" 
                                placeholder="Todas las categorías" showClear
                                class="w-full"
                                :pt="{ root: { class: '!rounded-2xl' } }" />
                        </div>

                        <!-- Order By (top sellers) -->
                        <div v-if="selectedReport.needsOrderBy" class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Ordenar por</label>
                            <Select v-model="orderBy" :options="orderByOptions" optionLabel="label" optionValue="value"
                                class="w-full"
                                :pt="{ root: { class: '!rounded-2xl' } }" />
                        </div>

                        <!-- Group By (valuation) -->
                        <div v-if="selectedReport.needsGroupBy" class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Agrupar por</label>
                            <Select v-model="groupBy" :options="groupByOptions" optionLabel="label" optionValue="value"
                                class="w-full"
                                :pt="{ root: { class: '!rounded-2xl' } }" />
                        </div>

                        <!-- Min Stock (dead stock) -->
                        <div v-if="selectedReport.needsMinStock" class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Stock mínimo</label>
                            <InputNumber v-model="minStock" :min="0" class="w-full"
                                :pt="{ input: { root: { class: 'w-full !rounded-2xl' } } }" />
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <Button :label="'Generar reporte'" icon="pi pi-play" @click="generateReport" :loading="isLoading"
                            class="!rounded-full" />
                        <Button label="Abrir para imprimir" icon="pi pi-print" @click="openPrintableReport"
                            severity="secondary" outlined class="!rounded-full"
                            :disabled="!selectedReportType" />
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!selectedReport" class="text-center py-8 text-gray-400">
                    <i class="pi pi-arrow-up text-3xl mb-2 block"></i>
                    <p class="text-sm m-0">Selecciona un tipo de reporte para comenzar</p>
                </div>
            </div>

            <!-- Report Result (inline) -->
            <div v-if="showReportInline && generatedReport" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] print:shadow-none print:border-none">
                
                <!-- Report header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 pb-4 border-b border-gray-100 dark:border-[#3a3a3a]">
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ generatedReport.title }}</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 m-0">{{ generatedReport.subtitle }}</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <Button label="Imprimir / PDF" icon="pi pi-print" @click="openPrintableReport"
                            severity="secondary" outlined class="!rounded-full" size="small" />
                    </div>
                </div>

                <!-- Summary cards -->
                <div v-if="generatedReport.summary" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div v-for="(value, key) in generatedReport.summary" :key="key"
                        class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-4 border border-gray-100 dark:border-[#3a3a3a]">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ 
                            key === 'total_products' ? 'Productos' :
                            key === 'total_value' ? 'Valor total' :
                            key === 'total_stock' ? 'Unidades' :
                            key === 'total_quantity' ? 'Cantidad' :
                            key === 'total_revenue' ? 'Ingreso total' :
                            key === 'total_cost' ? 'Costo total' :
                            key === 'total_margin' ? 'Margen' :
                            key === 'avg_margin_pct' ? 'Margen prom.' :
                            key === 'potential_revenue' ? 'Ingreso potencial' :
                            key === 'total_potential' ? 'Ingreso potencial' :
                            key === 'avg_turnover' ? 'Rotación prom.' :
                            key === 'high_rotation' ? 'Alta rotación' :
                            key === 'low_rotation' ? 'Baja rotación' :
                            key === 'total_stockouts' ? 'Quiebres' :
                            key === 'affected_products' ? 'Afectados' :
                            key === 'avg_days_without_sale' ? 'Días sin venta prom.' :
                            key.replace(/_/g, ' ')
                        }}</p>
                        <p class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0 mt-1">
                            {{ typeof value === 'number' && (key.includes('value') || key.includes('revenue') || key.includes('cost') || key.includes('margin') || key.includes('potential')) ? formatCurrency(value) : formatNumber(value) }}
                            <span v-if="key === 'avg_margin_pct'" class="text-sm">%</span>
                        </p>
                    </div>
                </div>

                <!-- Data table -->
                <div v-if="generatedReport.rows && generatedReport.rows.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-[#3a3a3a]">
                                <th v-for="header in generatedReport.headers" :key="header"
                                    class="text-left py-3 px-3 text-[10px] uppercase tracking-widest font-bold text-gray-500 whitespace-nowrap">
                                    {{ header }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in generatedReport.rows" :key="idx"
                                class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors">
                                <td v-for="(header, hIdx) in generatedReport.headers" :key="hIdx"
                                    class="py-2.5 px-3 whitespace-nowrap"
                                    :class="{
                                        'font-medium text-gray-900 dark:text-white': hIdx === 0,
                                        'text-gray-600 dark:text-gray-400': hIdx !== 0,
                                        'text-red-500 dark:text-red-400 font-medium': 
                                            (header === 'Margen bruto' || header === 'Margen %') && getRowValue(row, header) < 0,
                                        'text-green-600 dark:text-green-400 font-medium':
                                            (header === 'Margen bruto' || header === 'Margen %') && getRowValue(row, header) > 0,
                                    }">
                                    {{ formatRowValue(row, header) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty rows -->
                <div v-else class="text-center py-12 text-gray-400">
                    <i class="pi pi-inbox text-3xl mb-2 block opacity-50"></i>
                    <p class="text-sm m-0">No se encontraron registros para este reporte en el período seleccionado.</p>
                </div>
            </div>

        </div>
    </AppLayout>
</template>

<style scoped>
/* Print-friendly: hide non-essential elements when printing */
@media print {
    :deep(.layout-topbar),
    :deep(.layout-sidebar),
    :deep(.p-button) {
        display: none !important;
    }
    .print\:shadow-none { box-shadow: none !important; }
    .print\:border-none { border: none !important; }
}
</style>
