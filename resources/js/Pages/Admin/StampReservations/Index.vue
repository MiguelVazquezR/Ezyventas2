<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    reservations: Object,
});

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-400 py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a] align-top' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } },
};

const dialogPt = {
    root: { class: '!rounded-3xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' },
    header: { class: '!bg-transparent' },
    content: { class: '!bg-transparent' },
};

const inputPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
};

const textareaPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] font-mono !text-xs' },
};

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Timbres', url: route('admin.stamps.index') },
    { label: 'Revisión manual de timbrado' },
]);

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
}

function formatDate(date) {
    if (!date) return '—';
    return new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

function invoiceOf(reservation) {
    return reservation.reference;
}

function lastResponseOf(reservation) {
    return reservation.last_pac_response || {};
}

function prettyJson(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value || '');
    }
}

const expandedRows = ref({});

function toggleRow(id) {
    expandedRows.value = { ...expandedRows.value, [id]: !expandedRows.value[id] };
}

// ──────────────────────────────────────
// Pagination
// ──────────────────────────────────────
const onPage = (event) => {
    router.get(route('admin.stamp-reservations.index'), {
        page: event.page + 1,
        rows: event.rows,
    }, { preserveState: true });
};

// ──────────────────────────────────────
// Confirm dialog
// ──────────────────────────────────────
const confirmDialog = ref(false);
const selectedReservation = ref(null);
const confirmForm = ref({ uuid: '', cfdi_xml: '' });

function openConfirm(reservation) {
    selectedReservation.value = reservation;
    const data = lastResponseOf(reservation);
    confirmForm.value = {
        uuid: data.uuid || (data.data?.uuid) || '',
        cfdi_xml: data.cfdi || (data.data?.cfdi) || '',
    };
    confirmDialog.value = true;
}

function submitConfirm() {
    router.post(
        route('admin.stamp-reservations.confirm', selectedReservation.value.id),
        {
            uuid: confirmForm.value.uuid || null,
            cfdi_xml: confirmForm.value.cfdi_xml || null,
        },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                confirmDialog.value = false;
                selectedReservation.value = null;
            },
        }
    );
}

// ──────────────────────────────────────
// Release dialog
// ──────────────────────────────────────
const releaseDialog = ref(false);
const releaseReservation = ref(null);

function openRelease(reservation) {
    releaseReservation.value = reservation;
    releaseDialog.value = true;
}

function submitRelease() {
    router.post(
        route('admin.stamp-reservations.release', releaseReservation.value.id),
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                releaseDialog.value = false;
                releaseReservation.value = null;
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
                    Revisión manual de timbrado
                </h1>
                <p class="text-sm text-gray-500 mt-1 m-0">
                    {{ reservations.total }} reserva(s) que agotaron los reintentos automáticos y requieren decisión del administrador
                </p>
            </div>

            <!-- ── Reservations Table ────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <DataTable
                    :value="reservations.data"
                    lazy
                    paginator
                    :totalRecords="reservations.total"
                    :rows="reservations.per_page || 25"
                    :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id"
                    rowHover
                    tableStyle="min-width: 70rem"
                    @page="onPage"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} reservas"
                    :pt="dataTablePt"
                >
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                            <i class="pi pi-check-circle !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed">
                                No hay reservas en revisión manual.
                            </p>
                        </div>
                    </template>

                    <Column header="Factura">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ invoiceOf(data)?.series && invoiceOf(data)?.folio
                                        ? `${invoiceOf(data).series}-${invoiceOf(data).folio}`
                                        : `#${invoiceOf(data)?.id ?? '—'}` }}
                                </span>
                                <span class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">
                                    RFC: {{ invoiceOf(data)?.fiscal_profile?.rfc ?? '—' }}
                                </span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Cliente">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-300">
                                {{ invoiceOf(data)?.receiver_legal_name ?? '—' }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Monto">
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg text-gray-900 dark:text-white">
                                {{ formatCurrency(invoiceOf(data)?.total ?? 0) }}
                            </span>
                        </template>
                    </Column>
                    <Column header="CustomID">
                        <template #body="{ data }">
                            <span class="text-xs font-mono text-gray-500 dark:text-gray-400 break-all">{{ data.customid }}</span>
                        </template>
                    </Column>
                    <Column header="Intentos">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ data.attempts }}</span>
                        </template>
                    </Column>
                    <Column header="Última respuesta del PAC">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400 break-all">
                                    {{ lastResponseOf(data).data?.uuid || lastResponseOf(data).uuid || 'Sin UUID' }}
                                </span>
                                <Button
                                    :label="expandedRows[data.id] ? 'Ocultar detalle' : 'Ver detalle'"
                                    severity="secondary"
                                    size="small"
                                    class="!rounded-full !text-[10px] !uppercase !tracking-wider !self-start"
                                    @click="toggleRow(data.id)"
                                />
                                <pre
                                    v-if="expandedRows[data.id]"
                                    class="text-[10px] leading-relaxed text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-xl p-3 m-0 max-h-48 overflow-auto whitespace-pre-wrap"
                                >{{ prettyJson(lastResponseOf(data)) }}</pre>
                            </div>
                        </template>
                    </Column>
                    <Column header="Actualizada">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(data.updated_at) }}</span>
                        </template>
                    </Column>
                    <Column header="Acciones" headerStyle="width: 18rem; text-align: center">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1 justify-center">
                                <Button
                                    icon="pi pi-check"
                                    label="Confirmar timbrado"
                                    severity="success"
                                    size="small"
                                    class="!rounded-full !text-[10px] !uppercase !tracking-wider"
                                    v-tooltip.top="'El admin verificó por fuera que SÍ se timbró'"
                                    @click="openConfirm(data)"
                                />
                                <Button
                                    icon="pi pi-undo"
                                    label="Liberar y descartar"
                                    severity="danger"
                                    size="small"
                                    class="!rounded-full !text-[10px] !uppercase !tracking-wider"
                                    v-tooltip.top="'El admin confirmó que NUNCA se timbró — regresa a borrador'"
                                    @click="openRelease(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <!-- ── Confirm dialog ─────────────────────────── -->
            <Dialog
                v-model:visible="confirmDialog"
                modal
                header="Confirmar timbrado"
                :style="{ width: '30rem' }"
                :pt="dialogPt"
            >
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Confirma que la factura <strong class="text-gray-700 dark:text-gray-200">SÍ fue timbrada</strong>
                        (verifícala en el portal del PAC). Si tienes el UUID y/o el XML, captúralos; si no,
                        se usará la última respuesta guardada.
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">UUID del timbre</label>
                        <InputText
                            v-model="confirmForm.uuid"
                            class="w-full"
                            :pt="inputPt"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">XML del CFDI (opcional)</label>
                        <Textarea
                            v-model="confirmForm.cfdi_xml"
                            rows="4"
                            class="w-full"
                            :pt="textareaPt"
                        />
                    </div>

                    <div class="flex justify-end gap-2 mt-2">
                        <Button
                            label="Cancelar"
                            severity="secondary"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="confirmDialog = false"
                        />
                        <Button
                            label="Confirmar timbrado"
                            severity="success"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="submitConfirm"
                        />
                    </div>
                </div>
            </Dialog>

            <!-- ── Release dialog ─────────────────────────── -->
            <Dialog
                v-model:visible="releaseDialog"
                modal
                header="Liberar y descartar"
                :style="{ width: '28rem' }"
                :pt="dialogPt"
            >
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Vas a confirmar que la factura <strong class="text-gray-700 dark:text-gray-200">NUNCA se timbró</strong>.
                        La reserva se liberará y la factura volverá a <strong>borrador</strong> para que el
                        cliente pueda reintentarla (con un customid nuevo).
                    </p>

                    <div class="flex justify-end gap-2 mt-2">
                        <Button
                            label="Cancelar"
                            severity="secondary"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="releaseDialog = false"
                        />
                        <Button
                            label="Liberar y descartar"
                            severity="danger"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="submitRelease"
                        />
                    </div>
                </div>
            </Dialog>
        </div>
    </AppLayout>
</template>
