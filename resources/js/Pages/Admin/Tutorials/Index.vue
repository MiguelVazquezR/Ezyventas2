<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import TutorialVideoFormModal from './Partials/TutorialVideoFormModal.vue';

/**
 * Admin → Tutoriales: manages the video tutorials of every module.
 *
 * Videos are grouped by module → section; each module's pages only display
 * their own videos in the tutorial modal (icon next to the page title).
 */
const props = defineProps({
    videos: { type: Array, default: () => [] },
    moduleOptions: { type: Array, default: () => [] },
    uploadLimitKb: { type: Number, default: 51200 },
});

const confirm = useConfirm();

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Tutoriales' },
]);

// ──────────────────────────────────────
// Filters + grouping (module → section → videos)
// ──────────────────────────────────────
const moduleFilter = ref(null);

const moduleFilterOptions = computed(() => [
    { label: 'Todos los módulos', value: null },
    ...props.moduleOptions.map((option) => ({ label: option.label, value: option.key })),
]);

const groupedModules = computed(() => {
    const groups = new Map();

    for (const video of props.videos) {
        if (moduleFilter.value && video.module !== moduleFilter.value) continue;

        if (!groups.has(video.module)) {
            const meta = props.moduleOptions.find((option) => option.key === video.module);
            groups.set(video.module, {
                key: video.module,
                label: meta?.label || video.module,
                sections: new Map(),
                count: 0,
            });
        }

        const group = groups.get(video.module);

        if (!group.sections.has(video.section)) {
            group.sections.set(video.section, { title: video.section, videos: [] });
        }

        group.sections.get(video.section).videos.push(video);
        group.count++;
    }

    return Array.from(groups.values()).map((group) => ({
        ...group,
        sections: Array.from(group.sections.values()),
    }));
});

const totalVideos = computed(() => props.videos.length);
const totalActive = computed(() => props.videos.filter((video) => video.is_active).length);

// ──────────────────────────────────────
// Form modal
// ──────────────────────────────────────
const formModalRef = ref(null);
const openCreate = () => formModalRef.value?.open();
const openEdit = (video) => formModalRef.value?.open(video);

// ──────────────────────────────────────
// Row actions
// ──────────────────────────────────────
const toggleActive = (video) => {
    router.post(route('admin.tutorials.toggle-active', video.id), {}, { preserveScroll: true });
};

const move = (video, direction) => {
    router.post(route('admin.tutorials.move', video.id), { direction }, { preserveScroll: true });
};

const destroyVideo = (video) => {
    confirm.require({
        message: `¿Eliminar el video "${video.title}"? Esta acción no se puede deshacer.`,
        header: 'Eliminar video',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Eliminar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(route('admin.tutorials.destroy', video.id), { preserveScroll: true }),
    });
};

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
const sourceInfo = (video) => {
    if (video.url) {
        return {
            label: /youtu\.?be/.test(video.url) ? 'YouTube' : 'Enlace',
            severity: 'success',
        };
    }

    if (video.file_url) {
        return { label: 'Archivo', severity: 'info' };
    }

    return { label: 'Próximamente', severity: 'warn' };
};

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' },
};
</script>

<template>
    <Head title="Tutoriales" />
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- ════════════════════════════════════════
                 Main panel
                 ════════════════════════════════════════ -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">

                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                            Tutoriales
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                            {{ totalVideos }} video{{ totalVideos === 1 ? '' : 's' }} · {{ totalActive }} activo{{ totalActive === 1 ? '' : 's' }} · todos los módulos
                        </p>
                    </div>

                    <!-- Header actions -->
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3 w-full sm:w-auto shrink-0">
                        <Select
                            v-model="moduleFilter"
                            :options="moduleFilterOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Filtrar por módulo"
                            class="w-full sm:w-60"
                            :pt="selectPt"
                        />
                        <Button
                            label="Agregar video"
                            icon="pi pi-plus"
                            @click="openCreate"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider !justify-center w-full sm:w-auto"
                        />
                    </div>
                </div>

                <!-- Hint -->
                <div class="flex items-start gap-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] px-4 py-3 mb-6">
                    <i class="pi pi-info-circle !text-sm text-gray-400 mt-0.5 shrink-0"></i>
                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Los videos se muestran en el icono de tutorial de cada módulo, agrupados por sección. Si un módulo no tiene videos activos, el icono no aparece en sus páginas.
                    </p>
                </div>

                <!-- Empty state -->
                <div v-if="groupedModules.length === 0" class="flex flex-col items-center justify-center py-16 px-4 text-center">
                    <i class="pi pi-play-circle !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed m-0">
                        {{ totalVideos === 0
                            ? 'Aún no hay videotutoriales. Agrega el primero y aparecerá en el icono de tutorial del módulo elegido.'
                            : 'No hay videos que coincidan con el filtro seleccionado.' }}
                    </p>
                </div>

                <!-- Modules -->
                <div v-else class="space-y-6">
                    <div
                        v-for="group in groupedModules"
                        :key="group.key"
                        class="rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden"
                    >
                        <!-- Module header -->
                        <div class="flex items-center justify-between gap-3 bg-gray-50 dark:bg-[#1a1a1a] px-5 py-4 border-b border-gray-100 dark:border-[#3a3a3a]">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-center shrink-0">
                                    <i class="pi pi-play-circle !text-sm text-primary-500"></i>
                                </div>
                                <div class="flex flex-col min-w-0">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ group.label }}</span>
                                    <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mt-0.5">
                                        {{ group.count }} video{{ group.count === 1 ? '' : 's' }} · {{ group.sections.length }} sección{{ group.sections.length === 1 ? '' : 'es' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Sections -->
                        <div>
                            <div
                                v-for="section in group.sections"
                                :key="section.title"
                                class="px-5 py-4 border-b border-gray-50 dark:border-[#2a2a2a] last:border-b-0"
                            >
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-3">
                                    {{ section.title }}
                                </p>

                                <div class="space-y-2">
                                    <div
                                        v-for="video in section.videos"
                                        :key="video.id"
                                        class="flex items-center gap-3 p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]"
                                        :class="{ 'opacity-60': !video.is_active }"
                                    >
                                        <!-- Order -->
                                        <div class="flex flex-col shrink-0">
                                            <Button
                                                icon="pi pi-angle-up"
                                                text
                                                rounded
                                                size="small"
                                                :disabled="section.videos[0]?.id === video.id"
                                                class="!w-6 !h-6 !p-0 !text-gray-400"
                                                v-tooltip.right="'Subir'"
                                                @click="move(video, 'up')"
                                            />
                                            <Button
                                                icon="pi pi-angle-down"
                                                text
                                                rounded
                                                size="small"
                                                :disabled="section.videos[section.videos.length - 1]?.id === video.id"
                                                class="!w-6 !h-6 !p-0 !text-gray-400"
                                                v-tooltip.right="'Bajar'"
                                                @click="move(video, 'down')"
                                            />
                                        </div>

                                        <!-- Info -->
                                        <div class="flex flex-col min-w-0 flex-1">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate m-0">{{ video.title }}</span>
                                                <span v-if="video.duration" class="text-[10px] font-mono text-gray-400 shrink-0">{{ video.duration }}</span>
                                            </div>
                                            <span v-if="video.description" class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ video.description }}</span>
                                        </div>

                                        <!-- Source -->
                                        <Tag
                                            :value="sourceInfo(video).label"
                                            :severity="sourceInfo(video).severity"
                                            :pt="tagPt"
                                            class="shrink-0 hidden sm:inline-flex"
                                        />

                                        <!-- Active toggle -->
                                        <ToggleSwitch
                                            :modelValue="video.is_active"
                                            class="shrink-0"
                                            v-tooltip.top="video.is_active ? 'Desactivar' : 'Activar'"
                                            @update:modelValue="toggleActive(video)"
                                        />

                                        <!-- Actions -->
                                        <div class="flex items-center shrink-0">
                                            <Button
                                                icon="pi pi-pencil"
                                                text
                                                rounded
                                                class="!w-8 !h-8 !text-gray-500 hover:!bg-gray-100 dark:hover:!bg-[#2a2a2a]"
                                                v-tooltip.top="'Editar'"
                                                @click="openEdit(video)"
                                            />
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                class="!w-8 !h-8 !text-red-500 hover:!bg-red-50 dark:hover:!bg-red-900/20"
                                                v-tooltip.top="'Eliminar'"
                                                @click="destroyVideo(video)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <TutorialVideoFormModal
            ref="formModalRef"
            :module-options="moduleOptions"
            :videos="videos"
            :upload-limit-kb="uploadLimitKb"
            @saved="router.reload()"
        />
    </AppLayout>
</template>
