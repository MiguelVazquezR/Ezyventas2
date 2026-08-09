<script setup>
import { computed, ref, watch } from 'vue';
import { paymentFormOptions, pagoMonedaOptions } from '../../satCatalogs';
import { inputPt, selectPt, inputNumberPt, readonlyPt, datePickerPt, autoCompleteInputPt } from '../../ptConfigs';
import { toArray, blankPagoDocument, pagoInvoiceLabel, formatDateShort, formatCurrency } from '../../formHelpers';
import SectionCard from '@/Components/Billing/SectionCard.vue';

const props = defineProps({
    form: { type: Object, required: true },
    ppdInvoices: { type: [Array, Object], default: () => [] },
});

const ppdInvoicesList = computed(() => toArray(props.ppdInvoices));

// ── Pago (Tipo P) — buscador de facturas PPD timbradas ──
// Facturas certificadas con método PPD, filtradas por emisor y receptor.
const availablePpdInvoices = computed(() => {
    const profileId = props.form.fiscal_profile_id;
    const customerId = props.form.customer_id;
    const receiverRfc = (props.form.receiver_rfc || '').trim().toUpperCase();

    return ppdInvoicesList.value.filter((inv) => {
        // Emisor: siempre que ya se haya seleccionado un RFC emisor
        if (profileId && inv.fiscal_profile_id && Number(inv.fiscal_profile_id) !== Number(profileId)) return false;

        // Receptor: cliente seleccionado o RFC escrito a mano en el formulario
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

const hasPpdInvoices = computed(() => ppdInvoicesList.value.length > 0);

// Sugerencias por documento (caché por índice)
const pagoInvoiceSuggestions = ref({});

const searchPagoInvoices = (event, index) => {
    const query = (event.query || '').toLowerCase().trim();
    const list = availablePpdInvoices.value;
    pagoInvoiceSuggestions.value[index] = !query
        ? [...list]
        : list.filter(inv =>
            pagoInvoiceLabel(inv).toLowerCase().includes(query)
            || (inv.folio && String(inv.folio).toLowerCase().includes(query))
            || (inv.uuid && inv.uuid.toLowerCase().includes(query))
            || (inv.receiver_legal_name && inv.receiver_legal_name.toLowerCase().includes(query)),
        );
};

const onPagoInvoiceSelect = (event, index) => {
    const doc = props.form.pago_documentos[index];
    const inv = event.value;
    if (!inv || typeof inv !== 'object' || !inv.uuid) return;
    doc.invoice_id = inv.id ?? null;
    doc.folio = pagoInvoiceLabel(inv);
    doc.uuid = inv.uuid;
};

// Si el usuario edita el folio a mano (factura fuera del sistema), se
// desvincula la factura seleccionada para no enviar un UUID incorrecto.
watch(
    () => props.form.pago_documentos.map((d) => d.folio),
    () => {
        props.form.pago_documentos.forEach((doc) => {
            if (!doc.invoice_id) return;
            const linked = ppdInvoicesList.value.find(inv => Number(inv.id) === Number(doc.invoice_id));
            const expected = linked ? pagoInvoiceLabel(linked) : '';
            if (doc.folio !== expected) {
                doc.invoice_id = null;
                doc.uuid = '';
            }
        });
    },
);

// Cuando el saldo insoluto llega a $0, la factura queda liquidada ante el SAT.
const isFacturaLiquidada = (doc) =>
    doc.imp_saldo_insoluto !== null
    && doc.imp_saldo_insoluto !== undefined
    && doc.imp_saldo_insoluto !== ''
    && Number(doc.imp_saldo_insoluto) === 0;

const addPagoDocument = () => props.form.pago_documentos.push(blankPagoDocument(false));
const removePagoDocument = (index) => props.form.pago_documentos.splice(index, 1);

// IdDocumento must be a real RFC 4122 UUID (8-4-4-4-12 hex groups) or the PAC
// rejects the CFDI de pago. Validates the field client-side for instant feedback.
const UUID_REGEX = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;
const hasInvalidUuid = (value) => !!value && value.trim() !== '' && !UUID_REGEX.test(value.trim());

// Warns the user when they type/paste a character outside 0-9 / A-F (e.g. "G",
// "Ñ"). The character is stripped automatically, but the message explains why
// instead of silently discarding it, so the user is not confused.
const invalidUuidChar = ref('');
let invalidUuidTimer = null;

// Auto-format the UUID while the user types or pastes: keeps only hex digits
// (32 max) and inserts the hyphens automatically (8-4-4-4-12) so manual entry
// is easier — the user only writes the 32 characters, never the separators.
const formatUuid = (value, doc) => {
    const raw = String(value ?? '');
    const cleaned = raw.replace(/[^0-9a-fA-F]/g, '');

    // If any non-hex char was typed/pasted, notify the user briefly.
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
    doc.uuid = groups.filter(Boolean).join('-');
};
</script>

<template>
    <!-- ═══ Detalle del pago (Complemento de pago 2.0) ═══ -->
    <SectionCard id="detalle-pago" icon="pi pi-wallet" title="Detalle del pago" subtitle="Complemento de pago 2.0">

        <!-- Concepto automatizado (no editable) -->
        <div class="rounded-2xl border border-slate-100 dark:border-neutral-800 bg-slate-50/50 dark:bg-neutral-900/50 p-4">
            <div class="flex items-center gap-2 mb-3">
                <i class="pi pi-info-circle !text-sm text-cyan-500"></i>
                <span class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-400">Concepto automatizado</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="flex flex-col gap-1"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">ClaveProdServ</label><InputText modelValue="84111506" readonly class="w-full" :pt="readonlyPt" /></div>
                <div class="flex flex-col gap-1"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">ClaveUnidad</label><InputText modelValue="ACT" readonly class="w-full" :pt="readonlyPt" /></div>
                <div class="flex flex-col gap-1"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Descripción</label><InputText modelValue="Pago" readonly class="w-full" :pt="readonlyPt" /></div>
                <div class="flex flex-col gap-1"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Objeto imp.</label><InputText modelValue="01 - No objeto de impuesto" readonly class="w-full" :pt="readonlyPt" /></div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Fecha y hora de recepción del pago *</label>
                <DatePicker v-model="form.pago_fecha" showTime hourFormat="24" dateFormat="dd/mm/yy" placeholder="Selecciona fecha y hora" class="w-full" :pt="datePickerPt" />
                <Message v-if="form.errors.pago_fecha" severity="error" variant="simple" size="small">{{ form.errors.pago_fecha }}</Message>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Forma de pago real *</label>
                <Select v-model="form.pago_forma" :options="paymentFormOptions" optionLabel="label" optionValue="value" placeholder="Selecciona la forma de pago" class="w-full" :pt="selectPt" />
                <Message v-if="form.errors.pago_forma" severity="error" variant="simple" size="small">{{ form.errors.pago_forma }}</Message>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Moneda del pago *</label>
                <Select v-model="form.pago_moneda" :options="pagoMonedaOptions" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" />
                <Message v-if="form.errors.pago_moneda" severity="error" variant="simple" size="small">{{ form.errors.pago_moneda }}</Message>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Monto total del pago *</label>
                <InputNumber v-model="form.pago_monto" placeholder="$0.00" mode="currency" currency="MXN" locale="es-MX" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" />
                <Message v-if="form.errors.pago_monto" severity="error" variant="simple" size="small">{{ form.errors.pago_monto }}</Message>
            </div>
            <div v-if="form.pago_moneda !== 'MXN'" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Tipo de cambio del pago *</label>
                <InputNumber v-model="form.pago_tipo_cambio" placeholder="17.33" :minFractionDigits="2" :maxFractionDigits="6" :min="0.000001" locale="es-MX" class="w-full" :pt="inputNumberPt" />
                <Message v-if="form.errors.pago_tipo_cambio" severity="error" variant="simple" size="small">{{ form.errors.pago_tipo_cambio }}</Message>
                <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Requerido por el SAT cuando el pago es en moneda extranjera.</p>
            </div>
        </div>

        <!-- Documentos relacionados del pago -->
        <div class="pt-5 border-t border-slate-100 dark:border-neutral-800 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold tracking-wider text-slate-400 uppercase m-0">Documentos relacionados</h3>
                    <p class="text-[10px] text-slate-400/70 dark:text-neutral-500 mt-0.5 m-0">Facturas PPD que cubre este pago</p>
                </div>
                <Button type="button" icon="pi pi-plus" label="Agregar" severity="secondary" text size="small" @click="addPagoDocument" class="!rounded-full !px-5 !py-2 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95" />
            </div>

            <Message v-if="form.errors['pago_documentos']" severity="error" variant="simple" size="small" class="w-full">{{ form.errors['pago_documentos'] }}</Message>

            <div v-if="form.pago_documentos.length === 0" class="rounded-2xl border-2 border-dashed border-slate-200 dark:border-neutral-800 py-8 text-center">
                <i class="pi pi-file !text-2xl text-slate-300 dark:text-neutral-600 mb-2 block"></i>
                <p class="text-xs text-slate-400 dark:text-neutral-500 m-0">Agrega al menos un documento relacionado</p>
            </div>

            <div v-else class="space-y-4">
                <div v-for="(doc, index) in form.pago_documentos" :key="index" class="rounded-2xl border border-slate-100 dark:border-neutral-800 bg-slate-50/50 dark:bg-neutral-900/50 p-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-500 dark:text-neutral-400 tracking-widest uppercase flex items-center gap-2">
                            Documento {{ index + 1 }}
                            <Tag v-if="doc.is_default" value="Obligatoria" severity="info" class="!text-[9px] !px-2 !py-0.5" />
                        </span>
                        <Button v-if="!doc.is_default" type="button" icon="pi pi-trash" severity="danger" text rounded size="small" @click="removePagoDocument(index)" v-tooltip.top="'Eliminar documento'" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Folio de Factura timbrada PPD *</label>
                            <AutoComplete
                                v-model="doc.folio"
                                :suggestions="pagoInvoiceSuggestions[index] || []"
                                @complete="(e) => searchPagoInvoices(e, index)"
                                @item-select="(e) => onPagoInvoiceSelect(e, index)"
                                field="folio"
                                optionLabel="folio"
                                placeholder="Busca o escribe el folio de la factura timbrada PPD"
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
                            <Message v-if="form.errors[`pago_documentos.${index}.folio`]" severity="error" variant="simple" size="small">{{ form.errors[`pago_documentos.${index}.folio`] }}</Message>
                            <Message v-if="hasPpdInvoices && availablePpdInvoices.length === 0" severity="info" variant="simple" size="small">No hay facturas PPD timbradas para este emisor y cliente. Puedes escribir el folio manualmente.</Message>
                            <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Selecciona una factura del sistema o escribe el folio si la factura se timbró fuera de la plataforma.</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">UUID de la factura *</label>
                            <InputText
                                :modelValue="doc.uuid"
                                @update:modelValue="(val) => formatUuid(val, doc)"
                                placeholder="Se llena automáticamente si seleccionas la factura"
                                maxlength="36"
                                class="w-full"
                                :pt="inputPt"
                            />
                            <Message v-if="form.errors[`pago_documentos.${index}.uuid`]" severity="error" variant="simple" size="small">{{ form.errors[`pago_documentos.${index}.uuid`] }}</Message>
                            <Message v-if="hasInvalidUuid(doc.uuid)" severity="error" variant="simple" size="small">El UUID no tiene un formato válido. Debe verse así: 12345678-1234-1234-1234-123456789012.</Message>
                            <Message v-if="invalidUuidChar" severity="warn" variant="simple" size="small">Solo se permiten números (0-9) y letras de la A a la F. El carácter "{{ invalidUuidChar }}" no es válido y se omitió.</Message>
                            <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Identificador único del SAT. Escribe o pega los 32 caracteres: los guiones se agregan automáticamente.</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Núm. parcialidad *</label>
                            <InputNumber v-model="doc.num_parcialidad" placeholder="Ej. 1" :min="1" :maxFractionDigits="0" locale="es-MX" class="w-full" :pt="inputNumberPt" />
                            <Message v-if="form.errors[`pago_documentos.${index}.num_parcialidad`]" severity="error" variant="simple" size="small">{{ form.errors[`pago_documentos.${index}.num_parcialidad`] }}</Message>
                            <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Número consecutivo de abono que se le está haciendo a la factura.</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Saldo anterior *</label>
                            <InputNumber v-model="doc.imp_saldo_ant" placeholder="$0.00" mode="currency" currency="MXN" locale="es-MX" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" />
                            <Message v-if="form.errors[`pago_documentos.${index}.imp_saldo_ant`]" severity="error" variant="simple" size="small">{{ form.errors[`pago_documentos.${index}.imp_saldo_ant`] }}</Message>
                            <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Es el monto pendiente que tenía la factura justo antes de aplicar este pago.</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Importe pagado *</label>
                            <InputNumber v-model="doc.imp_pagado" placeholder="$0.00" mode="currency" currency="MXN" locale="es-MX" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" />
                            <Message v-if="form.errors[`pago_documentos.${index}.imp_pagado`]" severity="error" variant="simple" size="small">{{ form.errors[`pago_documentos.${index}.imp_pagado`] }}</Message>
                            <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Cantidad exacta de dinero que pagó el cliente.</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Saldo insoluto *</label>
                            <InputNumber v-model="doc.imp_saldo_insoluto" placeholder="$0.00" mode="currency" currency="MXN" locale="es-MX" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" />
                            <Message v-if="form.errors[`pago_documentos.${index}.imp_saldo_insoluto`]" severity="error" variant="simple" size="small">{{ form.errors[`pago_documentos.${index}.imp_saldo_insoluto`] }}</Message>
                            <p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Monto que resta por pagar de la factura.</p>
                            <Message v-if="isFacturaLiquidada(doc)" severity="success" variant="simple" size="small">Con este pago la factura quedará liquidada ante el SAT.</Message>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SectionCard>
</template>
