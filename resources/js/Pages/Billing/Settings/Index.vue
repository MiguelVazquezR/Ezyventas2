<script setup>
import { ref } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';

// ──────────────────────────────────────
// Props
// ──────────────────────────────────────
const props = defineProps({
    fiscalProfiles: {
        type: Array,
        default: () => [],
    },
});

// ──────────────────────────────────────
// Dialog visibility
// ──────────────────────────────────────
const isDialogVisible = ref(false);
const isCsdDialogVisible = ref(false);

// ──────────────────────────────────────
// CSD dialog state
// ──────────────────────────────────────
const selectedProfile = ref(null);
const isUpdatingCsd = ref(false);

// ──────────────────────────────────────
// Inertia form for new fiscal profile
// ──────────────────────────────────────
const form = useForm({
    rfc: '',
    razon_social: '',
    regimen_fiscal: '',
    postal_code: '',
    email: '',
});

// ──────────────────────────────────────
// Inertia form for CSD upload
// ──────────────────────────────────────
const csdForm = useForm({
    fiscal_profile_id: null,
    cer: null,
    key: null,
    password: '',
});

// ──────────────────────────────────────
// SAT tax regime options
// ──────────────────────────────────────
const taxRegimeOptions = [
    { label: '601 — General de Ley Personas Morales', value: '601' },
    { label: '603 — Personas Morales con Fines no Lucrativos', value: '603' },
    { label: '605 — Sueldos y Salarios', value: '605' },
    { label: '606 — Arrendamiento', value: '606' },
    { label: '608 — Demás ingresos', value: '608' },
    { label: '612 — Personas Físicas con Actividades Empresariales', value: '612' },
    { label: '614 — Ingresos por intereses', value: '614' },
    { label: '616 — Sin obligaciones fiscales', value: '616' },
    { label: '620 — Sociedades Cooperativas', value: '620' },
    { label: '621 — Incorporación Fiscal', value: '621' },
    { label: '622 — Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', value: '622' },
    { label: '626 — Régimen Simplificado de Confianza', value: '626' },
];

// ──────────────────────────────────────
// Status helpers
// ──────────────────────────────────────
const getStatusSeverity = (profile) => {
    if (!profile.is_active) return 'secondary';
    if (profile.sw_user_id) return 'success';
    return 'warn';
};

const getStatusLabel = (profile) => {
    if (!profile.is_active) return 'Inactivo';
    if (profile.sw_user_id) return 'Activo';
    return 'Pendiente PAC';
};

const openNewDialog = () => {
    form.reset();
    form.clearErrors();
    isDialogVisible.value = true;
};

const submit = () => {
    form.post(route('billing.settings.storeFiscalProfile'), {
        onSuccess: () => {
            isDialogVisible.value = false;
            form.reset();
        },
    });
};

const openCsdDialog = (profile) => {
    csdForm.reset();
    csdForm.clearErrors();
    csdForm.fiscal_profile_id = profile.id;
    selectedProfile.value = profile;
    isUpdatingCsd.value = !profile.certificate_number;
    isCsdDialogVisible.value = true;
};

const confirm = useConfirm();

const submitCsd = () => {
    csdForm.post(route('billing.settings.uploadCsd'), {
        onSuccess: () => {
            isCsdDialogVisible.value = false;
            csdForm.reset();
        },
    });
};

const confirmDeleteProfile = (profile) => {
    confirm.require({
        message: '¿Deseas dar de baja este perfil fiscal? Esta acción desactivará la cuenta en el PAC. Tus facturas anteriores e historial permanecerán intactos.',
        header: 'Baja de perfil fiscal',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Dar de baja',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('billing.settings.destroyFiscalProfile', profile.id));
        },
    });
};

// ──────────────────────────────────────
// Tesla UI PT configurations
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
    <AppLayout title="Perfiles fiscales">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto space-y-6">

            <!-- ════════════════════════════════════════
                 Header
                 ════════════════════════════════════════ -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Perfiles fiscales
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                            Emisores CFDI 4.0
                        </p>
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                            {{ fiscalProfiles.length }} {{ fiscalProfiles.length === 1 ? 'perfil' : 'perfiles' }} registrados
                        </span>
                    </div>
                </div>
                <div class="flex gap-2 w-full md:w-auto">
                    <Link
                        :href="route('billing.invoices.index')"
                        class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors no-underline"
                    >
                        <i class="pi pi-arrow-left !text-[10px]"></i>
                        Historial
                    </Link>
                    <Button
                        type="button"
                        label="Agregar perfil fiscal"
                        icon="pi pi-plus"
                        @click="openNewDialog"
                        class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold"
                    />
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 Fiscal profiles table / empty state
                 ════════════════════════════════════════ -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <!-- Empty state -->
                <div
                    v-if="fiscalProfiles.length === 0"
                    class="flex flex-col items-center justify-center py-16 text-center"
                >
                    <div class="w-16 h-16 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center mb-4 border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-building !text-2xl text-gray-400"></i>
                    </div>
                    <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0 mb-2">
                        Sin perfiles fiscales
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0 max-w-sm">
                        Agrega tu primer RFC para comenzar a facturar. Puedes registrar múltiples razones sociales desde una misma cuenta.
                    </p>
                    <Button
                        type="button"
                        label="Agregar perfil fiscal"
                        icon="pi pi-plus"
                        @click="openNewDialog"
                        severity="secondary"
                        outlined
                        class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold mt-6"
                    />
                </div>

                <!-- DataTable -->
                <DataTable
                    v-else
                    :value="fiscalProfiles"
                    stripedRows
                    class="!border-none !bg-transparent"
                    :pt="{
                        root: { class: '!bg-transparent !border-none' },
                        header: { class: '!bg-transparent !border-none !p-0 !mb-4' },
                        table: { class: '!border-collapse' },
                        thead: { class: '!bg-transparent' },
                        th: { class: '!bg-gray-50 dark:!bg-[#1a1a1a] !border-none !py-3 !px-5 !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-500 first:!rounded-l-2xl last:!rounded-r-2xl' },
                        td: { class: '!border-b !border-gray-50 dark:!border-[#2a2a2a] !py-4 !px-5 !text-sm !text-gray-900 dark:!text-gray-100' },
                        tbody: { class: '!border-none' },
                    }"
                >
                    <Column field="rfc" header="RFC">
                        <template #body="{ data }">
                            <span class="font-mono text-sm font-medium text-gray-900 dark:text-white">{{ data.rfc }}</span>
                        </template>
                    </Column>
                    <Column field="razon_social" header="Razón social">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.razon_social }}</span>
                        </template>
                    </Column>
                    <Column field="regimen_fiscal" header="Régimen fiscal">
                        <template #body="{ data }">
                            <span class="text-gray-500 dark:text-gray-400">{{ data.regimen_fiscal }}</span>
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag
                                :value="getStatusLabel(data)"
                                :severity="getStatusSeverity(data)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>
                    <Column header="Acciones" style="width:120px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1">
                                <!-- CSD key button -->
                                <Button
                                    icon="pi pi-key"
                                    text
                                    rounded
                                    :disabled="!data.sw_user_id"
                                    v-tooltip.top="data.certificate_number ? 'Ver o actualizar certificado CSD' : (data.sw_user_id ? 'Cargar certificados CSD' : 'Aprovisiona la subcuenta en el PAC primero')"
                                    @click="openCsdDialog(data)"
                                    :class="data.certificate_number
                                        ? '!bg-amber-50 dark:!bg-amber-900/20 !text-amber-600 dark:!text-amber-400 !border !border-amber-200 dark:!border-amber-800/50 hover:!bg-amber-100 dark:hover:!bg-amber-900/40'
                                        : '!text-gray-400 hover:!text-primary-500 dark:hover:!text-primary-400 !transition-colors'"
                                    :pt="{
                                        root: { class: '!w-9 !h-9' },
                                    }"
                                />
                                <!-- Delete / deactivate button -->
                                <Button
                                    icon="pi pi-trash"
                                    text
                                    rounded
                                    v-tooltip.top="'Dar de baja perfil fiscal'"
                                    @click="confirmDeleteProfile(data)"
                                    class="!text-gray-400 hover:!text-red-500 dark:hover:!text-red-400 hover:!bg-red-50 dark:hover:!bg-red-900/20 !transition-colors"
                                    :pt="{
                                        root: { class: '!w-9 !h-9' },
                                    }"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <!-- ════════════════════════════════════════
                 PAC provider info
                 ════════════════════════════════════════ -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                        <i class="pi pi-check-circle !text-sm text-green-500"></i>
                    </div>
                    <div>
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">
                            Proveedor de timbrado
                        </h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400 m-0 mt-1">
                            SW Smarter Web — Token infinito configurado
                        </p>
                    </div>
                </div>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 m-0 mt-4 ml-14">
                    Cada perfil fiscal se aprovisiona automáticamente como subcuenta en el PAC al momento de crearlo.
                </p>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             Dialog: Agregar perfil fiscal
             ════════════════════════════════════════ -->
        <Dialog
            v-model:visible="isDialogVisible"
            modal
            class="w-full max-w-lg mx-4"
            :pt="dialogPt"
        >
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 text-primary-500 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                        <i class="pi pi-building !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                            Agregar perfil fiscal
                        </h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                            Nueva razón social para facturación
                        </p>
                    </div>
                </div>
            </template>

            <form @submit.prevent="submit" class="flex flex-col gap-5 pt-2">
                <!-- RFC -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        RFC *
                    </label>
                    <InputText
                        v-model="form.rfc"
                        placeholder="Ej. XAXX010101000"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.rfc }"
                        maxlength="13"
                        :pt="{
                            root: {
                                class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm',
                            },
                        }"
                    />
                    <Message v-if="form.errors.rfc" severity="error" variant="simple" size="small">
                        {{ form.errors.rfc }}
                    </Message>
                </div>

                <!-- Razón social -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Razón social *
                    </label>
                    <InputText
                        v-model="form.razon_social"
                        placeholder="Nombre o razón social del emisor"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.razon_social }"
                        :pt="{
                            root: {
                                class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm',
                            },
                        }"
                    />
                    <Message v-if="form.errors.razon_social" severity="error" variant="simple" size="small">
                        {{ form.errors.razon_social }}
                    </Message>
                </div>

                <!-- Régimen fiscal -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Régimen fiscal *
                    </label>
                    <Select
                        v-model="form.regimen_fiscal"
                        :options="taxRegimeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Selecciona el régimen fiscal"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.regimen_fiscal }"
                        :pt="{
                            root: {
                                class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !text-sm',
                            },
                        }"
                    />
                    <Message v-if="form.errors.regimen_fiscal" severity="error" variant="simple" size="small">
                        {{ form.errors.regimen_fiscal }}
                    </Message>
                </div>

                <!-- Código postal fiscal -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Código postal fiscal *
                    </label>
                    <InputText
                        v-model="form.postal_code"
                        placeholder="Ej. 44600"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.postal_code }"
                        maxlength="5"
                        :pt="{
                            root: {
                                class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm',
                            },
                        }"
                    />
                    <Message v-if="form.errors.postal_code" severity="error" variant="simple" size="small">
                        {{ form.errors.postal_code }}
                    </Message>
                </div>

                <!-- Email de contacto fiscal -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Email de contacto fiscal *
                    </label>
                    <InputText
                        v-model="form.email"
                        type="email"
                        placeholder="Ej. facturacion@empresa.com"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.email }"
                        :pt="{
                            root: {
                                class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm',
                            },
                        }"
                    />
                    <Message v-if="form.errors.email" severity="error" variant="simple" size="small">
                        {{ form.errors.email }}
                    </Message>
                </div>

                <!-- Info sobre aprovisionamiento automático -->
                <div class="bg-blue-50/50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                    <div class="flex items-start gap-3">
                        <i class="pi pi-info-circle !text-sm text-blue-500 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-medium text-blue-700 dark:text-blue-400 m-0 mb-0.5">
                                Aprovisionamiento automático
                            </p>
                            <p class="text-[10px] text-blue-600/70 dark:text-blue-400/70 m-0 leading-relaxed">
                                Al guardar, se creará automáticamente una subcuenta en SW Smarter Web para este RFC.
                                El estado cambiará a "Activo" cuando el PAC confirme el registro.
                            </p>
                        </div>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex justify-end items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button
                        label="Cancelar"
                        text
                        @click="isDialogVisible = false"
                        :disabled="form.processing"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                    />
                    <Button
                        label="Guardar perfil"
                        icon="pi pi-save"
                        @click="submit"
                        :loading="form.processing"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                        severity="primary"
                    />
                </div>
            </template>
        </Dialog>

        <!-- ════════════════════════════════════════
             Dialog: Certificado (CSD)
             ════════════════════════════════════════ -->
        <Dialog
            v-model:visible="isCsdDialogVisible"
            modal
            class="w-full max-w-lg mx-4"
            :pt="dialogPt"
            @hide="isUpdatingCsd = false"
        >
            <template #header>
                <div class="flex items-center gap-4">
                    <div
                        class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 border"
                        :class="selectedProfile?.certificate_number && !isUpdatingCsd
                            ? 'bg-green-50 dark:bg-green-900/20 text-green-500 border-green-100 dark:border-green-900/30'
                            : 'bg-amber-50 dark:bg-amber-900/20 text-amber-500 border-amber-100 dark:border-amber-900/30'"
                    >
                        <i class="pi text-sm" :class="selectedProfile?.certificate_number && !isUpdatingCsd ? 'pi-check-circle' : 'pi-key'"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                            {{ selectedProfile?.certificate_number && !isUpdatingCsd ? 'Certificado activo' : 'Cargar Certificado (CSD)' }}
                        </h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                            {{ selectedProfile?.certificate_number && !isUpdatingCsd ? 'Datos del CSD registrado' : 'Archivos .cer y .key del SAT' }}
                        </p>
                    </div>
                </div>
            </template>

            <!-- ── MODO LECTURA: certificado ya registrado ── -->
            <div v-if="selectedProfile?.certificate_number && !isUpdatingCsd" class="flex flex-col gap-5 pt-2">
                <div class="flex items-center gap-3 mb-2">
                    <Tag value="Certificado activo" severity="success" :pt="tagPt" />
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                </div>

                <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 space-y-4 border border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Número de serie</span>
                        <span class="text-sm font-mono text-gray-900 dark:text-white break-all">{{ selectedProfile.certificate_number }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Vigencia</span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ selectedProfile.valid_from }} — {{ selectedProfile.valid_to }}</span>
                    </div>
                </div>
            </div>

            <!-- ── MODO CARGA: formulario de subida ── -->
            <form v-if="!selectedProfile?.certificate_number || isUpdatingCsd" @submit.prevent="submitCsd" class="flex flex-col gap-5 pt-2">
                <!-- Archivo .cer -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Certificado (.cer) *
                    </label>
                    <input
                        type="file"
                        accept=".cer"
                        @input="csdForm.cer = $event.target.files[0]"
                        class="w-full text-sm text-gray-600 dark:text-gray-400
                               file:mr-4 file:py-2.5 file:px-5
                               file:rounded-xl file:border-0
                               file:text-[10px] file:uppercase file:tracking-widest file:font-bold
                               file:bg-gray-100 dark:file:bg-[#1a1a1a]
                               file:text-gray-700 dark:file:text-gray-300
                               hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20
                               file:transition-colors file:cursor-pointer"
                    />
                    <Message v-if="csdForm.errors.cer" severity="error" variant="simple" size="small">
                        {{ csdForm.errors.cer }}
                    </Message>
                </div>

                <!-- Archivo .key -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Llave privada (.key) *
                    </label>
                    <input
                        type="file"
                        accept=".key"
                        @input="csdForm.key = $event.target.files[0]"
                        class="w-full text-sm text-gray-600 dark:text-gray-400
                               file:mr-4 file:py-2.5 file:px-5
                               file:rounded-xl file:border-0
                               file:text-[10px] file:uppercase file:tracking-widest file:font-bold
                               file:bg-gray-100 dark:file:bg-[#1a1a1a]
                               file:text-gray-700 dark:file:text-gray-300
                               hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20
                               file:transition-colors file:cursor-pointer"
                    />
                    <Message v-if="csdForm.errors.key" severity="error" variant="simple" size="small">
                        {{ csdForm.errors.key }}
                    </Message>
                </div>

                <!-- Contraseña del CSD -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Contraseña del CSD *
                    </label>
                    <InputText
                        v-model="csdForm.password"
                        type="password"
                        toggleMask
                        placeholder="Contraseña de la llave privada"
                        class="w-full"
                        :class="{ '!border-red-500': csdForm.errors.password }"
                        :pt="{
                            root: {
                                class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm',
                            },
                        }"
                    />
                    <Message v-if="csdForm.errors.password" severity="error" variant="simple" size="small">
                        {{ csdForm.errors.password }}
                    </Message>
                </div>

                <!-- Info sobre CSD -->
                <div class="bg-amber-50/50 dark:bg-amber-900/10 p-4 rounded-2xl border border-amber-100 dark:border-amber-900/30">
                    <div class="flex items-start gap-3">
                        <i class="pi pi-shield !text-sm text-amber-500 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-medium text-amber-700 dark:text-amber-400 m-0 mb-0.5">
                                Certificados oficiales del SAT
                            </p>
                            <p class="text-[10px] text-amber-600/70 dark:text-amber-400/70 m-0 leading-relaxed">
                                Los archivos .cer y .key son emitidos por el SAT. Se enviarán de forma segura al PAC
                                para configurar el timbrado de este RFC.
                            </p>
                        </div>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex justify-end items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button
                        v-if="selectedProfile?.certificate_number && !isUpdatingCsd"
                        label="Actualizar certificado"
                        icon="pi pi-refresh"
                        text
                        @click="isUpdatingCsd = true"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                    />
                    <Button
                        v-if="!selectedProfile?.certificate_number || isUpdatingCsd"
                        label="Cancelar"
                        text
                        @click="selectedProfile?.certificate_number ? isUpdatingCsd = false : isCsdDialogVisible = false"
                        :disabled="csdForm.processing"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                    />
                    <Button
                        v-if="!selectedProfile?.certificate_number || isUpdatingCsd"
                        label="Cargar certificados"
                        icon="pi pi-cloud-upload"
                        @click="submitCsd"
                        :loading="csdForm.processing"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                        severity="primary"
                    />
                    <Button
                        v-if="selectedProfile?.certificate_number && !isUpdatingCsd"
                        label="Cerrar"
                        text
                        @click="isCsdDialogVisible = false"
                        class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                    />
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
