<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const emit = defineEmits(['success']);

const visible = ref(false);
const isUpdating = ref(false);
const profile = ref(null);

const csdForm = useForm({
    fiscal_profile_id: null,
    cer: null,
    key: null,
    password: '',
});

function open(p) {
    profile.value = p;
    csdForm.reset();
    csdForm.clearErrors();
    csdForm.fiscal_profile_id = p.id;
    isUpdating.value = !p.certificate_number;
    visible.value = true;
}

function submitCsd() {
    csdForm.post(route('billing.settings.uploadCsd'), {
        onSuccess: () => {
            visible.value = false;
            csdForm.reset();
            emit('success');
        },
    });
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

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        class="w-full max-w-lg mx-4"
        :pt="dialogPt"
        @hide="isUpdating = false"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div
                    class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 border"
                    :class="profile?.certificate_number && !isUpdating
                        ? 'bg-green-50 dark:bg-green-900/20 text-green-500 border-green-100 dark:border-green-900/30'
                        : 'bg-amber-50 dark:bg-amber-900/20 text-amber-500 border-amber-100 dark:border-amber-900/30'"
                >
                    <i class="pi text-sm" :class="profile?.certificate_number && !isUpdating ? 'pi-check-circle' : 'pi-key'"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                        {{ profile?.certificate_number && !isUpdating ? 'Certificado activo' : 'Cargar certificado (CSD)' }}
                    </h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        {{ profile?.certificate_number && !isUpdating ? 'Datos del CSD registrado' : 'Archivos .cer y .key del SAT' }}
                    </p>
                </div>
            </div>
        </template>

        <!-- Read mode -->
        <div v-if="profile?.certificate_number && !isUpdating" class="flex flex-col gap-5 pt-2">
            <div class="flex items-center gap-3 mb-2">
                <Tag value="Certificado activo" severity="success" :pt="tagPt" />
                <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
            </div>
            <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 space-y-4 border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Número de serie</span>
                    <span class="text-sm font-mono text-gray-900 dark:text-white break-all">{{ profile.certificate_number }}</span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Vigencia</span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ profile.valid_from }} — {{ profile.valid_to }}</span>
                </div>
            </div>
        </div>

        <!-- Upload mode -->
        <form v-if="!profile?.certificate_number || isUpdating" @submit.prevent="submitCsd" class="flex flex-col gap-5 pt-2">
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Certificado (.cer) *</label>
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
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Llave privada (.key) *</label>
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
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Contraseña del CSD *</label>
                <InputText
                    v-model="csdForm.password"
                    type="password"
                    toggleMask
                    placeholder="Contraseña de la llave privada"
                    class="w-full"
                    :class="{ '!border-red-500': csdForm.errors.password }"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm' } }"
                />
                <Message v-if="csdForm.errors.password" severity="error" variant="simple" size="small">
                    {{ csdForm.errors.password }}
                </Message>
            </div>
            <div class="bg-amber-50/50 dark:bg-amber-900/10 p-4 rounded-2xl border border-amber-100 dark:border-amber-900/30">
                <div class="flex items-start gap-3">
                    <i class="pi pi-shield !text-sm text-amber-500 mt-0.5"></i>
                    <div>
                        <p class="text-[12px] font-medium text-amber-700 dark:text-amber-400 m-0 mb-0.5">Certificados oficiales del SAT</p>
                        <p class="text-[12px] text-amber-600/90 dark:text-amber-400/70 m-0 leading-relaxed">
                            Los archivos .cer y .key son emitidos por el SAT. Se enviarán de forma segura al PAC (Proveedor de Timbrado). Estos archivos se requieren para la generación de comprobantes fiscales.
                        </p>
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <div class="flex justify-end items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    v-if="profile?.certificate_number && !isUpdating"
                    label="Actualizar certificado"
                    icon="pi pi-refresh"
                    text
                    @click="isUpdating = true"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
                <Button
                    v-if="!profile?.certificate_number || isUpdating"
                    label="Cancelar"
                    text
                    @click="profile?.certificate_number ? isUpdating = false : visible = false"
                    :disabled="csdForm.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
                <Button
                    v-if="!profile?.certificate_number || isUpdating"
                    label="Cargar certificados"
                    icon="pi pi-cloud-upload"
                    @click="submitCsd"
                    :loading="csdForm.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                    severity="primary"
                />
                <Button
                    v-if="profile?.certificate_number && !isUpdating"
                    label="Cerrar"
                    text
                    @click="visible = false"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
            </div>
        </template>
    </Dialog>
</template>
