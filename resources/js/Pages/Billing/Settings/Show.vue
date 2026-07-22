<script setup>
import { ref } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LogoUploadModal from '@/Pages/Billing/Settings/Partials/LogoUploadModal.vue';
import PurchaseStampsModal from '@/Pages/Billing/Settings/Partials/PurchaseStampsModal.vue';
import ManifestWizardModal from '@/Pages/Billing/Settings/Partials/ManifestWizardModal.vue';

const props = defineProps({
    fiscalProfile: Object,
    balance: [Object, null],
    balanceError: [String, null],
    invoiceStats: Object,
    purchases: Object,
    ourBankAccounts: Array,
    canPurchaseStamps: Boolean,
    canRetryManifestSigning: Boolean,
});

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Facturación', url: route('billing.settings.index') },
    { label: props.fiscalProfile.razon_social },
]);

// ──────────────────────────────────────
// Logo Modal
// ──────────────────────────────────────
const showLogoModal = ref(false);

const onLogoUpdated = () => {
    router.reload();
};

// ──────────────────────────────────────
// CSD Dialog state
// ──────────────────────────────────────
const showCsdDialog = ref(false);
const isUpdatingCsd = ref(false);

const csdForm = useForm({
    fiscal_profile_id: props.fiscalProfile.id,
    cer: null,
    key: null,
    password: '',
});

const openCsdDialog = () => {
    csdForm.reset();
    csdForm.clearErrors();
    csdForm.fiscal_profile_id = props.fiscalProfile.id;
    isUpdatingCsd.value = !props.fiscalProfile.certificate_number;
    showCsdDialog.value = true;
};

const submitCsd = () => {
    csdForm.post(route('billing.settings.uploadCsd'), {
        onSuccess: () => {
            showCsdDialog.value = false;
            csdForm.reset();
            router.reload();
        },
    });
};

// ──────────────────────────────────────
// Reusable modal refs
// ──────────────────────────────────────
const purchaseModalRef = ref(null);
const manifestModalRef = ref(null);

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function statusLabel(status, reviewReason) {
    // For MercadoPago purchases awaiting admin review, show a subscriber-friendly label
    if (status === 'awaiting_review' && reviewReason === 'large_quantity') {
        return 'Pago confirmado, aplicando timbres';
    }
    const labels = {
        pending: 'Pendiente',
        awaiting_review: 'En revisión',
        approved: 'Aprobado',
        rejected: 'Rechazado',
        failed: 'Fallido',
        stamps_applied: 'Acreditado',
    };
    return labels[status] || status;
}

function statusSeverity(status, reviewReason) {
    // Large quantity awaiting review: use success-like severity (payment confirmed)
    if (status === 'awaiting_review' && reviewReason === 'large_quantity') {
        return 'info';
    }
    const map = {
        pending: 'warn',
        awaiting_review: 'info',
        approved: 'success',
        rejected: 'danger',
        failed: 'danger',
        stamps_applied: 'success',
    };
    return map[status] || 'secondary';
}

function paymentMethodLabel(method) {
    const labels = {
        mercadopago: 'Mercado Pago',
        bank_transfer: 'Transferencia',
        manual_adjustment: 'Ajuste manual',
    };
    return labels[method] || method;
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-MX').format(value || 0);
}

function formatDate(date) {
    if (!date) return '—';
    return new Date(date).toLocaleDateString('es-MX', { dateStyle: 'long' });
}

// ──────────────────────────────────────
// Tesla UI PT configs
// ──────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#1a1a1a] !transition-colors !rounded-full !w-8 !h-8 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-5xl mx-auto space-y-6">

            <!-- ═══════════════════════════════ HEADER: Profile identity ═══════════════════════════════ -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex flex-col sm:flex-row items-start gap-6">
                    <button
                        @click="showLogoModal = true"
                        class="shrink-0 w-20 h-20 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-center overflow-hidden hover:border-primary-300 dark:hover:border-primary-600 transition-colors cursor-pointer group relative"
                        v-tooltip.top="fiscalProfile.logo_url ? 'Click para cambiar el logotipo de facturación' : 'Click para subir un logotipo de facturación'"
                    >
                        <img v-if="fiscalProfile.logo_url" :src="fiscalProfile.logo_url" alt="Logotipo" class="w-full h-full object-contain p-2" />
                        <div v-else class="flex flex-col items-center gap-1 text-gray-300 dark:text-gray-600">
                            <i class="pi pi-image !text-xl" />
                            <span class="text-[8px] uppercase tracking-widest font-bold">Logo</span>
                        </div>
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center rounded-2xl">
                            <i class="pi pi-pencil !text-white !text-lg"></i>
                        </div>
                    </button>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                                    {{ fiscalProfile.razon_social }}
                                </h1>
                                <p class="text-sm text-gray-500 mt-1 m-0">
                                    RFC: {{ fiscalProfile.rfc }}
                                    <span class="mx-2 text-gray-300 dark:text-gray-600">·</span>
                                    Régimen: {{ fiscalProfile.regimen_fiscal }}
                                </p>
                                <p class="text-xs text-gray-400 mt-1 m-0">
                                    Registrado el {{ formatDate(fiscalProfile.created_at) }}
                                    <span v-if="fiscalProfile.postal_code" class="ml-2">· CP {{ fiscalProfile.postal_code }}</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <Button
                                    v-if="fiscalProfile.sw_user_id"
                                    icon="pi pi-key"
                                    :label="fiscalProfile.certificate_number ? 'Certificado activo' : 'Configurar CSD'"
                                    :severity="fiscalProfile.certificate_number ? 'success' : 'secondary'"
                                    outlined
                                    size="small"
                                    class="!rounded-full"
                                    @click="openCsdDialog"
                                    v-tooltip.top="fiscalProfile.certificate_number ? 'Ver o actualizar certificado CSD' : 'Cargar certificados CSD (.cer y .key)'"
                                />
                                <Button
                                    v-if="canPurchaseStamps && fiscalProfile.is_active"
                                    icon="pi pi-plus"
                                    label="Comprar timbres"
                                    class="!rounded-full"
                                    @click="purchaseModalRef?.open()"
                                />
                            </div>
                        </div>
                        <div v-if="fiscalProfile.certificate_number" class="mt-4 flex items-center gap-2 text-xs text-gray-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                            <span>Nº serie: <span class="font-mono text-gray-700 dark:text-gray-300">{{ fiscalProfile.certificate_number }}</span></span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span>{{ fiscalProfile.valid_from }} — {{ fiscalProfile.valid_to }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════ MANIFEST SAT ═══════════════════════════════ -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Manifiesto SAT</h2>

                <div v-if="fiscalProfile.manifest_signed_at" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                            <i class="pi pi-check-circle !text-sm text-green-500"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-900 dark:text-white m-0">Manifiesto firmado</p>
                            <p class="text-xs text-gray-400 m-0">{{ new Date(fiscalProfile.manifest_signed_at).toLocaleDateString('es-MX', { dateStyle: 'long' }) }}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            as="a"
                            :href="route('billing.fiscal-profiles.manifest.download', fiscalProfile.id)"
                            icon="pi pi-download"
                            label="Descargar PDF"
                            severity="secondary"
                            class="!rounded-full"
                        />
                        <Button
                            label="Volver a firmar"
                            icon="pi pi-refresh"
                            severity="secondary"
                            outlined
                            size="small"
                            class="!rounded-full"
                            @click="manifestModalRef?.open()"
                        />
                    </div>
                </div>

                <div v-else>
                    <div class="flex items-start gap-3 mb-4 p-4 rounded-2xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/30">
                        <i class="pi pi-exclamation-triangle text-amber-500 !text-sm mt-0.5" />
                        <div>
                            <p class="text-sm text-amber-700 dark:text-amber-300 m-0">
                                Falta firmar el Manifiesto ante el SAT. Este trámite es obligatorio para poder timbrar y no tiene costo. Solo toma un minuto.
                            </p>
                            <p v-if="fiscalProfile.manifest_last_attempt_error" class="text-xs text-red-500 mt-2 m-0">
                                Último error: {{ fiscalProfile.manifest_last_attempt_error }}
                            </p>
                        </div>
                    </div>
                    <Button
                        label="Firmar manifiesto"
                        icon="pi pi-pen-to-square"
                        class="!rounded-full"
                        @click="manifestModalRef?.open()"
                    />
                </div>
            </div>

            <!-- ═══════════════════════════════ STAMP BALANCE (live) ═══════════════════════════════ -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Saldo de timbres</h2>
                    <Link
                        v-if="$page.props.auth.user?.is_super_admin"
                        :href="route('admin.stamps.index')"
                        class="text-[10px] uppercase tracking-widest font-bold text-gray-400 hover:text-primary-500 transition-colors"
                        v-tooltip.top="'Estos precios aplican a todos los suscriptores, no solo a este.'"
                    >
                        Gestionar timbres y precios
                        <i class="pi pi-arrow-up-right !text-[8px] ml-1" />
                    </Link>
                </div>

                <div v-if="balanceError" class="flex items-center gap-3 text-red-500">
                    <i class="pi pi-exclamation-triangle" />
                    <span class="text-sm">{{ balanceError }}</span>
                    <Button label="Reintentar" size="small" severity="secondary" class="!rounded-full" @click="router.reload()" />
                </div>
                <div v-else-if="!balance && fiscalProfile.sw_user_id" class="flex items-center gap-3 text-gray-400">
                    <i class="pi pi-spin pi-spinner" />
                    <span class="text-sm">Consultando saldo...</span>
                </div>
                <div v-else-if="!fiscalProfile.sw_user_id" class="text-sm text-gray-500">
                    Este emisor aún no tiene una subcuenta PAC vinculada.
                </div>
                <div v-else class="grid grid-cols-2 sm:grid-cols-4 gap-6">
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Disponibles</span>
                        <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">{{ balance?.stampsBalance ?? '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Usados</span>
                        <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">{{ balance?.stampsUsed ?? '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Asignados</span>
                        <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">{{ balance?.stampsAssigned ?? '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Ilimitado</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ balance?.isUnlimited ? 'Sí' : 'No' }}</span>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════ INVOICE KPIs ═══════════════════════════════ -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                            <i class="pi pi-receipt !text-xs text-blue-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Comprobantes</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(invoiceStats.totalInvoices) }}</span>
                </div>
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                            <i class="pi pi-check-circle !text-xs text-emerald-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Certificadas</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-emerald-600 dark:text-emerald-400">{{ formatNumber(invoiceStats.certifiedCount) }}</span>
                </div>
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                            <i class="pi pi-times-circle !text-xs text-red-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Canceladas</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-red-500">{{ formatNumber(invoiceStats.canceledCount) }}</span>
                </div>
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                            <i class="pi pi-dollar !text-xs text-amber-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Facturado</span>
                    </div>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">{{ formatCurrency(invoiceStats.totalAmount) }}</span>
                </div>
            </div>

            <!-- ═══════════════════════════════ PURCHASE HISTORY ═══════════════════════════════ -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Historial de timbres</h2>
                <DataTable
                    :value="purchases.data"
                    :paginator="purchases.total > 15"
                    :rows="15"
                    :totalRecords="purchases.total"
                    stripedRows
                    class="w-full"
                    :pt="{ root: { class: '!bg-transparent' }, headerRow: { class: '!bg-transparent' } }"
                >
                    <Column field="created_at" header="Fecha">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ new Date(data.created_at).toLocaleDateString('es-MX', { dateStyle: 'medium' }) }}
                            </span>
                        </template>
                    </Column>
                    <Column field="stamp_quantity" header="Cantidad">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.stamp_quantity.toLocaleString() }}</span>
                        </template>
                    </Column>
                    <Column field="amount_total" header="Total">
                        <template #body="{ data }">
                            <span v-if="data.amount_total > 0" class="text-sm">{{ formatCurrency(data.amount_total) }}</span>
                            <span v-else class="text-sm text-gray-400">—</span>
                        </template>
                    </Column>
                    <Column field="payment_method" header="Método">
                        <template #body="{ data }">
                            <span class="text-sm">{{ paymentMethodLabel(data.payment_method) }}</span>
                        </template>
                    </Column>
                    <Column field="status" header="Estado">
                        <template #body="{ data }">
                            <Tag :value="statusLabel(data.status, data.review_reason)" :severity="statusSeverity(data.status, data.review_reason)" class="!rounded-full" />
                        </template>
                    </Column>
                </DataTable>
                <div v-if="purchases.data.length === 0" class="text-center py-8 text-sm text-gray-400">
                    No hay compras registradas para este emisor fiscal.
                </div>
            </div>

            <div class="rounded-2xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 p-4">
                <div class="flex items-start gap-3">
                    <i class="pi pi-info-circle text-blue-500 !text-sm mt-0.5" />
                    <p class="text-sm text-blue-700 dark:text-blue-300 m-0">
                        Cancelar una factura no aumenta ni descuenta tu saldo de timbres. El PAC solo consume un timbre al timbrar, no al cancelar.
                    </p>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <LogoUploadModal
            :profile="fiscalProfile"
            :visible="showLogoModal"
            @update:visible="showLogoModal = $event"
            @updated="onLogoUpdated"
        />

        <PurchaseStampsModal
            ref="purchaseModalRef"
            :fiscalProfileId="fiscalProfile.id"
            :ourBankAccounts="ourBankAccounts"
            @success="router.reload()"
        />

        <ManifestWizardModal
            ref="manifestModalRef"
            :fiscalProfile="fiscalProfile"
            :canRetryManifestSigning="canRetryManifestSigning"
            @success="router.reload()"
        />

        <!-- ════════════════ CSD DIALOG ════════════════ -->
        <Dialog
            v-model:visible="showCsdDialog"
            modal
            class="w-full max-w-lg mx-4"
            :pt="dialogPt"
            @hide="isUpdatingCsd = false"
        >
            <template #header>
                <div class="flex items-center gap-4">
                    <div
                        class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 border"
                        :class="fiscalProfile.certificate_number && !isUpdatingCsd
                            ? 'bg-green-50 dark:bg-green-900/20 text-green-500 border-green-100 dark:border-green-900/30'
                            : 'bg-amber-50 dark:bg-amber-900/20 text-amber-500 border-amber-100 dark:border-amber-900/30'"
                    >
                        <i class="pi text-sm" :class="fiscalProfile.certificate_number && !isUpdatingCsd ? 'pi-check-circle' : 'pi-key'"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                            {{ fiscalProfile.certificate_number && !isUpdatingCsd ? 'Certificado activo' : 'Cargar Certificado (CSD)' }}
                        </h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                            {{ fiscalProfile.certificate_number && !isUpdatingCsd ? 'Datos del CSD registrado' : 'Archivos .cer y .key del SAT' }}
                        </p>
                    </div>
                </div>
            </template>

            <div v-if="fiscalProfile.certificate_number && !isUpdatingCsd" class="flex flex-col gap-5 pt-2">
                <div class="flex items-center gap-3 mb-2">
                    <Tag value="Certificado activo" severity="success" :pt="tagPt" />
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                </div>
                <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 space-y-4 border border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Número de serie</span>
                        <span class="text-sm font-mono text-gray-900 dark:text-white break-all">{{ fiscalProfile.certificate_number }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Vigencia</span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ fiscalProfile.valid_from }} — {{ fiscalProfile.valid_to }}</span>
                    </div>
                </div>
            </div>

            <form v-if="!fiscalProfile.certificate_number || isUpdatingCsd" @submit.prevent="submitCsd" class="flex flex-col gap-5 pt-2">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Certificado (.cer) *</label>
                    <input type="file" accept=".cer" @input="csdForm.cer = $event.target.files[0]"
                        class="w-full text-sm text-gray-600 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-[10px] file:uppercase file:tracking-widest file:font-bold file:bg-gray-100 dark:file:bg-[#1a1a1a] file:text-gray-700 dark:file:text-gray-300 hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20 file:transition-colors file:cursor-pointer" />
                    <Message v-if="csdForm.errors.cer" severity="error" variant="simple" size="small">{{ csdForm.errors.cer }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Llave privada (.key) *</label>
                    <input type="file" accept=".key" @input="csdForm.key = $event.target.files[0]"
                        class="w-full text-sm text-gray-600 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-[10px] file:uppercase file:tracking-widest file:font-bold file:bg-gray-100 dark:file:bg-[#1a1a1a] file:text-gray-700 dark:file:text-gray-300 hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20 file:transition-colors file:cursor-pointer" />
                    <Message v-if="csdForm.errors.key" severity="error" variant="simple" size="small">{{ csdForm.errors.key }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Contraseña del CSD *</label>
                    <Password v-model="csdForm.password" placeholder="Contraseña de la llave privada" :feedback="false" toggleMask class="w-full"
                        :pt="{ root: { class: '!w-full' }, input: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }" />
                    <Message v-if="csdForm.errors.password" severity="error" variant="simple" size="small">{{ csdForm.errors.password }}</Message>
                </div>
            </form>

            <template #footer>
                <Button label="Cerrar" severity="secondary" class="!rounded-full" @click="showCsdDialog = false" />
                <Button v-if="!fiscalProfile.certificate_number || isUpdatingCsd" label="Cargar certificado" icon="pi pi-cloud-upload" :loading="csdForm.processing" class="!rounded-full" @click="submitCsd" />
                <Button v-else label="Actualizar certificado" icon="pi pi-refresh" class="!rounded-full" @click="isUpdatingCsd = true" />
            </template>
        </Dialog>
    </AppLayout>
</template>
