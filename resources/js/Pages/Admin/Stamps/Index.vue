<script setup>
import { ref, onMounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdjustStampModal from '@/Components/AdjustStampModal.vue';

const props = defineProps({
    masterBalance: [Object, null],
    masterBalanceError: [String, null],
    snapshot: [Object, null],
    tiers: Array,
    preview: Array,
    threshold: Number,
    totalSubaccounts: Number,
    fiscalProfiles: {
        type: Array,
        default: () => [],
    },
});

// ──────────────────────────────────────
// Adjust Stamp Modal
// ──────────────────────────────────────
const showAdjustModal = ref(false);
const adjustPreselectedId = ref(null);

function openAdjustModal(profileId = null) {
    adjustPreselectedId.value = profileId;
    showAdjustModal.value = true;
}

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Gestión de timbres' },
]);

// ──────────────────────────────────────
// Tab state
// ──────────────────────────────────────
const activeTab = ref(0);

// ──────────────────────────────────────
// Master balance refresh
// ──────────────────────────────────────
const masterBalanceRefreshing = ref(false);
const liveMasterBalance = ref(props.masterBalance);

function refreshMasterBalance() {
    masterBalanceRefreshing.value = true;
    fetch(route('admin.stamps.master-balance'))
        .then(r => r.json())
        .then(data => {
            if (data.balance) liveMasterBalance.value = data.balance;
        })
        .finally(() => { masterBalanceRefreshing.value = false; });
}

// ──────────────────────────────────────
// Global stats snapshot
// ──────────────────────────────────────
const statsRefreshing = ref(false);
const statsMessage = ref('');
const snapshotData = ref(props.snapshot);

function refreshStats() {
    statsRefreshing.value = true;
    statsMessage.value = '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch(route('admin.stamps.global-stats.refresh'), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    })
        .then(r => r.json())
        .then(data => {
            if (data.snapshot) snapshotData.value = data.snapshot;
            statsMessage.value = data.message || data.error || '';
        })
        .catch(() => { statsMessage.value = 'Error al actualizar.'; })
        .finally(() => { statsRefreshing.value = false; });
}

// ──────────────────────────────────────
// Issuers table
// ──────────────────────────────────────
const issuers = ref({ data: [], total: 0 });
const issuersLoading = ref(false);
const issuersPage = ref(1);
const issuersPerPage = ref(15);
const issuersSearch = ref('');
let issuersSearchTimeout = null;

function loadIssuers() {
    issuersLoading.value = true;
    const params = new URLSearchParams({
        page: issuersPage.value,
        per_page: issuersPerPage.value,
        search: issuersSearch.value,
    });
    fetch(route('admin.stamps.issuers.index') + '?' + params)
        .then(r => r.json())
        .then(data => { issuers.value = data.issuers; })
        .finally(() => { issuersLoading.value = false; });
}

function onIssuerPage(event) {
    issuersPage.value = event.page + 1;
    issuersPerPage.value = event.rows;
    loadIssuers();
}

function onIssuerSearch() {
    clearTimeout(issuersSearchTimeout);
    issuersSearchTimeout = setTimeout(() => {
        issuersPage.value = 1;
        loadIssuers();
    }, 400);
}

// ──────────────────────────────────────
// Pricing tier CRUD
// ──────────────────────────────────────
const showTierDialog = ref(false);
const editingTier = ref(null);
const isEditingTier = ref(false);

const tierForm = useForm({
    min_quantity: 1,
    max_quantity: null,
    unit_price: 0.85,
    label: '',
    is_active: true,
    sort_order: 0,
});

function openCreateTier() {
    isEditingTier.value = false;
    editingTier.value = null;
    tierForm.reset();
    tierForm.min_quantity = 1;
    tierForm.max_quantity = null;
    tierForm.unit_price = 0.85;
    tierForm.label = '';
    tierForm.is_active = true;
    tierForm.sort_order = 0;
    tierForm.clearErrors();
    showTierDialog.value = true;
}

function openEditTier(tier) {
    isEditingTier.value = true;
    editingTier.value = tier;
    tierForm.min_quantity = tier.min_quantity;
    tierForm.max_quantity = tier.max_quantity;
    tierForm.unit_price = tier.unit_price;
    tierForm.label = tier.label || '';
    tierForm.is_active = tier.is_active;
    tierForm.sort_order = tier.sort_order || 0;
    tierForm.clearErrors();
    showTierDialog.value = true;
}

function submitTier() {
    if (isEditingTier.value) {
        tierForm.put(route('admin.stamps.pricing.update', editingTier.value.id), {
            preserveScroll: true,
            onSuccess: () => { showTierDialog.value = false; },
        });
    } else {
        tierForm.post(route('admin.stamps.pricing.store'), {
            preserveScroll: true,
            onSuccess: () => { showTierDialog.value = false; },
        });
    }
}

function confirmDeleteTier(tier) {
    if (confirm(`¿Eliminar el tramo "${tier.label || tier.min_quantity + ' timbres'}"?`)) {
        router.delete(route('admin.stamps.pricing.destroy', tier.id), { preserveScroll: true });
    }
}

// ──────────────────────────────────────
// Threshold configuration
// ──────────────────────────────────────
const thresholdValue = ref(props.threshold);
const thresholdSaving = ref(false);
const thresholdMessage = ref('');

function saveThreshold() {
    thresholdSaving.value = true;
    thresholdMessage.value = '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch(route('admin.stamps.threshold.update'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ threshold: thresholdValue.value }),
    })
        .then(r => r.json())
        .then(data => { thresholdMessage.value = data.message || ''; })
        .catch(() => { thresholdMessage.value = 'Error al guardar.'; })
        .finally(() => { thresholdSaving.value = false; });
}

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount || 0);
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-MX').format(value || 0);
}

function formatDate(date) {
    if (!date) return '—';
    return new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

function timeAgo(dateString) {
    if (!dateString) return '—';
    const diff = Date.now() - new Date(dateString).getTime();
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `hace ${hours} h`;
    return `hace ${Math.floor(hours / 24)} d`;
}

function paymentMethodLabel(method) {
    const labels = { 'mercadopago': 'Mercado Pago', 'bank_transfer': 'Transferencia', 'manual_adjustment': 'Ajuste manual' };
    return labels[method] || method;
}

// ──────────────────────────────────────
// Lifecycle
// ──────────────────────────────────────
onMounted(() => { loadIssuers(); });

// ──────────────────────────────────────
// Tesla UI PT
// ──────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-6xl mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Gestión de timbres
                    </h1>
                    <p class="text-sm text-gray-500 mt-1 m-0">
                        Panel global de administración de timbres y PAC
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button
                        as="a"
                        :href="route('admin.stamps.review-queue')"
                        icon="pi pi-inbox"
                        label="Bandeja de revisión"
                        severity="secondary"
                        class="!rounded-full"
                    />
                    <Button
                        icon="pi pi-cog"
                        label="Ajuste manual"
                        severity="secondary"
                        class="!rounded-full"
                        @click="openAdjustModal()"
                    />
                </div>
            </div>

            <!-- ════════════════ KPIs ════════════════ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                <!-- Master balance (live) -->
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cuenta maestra PAC</p>
                        <button @click="refreshMasterBalance" class="bg-transparent border-none cursor-pointer p-0 text-gray-400 hover:text-primary-500 transition-colors" :disabled="masterBalanceRefreshing">
                            <i class="pi pi-refresh" :class="{ 'animate-spin': masterBalanceRefreshing }" :style="{ fontSize: '12px' }"></i>
                        </button>
                    </div>
                    <p v-if="masterBalanceError" class="text-xs text-red-500 m-0">{{ masterBalanceError }}</p>
                    <template v-else-if="liveMasterBalance">
                        <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                            {{ formatNumber(liveMasterBalance.stampsBalance) }}
                        </p>
                        <div class="flex gap-4 mt-2 text-xs text-gray-400">
                            <span>{{ formatNumber(liveMasterBalance.stampsAssigned) }} asignados</span>
                            <span>{{ formatNumber(liveMasterBalance.stampsUsed) }} usados</span>
                        </div>
                    </template>
                    <p v-else class="text-sm text-gray-400 m-0">Cargando...</p>
                </div>

                <!-- Timbres Asignados (cached) -->
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Timbres asignados</p>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        {{ snapshotData ? formatNumber(snapshotData.total_stamps_assigned) : '—' }}
                    </p>
                    <p v-if="snapshotData" class="text-xs text-gray-400 mt-1 m-0">Actualizado {{ timeAgo(snapshotData.computed_at) }}</p>
                </div>

                <!-- Timbres Consumidos (cached) -->
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Timbres consumidos</p>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        {{ snapshotData ? formatNumber(snapshotData.total_stamps_used) : '—' }}
                    </p>
                    <p v-if="snapshotData" class="text-xs text-gray-400 mt-1 m-0">Actualizado {{ timeAgo(snapshotData.computed_at) }}</p>
                </div>

                <!-- Emisores Activos (cached + refresh button) -->
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Emisores activos</p>
                        <button @click="refreshStats" class="bg-transparent border-none cursor-pointer p-0 text-gray-400 hover:text-primary-500 transition-colors" :disabled="statsRefreshing">
                            <i class="pi pi-refresh" :class="{ 'animate-spin': statsRefreshing }" :style="{ fontSize: '12px' }"></i>
                        </button>
                    </div>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        {{ snapshotData ? formatNumber(snapshotData.active_issuers_count) : '—' }}
                    </p>
                    <p v-if="statsMessage" class="text-xs mt-1 m-0" :class="statsMessage.includes('Error') ? 'text-red-500' : 'text-green-500'">{{ statsMessage }}</p>
                </div>

                <!-- Timbres Distribuidos (live from master balance) -->
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Timbres distribuidos</p>
                        <button @click="refreshMasterBalance" class="bg-transparent border-none cursor-pointer p-0 text-gray-400 hover:text-primary-500 transition-colors" :disabled="masterBalanceRefreshing">
                            <i class="pi pi-refresh" :class="{ 'animate-spin': masterBalanceRefreshing }" :style="{ fontSize: '12px' }"></i>
                        </button>
                    </div>
                    <p v-if="masterBalanceError" class="text-xs text-red-500 m-0">{{ masterBalanceError }}</p>
                    <template v-else-if="liveMasterBalance">
                        <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                            {{ formatNumber(liveMasterBalance.stampsAssigned) }}
                        </p>
                        <p class="text-xs text-gray-400 mt-1 m-0">Asignados a subcuentas</p>
                    </template>
                    <p v-else class="text-sm text-gray-400 m-0">Cargando...</p>
                </div>

                <!-- Subcuentas (live count from DB) -->
                <div class="bg-white dark:bg-[#232323] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Subcuentas</p>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        {{ formatNumber(totalSubaccounts) }}
                    </p>
                    <p class="text-xs text-gray-400 mt-1 m-0">Perfiles fiscales activos en el PAC</p>
                </div>
            </div>

            <!-- ════════════════ Tabs: Distribución | Precios | Configuración ════════════════ -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] overflow-hidden">
                <div class="flex border-b border-gray-100 dark:border-[#3a3a3a]">
                    <button
                        v-for="(tab, i) in ['Distribución por emisor', 'Precios y escalas', 'Configuración']"
                        :key="i"
                        @click="activeTab = i"
                        class="flex-1 px-4 py-3 text-xs uppercase tracking-widest font-bold transition-colors border-b-2 bg-transparent cursor-pointer"
                        :class="activeTab === i
                            ? 'text-primary-500 border-primary-500'
                            : 'text-gray-400 border-transparent hover:text-gray-600 dark:hover:text-gray-300'"
                    >
                        {{ tab }}
                    </button>
                </div>

                <!-- ──── Tab 0: Distribución por emisor ──── -->
                <div v-if="activeTab === 0" class="p-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div class="relative flex-1 max-w-sm">
                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 !text-xs"></i>
                            <input
                                v-model="issuersSearch"
                                @input="onIssuerSearch"
                                placeholder="Buscar por RFC o razón social..."
                                class="w-full pl-9 pr-4 py-2.5 rounded-2xl text-sm bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:border-primary-500 transition-colors"
                            />
                        </div>
                    </div>

                    <DataTable
                        :value="issuers.data"
                        :paginator="issuers.total > issuersPerPage"
                        :rows="issuersPerPage"
                        :totalRecords="issuers.total"
                        :lazy="true"
                        :loading="issuersLoading"
                        @page="onIssuerPage"
                        stripedRows
                        class="w-full"
                        :pt="{ root: { class: '!bg-transparent' }, headerRow: { class: '!bg-transparent' } }"
                    >
                        <Column header="Emisor">
                            <template #body="{ data }">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ data.razon_social }}</span>
                                    <span class="text-xs text-gray-400">RFC: {{ data.rfc }}</span>
                                </div>
                            </template>
                        </Column>
                        <Column header="Suscriptor">
                            <template #body="{ data }">
                                <span class="text-xs text-gray-500">{{ data.subscription_name ?? '—' }}</span>
                            </template>
                        </Column>
                        <Column header="Disponibles">
                            <template #body="{ data }">
                                <span v-if="data.balanceError" class="text-xs text-red-500">{{ data.balanceError }}</span>
                                <span v-else-if="data.balance" class="font-medium">{{ formatNumber(data.balance.stampsBalance) }}</span>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </template>
                        </Column>
                        <Column header="Usados">
                            <template #body="{ data }">
                                <span v-if="data.balance" class="text-gray-500">{{ formatNumber(data.balance.stampsUsed) }}</span>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </template>
                        </Column>
                        <Column header="Asignados">
                            <template #body="{ data }">
                                <span v-if="data.balance" class="text-gray-500">{{ formatNumber(data.balance.stampsAssigned) }}</span>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </template>
                        </Column>
                        <Column header="Último movimiento">
                            <template #body="{ data }">
                                <span v-if="data.last_purchase" class="text-xs text-gray-500">
                                    {{ paymentMethodLabel(data.last_purchase.payment_method) }}
                                    <span class="mx-1">·</span>
                                    {{ data.last_purchase.stamp_quantity.toLocaleString() }} timbres
                                    <span class="mx-1">·</span>
                                    {{ formatDate(data.last_purchase.created_at) }}
                                </span>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </template>
                        </Column>
                        <Column header="">
                            <template #body="{ data }">
                                <div class="flex gap-2">
                                    <Button
                                        as="a"
                                        :href="route('admin.stamps.history', data.id)"
                                        icon="pi pi-history"
                                        severity="secondary"
                                        size="small"
                                        class="!rounded-full"
                                        v-tooltip.top="'Historial de timbres'"
                                    />
                                    <Button
                                        icon="pi pi-cog"
                                        severity="secondary"
                                        size="small"
                                        class="!rounded-full"
                                        v-tooltip.top="'Ajustar timbres'"
                                        @click="openAdjustModal(data.id)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>

                <!-- ──── Tab 1: Precios y escalas ──── -->
                <div v-if="activeTab === 1" class="p-6 space-y-6">
                    <!-- Preview -->
                    <div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Vista previa de precios</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[10px] uppercase tracking-widest font-bold text-gray-500">
                                        <th class="pb-3 pr-4">Cantidad</th>
                                        <th class="pb-3 pr-4">Precio unitario</th>
                                        <th class="pb-3 pr-4">Total</th>
                                        <th class="pb-3">Tramo aplicado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in preview" :key="row.quantity" class="border-t border-gray-100 dark:border-[#3a3a3a]">
                                        <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ row.quantity.toLocaleString() }}</td>
                                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-400">{{ formatCurrency(row.unit_price) }}</td>
                                        <td class="py-3 pr-4 text-gray-900 dark:text-white font-light text-lg">{{ formatCurrency(row.total) }}</td>
                                        <td class="py-3 text-gray-500">{{ row.tier_label }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tiers list -->
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tramos configurados</h2>
                            <Button icon="pi pi-plus" label="Nuevo tramo" size="small" class="!rounded-full" @click="openCreateTier" />
                        </div>

                        <div v-if="tiers.length === 0" class="text-center py-8 text-sm text-gray-400">
                            No hay tramos de precio configurados. Crea el primero.
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="tier in tiers"
                                :key="tier.id"
                                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]"
                                :class="{ 'opacity-50': !tier.is_active }"
                            >
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                                        <i class="pi pi-tag !text-xs text-primary-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white m-0">
                                            {{ tier.label || 'Sin etiqueta' }}
                                            <Tag v-if="!tier.is_active" value="Inactivo" severity="secondary" class="!rounded-full ml-2" />
                                        </p>
                                        <p class="text-xs text-gray-500 m-0 mt-0.5">
                                            {{ tier.min_quantity.toLocaleString() }}
                                            <template v-if="tier.max_quantity">– {{ tier.max_quantity.toLocaleString() }}</template>
                                            <template v-else>+</template>
                                            timbres
                                            <span class="mx-1.5 text-gray-300 dark:text-gray-600">·</span>
                                            {{ formatCurrency(tier.unit_price) }} c/u
                                        </p>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <Button icon="pi pi-pencil" severity="secondary" size="small" class="!rounded-full" @click="openEditTier(tier)" v-tooltip.top="'Editar'" />
                                    <Button icon="pi pi-trash" severity="danger" size="small" class="!rounded-full" @click="confirmDeleteTier(tier)" v-tooltip.top="'Eliminar'" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ──── Tab 2: Configuración ──── -->
                <div v-if="activeTab === 2" class="p-6 space-y-6">
                    <!-- Threshold -->
                    <div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Umbral de revisión de compras grandes</h2>
                        <p class="text-xs text-gray-400 mb-4 m-0">
                            Las compras por Mercado Pago con esta cantidad de timbres o superior requieren revisión manual del superadmin antes de aplicarse al PAC.
                        </p>
                        <div class="flex items-end gap-3">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Timbres (mínimo)</label>
                                <InputNumber
                                    v-model="thresholdValue"
                                    :min="1"
                                    :max="999999"
                                    class="w-48"
                                    :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }"
                                />
                            </div>
                            <Button
                                icon="pi pi-check"
                                label="Guardar"
                                :loading="thresholdSaving"
                                class="!rounded-full"
                                @click="saveThreshold"
                            />
                        </div>
                        <p v-if="thresholdMessage" class="text-xs mt-2 m-0 text-green-500">{{ thresholdMessage }}</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Pricing Tier Dialog ─────────────────────── -->
        <Dialog
            v-model:visible="showTierDialog"
            :modal="true"
            class="w-full max-w-md"
            :pt="dialogPt"
        >
            <template #header>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                        <i class="pi pi-tag !text-sm text-primary-500"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">
                            {{ isEditingTier ? 'Editar tramo' : 'Nuevo tramo' }}
                        </h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Precio por volumen</p>
                    </div>
                </div>
            </template>

            <form @submit.prevent="submitTier" class="flex flex-col gap-4 pt-2">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad mínima *</label>
                    <InputNumber v-model="tierForm.min_quantity" :min="1" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }" />
                    <Message v-if="tierForm.errors.min_quantity" severity="error" variant="simple" size="small">{{ tierForm.errors.min_quantity }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad máxima (vacío = sin límite)</label>
                    <InputNumber v-model="tierForm.max_quantity" :min="1" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }" />
                    <Message v-if="tierForm.errors.max_quantity" severity="error" variant="simple" size="small">{{ tierForm.errors.max_quantity }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Precio unitario (MXN) *</label>
                    <InputNumber v-model="tierForm.unit_price" :minFractionDigits="2" :maxFractionDigits="4" mode="decimal" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-2xl !font-light !text-gray-900 dark:!text-white' } } }" />
                    <Message v-if="tierForm.errors.unit_price" severity="error" variant="simple" size="small">{{ tierForm.errors.unit_price }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Etiqueta</label>
                    <InputText v-model="tierForm.label" placeholder="Ej: Volumen medio" class="w-full"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }" />
                </div>

                <div class="flex items-center gap-3">
                    <Checkbox v-model="tierForm.is_active" inputId="isActiveTier" :binary="true" />
                    <label for="isActiveTier" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">Activo</label>
                </div>
            </form>

            <template #footer>
                <Button label="Cancelar" severity="secondary" class="!rounded-full" @click="showTierDialog = false" />
                <Button
                    :label="isEditingTier ? 'Guardar cambios' : 'Crear tramo'"
                    class="!rounded-full"
                    :loading="tierForm.processing"
                    @click="submitTier"
                />
            </template>
        </Dialog>
        <!-- ── Adjust Stamp Modal ─────────────────────── -->
        <AdjustStampModal
            v-model:visible="showAdjustModal"
            :fiscal-profiles="fiscalProfiles"
            :tiers="tiers"
            :preselected-profile-id="adjustPreselectedId"
        />
    </AppLayout>
</template>
