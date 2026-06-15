<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    invoices: Object,
    filters: Object,
    hasBillingSettings: Boolean,
});

const { hasPermission } = usePermissions();

// ──────────────────────────────────────
// Search & filters
// ──────────────────────────────────────
const searchTerm = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || null);

const statusOptions = [
    { label: 'Todos', value: null },
    { label: 'Borrador', value: 'borrador' },
    { label: 'Pendiente', value: 'pendiente' },
    { label: 'Certificada', value: 'certificada' },
    { label: 'Cancelada', value: 'cancelada' },
];

let searchTimeout = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('invoices.index'), { search: val || null, status: statusFilter.value }, {
            preserveState: true,
            replace: true,
        });
    }, 400);
});

watch(statusFilter, (val) => {
    router.get(route('invoices.index'), { search: searchTerm.value || null, status: val }, {
        preserveState: true,
        replace: true,
    });
});

// ──────────────────────────────────────
// Pagination & sorting
// ──────────────────────────────────────
const onPage = (event) => {
    router.get(route('invoices.index'), {
        page: event.page + 1,
        rows: event.rows,
        search: searchTerm.value || null,
        status: statusFilter.value,
        sortField: props.filters?.sortField,
        sortOrder: props.filters?.sortOrder,
    }, { preserveState: true });
};

const onSort = (event) => {
    router.get(route('invoices.index'), {
        sortField: event.sortField,
        sortOrder: event.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value || null,
        status: statusFilter.value,
    }, { preserveState: true });
};

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const truncateUuid = (uuid) => {
    if (!uuid) return '—';
    return uuid.length > 16 ? uuid.slice(0, 8) + '...' + uuid.slice(-8) : uuid;
};

const statusSeverity = (status) => {
    const map = {
        borrador: 'warn',
        pendiente: 'warn',
        certificada: 'success',
        cancelada: 'danger',
        no_solicitada: 'secondary',
        solicitada: 'info',
        generada: 'success',
    };
    return map[status] || 'secondary';
};

const statusLabel = (status) => {
    const map = {
        borrador: 'Borrador',
        pendiente: 'Pendiente',
        certificada: 'Certificada',
        cancelada: 'Cancelada',
        no_solicitada: 'No solicitada',
        solicitada: 'Solicitada',
        generada: 'Generada',
    };
    return map[status] || status;
};

const statusDotClass = (status) => {
    if (status === 'certificada' || status === 'generada') return 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.6)]';
    if (status === 'cancelada') return 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.6)]';
    return 'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.6)]';
};

const rowClass = (data) => {
    if (data.status === 'cancelada') return 'opacity-60';
    return '';
};

// ──────────────────────────────────────
// Row click → navigate to detail
// ──────────────────────────────────────
const onRowClick = (event) => {
    router.get(route('invoices.show', event.data.id));
};

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-400 py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } },
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' },
};

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' },
};
</script>

<template>
    <Head title="Facturación" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- ════════════════════════════════════════
                 Main panel
                 ════════════════════════════════════════ -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">

                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                            Facturación
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                            CFDI 4.0 &middot; Historial de comprobantes fiscales
                        </p>
                    </div>

                    <!-- Header actions -->
                    <div class="flex items-center gap-3 shrink-0">
                        <Button
                            label="Configuración fiscal"
                            icon="pi pi-cog"
                            outlined
                            severity="secondary"
                            @click="router.get(route('invoices.settings'))"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider !bg-transparent !border-gray-300 dark:!border-[#3a3a3a] !text-gray-500 dark:!text-gray-400 hover:!bg-gray-50 dark:hover:!bg-[#1a1a1a]"
                        />
                        <Button
                            v-if="hasPermission('create invoices')"
                            label="Emitir factura"
                            icon="pi pi-plus"
                            @click="router.get(route('invoices.create'))"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider"
                        />
                    </div>
                </div>

                <!-- Filter bar -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <!-- Search -->
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500" />
                        <InputText
                            v-model="searchTerm"
                            placeholder="Buscar por folio, razón social, RFC o UUID..."
                            :pt="inputPt"
                            class="!pl-10"
                        />
                    </IconField>

                    <div class="flex items-center gap-3 w-full md:w-auto">
                        <!-- Status filter -->
                        <Select
                            v-model="statusFilter"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Filtrar por estado"
                            class="w-full md:w-48"
                            :pt="selectPt"
                        />
                    </div>
                </div>

                <!-- ════════════════════════════════════════
                     DataTable
                     ════════════════════════════════════════ -->
                <DataTable
                    :value="invoices.data"
                    lazy
                    paginator
                    :totalRecords="invoices.total"
                    :rows="invoices.per_page"
                    :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id"
                    @page="onPage"
                    @sort="onSort"
                    @row-click="onRowClick"
                    removableSort
                    tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} facturas"
                    rowHover
                    :rowClass="rowClass"
                    :pt="dataTablePt"
                >
                    <!-- Empty state -->
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                            <i class="pi pi-receipt !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed">
                                No se encontraron facturas emitidas. Asegúrate de tener configurada tu información fiscal antes de generar un nuevo comprobante.
                            </p>
                        </div>
                    </template>
                    <!-- Folio + Series -->
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <span
                                    class="w-2 h-2 rounded-full animate-pulse shrink-0"
                                    :class="statusDotClass(data.status)"
                                ></span>
                                <div class="flex flex-col">
                                    <span class="font-mono font-bold text-gray-900 dark:text-gray-100">
                                        {{ data.series ? data.series + ' ' : '' }}{{ data.folio }}
                                    </span>
                                    <span v-if="data.series" class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">
                                        Serie {{ data.series }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Cliente (Razón Social) -->
                    <Column field="receiver_legal_name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ data.receiver_legal_name }}
                                </span>
                                <span v-if="data.customer" class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">
                                    {{ data.customer.name || data.customer.company_name }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- RFC -->
                    <Column field="receiver_rfc" header="RFC" sortable>
                        <template #body="{ data }">
                            <span class="font-mono text-xs text-gray-600 dark:text-gray-400 tracking-wide">
                                {{ data.receiver_rfc }}
                            </span>
                        </template>
                    </Column>

                    <!-- UUID -->
                    <Column field="uuid" header="UUID">
                        <template #body="{ data }">
                            <span
                                v-if="data.uuid"
                                class="font-mono text-xs text-gray-500 dark:text-gray-400 cursor-copy"
                                :title="data.uuid"
                                v-tooltip.bottom="{ value: data.uuid, class: '!text-[10px] !font-mono' }"
                            >
                                {{ truncateUuid(data.uuid) }}
                            </span>
                            <span v-else class="text-gray-300 dark:text-gray-600 text-xs italic">
                                Pendiente de timbrado
                            </span>
                        </template>
                    </Column>

                    <!-- Total -->
                    <Column field="total" header="Total" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg text-gray-900 dark:text-white">
                                {{ formatCurrency(data.total) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Status badge -->
                    <Column field="status" header="Estado" sortable>
                        <template #body="{ data }">
                            <Tag
                                :value="statusLabel(data.status)"
                                :severity="statusSeverity(data.status)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button
                                v-if="hasPermission('invoices.see_details')"
                                icon="pi pi-arrow-right"
                                text
                                rounded
                                @click="router.get(route('invoices.show', data.id))"
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors"
                                v-tooltip.top="'Ver detalle'"
                            />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
