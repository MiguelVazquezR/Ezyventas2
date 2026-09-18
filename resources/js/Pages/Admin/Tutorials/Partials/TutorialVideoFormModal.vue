<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

/**
 * Create/edit form for one tutorial video (Admin → Tutoriales).
 *
 * A video plays from an external link (YouTube/Vimeo) or from a file uploaded
 * to the server — the form switches between both sources.
 */
const props = defineProps({
    moduleOptions: { type: Array, default: () => [] },
    videos: { type: Array, default: () => [] },
    uploadLimitKb: { type: Number, default: 51200 },
});

const emit = defineEmits(['saved']);

const visible = ref(false);
const editing = ref(null);
const fileInput = ref(null);
const selectedFileName = ref(null);

const form = useForm({
    module: null,
    section: '',
    title: '',
    description: '',
    duration: '',
    source_type: 'link',
    url: '',
    file: null,
});

const isEditing = computed(() => editing.value !== null);

const sourceOptions = [
    { label: 'Enlace externo', value: 'link' },
    { label: 'Subir archivo', value: 'file' },
];

const moduleSelectOptions = computed(() =>
    props.moduleOptions.map((option) => ({ label: option.label, value: option.key }))
);

function open(video = null) {
    editing.value = video ?? null;
    selectedFileName.value = null;

    form.clearErrors();
    form.reset();

    if (video) {
        form.module = video.module;
        form.section = video.section;
        form.title = video.title;
        form.description = video.description || '';
        form.duration = video.duration || '';
        form.source_type = video.file_url ? 'file' : 'link';
        form.url = video.url || '';
        selectedFileName.value = video.file_url ? 'Archivo actual (puedes reemplazarlo)' : null;
    } else {
        form.source_type = 'link';
    }

    visible.value = true;
}

defineExpose({ open });

// ──────────────────────────────────────
// Section suggestions (existing sections of the selected module)
// ──────────────────────────────────────
const filteredSections = ref([]);

const searchSections = (event) => {
    const query = (event.query || '').toLowerCase();

    const existing = new Set(
        props.videos
            .filter((video) => video.module === form.module)
            .map((video) => video.section)
    );

    filteredSections.value = Array.from(existing).filter((section) =>
        section.toLowerCase().includes(query)
    );
};

// ──────────────────────────────────────
// File selection
// ──────────────────────────────────────
const onFileSelect = (event) => {
    const file = event.target.files?.[0] || null;

    form.file = file;

    selectedFileName.value = file
        ? `${file.name} · ${(file.size / 1024 / 1024).toFixed(1)} MB`
        : null;
};

const fileSizeLabel = (kb) => (kb >= 1024 ? `${Math.round(kb / 1024)} MB` : `${kb} KB`);

// ──────────────────────────────────────
// Submit
// ──────────────────────────────────────
const submit = () => {
    const options = {
        onSuccess: () => {
            visible.value = false;
            emit('saved');
        },
    };

    if (isEditing.value) {
        form
            .transform((data) => ({ ...data, _method: 'put' }))
            .post(route('admin.tutorials.update', editing.value.id), options);
    } else {
        form
            .transform((data) => data)
            .post(route('admin.tutorials.store'), options);
    }
};

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const controlClass =
    'w-full !rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm';

const selectPt = { root: { class: controlClass } };
const inputPt = { root: { class: controlClass } };
const autoCompletePt = {
    root: { class: 'w-full' },
    input: { root: { class: controlClass } },
    panel: { class: 'dark:!bg-[#121212] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !shadow-xl' },
};

const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#1a1a1a] !transition-colors !rounded-full !w-8 !h-8 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <Dialog v-model:visible="visible" modal :dismissableMask="true" class="!w-[95vw] !max-w-lg" :pt="dialogPt">
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 text-primary-500 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i class="pi pi-play-circle !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                        {{ isEditing ? 'Editar video' : 'Nuevo video' }}
                    </h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Videotutoriales
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Module + Section -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Módulo *</label>
                    <Select
                        v-model="form.module"
                        :options="moduleSelectOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Selecciona"
                        :pt="selectPt"
                    />
                    <Message v-if="form.errors.module" severity="error" variant="simple" size="small">{{ form.errors.module }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sección *</label>
                    <AutoComplete
                        v-model="form.section"
                        :suggestions="filteredSections"
                        dropdown
                        placeholder="Ej. Pagos y complementos"
                        :pt="autoCompletePt"
                        @complete="searchSections"
                    />
                    <Message v-if="form.errors.section" severity="error" variant="simple" size="small">{{ form.errors.section }}</Message>
                    <span class="text-[10px] text-gray-400 dark:text-gray-500">Escribe una nueva o elige una existente del módulo.</span>
                </div>
            </div>

            <!-- Title + Duration -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="flex flex-col gap-1.5 sm:col-span-2">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Título *</label>
                    <InputText v-model="form.title" placeholder="Ej. Cómo crear una factura" :pt="inputPt" />
                    <Message v-if="form.errors.title" severity="error" variant="simple" size="small">{{ form.errors.title }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Duración</label>
                    <InputText v-model="form.duration" placeholder="3:20" :pt="inputPt" />
                    <Message v-if="form.errors.duration" severity="error" variant="simple" size="small">{{ form.errors.duration }}</Message>
                </div>
            </div>

            <!-- Description -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descripción</label>
                <InputText v-model="form.description" placeholder="Breve descripción (opcional)" :pt="inputPt" />
                <Message v-if="form.errors.description" severity="error" variant="simple" size="small">{{ form.errors.description }}</Message>
            </div>

            <!-- Source -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Origen del video *</label>
                <SelectButton
                    v-model="form.source_type"
                    :options="sourceOptions"
                    optionLabel="label"
                    optionValue="value"
                    :allowEmpty="false"
                />
                <Message v-if="form.errors.source_type" severity="error" variant="simple" size="small">{{ form.errors.source_type }}</Message>
            </div>

            <!-- Link -->
            <div v-if="form.source_type === 'link'" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Enlace del video *</label>
                <InputText v-model="form.url" placeholder="https://www.youtube.com/watch?v=..." :pt="inputPt" />
                <Message v-if="form.errors.url" severity="error" variant="simple" size="small">{{ form.errors.url }}</Message>
                <span class="text-[10px] text-gray-400 dark:text-gray-500">YouTube, Vimeo o un enlace directo a un video.</span>
            </div>

            <!-- File -->
            <div v-else class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                    Archivo del video {{ isEditing && editing?.file_url ? '' : '*' }}
                </label>
                <div class="flex items-center gap-2 min-w-0">
                    <input
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        accept="video/mp4,video/webm,video/quicktime"
                        @change="onFileSelect"
                    />
                    <Button
                        label="Seleccionar video"
                        icon="pi pi-upload"
                        outlined
                        severity="secondary"
                        class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0"
                        @click="fileInput?.click()"
                    />
                    <span v-if="selectedFileName" class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ selectedFileName }}</span>
                </div>
                <Message v-if="form.errors.file" severity="error" variant="simple" size="small">{{ form.errors.file }}</Message>
                <span class="text-[10px] text-gray-400 dark:text-gray-500">
                    Formatos: MP4, WebM o MOV · máximo {{ fileSizeLabel(uploadLimitKb) }} (también depende del límite de subida de tu servidor).
                </span>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold"
                    @click="visible = false"
                />
                <Button
                    :label="isEditing ? 'Guardar cambios' : 'Agregar video'"
                    icon="pi pi-check"
                    :loading="form.processing"
                    class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold"
                    @click="submit"
                />
            </div>
        </template>
    </Dialog>
</template>
