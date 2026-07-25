<script setup>
import { ref, onMounted, watch, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { taxRegimeLabel } from '@/Composables';
import LogoUploadModal from '@/Pages/Billing/Settings/Partials/LogoUploadModal.vue';
import PurchaseStampsModal from '@/Pages/Billing/Settings/Partials/PurchaseStampsModal.vue';
import ManifestWizardModal from '@/Pages/Billing/Settings/Partials/ManifestWizardModal.vue';
import CsdUploadModal from '@/Pages/Billing/Settings/Partials/CsdUploadModal.vue';

const props = defineProps({
    fiscalProfile: Object,
    balance: [Object, null],
    balanceError: [String, null],
    invoiceStats: Object,
    movements: Object,
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
// CSD Modal (reusable component)
// ──────────────────────────────────────
const csdModalRef = ref(null);

const openCsdDialog = () => {
    csdModalRef.value?.open(props.fiscalProfile);
};

// ──────────────────────────────────────
// Tutorial onboarding state
// ──────────────────────────────────────
const tutorialStep = ref(null); // null | 'csd' | 'manifest'
const tutorialDismissed = ref(false);

const csdBtnRef = ref(null);
const manifestBtnRef = ref(null);

function startTutorial() {
    tutorialDismissed.value = false;
    if (!props.fiscalProfile.certificate_number) {
        tutorialStep.value = 'csd';
    } else if (!props.fiscalProfile.manifest_signed_at) {
        tutorialStep.value = 'manifest';
    } else {
        tutorialStep.value = null;
    }
}

function dismissTutorial() {
    tutorialStep.value = null;
    tutorialDismissed.value = true;
}

const tutorialCardStyle = ref({});

function repositionTutorialCard() {
    if (!tutorialStep.value) return;
    const targetRef = tutorialStep.value === 'csd' ? csdBtnRef.value : manifestBtnRef.value;
    if (!targetRef?.$el) return;
    const rect = targetRef.$el.getBoundingClientRect();
    // Position card just below the button, horizontally centered
    const cardTop = rect.bottom + 12; // 12px gap below the button
    const cardLeft = Math.max(16, rect.left + rect.width / 2 - 192); // Center of button minus half card width (~384px / 2), min 16px from edge
    tutorialCardStyle.value = {
        marginTop: `${cardTop}px`,
        marginLeft: `${Math.min(cardLeft, window.innerWidth - 416)}px`, // Don't overflow right edge
    };
}

watch(tutorialStep, async (val) => {
    if (val) {
        await nextTick();
        repositionTutorialCard();
    }
});

function goToNextTutorialStep() {
    if (tutorialStep.value === 'csd') {
        tutorialStep.value = props.fiscalProfile.manifest_signed_at ? null : 'manifest';
    } else {
        tutorialStep.value = null;
    }
}

function goToPrevTutorialStep() {
    if (tutorialStep.value === 'manifest') {
        tutorialStep.value = 'csd';
    }
}

onMounted(() => {
    if (!tutorialDismissed.value) {
        startTutorial();
    }
});
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

function movementTypeLabel(type) {
    return type === 'entry' ? 'Entrada' : 'Salida';
}

function movementTypeSeverity(type) {
    return type === 'entry' ? 'success' : 'danger';
}

function movementTypeIcon(type) {
    return type === 'entry' ? 'pi-arrow-up-right' : 'pi-arrow-down-left';
}

function paymentMethodLabel(method) {
    const labels = {
        mercadopago: 'Mercado Pago',
        bank_transfer: 'Transferencia',
        manual_adjustment: 'Ajuste manual',
    };
    return labels[method] || method;
}

const onMovementPage = (event) => {
    router.visit(route('billing.fiscal-profiles.show', props.fiscalProfile.id), {
        data: { page: event.page + 1 },
        preserveState: true,
        preserveScroll: true,
        only: ['movements'],
    });
};

function formatCurrency(amount) {
    const val = Number(amount) || 0;
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(val);
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
                                    Régimen: {{ fiscalProfile.regimen_fiscal }} - {{ taxRegimeLabel(fiscalProfile.regimen_fiscal) }}
                                </p>
                                <p class="text-xs text-gray-400 mt-1 m-0">
                                    Registrado el {{ formatDate(fiscalProfile.created_at) }}
                                    <span v-if="fiscalProfile.postal_code" class="ml-2">· CP {{ fiscalProfile.postal_code }}</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <Button
                                    ref="csdBtnRef"
                                    v-if="fiscalProfile.sw_user_id"
                                    icon="pi pi-key"
                                    :label="fiscalProfile.certificate_number ? 'Certificado activo' : 'Configurar CSD'"
                                    :severity="fiscalProfile.certificate_number ? 'success' : 'primary'"
                                    outlined
                                    size="small"
                                    class="!rounded-full !bg-white dark:!bg-[#232323]"
                                    :class="tutorialStep === 'csd' ? '!ring-2 !ring-primary-400 !ring-offset-2 !ring-offset-white dark:!ring-offset-[#232323] !shadow-lg !shadow-primary-500/30 !scale-105 !z-50' : ''"
                                    @click="openCsdDialog"
                                    v-tooltip.top="fiscalProfile.certificate_number ? 'Ver o actualizar certificado CSD' : 'Cargar certificados CSD (.cer y .key)'"
                                />
                                <Button
                                    v-if="canPurchaseStamps && fiscalProfile.is_active"
                                    icon="pi pi-ticket"
                                    label="Comprar timbres"
                                    class="!rounded-full"
                                    @click="purchaseModalRef?.open()"
                                />
                            </div>
                        </div>
                        <div v-if="fiscalProfile.certificate_number" class="mt-4 flex items-center gap-2 text-xs text-gray-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                            <span class="font-semibold text-gray-600 dark:text-gray-400">CSD</span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span>Nº serie: <span class="font-mono text-gray-700 dark:text-gray-300">{{ fiscalProfile.certificate_number }}</span></span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span>Vigencia: {{ fiscalProfile.valid_from }} — {{ fiscalProfile.valid_to }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════ TUTORIAL ONBOARDING OVERLAY ═══════════════════════════════ -->
            <Teleport to="body">
                <div v-if="tutorialStep"
                     class="fixed inset-0 z-40 flex items-start"
                     @click="dismissTutorial">
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"></div>

                    <!-- Floating card: CSD step -->
                    <div v-if="tutorialStep === 'csd'"
                         class="relative z-50 w-full max-w-sm mx-4"
                         :style="tutorialCardStyle"
                         @click.stop>
                        <div class="bg-white dark:bg-[#232323] rounded-3xl shadow-2xl dark:shadow-[0_0_40px_rgba(0,0,0,0.5)] border border-gray-100 dark:border-[#3a3a3a] p-6 lg:p-7 relative">
                            <!-- Arrow pointing up-right toward the CSD button area -->
                            <div class="absolute -top-2 right-12 w-4 h-4 rotate-45 bg-white dark:bg-[#232323] border-t border-l border-gray-100 dark:border-[#3a3a3a]"></div>

                            <!-- Step badge -->
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center shadow-[0_4px_12px_rgba(99,102,241,0.35)]">
                                    <i class="pi pi-key !text-sm !text-white"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Paso 2 de 3</p>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white m-0 leading-tight">Configurar CSD</p>
                                </div>
                            </div>

                            <p class="text-sm text-gray-600 dark:text-gray-400 m-0 leading-relaxed">
                                Sube tus certificados emitidos por el SAT (<span class="font-mono text-xs">.cer</span> y <span class="font-mono text-xs">.key</span>) para activar el timbrado de facturas electrónicas.
                            </p>

                            <div class="mt-6 flex items-center justify-between gap-3">
                                <button @click="dismissTutorial" class="text-[11px] font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                    Saltar tutorial
                                </button>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-gray-400">2/3</span>
                                    <Button
                                        icon="pi pi-arrow-right"
                                        class="!rounded-full !w-8 !h-8 !p-0"
                                        severity="secondary"
                                        size="small"
                                        @click="goToNextTutorialStep"
                                        v-tooltip.top="'Ver siguiente paso'"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating card: Manifest step -->
                    <div v-if="tutorialStep === 'manifest'"
                         class="relative z-50 w-full max-w-sm mx-4"
                         :style="tutorialCardStyle"
                         @click.stop>
                        <div class="bg-white dark:bg-[#232323] rounded-3xl shadow-2xl dark:shadow-[0_0_40px_rgba(0,0,0,0.5)] border border-gray-100 dark:border-[#3a3a3a] p-6 lg:p-7 relative">
                            <!-- Arrow pointing toward the manifest area -->
                            <div class="absolute -top-2 right-16 w-4 h-4 rotate-45 bg-white dark:bg-[#232323] border-t border-l border-gray-100 dark:border-[#3a3a3a]"></div>

                            <!-- Step badge -->
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center shadow-[0_4px_12px_rgba(99,102,241,0.35)]">
                                    <i class="pi pi-file !text-sm !text-white"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Paso 3 de 3</p>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white m-0 leading-tight">Firmar manifiesto SAT</p>
                                </div>
                            </div>

                            <p class="text-sm text-gray-600 dark:text-gray-400 m-0 leading-relaxed">
                                Firma el manifiesto con tu e.firma para autorizar al PAC (Proveedor Autorizado de Timbrado) a timbrar tus facturas ante el SAT. Este trámite es obligatorio y no tiene costo.
                            </p>

                            <div class="mt-6 flex items-center justify-between gap-3">
                                <button @click="dismissTutorial" class="text-[11px] font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                    Saltar tutorial
                                </button>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-gray-400">3/3</span>
                                    <Button
                                        icon="pi pi-arrow-left"
                                        class="!rounded-full !w-8 !h-8 !p-0"
                                        severity="secondary"
                                        size="small"
                                        @click="goToPrevTutorialStep"
                                        v-tooltip.top="'Paso anterior'"
                                    />
                                    <Button
                                        label="Entendido"
                                        class="!rounded-full !text-xs"
                                        size="small"
                                        @click="dismissTutorial"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Teleport>

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
                        ref="manifestBtnRef"
                        label="Firmar manifiesto"
                        icon="pi pi-pen-to-square"
                        class="!rounded-full"
                        :class="tutorialStep === 'manifest' ? '!ring-2 !ring-primary-400 !ring-offset-2 !ring-offset-white dark:!ring-offset-[#232323] !shadow-lg !shadow-primary-500/30 !scale-105 !z-50' : ''"
                        @click="manifestModalRef?.open()"
                    />
                </div>
            </div>

            <!-- ═══════════════════════════════ INVOICE KPIs ═══════════════════════════════ -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <!-- Pre-facturas -->
                <Link :href="route('billing.invoices.index', { status: 'borrador', fiscal_profile_id: fiscalProfile.id })"
                      class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2 group hover:border-blue-300 dark:hover:border-blue-800 transition-colors cursor-pointer no-underline">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                            <i class="pi pi-receipt !text-xs text-blue-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Pre-facturas</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-blue-600 dark:text-blue-400">{{ formatNumber(invoiceStats.draftCount) }}</span>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">
                            Total por facturar: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ formatCurrency(invoiceStats.draftAmount) }}</span>
                        </span>
                    </div>  
                </Link>

                <!-- Facturas timbradas -->
                <Link :href="route('billing.invoices.index', { status: 'certificada', fiscal_profile_id: fiscalProfile.id })"
                      class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2 group hover:border-emerald-300 dark:hover:border-emerald-700 transition-colors cursor-pointer no-underline">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                            <i class="pi pi-check-circle !text-xs text-emerald-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Facturas timbradas</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-emerald-600 dark:text-emerald-400">{{ formatNumber(invoiceStats.certifiedCount) }}</span>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">
                            Total facturado: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ formatCurrency(invoiceStats.certifiedAmount) }}</span>
                        </span>
                    </div>
                </Link>

                <!-- Pendientes de cancelación -->
                <Link :href="route('billing.invoices.index', { status: 'cancelacion_pendiente', fiscal_profile_id: fiscalProfile.id })"
                      class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2 group hover:border-amber-300 dark:hover:border-amber-700 transition-colors cursor-pointer no-underline">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                            <i class="pi pi-clock !text-xs text-amber-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Pendientes de cancelación</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-amber-600 dark:text-amber-400">{{ formatNumber(invoiceStats.cancelPendingCount) }}</span>
                    <div class="flex items-center justify-end mt-1">
                    </div>
                </Link>

                <!-- Facturas canceladas -->
                <Link :href="route('billing.invoices.index', { status: 'cancelada', fiscal_profile_id: fiscalProfile.id })"
                      class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2 group hover:border-red-300 dark:hover:border-red-700 transition-colors cursor-pointer no-underline">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                            <i class="pi pi-times-circle !text-xs text-red-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Facturas canceladas</span>
                    </div>
                    <span class="text-2xl font-light tracking-tight text-red-500">{{ formatNumber(invoiceStats.canceledCount) }}</span>
                    <div class="flex items-center justify-end mt-1">
                    </div>
                </Link>
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

            <!-- ═══════════════════════════════ STAMP MOVEMENT LEDGER ═══════════════════════════════ -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Historial de timbres</h2>
                <DataTable
                    :value="movements.data"
                    lazy
                    :paginator="movements.total > 15"
                    :rows="15"
                    :totalRecords="movements.total"
                    :first="(movements.current_page - 1) * movements.per_page"
                    @page="onMovementPage($event)"
                    stripedRows
                    class="w-full"
                    :pt="{ root: { class: '!bg-transparent' }, headerRow: { class: '!bg-transparent' } }"
                >
                    <Column field="created_at" header="Fecha">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                {{ new Date(data.created_at).toLocaleDateString('es-MX', { dateStyle: 'medium' }) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Descripción">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="text-sm text-gray-900 dark:text-white">{{ data.description }}</span>
                                <span v-if="data.metadata?.payment_method && data.type === 'entry'" class="text-[10px] text-gray-400 uppercase tracking-widest">
                                    {{ paymentMethodLabel(data.metadata.payment_method) }}
                                    <Tag
                                        v-if="data.metadata.status"
                                        :value="statusLabel(data.metadata.status)"
                                        :severity="statusSeverity(data.metadata.status)"
                                        class="!rounded-full !ml-2 !text-[9px] !uppercase !tracking-widest !font-bold"
                                    />
                                </span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Tipo" class="w-28">
                        <template #body="{ data }">
                            <Tag
                                :value="movementTypeLabel(data.type)"
                                :severity="movementTypeSeverity(data.type)"
                                class="!rounded-full"
                            />
                        </template>
                    </Column>

                    <Column header="Cantidad" class="w-28">
                        <template #body="{ data }">
                            <span class="text-sm font-medium" :class="data.type === 'entry' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">
                                {{ data.type === 'entry' ? '+' : '-' }}{{ data.quantity.toLocaleString() }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Saldo" class="w-28">
                        <template #body="{ data }">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ data.balance_after.toLocaleString() }}</span>
                        </template>
                    </Column>

                    <Column header="Monto pagado" class="w-36">
                        <template #body="{ data }">
                            <span v-if="data.type === 'entry' && data.metadata?.amount_total && data.metadata.amount_total > 0" class="text-sm">
                                {{ formatCurrency(data.metadata.amount_total) }}
                            </span>
                            <span v-else class="text-sm text-gray-400">—</span>
                        </template>
                    </Column>
                </DataTable>
                <div v-if="movements.data.length === 0" class="text-center py-8 text-sm text-gray-400">
                    No hay movimientos registrados para este emisor fiscal.
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

        <CsdUploadModal
            ref="csdModalRef"
            @success="router.reload()"
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
    </AppLayout>
</template>
