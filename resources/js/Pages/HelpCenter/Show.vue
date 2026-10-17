<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TutorialVideoList from '@/Components/HelpCenter/TutorialVideoList.vue';

/**
 * Documentation of one module: written guides plus videos grouped by section.
 */
const props = defineProps({
    module: { type: Object, required: true },
});

const totalVideos = computed(() => props.module.videos.reduce((total, section) => total + section.videos.length, 0));

const formatDate = (value) => {
    if (!value) return null;

    return new Date(value).toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' });
};

const scrollTo = (id) => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
</script>

<template>
    <AppLayout>
        <Head :title="`${module.label} · Centro de ayuda`" />

        <div class="flex flex-col gap-6 max-w-6xl mx-auto">
            <!-- Back -->
            <Link
                :href="route('help-center.index')"
                class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-primary-500 no-underline w-fit"
            >
                <i class="pi pi-arrow-left !text-[10px]"></i>
                Centro de ayuda
            </Link>

            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center gap-4 p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]">
                <div class="w-12 h-12 rounded-2xl bg-primary-50 dark:bg-primary-900/20 text-primary-500 flex items-center justify-center shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i :class="`pi ${module.icon} !text-lg`"></i>
                </div>

                <div class="flex flex-col min-w-0">
                    <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ module.label }}</h1>
                    <p v-if="module.description" class="text-sm text-gray-500 dark:text-gray-400 m-0 mt-1">{{ module.description }}</p>
                </div>

                <div class="flex items-center gap-5 md:ml-auto text-[10px] uppercase tracking-widest font-bold text-gray-500">
                    <span class="flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-primary-500"></span>
                        {{ module.articles_count }} {{ module.articles_count === 1 ? 'guía' : 'guías' }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        {{ totalVideos }} {{ totalVideos === 1 ? 'video' : 'videos' }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-6 items-start">
                <!-- Index -->
                <aside class="lg:sticky lg:top-24 flex flex-col gap-2 p-4 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#232323]">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 px-2">En este módulo</p>

                    <button
                        v-for="(article, index) in module.articles"
                        :key="article.id"
                        type="button"
                        class="flex items-start gap-2 p-2 rounded-xl text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-white dark:hover:bg-[#1a1a1a] transition-colors"
                        @click="scrollTo(`guide-${article.id}`)"
                    >
                        <span class="text-[10px] font-mono text-gray-400 mt-0.5">{{ index + 1 }}</span>
                        <span class="leading-snug">{{ article.title }}</span>
                    </button>

                    <button
                        v-if="totalVideos > 0"
                        type="button"
                        class="flex items-center gap-2 p-2 rounded-xl text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-white dark:hover:bg-[#1a1a1a] transition-colors"
                        @click="scrollTo('module-videos')"
                    >
                        <i class="pi pi-play-circle !text-xs text-gray-400"></i>
                        <span class="leading-snug">Videotutoriales</span>
                    </button>

                    <p v-if="module.articles.length === 0 && totalVideos === 0" class="text-xs text-gray-400 m-0 px-2 pb-2">
                        Sin contenido publicado.
                    </p>
                </aside>

                <!-- Content -->
                <div class="flex flex-col gap-6 min-w-0">
                    <!-- Written guides -->
                    <div v-if="module.articles.length > 0" class="flex flex-col gap-4">
                        <div
                            v-for="(article, index) in module.articles"
                            :id="`guide-${article.id}`"
                            :key="article.id"
                            class="scroll-mt-24 flex flex-col gap-4 p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]"
                        >
                            <div class="flex flex-col gap-2">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400">
                                    Guía {{ index + 1 }}{{ article.updated_at ? ` · actualizada el ${formatDate(article.updated_at)}` : '' }}
                                </span>
                                <h2 class="text-xl font-medium tracking-tight text-gray-900 dark:text-white m-0">{{ article.title }}</h2>
                                <p v-if="article.excerpt" class="text-sm text-gray-500 dark:text-gray-400 m-0">{{ article.excerpt }}</p>
                            </div>

                            <div
                                class="prose prose-sm max-w-none dark:prose-invert prose-headings:tracking-tight prose-headings:font-medium prose-a:text-primary-500 prose-img:rounded-2xl"
                                v-html="article.content"
                            ></div>
                        </div>
                    </div>

                    <!-- Videos -->
                    <div id="module-videos" class="scroll-mt-24 flex flex-col gap-4 p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]">
                        <div class="flex items-center gap-2">
                            <i class="pi pi-play-circle !text-sm text-gray-400"></i>
                            <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Videotutoriales</h2>
                        </div>

                        <TutorialVideoList :sections="module.videos" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

