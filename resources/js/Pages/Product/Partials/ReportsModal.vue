<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    categories: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:visible']);
const visible = defineModel('visible', { type: Boolean, default: false });

// Report type definitions
const reportTypes = [
    { value: 'dead_stock', label: 'Mercancía sin movimiento', icon: 'pi pi-ban', description: 'Productos con stock sin salidas en el período.', needsDateRange: true, needsOrderBy: false, needsGroupBy: false, needsMinStock: true },
    { value: 'top_sellers', label: 'Productos más vendidos', icon: 'pi pi-chart-bar', description: 'Ranking por cantidad, frecuencia o ingreso.', needsDateRange: true, needsOrderBy: true, needsGroupBy: false, needsMinStock: false },
    { value: 'inventory_turnover', label: 'Rotación de inventario', icon: 'pi pi-sync', description: 'Ventas / inventario promedio.', needsDateRange: true, needsOrderBy: false, needsGroupBy: false, needsMinStock: false },
    { value: 'stockouts', label: 'Quiebres de stock', icon: 'pi pi-exclamation-triangle', description: 'Productos que llegaron a cero en el período.', needsDateRange: true, needsOrderBy: false, needsGroupBy: false, needsMinStock: false },
    { value: 'inventory_valuation', label: 'Valorización de inventario', icon: 'pi pi-dollar', description: 'Stock × costo, por producto o categoría.', needsDateRange: false, needsOrderBy: false, needsGroupBy: true, needsMinStock: false },
    { value: 'high_value_stagnant', label: 'Mayor valor inmovilizado', icon: 'pi pi-lock', description: 'Sin movimiento + alto valor en stock.', needsDateRange: true, needsOrderBy: false, needsGroupBy: false, needsMinStock: false },
    { value: 'margin_by_product', label: 'Margen por producto', icon: 'pi pi-percentage', description: 'Ingreso - costo = utilidad real.', needsDateRange: true, needsOrderBy: false, needsGroupBy: false, needsMinStock: false },
];

const selectedReportType = ref('');
const startDate = ref('');
const endDate = ref('');
const categoryId = ref(null);
const orderBy = ref('quantity');
const groupBy = ref('product');
const minStock = ref(1);
const limit = ref(50);
const isLoading = ref(false);
const generatedReport = ref(null);

const selectedReport = computed(() => reportTypes.find(r => r.value === selectedReportType.value));

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
    if (!date) return '';
    const d = new Date(date);
    if (isNaN(d.getTime())) return '';
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function generateReport() {
    if (!selectedReportType.value) return;
    isLoading.value = true;

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

    fetch(route('products.reports.generate') + '?' + params.toString(), {
        headers: { 'Accept': 'application/json' },
    })
    .then(r => r.json())
    .then(data => {
        generatedReport.value = data.report;
        isLoading.value = false;
    })
    .catch(() => {
        isLoading.value = false;
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

// Reset state when modal opens
watch(visible, (val) => {
    if (!val) {
        generatedReport.value = null;
    }
});

// ── Formatters & helpers ──
const formatCurrency = (v) => v != null ? new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v) : '—';
const formatNumber = (v) => v != null ? new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 }).format(v) : '—';

const headerToKey = {
    'Producto': 'name', 'SKU': 'sku', 'Categoría': 'category', 'Marca': 'brand',
    'Stock actual': 'current_stock', 'Stock': 'current_stock',
    'Costo unit.': 'cost_price', 'Costo total': 'total_cost',
    'Precio venta': 'selling_price', 'Valor total': 'total_value',
    'Valor inmovilizado': 'total_value', 'Valor de costo': 'total_value',
    'Última venta': 'last_sale_date', 'Días sin venta': 'days_without_sale',
    'Cantidad vendida': 'total_quantity', 'Vendido': 'total_quantity',
    'Ventas período': 'total_sold', 'Transacciones': 'transaction_count',
    'Ingreso total': 'total_revenue', 'Ingreso': 'total_revenue',
    'Ingreso potencial': 'potential_revenue', 'Margen bruto': 'margin',
    'Margen': 'margin', 'Margen %': 'margin_pct',
    'Rotación (veces)': 'turnover', 'Veces en cero': 'stockout_count',
    'Primer quiebre': 'first_stockout', 'Último quiebre': 'last_stockout',
    'Productos': 'product_count', 'Unidades totales': 'total_stock',
};

const moneyHeaders = ['Costo unit.', 'Costo total', 'Precio venta', 'Valor total', 'Valor inmovilizado', 'Valor de costo', 'Ingreso total', 'Ingreso', 'Ingreso potencial', 'Margen bruto', 'Margen'];
const pctHeaders = ['Margen %'];

function getRowValue(row, header) {
    const key = headerToKey[header] || header.toLowerCase().replace(/ /g, '_');
    return row[key];
}
function formatRowValue(row, header) {
    const v = getRowValue(row, header);
    if (v == null) return '—';
    if (moneyHeaders.includes(header)) return formatCurrency(v);
    if (pctHeaders.includes(header)) return v + '%';
    return typeof v === 'number' ? formatNumber(v) : v;
}

const summaryLabels = {
    total_products: 'Productos', total_value: 'Valor total', total_stock: 'Unidades',
    total_quantity: 'Cantidad total', total_revenue: 'Ingreso total', total_cost: 'Costo total',
    total_margin: 'Margen total', avg_margin_pct: 'Margen promedio',
    potential_revenue: 'Ingreso potencial', total_potential: 'Ingreso potencial',
    avg_turnover: 'Rotación promedio', high_rotation: 'Alta rotación', low_rotation: 'Baja rotación',
    total_stockouts: 'Total quiebres', affected_products: 'Productos afectados',
    avg_days_without_sale: 'Días sin venta (prom.)',
};
</script>

<template>
    <Dialog v-model:visible="visible" modal header="Reportes de inventario"
        :style="{ width: '95vw', maxWidth: '1100px' }"
        :pt="{
            root: { class: '!bg-white dark:!bg-[#232323] !rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a] !shadow-2xl' },
            header: { class: '!bg-transparent !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
            title: { class: '!text-xl !font-light !tracking-tight !text-gray-900 dark:!text-white' },
            content: { class: '!bg-transparent !px-6 !py-5' },
            mask: { class: '!backdrop-blur-sm !bg-gray-900/40 dark:!bg-black/60' },
        }">

        <!-- Step 1: Report type selector -->
        <div v-if="!generatedReport" class="space-y-5">
            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Selecciona el tipo de reporte y ajusta los filtros.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div v-for="rt in reportTypes" :key="rt.value"
                    class="cursor-pointer rounded-2xl border-2 p-3.5 transition-all hover:border-primary-400"
                    :class="selectedReportType === rt.value
                        ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10 dark:border-primary-400'
                        : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a]'"
                    @click="selectedReportType = rt.value">
                    <div class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
                            :class="selectedReportType === rt.value ? 'bg-primary-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'">
                            <i :class="rt.icon" class="!text-xs"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white m-0">{{ rt.label }}</p>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">{{ rt.description }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div v-if="selectedReport" class="border-t border-gray-100 dark:border-[#3a3a3a] pt-4 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                    <template v-if="selectedReport.needsDateRange">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha inicio</label>
                            <DatePicker v-model="startDate" dateFormat="dd/mm/yy" showIcon class="w-full" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha fin</label>
                            <DatePicker v-model="endDate" dateFormat="dd/mm/yy" showIcon class="w-full" />
                        </div>
                    </template>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Categoría</label>
                        <Select v-model="categoryId" :options="categories" optionLabel="name" optionValue="id"
                            placeholder="Todas" showClear class="w-full" :pt="{ root: { class: '!rounded-2xl' } }" />
                    </div>
                    <div v-if="selectedReport.needsOrderBy" class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Ordenar por</label>
                        <Select v-model="orderBy" :options="orderByOptions" optionLabel="label" optionValue="value"
                            class="w-full" :pt="{ root: { class: '!rounded-2xl' } }" />
                    </div>
                    <div v-if="selectedReport.needsGroupBy" class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Agrupar por</label>
                        <Select v-model="groupBy" :options="groupByOptions" optionLabel="label" optionValue="value"
                            class="w-full" :pt="{ root: { class: '!rounded-2xl' } }" />
                    </div>
                    <div v-if="selectedReport.needsMinStock" class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Stock mínimo</label>
                        <InputNumber v-model="minStock" :min="0" class="w-full"
                            :pt="{ input: { root: { class: 'w-full !rounded-2xl' } } }" />
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <Button label="Generar reporte" icon="pi pi-play" @click="generateReport" :loading="isLoading" class="!rounded-full" />
                </div>
            </div>
        </div>

        <!-- Step 2: Report results -->
        <div v-if="generatedReport" class="space-y-5">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                <div>
                    <h3 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">{{ generatedReport.title }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 m-0">{{ generatedReport.subtitle }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <Button label="Imprimir / PDF" icon="pi pi-print" @click="openPrintableReport" severity="secondary" outlined class="!rounded-full" size="small" />
                    <Button label="Nuevo reporte" icon="pi pi-arrow-left" @click="generatedReport = null" text severity="secondary" size="small" class="!rounded-full" />
                </div>
            </div>

            <!-- Summary cards -->
            <div v-if="generatedReport.summary" class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <div v-for="(value, key) in generatedReport.summary" :key="key"
                    class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-3.5 border border-gray-100 dark:border-[#3a3a3a]">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ summaryLabels[key] || key.replace(/_/g, ' ') }}</p>
                    <p class="text-base font-light tracking-tight text-gray-900 dark:text-white m-0 mt-1">
                        {{ typeof value === 'number' && (key.includes('value') || key.includes('revenue') || key.includes('cost') || key.includes('margin') || key.includes('potential')) ? formatCurrency(value) : formatNumber(value) }}
                        <span v-if="key === 'avg_margin_pct'">%</span>
                    </p>
                </div>
            </div>

            <!-- Table -->
            <div v-if="generatedReport.rows && generatedReport.rows.length > 0" class="overflow-x-auto max-h-[50vh]">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-[#3a3a3a] sticky top-0 bg-white dark:bg-[#232323] z-10">
                            <th v-for="h in generatedReport.headers" :key="h"
                                class="text-left py-2.5 px-2.5 text-[9px] uppercase tracking-widest font-bold text-gray-500 whitespace-nowrap">{{ h }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in generatedReport.rows" :key="i"
                            class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors">
                            <td v-for="(h, hi) in generatedReport.headers" :key="hi"
                                class="py-2 px-2.5 whitespace-nowrap"
                                :class="{
                                    'font-medium text-gray-900 dark:text-white': hi === 0,
                                    'text-gray-600 dark:text-gray-400': hi !== 0,
                                    'text-red-500 dark:text-red-400 font-medium': (h === 'Margen bruto' || h === 'Margen %') && getRowValue(row, h) < 0,
                                    'text-green-600 dark:text-green-400 font-medium': (h === 'Margen bruto' || h === 'Margen %') && getRowValue(row, h) > 0,
                                }">
                                {{ formatRowValue(row, h) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty state -->
            <div v-else class="text-center py-10 text-gray-400">
                <i class="pi pi-inbox text-2xl mb-2 block opacity-50"></i>
                <p class="text-xs m-0">No se encontraron registros para este período.</p>
            </div>
        </div>

    </Dialog>
</template>
