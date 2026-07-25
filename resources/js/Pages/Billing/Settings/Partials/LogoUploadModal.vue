<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    profile: { type: Object, required: true },
    visible: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'updated']);

// ──────────────────────────────────────
// Local state
// ──────────────────────────────────────
const previewUrl = ref(props.profile?.logo_url || null);
const fileInput = ref(null);

// ──────────────────────────────────────
// Inertia form for logo upload
// ──────────────────────────────────────
const form = useForm({
    logo: null,
});

// ──────────────────────────────────────
// Sync preview when profile changes
// ──────────────────────────────────────
watch(() => props.profile?.logo_url, (url) => {
    previewUrl.value = url || null;
});

// ──────────────────────────────────────
// File selection handler
// ──────────────────────────────────────
const onFileSelect = (event) => {
    const file = event.files?.[0] || event.target?.files?.[0];
    if (!file) return;

    form.logo = file;
    form.clearErrors();

    // Generate local preview
    const reader = new FileReader();
    reader.onload = (e) => { previewUrl.value = e.target.result; };
    reader.readAsDataURL(file);
};

// ──────────────────────────────────────
// Upload
// ──────────────────────────────────────
const uploadLogo = () => {
    form.post(route('billing.settings.uploadLogo', props.profile.id), {
        onSuccess: () => {
            emit('updated');
            emit('update:visible', false);
        },
    });
};

// ──────────────────────────────────────
// Delete logo
// ──────────────────────────────────────
const removeLogo = () => {
    if (confirm('¿Eliminar el logotipo actual? Podrás subir uno nuevo después.')) {
        form.delete(route('billing.settings.deleteLogo', props.profile.id), {
            onSuccess: () => {
                previewUrl.value = null;
                form.reset();
                emit('updated');
            },
        });
    }
};

// ──────────────────────────────────────
// Close
// ──────────────────────────────────────
const close = () => {
    emit('update:visible', false);
};

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
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal
        class="w-full max-w-md mx-4"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-indigo-50 dark:bg-indigo-900/20 text-indigo-500 flex items-center justify-center flex-shrink-0 border border-indigo-100 dark:border-indigo-900/30">
                    <i class="pi pi-image !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                        Logotipo de facturación
                    </h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        {{ profile.razon_social }}
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6 pt-0">
            <!-- Explanation -->
            <div class="bg-indigo-50 dark:bg-[#1a1a1a] rounded-2xl p-5 border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex gap-4">
                    <i class="pi pi-info-circle !text-lg text-indigo-400 shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300 m-0 leading-relaxed">
                            Logo de tu empresa.
                        </p>
                        <p class="text-sm text-indigo-700 dark:text-gray-300 m-0 leading-relaxed">
                            Sube el logotipo que aparecerá en el PDF de tus facturas.
                        </p>
                        <p class="text-[11px] text-gray-600 dark:text-gray-500 m-0 mt-2 leading-justified">
                                <strong>Recomendación:</strong> Usa una imagen con fondo transparente o blanco para un acabado más limpio.
                                Formatos aceptados: JPG, PNG o WebP (máximo: 2 MB).
                        </p>
                    </div>
                </div>
            </div>

            <!-- Preview area -->
            <div
                class="relative w-full aspect-[3/1] rounded-2xl border-2 border-dashed border-gray-200 dark:border-[#3a3a3a] flex items-center justify-center overflow-hidden bg-gray-50 dark:bg-[#1a1a1a]"
                :class="{ 'border-indigo-300 dark:border-indigo-600 bg-indigo-50/30 dark:bg-indigo-900/10': previewUrl }"
            >
                <!-- Uploaded preview -->
                <div v-if="previewUrl" class="relative w-full h-full flex items-center justify-center p-6">
                    <img
                        :src="previewUrl"
                        alt="Vista previa del logotipo"
                        class="max-w-full max-h-full object-contain"
                    />

                    <!-- Remove overlay -->
                    <button
                        @click="removeLogo"
                        class="absolute top-3 right-3 w-8 h-8 rounded-full bg-white dark:bg-[#232323] border border-gray-200 dark:border-[#3a3a3a] flex items-center justify-center hover:bg-red-50 dark:hover:bg-red-900/30 hover:border-red-200 dark:hover:border-red-800/50 transition-all group shadow-sm"
                        v-tooltip.left="'Eliminar logotipo'"
                    >
                        <i class="pi pi-times !text-xs text-gray-400 group-hover:text-red-500 transition-colors"></i>
                    </button>
                </div>

                <!-- Empty state -->
                <div v-else class="flex flex-col items-center gap-3 p-6 text-center">
                    <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-[#2a2a2a] flex items-center justify-center">
                        <i class="pi pi-cloud-upload !text-xl text-gray-300 dark:text-gray-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400 m-0">
                            Sin logotipo
                        </p>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mt-1">
                            JPG, PNG o WebP &middot; Máx. 2 MB
                        </p>
                    </div>
                </div>
            </div>

            <!-- File input -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                    Seleccionar imagen
                </label>
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    @change="onFileSelect"
                    class="block w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-5 file:rounded-full file:border-0 file:text-xs file:uppercase file:tracking-widest file:font-bold file:bg-indigo-50 dark:file:bg-indigo-900/20 file:text-indigo-600 dark:file:text-indigo-400 hover:file:bg-indigo-100 dark:hover:file:bg-indigo-900/40 file:cursor-pointer file:transition-colors cursor-pointer"
                />
                <Message
                    v-if="form.errors.logo"
                    severity="error"
                    variant="simple"
                    size="small"
                >
                    {{ form.errors.logo }}
                </Message>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end items-center gap-3 w-full pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cancelar"
                    text
                    @click="close"
                    :disabled="form.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
                <Button
                    label="Guardar logotipo"
                    icon="pi pi-check"
                    :loading="form.processing"
                    :disabled="!form.logo"
                    @click="uploadLogo"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6"
                />
            </div>
        </template>
    </Dialog>
</template>
