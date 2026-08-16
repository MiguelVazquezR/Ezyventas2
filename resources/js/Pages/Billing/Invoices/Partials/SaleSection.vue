<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import SectionCard from '@/Components/Billing/SectionCard.vue';
import { autoCompleteInputPt } from '../ptConfigs';
import { applySaleToForm, formatCurrency, formatDateShort } from '../formHelpers';

const props = defineProps({
    form: { type: Object, required: true },
    // Already-linked sale on edit mode (invoice.transaction from the backend).
    linkedSale: { type: Object, default: null },
});

// ── State ──
const selectedSale = ref(null);
const searchText = ref('');
const suggestions = ref([]);
const searchLoading = ref(false);
const loadingMore = ref(false);
const errorMessage = ref('');
// Pagination state for the progressive sale search (10 per page).
const SALE_PAGE_SIZE = 10;
const saleQuery = ref('');
const saleHasMore = ref(false);
// UI note returned by applySaleToForm (payment-form auto-fill info).
const saleNote = ref(null);

onMounted(() => {
    if (props.linkedSale?.id) {
        selectedSale.value = props.linkedSale;
        searchText.value = props.linkedSale.folio || '';
    }
});

// ── Server-side sale search (paginated, 10 per page) ──
const fetchSalesPage = async (query, offset, append) => {
    if (append) loadingMore.value = true; else searchLoading.value = true;

    try {
        const { data } = await axios.get(route('billing.invoices.sales.search'), {
            params: { search: query, offset, limit: SALE_PAGE_SIZE },
        });
        const list = Array.isArray(data) ? data : [];
        suggestions.value = append ? [...suggestions.value, ...list] : list;
        saleHasMore.value = list.length === SALE_PAGE_SIZE;
    } catch {
        if (!append) {
            suggestions.value = [];
            saleHasMore.value = false;
        }
    } finally {
        if (append) loadingMore.value = false; else searchLoading.value = false;
    }
};

const searchSales = async (event) => {
    const query = String(event.query || '').trim();
    // Avoid refetching the same query when the dropdown re-opens.
    if (query === saleQuery.value && suggestions.value.length > 0) return;

    saleQuery.value = query;
    errorMessage.value = '';
    suggestions.value = [];
    saleHasMore.value = false;
    await fetchSalesPage(query, 0, false);
};

// Progressive loading on scroll (virtual scroller lazy).
const onSalesLazyLoad = async (event) => {
    if (!saleHasMore.value || loadingMore.value || searchLoading.value) return;
    if ((event?.last ?? 0) >= suggestions.value.length) {
        await fetchSalesPage(saleQuery.value, suggestions.value.length, true);
    }
};

// ── Select & apply a sale ──
const onSaleSelect = async (event) => {
    const sale = event.value;
    if (!sale || typeof sale !== 'object' || !sale.id) return;

    searchLoading.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.get(route('billing.invoices.sales.show', sale.id));
        selectedSale.value = data;
        searchText.value = data.folio || '';
        saleNote.value = applySaleToForm(props.form, data);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'No se pudo cargar la venta seleccionada.';
        searchText.value = '';
        selectedSale.value = null;
    } finally {
        searchLoading.value = false;
    }
};

// ── Detach the sale (keeps the auto-filled data editable) ──
const removeSale = () => {
    props.form.transaction_id = null;
    selectedSale.value = null;
    searchText.value = '';
    saleNote.value = null;
    errorMessage.value = '';
};
</script>

<template>
    <!-- ═══ Venta relacionada ═══ -->
    <SectionCard id="venta" icon="pi pi-shopping-bag" title="Venta relacionada" subtitle="Liga esta factura a una venta (opcional)">
        <!-- Selected sale summary -->
        <div v-if="selectedSale" class="rounded-2xl border border-slate-100 dark:border-neutral-800 bg-slate-50/50 dark:bg-neutral-900/50 p-4">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
                        <i class="pi pi-check-circle !text-sm text-emerald-500"></i>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-semibold text-slate-900 dark:text-neutral-100">{{ selectedSale.folio }}</span>
                        <span class="text-[11px] text-slate-500 dark:text-neutral-400 truncate">
                            {{ selectedSale.customer?.name || 'Sin cliente' }} · {{ formatDateShort(selectedSale.created_at) }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="text-lg font-light tracking-tight text-slate-900 dark:text-white">{{ formatCurrency(selectedSale.total) }}</span>
                    <Button type="button" icon="pi pi-times" severity="secondary" text rounded size="small" @click="removeSale" v-tooltip.top="'Desvincular venta'" />
                </div>
            </div>

            <div class="mt-3 pt-3 border-t border-slate-100 dark:border-neutral-800 flex flex-col gap-1.5">
                <Message severity="info" variant="simple" size="small">
                    El cliente y los conceptos se prellenaron desde la venta. Puedes editarlos libremente.
                    Si tus precios ya incluyen IVA, activa "Los precios ya incluyen IVA" en la sección Conceptos
                    para que el total de la factura coincida con el de la venta.
                </Message>
                <Message v-if="saleNote?.multiplePaymentMethods" severity="warn" variant="simple" size="small">
                    La venta se pagó con más de una forma de pago. El SAT solo permite una en la factura: se seleccionó
                    "{{ saleNote.selectedFormaLabel }}" (la de mayor monto). Puedes cambiarla en "Forma y método de pago".
                </Message>
            </div>
        </div>

        <!-- Sale search -->
        <div v-else class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Buscar venta (Opcional)</label>
            <AutoComplete
                v-model="searchText"
                :suggestions="suggestions"
                @complete="searchSales"
                @item-select="onSaleSelect"
                field="folio"
                optionLabel="folio"
                placeholder="Busca por folio o cliente..."
                class="w-full"
                dropdown
                :loading="searchLoading"
                :pt="autoCompleteInputPt"
                :virtualScrollerOptions="{ lazy: true, itemSize: 58, onLazyLoad: onSalesLazyLoad, showLoader: true }"
            >
                <template #option="slotProps">
                    <div class="flex items-center justify-between gap-3 py-1 w-full">
                        <div class="flex flex-col gap-0.5 min-w-0">
                            <span class="text-sm font-medium text-slate-900 dark:text-neutral-100 truncate">{{ slotProps.option.folio }}</span>
                            <span class="text-[11px] text-slate-500 dark:text-neutral-400 truncate">{{ slotProps.option.customer_name || 'Sin cliente' }}</span>
                        </div>
                        <span class="text-xs font-light text-slate-700 dark:text-neutral-300 shrink-0">{{ formatCurrency(slotProps.option.total) }}</span>
                    </div>
                </template>
            </AutoComplete>
            <Message v-if="errorMessage" severity="error" variant="simple" size="small">{{ errorMessage }}</Message>
            <p class="text-[11px] text-slate-500 dark:text-neutral-400 m-0">
                Solo se muestran ventas completadas y pagadas en su totalidad que aún no tienen factura.
            </p>
        </div>
    </SectionCard>
</template>
