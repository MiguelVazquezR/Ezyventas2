<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const emit = defineEmits(['success']);

const visible = ref(false);
// Perfil en edición: null = crear, objeto = editar los datos del emisor.
const profile = ref(null);
const isEdit = computed(() => !!profile.value);
// El RFC solo se puede modificar cuando el perfil aún no tiene certificados CSD.
const rfcLocked = computed(() => isEdit.value && !!profile.value?.certificate_number);

const form = useForm({
    rfc: '',
    razon_social: '',
    regimen_fiscal: '',
    postal_code: '',
    email: '',
});

const taxRegimeOptions = [
    { label: '601 - General de Ley Personas Morales', value: '601' },
    { label: '603 - Personas Morales con Fines no Lucrativos', value: '603' },
    { label: '605 - Sueldos y Salarios', value: '605' },
    { label: '606 - Arrendamiento', value: '606' },
    { label: '608 - Demás ingresos', value: '608' },
    { label: '612 - Personas Físicas con Actividades Empresariales', value: '612' },
    { label: '614 - Ingresos por intereses', value: '614' },
    { label: '616 - Sin obligaciones fiscales', value: '616' },
    { label: '620 - Sociedades Cooperativas', value: '620' },
    { label: '621 - Incorporación Fiscal', value: '621' },
    { label: '622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', value: '622' },
    { label: '626 - Régimen Simplificado de Confianza', value: '626' },
];

function open(profileData = null) {
    profile.value = profileData || null;
    form.clearErrors();

    if (profileData) {
        form.rfc = profileData.rfc || '';
        form.razon_social = profileData.razon_social || '';
        form.regimen_fiscal = profileData.regimen_fiscal || '';
        form.postal_code = profileData.postal_code || '';
        form.email = profileData.email || '';
    } else {
        form.reset();
    }

    visible.value = true;
}

function submit() {
    const options = {
        onSuccess: () => {
            visible.value = false;
            form.reset();
            profile.value = null;
            emit('success');
        },
    };

    if (isEdit.value) {
        form.put(route('billing.settings.updateFiscalProfile', profile.value.id), options);
    } else {
        form.post(route('billing.settings.storeFiscalProfile'), options);
    }
}

defineExpose({ open });

const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#1a1a1a] !transition-colors !rounded-full !w-8 !h-8 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};

const selectPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
};
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        class="w-full max-w-lg mx-4"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-4 mb-0">
                <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 text-primary-500 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i class="pi pi-building !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                        {{ isEdit ? 'Editar emisor fiscal' : 'Agregar emisor fiscal' }}
                    </h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        {{ isEdit ? 'Actualiza los datos del RFC para facturación' : 'Nueva razón social para facturación' }}
                    </p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="flex flex-col gap-5 pt-2">
            <!-- ═══════════════ PASO 1: Info banner (solo al crear) ═══════════════ -->
            <div v-if="!isEdit" class="bg-blue-50/50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                <div class="flex items-start gap-3">
                    <i class="pi pi-info-circle !text-sm text-blue-500 mt-0.5"></i>
                    <div>
                        <p class="text-[12px] font-medium text-blue-700 dark:text-blue-400 m-0 mb-0.5">
                            Paso 1 de 2: Vinculación fiscal
                        </p>
                        <p class="text-[12px] text-blue-600/90 dark:text-blue-400/70 m-0 leading-relaxed">
                            Registra tus datos fiscales básicos para conectar tu RFC con nuestro sistema. En el siguiente paso subirás tus Certificados (CSD) para comenzar a emitir facturas.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ RFC + Código Postal (same row) ═══════════════ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC *</label>
                    <div class="relative">
                        <i class="pi pi-id-card !absolute !left-3.5 !top-1/2 !-translate-y-1/2 !text-xs text-gray-400 dark:text-gray-500 pointer-events-none"></i>
                        <InputText
                            v-model="form.rfc"
                            placeholder="XAXX010101000"
                            class="w-full"
                            :class="{ '!border-red-500': form.errors.rfc }"
                            maxlength="13"
                            :disabled="rfcLocked"
                            :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !pl-9 !text-sm !placeholder:text-gray-400 dark:!placeholder:text-gray-500 disabled:!opacity-60 disabled:!cursor-not-allowed' } }"
                        />
                    </div>
                    <Message v-if="form.errors.rfc" severity="error" variant="simple" size="small">
                        {{ form.errors.rfc }}
                    </Message>
                    <Message v-if="rfcLocked" severity="info" variant="simple" size="small">
                        No se puede modificar el RFC porque tiene CSD cargados.
                    </Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Código postal *</label>
                    <div class="relative">
                        <i class="pi pi-map-marker !absolute !left-3.5 !top-1/2 !-translate-y-1/2 !text-xs text-gray-400 dark:text-gray-500 pointer-events-none"></i>
                        <InputText
                            v-model="form.postal_code"
                            placeholder="44600"
                            class="w-full"
                            :class="{ '!border-red-500': form.errors.postal_code }"
                            maxlength="5"
                            :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !pl-9 !text-sm !placeholder:text-gray-400 dark:!placeholder:text-gray-500' } }"
                        />
                    </div>
                    <Message v-if="form.errors.postal_code" severity="error" variant="simple" size="small">
                        {{ form.errors.postal_code }}
                    </Message>
                </div>
            </div>

            <!-- ═══════════════ Razón social ═══════════════ -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social *</label>
                <div class="relative">
                    <i class="pi pi-building !absolute !left-3.5 !top-1/2 !-translate-y-1/2 !text-xs text-gray-400 dark:text-gray-500 pointer-events-none"></i>
                    <InputText
                        v-model="form.razon_social"
                        placeholder="Nombre o razón social del emisor"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.razon_social }"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !pl-9 !text-sm !placeholder:text-gray-400 dark:!placeholder:text-gray-500' } }"
                    />
                </div>
                <Message v-if="form.errors.razon_social" severity="error" variant="simple" size="small">
                    {{ form.errors.razon_social }}
                </Message>
            </div>

            <!-- ═══════════════ Régimen fiscal ═══════════════ -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal *</label>
                <div class="relative">
                    <i class="pi pi-tag !absolute !left-3.5 !top-1/2 !-translate-y-1/2 !text-xs text-gray-400 dark:text-gray-500 pointer-events-none z-10"></i>
                    <Select
                        v-model="form.regimen_fiscal"
                        :options="taxRegimeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Selecciona el régimen fiscal"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.regimen_fiscal }"
                        :pt="{
                            ...selectPt,
                            root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !pl-9' },
                        }"
                    />
                </div>
                <Message v-if="form.errors.regimen_fiscal" severity="error" variant="simple" size="small">
                    {{ form.errors.regimen_fiscal }}
                </Message>
            </div>

            <!-- ═══════════════ Email ═══════════════ -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Email de contacto fiscal *</label>
                <div class="relative">
                    <i class="pi pi-envelope !absolute !left-3.5 !top-1/2 !-translate-y-1/2 !text-xs text-gray-400 dark:text-gray-500 pointer-events-none"></i>
                    <InputText
                        v-model="form.email"
                        type="email"
                        placeholder="facturacion@empresa.com"
                        class="w-full"
                        :class="{ '!border-red-500': form.errors.email }"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !pl-9 !text-sm !placeholder:text-gray-400 dark:!placeholder:text-gray-500' } }"
                    />
                </div>
                <Message v-if="form.errors.email" severity="error" variant="simple" size="small">
                    {{ form.errors.email }}
                </Message>
            </div>
        </form>

        <template #footer>
            <div class="flex justify-end items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cancelar"
                    text
                    @click="visible = false"
                    :disabled="form.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold !text-gray-500 hover:!text-gray-700 dark:!text-gray-400 dark:hover:!text-gray-200 !transition-colors"
                />
                <Button
                    :label="isEdit ? 'Actualizar datos' : 'Guardar emisor'"
                    icon="pi pi-save"
                    @click="submit"
                    :loading="form.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold !px-6 !shadow-lg !shadow-primary-500/20 hover:!shadow-primary-500/30 !transition-all !duration-200 hover:!scale-[1.02] active:!scale-[0.98]"
                    severity="primary"
                />
            </div>
        </template>
    </Dialog>
</template>
