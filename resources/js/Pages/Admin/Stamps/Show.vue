<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchase: Object,
});

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Comprobantes pendientes', url: route('admin.stamps.index') },
    { label: `Compra #${props.purchase.id}` },
]);

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function statusLabel(status) {
    const labels = {
        'pending': 'Pendiente',
        'awaiting_review': 'En revisión',
        'approved': 'Aprobado',
        'rejected': 'Rechazado',
        'failed': 'Fallido',
        'stamps_applied': 'Acreditado',
    };
    return labels[status] || status;
}

function statusSeverity(status) {
    const map = {
        'pending': 'warn',
        'awaiting_review': 'info',
        'approved': 'success',
        'rejected': 'danger',
        'failed': 'danger',
        'stamps_applied': 'success',
    };
    return map[status] || 'secondary';
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
    if (!date) return '—';
    return new Date(date).toLocaleDateString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

// ──────────────────────────────────────
// Approve / Reject
// ──────────────────────────────────────
const showRejectDialog = ref(false);
const rejectReason = ref('');

function approve() {
    router.post(route('admin.stamps.approve', props.purchase.id), {}, {
        preserveScroll: true,
        preserveState: false,
    });
}

function confirmReject() {
    if (!rejectReason.value.trim()) return;

    router.post(
        route('admin.stamps.reject', props.purchase.id),
        { rejection_reason: rejectReason.value },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                showRejectDialog.value = false;
            },
        }
    );
}
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-3xl mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Compra #{{ purchase.id }}
                    </h1>
                    <p class="text-sm text-gray-500 mt-1 m-0">
                        {{ formatDate(purchase.created_at) }}
                    </p>
                </div>
                <Tag :value="statusLabel(purchase.status)" :severity="statusSeverity(purchase.status)" class="!rounded-full" />
            </div>

            <!-- ── Details Card ─────────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6 space-y-4">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Detalles</h2>

                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Suscriptor</span>
                        <Link
                            :href="route('admin.subscriptions.show', purchase.fiscal_profile?.subscription_id)"
                            class="text-primary-500 hover:underline font-medium"
                        >
                            {{ purchase.fiscal_profile?.subscription?.business_name ?? '—' }}
                        </Link>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Perfil fiscal</span>
                        <span class="font-medium">{{ purchase.fiscal_profile?.razon_social }}</span>
                        <span class="text-xs text-gray-400">RFC: {{ purchase.fiscal_profile?.rfc }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Cantidad</span>
                        <span class="font-medium">{{ purchase.stamp_quantity.toLocaleString() }} timbres</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Monto</span>
                        <span class="font-medium">{{ purchase.amount_total > 0 ? formatCurrency(purchase.amount_total) : '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Método de pago</span>
                        <span>{{ paymentMethodLabel(purchase.payment_method) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Solicitado por</span>
                        <span>{{ purchase.requested_by?.name ?? '—' }}</span>
                    </div>
                    <div v-if="purchase.reviewed_by" class="flex flex-col gap-0.5">
                        <span class="text-gray-400">Revisado por</span>
                        <span>{{ purchase.reviewed_by?.name }}</span>
                    </div>
                    <div v-if="purchase.rejection_reason" class="col-span-2 flex flex-col gap-0.5">
                        <span class="text-gray-400">Motivo de rechazo</span>
                        <span class="text-red-600">{{ purchase.rejection_reason }}</span>
                    </div>
                    <div v-if="purchase.admin_note" class="col-span-2 flex flex-col gap-0.5">
                        <span class="text-gray-400">Nota del admin</span>
                        <span>{{ purchase.admin_note }}</span>
                    </div>
                </div>

                <!-- Proof file -->
                <div v-if="purchase.proof_file_path" class="pt-2 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button
                        as="a"
                        :href="`/storage/${purchase.proof_file_path}`"
                        target="_blank"
                        icon="pi pi-file"
                        label="Ver comprobante"
                        severity="secondary"
                        class="!rounded-full"
                    />
                </div>
            </div>

            <!-- ── Actions ──────────────────────────── -->
            <div class="flex gap-3 justify-end">
                <!-- Retry PAC call (approved but not applied) -->
                <Button
                    v-if="purchase.status === 'approved' && purchase.status !== 'stamps_applied'"
                    label="Reintentar envío al PAC"
                    icon="pi pi-refresh"
                    severity="warn"
                    class="!rounded-full"
                    @click="router.post(route('admin.stamps.retry', purchase.id))"
                />
                <!-- Awaiting review actions -->
                <template v-if="purchase.status === 'awaiting_review'">
                    <Button
                        label="Rechazar"
                        severity="danger"
                        icon="pi pi-times"
                        class="!rounded-full"
                        @click="showRejectDialog = true"
                    />
                    <Button
                        label="Aprobar y acreditar timbres"
                        icon="pi pi-check"
                        class="!rounded-full"
                        @click="approve"
                    />
                </template>
            </div>

            <!-- ── PAC Response (if applied) ────────────── -->
            <div v-if="purchase.pac_stamps_response_raw" class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Respuesta del PAC</h2>
                <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto m-0">{{ JSON.stringify(purchase.pac_stamps_response_raw, null, 2) }}</pre>
            </div>

        </div>

        <!-- ── Reject Dialog ────────────────────────────── -->
        <Dialog
            v-model:visible="showRejectDialog"
            header="Rechazar comprobante"
            :modal="true"
            class="w-full max-w-md"
            :pt="{
                root: { class: '!rounded-3xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' },
                header: { class: '!bg-transparent' },
                content: { class: '!bg-transparent' },
            }"
        >
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Motivo del rechazo *</label>
                <Textarea
                    v-model="rejectReason"
                    rows="3"
                    class="w-full"
                    placeholder="Explica por qué se rechaza..."
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
