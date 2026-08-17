<script setup>
import { computed, ref, onMounted } from 'vue';
import SectionCard from '@/Components/Billing/SectionCard.vue';
import { autoCompleteInputPt } from '../../ptConfigs';
import { toArray, blankPagoDocument, applyPpdToPagoDocument, pagoInvoiceLabel, formatCurrency, formatDateShort } from '../../formHelpers';

const props = defineProps({
    form: { type: Object, required: true },
    ppdInvoices: { type: [Array, Object], default: () => [] },
    multipleEmitters: { type: Boolean, default: false },
});

const ppdInvoicesList = computed(() => toArray(props.ppdInvoices));

// Con varios emisores en la cuenta primero hay que elegir el RFC emisor: la
// lista de facturas PPD se filtra por emisor y no debe poder seleccionarse
// nada antes.
const needsEmitter = computed(() => props.multipleEmitters && !props.form.fiscal_profile_id);

// ── Facturas PPD timbradas (ventas a crédito facturadas) ──
const availablePpdInvoices = computed(() => {
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

const selectedPpd = ref(null);
const searchText = ref('');
const suggestions = ref([]);

const searchPpd = (event) => {
    const query = (event.query || '').toLowerCase().trim();
    const list = availablePpdInvoices.value;
    suggestions.value = !query
        ? [...list]
        : list.filter(inv =>
            pagoInvoiceLabel(inv).toLowerCase().includes(query)
            || String(inv.sale_folio || '').toLowerCase().includes(query)
            || String(inv.folio || '').toLowerCase().includes(query)
            || (inv.receiver_legal_name || '').toLowerCase().includes(query),
        );
};

// Aplica una factura PPD al formulario: llena el receptor y el primer documento
// obligatorio (UUID/folio, siguiente parcialidad, saldo anterior, importe
// pagado y saldo insoluto calculado). Todo queda editable.
const applyPpd = (ppd) => {
    if (!ppd || typeof ppd !== 'object' || !ppd.uuid) return false;

    selectedPpd.value = ppd;
    searchText.value = pagoInvoiceLabel(ppd);

    // Llena el receptor con los datos fiscales de la factura PPD.
    props.form.customer_id = ppd.customer_id ?? null;
    props.form.receiver_rfc = ppd.receiver_rfc || '';
    props.form.receiver_legal_name = ppd.receiver_legal_name || '';
    props.form.receiver_tax_regime = ppd.receiver_tax_regime || '';
    props.form.receiver_postal_code = ppd.receiver_postal_code || '';

    if (!props.form.pago_documentos || props.form.pago_documentos.length === 0) {
        props.form.pago_documentos.push(blankPagoDocument(true));
    }
    applyPpdToPagoDocument(props.form.pago_documentos[0], ppd, props.form.pago_monto);
    return true;
};

const onPpdSelect = (event) => applyPpd(event.value);

// Pre-selección vía "Facturar pago" desde el Show de una factura PPD
// (?tipo=P&ppd=<id>): se carga la venta a crédito y su factura PPD.
onMounted(() => {
    const preselectId = new URLSearchParams(window.location.search).get('ppd');
    if (!preselectId) return;
    const ppd = ppdInvoicesList.value.find((inv) => Number(inv.id) === Number(preselectId));
    if (ppd) applyPpd(ppd);
});

const removePpd = () => {
    selectedPpd.value = null;
    searchText.value = '';
};
</script>

<template>
    <!-- ═══ Venta relacionada (CFDI de Pago) ═══ -->
    <SectionCard id="venta-ppd" icon="pi pi-shopping-bag" title="Venta relacionada" subtitle="Selecciona la venta a crédito (factura PPD) que cubre este pago">
        <!-- Selected PPD sale summary -->
        <div v-if="selectedPpd" class="rounded-2xl border border-slate-100 dark:border-neutral-800 bg-slate-50/50 dark:bg-neutral-900/50 p-4">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
                        <i class="pi pi-check-circle !text-sm text-emerald-500"></i>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-semibold text-slate-900 dark:text-neutral-100">
                            {{ selectedPpd.sale_folio ? `Venta ${selectedPpd.sale_folio}` : pagoInvoiceLabel(selectedPpd) }}
                        </span>
                        <span class="text-[11px] text-slate-500 dark:text-neutral-400 truncate">
                            Factura {{ pagoInvoiceLabel(selectedPpd) }} · {{ formatDateShort(selectedPpd.issued_at) }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <div class="flex flex-col items-end">
                        <span class="text-3xl font-light tracking-tight text-slate-900 dark:text-white">{{ formatCurrency(selectedPpd.total) }}</span>
                        <span class="text-lg font-bold text-amber-600 dark:text-amber-400">Resta por pagar: {{ formatCurrency(selectedPpd.remaining) }}</span>
                    </div>
                    <Button type="button" icon="pi pi-times" severity="secondary" text rounded size="small" @click="removePpd" v-tooltip.top="'Desvincular'" />
                </div>
            </div>

            <div class="mt-3 pt-3 border-t border-slate-100 dark:border-neutral-800">
                <Message severity="info" variant="simple" size="small">
                    Se completó automáticamente el receptor y el primer documento con el saldo anterior
                    ({{ formatCurrency(selectedPpd.remaining) }}), el importe pagado y el saldo insoluto calculado.
                    Puedes editarlo o agregar más documentos en "Detalle del pago".
                </Message>
            </div>
        </div>

        <!-- PPD sale search -->
        <div v-else class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Buscar venta a crédito (factura PPD)</label>
            <AutoComplete
                v-model="searchText"
                :suggestions="suggestions"
                @complete="searchPpd"
                @item-select="onPpdSelect"
                field="folio"
                optionLabel="folio"
                placeholder="Busca por folio de venta, factura o cliente..."
                class="w-full"
                dropdown
                :disabled="needsEmitter"
                :pt="autoCompleteInputPt"
            >
                <template #option="slotProps">
                    <div class="flex flex-col gap-0.5 py-0.5 w-full">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-slate-900 dark:text-neutral-100 truncate">
                                {{ slotProps.option.sale_folio ? `Venta ${slotProps.option.sale_folio}` : pagoInvoiceLabel(slotProps.option) }}
                            </span>
                            <span class="text-xs font-semibold text-slate-700 dark:text-neutral-300 shrink-0">{{ formatCurrency(parseFloat(slotProps.option.total) || 0) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[11px] text-slate-500 dark:text-neutral-400 truncate">{{ slotProps.option.receiver_legal_name }}</span>
                            <span class="text-[10px] font-semibold text-amber-600 dark:text-amber-400 shrink-0">Resta: {{ formatCurrency(parseFloat(slotProps.option.remaining) || 0) }}</span>
                        </div>
                    </div>
                </template>
            </AutoComplete>
            <Message v-if="needsEmitter" severity="info" variant="simple" size="small">Primero selecciona el emisor para cargar sus facturas.</Message>
            <Message v-if="!needsEmitter && ppdInvoicesList.length > 0 && availablePpdInvoices.length === 0" severity="info" variant="simple" size="small">No hay facturas PPD timbradas para este emisor y cliente.</Message>
            <p class="text-[11px] text-slate-500 dark:text-neutral-400 m-0">
                Solo se muestran ventas facturadas como PPD. Al seleccionarla se llenarán el receptor y el documento relacionado automáticamente.
            </p>
        </div>
    </SectionCard>
</template>
