<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useInvoiceTaxes } from '@/Composables/useInvoiceTaxes';
import InvoiceTotals from './Partials/InvoiceTotals.vue';
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';

const props = defineProps({
    customers: Array,
    fiscalProfiles: Array,
    hasFiscalProfiles: Boolean,
});

// ──────────────────────────────────────
// Normalize collections
// ──────────────────────────────────────
const toArray = (collection) => {
    if (!collection) return [];
    if (Array.isArray(collection)) return collection;
    if (typeof collection === 'object' && !Array.isArray(collection)) return Object.values(collection);
    return [];
};

const extractFiscalData = (customer) => {
    if (!customer) return { tax_regime: '', postal_code: '' };
    const fa = customer.fiscal_address;
    if (fa && typeof fa === 'object' && !Array.isArray(fa)) {
        return { tax_regime: fa.tax_regime || fa.regimen_fiscal || customer.tax_regime || '', postal_code: fa.zip_code || fa.postal_code || fa.cp || '' };
    }
    const addr = customer.address;
    const addrObj = (addr && typeof addr === 'object' && !Array.isArray(addr)) ? addr : {};
    return { tax_regime: customer.tax_regime || '', postal_code: addrObj.zip_code || addrObj.postal_code || '' };
};

const fiscalProfiles = computed(() => toArray(props.fiscalProfiles));
const customers = computed(() => toArray(props.customers));
const hasFiscalProfiles = computed(() => props.hasFiscalProfiles || fiscalProfiles.value.length > 0);

// ──────────────────────────────────────
// Sidebar navigation
// ──────────────────────────────────────
const formSections = [
    { id: 'emisor', label: 'Emisor' },
    { id: 'receptor', label: 'Receptor' },
    { id: 'pago', label: 'Pago' },
    { id: 'conceptos', label: 'Conceptos' },
];
const { activeSection, scrollTo } = useScrollspy(formSections.map(s => s.id));

// ──────────────────────────────────────
// Form
// ──────────────────────────────────────
const selectedFiscalProfile = ref(null);

onMounted(() => {
    if (fiscalProfiles.value.length === 1) {
        selectedFiscalProfile.value = fiscalProfiles.value[0];
        form.fiscal_profile_id = fiscalProfiles.value[0].id;
    }
});

const emitterRegime = ref('');
const emitterPostalCode = ref('');

watch(selectedFiscalProfile, (profile) => {
    emitterRegime.value = profile?.regimen_fiscal || '';
    emitterPostalCode.value = profile?.postal_code || '';
}, { immediate: true });

const form = useForm({
    receiver_rfc: '',
    receiver_legal_name: '',
    receiver_tax_regime: '',
    receiver_postal_code: '',
    cfdi_use: '',
    fiscal_profile_id: null,
    payment_form: '',
    payment_method: '',
    currency: 'MXN',
    exchange_rate: null,
    exportacion: '01',
    customer_id: null,
    items: [],
    draft: false,
});

// ──────────────────────────────────────
// SAT catalogs
// ──────────────────────────────────────
const cfdiUseOptions = [
    { label: 'G01 — Adquisición de mercancías', value: 'G01' },
    { label: 'G02 — Devoluciones, descuentos o bonificaciones', value: 'G02' },
    { label: 'G03 — Gastos en general', value: 'G03' },
    { label: 'I01 — Construcciones', value: 'I01' },
    { label: 'I02 — Mobiliario y equipo de oficina', value: 'I02' },
    { label: 'I03 — Equipo de transporte', value: 'I03' },
    { label: 'I04 — Equipo de cómputo', value: 'I04' },
    { label: 'D01 — Honorarios médicos', value: 'D01' },
    { label: 'D02 — Gastos médicos por incapacidad', value: 'D02' },
    { label: 'D03 — Gastos funerales', value: 'D03' },
    { label: 'D04 — Donativos', value: 'D04' },
    { label: 'D05 — Intereses hipotecarios', value: 'D05' },
    { label: 'D06 — Aportaciones SAR', value: 'D06' },
    { label: 'D07 — Primas seguros médicos', value: 'D07' },
    { label: 'D08 — Transportación escolar', value: 'D08' },
    { label: 'D09 — Depósitos en ahorro', value: 'D09' },
    { label: 'D10 — Servicios educativos', value: 'D10' },
    { label: 'P01 — Por definir', value: 'P01' },
];

const paymentFormOptions = [
    { label: '01 — Efectivo', value: '01' }, { label: '02 — Cheque nominativo', value: '02' },
    { label: '03 — Transferencia electrónica', value: '03' }, { label: '04 — Tarjeta de crédito', value: '04' },
    { label: '28 — Tarjeta de débito', value: '28' }, { label: '99 — Por definir', value: '99' },
];

const paymentMethodOptions = [
    { label: 'PUE — Pago en una sola exhibición', value: 'PUE' },
    { label: 'PPD — Pago en parcialidades o diferido', value: 'PPD' },
];

const taxRegimeOptions = [
    { label: '601 — General de Ley Personas Morales', value: '601' },
    { label: '612 — Personas Físicas con Actividades Empresariales', value: '612' },
    { label: '616 — Sin obligaciones fiscales', value: '616' },
    { label: '621 — Incorporación Fiscal', value: '621' },
    { label: '626 — Régimen Simplificado de Confianza', value: '626' },
    { label: '603 — Personas Morales con Fines no Lucrativos', value: '603' },
];

const objetoImpOptions = [
    { label: '01 — No objeto de impuesto', value: '01' },
    { label: '02 — Sí objeto de impuesto', value: '02' },
    { label: '03 — Sí objeto y no obligado al desglose', value: '03' },
];

// ──────────────────────────────────────
// Concepts management
// ──────────────────────────────────────
const blankItem = () => ({
    description: '', quantity: 1, unit_price: 0,
    sat_product_code: '', sat_unit_code: 'H87', unit_name: '',
    no_identificacion: '', objeto_imp: '02',
    tax_type: '002', tax_rate: 0.16, discount_amount: 0,
    retained_tax_type: null, retained_tax_rate: null, retained_tax_amount: 0,
});

const addItem = () => form.items.push(blankItem());
const removeItem = (index) => form.items.splice(index, 1);

// ──────────────────────────────────────
// Tax calculator
// ──────────────────────────────────────
const { subtotal, ivaTrasladado, isrRetenido, ivaRetenido, granTotal, formatCurrency, breakdown } = useInvoiceTaxes(form, fiscalProfiles, customers);

// ──────────────────────────────────────
// Customer auto-fill
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
// Submit
// ──────────────────────────────────────
const submit = (draft = false) => {
    form.draft = draft;
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
    form.post(route('billing.invoices.store'));
};

// ──────────────────────────────────────
// Tesla UI PT
// ──────────────────────────────────────
const inputPt = { root: { class: 'h-11 !rounded-xl !bg-white dark:!bg-zinc-950 !border !border-zinc-200 dark:!border-zinc-800 focus:dark:!border-primary-500 !transition-colors !text-sm !shadow-none w-full' } };
const selectPt = { root: { class: '!h-11 !rounded-xl !bg-white dark:!bg-zinc-950 !border !border-zinc-200 dark:!border-zinc-800 focus:dark:!border-primary-500 !transition-colors !text-sm !shadow-none flex items-center' } };
const inputNumberPt = { root: { class: 'w-full' }, input: { root: { class: 'w-full min-w-0 h-11 !rounded-xl !bg-white dark:!bg-zinc-950 !border !border-zinc-200 dark:!border-zinc-800 focus:dark:!border-primary-500 !transition-colors !text-sm !shadow-none' } } };
const readonlyPt = { root: { class: 'h-11 !rounded-xl !bg-zinc-100 dark:!bg-zinc-800 !border !border-zinc-200 dark:!border-zinc-700 !text-zinc-500 !cursor-default !text-sm' } };
</script>

<template>
    <AppLayout title="Nueva factura">
        <Breadcrumb :home="{ icon: 'pi pi-home', url: route('dashboard') }" :model="[{ label: 'Facturación', url: route('billing.invoices.index') }, { label: 'Nueva factura' }]" class="!bg-transparent !p-0 !mb-1" />

        <div class="flex items-center justify-between mt-2 mb-6">
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">Nueva factura</h1>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1 m-0">Completa los datos y conceptos para generar el CFDI 4.0</p>
            </div>
            <div class="flex gap-2">
                <Button label="Cancelar" severity="secondary" text class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" @click="$inertia.visit(route('billing.invoices.index'))" />
            </div>
        </div>

        <!-- Onboarding guard -->
        <div v-if="!hasFiscalProfiles" class="max-w-lg mx-auto bg-gray-50 dark:bg-[#1a1a1a] border border-gray-200 dark:border-[#3a3a3a] rounded-2xl p-8 text-center mt-10">
            <i class="pi pi-exclamation-triangle !text-4xl text-amber-400 mb-4 block"></i>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 m-0 mb-2">Perfil fiscal requerido</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 m-0 mb-6">No tienes perfiles fiscales. Agrega al menos un RFC emisor para facturar.</p>
            <a :href="route('billing.settings.index')" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors no-underline">
                <i class="pi pi-external-link !text-sm"></i> Agregar perfil fiscal
            </a>
        </div>

        <form v-else @submit.prevent="submit(false)" class="mt-6 flex flex-col md:flex-row gap-6 items-start relative">
            <!-- Sidebar -->
            <FormNavigationSidebar :sections="formSections" :activeSection="activeSection" @scrollTo="scrollTo" />

            <!-- Main content -->
            <div class="w-full md:w-3/4 space-y-6">

                <!-- ═══ Emisor ═══ -->
                <div id="emisor" class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center border border-blue-100 dark:border-blue-900/30">
                            <i class="pi pi-building !text-sm text-blue-500"></i>
                        </div>
                        <div><h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Emisor</h2><p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Perfil fiscal</p></div>
                    </div>

                    <div class="flex flex-col gap-1.5 mb-5" v-if="fiscalProfiles.length > 1">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Perfil fiscal *</label>
                        <Select v-model="selectedFiscalProfile" :options="fiscalProfiles" optionLabel="razon_social" placeholder="Selecciona el RFC emisor" class="w-full" @change="() => { form.fiscal_profile_id = selectedFiscalProfile?.id ?? null; }" :pt="selectPt">
                            <template #value="s"><div v-if="s.value" class="flex items-center gap-2"><span class="text-sm font-medium">{{ s.value.rfc }}</span><span class="text-zinc-400">—</span><span class="text-sm">{{ s.value.razon_social }}</span></div></template>
                            <template #option="s"><div class="flex flex-col gap-0.5"><span class="text-sm font-medium">{{ s.option.rfc }}</span><span class="text-xs text-zinc-500">{{ s.option.razon_social }}</span></div></template>
                        </Select>
                        <Message v-if="form.errors.fiscal_profile_id" severity="error" variant="simple" size="small">{{ form.errors.fiscal_profile_id }}</Message>
                    </div>

                    <div v-if="selectedFiscalProfile" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC</label><InputText :modelValue="selectedFiscalProfile.rfc" readonly class="w-full" :pt="readonlyPt" /></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social</label><InputText :modelValue="selectedFiscalProfile.razon_social" readonly class="w-full" :pt="readonlyPt" /></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal</label><InputText :modelValue="emitterRegime" readonly class="w-full" :pt="readonlyPt" /></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">C. P.</label><InputText :modelValue="emitterPostalCode" readonly class="w-full" :pt="readonlyPt" /></div>
                    </div>
                </div>

                <!-- ═══ Receptor ═══ -->
                <div id="receptor" class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center border border-emerald-100 dark:border-emerald-900/30">
                            <i class="pi pi-user !text-sm text-emerald-500"></i>
                        </div>
                        <div><h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Receptor</h2><p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Datos del cliente</p></div>
                    </div>

                    <div class="flex flex-col gap-1.5 mb-5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cliente</label>
                        <Select v-model="form.customer_id" :options="customers" optionLabel="name" optionValue="id" placeholder="Selecciona un cliente" showClear filter class="w-full" :pt="selectPt" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC *</label><InputText v-model="form.receiver_rfc" placeholder="XAXX010101000" class="w-full uppercase" :pt="inputPt" /><Message v-if="form.errors.receiver_rfc" severity="error" variant="simple" size="small">{{ form.errors.receiver_rfc }}</Message></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social *</label><InputText v-model="form.receiver_legal_name" placeholder="Nombre o razón social" class="w-full" :pt="inputPt" /><Message v-if="form.errors.receiver_legal_name" severity="error" variant="simple" size="small">{{ form.errors.receiver_legal_name }}</Message></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal *</label><Select v-model="form.receiver_tax_regime" :options="taxRegimeOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :pt="selectPt" /><Message v-if="form.errors.receiver_tax_regime" severity="error" variant="simple" size="small">{{ form.errors.receiver_tax_regime }}</Message></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Código postal *</label><InputText v-model="form.receiver_postal_code" placeholder="12345" maxlength="5" class="w-full" :pt="inputPt" /><Message v-if="form.errors.receiver_postal_code" severity="error" variant="simple" size="small">{{ form.errors.receiver_postal_code }}</Message></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Uso de CFDI *</label><Select v-model="form.cfdi_use" :options="cfdiUseOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :pt="selectPt" /><Message v-if="form.errors.cfdi_use" severity="error" variant="simple" size="small">{{ form.errors.cfdi_use }}</Message></div>
                    </div>
                </div>

                <!-- ═══ Pago ═══ -->
                <div id="pago" class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center border border-amber-100 dark:border-amber-900/30">
                            <i class="pi pi-credit-card !text-sm text-amber-500"></i>
                        </div>
                        <div><h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Forma y método de pago</h2><p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Condiciones del CFDI</p></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Forma de pago *</label><Select v-model="form.payment_form" :options="paymentFormOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" /><Message v-if="form.errors.payment_form" severity="error" variant="simple" size="small">{{ form.errors.payment_form }}</Message></div>
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Método de pago *</label><Select v-model="form.payment_method" :options="paymentMethodOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" /><Message v-if="form.errors.payment_method" severity="error" variant="simple" size="small">{{ form.errors.payment_method }}</Message></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Moneda</label><Select v-model="form.currency" :options="[{ label: 'MXN — Peso mexicano', value: 'MXN' }, { label: 'USD — Dólar estadounidense', value: 'USD' }]" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" /></div>
                        <div v-if="form.currency !== 'MXN'" class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de cambio *</label><InputNumber v-model="form.exchange_rate" :minFractionDigits="6" :maxFractionDigits="6" :min="0.000001" placeholder="1.000000" class="w-full" :pt="inputNumberPt" /><p class="text-[9px] text-gray-400 m-0">Requerido por el SAT (Anexo 20).</p></div>
                    </div>
                </div>

                <!-- ═══ Conceptos ═══ -->
                <div id="conceptos" class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8">
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center border border-rose-100 dark:border-rose-900/30">
                                <i class="pi pi-list !text-sm text-rose-500"></i>
                            </div>
                            <div><h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Conceptos</h2><p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">{{ form.items.length }} partidas</p></div>
                        </div>
                        <Button type="button" icon="pi pi-plus" label="Agregar" @click="addItem" class="!rounded-full !text-xs !font-bold !uppercase !tracking-widest" />
                    </div>

                    <Message v-if="form.errors.items" severity="error" variant="simple" size="small" class="mb-4">{{ form.errors.items }}</Message>

                    <div v-if="form.items.length === 0" class="rounded-2xl border-2 border-dashed border-zinc-200 dark:border-zinc-800 py-12 text-center">
                        <i class="pi pi-inbox !text-3xl text-zinc-300 dark:text-zinc-600 mb-3 block"></i>
                        <p class="text-sm text-zinc-400 dark:text-zinc-500 m-0">Sin conceptos</p>
                        <p class="text-xs text-zinc-400 mt-1 m-0">Agrega al menos un concepto</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="(item, index) in form.items" :key="index" class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/30 p-5">
                            <!-- Header row -->
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs font-bold text-gray-500 tracking-widest uppercase">Concepto {{ index + 1 }}</span>
                                <Button type="button" icon="pi pi-trash" severity="danger" text rounded size="small" @click="removeItem(index)" v-tooltip.top="'Eliminar concepto'" />
                            </div>

                            <!-- Description + SAT codes -->
                            <div class="flex flex-col gap-1.5 mb-4">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Descripción *</label>
                                <InputText v-model="item.description" placeholder="Descripción del producto o servicio" class="w-full" :pt="inputPt" />
                                <Message v-if="form.errors[`items.${index}.description`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.description`] }}</Message>
                            </div>

                            <!-- SAT codes row -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">ClaveProdServ *</label><InputText v-model="item.sat_product_code" placeholder="01010101" maxlength="15" class="w-full" :pt="inputPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">ClaveUnidad *</label><InputText v-model="item.sat_unit_code" placeholder="H87" maxlength="10" class="w-full" :pt="inputPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Unidad</label><InputText v-model="item.unit_name" placeholder="Pieza, Servicio..." maxlength="50" class="w-full" :pt="inputPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">SKU / No. Ident.</label><InputText v-model="item.no_identificacion" placeholder="SKU-001" maxlength="100" class="w-full" :pt="inputPt" /></div>
                            </div>

                            <!-- Amounts row -->
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Cantidad</label><InputNumber v-model="item.quantity" :minFractionDigits="2" :maxFractionDigits="4" :min="0.0001" class="w-full" :pt="inputNumberPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Precio unitario</label><InputNumber v-model="item.unit_price" mode="currency" currency="MXN" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Descuento</label><InputNumber v-model="item.discount_amount" mode="currency" currency="MXN" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Objeto imp.</label><Select v-model="item.objeto_imp" :options="objetoImpOptions" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" /></div>
                                <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-zinc-500 m-0">Tasa IVA</label><InputNumber v-model="item.tax_rate" suffix="%" :minFractionDigits="2" :maxFractionDigits="2" :min="0" :max="1" class="w-full" :pt="inputNumberPt" /></div>
                            </div>

                            <!-- Line total -->
                            <div class="pt-3 border-t border-zinc-200 dark:border-zinc-800 flex justify-end gap-6 text-xs text-zinc-400">
                                <span>Subtotal: {{ formatCurrency((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)) }}</span>
                                <span>IVA: {{ formatCurrency(((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0) - (parseFloat(item.discount_amount) || 0)) * (parseFloat(item.tax_rate) || 0)) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Submit buttons ═══ -->
                <div class="flex justify-end gap-3 sticky bottom-4 z-20">
                    <Button type="button" label="Guardar como prefactura" icon="pi pi-save" severity="secondary" outlined @click="submit(true)" :loading="form.processing" class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold shadow-lg" />
                    <Button type="submit" label="Timbrar ahora" icon="pi pi-shield" :loading="form.processing" class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold shadow-lg" />
                </div>

            </div>
        </form>
    </AppLayout>
</template>
