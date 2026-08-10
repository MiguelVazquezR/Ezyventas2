<script setup>
import { computed, ref, watch } from 'vue';
import { tipoRelacionOptions } from '../../satCatalogs';
import { selectPt, autoCompleteInputPt } from '../../ptConfigs';
import { toArray, pagoInvoiceLabel, formatDateShort, formatCurrency } from '../../formHelpers';
import SectionCard from '@/Components/Billing/SectionCard.vue';

const props = defineProps({
    form: { type: Object, required: true },
    ppdInvoices: { type: [Array, Object], default: () => [] },
    multipleEmitters: { type: Boolean, default: false },
});

const ppdInvoicesList = computed(() => toArray(props.ppdInvoices));

// Con varios emisores en la cuenta primero hay que elegir el RFC emisor: la
// lista de facturas relacionadas se filtra por emisor y no debe poder
// seleccionarse nada antes.
const needsEmitter = computed(() => props.multipleEmitters && !props.form.fiscal_profile_id);

// Facturas timbradas disponibles para relacionar, filtradas por emisor y
// receptor (misma lógica que el buscador del CFDI de pago).
const availableInvoices = computed(() => {
    if (needsEmitter.value) return [];

    const profileId = props.form.fiscal_profile_id;
    const customerId = props.form.customer_id;
    const receiverRfc = (props.form.receiver_rfc || '').trim().toUpperCase();

    return ppdInvoicesList.value.filter((inv) => {
        if (profileId && inv.fiscal_profile_id && Number(inv.fiscal_profile_id) !== Number(profileId)) return false;

        if (customerId) {
            const matchByCustomer = Number(inv.customer_id) === Number(customerId);
            const matchByRfc = !inv.customer_id && receiverRfc && (inv.receiver_rfc || '').toUpperCase() === receiverRfc;
            if (!matchByCustomer && !matchByRfc) return false;
        } else if (receiverRfc) {
            if ((inv.receiver_rfc || '').toUpperCase() !== receiverRfc) return false;
        }

        return true;
    });
});

// Sugerencias por fila (caché por índice)
const relatedSuggestions = ref({});

// Referencias a los AutoComplete por fila (el input interno no está
// controlado por modelValue, así que se sincroniza el DOM manualmente).
const acRefs = ref([]);

const searchInvoices = (event, index) => {
    const query = (event.query || '').toLowerCase().trim();
    const list = availableInvoices.value;
    relatedSuggestions.value[index] = !query
        ? [...list]
        : list.filter(inv =>
            pagoInvoiceLabel(inv).toLowerCase().includes(query)
            || (inv.uuid && inv.uuid.toLowerCase().includes(query))
            || (inv.receiver_legal_name && inv.receiver_legal_name.toLowerCase().includes(query)),
        );
};

// Al seleccionar una factura se guarda su UUID (no el folio de la venta).
const onInvoiceSelect = (event, index) => {
    const inv = event.value;
    if (!inv || typeof inv !== 'object' || !inv.uuid) return;
    props.form.cfdi_relacionados[index] = inv.uuid;
};

const addRelatedUuid = () => props.form.cfdi_relacionados.push('');
const removeRelatedUuid = (index) => props.form.cfdi_relacionados.splice(index, 1);

// Auto-formato del UUID mientras el usuario escribe o pega: conserva solo
// dígitos hexadecimales (máx. 32) e inserta los guiones automáticamente
// (8-4-4-4-12), así solo se escriben los 32 caracteres, nunca los separadores.
const invalidUuidChar = ref('');
let invalidUuidTimer = null;

const formatUuid = (value, index) => {
    const raw = String(value ?? '');
    const cleaned = raw.replace(/[^0-9a-fA-F]/g, '');

    // Si se escribió/pegó un carácter fuera de 0-9 / A-F, se avisa brevemente.
    if (cleaned !== raw) {
        const bad = raw.match(/[^0-9a-fA-F]/)?.[0] ?? '';
        invalidUuidChar.value = bad;
        if (invalidUuidTimer) clearTimeout(invalidUuidTimer);
        invalidUuidTimer = setTimeout(() => { invalidUuidChar.value = ''; }, 3000);
    }

    const hex = cleaned.slice(0, 32).toUpperCase();
    const groups = [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20, 32),
    ];
    const formatted = groups.filter(Boolean).join('-');
    props.form.cfdi_relacionados[index] = formatted;

    // El input del AutoComplete no está controlado por modelValue: se
    // escribe el valor formateado directo al DOM para que los guiones se vean
    // al escribir y el campo nunca exceda los 36 caracteres.
    const inputEl = acRefs.value[index]?.$el?.querySelector('input');
    if (inputEl && inputEl.value !== formatted) inputEl.value = formatted;
};

// Una nota de crédito debe relacionar al menos un UUID.
const hasAtLeastOneRelated = computed(() =>
    (props.form.cfdi_relacionados || []).some((u) => String(u || '').trim() !== ''),
);

// Al llenar al menos un UUID se limpia el error marcado al intentar timbrar
// o guardar la prefactura, para que la validación desaparezca.
watch(hasAtLeastOneRelated, (has) => {
    if (has) props.form.clearErrors('cfdi_relacionados');
});
</script>

<template>
    <!-- ═══ CFDI relacionados (Nota de crédito) ═══ -->
    <SectionCard id="cfdi-relacionados" icon="pi pi-link" title="CFDI relacionados" subtitle="Requerido por el SAT en notas de crédito">

        <div class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Tipo de relación *</label>
            <Select v-model="form.tipo_relacion" :options="tipoRelacionOptions" optionLabel="label" optionValue="value" placeholder="Selecciona el tipo de relación" class="w-full" :pt="selectPt" />
            <Message v-if="form.errors.tipo_relacion" severity="error" variant="simple" size="small">{{ form.errors.tipo_relacion }}</Message>
        </div>

        <div class="flex items-center justify-between">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">UUID de las facturas relacionadas *</label>
            <Button type="button" icon="pi pi-plus" label="Agregar UUID" severity="secondary" text size="small" :disabled="needsEmitter" @click="addRelatedUuid" class="!rounded-full !px-5 !py-2 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95" />
        </div>

        <!-- Con varios emisores, primero se debe elegir el RFC emisor -->
        <Message v-if="needsEmitter" severity="info" variant="simple" size="small">
            Primero selecciona el emisor para cargar sus facturas.
        </Message>

        <!-- La validación de "al menos un UUID" aparece solo al intentar timbrar o guardar -->
        <Message v-if="form.errors.cfdi_relacionados" severity="error" variant="simple" size="small">{{ form.errors.cfdi_relacionados }}</Message>

        <div v-if="form.cfdi_relacionados.length === 0" class="rounded-2xl border-2 border-dashed border-slate-200 dark:border-neutral-800 py-8 text-center">
            <i class="pi pi-hashtag !text-2xl text-slate-300 dark:text-neutral-600 mb-2 block"></i>
            <p class="text-xs text-slate-400 dark:text-neutral-500 m-0"></p>
        </div>

        <div v-else class="space-y-3">
            <div v-for="(uuid, index) in form.cfdi_relacionados" :key="index" class="flex items-center gap-3">
                <div class="flex flex-col gap-1 flex-1">
                    <AutoComplete
                        :ref="(el) => (acRefs[index] = el)"
                        :modelValue="form.cfdi_relacionados[index]"
                        @update:modelValue="(val) => formatUuid(val, index)"
                        :suggestions="relatedSuggestions[index] || []"
                        @complete="(e) => searchInvoices(e, index)"
                        @item-select="(e) => onInvoiceSelect(e, index)"
                        field="uuid"
                        optionLabel="uuid"
                        placeholder="Busca la factura a relacionar o escribe el UUID"
                        :disabled="needsEmitter"
                        maxlength="36"
                        class="w-full"
                        dropdown
                        :pt="autoCompleteInputPt"
                    >
                        <template #option="slotProps">
                            <div class="flex flex-col gap-0.5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-medium flex items-center gap-1.5">
                                        <i class="pi pi-file !text-xs text-slate-400"></i>
                                        {{ pagoInvoiceLabel(slotProps.option) }}
                                    </span>
                                    <span class="text-xs font-semibold text-slate-700 dark:text-neutral-300">{{ formatCurrency(parseFloat(slotProps.option.total) || 0) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-[10px] text-slate-500 dark:text-neutral-400 truncate">{{ slotProps.option.receiver_legal_name }}</span>
                                    <span class="text-[10px] text-slate-400 dark:text-neutral-500 shrink-0">{{ formatDateShort(slotProps.option.issued_at) }}</span>
                                </div>
                            </div>
                        </template>
                    </AutoComplete>
                    <Message v-if="form.errors[`cfdi_relacionados.${index}`]" severity="error" variant="simple" size="small">{{ form.errors[`cfdi_relacionados.${index}`] }}</Message>                    <Message v-if="invalidUuidChar" severity="warn" variant="simple" size="small">Solo se permiten números (0-9) y letras de la A a la F. El carácter "{{ invalidUuidChar }}" no es válido y se omitió.</Message>                    <p v-if="!needsEmitter && ppdInvoicesList.length > 0 && availableInvoices.length === 0" class="text-[13px] text-slate-400 dark:text-neutral-500 m-0">No hay facturas timbradas para este emisor y cliente. Puedes escribir el UUID manualmente.</p>
                </div>
                <Button type="button" icon="pi pi-trash" severity="danger" text rounded size="small" @click="removeRelatedUuid(index)" v-tooltip.top="'Eliminar UUID'" />
            </div>
        </div>
    </SectionCard>
</template>
