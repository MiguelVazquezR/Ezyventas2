<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions, taxRegimeLabel } from '@/Composables';
import { useConfirm } from 'primevue/useconfirm';
import LogoUploadModal from './Partials/LogoUploadModal.vue';
import PurchaseStampsModal from './Partials/PurchaseStampsModal.vue';
import ManifestWizardModal from './Partials/ManifestWizardModal.vue';
import FiscalProfileFormModal from './Partials/FiscalProfileFormModal.vue';
import CsdUploadModal from './Partials/CsdUploadModal.vue';

const props = defineProps({
    fiscalProfiles: Object,
    filters: Object,
    ourBankAccounts: {
        type: Array,
        default: () => [],
    },
    usesSharedAccount: Boolean,
});

const { hasPermission } = usePermissions();
const confirm = useConfirm();

// ──────────────────────────────────────
// Search
// ──────────────────────────────────────
const searchTerm = ref(props.filters?.search || '');

let searchTimeout = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('billing.settings.index'), {
            search: val || null,
        }, {
            preserveState: true,
            replace: true,
        });
    }, 400);
});

// ──────────────────────────────────────
// Pagination & sorting
// ──────────────────────────────────────
const onPage = (event) => {
    router.get(route('billing.settings.index'), {
        page: event.page + 1,
        rows: event.rows,
        search: searchTerm.value || null,
        sortField: props.filters?.sortField,
        sortOrder: props.filters?.sortOrder,
    }, { preserveState: true });
};

const onSort = (event) => {
    router.get(route('billing.settings.index'), {
        sortField: event.sortField,
        sortOrder: event.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value || null,
    }, { preserveState: true });
};

// ──────────────────────────────────────
// Logo modal state
// ──────────────────────────────────────
const isLogoModalVisible = ref(false);
const logoProfile = ref(null);

const openLogoModal = (profile) => {
    logoProfile.value = profile;
    isLogoModalVisible.value = true;
};

const onLogoUpdated = () => {
    router.reload();
};

// ──────────────────────────────────────
// Reusable modal refs
// ──────────────────────────────────────
const fiscalProfileFormModalRef = ref(null);
const csdUploadModalRef = ref(null);

// ──────────────────────────────────────
// More dropdown menu
// ──────────────────────────────────────
const menuRef = ref(null);
const items = ref([]);
const selectedProfileForModal = ref(null);
const purchaseModalRef = ref(null);
const manifestModalRef = ref(null);

const toggleMenu = (event, profile) => {
    selectedProfileForModal.value = profile;
    const options = [];

    // Logo
    if (isAccountActive(profile)) {
        options.push({
            label: profile.logo_url ? 'Cambiar logotipo' : 'Agregar logotipo',
            icon: 'pi pi-image',
            command: () => openLogoModal(profile),
        });
    }

    // Certificados CSD
    if (isAccountActive(profile)) {
        options.push({
            label: profile.certificate_number ? 'Actualizar certificados' : 'Agregar certificados',
            icon: 'pi pi-key',
            command: () => csdUploadModalRef.value?.open(profile),
        });
    }

    // Firmar manifiesto (solo subcuentas)
    if (isAccountActive(profile) && profile.requires_manifest) {
        options.push({
            label: profile.manifest_signed_at ? 'Volver a firmar manifiesto' : 'Firmar manifiesto',
            icon: 'pi pi-pen-to-square',
            command: () => manifestModalRef.value?.open(),
        });
    }

    // Comprar timbres
    if (isAccountActive(profile)) {
        options.push({
            label: 'Comprar timbres',
            icon: 'pi pi-ticket',
            command: () => purchaseModalRef.value?.open(),
        });
    }

    // Inactivar / Activar
    options.push({
        label: profile.is_active ? 'Inactivar emisor fiscal' : 'Activar emisor fiscal',
        icon: profile.is_active ? 'pi pi-power-off' : 'pi pi-check-circle',
        command: () => {
            const action = profile.is_active ? 'inactivar' : 'activar';
            confirm.require({
                message: profile.is_active
                    ? '¿Inactivar este emisor fiscal? Ya no aparecerá al crear facturas. Tus facturas anteriores permanecerán intactas.'
                    : '¿Activar este emisor fiscal? Volverá a estar disponible al crear facturas.',
                header: profile.is_active ? 'Inactivar emisor fiscal' : 'Activar emisor fiscal',
                icon: 'pi pi-exclamation-triangle',
                acceptLabel: profile.is_active ? 'Inactivar' : 'Activar',
                rejectLabel: 'Cancelar',
                acceptClass: profile.is_active ? 'p-button-danger' : '',
                accept: () => router.post(route('billing.settings.toggleFiscalProfileActive', profile.id)),
            });
        },
    });

    items.value = options;
    menuRef.value?.toggle(event);
};

// ──────────────────────────────────────
// Row click → navigate to detail
// ──────────────────────────────────────
const onRowClick = (event) => {
    router.get(route('billing.fiscal-profiles.show', event.data.id));
};

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
// Whether the profile's PAC account is active. Backward compatible with the
// legacy sw_user_id for profiles provisioned before the pac_accounts table.
const isAccountActive = (profile) => profile.pac_account?.status === 'active' || !!profile.sw_user_id;

const getStatusSeverity = (profile) => {
    if (!profile.is_active) return 'secondary';
    if (isAccountActive(profile)) return 'success';
    return 'warn';
};

const getStatusLabel = (profile) => {
    if (!profile.is_active) return 'Inactivo';
    if (isAccountActive(profile)) return 'Activo';
    return 'Pendiente de activación';
};

const getCsdSeverity = (profile) => {
    if (!profile.certificate_number) return 'warn';
    return 'success';
};

const getCsdLabel = (profile) => {
    if (!profile.certificate_number) return 'Pendiente';
    return 'Activo';
};

const getManifestSeverity = (profile) => {
    if (!profile.requires_manifest) return 'secondary';
    if (profile.manifest_signed_at) return 'success';
    if (isAccountActive(profile)) return 'warn';
    return 'secondary';
};

const getManifestLabel = (profile) => {
    if (!profile.requires_manifest) return '—';
    if (profile.manifest_signed_at) return 'Firmado';
    if (isAccountActive(profile)) return 'Pendiente';
    return '—';
};

// Whether the Manifiesto column should be shown at all. Only profiles linked
// to a subaccount require the manifest; for shared accounts the column is
// hidden entirely (no '—' placeholders).
const showManifestColumn = computed(() =>
    (props.fiscalProfiles?.data ?? []).some((p) => p.requires_manifest)
);

const formatStamps = (val) => {
    if (val === null || val === undefined) return 'No disponible';
    return Number(val).toLocaleString('es-MX');
};

const rowClass = (data) => {
    if (!data.is_active) return 'opacity-50';
    return '';
};

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-400 py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 cursor-pointer' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } },
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <Head title="Razones sociales" />
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
                            Emisores fiscales
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                            CFDI 4.0 &middot; Administra los RFC's emisores para emitir facturas desde esta cuenta.
                        </p>
                    </div>

                    <!-- Header actions -->
                    <div class="flex items-center gap-3 shrink-0">
                        <Button
                            label="Agregar emisor fiscal"
                            icon="pi pi-plus"
                            @click="fiscalProfileFormModalRef?.open()"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider"
                        />
                    </div>
                </div>

                <!-- Search bar -->
                <div class="flex items-center bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500" />
                        <InputText
                            v-model="searchTerm"
                            placeholder="Buscar por RFC o razón social..."
                            :pt="inputPt"
                            class="!pl-10"
                        />
                    </IconField>
                    <span class="ml-auto text-[10px] uppercase tracking-widest font-bold text-gray-400 shrink-0 hidden sm:block">
                        {{ fiscalProfiles.total ?? 0 }} {{ (fiscalProfiles.total ?? 0) === 1 ? 'emisor' : 'emisores' }}
                    </span>
                </div>

                <!-- ════════════════════════════════════════
                     DataTable
                     ════════════════════════════════════════ -->
                <DataTable
                    :value="fiscalProfiles.data"
                    lazy
                    paginator
                    :totalRecords="fiscalProfiles.total"
                    :rows="fiscalProfiles.per_page"
                    :rowsPerPageOptions="[10, 20, 50]"
                    dataKey="id"
                    @page="onPage"
                    @sort="onSort"
                    @row-click="onRowClick"
                    removableSort
                    tableStyle="min-width: 50rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} emores fiscales"
                    rowHover
                    :rowClass="rowClass"
                    :pt="dataTablePt"
                >
                    <!-- Empty state -->
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                            <i class="pi pi-building !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed">
                                No se encontraron emisores fiscales. Agrega tu primer RFC para comenzar a facturar.
                            </p>
                        </div>
                    </template>

                    <!-- RFC -->
                    <Column field="rfc" header="RFC" sortable>
                        <template #body="{ data }">
                            <span class="font-mono text-sm font-bold text-gray-900 dark:text-gray-100">
                                {{ data.rfc }}
                            </span>
                        </template>
                    </Column>

                    <!-- Razón social -->
                    <Column field="razon_social" header="Razón social" sortable>
                        <template #body="{ data }">
                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                {{ data.razon_social }}
                            </span>
                        </template>
                    </Column>

                    <!-- Régimen fiscal -->
                    <Column field="regimen_fiscal" header="Régimen fiscal" sortable>
                        <template #body="{ data }">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ data.regimen_fiscal }} - {{ taxRegimeLabel(data.regimen_fiscal) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Estado PAC -->
                    <Column field="is_active" header="Estado" sortable>
                        <template #body="{ data }">
                            <Tag
                                :value="getStatusLabel(data)"
                                :severity="getStatusSeverity(data)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>

                    <!-- Certificados CSD -->
                    <Column header="Certificados CSD" sortable>
                        <template #body="{ data }">
                            <Tag
                                :value="getCsdLabel(data)"
                                :severity="getCsdSeverity(data)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>

                    <!-- Manifiesto (solo subcuentas) -->
                    <Column v-if="showManifestColumn" field="manifest_signed_at" header="Manifiesto" sortable>
                        <template #body="{ data }">
                            <span v-if="!data.requires_manifest" class="text-xs text-gray-400 dark:text-gray-600">—</span>
                            <Tag v-else
                                :value="getManifestLabel(data)"
                                :severity="getManifestSeverity(data)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>


                    <!-- Timbres (Disponibles) -->
                    <Column header="Timbres">
                        <template #body="{ data }">
                            <span
                                v-if="data.stamps_available !== null && data.stamps_available !== undefined"
                                class="font-mono font-light tracking-tight text-lg text-gray-900 dark:text-white"
                            >
                                {{ formatStamps(data.stamps_available) }}
                            </span>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-600 italic">
                                No disponible
                            </span>
                        </template>
                    </Column>

                    <!-- Actions (More menu) -->
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <div class="flex items-center justify-center" @click.stop>
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

        <FiscalProfileFormModal
            ref="fiscalProfileFormModalRef"
            :uses-shared-account="props.usesSharedAccount"
            @success="router.reload()"
        />

        <CsdUploadModal
            ref="csdUploadModalRef"
            @success="router.reload()"
        />

        <!-- ════════════════════════════════════════
             Logo Upload Modal
             ════════════════════════════════════════ -->
        <LogoUploadModal
            v-if="logoProfile"
            :profile="logoProfile"
            :visible="isLogoModalVisible"
            @update:visible="isLogoModalVisible = $event"
            @updated="onLogoUpdated"
        />

        <PurchaseStampsModal
            v-if="selectedProfileForModal"
            ref="purchaseModalRef"
            :fiscalProfileId="selectedProfileForModal.id"
            :ourBankAccounts="ourBankAccounts"
            @success="router.reload()"
        />

        <ManifestWizardModal
            v-if="selectedProfileForModal"
            ref="manifestModalRef"
            :fiscalProfile="selectedProfileForModal"
            :canRetryManifestSigning="selectedProfileForModal.canRetryManifestSigning ?? false"
            @success="router.reload()"
        />

    </AppLayout>
</template>
