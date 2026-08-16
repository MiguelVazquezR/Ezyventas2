<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';
import CancelInvoiceModal from './Partials/CancelInvoiceModal.vue';

const props = defineProps({
    invoice: Object,
});

const { hasPermission } = usePermissions();

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(
        parseFloat(value) || 0,
    );

// ──────────────────────────────────────
// Inline file download (same tab, no new window)
// ──────────────────────────────────────
const downloadFile = (url) => {
    const a = document.createElement('a');
    a.href = url;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
};

// Safe external URL opener (same as Index.vue)
const openUrl = (url) => { if (url) window?.open(url, '_blank'); };

const formatDate = (dateString) => {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleDateString('es-MX', {
        year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};

const statusLabel = computed(() => {
    const map = {
        borrador: 'Pre-factura',
        pendiente: 'Pendiente',
        certificada: 'Timbrada',
        en_verificacion: 'En verificación',
        cancelacion_pendiente: 'Cancelación pendiente',
        cancelada: 'Cancelada',
    };
    return map[props.invoice.status] || props.invoice.status;
});

// ──────────────────────────────────────
// Retention detection & breakdown
// ──────────────────────────────────────
const normalizeRfc = (rfc) => (rfc || '').replace(/[\s-]/g, '').toUpperCase();
const isPersonaFisica = (rfc) => normalizeRfc(rfc).length === 13;
const isPersonaMoral = (rfc) => normalizeRfc(rfc).length === 12;

const retentionApplies = computed(() => {
    const emitterRfc = props.invoice.fiscal_profile?.rfc;
    const receiverRfc = props.invoice.receiver_rfc;
    if (!emitterRfc || !receiverRfc) return false;
    return isPersonaFisica(emitterRfc) && isPersonaMoral(receiverRfc);
});

const emitterRegime = computed(() => props.invoice.fiscal_profile?.regimen_fiscal);

const retentionMessage = computed(() => {
    if (!retentionApplies.value) return null;
    const regime = emitterRegime.value;

    if (regime === '626') {
        return 'Por disposición del SAT, cuando una persona física del Régimen Simplificado de Confianza (RESICO) factura a una persona moral, esta última debe retener el 1.25 % de ISR sobre el monto del concepto.';
    }
    if (regime === '606') {
        return 'Por disposición del SAT, cuando una persona física en régimen de Arrendamiento factura a una persona moral, esta última debe retener el 10 % de ISR y las dos terceras partes del IVA trasladado.';
    }
    if (regime === '612') {
        return 'Por disposición del SAT, cuando una persona física por servicios profesionales (honorarios) factura a una persona moral, esta última debe retener el 10 % de ISR y las dos terceras partes del IVA trasladado.';
    }
    return 'Aplican retenciones — Emisor Persona Física → Receptor Persona Moral.';
});

// Group retentions by type from all items
const groupedRetentions = computed(() => {
    const groups = {};
    if (!props.invoice.items) return [];

    props.invoice.items.forEach(item => {
        const allRetentions = [];

        // Multi-retention array (new format)
        if (Array.isArray(item.retentions)) {
            allRetentions.push(...item.retentions);
        }

        // Legacy single retention
        if (allRetentions.length === 0 && item.retained_tax_type && parseFloat(item.retained_tax_amount) > 0) {
            allRetentions.push({
                type: item.retained_tax_type,
                rate: parseFloat(item.retained_tax_rate || 0),
                amount: parseFloat(item.retained_tax_amount || 0),
            });
        }

        allRetentions.forEach(r => {
            const key = r.type || r.impuesto;
            if (!key) return;
            if (!groups[key]) groups[key] = { type: key, amount: 0, rate: 0 };
            groups[key].amount += parseFloat(r.amount || r.importe || 0);
            groups[key].rate = parseFloat(r.rate || r.tasaOCuota || groups[key].rate || 0);
        });
    });

    return Object.values(groups);
});

const retentionLabel = (type) => type === '001' ? 'ISR retenido' : type === '002' ? 'IVA retenido' : `Retención ${type}`;

const statusSeverity = computed(() => {
    const map = {
        borrador: 'info',
        pendiente: 'warn',
        certificada: 'success',
        en_verificacion: 'warn',
        cancelacion_pendiente: 'warn',
        cancelada: 'danger',
    };
    return map[props.invoice.status] || 'secondary';
});

const cfdiUseLabel = ((code) => {
    const map = {
        G01: 'Adquisición de mercancías', G02: 'Devoluciones, descuentos o bonificaciones',
        G03: 'Gastos en general', I01: 'Construcciones', I02: 'Mobiliario y equipo de oficina',
        I03: 'Equipo de transporte', I04: 'Equipo de cómputo', D01: 'Honorarios médicos',
        D02: 'Gastos médicos por incapacidad', D03: 'Gastos funerales', D04: 'Donativos',
        D05: 'Intereses hipotecarios', D06: 'Aportaciones SAR', D07: 'Primas seguros médicos',
        D08: 'Transportación escolar', D09: 'Depósitos en ahorro', D10: 'Servicios educativos',
        P01: 'Por definir',
    };
    return map[code] || code;
});

const paymentFormLabel = ((code) => {
    const map = { '01': 'Efectivo', '02': 'Cheque', '03': 'Transferencia', '04': 'Tarjeta de crédito', '28': 'Tarjeta de débito', '99': 'Por definir' };
    return map[code] || code;
});

const paymentMethodLabel = ((code) => {
    const map = { PUE: 'Pago en una sola exhibición', PPD: 'Pago en parcialidades o diferido' };
    return map[code] || code;
});

const pagoMonedaLabel = ((code) => {
    const map = { MXN: 'Peso mexicano', USD: 'Dólar estadounidense' };
    return map[code] || code;
});

const tipoRelacionLabel = ((code) => {
    const map = {
        '01': 'Nota de crédito de los documentos relacionados',
        '02': 'Débito de los documentos relacionados',
        '03': 'Sustitución de los CFDI previos',
        '04': 'Sustitución de los CFDI por un timbre fiscal digital',
        '05': 'Sustitución de los CFDI por un CFDI de pagos',
        '06': 'Factura de traslado',
        '07': 'CFDI por aplicación de anticipo',
        '08': 'Factura generada por pagos en parcialidades',
        '09': 'Factura generada por pagos diferidos',
    };
    return map[code] || code;
});

// CFDI 4.0 TipoDeComprobante helpers
const isPago = computed(() => props.invoice.tipo_comprobante === 'P');
const isEgreso = computed(() => props.invoice.tipo_comprobante === 'E');

const tipoComprobanteLabel = ((code) => {
    const map = { I: 'Ingreso', E: 'Egreso', T: 'Traslado', N: 'Nómina', P: 'Pago' };
    return map[code] || code;
});

const taxRegimeLabel = ((code) => {
    const map = {
        '601': 'General de Ley Personas Morales', '603': 'Fines no Lucrativos', '612': 'Actividades Empresariales',
        '616': 'Sin obligaciones fiscales', '621': 'Incorporación Fiscal', '626': 'Régimen Simplificado de Confianza',
    };
    return map[code] || code;
});

// ──────────────────────────────────────
// Cancel modal ref
// ──────────────────────────────────────
const cancelModalRef = ref(null);

// ──────────────────────────────────────
// Stamp a draft invoice directly from the detail page
// ──────────────────────────────────────
const stamping = ref(false);

function stampInvoice() {
    if (stamping.value) return;
    stamping.value = true;
    router.post(route('billing.invoices.stamp', props.invoice.id), {}, {
        preserveScroll: true,
        onFinish: () => { stamping.value = false; },
    });
}

// ──────────────────────────────────────
// Tesla UI Pass-Through
// ──────────────────────────────────────
const dataTablePt = {
    root: { class: '!bg-transparent' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-3 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'hover:bg-gray-50 dark:hover:bg-[#1a1a1a]/50 transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-3 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <Head :title="`Factura ${invoice.series ? invoice.series + ' ' : ''}${invoice.folio}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            <!-- Breadcrumb / Back link -->
            <div class="flex items-center">
                <Link :href="route('billing.invoices.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver a facturación
                </Link>
            </div>

            <!-- Header Principal -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-4">
                        Factura {{ invoice.series ? invoice.series + ' ' : '' }}{{ invoice.folio }}
                        <span v-if="invoice.series && !invoice.uuid" class="text-[10px] uppercase tracking-widest font-bold text-gray-400 bg-gray-100 dark:bg-[#1a1a1a] px-3 py-1 rounded-full">Serie {{ invoice.series }}</span>
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <Tag :value="statusLabel" :severity="statusSeverity" :pt="tagPt" />
                        <Tag
                            v-if="invoice.tipo_comprobante"
                            :value="tipoComprobanteLabel(invoice.tipo_comprobante)"
                            severity="secondary"
                            :pt="tagPt"
                        />
                        <Tag
                            v-if="invoice.prices_include_iva"
                            value="Precios con IVA incluido"
                            severity="secondary"
                            :pt="tagPt"
                        />
                        <Link
                            v-if="invoice.transaction"
                            :href="route('transactions.show', invoice.transaction.id)"
                            class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-widest font-bold text-primary dark:text-primary-400 no-underline hover:underline"
                        >
                            <i class="pi pi-shopping-bag !text-[10px]"></i> Venta {{ invoice.transaction.folio }}
                        </Link>

                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>

                        <span
                            v-if="invoice.uuid"
                            class="text-xs text-gray-400 dark:text-gray-500 tracking-wide"
                        >
                            {{ invoice.uuid }}
                        </span>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="w-full sm:w-auto shrink-0 flex gap-2">
                    <Button
                        v-if="invoice.status === 'borrador' || invoice.status === 'pendiente'"
                        label="Timbrar factura"
                        icon="pi pi-shield"
                        severity="success"
                        :loading="stamping"
                        :disabled="stamping"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto"
                        @click="stampInvoice"
                    />
                    <Link
                        v-if="invoice.status === 'borrador'"
                        :href="route('billing.invoices.edit', invoice.id)"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white no-underline transition-colors"
                    >
                        <i class="pi pi-pencil !text-xs"></i> Editar prefactura
                    </Link>
                    <Button
                        v-if="invoice.status === 'certificada' && hasPermission('invoices.cancel')"
                        label="Solicitar cancelación"
                        icon="pi pi-times-circle"
                        severity="danger"
                        outlined
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto"
                        @click="cancelModalRef?.open()"
                    />
                    <Button
                        v-if="invoice.status === 'cancelacion_pendiente'"
                        label="Verificar estatus"
                        icon="pi pi-refresh"
                        severity="warn"
                        outlined
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto"
                        @click="router.post(route('billing.invoices.checkCancelation', invoice.id))"
                    />
                    <Button
                        v-if="invoice.xml_url"
                        icon="pi pi-file-excel !text-sm"
                        label="XML"
                        severity="secondary"
                        outlined
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                        @click="downloadFile(route('billing.invoices.xml', invoice.id))"
                    />
                    <Button
                        icon="pi pi-file-pdf !text-sm"
                        label="PDF"
                        severity="secondary"
                        outlined
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                        @click="openUrl(route('billing.invoices.pdf', invoice.id))"
                    />
                </div>
            </div>
            <!-- Cancelation pending info -->
            <div v-if="invoice.status === 'cancelacion_pendiente'" class="flex items-start gap-2 mt-2 text-xs text-amber-600 dark:text-amber-400">
                <i class="pi pi-clock !text-xs mt-0.5" />
                <span class="m-0">Solicitud de cancelación enviada. Tu cliente (RFC receptor) debe aceptarla o rechazarla ante el SAT. Usa "Verificar estatus" para consultar si ya se resolvió. Mientras tanto, esta factura sigue vigente.</span>
            </div>

            <!-- Two-panel layout -->
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 lg:gap-8">
                <!-- ─── LEFT: Metadata ─── -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Emitter info -->
                    <div v-if="invoice.fiscal_profile" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                                <i class="pi pi-building !text-sm text-primary-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Emisor</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Perfil fiscal del emisor</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.fiscal_profile.rfc }}</span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.fiscal_profile.razon_social }}</span>
                            </div>
                            <div v-if="invoice.fiscal_profile.regimen_fiscal" class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.fiscal_profile.regimen_fiscal }}</span>
                            </div>
                            <div v-if="invoice.fiscal_profile.postal_code" class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Lugar de expedición</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.fiscal_profile.postal_code }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Receiver info -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                <i class="pi pi-user !text-sm text-blue-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Receptor</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Datos del cliente</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.receiver_rfc }}</span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.receiver_legal_name }}</span>
                            </div>
                            <div v-if="invoice.receiver_postal_code" class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Código postal</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.receiver_postal_code }}</span>
                            </div>
                            <div v-if="invoice.receiver_tax_regime" class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">
                                    {{ invoice.receiver_tax_regime }} - {{ taxRegimeLabel(invoice.receiver_tax_regime) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- SAT & Fiscal data -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                                <i class="pi pi-shield !text-sm text-emerald-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Datos fiscales</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Información del CFDI</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">UUID</span>
                                <span
                                    v-if="invoice.uuid"
                                    class="text-sm text-gray-900 dark:text-gray-200 break-all"
                                >{{ invoice.uuid }}</span>
                                <span v-else class="text-sm text-gray-400 dark:text-gray-600 italic">Pendiente de timbrado</span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de timbrado</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ formatDate(invoice.issued_at) }}</span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Uso de CFDI</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">
                                    {{ invoice.cfdi_use }} - {{ cfdiUseLabel(invoice.cfdi_use) }}
                                </span>
                            </div>
                            <Divider class="!my-3 !border-gray-100 dark:!border-[#3a3a3a]" />
                            <div v-if="!isPago" class="grid grid-cols-2 gap-4">
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Forma de pago</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">
                                        {{ invoice.payment_form }} - {{ paymentFormLabel(invoice.payment_form) }}
                                    </span>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Método de pago</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">
                                        {{ invoice.payment_method }} - {{ paymentMethodLabel(invoice.payment_method) }}
                                    </span>
                                </div>
                            </div>
                            <div v-else class="grid grid-cols-2 gap-4">
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Forma de pago real</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">
                                        {{ invoice.pago_forma }} - {{ paymentFormLabel(invoice.pago_forma) }}
                                    </span>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Moneda del pago</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">
                                        {{ invoice.pago_moneda }} - {{ pagoMonedaLabel(invoice.pago_moneda) }}
                                    </span>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha del pago</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ formatDate(invoice.pago_fecha) }}</span>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Monto del pago</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ formatCurrency(invoice.pago_monto) }}</span>
                                </div>
                                <div v-if="invoice.pago_moneda && invoice.pago_moneda !== 'MXN'" class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de cambio del pago</span>
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.pago_tipo_cambio }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CFDI relacionados (nota de crédito) -->
                    <div v-if="isEgreso && (invoice.tipo_relacion || (invoice.cfdi_relacionados && invoice.cfdi_relacionados.length))" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center flex-shrink-0 border border-violet-100 dark:border-violet-900/30">
                                <i class="pi pi-link !text-sm text-violet-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">CFDI relacionados</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Nota de crédito</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div v-if="invoice.tipo_relacion" class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de relación</span>
                                <span class="text-sm text-gray-900 dark:text-gray-200">{{ invoice.tipo_relacion }} - {{ tipoRelacionLabel(invoice.tipo_relacion) }}</span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">UUID relacionados</span>
                                <div class="flex flex-col gap-1.5">
                                    <span v-for="(uuid, i) in (invoice.cfdi_relacionados || [])" :key="i" class="text-sm text-gray-900 dark:text-gray-200 break-all font-mono">{{ uuid }}</span>
                                    <span v-if="!invoice.cfdi_relacionados || invoice.cfdi_relacionados.length === 0" class="text-sm text-gray-400 dark:text-gray-600 italic">Sin UUIDs registrados</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cancellation info (conditional) -->
                    <div
                        v-if="invoice.status === 'cancelada'"
                        class="bg-red-50 dark:bg-red-900/10 p-6 lg:p-8 rounded-3xl border border-red-100 dark:border-red-900/30 flex flex-col"
                    >
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                                <i class="pi pi-times-circle !text-sm text-red-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-red-400 dark:text-red-400/70 tracking-widest uppercase m-0">Cancelación</h2>
                                <p class="text-[10px] text-red-400/70 uppercase tracking-widest mt-1 m-0">Factura cancelada</p>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-red-400 dark:text-red-400/70 m-0">Motivo</span>
                                <span class="text-sm text-red-700 dark:text-red-300 font-medium">
                                    {{ invoice.cancellation_reason }}
                                </span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-red-400 dark:text-red-400/70 m-0">Fecha de cancelación</span>
                                <span class="text-sm text-red-700 dark:text-red-300">{{ formatDate(invoice.canceled_at) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ─── RIGHT: Items + Financials ─── -->
                <div class="lg:col-span-3 space-y-6">
                    <!-- Items table -->
                    <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden flex flex-col">
                        <div class="p-6 lg:p-8 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                                    <i class="pi pi-list !text-sm text-purple-500"></i>
                                </div>
                                <div>
                                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Conceptos</h2>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">{{ invoice.items?.length || 0 }} partidas registradas</p>
                                </div>
                            </div>
                        </div>
                        <DataTable
                            :value="invoice.items"
                            tableStyle="min-width: 40rem"
                            :pt="dataTablePt"
                        >
                            <Column field="quantity" header="Cant.">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ parseFloat(data.quantity) }}</span>
                                </template>
                            </Column>
                            <Column field="sat_product_code" header="Clave SAT">
                                <template #body="{ data }">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ data.sat_product_code || '—' }}</span>
                                </template>
                            </Column>
                            <Column field="description" header="Descripción">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ data.description }}</span>
                                </template>
                            </Column>
                            <Column field="unit_price" header="Precio unit.">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ formatCurrency(data.unit_price) }}</span>
                                </template>
                            </Column>
                            <Column field="total" header="Importe">
                                <template #body="{ data }">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ formatCurrency(data.total) }}</span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>

                    <!-- Documentos del pago (CFDI de Pago) -->
                    <div v-if="isPago" class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden flex flex-col">
                        <div class="p-6 lg:p-8 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0 border border-cyan-100 dark:border-cyan-900/30">
                                    <i class="pi pi-file !text-sm text-cyan-500"></i>
                                </div>
                                <div>
                                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Documentos relacionados del pago</h2>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">{{ (invoice.pago_documentos || []).length }} facturas PPD cubiertas</p>
                                </div>
                            </div>
                        </div>
                        <DataTable :value="invoice.pago_documentos || []" tableStyle="min-width: 40rem" :pt="dataTablePt">
                            <Column field="folio" header="Folio">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ data.folio || '—' }}</span>
                                </template>
                            </Column>
                            <Column field="uuid" header="UUID">
                                <template #body="{ data }">
                                    <span class="text-xs text-gray-500 dark:text-gray-400 break-all">{{ data.uuid || '—' }}</span>
                                </template>
                            </Column>
                            <Column field="num_parcialidad" header="Parcialidad">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-900 dark:text-gray-200">{{ data.num_parcialidad ?? '—' }}</span>
                                </template>
                            </Column>
                            <Column field="imp_saldo_ant" header="Saldo anterior">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ formatCurrency(data.imp_saldo_ant) }}</span>
                                </template>
                            </Column>
                            <Column field="imp_pagado" header="Importe pagado">
                                <template #body="{ data }">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ formatCurrency(data.imp_pagado) }}</span>
                                </template>
                            </Column>
                            <Column field="imp_saldo_insoluto" header="Saldo insoluto">
                                <template #body="{ data }">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ formatCurrency(data.imp_saldo_insoluto) }}</span>
                                </template>
                            </Column>
                            <template #empty>
                                <div class="flex flex-col items-center justify-center py-10 text-center">
                                    <i class="pi pi-inbox !text-3xl text-gray-300 dark:text-gray-600 mb-3"></i>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Sin documentos relacionados registrados</p>
                                </div>
                            </template>
                        </DataTable>
                    </div>

                    <!-- Financial breakdown -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                                <i class="pi pi-calculator !text-sm text-amber-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Desglose financiero</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Subtotal, impuestos y total</p>
                            </div>
                        </div>

                        <!-- Subtotal -->
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Subtotal</span>
                            <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white">
                                {{ formatCurrency(invoice.subtotal) }}
                            </span>
                        </div>

                        <!-- Discount -->
                        <div
                            v-if="parseFloat(invoice.discount_total) > 0"
                            class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]"
                        >
                            <span class="text-sm text-gray-500 dark:text-gray-400">Descuento</span>
                            <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white">
                                − {{ formatCurrency(invoice.discount_total) }}
                            </span>
                        </div>

                        <!-- IVA -->
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-sm text-gray-500 dark:text-gray-400">IVA trasladado</span>
                            <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white">
                                {{ formatCurrency(invoice.taxes_total) }}
                            </span>
                        </div>

                        <!-- Retention banner -->
                        <div v-if="retentionApplies" class="flex items-start gap-2.5 px-4 py-3 my-2 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/40">
                            <i class="pi pi-info-circle !text-sm text-amber-500 mt-px shrink-0"></i>
                            <p class="text-xs text-amber-700 dark:text-amber-300 font-medium leading-relaxed m-0">
                                {{ retentionMessage }}
                            </p>
                        </div>

                        <!-- Retentions -->
                        <div
                            v-for="ret in groupedRetentions"
                            :key="ret.type"
                            class="flex items-center justify-between py-3 border-b border-amber-100 dark:border-amber-900/20"
                        >
                            <span class="text-sm text-amber-700 dark:text-amber-700">{{ retentionLabel(ret.type) }}</span>
                            <span class="text-xl font-light tracking-tight text-amber-700 dark:text-amber-700">
                                − {{ formatCurrency(ret.amount) }}
                            </span>
                        </div>

                        <!-- Currency -->
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Moneda</span>
                            <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white">
                                {{ invoice.currency || 'MXN' }}
                            </span>
                        </div>

                        <!-- Total -->
                        <div class="flex items-center justify-between pt-4">
                            <span class="text-sm font-semibold text-green-600 dark:text-gray-300">Total</span>
                            <span class="text-3xl font-light tracking-tight text-green-600 dark:text-white">
                                {{ formatCurrency(invoice.total) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <CancelInvoiceModal
            ref="cancelModalRef"
            :invoice="invoice"
            @success="router.reload()"
        />
    </AppLayout>
</template>
