<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    accounts: Object,
    filters: Object,
    statuses: Array,
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

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' },
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
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
};

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Cuentas PAC' },
]);

// ──────────────────────────────────────
// Status helpers
// ──────────────────────────────────────
function statusLabel(status) {
    const map = {
        'pending_request': 'Pendiente de solicitud',
        'pending_activation': 'Pendiente de activación',
        'active': 'Activa',
        'inactive': 'Inactiva',
    };
    return map[status] || status;
}

function statusSeverity(status) {
    const map = {
        'pending_request': 'warn',
        'pending_activation': 'info',
        'active': 'success',
        'inactive': 'secondary',
    };
    return map[status] || 'secondary';
}

function accountTypeLabel(type) {
    const map = {
        'subaccount': 'Subcuenta · Legacy',
        'shared': 'Cuenta compartida',
    };
    return map[type] || type;
}

function formatDate(date) {
    if (!date) return '—';
    return new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

// ──────────────────────────────────────
// Filters & pagination
// ──────────────────────────────────────
function changeStatus(value) {
    router.get(route('admin.pac-accounts.index'), {
        status: value || undefined,
    }, { preserveState: true, replace: true });
}

const onPage = (event) => {
    router.get(route('admin.pac-accounts.index'), {
        page: event.page + 1,
        rows: event.rows,
        status: props.filters?.status || undefined,
    }, { preserveState: true });
};

// ──────────────────────────────────────
// Activation dialog
// ──────────────────────────────────────
const activationDialog = ref(false);
const activeAccount = ref(null);
const activationForm = ref({ login_email: '', password: '' });
const activationProcessing = ref(false);

function openActivation(account) {
    activeAccount.value = account;
    activationForm.value = { login_email: account.login_email || '', password: '' };
    activationDialog.value = true;
}

function submitActivation() {
    activationProcessing.value = true;

    const isActiveAccount = activeAccount.value?.status === 'active';
    const url = isActiveAccount
        ? route('admin.pac-accounts.credentials', activeAccount.value.id)
        : route('admin.pac-accounts.activate', activeAccount.value.id);

    const payload = {
        login_email: activationForm.value.login_email,
        password: activationForm.value.password,
    };

    const options = {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            activationDialog.value = false;
            activeAccount.value = null;
            activationProcessing.value = false;
        },
        onError: () => {
            activationProcessing.value = false;
        },
    };

    // Llamar directamente al método del router: extraerlo a una variable
    // (const method = router.post) pierde el contexto `this` y en Inertia v2
    // los métodos usan `this.visit()` internamente, causando
    // "Cannot read properties of undefined (reading 'visit')".
    if (isActiveAccount) {
        router.put(url, payload, options);
    } else {
        router.post(url, payload, options);
    }
}

// ──────────────────────────────────────
// Notes dialog
// ──────────────────────────────────────
const notesDialog = ref(false);
const notesAccount = ref(null);
const notesText = ref('');

function openNotes(account) {
    notesAccount.value = account;
    notesText.value = account.admin_notes || '';
    notesDialog.value = true;
}

function submitNotes() {
    router.put(
        route('admin.pac-accounts.notes', notesAccount.value.id),
        { admin_notes: notesText.value },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                notesDialog.value = false;
                notesAccount.value = null;
            },
        }
    );
}

// ──────────────────────────────────────
// Deactivate
// ──────────────────────────────────────
function deactivate(account) {
    router.post(route('admin.pac-accounts.deactivate', account.id), {}, {
        preserveScroll: true,
        preserveState: false,
    });
}
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-6xl mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Cuentas PAC
                    </h1>
                    <p class="text-sm text-gray-500 mt-1 m-0">
                        Solicitudes y activación de cuentas externas coordinadas con el revendedor
                    </p>
                </div>
                <Select
                    :model-value="filters?.status || null"
                    :options="statuses"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Todas las cuentas"
                    showClear
                    class="w-56"
                    :pt="inputPt"
                    @update:model-value="changeStatus"
                />
            </div>

            <!-- ── Accounts Table ────────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <DataTable
                    :value="accounts.data"
                    lazy
                    paginator
                    :totalRecords="accounts.total"
                    :rows="accounts.per_page || 25"
                    :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id"
                    rowHover
                    tableStyle="min-width: 70rem"
                    @page="onPage"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cuentas"
                    :pt="dataTablePt"
                >
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                            <i class="pi pi-inbox !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed">
                                No hay cuentas PAC que coincidan con los filtros.
                            </p>
                        </div>
                    </template>

                    <Column header="Cuenta">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <span class="font-medium text-gray-900 dark:text-gray-100">#{{ data.id }}</span>
                                <Tag
                                    :value="accountTypeLabel(data.account_type)"
                                    severity="secondary"
                                    :pt="tagPt"
                                />
                            </div>
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag
                                :value="statusLabel(data.status)"
                                :severity="statusSeverity(data.status)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>
                    <Column header="Suscriptor">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <Link
                                    :href="route('admin.subscriptions.show', data.subscription_id)"
                                    class="font-medium text-primary-500 hover:underline text-sm"
                                >
                                    {{ data.subscription?.commercial_name ?? '—' }}
                                </Link>
                                <span class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500">ID: {{ data.subscription_id }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column header="RFCs vinculados">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <template v-if="data.fiscal_profiles?.length">
                                    <span
                                        v-for="fp in data.fiscal_profiles"
                                        :key="fp.id"
                                        class="text-xs text-gray-600 dark:text-gray-300"
                                    >
                                        {{ fp.rfc }} <span class="text-gray-400 dark:text-gray-500">· {{ fp.razon_social }}</span>
                                    </span>
                                </template>
                                <span v-else class="text-xs text-gray-400">Sin perfiles vinculados</span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Login">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-300">
                                {{ data.login_email ?? '—' }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Solicitada">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(data.requested_at) }}</span>
                        </template>
                    </Column>
                    <Column header="Acciones" headerStyle="width: 11rem; text-align: center">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1 justify-center">
                                <template v-if="data.status !== 'active' && data.account_type === 'shared'">
                                    <Button
                                        icon="pi pi-key"
                                        label="Activar"
                                        size="small"
                                        class="!rounded-full !text-[10px] !uppercase !tracking-wider"
                                        @click="openActivation(data)"
                                    />
                                </template>
                                <template v-else-if="data.account_type === 'shared'">
                                    <Button
                                        icon="pi pi-key"
                                        severity="secondary"
                                        size="small"
                                        class="!rounded-full !w-8 !h-8"
                                        v-tooltip.top="'Actualizar credenciales'"
                                        @click="openActivation(data)"
                                    />
                                </template>
                                <Button
                                    icon="pi pi-pencil"
                                    severity="secondary"
                                    size="small"
                                    class="!rounded-full !w-8 !h-8"
                                    v-tooltip.top="'Notas'"
                                    @click="openNotes(data)"
                                />
                                <template v-if="data.status === 'active' && data.account_type === 'shared'">
                                    <Button
                                        icon="pi pi-ban"
                                        severity="danger"
                                        size="small"
                                        class="!rounded-full !w-8 !h-8"
                                        v-tooltip.top="'Desactivar'"
                                        @click="deactivate(data)"
                                    />
                                </template>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <!-- ── Activation / credentials dialog ────────── -->
            <Dialog
                v-model:visible="activationDialog"
                modal
                :header="activeAccount?.status === 'active' ? 'Actualizar credenciales' : 'Activar cuenta'"
                :style="{ width: '28rem' }"
                :pt="dialogPt"
            >
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Ingresa las credenciales que entregó el revendedor. Se validarán contra el Proveedor de timbrado antes de guardar.
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Correo de la cuenta *</label>
                        <InputText
                            v-model="activationForm.login_email"
                            type="email"
                            class="w-full"
                            :pt="inputPt"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Contraseña *</label>
                        <Password
                            v-model="activationForm.password"
                            :feedback="false"
                            toggleMask
                            class="w-full"
                            :pt="inputPt"
                        />
                    </div>

                    <div class="flex justify-end gap-2 mt-2">
                        <Button
                            label="Cancelar"
                            severity="secondary"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="activationDialog = false"
                        />
                        <Button
                            label="Guardar"
                            :loading="activationProcessing"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="submitActivation"
                        />
                    </div>
                </div>
            </Dialog>

            <!-- ── Notes dialog ────────────────────────────── -->
            <Dialog
                v-model:visible="notesDialog"
                modal
                header="Notas de coordinación"
                :style="{ width: '30rem' }"
                :pt="dialogPt"
            >
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Bitácora de coordinación con el revendedor (Conectia) para esta cuenta.
                    </p>

                    <Textarea
                        v-model="notesText"
                        rows="5"
                        class="w-full"
                        :pt="textareaPt"
                    />

                    <div class="flex justify-end gap-2 mt-2">
                        <Button
                            label="Cancelar"
                            severity="secondary"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="notesDialog = false"
                        />
                        <Button
                            label="Guardar notas"
                            class="!rounded-full !text-xs !uppercase !tracking-wider"
                            @click="submitNotes"
                        />
                    </div>
                </div>
            </Dialog>
        </div>
    </AppLayout>
</template>
