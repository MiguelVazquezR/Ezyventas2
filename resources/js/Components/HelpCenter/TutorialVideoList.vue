<script setup>
import { ref } from 'vue';

/**
 * Videos of one module grouped by section (help center detail page).
 *
 * Only one player is mounted at a time and it is destroyed when the user
 * closes it, so playback always stops.
 */
const props = defineProps({
    sections: { type: Array, default: () => [] },
});

const playingVideoId = ref(null);

const YOUTUBE_REGEX = /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/;

const youtubeId = (video) => video?.url?.match(YOUTUBE_REGEX)?.[1] ?? null;

const thumbnailUrl = (video) => {
    const id = youtubeId(video);
    return id ? `https://i.ytimg.com/vi/${id}/mqdefault.jpg` : null;
};

const playerSourceUrl = (video) => video?.url || video?.file_url || null;

const embedUrl = (video) => {
    const id = youtubeId(video);
    return id ? `https://www.youtube.com/embed/${id}?autoplay=1&rel=0` : null;
};

const toggleVideo = (video) => {
    playingVideoId.value = playingVideoId.value === video.id ? null : video.id;
};

const totalVideos = () => props.sections.reduce((total, section) => total + section.videos.length, 0);
</script>

<template>
    <div v-if="totalVideos() > 0" class="flex flex-col gap-6">
        <div v-for="section in sections" :key="section.title" class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(59,130,246,0.8)]"></span>
                <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ section.title }}</h3>
            </div>

            <div class="flex flex-col gap-2">
                <template v-for="video in section.videos" :key="video.id">
                    <div
                        v-if="playingVideoId === video.id"
                        class="aspect-video w-full overflow-hidden rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-black"
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

                    <button
                        type="button"
                        class="flex items-center gap-3 p-3 rounded-2xl border text-left transition-colors w-full"
                        :class="playingVideoId === video.id
                            ? 'border-primary-200 dark:border-primary-900/40 bg-primary-50/50 dark:bg-primary-900/10'
                            : 'border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#1a1a1a] hover:border-gray-200 dark:hover:border-[#4a4a4a]'"
                        @click="toggleVideo(video)"
                    >
                        <div class="relative w-24 h-14 rounded-xl overflow-hidden shrink-0 border border-gray-100 dark:border-[#3a3a3a] bg-gray-100 dark:bg-[#232323]">
                            <img v-if="thumbnailUrl(video)" :src="thumbnailUrl(video)" alt="" loading="lazy" class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center">
                                <i class="pi pi-video !text-lg text-gray-300 dark:text-gray-600"></i>
                            </div>
                            <div class="absolute inset-0 flex items-center justify-center bg-black/25">
                                <i :class="playingVideoId === video.id ? 'pi pi-pause' : 'pi pi-play'" class="!text-white !text-xs drop-shadow"></i>
                            </div>
                        </div>

                        <div class="flex flex-col min-w-0 flex-1">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ video.title }}</span>
                            <span v-if="video.description" class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ video.description }}</span>
                        </div>

                        <Tag v-if="video.duration" :value="video.duration" severity="secondary" class="shrink-0" />
                    </button>
                </template>
            </div>
        </div>
    </div>

    <div v-else class="flex flex-col items-center justify-center text-center py-10 opacity-60">
        <i class="pi pi-video !text-3xl text-gray-400 mb-3"></i>
        <p class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1">Sin videos por ahora</p>
        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Pronto agregaremos videotutoriales de este módulo.</p>
    </div>
</template>
