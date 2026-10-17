<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import HelpArticleFormModal from './Partials/HelpArticleFormModal.vue';

/**
 * Admin → Centro de ayuda: manages the written guides of every module.
 *
 * Each module page of the help center shows these guides together with that
 * module's videos (managed in Admin → Videos).
 */
const props = defineProps({
    articles: { type: Array, default: () => [] },
    moduleOptions: { type: Array, default: () => [] },
    videoCounts: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Centro de ayuda' },
]);

// ──────────────────────────────────────
// Filters + grouping (module → guides)
// ──────────────────────────────────────
const moduleFilter = ref(null);

const moduleFilterOptions = computed(() => [
    { label: 'Todos los módulos', value: null },
    ...props.moduleOptions.map((option) => ({ label: option.label, value: option.key })),
]);

const groupedModules = computed(() => {
    const groups = new Map();

    for (const article of props.articles) {
        if (moduleFilter.value && article.module !== moduleFilter.value) continue;

        if (!groups.has(article.module)) {
            const meta = props.moduleOptions.find((option) => option.key === article.module);

            groups.set(article.module, {
                key: article.module,
                label: meta?.label || article.module,
                videos: props.videoCounts[article.module] ?? 0,
                articles: [],
            });
        }

        groups.get(article.module).articles.push(article);
    }

    return Array.from(groups.values());
});

const totalArticles = computed(() => props.articles.length);
const totalPublished = computed(() => props.articles.filter((article) => article.is_published).length);

// ──────────────────────────────────────
// Form modal
// ──────────────────────────────────────
const formModalRef = ref(null);
const openCreate = (moduleKey = null) => formModalRef.value?.open(null, moduleKey);
const openEdit = (article) => formModalRef.value?.open(article);

// ──────────────────────────────────────
// Row actions
// ──────────────────────────────────────
const togglePublish = (article) => {
    router.post(route('admin.help-articles.toggle-publish', article.id), {}, { preserveScroll: true });
};

const move = (article, direction) => {
    router.post(route('admin.help-articles.move', article.id), { direction }, { preserveScroll: true });
};

const destroyArticle = (article) => {
    confirm.require({
        message: `¿Eliminar la guía "${article.title}"? Esta acción no se puede deshacer.`,
        header: 'Eliminar guía',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Eliminar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(route('admin.help-articles.destroy', article.id), { preserveScroll: true }),
    });
};

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
const plainText = (html) => {
    if (!html) return '';

    const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

    return text.length > 140 ? `${text.slice(0, 140)}…` : text;
};

const formatDate = (value) => {
    if (!value) return null;

    return new Date(value).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
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
    <Head title="Centro de ayuda" />
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- Main panel -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">

                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                            Centro de ayuda
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                            {{ totalArticles }} guía{{ totalArticles === 1 ? '' : 's' }} · {{ totalPublished }} publicada{{ totalPublished === 1 ? '' : 's' }} · todos los módulos
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
                            label="Videos"
                            icon="pi pi-video"
                            outlined
                            severity="secondary"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider !justify-center"
                            @click="router.visit(route('admin.tutorials.index'))"
                        />
                        <Button
                            label="Nueva guía"
                            icon="pi pi-plus"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider !justify-center w-full sm:w-auto"
                            @click="openCreate()"
                        />
                    </div>
                </div>

                <!-- Hint -->
                <div class="flex items-start gap-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] px-4 py-3 mb-6">
                    <i class="pi pi-info-circle !text-xs text-gray-400 mt-0.5"></i>
                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Las guías se agrupan por módulo y aparecen en el centro de ayuda del usuario junto con los videos de ese módulo.
                    </p>
                </div>

                <!-- Empty state -->
                <div v-if="groupedModules.length === 0" class="flex flex-col items-center justify-center py-16 px-4 text-center">
                    <i class="pi pi-book !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed m-0">
                        {{ totalArticles === 0
                            ? 'Aún no hay guías. Crea la primera y aparecerá en el centro de ayuda del módulo elegido.'
                            : 'No hay guías que coincidan con el filtro seleccionado.' }}
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
                                    <i class="pi pi-book !text-sm text-primary-500"></i>
                                </div>
                                <div class="flex flex-col min-w-0">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ group.label }}</span>
                                    <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mt-0.5">
                                        {{ group.articles.length }} guía{{ group.articles.length === 1 ? '' : 's' }} · {{ group.videos }} video{{ group.videos === 1 ? '' : 's' }}
                                    </span>
                                </div>
                            </div>

                            <Button
                                label="Agregar guía"
                                icon="pi pi-plus"
                                text
                                size="small"
                                class="!rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold shrink-0"
                                @click="openCreate(group.key)"
                            />
                        </div>


                        <!-- Guides -->
                        <div class="px-5 py-4">
                            <div class="space-y-2">
                                <div
                                    v-for="(article, index) in group.articles"
                                    :key="article.id"
                                    class="flex items-start gap-3 p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]"
                                    :class="{ 'opacity-60': !article.is_published }"
                                >
                                    <!-- Order -->
                                    <div class="flex flex-col shrink-0">
                                        <Button
                                            icon="pi pi-angle-up"
                                            text
                                            rounded
                                            class="!w-6 !h-5 !text-gray-400"
                                            :disabled="index === 0"
                                            v-tooltip.top="'Subir'"
                                            @click="move(article, 'up')"
                                        />
                                        <span class="text-[10px] font-mono text-gray-400 text-center leading-5">{{ index + 1 }}</span>
                                        <Button
                                            icon="pi pi-angle-down"
                                            text
                                            rounded
                                            class="!w-6 !h-5 !text-gray-400"
                                            :disabled="index === group.articles.length - 1"
                                            v-tooltip.top="'Bajar'"
                                            @click="move(article, 'down')"
                                        />
                                    </div>

                                    <!-- Info -->
                                    <div class="flex flex-col min-w-0 flex-1">
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate m-0">{{ article.title }}</span>
                                        <span class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ plainText(article.content) }}</span>
                                    </div>

                                    <!-- Status -->
                                    <Tag
                                        :value="article.is_published ? 'Publicada' : 'Borrador'"
                                        :severity="article.is_published ? 'success' : 'secondary'"
                                        :pt="tagPt"
                                        class="shrink-0 hidden sm:inline-flex mt-1"
                                    />

                                    <!-- Updated -->
                                    <span class="text-[10px] font-mono text-gray-400 shrink-0 hidden lg:block mt-1.5 w-24 text-right">
                                        {{ formatDate(article.updated_at) }}
                                    </span>

                                    <!-- Publish toggle -->
                                    <ToggleSwitch
                                        :modelValue="article.is_published"
                                        class="shrink-0 mt-1"
                                        v-tooltip.top="article.is_published ? 'Ocultar' : 'Publicar'"
                                        @update:modelValue="togglePublish(article)"
                                    />

                                    <!-- Actions -->
                                    <div class="flex items-center shrink-0">
                                        <Button
                                            icon="pi pi-pencil"
                                            text
                                            rounded
                                            class="!w-8 !h-8 !text-gray-500 hover:!bg-gray-100 dark:hover:!bg-[#2a2a2a]"
                                            v-tooltip.top="'Editar'"
                                            @click="openEdit(article)"
                                        />
                                        <Button
                                            icon="pi pi-trash"
                                            text
                                            rounded
                                            class="!w-8 !h-8 !text-red-500 hover:!bg-red-50 dark:hover:!bg-red-900/20"
                                            v-tooltip.top="'Eliminar'"
                                            @click="destroyArticle(article)"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <HelpArticleFormModal
            ref="formModalRef"
            :module-options="moduleOptions"
            @saved="router.reload()"
        />
    </AppLayout>
</template>

