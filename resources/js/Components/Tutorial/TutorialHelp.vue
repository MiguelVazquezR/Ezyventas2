<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Reusable tutorials button + videos modal.
 *
 * The videos are managed from the admin panel ("Tutoriales") and shared by
 * Inertia as `tutorials`, grouped per module → section. Drop it next to any
 * page title:
 *
 *   <TutorialHelp module="billing" default-section="facturas" />
 *
 * - `module`: key from config/tutorials.php. Only this module's videos are
 *   shown; when the module has no active videos the trigger is hidden.
 * - `defaultSection`: title (or slug) of the section expanded when the modal
 *   opens; users can still open/close every section (accordion).
 * - Videos are lazy: nothing is embedded until the user clicks a video, and the
 *   player is destroyed when the video is closed or the modal hides, so playback
 *   always stops.
 */
const props = defineProps({
    module: { type: String, required: true },
    defaultSection: { type: String, default: null },
    title: { type: String, default: 'Videotutoriales' },
    subtitle: { type: String, default: null },
});

const visible = ref(false);
const expandedSections = ref([]);
const playingVideoId = ref(null);

// Only one player is mounted at a time, so a single ref is enough.
const playerRef = ref(null);

function registerPlayer(element) {
    if (element) playerRef.value = element;
}

const page = usePage();

// Only the videos of this module (the payload contains every module).
const moduleData = computed(() =>
    (page.props.tutorials || []).find((item) => item.key === props.module) || null
);

const sections = computed(() => moduleData.value?.sections || []);

const modalSubtitle = computed(
    () => props.subtitle || (moduleData.value ? `${moduleData.value.label} · Guías paso a paso` : null)
);

// ──────────────────────────────────────
// Section slugs (accordion values + default-section matching)
// ──────────────────────────────────────
const slugify = (text) =>
    String(text ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

const sectionSlug = (section) => slugify(section.title);

function open() {
    playingVideoId.value = null;

    const wanted = props.defaultSection ? slugify(props.defaultSection) : null;
    const match = sections.value.find((section) => sectionSlug(section) === wanted);

    expandedSections.value = match
        ? [sectionSlug(match)]
        : (sections.value[0] ? [sectionSlug(sections.value[0])] : []);

    visible.value = true;
}

defineExpose({ open });

// Destroy the player when the modal closes so the video/audio stops.
watch(visible, (isVisible) => {
    if (!isVisible) playingVideoId.value = null;
});

// ──────────────────────────────────────
// Video helpers (YouTube links + local files)
// ──────────────────────────────────────
const YOUTUBE_REGEX = /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/;

const youtubeId = (video) => video?.url?.match(YOUTUBE_REGEX)?.[1] ?? null;

const isPlayable = (video) => Boolean(video?.url || video?.file_url);

const playerSourceUrl = (video) => video?.url || video?.file_url || null;

const thumbnailUrl = (video) => {
    const id = youtubeId(video);
    return id ? `https://i.ytimg.com/vi/${id}/mqdefault.jpg` : null;
};

const embedUrl = (video) => {
    const id = youtubeId(video);
    return id ? `https://www.youtube.com/embed/${id}?rel=0` : video?.url;
};

async function toggleVideo(video) {
    if (!isPlayable(video)) return;

    playingVideoId.value = playingVideoId.value === video.id ? null : video.id;

    // On small screens the modal content scrolls: keep the player in view.
    if (playingVideoId.value) {
        await nextTick();
        playerRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

const videoCountLabel = (section) => {
    const count = section.videos?.length ?? 0;
    return count === 1 ? '1 video' : `${count} videos`;
};

const rowClass = (video) => {
    if (!isPlayable(video)) {
        return 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#111111] opacity-60 cursor-default';
    }
    if (playingVideoId.value === video.id) {
        return 'border-primary-300 dark:border-primary-800 bg-primary-50/50 dark:bg-primary-900/10 cursor-pointer';
    }
    return 'border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] hover:border-primary-200 dark:hover:border-primary-800 cursor-pointer';
};

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] !rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-4 py-4 sm:px-6 sm:py-5' },
    title: { class: 'text-base sm:text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-3.5 sm:p-5 lg:p-7 max-h-[80vh] overflow-y-auto overscroll-contain' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' },
};

const accordionPt = {
    root: { class: 'space-y-3' },
    panel: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] overflow-hidden' },
    header: { class: 'bg-transparent dark:text-white' },
    headerAction: { class: '!p-3 sm:!p-4 hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors flex items-center justify-between gap-3 w-full outline-none focus:ring-0 text-sm font-medium dark:text-gray-200' },
    content: { class: '!p-3 sm:!p-4 !pt-0 sm:!pt-0 bg-transparent dark:text-gray-400' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <!-- Trigger (hidden when this module has no active videos) -->
    <Button
        v-if="sections.length > 0"
        icon="pi pi-play-circle"
        text
        rounded
        aria-label="Ver videotutoriales"
        @click="open"
        v-tooltip.top="'Ver videotutoriales'"
        class="!w-9 !h-9 !text-gray-400 hover:!text-primary-500 hover:!bg-primary-50 dark:hover:!bg-primary-900/20 !transition-colors shrink-0"
    />

    <!-- Videos modal -->
    <Dialog
        v-model:visible="visible"
        modal
        :dismissableMask="true"
        :header="title"
        class="!w-[96vw] !max-w-6xl 2xl:!max-w-7xl"
        :pt="dialogPt"
    >
        <p v-if="modalSubtitle" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3 sm:mb-4 flex items-center gap-2">
            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
            {{ modalSubtitle }}
        </p>

        <!-- Empty state -->
        <div v-if="!sections || sections.length === 0" class="flex flex-col items-center justify-center py-10 px-4 text-center">
            <i class="pi pi-video !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
            <p class="text-sm text-gray-500 dark:text-gray-400 m-0 max-w-md leading-relaxed">
                Aún no hay videotutoriales disponibles.
            </p>
        </div>

        <Accordion v-else v-model:value="expandedSections" :multiple="true" :pt="accordionPt">
            <AccordionPanel v-for="section in sections" :key="sectionSlug(section)" :value="sectionSlug(section)">
                <AccordionHeader :pt="{ toggleicon: { class: '!text-gray-400 dark:!text-gray-500' } }">
                    <div class="flex items-center gap-3 flex-1 min-w-0 text-left">
                        <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-center shrink-0">
                            <i :class="section.icon || 'pi pi-video'" class="!text-sm text-primary-500"></i>
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ section.title }}</span>
                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mt-0.5">{{ videoCountLabel(section) }}</span>
                        </div>
                    </div>
                </AccordionHeader>

                <AccordionContent>
                    <div class="flex flex-col gap-2">
                        <template v-for="video in section.videos" :key="video.id">
                            <!-- Lazy player (only mounted while playing) -->
                            <div
                                v-if="playingVideoId === video.id"
                                :ref="registerPlayer"
                                class="aspect-video w-full overflow-hidden rounded-xl sm:rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-black"
                            >
                                <iframe
                                    v-if="youtubeId(video)"
                                    :src="embedUrl(video)"
                                    :title="video.title"
                                    class="w-full h-full"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                ></iframe>
                                <video v-else :src="playerSourceUrl(video)" controls autoplay playsinline class="w-full h-full"></video>
                            </div>

                            <!-- Video row -->
                            <div
                                class="flex items-center gap-2.5 sm:gap-3 p-2 sm:p-2.5 rounded-2xl border transition-colors select-none"
                                :class="rowClass(video)"
                                @click="toggleVideo(video)"
                            >
                                <!-- Thumbnail -->
                                <div class="relative w-20 h-12 sm:w-24 sm:h-14 rounded-xl overflow-hidden shrink-0 border border-gray-100 dark:border-[#3a3a3a] bg-gray-100 dark:bg-[#232323]">
                                    <img v-if="thumbnailUrl(video)" :src="thumbnailUrl(video)" alt="" loading="lazy" class="w-full h-full object-cover" />
                                    <div v-else class="w-full h-full flex items-center justify-center">
                                        <i class="pi pi-video !text-lg text-gray-300 dark:text-gray-600"></i>
                                    </div>
                                    <div class="absolute inset-0 flex items-center justify-center bg-black/25">
                                        <i :class="playingVideoId === video.id ? 'pi pi-pause' : 'pi pi-play'" class="!text-white !text-xs drop-shadow"></i>
                                    </div>
                                </div>

                                <!-- Title + description -->
                                <div class="flex flex-col min-w-0 flex-1">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ video.title }}</span>
                                    <span v-if="video.description" class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ video.description }}</span>
                                </div>

                                <!-- Meta -->
                                <Tag v-if="!isPlayable(video)" value="Próximamente" severity="secondary" :pt="tagPt" class="shrink-0" />
                                <Tag v-else-if="video.duration" :value="video.duration" severity="secondary" :pt="tagPt" class="shrink-0" />
                            </div>
                        </template>
                    </div>
                </AccordionContent>
            </AccordionPanel>
        </Accordion>
    </Dialog>
</template>
