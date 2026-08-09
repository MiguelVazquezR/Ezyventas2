<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';
import { useConfirm } from 'primevue/useconfirm';
import CancelInvoiceModal from './Partials/CancelInvoiceModal.vue';

const props = defineProps({
    invoices: Object,
    filters: Object,
    fiscalProfiles: Array,
    hasBillingSettings: Boolean,
    hasFiscalProfiles: Boolean,
});

const { hasPermission } = usePermissions();
const confirm = useConfirm();

// ──────────────────────────────────────
// Search & filters
// ──────────────────────────────────────
const searchTerm = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || null);
const fiscalProfileFilter = ref(props.filters?.fiscal_profile_id || null);

const statusOptions = [
    { label: 'Todos', value: null },
    { label: 'Pre-factura', value: 'Pre-factura' },
    { label: 'Pendiente', value: 'pendiente' },
    { label: 'Timbrada', value: 'Timbrada' },
    { label: 'Cancelación pendiente', value: 'cancelacion_pendiente' },
    { label: 'Cancelada', value: 'cancelada' },
];

// Build fiscal profile dropdown options
const fiscalProfileOptions = computed(() => {
    if (!props.fiscalProfiles) return [];
    return [
        { label: 'Todos los emisores', value: null },
        ...props.fiscalProfiles.map(fp => ({
            label: `${fp.razon_social} (${fp.rfc})`,
            value: fp.id,
        })),
    ];
});

let searchTimeout = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('billing.invoices.index'), {
            search: val || null,
            status: statusFilter.value,
            fiscal_profile_id: fiscalProfileFilter.value,
        }, {
            preserveState: true,
            replace: true,
        });
    }, 400);
});

watch(statusFilter, (val) => {
    router.get(route('billing.invoices.index'), {
        search: searchTerm.value || null,
        status: val,
        fiscal_profile_id: fiscalProfileFilter.value,
    }, {
        preserveState: true,
        replace: true,
    });
});

watch(fiscalProfileFilter, (val) => {
    router.get(route('billing.invoices.index'), {
        search: searchTerm.value || null,
        status: statusFilter.value,
        fiscal_profile_id: val,
    }, {
        preserveState: true,
        replace: true,
    });
});

// ──────────────────────────────────────
// Pagination & sorting
// ──────────────────────────────────────
const onPage = (event) => {
    router.get(route('billing.invoices.index'), {
        page: event.page + 1,
        rows: event.rows,
        search: searchTerm.value || null,
        status: statusFilter.value,
        fiscal_profile_id: fiscalProfileFilter.value,
        sortField: props.filters?.sortField,
        sortOrder: props.filters?.sortOrder,
    }, { preserveState: true });
};

const onSort = (event) => {
    router.get(route('billing.invoices.index'), {
        sortField: event.sortField,
        sortOrder: event.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value || null,
        status: statusFilter.value,
        fiscal_profile_id: fiscalProfileFilter.value,
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
        borrador: 'info',
        pendiente: 'secondary',
        certificada: 'success',
        cancelacion_pendiente: 'warn',
        cancelada: 'danger',
        no_solicitada: 'secondary',
        solicitada: 'info',
        generada: 'success',
    };
    return map[status] || 'secondary';
};

const statusLabel = (status) => {
    const map = {
        borrador: 'Pre-factura',
        pendiente: 'Pendiente',
        certificada: 'Timbrada',
        cancelacion_pendiente: 'Cancelación pendiente',
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
    if (status === 'cancelacion_pendiente') return 'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.6)] animate-pulse';
    return 'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.6)]';
};

// CFDI 4.0 TipoDeComprobante badge
const tipoComprobanteLabel = (type) => {
    const map = { I: 'Ingreso', E: 'Egreso', P: 'Pago', T: 'Traslado', N: 'Nómina' };
    return map[type] || type || '—';
};

const tipoComprobanteSeverity = (type) => {
    const map = { I: 'info', E: 'warn', P: 'success', T: 'secondary', N: 'danger' };
    return map[type] || 'secondary';
};

const rowClass = (data) => {
    if (data.status === 'cancelada') return 'opacity-60';
    if (data.status === 'cancelacion_pendiente') return 'bg-amber-50/30 dark:bg-amber-900/10';
    return '';
};

// ──────────────────────────────────────
// Safe external URL opener
// ──────────────────────────────────────
const openUrl = (url) => { if (url) window?.open(url, '_blank'); };

// ──────────────────────────────────────
// Inline file download (same tab, no new window)
// ──────────────────────────────────────
const downloadFile = (url) => {
    const a = document.createElement('a');
    a.href = url;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
};

// ──────────────────────────────────────
// More dropdown menu
// ──────────────────────────────────────
const menuRef = ref(null);
const selectedInvoice = ref(null);
const items = ref([]);
const cancelModalRef = ref(null);

const toggleMenu = (event, invoice) => {
    selectedInvoice.value = invoice;

    const isDraft = invoice.status === 'borrador' || invoice.status === 'pendiente';
    const isCertified = invoice.status === 'certificada';

    const options = [
        {
            label: 'Ver detalle',
            icon: 'pi pi-eye',
            command: () => router.get(route('billing.invoices.show', invoice.id)),
        },
        {
            label: 'Ver PDF',
            icon: 'pi pi-file-pdf',
            command: () => window?.open(route('billing.invoices.pdf', invoice.id), '_blank'),
        },
    ];

    if (isDraft) {
        options.push({
            label: 'Editar prefactura',
            icon: 'pi pi-pencil',
            command: () => router.get(route('billing.invoices.edit', invoice.id)),
        });
        options.push({
            label: 'Timbrar factura',
            icon: 'pi pi-check-circle',
            command: () => {
                confirm.require({
                    message: '¿Timbrar factura? La factura se registrará y validará ante el SAT.',
                    header: 'Timbrar factura',
                    icon: 'pi pi-shield',
                    acceptLabel: 'Timbrar',
                    rejectLabel: 'Cancelar',
                    rejectClass: 'p-button-outlined',
                    accept: () => router.post(route('billing.invoices.stamp', invoice.id)),
                });
            },
        });
        options.push({
            label: 'Eliminar prefactura',
            icon: 'pi pi-trash',
            command: () => {
                confirm.require({
                    message: '¿Eliminar esta prefactura? Esta acción no se puede deshacer.',
                    header: 'Eliminar prefactura',
                    icon: 'pi pi-exclamation-triangle',
                    acceptLabel: 'Eliminar',
                    rejectLabel: 'Cancelar',
                    acceptClass: 'p-button-danger',
                    accept: () => router.delete(route('billing.invoices.destroy', invoice.id)),
                });
            },
        });
    }

    if (isCertified) {
        options.push({
            label: 'Solicitar cancelación',
            icon: 'pi pi-times-circle',
            command: () => cancelModalRef.value?.open(),
        });
    }

    items.value = options;
    menuRef.value?.toggle(event);
};

// ──────────────────────────────────────
// Row click → navigate to detail
// ──────────────────────────────────────
const onRowClick = (event) => {
    router.get(route('billing.invoices.show', event.data.id));
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
                            Lista de facturas
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                            CFDI 4.0 &middot; Historial de facturas
                        </p>
                    </div>

                    <!-- Header actions -->
                    <div class="flex items-center gap-3 shrink-0">
                        <Button
                            label="Configuración fiscal"
                            icon="pi pi-cog"
                            outlined
                            severity="secondary"
                            @click="router.get(route('billing.settings.index'))"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider !bg-transparent !border-gray-300 dark:!border-[#3a3a3a] !text-gray-500 dark:!text-gray-400 hover:!bg-gray-50 dark:hover:!bg-[#1a1a1a]"
                        />
                        <Button
                            v-if="hasPermission('invoices.create')"
                            label="Emitir factura"
                            icon="pi pi-plus"
                            @click="router.get(route('billing.invoices.create'))"
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
                        <!-- Fiscal profile filter (hidden if only one profile) -->
                        <Select
                            v-if="fiscalProfiles && fiscalProfiles.length > 1"
                            v-model="fiscalProfileFilter"
                            :options="fiscalProfileOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Emisor fiscal"
                            class="w-full md:w-56"
                            :pt="selectPt"
                        />
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

                    <!-- Tipo de comprobante -->
                    <Column field="tipo_comprobante" header="Tipo" headerStyle="width: 9rem">
                        <template #body="{ data }">
                            <Tag
                                :value="tipoComprobanteLabel(data.tipo_comprobante)"
                                :severity="tipoComprobanteSeverity(data.tipo_comprobante)"
                                :pt="tagPt"
                            />
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

                    <!-- Emisor (only when multiple fiscal profiles exist) -->
                    <Column v-if="fiscalProfiles && fiscalProfiles.length > 1" field="fiscal_profile_id" header="Emisor">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ data.fiscal_profile?.razon_social }}
                                </span>
                                <span class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">
                                    {{ data.fiscal_profile?.rfc }}
                                </span>
                            </div>
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
                    <Column headerStyle="width: 10rem; text-align: center">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1 justify-center" @click.stop>
                                <Button
                                    icon="pi pi-file-pdf"
                                    text
                                    rounded
                                    @click.stop="openUrl(route('billing.invoices.pdf', data.id))"
                                    class="!w-8 !h-8 !text-red-500 hover:!bg-red-50 dark:hover:!bg-red-900/20 !transition-colors"
                                    v-tooltip.top="'Ver PDF'"
                                />
                                <Button
                                    v-if="data.xml_url"
                                    icon="pi pi-download"
                                    text
                                    rounded
                                    @click.stop="downloadFile(route('billing.invoices.xml', data.id))"
                                    class="!w-8 !h-8 !text-green-600 hover:!bg-green-50 dark:hover:!bg-green-900/20 !transition-colors"
                                    v-tooltip.top="'Descargar XML'"
                                />
                                <span v-else class="w-8 h-8 shrink-0"></span>
                                <Button
                                    icon="pi pi-ellipsis-v"
                                    text
                                    rounded
                                    @click.stop="toggleMenu($event, data)"
                                    class="!w-8 !h-8 !text-gray-500 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors"
                                    v-tooltip.top="'Más acciones'"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>

        <!-- More actions dropdown -->
        <Menu ref="menuRef" :model="items" :popup="true" />

        <CancelInvoiceModal
            v-if="selectedInvoice"
            ref="cancelModalRef"
            :invoice="selectedInvoice"
            @success="router.reload()"
        />

    </AppLayout>
</template>
