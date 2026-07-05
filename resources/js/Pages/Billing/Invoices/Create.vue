<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useInvoiceTaxes } from '@/Composables/useInvoiceTaxes';
import InvoiceTotals from './Partials/InvoiceTotals.vue';

const props = defineProps({
    customers: Array,
    fiscalProfiles: Array,
    hasFiscalProfiles: Boolean,
});

// ──────────────────────────────────────
// Mock data — graceful fallback when backend returns empty collections
// ──────────────────────────────────────
const MOCK_FISCAL_PROFILES = [
    { id: 1001, rfc: 'MOR830115CZ8', razon_social: 'Cliente Moral Mock, S.A. de C.V.', regimen_fiscal: '601', postal_code: '44100' },
    { id: 1002, rfc: 'FIS920101ABC', razon_social: 'Juan Pérez Mock (RESICO)', regimen_fiscal: '626', postal_code: '44600' },
];

const MOCK_CUSTOMERS = [
    { id: 2001, name: 'Empresa Demo S.A. de C.V.', company_name: 'Empresa Demo S.A. de C.V.', tax_id: 'EDO120101AB1', tax_regime: '601', fiscal_address: { tax_regime: '601', zip_code: '06600' }, address: { zip_code: '06600' } },
    { id: 2002, name: 'Carlos López Demo', company_name: null, tax_id: 'LOPC850101XYZ', tax_regime: '612', fiscal_address: { tax_regime: '612', zip_code: '11520' }, address: { zip_code: '11520' } },
];

/**
 * Normalize Laravel collections to plain arrays.
 * Tolerates associative objects (keyBy) by converting with Object.values().
 */
const toArray = (collection) => {
    if (!collection) return [];
    if (Array.isArray(collection)) return collection;
    if (typeof collection === 'object' && !Array.isArray(collection)) return Object.values(collection);
    return [];
};

/**
 * Extract fiscal regime & postal code from a customer record.
 * Tries fiscal_address first (JSON object or flat), then direct columns, then address.
 */
const extractFiscalData = (customer) => {
    if (!customer) return { tax_regime: '', postal_code: '' };
    const fa = customer.fiscal_address;

    // fiscal_address is a JSON object with nested fields
    if (fa && typeof fa === 'object' && !Array.isArray(fa)) {
        return {
            tax_regime: fa.tax_regime || fa.regimen_fiscal || customer.tax_regime || '',
            postal_code: fa.zip_code || fa.postal_code || fa.cp || '',
        };
    }

    // fiscal_address is a flat string or number (unlikely, but tolerant)
    if (fa && typeof fa !== 'object') {
        return {
            tax_regime: customer.tax_regime || '',
            postal_code: String(fa),
        };
    }

    // Fallback: direct columns + address JSON
    const addr = customer.address;
    const addrObj = (addr && typeof addr === 'object' && !Array.isArray(addr)) ? addr : {};
    return {
        tax_regime: customer.tax_regime || '',
        postal_code: addrObj.zip_code || addrObj.postal_code || '',
    };
};

const fiscalProfiles = computed(() =>
    toArray(props.fiscalProfiles).length > 0 ? toArray(props.fiscalProfiles) : MOCK_FISCAL_PROFILES,
);

const customers = computed(() =>
    toArray(props.customers).length > 0 ? toArray(props.customers) : MOCK_CUSTOMERS,
);

const hasFiscalProfiles = computed(() =>
    props.hasFiscalProfiles || fiscalProfiles.value.length > 0,
);

// ──────────────────────────────────────
// Selected emitter fiscal profile
// ──────────────────────────────────────
const selectedFiscalProfile = ref(null);

onMounted(() => {
    if (fiscalProfiles.value.length === 1) {
        selectedFiscalProfile.value = fiscalProfiles.value[0];
        form.fiscal_profile_id = fiscalProfiles.value[0].id;
    }
});

// ──────────────────────────────────────
// Emitter read-only data (auto-filled from selected fiscal profile)
// ──────────────────────────────────────
const emitterRegime = ref('');
const emitterPostalCode = ref('');

watch(selectedFiscalProfile, (profile) => {
    emitterRegime.value = profile?.regimen_fiscal || '';
    emitterPostalCode.value = profile?.postal_code || '';
}, { immediate: true });

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Facturación', url: route('billing.invoices.index') },
    { label: 'Nueva factura' },
]);

// ──────────────────────────────────────
// Inertia form
// ──────────────────────────────────────
const form = useForm({
    // Receiver
    receiver_rfc: '',
    receiver_legal_name: '',
    receiver_tax_regime: '',
    receiver_postal_code: '',
    cfdi_use: '',
    fiscal_profile_id: null,

    // Payment
    payment_form: '',
    payment_method: '',
    currency: 'MXN',
    exchange_rate: null,
    exportacion: '01',

    // Relation
    customer_id: null,

    // Items
    items: [],
});

// ──────────────────────────────────────
// Dropdown options (SAT catalogs)
// ──────────────────────────────────────
const cfdiUseOptions = [
    { label: 'G01 — Adquisición de mercancías', value: 'G01' },
    { label: 'G02 — Devoluciones, descuentos o bonificaciones', value: 'G02' },
    { label: 'G03 — Gastos en general', value: 'G03' },
    { label: 'I01 — Construcciones', value: 'I01' },
    { label: 'I02 — Mobiliario y equipo de oficina por inversiones', value: 'I02' },
    { label: 'I03 — Equipo de transporte', value: 'I03' },
    { label: 'I04 — Equipo de cómputo y accesorios', value: 'I04' },
    { label: 'I05 — Dados, troqueles, moldes, matrices y herramentales', value: 'I05' },
    { label: 'I06 — Comunicaciones telefónicas', value: 'I06' },
    { label: 'I07 — Comunicaciones satelitales', value: 'I07' },
    { label: 'I08 — Otra maquinaria y equipo', value: 'I08' },
    { label: 'D01 — Honorarios médicos, dentales y gastos hospitalarios', value: 'D01' },
    { label: 'D02 — Gastos médicos por incapacidad o discapacidad', value: 'D02' },
    { label: 'D03 — Gastos funerales', value: 'D03' },
    { label: 'D04 — Donativos', value: 'D04' },
    { label: 'D05 — Intereses reales por créditos hipotecarios', value: 'D05' },
    { label: 'D06 — Aportaciones voluntarias al SAR', value: 'D06' },
    { label: 'D07 — Primas por seguros de gastos médicos', value: 'D07' },
    { label: 'D08 — Gastos de transportación escolar', value: 'D08' },
    { label: 'D09 — Depósitos en cuentas de ahorro', value: 'D09' },
    { label: 'D10 — Pagos por servicios educativos', value: 'D10' },
    { label: 'P01 — Por definir', value: 'P01' },
];

const paymentFormOptions = [
    { label: '01 — Efectivo', value: '01' },
    { label: '02 — Cheque nominativo', value: '02' },
    { label: '03 — Transferencia electrónica de fondos', value: '03' },
    { label: '04 — Tarjeta de crédito', value: '04' },
    { label: '28 — Tarjeta de débito', value: '28' },
    { label: '99 — Por definir', value: '99' },
];

const paymentMethodOptions = [
    { label: 'PUE — Pago en una sola exhibición', value: 'PUE' },
    { label: 'PPD — Pago en parcialidades o diferido', value: 'PPD' },
];

const currencyOptions = [
    { label: 'MXN — Peso mexicano', value: 'MXN' },
    { label: 'USD — Dólar estadounidense', value: 'USD' },
];

const taxRegimeOptions = [
    { label: '601 — General de Ley Personas Morales', value: '601' },
    { label: '603 — Personas Morales con Fines no Lucrativos', value: '603' },
    { label: '605 — Sueldos y Salarios', value: '605' },
    { label: '606 — Arrendamiento', value: '606' },
    { label: '608 — Demás ingresos', value: '608' },
    { label: '612 — Personas Físicas con Actividades Empresariales', value: '612' },
    { label: '614 — Ingresos por intereses', value: '614' },
    { label: '616 — Sin obligaciones fiscales', value: '616' },
    { label: '620 — Sociedades Cooperativas', value: '620' },
    { label: '621 — Incorporación Fiscal', value: '621' },
    { label: '622 — Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', value: '622' },
    { label: '626 — Régimen Simplificado de Confianza', value: '626' },
];

const exportacionOptions = [
    { label: '01 — No aplica', value: '01' },
    { label: '02 — Definitiva con clave A1', value: '02' },
    { label: '03 — Temporal', value: '03' },
    { label: '04 — Definitiva con clave distinta a A1', value: '04' },
];

const objetoImpOptions = [
    { label: '01 — No objeto de impuesto', value: '01' },
    { label: '02 — Sí objeto de impuesto', value: '02' },
    { label: '03 — Sí objeto de impuesto y no obligado al desglose', value: '03' },
];

const conceptoTipoOptions = [
    { label: 'General (sin retención)', value: null },
    { label: 'Servicio', value: 'servicio' },
    { label: 'Honorarios', value: 'honorarios' },
    { label: 'Arrendamiento', value: 'arrendamiento' },
    { label: 'Flete / Autotransporte', value: 'flete' },
];

// ──────────────────────────────────────
// Tesla UI — Uniform Pass-Through configs
// All inputs & selects share identical height, radius, border & background
// ──────────────────────────────────────
const inputPt = {
    root: { class: 'h-11 !rounded-xl !bg-white dark:!bg-zinc-950 !border !border-zinc-200 dark:!border-zinc-800 focus:dark:!border-primary-500 !transition-colors !text-sm !shadow-none w-full' },
};

const selectPt = {
    root: { class: '!h-11 !min-h-[34px] !rounded-xl !bg-white dark:!bg-zinc-950 !border !border-zinc-200 dark:!border-zinc-800 focus:dark:!border-primary-500 !transition-colors !text-sm !shadow-none flex items-center' },
    label: { class: '!p-0 !p-2.5 !text-sm text-zinc-900 dark:text-white flex items-center h-full' },
};

const inputNumberPt = {
    root: { class: 'w-full' },
    input: { root: { class: 'w-full min-w-0 h-11 !rounded-xl !bg-white dark:!bg-zinc-950 !border !border-zinc-200 dark:!border-zinc-800 focus:dark:!border-primary-500 !transition-colors !text-sm !shadow-none' } },
};

const readonlyPt = {
    root: { class: 'h-11 !rounded-xl !bg-zinc-100 dark:!bg-zinc-800 !border !border-zinc-200 dark:!border-zinc-700 !text-zinc-500 dark:!text-zinc-400 !cursor-default !text-sm !shadow-none' },
};

// ──────────────────────────────────────
// Items management
// ──────────────────────────────────────
const blankItem = () => ({
    description: '',
    quantity: 1,
    unit_price: 0,
    sat_product_code: '',
    sat_unit_code: 'H87',
    unit_name: '',
    no_identificacion: '',
    objeto_imp: '02',
    concepto_tipo: null,
    tax_type: '002',
    tax_rate: 0.16,
    discount_amount: 0,
    retained_tax_type: null,
    retained_tax_rate: null,
    retained_tax_amount: 0,
});

const addItem = () => {
    form.items.push(blankItem());
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

// ──────────────────────────────────────
// CFDI 4.0 Tax & Retention Calculator (composable)
// ──────────────────────────────────────
const {
    subtotal,
    ivaTrasladado,
    isrRetenido,
    ivaRetenido,
    granTotal,
    retentionApplies,
    isResico,
    breakdown,
    formatCurrency,
} = useInvoiceTaxes(form, fiscalProfiles, customers);

// ──────────────────────────────────────
// Customer auto-fill — extracts fiscal data from fiscal_address or direct columns
// ──────────────────────────────────────
watch(() => form.customer_id, (newId) => {
    if (!newId) return;
    const customer = customers.value?.find(c => c.id === newId);
    if (!customer) return;
    if (!form.receiver_rfc) form.receiver_rfc = customer.tax_id || '';
    if (!form.receiver_legal_name) form.receiver_legal_name = customer.company_name || customer.name || '';

    const fiscal = extractFiscalData(customer);
    form.receiver_tax_regime = fiscal.tax_regime || form.receiver_tax_regime || '';
    if (fiscal.postal_code) form.receiver_postal_code = fiscal.postal_code;
});

// ──────────────────────────────────────
// Submit — inject computed retention data from breakdown into each item
// Retentions are sent as an array (same structure as Traslados), allowing
// ISR (001) and IVA (002) to coexist on the same concept per SAT rules.
// ──────────────────────────────────────
const submit = () => {
    const items = form.items;
    const bd = breakdown.value;

    for (let i = 0; i < items.length; i++) {
        const entry = bd[i];
        if (!entry) continue;

        // Build retentions array — both ISR and IVA can coexist
        const retentions = [];

        if (entry.isrRetention > 0) {
            retentions.push({
                type: '001',
                rate: entry.rates.isrRate,
                amount: entry.isrRetention,
            });
        }

        if (entry.ivaRetention > 0) {
            retentions.push({
                type: '002',
                rate: entry.rates.ivaRetentionRate,
                amount: entry.ivaRetention,
            });
        }

        items[i].retentions = retentions.length > 0 ? retentions : null;

        // Backward compatibility: also set legacy single fields (first retention, if any)
        if (retentions.length > 0) {
            items[i].retained_tax_type  = retentions[0].type;
            items[i].retained_tax_rate  = retentions[0].rate;
            items[i].retained_tax_amount = retentions[0].amount;
        } else {
            items[i].retained_tax_type  = null;
            items[i].retained_tax_rate  = null;
            items[i].retained_tax_amount = 0;
        }
    }

    form.post(route('billing.invoices.store'));
};
</script>

<template>
    <AppLayout title="Nueva factura">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 !mb-1" />

        <div class="flex items-center justify-between mt-2 mb-6">
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">Nueva factura</h1>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1 m-0">
                    Completa los datos y conceptos para generar el CFDI 4.0
                </p>
            </div>
        </div>

        <!-- Onboarding guard -->
        <div
            v-if="!hasFiscalProfiles"
            class="max-w-lg mx-auto bg-gray-50 dark:bg-[#1a1a1a] border border-gray-200 dark:border-[#3a3a3a] rounded-2xl p-8 text-center mt-10"
        >
            <i class="pi pi-exclamation-triangle !text-4xl text-amber-400 mb-4 block"></i>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 m-0 mb-2">Perfil fiscal requerido</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 m-0 mb-6 leading-relaxed">
                No tienes perfiles fiscales registrados. Para emitir facturas, primero debes agregar al menos un RFC emisor.
            </p>
            <a
                :href="route('billing.settings.index')"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors no-underline"
            >
                <i class="pi pi-external-link !text-sm"></i>
                Agregar perfil fiscal
            </a>
        </div>

        <!-- ════════════════════════════════════════
             Form: two-column layout
             Left: Sections  |  Right: InvoiceTotals (sticky)
             ════════════════════════════════════════ -->
        <form v-else @submit.prevent="submit" class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

                <!-- ═══ LEFT COLUMN: Form sections ═══ -->
                <div class="lg:col-span-7 space-y-8">

                    <!-- ── SECTION: Emisor ── -->
                    <div class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                <i class="pi pi-building !text-sm text-blue-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Emisor</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Perfil fiscal del emisor</p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5 mb-5" v-if="fiscalProfiles.length > 1">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Perfil fiscal *</label>
                            <Select
                                v-model="selectedFiscalProfile"
                                :options="fiscalProfiles"
                                optionLabel="razon_social"
                                placeholder="Selecciona el RFC emisor"
                                class="w-full"
                                @change="() => { form.fiscal_profile_id = selectedFiscalProfile?.id ?? null; }"
                                :pt="selectPt"
                            >
                                <template #value="slotProps">
                                    <div v-if="slotProps.value" class="flex items-center gap-2">
                                        <span class="text-sm font-medium">{{ slotProps.value.rfc }}</span>
                                        <span class="text-zinc-400">—</span>
                                        <span class="text-sm">{{ slotProps.value.razon_social }}</span>
                                    </div>
                                </template>
                                <template #option="slotProps">
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-sm font-medium">{{ slotProps.option.rfc }}</span>
                                        <span class="text-xs text-zinc-500">{{ slotProps.option.razon_social }}</span>
                                    </div>
                                </template>
                            </Select>
                            <Message v-if="form.errors.fiscal_profile_id" severity="error" variant="simple" size="small">
                                {{ form.errors.fiscal_profile_id }}
                            </Message>
                        </div>

                        <div v-if="selectedFiscalProfile" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC</label>
                                <InputText :modelValue="selectedFiscalProfile.rfc" readonly class="w-full" :pt="readonlyPt" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social</label>
                                <InputText :modelValue="selectedFiscalProfile.razon_social" readonly class="w-full" :pt="readonlyPt" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal</label>
                                <InputText :modelValue="emitterRegime" readonly class="w-full" :pt="readonlyPt" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">C. P.</label>
                                <InputText :modelValue="emitterPostalCode" readonly class="w-full" :pt="readonlyPt" />
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION: Atributos globales CFDI 4.0 ── -->
                    <div class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                                <i class="pi pi-globe !text-sm text-purple-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Atributos globales CFDI 4.0</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Configuración del comprobante</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Exportación *</label>
                                <Select v-model="form.exportacion" :options="exportacionOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" />
                                <Message v-if="form.errors.exportacion" severity="error" variant="simple" size="small">{{ form.errors.exportacion }}</Message>
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION: Receptor ── -->
                    <div class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                                <i class="pi pi-user !text-sm text-emerald-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Receptor</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Datos del cliente</p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5 mb-5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cliente</label>
                            <Select v-model="form.customer_id" :options="customers" optionLabel="name" optionValue="id" placeholder="Selecciona un cliente existente" showClear filter class="w-full" :pt="selectPt" />
                            <Message v-if="form.errors.customer_id" severity="error" variant="simple" size="small">{{ form.errors.customer_id }}</Message>
                        </div>

                        <Divider class="!my-6 !border-zinc-200 dark:!border-zinc-800" />

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC *</label>
                                <InputText v-model="form.receiver_rfc" placeholder="XAXX010101000" class="w-full uppercase" :pt="inputPt" />
                                <Message v-if="form.errors.receiver_rfc" severity="error" variant="simple" size="small">{{ form.errors.receiver_rfc }}</Message>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social *</label>
                                <InputText v-model="form.receiver_legal_name" placeholder="Nombre o razón social" class="w-full" :pt="inputPt" />
                                <Message v-if="form.errors.receiver_legal_name" severity="error" variant="simple" size="small">{{ form.errors.receiver_legal_name }}</Message>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal *</label>
                                <Select v-model="form.receiver_tax_regime" :options="taxRegimeOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :pt="selectPt" />
                                <Message v-if="form.errors.receiver_tax_regime" severity="error" variant="simple" size="small">{{ form.errors.receiver_tax_regime }}</Message>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Código postal *</label>
                                <InputText v-model="form.receiver_postal_code" placeholder="12345" maxlength="5" class="w-full" :pt="inputPt" />
                                <Message v-if="form.errors.receiver_postal_code" severity="error" variant="simple" size="small">{{ form.errors.receiver_postal_code }}</Message>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Uso de CFDI *</label>
                                <Select v-model="form.cfdi_use" :options="cfdiUseOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :pt="selectPt" />
                                <Message v-if="form.errors.cfdi_use" severity="error" variant="simple" size="small">{{ form.errors.cfdi_use }}</Message>
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION: Pago ── -->
                    <div class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                                <i class="pi pi-credit-card !text-sm text-amber-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Forma y método de pago</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Condiciones del CFDI</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Forma de pago *</label>
                                <Select v-model="form.payment_form" :options="paymentFormOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" />
                                <Message v-if="form.errors.payment_form" severity="error" variant="simple" size="small">{{ form.errors.payment_form }}</Message>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Método de pago *</label>
                                <Select v-model="form.payment_method" :options="paymentMethodOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" />
                                <Message v-if="form.errors.payment_method" severity="error" variant="simple" size="small">{{ form.errors.payment_method }}</Message>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Moneda</label>
                                <Select v-model="form.currency" :options="currencyOptions" optionLabel="label" optionValue="value" placeholder="MXN" class="w-full" :pt="selectPt" />
                                <Message v-if="form.errors.currency" severity="error" variant="simple" size="small">{{ form.errors.currency }}</Message>
                            </div>
                            <div v-if="form.currency !== 'MXN'" class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de cambio *</label>
                                <InputNumber v-model="form.exchange_rate" :minFractionDigits="6" :maxFractionDigits="6" :min="0.000001" placeholder="1.000000" class="w-full" :pt="inputNumberPt" />
                                <Message v-if="form.errors.exchange_rate" severity="error" variant="simple" size="small">{{ form.errors.exchange_rate }}</Message>
                                <p v-else class="text-[9px] text-gray-400 dark:text-gray-500 m-0">Requerido por el SAT cuando la moneda no es MXN (Anexo 20).</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION: Conceptos ── -->
                    <div class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                        <div class="flex items-center justify-between mb-5">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center flex-shrink-0 border border-rose-100 dark:border-rose-900/30">
                                    <i class="pi pi-list !text-sm text-rose-500"></i>
                                </div>
                                <div>
                                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Conceptos</h2>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">{{ form.items.length }} partidas</p>
                                </div>
                            </div>
                            <Button type="button" icon="pi pi-plus" label="Agregar concepto" @click="addItem" class="!rounded-full !text-xs !font-bold !uppercase !tracking-widest" />
                        </div>

                        <Message v-if="form.errors.items" severity="error" variant="simple" size="small" class="mb-4">{{ form.errors.items }}</Message>

                        <!-- Empty state -->
                        <div v-if="form.items.length === 0" class="rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 py-10 text-center">
                            <i class="pi pi-inbox !text-3xl text-zinc-300 dark:text-zinc-600 mb-3 block"></i>
                            <p class="text-sm text-zinc-400 dark:text-zinc-500 m-0">No hay conceptos agregados</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-600 mt-1 m-0">Agrega al menos un concepto para continuar</p>
                        </div>

                        <!-- Items list -->
                        <div v-else class="space-y-4">
                            <div v-for="(item, index) in form.items" :key="index" class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/30 p-4">
                                <!-- Row 1: Description -->
                                <div class="flex flex-col gap-1.5 mb-4">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Concepto {{ index + 1 }}</label>
                                    <div class="flex gap-2">
                                        <InputText v-model="item.description" placeholder="Descripción del producto o servicio" class="w-full" :pt="inputPt" />
                                        <Button type="button" icon="pi pi-trash" severity="danger" text rounded size="small" @click="removeItem(index)" />
                                    </div>
                                    <Message v-if="form.errors[`items.${index}.description`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.description`] }}</Message>
                                </div>

                                <!-- Row 2: SAT Codes + Quantity + Unit Price -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">ClaveProdServ</label>
                                        <InputText v-model="item.sat_product_code" placeholder="01010101" maxlength="15" class="w-full" :pt="inputPt" />
                                        <Message v-if="form.errors[`items.${index}.sat_product_code`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.sat_product_code`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">ClaveUnidad</label>
                                        <InputText v-model="item.sat_unit_code" placeholder="H87" maxlength="10" class="w-full" :pt="inputPt" />
                                        <Message v-if="form.errors[`items.${index}.sat_unit_code`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.sat_unit_code`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Cantidad</label>
                                        <InputNumber v-model="item.quantity" :minFractionDigits="2" :maxFractionDigits="4" :min="0.0001" class="w-full" :pt="inputNumberPt" />
                                        <Message v-if="form.errors[`items.${index}.quantity`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.quantity`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Precio unitario</label>
                                        <InputNumber v-model="item.unit_price" mode="currency" currency="MXN" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" />
                                        <Message v-if="form.errors[`items.${index}.unit_price`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.unit_price`] }}</Message>
                                    </div>
                                </div>

                                <!-- Row 2b: Unit name + SKU -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Unidad (nombre comercial)</label>
                                        <InputText v-model="item.unit_name" placeholder="Pieza, Servicio, Horas..." maxlength="50" class="w-full" :pt="inputPt" />
                                        <Message v-if="form.errors[`items.${index}.unit_name`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.unit_name`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">SKU / No. identificación</label>
                                        <InputText v-model="item.no_identificacion" placeholder="SKU-001" maxlength="100" class="w-full" :pt="inputPt" />
                                        <Message v-if="form.errors[`items.${index}.no_identificacion`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.no_identificacion`] }}</Message>
                                    </div>
                                </div>

                                <!-- Row 3: concepto_tipo + objeto_imp + tax_type + tax_rate + discount -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mt-4">
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Tipo concepto</label>
                                        <Select v-model="item.concepto_tipo" :options="conceptoTipoOptions" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" />
                                        <Message v-if="form.errors[`items.${index}.concepto_tipo`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.concepto_tipo`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Objeto impuesto</label>
                                        <Select v-model="item.objeto_imp" :options="objetoImpOptions" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" />
                                        <Message v-if="form.errors[`items.${index}.objeto_imp`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.objeto_imp`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Tipo impuesto</label>
                                        <Select v-model="item.tax_type" :options="[{ label: '002 — IVA', value: '002' }, { label: '001 — ISR', value: '001' }]" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" />
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Tasa (%)</label>
                                        <InputNumber v-model="item.tax_rate" suffix="%" :minFractionDigits="2" :maxFractionDigits="2" :min="0" :max="1" class="w-full" :pt="inputNumberPt" />
                                        <Message v-if="form.errors[`items.${index}.tax_rate`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.tax_rate`] }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Descuento</label>
                                        <InputNumber v-model="item.discount_amount" mode="currency" currency="MXN" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" />
                                    </div>
                                </div>

                                <!-- Line subtotal preview -->
                                <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-800 flex justify-end gap-6 text-xs">
                                    <span class="text-zinc-400 dark:text-zinc-500">{{ formatCurrency((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)) }}</span>
                                    <span class="text-zinc-400 dark:text-zinc-500">IVA {{ formatCurrency(((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0) - (parseFloat(item.discount_amount) || 0)) * (parseFloat(item.tax_rate) || 0)) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /left column -->

                <!-- ═══ RIGHT COLUMN: InvoiceTotals (sticky floating card) + Submit ═══ -->
                <div class="lg:col-span-5">
                    <div class="sticky top-24 space-y-6">
                        <InvoiceTotals
                            :subtotal="subtotal"
                            :iva-trasladado="ivaTrasladado"
                            :isr-retenido="isrRetenido"
                            :iva-retenido="ivaRetenido"
                            :gran-total="granTotal"
                            :retention-applies="retentionApplies"
                            :is-resico="isResico"
                        />

                        <div class="flex justify-end gap-3">
                            <Button type="button" label="Cancelar" severity="secondary" text class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" @click="$inertia.visit(route('billing.invoices.index'))" />
                            <Button type="submit" label="Crear factura" icon="pi pi-file" :loading="form.processing" class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" />
                        </div>
                    </div>
                </div><!-- /right column -->

            </div><!-- /grid -->
        </form>
    </AppLayout>
</template>
