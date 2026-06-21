<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    report: Object,
});

const showPdfHelp = ref(false);

function printReport() {
    window.print();
}

const formatCurrency = (value) => {
    if (value === undefined || value === null) return '—';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatNumber = (value) => {
    if (value === undefined || value === null) return '—';
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 }).format(value);
};

const headerToKeyMap = {
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

const summaryLabels = {
    'total_products': 'Productos',
    'total_value': 'Valor total',
    'total_stock': 'Unidades',
    'total_quantity': 'Cantidad total',
    'total_revenue': 'Ingreso total',
    'total_cost': 'Costo total',
    'total_margin': 'Margen total',
    'avg_margin_pct': 'Margen promedio',
    'potential_revenue': 'Ingreso potencial',
    'total_potential': 'Ingreso potencial',
    'avg_turnover': 'Rotación promedio',
    'high_rotation': 'Alta rotación',
    'low_rotation': 'Baja rotación',
    'total_stockouts': 'Total quiebres',
    'affected_products': 'Productos afectados',
    'avg_days_without_sale': 'Días sin venta (prom.)',
};
</script>

<template>
    <Head title="Reporte de inventario" />
    <div v-if="report" class="report-container">

        <!-- Action bar (hidden when printing) -->
        <div class="action-bar no-print">
            <button class="btn-print" @click="printReport">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 12H4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Imprimir / Guardar como PDF
            </button>
            <button class="btn-help" @click="showPdfHelp = !showPdfHelp">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                ¿Cómo guardar como PDF?
            </button>
        </div>

        <!-- PDF help tooltip -->
        <div v-if="showPdfHelp" class="pdf-help-box no-print">
            <button class="pdf-help-close" @click="showPdfHelp = false">&times;</button>
            <p class="pdf-help-title">Para guardar como PDF:</p>
            <ol class="pdf-help-steps">
                <li>Haz clic en <strong>"Imprimir / Guardar como PDF"</strong> o presiona <kbd>Ctrl</kbd> + <kbd>P</kbd>.</li>
                <li>En el diálogo de impresión, selecciona como destino <strong>"Guardar como PDF"</strong>.</li>
                <li>Ajusta el diseño a <strong>Horizontal</strong> si el reporte es ancho.</li>
                <li>Haz clic en <strong>Guardar</strong> y elige la ubicación del archivo.</li>
            </ol>
        </div>

        <!-- Header -->
        <div class="report-header">
            <h1>{{ report.title }}</h1>
            <p class="subtitle">{{ report.subtitle }}</p>
            <p class="generated-at">Generado el {{ new Date().toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</p>
        </div>

        <!-- Summary -->
        <div v-if="report.summary" class="summary-grid">
            <div v-for="(value, key) in report.summary" :key="key" class="summary-item">
                <span class="summary-label">{{ summaryLabels[key] || key.replace(/_/g, ' ') }}</span>
                <span class="summary-value">
                    {{ typeof value === 'number' && (key.includes('value') || key.includes('revenue') || key.includes('cost') || key.includes('margin') || key.includes('potential')) ? formatCurrency(value) : formatNumber(value) }}
                    <span v-if="key === 'avg_margin_pct'">%</span>
                </span>
            </div>
        </div>

        <!-- Table -->
        <div v-if="report.rows && report.rows.length > 0">
            <table>
                <thead>
                    <tr>
                        <th v-for="header in report.headers" :key="header">{{ header }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, idx) in report.rows" :key="idx">
                        <td v-for="(header, hIdx) in report.headers" :key="hIdx"
                            :class="{
                                'col-name': hIdx === 0,
                                'col-negative': (header === 'Margen bruto' || header === 'Margen %') && getRowValue(row, header) < 0,
                                'col-positive': (header === 'Margen bruto' || header === 'Margen %') && getRowValue(row, header) > 0,
                            }">
                            {{ formatRowValue(row, header) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Empty -->
        <div v-else class="empty-state">
            <p>No se encontraron registros para este reporte.</p>
        </div>
    </div>

    <div v-else class="empty-state">
        <p>No hay datos de reporte disponibles.</p>
    </div>
</template>

<style scoped>
* { margin: 0; padding: 0; box-sizing: border-box; }

.report-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 32px 24px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #1a1a1a;
    background: #fff;
}

.report-header {
    text-align: center;
    margin-bottom: 32px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}

.report-header h1 {
    font-size: 24px;
    font-weight: 300;
    letter-spacing: -0.5px;
    color: #111827;
}

.subtitle {
    font-size: 13px;
    color: #6b7280;
    margin-top: 6px;
}

.generated-at {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

.summary-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 28px;
}

.summary-item {
    flex: 1;
    min-width: 140px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.summary-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    color: #9ca3af;
}

.summary-value {
    font-size: 20px;
    font-weight: 300;
    letter-spacing: -0.5px;
    color: #111827;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

thead th {
    text-align: left;
    padding: 10px 12px;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    color: #9ca3af;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody td {
    padding: 9px 12px;
    border-bottom: 1px solid #f3f4f6;
    white-space: nowrap;
}

.col-name {
    font-weight: 500;
    color: #111827;
}

.col-negative { color: #ef4444; font-weight: 500; }
.col-positive { color: #10b981; font-weight: 500; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}

/* Print styles */
@media print {
    .no-print { display: none !important; }
    .report-container {
        padding: 16px 0;
    }
    .summary-item {
        background: #fff;
        break-inside: avoid;
    }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; }
}

/* Action bar */
.action-bar {
    display: flex;
    justify-content: center;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.btn-print {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    background: #111827;
    color: #fff;
    border: none;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.5px;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-print:hover { background: #1f2937; }

.btn-help {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    background: #fff;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-help:hover { background: #f9fafb; border-color: #9ca3af; }

.pdf-help-box {
    position: relative;
    max-width: 560px;
    margin: 0 auto 24px;
    padding: 18px 22px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 16px;
    font-size: 13px;
    color: #1e40af;
    line-height: 1.6;
}
.pdf-help-close {
    position: absolute;
    top: 8px;
    right: 12px;
    background: none;
    border: none;
    font-size: 20px;
    color: #93c5fd;
    cursor: pointer;
    line-height: 1;
}
.pdf-help-close:hover { color: #1e40af; }
.pdf-help-title {
    font-weight: 700;
    margin: 0 0 8px;
    font-size: 14px;
}
.pdf-help-steps {
    margin: 0;
    padding-left: 20px;
}
.pdf-help-steps li {
    margin-bottom: 4px;
}
.pdf-help-steps kbd {
    display: inline-block;
    padding: 1px 6px;
    font-size: 11px;
    font-family: inherit;
    background: #dbeafe;
    border: 1px solid #93c5fd;
    border-radius: 4px;
}
</style>
