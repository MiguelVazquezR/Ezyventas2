<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Help center landing page: every module that currently has documentation or
 * videos. Contents are managed from Admin → Centro de ayuda and Admin → Videos.
 */
const props = defineProps({
    modules: { type: Array, default: () => [] },
});

const page = usePage();
// Support channels come from config/support.php through HandleInertiaRequests.
const support = computed(() => page.props.support ?? {});
const emailUrl = computed(() => (support.value.email ? `mailto:${support.value.email}` : null));
const whatsappUrl = computed(() => support.value.whatsapp_url ?? null);
const whatsappLabel = computed(() => support.value.whatsapp_label ?? 'WhatsApp');

const search = ref('');

const filteredModules = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) return props.modules;

    return props.modules.filter((module) => {
        const haystack = [
            module.label,
            module.description,
            ...module.articles.map((article) => `${article.title} ${article.excerpt ?? ''}`),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(term);
    });
});
</script>

<template>
    <AppLayout>
        <Head title="Centro de ayuda" />

        <div class="flex flex-col gap-6 max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col gap-2">
                <h1 class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">Centro de ayuda</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 m-0">
                    Guías paso a paso y videotutoriales por módulo. Aprende a registrar y administrar tu información.
                </p>
            </div>

            <!-- Search -->
            <IconField iconPosition="left" class="w-full md:w-1/2">
                <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                <InputText
                    v-model="search"
                    placeholder="Buscar una guía o un módulo..."
                    class="w-full"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }"
                />
            </IconField>

            <!-- Modules -->
            <div v-if="filteredModules.length > 0" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Link
                    v-for="module in filteredModules"
                    :key="module.key"
                    :href="route('help-center.show', module.key)"
                    class="flex flex-col gap-4 p-5 bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-primary-200 dark:hover:border-primary-900/40 transition-colors no-underline group"
                >
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-primary-50 dark:bg-primary-900/20 text-primary-500 flex items-center justify-center shrink-0 border border-primary-100 dark:border-primary-900/30">
                            <i :class="`pi ${module.icon} !text-sm`"></i>
                        </div>
                        <div class="flex flex-col min-w-0">
                            <h2 class="text-base font-medium text-gray-900 dark:text-white m-0 tracking-tight truncate">{{ module.label }}</h2>
                            <p v-if="module.description" class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1">{{ module.description }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 text-[10px] uppercase tracking-widest font-bold text-gray-500 mt-auto">
                        <span class="flex items-center gap-1.5">
                            <i class="pi pi-file-edit !text-[11px] text-gray-400"></i>
                            {{ module.articles_count }} {{ module.articles_count === 1 ? 'guía' : 'guías' }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="pi pi-video !text-[11px] text-gray-400"></i>
                            {{ module.videos_count }} {{ module.videos_count === 1 ? 'video' : 'videos' }}
                        </span>
                        <i class="pi pi-arrow-right !text-[10px] ml-auto text-gray-300 dark:text-gray-600 group-hover:text-primary-500 transition-colors"></i>
                    </div>
                </Link>
            </div>

            <!-- Empty: no documentation yet -->
            <div v-else-if="modules.length === 0" class="flex flex-col items-center justify-center text-center py-16 opacity-70">
                <i class="pi pi-book !text-4xl text-gray-400 mb-4"></i>
                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1">Todavía no hay documentación disponible</p>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Pronto publicaremos guías y videos de los módulos.</p>
            </div>

            <!-- Empty: search without results -->
            <div v-else class="flex flex-col items-center justify-center text-center py-16 opacity-70">
                <i class="pi pi-search !text-4xl text-gray-400 mb-4"></i>
                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1">Sin resultados para "{{ search }}"</p>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Intenta con otro término o revisa los módulos disponibles.</p>
            </div>

            <!-- Support CTA -->
            <div class="flex flex-col md:flex-row md:items-center gap-4 p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#232323]">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 dark:bg-amber-900/20 text-amber-500 flex items-center justify-center shrink-0 border border-amber-100 dark:border-amber-900/30">
                        <i class="pi pi-question-circle !text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white m-0">¿No encuentras lo que buscas?</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Escríbenos y con gusto te ayudamos.</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 md:ml-auto">
                    <Button
                        v-if="emailUrl"
                        as="a"
                        :href="emailUrl"
                        label="Correo"
                        icon="pi pi-envelope"
                        outlined
                        class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold"
                    />
                    <Button
                        v-if="whatsappUrl"
                        as="a"
                        :href="whatsappUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        :label="whatsappLabel"
                        icon="pi pi-whatsapp"
                        class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
