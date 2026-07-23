<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchases: Object,
});

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

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' },
};

const dialogPt = {
    root: { class: '!rounded-3xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' },
    header: { class: '!bg-transparent' },
    content: { class: '!bg-transparent' },
};

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Timbres', url: route('admin.stamps.index') },
    { label: 'Bandeja de revisión' },
]);

// ──────────────────────────────────────
// Review reason helpers
// ──────────────────────────────────────
function reviewReasonLabel(reason) {
    const labels = {
        'bank_transfer': 'Transferencia',
        'large_quantity': 'Monto grande',
    };
    return labels[reason] || reason || '—';
}

function reviewReasonSeverity(reason) {
    const map = {
        'bank_transfer': 'info',
        'large_quantity': 'warn',
    };
    return map[reason] || 'secondary';
}

function paymentMethodLabel(method) {
    const labels = {
        'mercadopago': 'Mercado Pago',
        'bank_transfer': 'Transferencia',
        'manual_adjustment': 'Ajuste manual',
    };
    return labels[method] || method;
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
}

function formatDate(date) {
    return new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

// ──────────────────────────────────────
// Pagination & sorting
// ──────────────────────────────────────
const sortField = ref(props.purchases?.sort_field || 'created_at');
const sortOrder = ref(props.purchases?.sort_order || 'desc');

const onPage = (event) => {
    router.get(route('admin.stamps.index'), {
        page: event.page + 1,
        rows: event.rows,
        sortField: sortField.value,
        sortOrder: sortOrder.value,
    }, { preserveState: true });
};

const onSort = (event) => {
    sortField.value = event.sortField;
    sortOrder.value = event.sortOrder === 1 ? 'asc' : 'desc';

    router.get(route('admin.stamps.index'), {
        sortField: sortField.value,
        sortOrder: sortOrder.value,
    }, { preserveState: true });
};

// ──────────────────────────────────────
// Approve / Reject
// ──────────────────────────────────────
const rejectReason = ref('');
const showRejectDialog = ref(false);
const selectedPurchase = ref(null);

function approve(purchase) {
    router.post(route('admin.stamps.approve', purchase.id), {}, {
        preserveScroll: true,
        preserveState: false,
    });
}

function openRejectDialog(purchase) {
    selectedPurchase.value = purchase;
    rejectReason.value = '';
    showRejectDialog.value = true;
}

function confirmReject() {
    if (!rejectReason.value.trim()) return;

    router.post(
        route('admin.stamps.reject', selectedPurchase.value.id),
        { rejection_reason: rejectReason.value },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                showRejectDialog.value = false;
                selectedPurchase.value = null;
                rejectReason.value = '';
            },
        }
    );
}
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-6xl mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    Bandeja de revisión
                </h1>
                <p class="text-sm text-gray-500 mt-1 m-0">
                    {{ purchases.total }} compra(s) esperando revisión
                </p>
            </div>

            <!-- ── Purchases Table ──────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <DataTable
                    :value="purchases.data"
                    lazy
                    paginator
                    :totalRecords="purchases.total"
                    :rows="purchases.per_page || 25"
                    :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id"
                    removableSort
                    rowHover
                    tableStyle="min-width: 60rem"
                    @page="onPage"
                    @sort="onSort"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} compras"
                    :pt="dataTablePt"
                >
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                            <i class="pi pi-check-circle !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed">
                                No hay compras pendientes de revisión.
                            </p>
                        </div>
                    </template>

                    <Column header="Tipo">
                        <template #body="{ data }">
                            <Tag
                                :value="reviewReasonLabel(data.review_reason)"
                                :severity="reviewReasonSeverity(data.review_reason)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>
                    <Column header="Suscriptor">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <Link
                                    :href="route('admin.subscriptions.show', data.fiscal_profile?.subscription_id)"
                                    class="font-medium text-primary-500 hover:underline text-sm"
                                >
                                    {{ data.fiscal_profile?.subscription?.commercial_name ?? '—' }}
                                </Link>
                                <span class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">ID: {{ data.fiscal_profile?.subscription_id }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Perfil fiscal">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.fiscal_profile?.razon_social ?? '—' }}</span>
                                <span class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">RFC: {{ data.fiscal_profile?.rfc }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="stamp_quantity" header="Timbres" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg text-gray-900 dark:text-white">
                                {{ data.stamp_quantity.toLocaleString() }}
                            </span>
                        </template>
                    </Column>
                    <Column field="amount_total" header="Monto" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg text-gray-900 dark:text-white">
                                {{ formatCurrency(data.amount_total) }}
                            </span>
                        </template>
                    </Column>
                    <Column field="created_at" header="Fecha" sortable>
                        <template #body="{ data }">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(data.created_at) }}</span>
                        </template>
                    </Column>
                    <Column header="Acciones" headerStyle="width: 12rem; text-align: center">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1 justify-center">
                                <!-- Bank transfer: show proof + approve/reject -->
                                <template v-if="data.review_reason === 'bank_transfer'">
                                    <Button
                                        v-if="data.proof_file_path"
                                        as="a"
                                        :href="`/storage/${data.proof_file_path}`"
                                        target="_blank"
                                        icon="pi pi-eye"
                                        severity="secondary"
                                        size="small"
                                        class="!rounded-full !w-8 !h-8"
                                        v-tooltip.top="'Ver comprobante'"
                                    />
                                    <Button
                                        icon="pi pi-check"
                                        severity="success"
                                        size="small"
                                        class="!rounded-full !w-8 !h-8"
                                        v-tooltip.top="'Aprobar'"
                                        @click="approve(data)"
                                    />
                                    <Button
                                        icon="pi pi-times"
                                        severity="danger"
                                        size="small"
                                        class="!rounded-full !w-8 !h-8"
                                        v-tooltip.top="'Rechazar'"
                                        @click="openRejectDialog(data)"
                                    />
                                </template>

                                <!-- Large quantity (MercadoPago): apply now only -->
                                <template v-else>
                                    <Button
                                        icon="pi pi-check"
                                        label="Aplicar ahora"
                                        severity="success"
                                        size="small"
                                        class="!rounded-full !text-[10px] !uppercase !tracking-wider"
                                        @click="approve(data)"
                                    />
                                </template>
                            </div>
                        </template>
                    </Column>
                </DataTable>

            </div>

        </div>

        <!-- ── Reject Dialog ────────────────────────────── -->
        <Dialog
            v-model:visible="showRejectDialog"
            header="Rechazar comprobante"
            :modal="true"
            class="w-full max-w-md"
            :pt="dialogPt"
        >
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Motivo del rechazo *</label>
                <Textarea
                    v-model="rejectReason"
                    rows="3"
                    class="w-full"
                    placeholder="Explica por qué se rechaza este comprobante..."
                    :pt="{
                        root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' }
                    }"
                />
            </div>

            <template #footer>
                <Button label="Cancelar" severity="secondary" class="!rounded-full" @click="showRejectDialog = false" />
                <Button label="Rechazar" severity="danger" class="!rounded-full" :disabled="!rejectReason.trim()" @click="confirmReject" />
            </template>
        </Dialog>
    </AppLayout>
</template>