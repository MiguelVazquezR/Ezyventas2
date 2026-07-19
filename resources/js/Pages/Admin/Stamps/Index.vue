<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchases: Object,
});

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Comprobantes de transferencia pendientes' },
]);

// ──────────────────────────────────────
// Status helpers
// ──────────────────────────────────────
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
    return new Date(date).toLocaleDateString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

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
                    Comprobantes de transferencia pendientes
                </h1>
                <p class="text-sm text-gray-500 mt-1 m-0">
                    {{ purchases.total }} comprobante(s) esperando revisión
                </p>
            </div>

            <!-- ── Purchases Table ──────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <DataTable
                    :value="purchases.data"
                    :paginator="purchases.total > 25"
                    :rows="25"
                    :totalRecords="purchases.total"
                    stripedRows
                    class="w-full"
                    :pt="{
                        root: { class: '!bg-transparent' },
                        headerRow: { class: '!bg-transparent' },
                    }"
                >
                    <Column header="Suscriptor">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <Link
                                    :href="route('admin.subscriptions.show', data.fiscal_profile?.subscription_id)"
                                    class="text-sm font-medium text-primary-500 hover:underline"
                                >
                                    {{ data.fiscal_profile?.subscription?.business_name ?? '—' }}
                                </Link>
                                <span class="text-xs text-gray-400">ID: {{ data.fiscal_profile?.subscription_id }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Perfil fiscal">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="text-sm font-medium">{{ data.fiscal_profile?.razon_social ?? '—' }}</span>
                                <span class="text-xs text-gray-400">RFC: {{ data.fiscal_profile?.rfc }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="stamp_quantity" header="Timbres">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.stamp_quantity.toLocaleString() }}</span>
                        </template>
                    </Column>
                    <Column field="amount_total" header="Monto">
                        <template #body="{ data }">
                            {{ formatCurrency(data.amount_total) }}
                        </template>
                    </Column>
                    <Column field="created_at" header="Fecha de subida">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-500">{{ formatDate(data.created_at) }}</span>
                        </template>
                    </Column>
                    <Column header="Acciones">
                        <template #body="{ data }">
                            <div class="flex gap-2">
                                <Button
                                    v-if="data.proof_file_path"
                                    as="a"
                                    :href="`/storage/${data.proof_file_path}`"
                                    target="_blank"
                                    icon="pi pi-eye"
                                    severity="secondary"
                                    size="small"
                                    class="!rounded-full"
                                    v-tooltip.top="'Ver comprobante'"
                                />
                                <Button
                                    icon="pi pi-check"
                                    severity="success"
                                    size="small"
                                    class="!rounded-full"
                                    v-tooltip.top="'Aprobar'"
                                    @click="approve(data)"
                                />
                                <Button
                                    icon="pi pi-times"
                                    severity="danger"
                                    size="small"
                                    class="!rounded-full"
                                    v-tooltip.top="'Rechazar'"
                                    @click="openRejectDialog(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>

                <div v-if="purchases.data.length === 0" class="text-center py-12 text-sm text-gray-400">
                    <i class="pi pi-check-circle text-3xl mb-3 text-green-400" />
                    <p class="m-0">No hay comprobantes pendientes de revisión.</p>
                </div>
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
