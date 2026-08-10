<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { cfdiUseOptions, taxRegimeOptions } from '../satCatalogs';
import { inputPt, selectPt, autoCompleteInputPt } from '../ptConfigs';
import { toArray, extractFiscalData } from '../formHelpers';
import CfdiUseHelp from './Sections/CfdiUseHelp.vue';
import SectionCard from '@/Components/Billing/SectionCard.vue';

const props = defineProps({
    form: { type: Object, required: true },
    customers: { type: [Array, Object], default: () => [] },
    isIngreso: { type: Boolean, default: true },
    isPago: { type: Boolean, default: false },
    isTraslado: { type: Boolean, default: false },
    // Pre-filled customer name on edit mode (display only)
    initialCustomerName: { type: String, default: '' },
});

// ── Customer autocomplete (receptor) ──
const customersList = computed(() => toArray(props.customers));

const customerSearchText = ref('');
const customerSuggestions = ref([]);

onMounted(() => {
    if (props.initialCustomerName) {
        customerSearchText.value = props.initialCustomerName;
    }
});

const searchCustomers = (event) => {
    const query = event.query.toLowerCase().trim();
    if (!query) {
        customerSuggestions.value = [...customersList.value];
    } else {
        customerSuggestions.value = customersList.value.filter(
            c => c.name.toLowerCase().includes(query)
                || (c.company_name && c.company_name.toLowerCase().includes(query))
                || (c.tax_id && c.tax_id.toLowerCase().includes(query)),
        );
    }
};

const onCustomerSelect = (event) => {
    const selected = event.value;
    // Guard: must be a valid object with an id
    if (!selected || typeof selected !== 'object' || !selected.id) return;

    // Always resolve from the master list by ID — never trust the suggestions
    // array reference, which can go stale inside PrimeVue's AutoComplete
    const customer = customersList.value.find(c => c.id === selected.id);
    if (!customer) return;

    // Explicitly sync the display text so v-model stays consistent
    customerSearchText.value = customer.name;
    props.form.customer_id = customer.id;

    // Auto-fill receiver fields from the definitive customer object.
    // Razón social always uppercase (SAT expects the legal name in caps).
    props.form.receiver_rfc = customer.tax_id || '';
    props.form.receiver_legal_name = (customer.company_name || customer.name || '').toUpperCase();

    const fiscal = extractFiscalData(customer);
    props.form.receiver_tax_regime = fiscal.tax_regime || '';
    props.form.receiver_postal_code = fiscal.postal_code || '';
    // Auto-fill the Uso de CFDI only for Ingreso (I) — E, P y T tienen
    // valores fijos o preseleccionados por reglas SAT que no deben sobreescribirse.
    if (customer.cfdi_use && props.isIngreso) {
        props.form.cfdi_use = customer.cfdi_use;
    }
};

// Clear customer association when the user manually deletes the search text
watch(customerSearchText, (val) => {
    if (!val || val.trim() === '') {
        props.form.customer_id = null;
    }
});

// ── Uso de CFDI filtered per comprobante type (SAT rule) ──
const availableCfdiUseOptions = computed(() => {
    if (props.isPago) return cfdiUseOptions.filter(o => o.value === 'CP01');
    if (props.isTraslado) return cfdiUseOptions.filter(o => o.value === 'S01');
    if (props.isIngreso) return cfdiUseOptions.filter(o => !['CP01', 'S01'].includes(o.value));
    return cfdiUseOptions;
});

const isCfdiUseLocked = computed(() => props.isPago || props.isTraslado);
</script>

<template>
    <!-- ═══ Receptor ═══ -->
    <SectionCard id="receptor" icon="pi pi-user" title="Receptor" subtitle="Datos del cliente">

        <div class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Cliente (Opcional)</label>
            <AutoComplete
                v-model="customerSearchText"
                :suggestions="customerSuggestions"
                @complete="searchCustomers"
                @item-select="onCustomerSelect"
                field="name"
                optionLabel="name"
                placeholder="Busca o escribe el nombre del cliente"
                class="w-full"
                dropdown
                :pt="autoCompleteInputPt"
            >
                <template #option="slotProps">
                    <div class="flex items-center justify-between gap-3 py-1">
                        <div class="flex flex-col gap-0.5 min-w-0">
                            <span class="text-sm font-medium text-slate-900 dark:text-neutral-100 truncate">{{ slotProps.option.name }}</span>
                            <span v-if="slotProps.option.company_name && slotProps.option.company_name !== slotProps.option.name" class="text-[11px] text-slate-500 dark:text-neutral-400 truncate">{{ slotProps.option.company_name }}</span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <Tag v-if="slotProps.option.tax_id" :value="slotProps.option.tax_id" severity="info" class="!text-[9px] !px-2 !py-0.5" />
                            <i v-if="slotProps.option.tax_regime" class="pi pi-check-circle !text-[11px] text-emerald-400" v-tooltip.top="'Datos fiscales completos'" />
                        </div>
                    </div>
                </template>
            </AutoComplete>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">RFC *</label><InputText v-model="form.receiver_rfc" placeholder="XAXX010101000" class="w-full uppercase" :pt="inputPt" /><Message v-if="form.errors.receiver_rfc" severity="error" variant="simple" size="small">{{ form.errors.receiver_rfc }}</Message></div>
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Razón social *</label><InputText :modelValue="form.receiver_legal_name" @update:modelValue="(val) => form.receiver_legal_name = String(val ?? '').toUpperCase()" placeholder="Nombre o razón social" class="w-full" :pt="inputPt" /><p class="text-[11px] text-slate-500 dark:text-neutral-400 m-0">Ej: "Servicios" - omite el SA de CV, S de RL, SC, etc.</p><Message v-if="form.errors.receiver_legal_name" severity="error" variant="simple" size="small">{{ form.errors.receiver_legal_name }}</Message></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Régimen fiscal *</label><Select v-model="form.receiver_tax_regime" :options="taxRegimeOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :pt="selectPt" /><Message v-if="form.errors.receiver_tax_regime" severity="error" variant="simple" size="small">{{ form.errors.receiver_tax_regime }}</Message></div>
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Código postal *</label><InputText v-model="form.receiver_postal_code" placeholder="Ej. 12345" maxlength="5" class="w-full" :pt="inputPt" /><Message v-if="form.errors.receiver_postal_code" severity="error" variant="simple" size="small">{{ form.errors.receiver_postal_code }}</Message></div>
            <div class="flex flex-col gap-1.5">
                <div class="flex items-center gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Uso de CFDI *</label>
                    <CfdiUseHelp :model-value="form.cfdi_use" />
                </div>
                <Select v-model="form.cfdi_use" :options="availableCfdiUseOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :pt="selectPt" :disabled="isCfdiUseLocked" />
                <Message v-if="form.errors.cfdi_use" severity="error" variant="simple" size="small">{{ form.errors.cfdi_use }}</Message>
                <Message v-if="isPago" severity="info" variant="simple" size="small">Un CFDI de pago usa obligatoriamente el uso "CP01 - Pagos" (regla SAT).</Message>
                <Message v-else-if="isTraslado" severity="info" variant="simple" size="small">Una carta porte usa obligatoriamente el uso "S01 - Sin efectos fiscales" (regla SAT).</Message>
            </div>
        </div>
    </SectionCard>
</template>
