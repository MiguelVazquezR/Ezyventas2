<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    customers: Array,
    billingSetting: Object,   // nullable — branch billing config
    hasBillingSettings: Boolean,
});

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Facturación', url: route('invoices.index') },
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

    // Payment
    payment_form: '',
    payment_method: '',
    currency: 'MXN',

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

// ──────────────────────────────────────
// Items management
// ──────────────────────────────────────
const blankItem = () => ({
    description: '',
    quantity: 1,
    unit_price: 0,
    sat_product_code: '',
    sat_unit_code: 'H87',
    tax_type: '002',
    tax_rate: 0.16,
    discount_amount: 0,
});

const addItem = () => {
    form.items.push(blankItem());
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

// ──────────────────────────────────────
// Totals (computed reactively from items)
// ──────────────────────────────────────
const totals = computed(() => {
    let subtotal = 0;
    let discountTotal = 0;
    let taxesTotal = 0;

    form.items.forEach(item => {
        const qty = parseFloat(item.quantity) || 0;
        const price = parseFloat(item.unit_price) || 0;
        const discount = parseFloat(item.discount_amount) || 0;
        const rate = parseFloat(item.tax_rate) || 0;

        const lineSubtotal = qty * price;
        const lineDiscount = Math.min(discount, lineSubtotal);
        const base = lineSubtotal - lineDiscount;
        const lineTax = base * rate;

        subtotal += lineSubtotal;
        discountTotal += lineDiscount;
        taxesTotal += lineTax;
    });

    return {
        subtotal: round(subtotal),
        discount_total: round(discountTotal),
        taxes_total: round(taxesTotal),
        total: round(subtotal - discountTotal + taxesTotal),
    };
});

const round = (n) => Math.round(n * 100) / 100;

// ──────────────────────────────────────
// Currency formatter
// ──────────────────────────────────────
const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

// ──────────────────────────────────────
// Customer auto-fill
// ──────────────────────────────────────
watch(() => form.customer_id, (newId) => {
    if (!newId) return;
    const customer = props.customers?.find(c => c.id === newId);
    if (!customer) return;
    if (!form.receiver_rfc) form.receiver_rfc = customer.tax_id || '';
    if (!form.receiver_legal_name) form.receiver_legal_name = customer.company_name || customer.name || '';
    if (customer.address) {
        const addr = typeof customer.address === 'object' ? customer.address : {};
        if (!form.receiver_postal_code) form.receiver_postal_code = addr.postal_code || addr.zip || '';
    }
});

// ──────────────────────────────────────
// Submit
// ──────────────────────────────────────
const submit = () => {
    form.post(route('invoices.store'));
};
</script>

<template>
    <AppLayout title="Nueva factura">
        <!-- Breadcrumb -->
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 !mb-1" />

        <!-- Header -->
        <div class="flex items-center justify-between mt-2 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white m-0">Nueva factura</h1>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1 m-0">
                    Completa los datos del receptor y los conceptos para generar el CFDI 4.0
                </p>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             Onboarding guard — no billing settings yet
             ════════════════════════════════════════ -->
        <div
            v-if="!props.hasBillingSettings"
            class="max-w-lg mx-auto bg-gray-50 border border-gray-200 rounded-2xl p-8 text-center mt-10"
        >
            <i class="pi pi-exclamation-triangle !text-4xl text-amber-400 mb-4 block"></i>
            <h2 class="text-lg font-semibold text-gray-800 m-0 mb-2">
                Configuración fiscal requerida
            </h2>
            <p class="text-sm text-gray-500 m-0 mb-6 leading-relaxed">
                Aún no has configurado tu información fiscal. Para poder emitir facturas legales, es obligatorio registrar primero tus datos de emisor.
            </p>
            <a
                :href="route('invoices.settings')"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors no-underline"
            >
                <i class="pi pi-external-link !text-sm"></i>
                Ir a configuración fiscal
            </a>
        </div>

        <!-- ════════════════════════════════════════
             Full CFDI form (visible when billing is configured)
             ════════════════════════════════════════ -->
        <form v-else @submit.prevent="submit" class="max-w-5xl mx-auto space-y-6">

            <!-- ════════════════════════════════════════
                 SECTION: Receiver data (Receptor)
                 ════════════════════════════════════════ -->
            <div class="rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] p-6">
                <div class="flex items-center gap-2 mb-5">
                    <i class="pi pi-user !text-sm text-gray-400" />
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0">Datos del receptor</h2>
                </div>

                <!-- Customer selector -->
                <div class="flex flex-col gap-1.5 mb-5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Cliente
                    </label>
                    <Select
                        v-model="form.customer_id"
                        :options="props.customers"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Selecciona un cliente existente"
                        showClear
                        filter
                        class="w-full"
                        :pt="{
                            root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
                        }"
                    />
                    <Message v-if="form.errors.customer_id" severity="error" variant="simple" size="small">
                        {{ form.errors.customer_id }}
                    </Message>
                </div>

                <Divider class="!my-5 !border-gray-100 dark:!border-[#3a3a3a]" />

                <!-- RFC + Razón Social -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            RFC *
                        </label>
                        <InputText
                            v-model="form.receiver_rfc"
                            placeholder="XAXX010101000"
                            class="w-full uppercase"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-3' },
                            }"
                        />
                        <Message v-if="form.errors.receiver_rfc" severity="error" variant="simple" size="small">
                            {{ form.errors.receiver_rfc }}
                        </Message>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Razón social *
                        </label>
                        <InputText
                            v-model="form.receiver_legal_name"
                            placeholder="Nombre o razón social"
                            class="w-full"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-3' },
                            }"
                        />
                        <Message v-if="form.errors.receiver_legal_name" severity="error" variant="simple" size="small">
                            {{ form.errors.receiver_legal_name }}
                        </Message>
                    </div>
                </div>

                <!-- Régimen Fiscal + Código Postal + Uso CFDI -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Régimen fiscal *
                        </label>
                        <Select
                            v-model="form.receiver_tax_regime"
                            :options="taxRegimeOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Selecciona"
                            filter
                            class="w-full"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
                            }"
                        />
                        <Message v-if="form.errors.receiver_tax_regime" severity="error" variant="simple" size="small">
                            {{ form.errors.receiver_tax_regime }}
                        </Message>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Código postal *
                        </label>
                        <InputText
                            v-model="form.receiver_postal_code"
                            placeholder="12345"
                            maxlength="5"
                            class="w-full"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-3' },
                            }"
                        />
                        <Message v-if="form.errors.receiver_postal_code" severity="error" variant="simple" size="small">
                            {{ form.errors.receiver_postal_code }}
                        </Message>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Uso de CFDI *
                        </label>
                        <Select
                            v-model="form.cfdi_use"
                            :options="cfdiUseOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Selecciona"
                            filter
                            class="w-full"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
                            }"
                        />
                        <Message v-if="form.errors.cfdi_use" severity="error" variant="simple" size="small">
                            {{ form.errors.cfdi_use }}
                        </Message>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION: Payment
                 ════════════════════════════════════════ -->
            <div class="rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] p-6">
                <div class="flex items-center gap-2 mb-5">
                    <i class="pi pi-credit-card !text-sm text-gray-400" />
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0">Forma y método de pago</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Forma de pago *
                        </label>
                        <Select
                            v-model="form.payment_form"
                            :options="paymentFormOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Selecciona"
                            class="w-full"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
                            }"
                        />
                        <Message v-if="form.errors.payment_form" severity="error" variant="simple" size="small">
                            {{ form.errors.payment_form }}
                        </Message>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Método de pago *
                        </label>
                        <Select
                            v-model="form.payment_method"
                            :options="paymentMethodOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Selecciona"
                            class="w-full"
                            :pt="{
                                root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
                            }"
                        />
                        <Message v-if="form.errors.payment_method" severity="error" variant="simple" size="small">
                            {{ form.errors.payment_method }}
                        </Message>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION: Items (Conceptos)
                 ════════════════════════════════════════ -->
            <div class="rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] p-6">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-2">
                        <i class="pi pi-list !text-sm text-gray-400" />
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0">Conceptos</h2>
                    </div>
                    <Button
                        type="button"
                        icon="pi pi-plus"
                        label="Agregar concepto"
                        size="small"
                        @click="addItem"
                        class="!rounded-full"
                    />
                </div>

                <Message v-if="form.errors.items" severity="error" variant="simple" size="small" class="mb-4">
                    {{ form.errors.items }}
                </Message>

                <!-- Empty state -->
                <div
                    v-if="form.items.length === 0"
                    class="rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a] bg-gray-50/50 dark:bg-[#1a1a1a] py-10 text-center"
                >
                    <i class="pi pi-inbox !text-3xl text-gray-300 dark:text-gray-600 mb-3 block" />
                    <p class="text-sm text-gray-400 dark:text-gray-500 m-0">No hay conceptos agregados</p>
                    <p class="text-xs text-gray-300 dark:text-gray-600 mt-1 m-0">
                        Agrega al menos un concepto para continuar
                    </p>
                </div>

                <!-- Items list -->
                <div v-else class="space-y-4">
                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50/50 dark:bg-[#1a1a1a] p-4"
                    >
                        <!-- Row 1: Description -->
                        <div class="flex flex-col gap-1.5 mb-4">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                Concepto {{ index + 1 }}
                            </label>
                            <div class="flex gap-2">
                                <InputText
                                    v-model="item.description"
                                    placeholder="Descripción del producto o servicio"
                                    class="w-full"
                                    :pt="{
                                        root: { class: '!rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-2.5' },
                                    }"
                                />
                                <Button
                                    type="button"
                                    icon="pi pi-trash"
                                    severity="danger"
                                    text
                                    rounded
                                    size="small"
                                    @click="removeItem(index)"
                                />
                            </div>
                            <Message v-if="form.errors[`items.${index}.description`]" severity="error" variant="simple" size="small">
                                {{ form.errors[`items.${index}.description`] }}
                            </Message>
                        </div>

                        <!-- Row 2: Quantity, Unit price, SAT codes -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    Cantidad
                                </label>
                                <InputNumber
                                    v-model="item.quantity"
                                    :minFractionDigits="2"
                                    :maxFractionDigits="4"
                                    :min="0.0001"
                                    class="w-full"
                                    :pt="{
                                        input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5' } },
                                    }"
                                />
                                <Message v-if="form.errors[`items.${index}.quantity`]" severity="error" variant="simple" size="small">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    Precio unitario
                                </label>
                                <InputNumber
                                    v-model="item.unit_price"
                                    mode="currency"
                                    currency="MXN"
                                    :minFractionDigits="2"
                                    :min="0"
                                    class="w-full"
                                    :pt="{
                                        input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5' } },
                                    }"
                                />
                                <Message v-if="form.errors[`items.${index}.unit_price`]" severity="error" variant="simple" size="small">
                                    {{ form.errors[`items.${index}.unit_price`] }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    ClaveProdServ
                                </label>
                                <InputText
                                    v-model="item.sat_product_code"
                                    placeholder="01010101"
                                    maxlength="15"
                                    class="w-full"
                                    :pt="{
                                        root: { class: '!rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-2.5' },
                                    }"
                                />
                                <Message v-if="form.errors[`items.${index}.sat_product_code`]" severity="error" variant="simple" size="small">
                                    {{ form.errors[`items.${index}.sat_product_code`] }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    ClaveUnidad
                                </label>
                                <InputText
                                    v-model="item.sat_unit_code"
                                    placeholder="H87"
                                    maxlength="10"
                                    class="w-full"
                                    :pt="{
                                        root: { class: '!rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-2.5' },
                                    }"
                                />
                                <Message v-if="form.errors[`items.${index}.sat_unit_code`]" severity="error" variant="simple" size="small">
                                    {{ form.errors[`items.${index}.sat_unit_code`] }}
                                </Message>
                            </div>
                        </div>

                        <!-- Row 3: Tax rate + Discount (compact inline) -->
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-3">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    Tipo impuesto
                                </label>
                                <Select
                                    v-model="item.tax_type"
                                    :options="[{ label: '002 — IVA', value: '002' }, { label: '001 — ISR', value: '001' }]"
                                    optionLabel="label"
                                    optionValue="value"
                                    class="w-full"
                                    :pt="{
                                        root: { class: '!rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' },
                                    }"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    Tasa (%)
                                </label>
                                <InputNumber
                                    v-model="item.tax_rate"
                                    suffix="%"
                                    :minFractionDigits="2"
                                    :maxFractionDigits="2"
                                    :min="0"
                                    :max="1"
                                    class="w-full"
                                    :pt="{
                                        input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5' } },
                                    }"
                                />
                                <Message v-if="form.errors[`items.${index}.tax_rate`]" severity="error" variant="simple" size="small">
                                    {{ form.errors[`items.${index}.tax_rate`] }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                    Descuento
                                </label>
                                <InputNumber
                                    v-model="item.discount_amount"
                                    mode="currency"
                                    currency="MXN"
                                    :minFractionDigits="2"
                                    :min="0"
                                    class="w-full"
                                    :pt="{
                                        input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5' } },
                                    }"
                                />
                            </div>
                        </div>

                        <!-- Line subtotal preview -->
                        <div class="mt-3 pt-3 border-t border-gray-100 dark:border-[#3a3a3a] flex justify-end gap-6 text-xs">
                            <span class="text-gray-400 dark:text-gray-500">
                                {{ formatCurrency((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)) }}
                            </span>
                            <span class="text-gray-400 dark:text-gray-500">
                                IVA {{ formatCurrency(((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0) - (parseFloat(item.discount_amount) || 0)) * (parseFloat(item.tax_rate) || 0)) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION: Totals
                 ════════════════════════════════════════ -->
            <div class="rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] p-6">
                <div class="flex items-center gap-2 mb-5">
                    <i class="pi pi-calculator !text-sm text-gray-400" />
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0">Totales</h2>
                </div>

                <!-- Subtotal -->
                <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Subtotal</span>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">
                        {{ formatCurrency(totals.subtotal) }}
                    </span>
                </div>

                <!-- Discount -->
                <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Descuento</span>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">
                        − {{ formatCurrency(totals.discount_total) }}
                    </span>
                </div>

                <!-- IVA -->
                <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-[#3a3a3a]">
                    <span class="text-sm text-gray-500 dark:text-gray-400">IVA trasladado</span>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">
                        {{ formatCurrency(totals.taxes_total) }}
                    </span>
                </div>

                <!-- Total -->
                <div class="flex items-center justify-between pt-4">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Total</span>
                    <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">
                        {{ formatCurrency(totals.total) }}
                    </span>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 Emitter preview (if billingSetting exists)
                 ════════════════════════════════════════ -->
            <div
                v-if="props.billingSetting"
                class="rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] p-6"
            >
                <div class="flex items-center gap-2 mb-3">
                    <i class="pi pi-building !text-sm text-gray-400" />
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0">Datos del emisor</h2>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs text-gray-400 dark:text-gray-500">
                    <div>
                        <span class="block text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">RFC</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ props.billingSetting.emitter_rfc }}</span>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Razón social</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ props.billingSetting.emitter_legal_name }}</span>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Régimen fiscal</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ props.billingSetting.emitter_tax_regime }}</span>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">C. P.</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ props.billingSetting.emitter_postal_code }}</span>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 Submit
                 ════════════════════════════════════════ -->
            <div class="flex justify-end gap-3 pt-2 pb-8">
                <Button
                    type="button"
                    label="Cancelar"
                    severity="secondary"
                    text
                    class="!rounded-full"
                    @click="$inertia.visit(route('invoices.index'))"
                />
                <Button
                    type="submit"
                    label="Crear factura"
                    icon="pi pi-file"
                    :loading="form.processing"
                    class="!rounded-full"
                />
            </div>

        </form>
    </AppLayout>
</template>
