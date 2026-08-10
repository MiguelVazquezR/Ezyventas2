<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useInvoiceTaxes } from '@/Composables/useInvoiceTaxes';
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';
import EmisorSection from './EmisorSection.vue';
import ReceptorSection from './ReceptorSection.vue';
import IngresoForm from './IngresoForm.vue';
import EgresoForm from './EgresoForm.vue';
import PagoForm from './PagoForm.vue';
import TrasladoForm from './TrasladoForm.vue';
import { toArray, mapInvoiceItems, mapPagoDocuments, pagoConceptItem, blankPagoDocument } from '../formHelpers';

const props = defineProps({
    mode: { type: String, required: true }, // 'create' | 'edit'
    invoice: { type: Object, default: null },
    customers: { type: [Array, Object], default: () => [] },
    fiscalProfiles: { type: [Array, Object], default: () => [] },
    hasFiscalProfiles: { type: Boolean, default: false },
    ppdInvoices: { type: [Array, Object], default: () => [] },
    products: { type: [Array, Object], default: () => [] },
    services: { type: [Array, Object], default: () => [] },
});

const emit = defineEmits(['submit']);

// ──────────────────────────────────────
// Normalize collections
// ──────────────────────────────────────
const fiscalProfilesList = computed(() => toArray(props.fiscalProfiles));
const customersList = computed(() => toArray(props.customers));
const ppdInvoicesList = computed(() => toArray(props.ppdInvoices));
const hasProfiles = computed(() => props.hasFiscalProfiles || fiscalProfilesList.value.length > 0);

// Más de un RFC emisor en la cuenta: obliga a elegir emisor antes de usar las
// listas de facturas relacionadas (se filtran por emisor).
const multipleEmitters = computed(() => fiscalProfilesList.value.length > 1);

const isEdit = props.mode === 'edit';
const inv = props.invoice;

// DB datetime (e.g. "2026-08-05 14:30:00") → JS Date for the DatePicker
const parsePagoFecha = (value) => {
    if (!value) return null;
    const d = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? null : d;
};

// ──────────────────────────────────────
// Form
// ──────────────────────────────────────
const form = useForm({
    receiver_rfc: isEdit ? (inv?.receiver_rfc || '') : '',
    receiver_legal_name: isEdit ? (inv?.receiver_legal_name || '') : '',
    receiver_tax_regime: isEdit ? (inv?.receiver_tax_regime || '') : '',
    receiver_postal_code: isEdit ? (inv?.receiver_postal_code || '') : '',
    cfdi_use: isEdit ? (inv?.cfdi_use || '') : '',
    fiscal_profile_id: isEdit ? (inv?.fiscal_profile_id || null) : null,
    payment_form: isEdit ? (inv?.payment_form || '') : '',
    payment_method: isEdit ? (inv?.payment_method || '') : '',
    currency: isEdit ? (inv?.currency || 'MXN') : 'MXN',
    exchange_rate: isEdit && inv?.exchange_rate ? parseFloat(inv.exchange_rate) : null,
    exportacion: isEdit ? (inv?.exportacion || '01') : '01',
    tipo_comprobante: isEdit ? (inv?.tipo_comprobante || 'I') : 'I',
    customer_id: isEdit ? (inv?.customer_id || null) : null,
    items: isEdit ? mapInvoiceItems(inv) : [],
    // CFDI relacionados (Tipo E — Nota de crédito)
    tipo_relacion: isEdit ? (inv?.tipo_relacion || '') : '',
    cfdi_relacionados: isEdit ? (inv?.cfdi_relacionados || []) : [],
    // Detalle del pago (Tipo P — Complemento de pago 2.0)
    pago_fecha: isEdit ? parsePagoFecha(inv?.pago_fecha) : null,
    pago_forma: isEdit ? (inv?.pago_forma || '') : '',
    pago_moneda: isEdit ? (inv?.pago_moneda || 'MXN') : 'MXN',
    pago_monto: isEdit && inv?.pago_monto !== null && inv?.pago_monto !== undefined
        ? parseFloat(inv.pago_monto) : null,
    pago_tipo_cambio: isEdit && inv?.pago_tipo_cambio ? parseFloat(inv.pago_tipo_cambio) : null,
    pago_documentos: isEdit ? mapPagoDocuments(inv) : [],
    draft: false,
});

// ──────────────────────────────────────
// Fiscal profile selection
// ──────────────────────────────────────
const selectedFiscalProfile = ref(null);

// Keep the Inertia form in sync with the selected emitter profile.
// Watching the ref (instead of relying on Select's @change in EmisorSection)
// avoids a stale-read: when the parent passes v-model, defineModel does NOT
// update its local value synchronously, so reading model?.id inside @change
// captures the PREVIOUS selection (null on first pick) and fiscal_profile_id
// would arrive empty on submit.
watch(selectedFiscalProfile, (profile) => {
    form.fiscal_profile_id = profile?.id ?? null;
});

onMounted(() => {
    if (props.mode === 'edit' && props.invoice?.fiscal_profile) {
        const match = fiscalProfilesList.value.find(p => p.id === props.invoice.fiscal_profile_id);
        selectedFiscalProfile.value = match || props.invoice.fiscal_profile;
    } else if (fiscalProfilesList.value.length === 1) {
        selectedFiscalProfile.value = fiscalProfilesList.value[0];
    }
});

const initialCustomerName = computed(() =>
    props.mode === 'edit' ? (props.invoice?.customer?.name || '') : '',
);

// ──────────────────────────────────────
// PAC readiness check (certificates + manifest)
// ──────────────────────────────────────
// A profile can only stamp when it has BOTH:
//   1. CSD certificates uploaded (certificate_number set)
//   2. The SAT/SW manifest signed (manifest_signed_at set)
const canStamp = computed(() => {
    const profile = selectedFiscalProfile.value;
    if (!profile) return false;
    return !!profile.certificate_number && !!profile.manifest_signed_at;
});

const profileSelected = computed(() => !!selectedFiscalProfile.value);

// Which required items are missing for the selected profile.
// Empty array means the profile is fully ready to stamp.
const readinessMissing = computed(() => {
    const profile = selectedFiscalProfile.value;
    if (!profile) return [];
    const missing = [];
    if (!profile.certificate_number) missing.push('los certificados');
    if (!profile.manifest_signed_at) missing.push('el manifiesto');
    return missing;
});

// Human-readable warning message. Returns null when there is nothing to warn
// about (profile ready, or no profile selected yet — e.g. multiple profiles
// where the user must pick one first).
const readinessMessage = computed(() => {
    if (!profileSelected.value) return null;
    const missing = readinessMissing.value;
    if (missing.length === 0) return null;
    return `No se puede timbrar facturas porque a este RFC emisor le faltan ${missing.join(' y ')}. Solo puedes crear pre-facturas.`;
});

// Link target for the warning — goes straight to the selected profile's detail page.
const profileSettingsUrl = computed(() => {
    const profile = selectedFiscalProfile.value;
    if (!profile) return '#';
    return route('billing.fiscal-profiles.show', profile.id);
});

// ──────────────────────────────────────
// Comprobante type helpers (TipoDeComprobante)
// ──────────────────────────────────────
const isIngreso = computed(() => form.tipo_comprobante === 'I');
const isEgreso = computed(() => form.tipo_comprobante === 'E');
const isPago = computed(() => form.tipo_comprobante === 'P');
const isTraslado = computed(() => form.tipo_comprobante === 'T');
const isNomina = computed(() => form.tipo_comprobante === 'N');

// Section visibility driven by the selected comprobante type
const showPaymentFormSection = computed(() => isIngreso.value || isEgreso.value);
const showRelatedCfdiSection = computed(() => isEgreso.value);
const showConceptsSection = computed(() => !isPago.value);
const showPaymentDetailSection = computed(() => isPago.value);
const showInvoiceTotals = computed(() => isIngreso.value || isEgreso.value);

// ──────────────────────────────────────
// Sidebar navigation
// ──────────────────────────────────────
const formSections = computed(() => {
    const sections = [
        { id: 'emisor', label: 'Emisor' },
        { id: 'receptor', label: 'Receptor' },
    ];
    if (showPaymentFormSection.value) sections.push({ id: 'forma-pago', label: 'Forma y método de pago' });
    if (showRelatedCfdiSection.value) sections.push({ id: 'cfdi-relacionados', label: 'CFDI relacionados' });
    if (showConceptsSection.value) sections.push({ id: 'conceptos', label: 'Conceptos' });
    if (showPaymentDetailSection.value) sections.push({ id: 'detalle-pago', label: 'Detalle del pago' });
    if (showInvoiceTotals.value) sections.push({ id: 'pago', label: 'Totales' });
    return sections;
});
const { activeSection, scrollTo } = useScrollspy(() => formSections.value.map(s => s.id));

// ──────────────────────────────────────
// Comprobante-type reactive rules
// ──────────────────────────────────────
// Clear fields that only apply to a given comprobante type so the payload
// never carries stale data after switching.
const resetContextualFields = (type) => {
    if (type !== 'E') {
        form.cfdi_relacionados = [];
        form.tipo_relacion = '';
    }
    if (type !== 'P') {
        form.pago_fecha = null;
        form.pago_forma = '';
        form.pago_moneda = 'MXN';
        form.pago_monto = null;
        form.pago_tipo_cambio = null;
        form.pago_documentos = [];
    }
};

/**
 * Applies SAT 4.0 rules that depend on the selected TipoDeComprobante.
 * Runs whenever the user changes the type (and once on mount) so the
 * form state, defaults and validations stay consistent.
 */
const applyComprobanteTypeRules = (type) => {
    form.clearErrors();
    resetContextualFields(type);

    if (type === 'I' || type === 'E') {
        // Leaving payment/traslado mode → restore editable concepts & MXN
        if (form.currency === 'XXX') form.currency = 'MXN';
        form.exchange_rate = null;
        // Restore editable concepts when leaving the automated payment concept
        if (form.items.length === 1
            && form.items[0]?.sat_product_code === '84111506'
            && form.items[0]?.description === 'Pago') {
            form.items = [];
        }
    }

    if (type === 'E') {
        // Nota de crédito: G02 por defecto y método PUE preseleccionado.
        if (!form.cfdi_use) form.cfdi_use = 'G02';
        if (!form.payment_method) form.payment_method = 'PUE';
        if (!form.tipo_relacion) form.tipo_relacion = '01';
        if (!form.cfdi_relacionados || form.cfdi_relacionados.length === 0) {
            form.cfdi_relacionados = [''];
        }
        return;
    }

    if (type === 'P') {
        // CFDI de pago: valores fijos del encabezado (Anexo 20).
        form.exportacion = '01';
        form.cfdi_use = 'CP01';
        form.currency = 'XXX';
        form.exchange_rate = null;
        form.payment_form = '';
        form.payment_method = '';
        form.items = [pagoConceptItem()];
        // Al menos una factura relacionada es obligatoria: se siembra una por
        // defecto (no se puede eliminar) y el usuario puede agregar más.
        if (!form.pago_documentos || form.pago_documentos.length === 0) {
            form.pago_documentos = [blankPagoDocument(true)];
        }
        return;
    }

    if (type === 'T') {
        // Carta porte: sin efectos fiscales y sin importes a nivel concepto.
        form.cfdi_use = 'S01';
        form.currency = 'XXX';
        form.exchange_rate = null;
        form.items.forEach((item) => {
            item.objeto_imp = '01';
            item.unit_price = 0;
            item.discount_amount = 0;
            item.tax_rate = 0;
        });
        return;
    }

    // Tipo N (nómina) no se procesa aquí — se muestra una alerta informativa.
};

watch(() => form.tipo_comprobante, (type) => {
    applyComprobanteTypeRules(type);
}, { immediate: true });

// ──────────────────────────────────────
// Tax calculator
// ──────────────────────────────────────
const { subtotal, ivaTrasladado, isrRetenido, ivaRetenido, granTotal, breakdown, retentionApplies, isResico, retentionMessage } = useInvoiceTaxes(form, fiscalProfilesList, customersList);

// ──────────────────────────────────────
// Submit
// ──────────────────────────────────────
const submit = (draft = false) => {
    form.draft = draft;

    // La validación de "al menos un UUID" (Tipo E) la resuelve el backend en
    // Store/UpdateInvoiceRequest, para que al guardar/timbrar aparezcan TODAS
    // las validaciones obligatorias a la vez.
    const items = form.items;
    const bd = breakdown.value;
    for (let i = 0; i < items.length; i++) {
        const entry = bd[i]; if (!entry) continue;
        const retentions = [];
        if (entry.isrRetention > 0) retentions.push({ type: '001', rate: entry.rates.isrRate, amount: entry.isrRetention });
        if (entry.ivaRetention > 0) retentions.push({ type: '002', rate: entry.rates.ivaRetentionRate, amount: entry.ivaRetention });
        items[i].retentions = retentions.length > 0 ? retentions : null;
        if (retentions.length > 0) {
            items[i].retained_tax_type = retentions[0].type;
            items[i].retained_tax_rate = retentions[0].rate;
            items[i].retained_tax_amount = retentions[0].amount;
        }
    }
    emit('submit', { form, mode: props.mode });
};
</script>

<template>
    <!-- Onboarding guard -->
    <div v-if="!hasProfiles" class="max-w-lg mx-auto bg-white dark:bg-[#121212] border border-slate-100 dark:border-neutral-800 rounded-3xl p-8 text-center mt-10 shadow-sm">
        <i class="pi pi-exclamation-triangle !text-4xl text-amber-400 mb-4 block"></i>
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white m-0 mb-2">Emisor fiscal requerido</h2>
        <p class="text-sm text-slate-500 dark:text-neutral-400 m-0 mb-6">No tienes emisores fiscales. Agrega al menos un RFC emisor para facturar.</p>
        <a :href="route('billing.settings.index')" class="inline-flex items-center gap-2 px-6 py-2.5 bg-black dark:bg-white text-white dark:text-black hover:opacity-80 text-xs font-semibold tracking-wider uppercase rounded-full transition-all duration-200 active:scale-95 no-underline">
            <i class="pi pi-external-link !text-sm"></i> Agregar emisor fiscal
        </a>
    </div>

    <form v-else @submit.prevent="submit(false)" class="tesla-form mt-6 flex flex-col md:flex-row gap-6 items-start relative">
        <!-- Sidebar -->
        <FormNavigationSidebar :sections="formSections" :activeSection="activeSection" @scrollTo="scrollTo" />

        <!-- Main content -->
        <div class="w-full md:w-3/4 space-y-6">

            <!-- ═══ Emisor (shared) ═══ -->
            <EmisorSection
                v-model="selectedFiscalProfile"
                :form="form"
                :fiscal-profiles="fiscalProfilesList"
                :mode="mode"
                :is-nomina="isNomina"
                :is-pago="isPago"
                :readiness-message="readinessMessage"
                :profile-settings-url="profileSettingsUrl"
            />

            <!-- ═══ Receptor (shared) ═══ -->
            <ReceptorSection
                :form="form"
                :customers="customersList"
                :is-ingreso="isIngreso"
                :is-pago="isPago"
                :is-traslado="isTraslado"
                :initial-customer-name="initialCustomerName"
            />

            <!-- ═══ Type-specific partials ═══ -->
            <IngresoForm
                v-if="isIngreso"
                :form="form"
                :products="products"
                :services="services"
                :subtotal="subtotal"
                :iva-trasladado="ivaTrasladado"
                :isr-retenido="isrRetenido"
                :iva-retenido="ivaRetenido"
                :gran-total="granTotal"
                :retention-applies="retentionApplies"
                :is-resico="isResico"
                :retention-message="retentionMessage"
            />
            <EgresoForm
                v-else-if="isEgreso"
                :form="form"
                :products="products"
                :services="services"
                :ppd-invoices="ppdInvoicesList"
                :multiple-emitters="multipleEmitters"
                :subtotal="subtotal"
                :iva-trasladado="ivaTrasladado"
                :isr-retenido="isrRetenido"
                :iva-retenido="ivaRetenido"
                :gran-total="granTotal"
                :retention-applies="retentionApplies"
                :is-resico="isResico"
                :retention-message="retentionMessage"
            />
            <PagoForm
                v-else-if="isPago"
                :form="form"
                :ppd-invoices="ppdInvoicesList"
                :multiple-emitters="multipleEmitters"
            />
            <TrasladoForm
                v-else-if="isTraslado"
                :form="form"
                :products="products"
                :services="services"
            />

            <!-- ═══ Submit buttons — Tesla dock ═══ -->
            <div class="sticky bottom-4 z-20 flex justify-center">
                <div class="inline-flex items-center gap-3 rounded-full p-2 border border-slate-100 dark:border-neutral-800 bg-white/80 dark:bg-[#121212]/80 backdrop-blur-xl shadow-lg shadow-slate-200/40 dark:shadow-black/40">
                    <template v-if="mode === 'create'">
                        <Button type="submit" label="Guardar como prefactura" icon="pi pi-file" severity="primary" outlined @click="submit(true)" :loading="form.processing" class="!rounded-full !px-6 !py-2.5 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95 !bg-white dark:!bg-transparent" />
                        <Button type="submit" label="Timbrar ahora" icon="pi pi-shield" :loading="form.processing" :disabled="!canStamp" :class="['!rounded-full !px-6 !py-2.5 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95', !canStamp ? 'opacity-50 cursor-not-allowed' : '']" v-tooltip.top="!canStamp ? 'Carga tus certificados y firma el manifiesto del SAT para comenzar a timbrar.' : ''" />
                    </template>
                    <template v-else>
                        <Button type="submit" label="Guardar cambios" icon="pi pi-save" :loading="form.processing" class="!rounded-full !px-6 !py-2.5 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95" />
                        <Button type="button" label="Cancelar" severity="secondary" outlined :disabled="form.processing" @click="$inertia.visit(route('billing.invoices.show', invoice.id))" class="!rounded-full !px-6 !py-2.5 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95" />
                    </template>
                </div>
            </div>

            <!-- Spacer so the last section can scroll up past the sticky buttons -->
            <div class="h-[50vh] md:h-[5vh]" aria-hidden="true"></div>

        </div>
    </form>
</template>
