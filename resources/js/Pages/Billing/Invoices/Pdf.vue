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
// Retention detection for PDF display
// ──────────────────────────────────────
const normalizeRfc = (rfc) => (rfc || '').replace(/[\s-]/g, '').toUpperCase();
const isPersonaFisicaPdf = (rfc) => normalizeRfc(rfc).length === 13;
const isPersonaMoralPdf = (rfc) => normalizeRfc(rfc).length === 12;

const retentionApplies = computed(() => {
    const emitterRfc = props.invoice?.fiscal_profile?.rfc;
    const receiverRfc = props.invoice?.receiver_rfc;
    if (!emitterRfc || !receiverRfc) return false;
    return isPersonaFisicaPdf(emitterRfc) && isPersonaMoralPdf(receiverRfc);
});

const emitterRegime = computed(() => props.invoice?.fiscal_profile?.regimen_fiscal);

const retentionMessage = computed(() => {
    if (!retentionApplies.value) return null;
    const regime = emitterRegime.value;

    if (regime === '626') {
        return '';
    }
    if (regime === '606') {
        return '';
    }
    if (regime === '612') {
        return '';
    }
    return '';
});

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
             Printable sheet — CFDI 4.0 Modern High-Density
             ════════════════════════════════════════ -->
        <div class="max-w-[21cm] mx-auto bg-white shadow-md print:shadow-none my-6 print:my-0 p-6 print:p-4 text-[10px] text-gray-900 leading-tight tracking-tight">

            <!-- ════════════════════════════════════════
                 BLOCK 1 — Header: 3-column grid
                 ════════════════════════════════════════ -->
            <div class="grid grid-cols-3 gap-x-5 gap-y-1.5 mb-5">
                <!-- Col 1: Logo + Emitter -->
                <div class="space-y-1">
                    <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="max-h-12 max-w-full object-contain mb-1" />
                    <div class="text-[10px] uppercase tracking-widest font-bold text-gray-400 mb-0.5">Emisor</div>
                    <div class="space-y-0.5">
                        <p class="m-0 font-semibold text-gray-900">{{ invoice.fiscal_profile?.razon_social || '—' }}</p>
                        <p class="text-[11px] m-0 text-gray-700">
                            <span class="text-[11px] font-medium text-gray-900">RFC:</span> {{ invoice.fiscal_profile?.rfc || '—' }}
                        </p>
                        <p class="text-[11px] m-0 text-gray-700">
                            <span class="text-[11px] font-medium text-gray-900">Régimen:</span> {{ taxRegimeLabel(invoice.fiscal_profile?.regimen_fiscal) }}
                        </p>
                    </div>
                </div>

                <!-- Col 2: Receiver -->
                <div class="space-y-1">
                    <div class="text-[10px] uppercase tracking-widest font-bold text-gray-400 mb-0.5">Receptor</div>
                    <div class="space-y-0.5">
                        <p class="m-0 font-semibold text-gray-900">{{ invoice.receiver_legal_name }}</p>
                        <p class="text-[11px] m-0 text-gray-700">
                            <span class="text-[11px] font-medium text-gray-900">RFC:</span> {{ invoice.receiver_rfc }}
                        </p>
                        <p class="text-[11px] m-0 text-gray-700">
                            <span class="text-[11px] font-medium text-gray-900">Régimen:</span> {{ taxRegimeLabel(invoice.receiver_tax_regime) }}
                        </p>
                        <p class="text-[11px] m-0 text-gray-700">
                            <span class="text-[11px] font-medium text-gray-900">CP:</span> {{ invoice.receiver_postal_code || '-' }}
                        </p>
                        <p class="text-[11px] m-0 text-gray-700">
                            <span class="text-[11px] font-medium text-gray-900">Uso CFDI:</span> {{ invoice.cfdi_use }} - {{ cfdiUseLabel(invoice.cfdi_use) }}
                        </p>
                    </div>
                </div>

                <!-- Col 3: Comprobante metadata -->
                <div class="bg-gray-50 rounded-lg p-2.5 space-y-1">
                    <div class="text-[10px] uppercase tracking-widest font-bold text-gray-400 mb-0.5">Comprobante</div>
                    <p class="text-[11px] m-0 text-gray-700 break-all leading-tight">
                        <span class="text-[11px] font-medium text-gray-900">UUID:</span> {{ timbre.uuid }}
                    </p>
                    <p class="text-[11px] m-0 text-gray-700">
                        <span class="text-[11px] font-medium text-gray-900">CSD:</span> {{ comprobante.no_certificado }}
                    </p>
                    <p class="text-[11px] m-0 text-gray-700">
                        <span class="text-[11px] font-medium text-gray-900">Emisión:</span> {{ lugarFechaEmision }}
                    </p>
                    <p class="text-[11px] m-0 text-gray-700">
                        <span class="text-[11px] font-medium text-gray-900">Efecto de comprobante:</span> {{ tipoComprobanteLabel(comprobante.tipo_de_comprobante) }}
                    </p>
                    <p class="text-[11px] m-0 text-gray-700">
                        <span class="text-[11px] font-medium text-gray-900">Exportación:</span> {{ exportacionLabel(invoice.exportacion) }}
                    </p>
                </div>
            </div>

            <hr class="border-gray-200 mb-2">

            <!-- ════════════════════════════════════════
                 BLOCK 2 — Conceptos (ultra-compact)
                 ════════════════════════════════════════ -->
            <div class="mb-4">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400">Conceptos</span>
                </div>

                <table class="w-full border-collapse text-[10px]">
                    <thead>
                        <tr class="bg-gray-50 border-y border-gray-200">
                            <th class="p-1.5 text-left font-semibold text-gray-500">Clave Prod/Serv</th>
                            <th class="p-1.5 text-left font-semibold text-gray-500">No. Ident.</th>
                            <th class="p-1.5 text-right font-semibold text-gray-500 w-[7%]">Cant.</th>
                            <th class="p-1.5 text-left font-semibold text-gray-500">Unidad</th>
                            <th class="p-1.5 text-right font-semibold text-gray-500">V. Unitario</th>
                            <th class="p-1.5 text-right font-semibold text-gray-500">Descuento</th>
                            <th class="p-1.5 text-right font-semibold text-gray-500">Importe</th>
                            <th class="p-1.5 text-left font-semibold text-gray-500">Obj. Imp.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(item, idx) in invoice.items" :key="idx">
                            <!-- Main data row -->
                            <tr class="border-b border-gray-100">
                                <td class="p-1.5 text-gray-900 text-[9px]">{{ item.sat_product_code || '-' }}</td>
                                <td class="p-1.5 text-gray-500 text-[9px]">{{ item.no_identificacion || '-' }}</td>
                                <td class="p-1.5 text-right text-gray-900">{{ parseFloat(item.quantity) }}</td>
                                <td class="p-1.5 text-gray-700">
                                    {{ item.sat_unit_code || '' }}{{ item.unit_name ? ' - ' + item.unit_name : '' }}{{ unidadLabel(item.sat_unit_code) && !item.unit_name ? ' - ' + unidadLabel(item.sat_unit_code) : '' }}
                                </td>
                                <td class="p-1.5 text-right text-gray-500">{{ parseFloat(item.discount_amount) > 0 ? formatCurrency(item.discount_amount) : '-' }}</td>
                                <td class="p-1.5 text-right text-gray-900">{{ formatCurrency(item.unit_price) }}</td>
                                <td class="p-1.5 text-right text-gray-900 font-medium">{{ formatCurrency(parseFloat(item.subtotal || item.quantity * item.unit_price)) }}</td>
                                <td class="p-1.5 text-[9px] text-gray-500">{{ objetoImpLabel(item.objeto_imp) }}</td>
                            </tr>

                            <!-- Metadata sub-row: Description + Taxes inline -->
                            <tr class="border-b border-gray-100">
                                <td colspan="8" class="p-1.5">
                                    <div class="flex flex-wrap items-start gap-x-4 gap-y-1">
                                        <!-- Description -->
                                        <span class="text-gray-900 font-medium">{{ item.description }}</span>

                                        <!-- Tax badges inline -->
                                        <span v-for="t in itemTransfers(item)" :key="t.impuesto + 'T'"
                                            class="inline-flex items-center gap-0.5 bg-gray-50 border border-gray-200 px-1.5 py-0.5 rounded text-[8px] text-gray-700 font-medium">
                                            {{ t.impuesto === '002' ? 'IVA' : t.impuesto }} {{ (t.tasaOCuota * 100).toFixed(0) }}%: {{ formatCurrency(t.importe) }}
                                        </span>
                                        <span v-for="r in itemRetentions(item)" :key="r.impuesto + 'R'"
                                            class="inline-flex items-center gap-0.5 bg-gray-50 border border-gray-200 px-1.5 py-0.5 rounded text-[8px] text-gray-700 font-medium">
                                            {{ retentionLabel(r) }} {{ (r.tasaOCuota * 100).toFixed(0) }}%: −{{ formatCurrency(r.importe) }}
                                        </span>

                                        <!-- Pedimento / Cuenta predial (tiny, at the end) -->
                                        <span v-if="item.numero_pedimento" class="text-[8px] text-gray-400 ml-auto">Pedimento: {{ item.numero_pedimento }}</span>
                                        <span v-if="item.cuenta_predial" class="text-[8px] text-gray-400 ml-auto">Cta. Predial: {{ item.cuenta_predial }}</span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <hr class="border-gray-200 mb-4">

            <!-- ════════════════════════════════════════
                 BLOCK 3 — Payment + Totals (mirror layout)
                 ════════════════════════════════════════ -->
            <div class="flex justify-between items-start gap-8 mb-5">
                <!-- Left: Payment info -->
                <div class="space-y-0.5">
                    <div class="text-[9px] uppercase tracking-widest font-bold text-gray-400 mb-1">Condiciones de pago</div>
                    <div class="flex gap-1 text-gray-900">
                        <span class="font-medium text-gray-600">Moneda:</span> {{ currencyLabel(invoice.currency) }}
                    </div>
                    <div class="flex gap-1 text-gray-900">
                        <span class="font-medium text-gray-600">Forma de pago:</span> {{ invoice.payment_form }} - {{ paymentFormLabel(invoice.payment_form) }}
                    </div>
                    <div class="flex gap-1 text-gray-900">
                        <span class="font-medium text-gray-600">Método de pago:</span> {{ invoice.payment_method }} - {{ paymentMethodLabel(invoice.payment_method) }}
                    </div>
                </div>

                <!-- Right: Totals -->
                <div class="min-w-[220px]">
                    <table class="w-full text-[10px]">
                        <tbody>
                            <tr class="border-b border-gray-100">
                                <td class="py-0.5 pr-6 text-right text-gray-600 font-medium">Subtotal</td>
                                <td class="py-0.5 text-right text-gray-900">{{ formatCurrency(subtotal) }}</td>
                            </tr>
                            <tr v-if="parseFloat(discountTotal) > 0" class="border-b border-gray-100">
                                <td class="py-0.5 pr-6 text-right text-gray-600 font-medium">Descuento</td>
                                <td class="py-0.5 text-right text-gray-500">− {{ formatCurrency(discountTotal) }}</td>
                            </tr>
                            <tr v-for="t in groupedTransfers" :key="t.impuesto + '|' + (t.tasaOCuota || 0)" class="border-b border-gray-100">
                                <td class="py-0.5 pr-6 text-right text-gray-600 font-medium">{{ taxLabel(t) }} trasladado</td>
                                <td class="py-0.5 text-right text-gray-900">{{ formatCurrency(t.importe) }}</td>
                            </tr>
                            <tr v-for="r in groupedRetentions" :key="r.impuesto" class="border-b border-gray-100">
                                <td class="py-0.5 pr-6 text-right text-gray-600 font-medium">{{ retentionLabel(r) }} retenido</td>
                                <td class="py-0.5 text-right text-gray-900">− {{ formatCurrency(r.importe) }}</td>
                            </tr>
                            <tr>
                                <td class="py-0.5 pr-6 text-right font-bold text-gray-900 text-xs">Total</td>
                                <td class="py-0.5 text-right font-bold text-gray-900 text-xs">{{ formatCurrency(total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <hr class="border-gray-200 mb-2">

            <!-- ════════════════════════════════════════
                 BLOCK 4 — Sellos + QR + Certificador (parallel)
                 ════════════════════════════════════════ -->
            <div class="flex gap-5 mb-3">
                <!-- QR + Certifier -->
                <div class="shrink-0 flex flex-col items-center gap-0">
                    <img v-if="qrCodeSrc" :src="qrCodeSrc" alt="QR verificación SAT" class="w-[90px] h-[90px]" />
                    <span class="text-[8px] text-gray-400 text-center leading-tight">Sello digital del SAT</span>
                </div>

                <!-- Certifier data -->
                <div class="flex-1 space-y-0.5 text-[9px]">
                    <div class="text-[9px] uppercase tracking-widest font-bold text-gray-400 mb-1">Certificación</div>
                    <p class="m-0 text-gray-900">
                        <span class="font-medium text-gray-600">RFC Prov. Certif.:</span> {{ timbre.rfc_prov_certif }}
                    </p>
                    <p class="m-0 text-gray-900">
                        <span class="font-medium text-gray-600">Certificado SAT:</span> {{ timbre.no_certificado_sat }}
                    </p>
                    <p class="m-0 text-gray-900">
                        <span class="font-medium text-gray-600">Fecha y hora:</span> {{ timbre.fecha_timbrado }}
                    </p>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 BLOCK 5 — Sellos digitales (ultra-compact)
                 ════════════════════════════════════════ -->
            <div class="space-y-1.5 mb-4">
                <div>
                    <p class="m-0 text-[8px] font-semibold text-gray-500 uppercase tracking-wider">Sello digital del CFDI</p>
                    <p class="text-[7px] break-all m-0 text-gray-500 leading-tight">{{ timbre.sello_cfd }}</p>
                </div>
                <div>
                    <p class="m-0 text-[8px] font-semibold text-gray-500 uppercase tracking-wider">Sello digital del SAT</p>
                    <p class="text-[7px] break-all m-0 text-gray-500 leading-tight">{{ timbre.sello_sat }}</p>
                </div>
                <div>
                    <p class="m-0 text-[8px] font-semibold text-gray-500 uppercase tracking-wider">Cadena original del complemento de certificación digital del SAT</p>
                    <p class="text-[7px] break-all m-0 text-gray-500 leading-tight">{{ timbre.cadena_original }}</p>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 BLOCK 6 — Footer
                 ════════════════════════════════════════ -->
            <div class="border-t border-gray-200 pt-3 mt-4">
                <div class="flex justify-between text-[8px] text-gray-400 mb-2">
                    <span>RFC emisor: {{ invoice.fiscal_profile?.rfc || '—' }}</span>
                    <span>Folio fiscal: {{ timbre.uuid }}</span>
                </div>

                <div class="text-center text-[7.5px] text-gray-400 leading-relaxed space-y-0.5">
                    <p class="m-0 font-medium text-gray-500">Este documento es una representación impresa de un CFDI</p>
                    <p class="m-0">
                        El logotipo de esta factura es responsabilidad única y exclusiva de quien la emite,
                        en consecuencia, el SAT queda relevado de cualquier obligación que derive de ello.
                    </p>
                </div>

                <div class="text-right text-[8px] text-gray-400 mt-2">
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
