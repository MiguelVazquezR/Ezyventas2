<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    invoice: Object,
    timbre: Object,
    comprobante: Object,
    qrCodeSrc: [String, null],
    subtotal: Number,
    discountTotal: Number,
    taxesTotal: Number,
    retainedTotal: Number,
    total: Number,
    groupedTransfers: Array,
    groupedRetentions: Array,
    logoUrl: [String, null],
});

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function handlePrint() {
    window.print();
}

function formatCurrency(value) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: props.invoice.currency ?? 'MXN',
        minimumFractionDigits: 2,
    }).format(parseFloat(value) ?? 0);
}

function formatDate(dateString) {
    if (!dateString) return '—';
    try {
        const d = new Date(dateString);
        return d.toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
    } catch { return dateString; }
}

// ──────────────────────────────────────
// SAT catalog translations (reuses same labels as Create.vue)
// ──────────────────────────────────────
const cfdiUseLabel = ((code) => {
    const map = {
        G01: 'Adquisición de mercancías', G02: 'Devoluciones, descuentos o bonificaciones',
        G03: 'Gastos en general', I01: 'Construcciones', I02: 'Mobiliario y equipo de oficina por inversiones',
        I03: 'Equipo de transporte', I04: 'Equipo de cómputo y accesorios',
        D01: 'Honorarios médicos, dentales y gastos hospitalarios',
        D02: 'Gastos médicos por incapacidad o discapacidad', D03: 'Gastos funerales',
        D04: 'Donativos', D05: 'Intereses reales por créditos hipotecarios',
        D06: 'Aportaciones voluntarias al SAR', D07: 'Primas por seguros de gastos médicos',
        D08: 'Gastos de transportación escolar', D09: 'Depósitos en cuentas de ahorro',
        D10: 'Pagos por servicios educativos', P01: 'Por definir',
    };
    return map[code] || code;
});

const paymentFormLabel = ((code) => {
    const map = {
        '01': 'Efectivo', '02': 'Cheque nominativo', '03': 'Transferencia electrónica de fondos',
        '04': 'Tarjeta de crédito', '28': 'Tarjeta de débito', '99': 'Por definir',
    };
    return map[code] || code;
});

const paymentMethodLabel = ((code) => {
    const map = { PUE: 'Pago en una sola exhibición', PPD: 'Pago en parcialidades o diferido' };
    return map[code] || code;
});

const taxRegimeLabel = ((code) => {
    const map = {
        '601': 'General de Ley Personas Morales', '603': 'Personas Morales con Fines no Lucrativos',
        '605': 'Sueldos y Salarios', '606': 'Arrendamiento', '608': 'Demás ingresos',
        '612': 'Personas Físicas con Actividades Empresariales', '614': 'Ingresos por intereses',
        '616': 'Sin obligaciones fiscales', '620': 'Sociedades Cooperativas',
        '621': 'Incorporación Fiscal', '622': 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '626': 'Régimen Simplificado de Confianza',
    };
    return map[code] || code;
});

const tipoComprobanteLabel = ((code) => {
    const map = { I: 'Ingreso', E: 'Egreso', T: 'Traslado', N: 'Nómina', P: 'Pago' };
    return map[code] || code;
});

const exportacionLabel = ((code) => {
    const map = { '01': 'No aplica', '02': 'Definitiva con clave A1', '03': 'Temporal', '04': 'Definitiva con clave distinta a A1' };
    return map[code] || code;
});

const objetoImpLabel = ((code) => {
    const map = { '01': 'No objeto de impuesto.', '02': 'Sí objeto de impuesto.', '03': 'Sí objeto de impuesto y no obligado al desglose.' };
    return map[code] || code;
});

const currencyLabel = ((code) => {
    const map = { MXN: 'Peso mexicano', USD: 'Dólar estadounidense' };
    return map[code] || code;
});

const unidadLabel = ((code) => {
    const map = { H87: 'Pieza', KGM: 'Kilogramo', LTR: 'Litro', E48: 'Servicio', MTR: 'Metro', NMB: 'Número' };
    return map[code] || code;
});

// ──────────────────────────────────────
// Tax label helpers
// ──────────────────────────────────────
function taxLabel(t) {
    const impuesto = t.impuesto === '002' ? 'IVA' : `Impuesto ${t.impuesto}`;
    const tasa = t.tasaOCuota ? ` ${(t.tasaOCuota * 100).toFixed(2)}%` : '';
    return `${impuesto}${tasa}`;
}

function retentionLabel(r) {
    return r.impuesto === '001' ? 'ISR' : r.impuesto === '002' ? 'IVA' : `Ret. ${r.impuesto}`;
}

// ──────────────────────────────────────
// Item taxes — grouped by Impuesto + TipoFactor + Tasa for each item
// ──────────────────────────────────────
function itemTransfers(item) {
    const transfers = [];
    if (item.objeto_imp === '02' && item.tax_type && parseFloat(item.tax_amount) > 0) {
        transfers.push({
            impuesto: item.tax_type,
            tipo: 'Traslado',
            base: parseFloat(item.subtotal) - parseFloat(item.discount_amount || 0),
            tipoFactor: 'Tasa',
            tasaOCuota: parseFloat(item.tax_rate || 0),
            importe: parseFloat(item.tax_amount || 0),
        });
    }
    return transfers;
}

function itemRetentions(item) {
    const retentions = [];
    if (item.retained_tax_type && parseFloat(item.retained_tax_amount) > 0) {
        retentions.push({
            impuesto: item.retained_tax_type,
            tipo: 'Retención',
            base: parseFloat(item.subtotal) - parseFloat(item.discount_amount || 0),
            tipoFactor: 'Tasa',
            tasaOCuota: parseFloat(item.retained_tax_rate || 0),
            importe: parseFloat(item.retained_tax_amount || 0),
        });
    }
    return retentions;
}

// ──────────────────────────────────────
// Combined emitter postal code + date + time
// ──────────────────────────────────────
const lugarFechaEmision = computed(() => {
    const cp = props.comprobante?.lugar_expedicion || props.invoice?.fiscal_profile?.postal_code || '—';
    const fecha = props.comprobante?.fecha || '—';
    return `${cp}, ${fecha}`;
});
</script>

<template>
    <Head :title="`Factura ${invoice.series ?? ''}${invoice.folio}`" />

    <div class="min-h-screen bg-gray-200 print:bg-white">
        <!-- ════════════════════════════════════════
             Action bar — hidden on print
             ════════════════════════════════════════ -->
        <div class="print:hidden sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-3 flex justify-between items-center">
            <button
                class="text-sm text-gray-600 hover:text-gray-900 transition-colors"
                @click="$inertia.visit(route('billing.invoices.show', invoice.id))"
            >
                ← Volver a la factura
            </button>
            <button
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-md transition-colors"
                @click="handlePrint"
            >
                Imprimir / Guardar como PDF
            </button>
        </div>

        <!-- ════════════════════════════════════════
             Printable sheet — CFDI 4.0 Official Format
             ════════════════════════════════════════ -->
        <div class="max-w-[21cm] mx-auto bg-white shadow-md print:shadow-none my-6 print:my-0 p-8 print:p-6 text-[11px] text-gray-800 leading-relaxed">

            <!-- ════════════════════════════════════════
                 BLOCK 1 — Header: Logo + 2-column info
                 ════════════════════════════════════════ -->
            <div class="flex justify-between items-start mb-6">
                <!-- Logo -->
                <div class="w-1/3">
                    <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="max-h-16 max-w-full object-contain" />
                </div>
            </div>

            <!-- Two-column data grid -->
            <table class="w-full text-[11px] border-collapse mb-6">
                <tbody>
                    <tr>
                        <td class="w-1/2 pr-4 py-0.5"><strong>RFC emisor:</strong> {{ invoice.fiscal_profile?.rfc || '—' }}</td>
                        <td class="w-1/2 pl-4 py-0.5"><strong>Folio fiscal:</strong> {{ timbre.uuid }}</td>
                    </tr>
                    <tr>
                        <td class="pr-4 py-0.5"><strong>Nombre emisor:</strong> {{ invoice.fiscal_profile?.razon_social || '—' }}</td>
                        <td class="pl-4 py-0.5"><strong>No. de serie del CSD:</strong> {{ comprobante.no_certificado }}</td>
                    </tr>
                    <tr>
                        <td class="pr-4 py-0.5"><strong>RFC receptor:</strong> {{ invoice.receiver_rfc }}</td>
                        <td class="pl-4 py-0.5"><strong>Código postal, fecha y hora de emisión:</strong> {{ lugarFechaEmision }}</td>
                    </tr>
                    <tr>
                        <td class="pr-4 py-0.5"><strong>Nombre receptor:</strong> {{ invoice.receiver_legal_name }}</td>
                        <td class="pl-4 py-0.5"><strong>Efecto de comprobante:</strong> {{ tipoComprobanteLabel(comprobante.tipo_de_comprobante) }}</td>
                    </tr>
                    <tr>
                        <td class="pr-4 py-0.5"><strong>Código postal del receptor:</strong> {{ invoice.receiver_postal_code || '—' }}</td>
                        <td class="pl-4 py-0.5"><strong>Régimen fiscal:</strong> {{ taxRegimeLabel(invoice.fiscal_profile?.regimen_fiscal) }}</td>
                    </tr>
                    <tr>
                        <td class="pr-4 py-0.5"><strong>Régimen fiscal receptor:</strong> {{ taxRegimeLabel(invoice.receiver_tax_regime) }}</td>
                        <td class="pl-4 py-0.5"><strong>Exportación:</strong> {{ exportacionLabel(invoice.exportacion) }}</td>
                    </tr>
                    <tr>
                        <td class="pr-4 py-0.5"><strong>Uso CFDI:</strong> {{ cfdiUseLabel(invoice.cfdi_use) }}</td>
                        <td class="pl-4 py-0.5"></td>
                    </tr>
                </tbody>
            </table>

            <!-- ════════════════════════════════════════
                 BLOCK 2 — Conceptos
                 ════════════════════════════════════════ -->
            <div class="mb-4">
                <h2 class="text-sm font-bold m-0 mb-2">Conceptos</h2>

                <!-- Outer concepts table -->
                <table class="w-full border-collapse border border-gray-400 text-[11px]">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border border-gray-400 p-1.5 text-left">Clave del producto y/o servicio</th>
                            <th class="border border-gray-400 p-1.5 text-left">No. identificación</th>
                            <th class="border border-gray-400 p-1.5 text-right">Cantidad</th>
                            <th class="border border-gray-400 p-1.5 text-left">Clave de unidad</th>
                            <th class="border border-gray-400 p-1.5 text-left">Unidad</th>
                            <th class="border border-gray-400 p-1.5 text-right">Valor unitario</th>
                            <th class="border border-gray-400 p-1.5 text-right">Importe</th>
                            <th class="border border-gray-400 p-1.5 text-right">Descuento</th>
                            <th class="border border-gray-400 p-1.5 text-left">Objeto impuesto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(item, idx) in invoice.items" :key="idx">
                            <!-- Main item row -->
                            <tr>
                                <td class="border border-gray-400 p-1.5">{{ item.sat_product_code || '—' }}</td>
                                <td class="border border-gray-400 p-1.5">{{ item.no_identificacion || '' }}</td>
                                <td class="border border-gray-400 p-1.5 text-right">{{ parseFloat(item.quantity) }}</td>
                                <td class="border border-gray-400 p-1.5">{{ item.sat_unit_code || '—' }}</td>
                                <td class="border border-gray-400 p-1.5">{{ unidadLabel(item.sat_unit_code) || item.unit_name || '' }}</td>
                                <td class="border border-gray-400 p-1.5 text-right">{{ formatCurrency(item.unit_price) }}</td>
                                <td class="border border-gray-400 p-1.5 text-right">{{ formatCurrency(parseFloat(item.subtotal || item.quantity * item.unit_price)) }}</td>
                                <td class="border border-gray-400 p-1.5 text-right">{{ parseFloat(item.discount_amount) > 0 ? formatCurrency(item.discount_amount) : '' }}</td>
                                <td class="border border-gray-400 p-1.5">{{ objetoImpLabel(item.objeto_imp) }}</td>
                            </tr>

                            <!-- Sub-row: Description (left) + Tax mini-table (right) -->
                            <tr>
                                <td colspan="5" class="border border-gray-400 p-1.5 align-top">
                                    <strong>Descripción:</strong> {{ item.description }}
                                </td>
                                <td colspan="4" class="border border-gray-400 p-1.5 align-top">
                                    <!-- Mini tax table -->
                                    <table class="w-full border-collapse text-[10px]">
                                        <thead>
                                            <tr class="bg-gray-50">
                                                <th class="border border-gray-300 p-1 text-left">Impuesto</th>
                                                <th class="border border-gray-300 p-1 text-left">Tipo</th>
                                                <th class="border border-gray-300 p-1 text-right">Base</th>
                                                <th class="border border-gray-300 p-1 text-left">Tipo factor</th>
                                                <th class="border border-gray-300 p-1 text-right">Tasa o cuota</th>
                                                <th class="border border-gray-300 p-1 text-right">Importe</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="t in itemTransfers(item)" :key="t.impuesto + 'T'">
                                                <td class="border border-gray-300 p-1">{{ t.impuesto === '002' ? 'IVA' : t.impuesto }}</td>
                                                <td class="border border-gray-300 p-1">{{ t.tipo }}</td>
                                                <td class="border border-gray-300 p-1 text-right">{{ formatCurrency(t.base) }}</td>
                                                <td class="border border-gray-300 p-1">{{ t.tipoFactor }}</td>
                                                <td class="border border-gray-300 p-1 text-right">{{ (t.tasaOCuota * 100).toFixed(2) }}%</td>
                                                <td class="border border-gray-300 p-1 text-right">{{ formatCurrency(t.importe) }}</td>
                                            </tr>
                                            <tr v-for="r in itemRetentions(item)" :key="r.impuesto + 'R'">
                                                <td class="border border-gray-300 p-1">{{ r.impuesto === '001' ? 'ISR' : r.impuesto === '002' ? 'IVA' : r.impuesto }}</td>
                                                <td class="border border-gray-300 p-1">{{ r.tipo }}</td>
                                                <td class="border border-gray-300 p-1 text-right">{{ formatCurrency(r.base) }}</td>
                                                <td class="border border-gray-300 p-1">{{ r.tipoFactor }}</td>
                                                <td class="border border-gray-300 p-1 text-right">{{ (r.tasaOCuota * 100).toFixed(2) }}%</td>
                                                <td class="border border-gray-300 p-1 text-right">{{ formatCurrency(r.importe) }}</td>
                                            </tr>
                                            <tr v-if="itemTransfers(item).length === 0 && itemRetentions(item).length === 0">
                                                <td colspan="6" class="border border-gray-300 p-1 text-gray-400 text-center">—</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            <!-- Sub-row: Pedimento / Cuenta predial -->
                            <tr>
                                <td colspan="5" class="border border-gray-400 p-1.5">
                                    <strong>Número de pedimento:</strong>
                                </td>
                                <td colspan="4" class="border border-gray-400 p-1.5">
                                    <strong>Número de cuenta predial:</strong>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- ════════════════════════════════════════
                 BLOCK 3 — Forma/Método pago + Totals
                 ════════════════════════════════════════ -->
            <div class="flex justify-between items-start mb-6">
                <!-- Left: Payment info -->
                <div class="w-1/2">
                    <table class="text-[11px]">
                        <tbody>
                            <tr>
                                <td class="pr-4 py-0.5"><strong>Moneda:</strong></td>
                                <td class="py-0.5">{{ currencyLabel(invoice.currency) }}</td>
                            </tr>
                            <tr>
                                <td class="pr-4 py-0.5"><strong>Forma de pago:</strong></td>
                                <td class="py-0.5">{{ paymentFormLabel(invoice.payment_form) }}</td>
                            </tr>
                            <tr>
                                <td class="pr-4 py-0.5"><strong>Método de pago:</strong></td>
                                <td class="py-0.5">{{ paymentMethodLabel(invoice.payment_method) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Right: Totals -->
                <div class="w-1/2 flex justify-end">
                    <table class="text-[11px]">
                        <tbody>
                            <tr>
                                <td class="pr-6 py-0.5 text-right"><strong>Subtotal:</strong></td>
                                <td class="py-0.5 text-right">{{ formatCurrency(subtotal) }}</td>
                            </tr>
                            <tr v-for="t in groupedTransfers" :key="t.impuesto + '|' + (t.tasaOCuota || 0)">
                                <td class="pr-6 py-0.5 text-right"><strong>Impuestos trasladados {{ taxLabel(t) }}:</strong></td>
                                <td class="py-0.5 text-right">{{ formatCurrency(t.importe) }}</td>
                            </tr>
                            <tr v-for="r in groupedRetentions" :key="r.impuesto">
                                <td class="pr-6 py-0.5 text-right"><strong>Impuestos retenidos {{ retentionLabel(r) }}:</strong></td>
                                <td class="py-0.5 text-right">− {{ formatCurrency(r.importe) }}</td>
                            </tr>
                            <tr class="border-t border-gray-400">
                                <td class="pr-6 py-1 text-right"><strong>Total:</strong></td>
                                <td class="py-1 text-right"><strong>{{ formatCurrency(total) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 BLOCK 4 — Sellos digitales
                 ════════════════════════════════════════ -->
            <div class="mb-6">
                <p class="m-0 mb-1"><strong>Sello digital del CFDI:</strong></p>
                <p class="text-[9px] break-all m-0 mb-3 text-gray-600 leading-relaxed">{{ timbre.sello_cfd }}</p>

                <p class="m-0 mb-1"><strong>Sello digital del SAT:</strong></p>
                <p class="text-[9px] break-all m-0 text-gray-600 leading-relaxed">{{ timbre.sello_sat }}</p>
            </div>

            <!-- ════════════════════════════════════════
                 BLOCK 5 — Cadena original + QR + Certificador
                 ════════════════════════════════════════ -->
            <div class="mb-6">
                <p class="m-0 mb-1"><strong>Cadena original del complemento de certificación digital del SAT:</strong></p>
                <p class="text-[9px] break-all m-0 mb-4 text-gray-600 leading-relaxed">{{ timbre.cadena_original }}</p>

                <!-- QR + Certifier data side by side -->
                <div class="flex justify-between items-start gap-6">
                    <!-- QR code -->
                    <div class="shrink-0">
                        <img
                            v-if="qrCodeSrc"
                            :src="qrCodeSrc"
                            alt="Código QR verificación SAT"
                            class="w-[120px] h-[120px]"
                        />
                    </div>

                    <!-- Certifier data -->
                    <div class="text-left">
                        <table class="text-[11px]">
                            <tbody>
                                <tr>
                                    <td class="pr-3 py-0.5"><strong>RFC del proveedor de certificación:</strong></td>
                                    <td class="py-0.5">{{ timbre.rfc_prov_certif }}</td>
                                </tr>
                                <tr>
                                    <td class="pr-3 py-0.5"><strong>No. de serie del certificado SAT:</strong></td>
                                    <td class="py-0.5">{{ timbre.no_certificado_sat }}</td>
                                </tr>
                                <tr>
                                    <td class="pr-3 py-0.5"><strong>Fecha y hora de certificación:</strong></td>
                                    <td class="py-0.5">{{ timbre.fecha_timbrado }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 BLOCK 6 — Footer
                 ════════════════════════════════════════ -->
            <div class="border-t border-gray-400 pt-3 mt-6">
                <div class="flex justify-between text-[10px] text-gray-600 mb-3">
                    <span>RFC emisor: {{ invoice.fiscal_profile?.rfc || '—' }}</span>
                    <span>Folio fiscal: {{ timbre.uuid }}</span>
                </div>

                <div class="text-center text-[10px] text-gray-600 leading-relaxed">
                    <p class="m-0"><strong>Este documento es una representación impresa de un CFDI</strong></p>
                    <p class="m-0">
                        El logotipo de esta factura es responsabilidad única y exclusiva de quien la emite,
                        en consecuencia, el SAT queda relevado de cualquier obligación que derive de ello.
                    </p>
                </div>

                <div class="text-right text-[10px] text-gray-500 mt-3">
                    Página 1 de 1
                </div>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    @page {
        size: letter;
        margin: 0.8cm;
    }
    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
