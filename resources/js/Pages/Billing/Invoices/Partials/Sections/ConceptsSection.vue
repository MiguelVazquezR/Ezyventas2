<script setup>
import { computed, ref } from 'vue';
import { satUnitOptions, objetoImpOptions, taxRateOptions } from '../../satCatalogs';
import { inputPt, selectPt, inputNumberPt, readonlyPt, autoCompleteInputPt } from '../../ptConfigs';
import { toArray, blankItem, formatCurrency, fuzzySearchCollection } from '../../formHelpers';
import SectionCard from '@/Components/Billing/SectionCard.vue';

const props = defineProps({
    form: { type: Object, required: true },
    products: { type: [Array, Object], default: () => [] },
    services: { type: [Array, Object], default: () => [] },
    isTraslado: { type: Boolean, default: false },
});

// ── Concept autocomplete (products & services) ──
const productsList = computed(() => toArray(props.products));
const servicesList = computed(() => toArray(props.services));

const availableConceptItems = computed(() => [
    ...productsList.value.map(p => ({ ...p, type: 'Producto', price: p.selling_price })),
    ...servicesList.value.map(s => ({ ...s, type: 'Servicio', price: s.base_price })),
]);

const conceptSuggestions = ref({});
// Full match list per concept row (capped), revealed in chunks of 10.
const conceptMatches = ref({});

const searchConceptItems = (event, index) => {
    const query = String(event.query || '').trim();
    const matches = fuzzySearchCollection(
        availableConceptItems.value,
        query,
        (item) => [item.name, item.sku, item.sat_product_code],
        200,
    );
    conceptMatches.value[index] = matches;
    conceptSuggestions.value[index] = matches.slice(0, 10);
};

// Progressive loading on scroll per concept row (virtual scroller lazy).
const onConceptLazyLoad = (event, index) => {
    const matches = conceptMatches.value[index] || [];
    const visible = conceptSuggestions.value[index] || [];
    const target = (event?.last ?? 0) + 10;
    if (visible.length < matches.length) {
        conceptSuggestions.value[index] = matches.slice(0, target);
    }
};

const onConceptSelect = (event, index) => {
    const item = props.form.items[index];
    const selected = event.value;
    if (!selected || typeof selected !== 'object') return;
    item.description = selected.name;
    // Track the catalog record so SAT codes filled manually can be persisted
    // back to the product/service when the invoice is saved.
    item.itemable_id = selected.id ?? null;
    item.itemable_type = selected.type === 'Servicio' ? 'service' : 'product';
    // Carta porte (T): el precio unitario siempre es 0 (sin importes).
    item.unit_price = props.isTraslado ? 0 : (parseFloat(selected.price) || 0);
    if (props.isTraslado) {
        item.objeto_imp = '01';
        item.discount_amount = 0;
        item.tax_rate = 0;
    }
    // Auto-fill SKU (only products have it)
    if (selected.sku) {
        item.no_identificacion = selected.sku;
    }
    // Auto-fill SAT codes from product/service
    if (selected.sat_product_code) {
        item.sat_product_code = selected.sat_product_code;
    }
    if (selected.sat_unit_code) {
        item.sat_unit_code = selected.sat_unit_code;
    }
};

// ── Concepts management ──
const addItem = () => {
    const item = blankItem();
    // Carta porte (T): los conceptos no llevan importes ni impuestos.
    if (props.isTraslado) {
        item.objeto_imp = '01';
        item.unit_price = 0;
        item.discount_amount = 0;
        item.tax_rate = 0;
    }
    props.form.items.push(item);
};
const removeItem = (index) => props.form.items.splice(index, 1);

// ── "Precios con IVA incluido" per-line breakdown ──
const lineGross = (item) =>
    ((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)) - (parseFloat(item.discount_amount) || 0);

const lineBase = (item) => {
    const rate = parseFloat(item.tax_rate) || 0;
    const gross = lineGross(item);
    return rate > 0 ? Math.round((gross / (1 + rate)) * 100) / 100 : gross;
};

const lineIva = (item) => Math.round((lineGross(item) - lineBase(item)) * 100) / 100;

const hasIncludedIvaBreakdown = (item) =>
    !!props.form.prices_include_iva
    && item.objeto_imp === '02'
    && item.tax_rate !== 'Exento'
    && (parseFloat(item.tax_rate) || 0) > 0;

const needsZeroTaxWarning = (item) =>
    !!props.form.prices_include_iva
    && (item.objeto_imp !== '02' || item.tax_rate === 'Exento' || (parseFloat(item.tax_rate) || 0) === 0);
</script>

<template>
    <!-- ═══ Conceptos ═══ -->
    <SectionCard id="conceptos" icon="pi pi-list" title="Conceptos" :subtitle="`${form.items.length} partidas`">
        <template #actions>
            <Button type="button" icon="pi pi-plus" label="Agregar" severity="secondary" text size="small" @click="addItem" class="!rounded-full !px-5 !py-2 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95" />
        </template>

        <Message v-if="isTraslado" severity="info" variant="simple" size="small">
            En una carta porte los conceptos no llevan importes ni impuestos (ObjetoImp 01).
        </Message>

        <Message v-if="form.errors.items" severity="error" variant="simple" size="small">{{ form.errors.items }}</Message>

        <!-- Precios con IVA incluido (modo de cálculo flexible por factura) -->
        <div v-if="!isTraslado" class="flex items-start gap-3 rounded-2xl border border-slate-100 dark:border-neutral-800 bg-slate-50/50 dark:bg-neutral-900/50 px-4 py-3.5">
            <Checkbox v-model="form.prices_include_iva" :binary="true" inputId="pricesIncludeIva" class="mt-0.5" />
            <div class="flex flex-col gap-0.5">
                <label for="pricesIncludeIva" class="text-xs font-semibold text-slate-700 dark:text-neutral-200 cursor-pointer">Los precios ya incluyen IVA</label>
                <p class="text-[11px] text-slate-500 dark:text-neutral-400 m-0 leading-relaxed">El sistema separa la base y el IVA de cada precio para que el total de la factura coincida con lo cobrado. El importe por concepto no cambia.</p>
            </div>
        </div>

        <div v-if="form.items.length === 0" class="rounded-2xl border-2 border-dashed border-slate-200 dark:border-neutral-800 py-12 text-center">
            <i class="pi pi-inbox !text-3xl text-slate-300 dark:text-neutral-600 mb-3 block"></i>
            <p class="text-sm text-slate-400 dark:text-neutral-500 m-0">Sin conceptos</p>
            <p class="text-xs text-slate-400 dark:text-neutral-500 mt-1 m-0">Agrega al menos un concepto</p>
        </div>

        <div v-else class="space-y-4">
            <div v-for="(item, index) in form.items" :key="index" class="rounded-2xl border border-slate-100 dark:border-neutral-800 bg-slate-50/50 dark:bg-neutral-900/50 p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-neutral-400 tracking-widest uppercase">Concepto {{ index + 1 }}</span>
                    <Button type="button" icon="pi pi-trash" severity="danger" text rounded size="small" @click="removeItem(index)" v-tooltip.top="'Eliminar concepto'" />
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Descripción del producto o servicio *</label>
                    <AutoComplete
                        v-model="item.description"
                        :suggestions="conceptSuggestions[index] || []"
                        @complete="(e) => searchConceptItems(e, index)"
                        @item-select="(e) => onConceptSelect(e, index)"
                        field="name"
                        optionLabel="name"
                        placeholder="Busca o escribe un concepto..."
                        class="w-full"
                        dropdown
                        :pt="autoCompleteInputPt"
                        :virtualScrollerOptions="{ lazy: true, itemSize: 48, onLazyLoad: (e) => onConceptLazyLoad(e, index) }"
                    >
                        <template #option="slotProps">
                            <div class="flex items-center gap-2">
                                <span class="text-sm">{{ slotProps.option.name }}</span>
                                <Tag :value="slotProps.option.type" :severity="slotProps.option.type === 'Servicio' ? 'success' : 'info'" class="!text-[10px]" />
                            </div>
                        </template>
                    </AutoComplete>
                    <Message v-if="form.errors[`items.${index}.description`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.description`] }}</Message>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">ClaveProdServ *</label><InputText v-model="item.sat_product_code" placeholder="01010101" maxlength="8" class="w-full" :class="{ '!border-red-400': form.errors[`items.${index}.sat_product_code`] }" :pt="inputPt" /><Message v-if="form.errors[`items.${index}.sat_product_code`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.sat_product_code`] }}</Message></div>
                    <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">ClaveUnidad *</label><Select v-model="item.sat_unit_code" :options="satUnitOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" filter class="w-full" :class="{ '!border-red-400': form.errors[`items.${index}.sat_unit_code`] }" :pt="selectPt"><template #option="s"><div class="flex flex-col gap-0.5"><span class="text-sm font-medium">{{ s.option.label }}</span><span class="text-xs text-slate-500 dark:text-neutral-400">{{ s.option.description }}</span></div></template></Select><Message v-if="form.errors[`items.${index}.sat_unit_code`]" severity="error" variant="simple" size="small">{{ form.errors[`items.${index}.sat_unit_code`] }}</Message></div>
                    <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">SKU / No. Ident.</label><InputText v-model="item.no_identificacion" placeholder="SKU-001" maxlength="100" class="w-full" :pt="inputPt" /></div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Cantidad</label><InputNumber v-model="item.quantity" placeholder="1" :minFractionDigits="2" :maxFractionDigits="4" :min="0.0001" locale="es-MX" class="w-full" :pt="inputNumberPt" /></div>
                    <div v-if="!isTraslado" class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Precio unitario</label><InputNumber v-model="item.unit_price" placeholder="$0.00" mode="currency" currency="MXN" locale="es-MX" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" /></div>
                    <div v-else class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Precio unitario</label><InputText modelValue="$0.00" readonly class="w-full" :pt="readonlyPt" /></div>
                    <div v-if="!isTraslado" class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Descuento</label><InputNumber v-model="item.discount_amount" placeholder="$0.00" mode="currency" currency="MXN" locale="es-MX" :minFractionDigits="2" :min="0" class="w-full" :pt="inputNumberPt" /></div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Objeto imp.</label><Select v-model="item.objeto_imp" :options="objetoImpOptions" optionLabel="label" optionValue="value" :disabled="isTraslado" class="w-full" :pt="selectPt"><template #option="s"><div class="flex flex-col gap-0.5"><span class="text-sm font-medium">{{ s.option.label }}</span><span class="text-[10px] text-slate-400 dark:text-neutral-500 leading-tight">{{ s.option.description }}</span></div></template></Select></div>
                    <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Tasa IVA</label><Select v-if="item.objeto_imp === '02'" v-model="item.tax_rate" :options="taxRateOptions" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" /><InputText v-else modelValue="No aplica" readonly class="w-full" :pt="readonlyPt" /></div>
                </div>

                <Message v-if="needsZeroTaxWarning(item)" severity="warn" variant="simple" size="small">
                    Este concepto no desglosará IVA. Úsalo solo si el producto o servicio es tasa 0 % o exento.
                </Message>

                <div class="pt-3 border-t border-slate-100 dark:border-neutral-800 flex flex-wrap justify-end gap-x-6 gap-y-1 text-[12px] text-slate-500 dark:text-neutral-400">
                    <template v-if="hasIncludedIvaBreakdown(item)">
                        <span>Base: {{ formatCurrency(lineBase(item)) }}</span>
                        <span>IVA incluido: {{ formatCurrency(lineIva(item)) }}</span>
                        <span>Importe: {{ formatCurrency(lineGross(item)) }}</span>
                    </template>
                    <template v-else>
                        <span>Subtotal: {{ formatCurrency((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)) }}</span>
                        <span>IVA: {{ item.objeto_imp !== '02' ? formatCurrency(0) : item.tax_rate === 'Exento' ? 'Exento' : formatCurrency(((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0) - (parseFloat(item.discount_amount) || 0)) * (parseFloat(item.tax_rate) || 0)) }}</span>
                    </template>
                </div>
            </div>
        </div>
    </SectionCard>
</template>
