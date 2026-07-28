<script setup>
import { ref, watch, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';

const emit = defineEmits(['loaded']);

// ──────────────────────────────────────
// Filters
// ──────────────────────────────────────
const search = ref('');
const filterStatus = ref(null);
const filterMethod = ref(null);
const filterDateFrom = ref(null);
const filterDateTo = ref(null);

const statusOptions = [
    { label: 'Todos los estados', value: null },
    { label: 'Pendiente', value: 'pending' },
    { label: 'En revisión', value: 'awaiting_review' },
    { label: 'Aprobado', value: 'approved' },
    { label: 'Rechazado', value: 'rejected' },
    { label: 'Fallido', value: 'failed' },
    { label: 'Acreditado', value: 'stamps_applied' },
];

const methodOptions = [
    { label: 'Todos los métodos', value: null },
    { label: 'Mercado Pago', value: 'mercadopago' },
    { label: 'Transferencia', value: 'bank_transfer' },
    { label: 'Ajuste manual', value: 'manual_adjustment' },
];

// ──────────────────────────────────────
// Data & pagination
// ──────────────────────────────────────
const movements = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const loading = ref(false);
const page = ref(1);
const perPage = ref(20);
const sortField = ref('created_at');
const sortOrder = ref('desc');

let searchTimeout = null;

function loadMovements() {
    loading.value = true;
    const params = new URLSearchParams({
        page: page.value,
        per_page: perPage.value,
        sort_field: sortField.value,
        sort_order: sortOrder.value,
    });
    if (search.value) params.set('search', search.value);
    if (filterStatus.value) params.set('status', filterStatus.value);
    if (filterMethod.value) params.set('payment_method', filterMethod.value);
    if (filterDateFrom.value) params.set('date_from', filterDateFrom.value);
    if (filterDateTo.value) params.set('date_to', filterDateTo.value);

    fetch(route('admin.stamps.movements') + '?' + params)
        .then(r => r.json())
        .then(data => {
            movements.value = data.movements;
            emit('loaded', data.movements.total);
        })
        .finally(() => { loading.value = false; });
}

function onPage(event) {
    page.value = event.page + 1;
    perPage.value = event.rows;
    loadMovements();
}

function onSort(event) {
    sortField.value = event.sortField;
    sortOrder.value = event.sortOrder === 1 ? 'asc' : 'desc';
    loadMovements();
}

function onSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        page.value = 1;
        loadMovements();
    }, 400);
}

function clearFilters() {
    search.value = '';
    filterStatus.value = null;
    filterMethod.value = null;
    filterDateFrom.value = null;
    filterDateTo.value = null;
    page.value = 1;
    loadMovements();
}

watch([filterStatus, filterMethod, filterDateFrom, filterDateTo], () => {
    page.value = 1;
    loadMovements();
});

// ── Initial load ────────────────────────
onMounted(() => loadMovements());

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function statusLabel(s) {
    const labels = {
        pending: 'Pendiente', awaiting_review: 'En revisión', approved: 'Aprobado',
        rejected: 'Rechazado', failed: 'Fallido', stamps_applied: 'Acreditado',
    };
    return labels[s] || s;
}
function statusSeverity(s) {
    const map = { pending: 'warn', awaiting_review: 'info', approved: 'success', rejected: 'danger', failed: 'danger', stamps_applied: 'success' };
    return map[s] || 'secondary';
}
function methodLabel(m) {
    const labels = { mercadopago: 'Mercado Pago', bank_transfer: 'Transferencia', manual_adjustment: 'Ajuste manual' };
    return labels[m] || m;
}
function adjLabel(t) { return t === 'remove' ? 'Retiro' : 'Agregar'; }
function fmtCurrency(v) { return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v || 0); }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('es-MX', { dateStyle: 'medium' }) : '—'; }
function fmtNum(n) { return new Intl.NumberFormat('es-MX').format(n || 0); }

const dataTablePt = {
    root: { class: '!bg-transparent' },
    headerRow: { class: '!bg-transparent' },
};
</script>

<template>
    <div class="p-6 space-y-4">
        <!-- ── Filters bar ────────────────────────── -->
        <div class="flex flex-col lg:flex-row gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 !text-xs" />
                <input
                    v-model="search" @input="onSearch"
                    placeholder="Buscar por suscriptor o RFC..."
                    class="w-full pl-9 pr-4 py-2.5 rounded-2xl text-sm bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:border-primary-500 transition-colors"
                />
            </div>
            <Select v-model="filterStatus" :options="statusOptions" optionLabel="label" optionValue="value"
                placeholder="Estado" class="w-40"
                :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-sm' } }" />
            <Select v-model="filterMethod" :options="methodOptions" optionLabel="label" optionValue="value"
                placeholder="Método" class="w-44"
                :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-sm' } }" />
            <input type="date" v-model="filterDateFrom"
                class="w-36 px-3 py-2.5 rounded-2xl text-sm bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] text-gray-900 dark:text-white focus:outline-none focus:border-primary-500 transition-colors" />
            <input type="date" v-model="filterDateTo"
                class="w-36 px-3 py-2.5 rounded-2xl text-sm bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] text-gray-900 dark:text-white focus:outline-none focus:border-primary-500 transition-colors" />
            <Button icon="pi pi-filter-slash" severity="secondary" class="!rounded-full shrink-0" @click="clearFilters" v-tooltip.top="'Limpiar filtros'" />
        </div>

        <!-- ── DataTable ──────────────────────────── -->
        <DataTable
            :value="movements.data"
            :paginator="movements.total > perPage"
            :rows="perPage"
            :totalRecords="movements.total"
            :lazy="true"
            :loading="loading"
            @page="onPage"
            @sort="onSort"
            sortField="created_at"
            :sortOrder="-1"
            removableSort
            stripedRows
            class="w-full text-sm"
            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
            currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} movimientos"
            :rowsPerPageOptions="[10, 20, 50, 100]"
            :pt="dataTablePt"
        >
            <template #empty>
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <i class="pi pi-inbox !text-3xl text-gray-300 dark:text-gray-600 mb-3" />
                    <p class="text-sm text-gray-500 m-0">No se encontraron movimientos.</p>
                </div>
            </template>

            <Column header="Fecha" field="created_at" sortable style="width: 8rem">
                <template #body="{ data }">
                    <span class="text-xs text-gray-500 whitespace-nowrap">{{ fmtDate(data.created_at) }}</span>
                </template>
            </Column>

            <Column header="RFC" style="width: 8rem">
                <template #body="{ data }">
                    <span class="text-xs font-mono text-gray-600 dark:text-gray-400">{{ data.fiscal_profile?.rfc ?? '—' }}</span>
                </template>
            </Column>

            <Column header="Suscriptor">
                <template #body="{ data }">
                    <Link
                        v-if="data.fiscal_profile?.subscription?.id"
                        :href="route('admin.subscriptions.show', data.fiscal_profile.subscription.id)"
                        class="text-xs text-primary-500 hover:underline"
                    >
                        {{ data.fiscal_profile.subscription.commercial_name ?? '—' }}
                    </Link>
                    <span v-else class="text-xs text-gray-400">—</span>
                </template>
            </Column>

            <Column header="Cantidad" field="stamp_quantity" sortable style="width: 6rem">
                <template #body="{ data }">
                    <span class="font-medium text-gray-900 dark:text-white">{{ fmtNum(data.stamp_quantity) }}</span>
                </template>
            </Column>

            <Column header="Monto" field="amount_total" sortable style="width: 7rem">
                <template #body="{ data }">
                    <span v-if="data.amount_total > 0" class="text-emerald-600 dark:text-emerald-400 font-medium">
                        {{ fmtCurrency(data.amount_total) }}
                    </span>
                    <span v-else class="text-xs text-gray-400">—</span>
                </template>
            </Column>

            <Column header="Método" style="width: 7rem">
                <template #body="{ data }">
                    <span class="text-xs text-gray-500">{{ methodLabel(data.payment_method) }}</span>
                </template>
            </Column>

            <Column header="Tipo" style="width: 5rem">
                <template #body="{ data }">
                    <Tag v-if="data.adjustment_type"
                        :value="adjLabel(data.adjustment_type)"
                        :severity="data.adjustment_type === 'remove' ? 'danger' : 'info'"
                        class="!rounded-full !text-[9px]" />
                    <span v-else class="text-xs text-gray-400">Compra</span>
                </template>
            </Column>

            <Column header="Estado" style="width: 7rem">
                <template #body="{ data }">
                    <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)"
                        class="!rounded-full !text-[9px]" />
                </template>
            </Column>

            <Column header="Admin" style="width: 7rem">
                <template #body="{ data }">
                    <span v-if="data.reviewed_by?.name" class="text-xs text-gray-500">{{ data.reviewed_by.name }}</span>
                    <span v-else-if="data.requested_by?.name" class="text-xs text-gray-400">{{ data.requested_by.name }}</span>
                    <span v-else class="text-xs text-gray-400">—</span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>
