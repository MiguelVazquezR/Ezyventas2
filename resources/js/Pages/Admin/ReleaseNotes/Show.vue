<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Tag from 'primevue/tag';
import Image from 'primevue/image';

const props = defineProps({
    note: {
        type: Object,
        required: true
    }
});

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('es-MX', { 
        weekday: 'long', 
        day: 'numeric', 
        month: 'long', 
        year: 'numeric' 
    });
};
</script>

<template>
    <AppLayout :title="note.title">
        <!-- Contenedor principal sin fondo explícito para heredar el del Layout general -->
        <div class="p-4 md:p-6 lg:p-8 min-h-full">
            <div class="max-w-4xl mx-auto">
                
                <!-- Botón de regreso apuntando al POS -->
                <div class="mb-6">
                    <Link :href="route('pos.index')" class="inline-flex items-center gap-2 px-4 py-2 bg-white/60 dark:bg-surface-800/60 hover:bg-white dark:hover:bg-surface-800 text-surface-600 dark:text-surface-300 hover:text-primary-600 dark:hover:text-primary-400 font-medium rounded-xl border border-surface-200 dark:border-surface-700 shadow-sm backdrop-blur-md transition-all w-max">
                        <i class="pi pi-arrow-left text-sm"></i> Volver a punto de venta
                    </Link>
                </div>
                
                <!-- Tarjeta Principal del Contenido (Estilo macOS) -->
                <div class="rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-white/60 dark:border-surface-700 bg-gradient-to-br from-blue-50/80 to-white/90 dark:from-blue-900/20 dark:to-surface-800/80 backdrop-blur-xl overflow-hidden">
                    
                    <!-- Encabezado de la Novedad -->
                    <div class="p-6 md:p-8 border-b border-surface-200/50 dark:border-surface-700/50 bg-white/40 dark:bg-surface-800/40">
                        <div class="flex items-center gap-3 mb-4">
                            <Tag v-if="note.version" :value="note.version" class="!bg-blue-100 !text-blue-700 dark:!bg-blue-900/50 dark:!text-blue-300 !rounded-lg !px-3 !py-1 !text-xs font-semibold tracking-wide" />
                            <span class="text-sm text-surface-500 font-medium">{{ formatDate(note.published_at) }}</span>
                        </div>
                        <h1 class="text-3xl md:text-4xl font-semibold text-surface-900 dark:text-surface-0 leading-tight tracking-tight">
                            {{ note.title }}
                        </h1>
                    </div>

                    <!-- Contenido Rico (Con control de desbordamiento de palabras) -->
                    <div class="p-6 md:p-8">
                        <div class="rich-text-content text-surface-700 dark:text-surface-300 break-words" v-html="note.content"></div>
                    </div>

                    <!-- Galería de Multimedia -->
                    <div v-if="note.media && note.media.length > 0" class="p-6 md:p-8 border-t border-surface-200/50 dark:border-surface-700/50 bg-surface-50/30 dark:bg-surface-800/30">
                        <h3 class="text-lg font-semibold text-surface-800 dark:text-surface-200 mb-4 flex items-center gap-2">
                            <i class="pi pi-images"></i> Galería adjunta
                        </h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="mediaItem in note.media" :key="mediaItem.id" class="rounded-2xl overflow-hidden border border-surface-200 dark:border-surface-700 shadow-sm bg-white dark:bg-surface-900 flex items-center justify-center">
                                
                                <!-- Si es imagen -->
                                <Image v-if="mediaItem.mime_type.startsWith('image/')" :src="mediaItem.url" :alt="mediaItem.name" preview imageClass="w-full h-48 object-cover cursor-pointer hover:scale-105 transition-transform duration-300" />
                                
                                <!-- Si es video -->
                                <video v-else-if="mediaItem.mime_type.startsWith('video/')" controls class="w-full h-48 object-cover bg-black">
                                    <source :src="mediaItem.url" :type="mediaItem.mime_type">
                                    Tu navegador no soporta el formato de video.
                                </video>
                                
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Restauración de estilos para el editor de PrimeVue */
:deep(.rich-text-content) {
    /* CORRECCIÓN DE DESBORDAMIENTO: Obliga a que la palabra se rompa y baje de línea */
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
    hyphens: auto;
}
/* Evitar que imágenes y videos desborden el contenedor en móviles */
:deep(.rich-text-content img), 
:deep(.rich-text-content iframe), 
:deep(.rich-text-content video) {
    max-width: 100%;
    height: auto;
    border-radius: 0.75rem;
}
/* Controlar desbordamiento de bloques preformateados como código */
:deep(.rich-text-content pre) {
    max-width: 100%;
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
:deep(.rich-text-content h1) { font-size: 2.25rem; font-weight: 700; margin-top: 1.5rem; margin-bottom: 1rem; color: var(--p-surface-900); }
:deep(.rich-text-content h2) { font-size: 1.875rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 1rem; color: var(--p-surface-800); }
:deep(.rich-text-content h3) { font-size: 1.5rem; font-weight: 600; margin-top: 1.25rem; margin-bottom: 0.75rem; color: var(--p-surface-800); }
:deep(.rich-text-content p) { margin-bottom: 1rem; line-height: 1.75; }
:deep(.rich-text-content ul) { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
:deep(.rich-text-content ol) { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 1rem; }
:deep(.rich-text-content li) { margin-bottom: 0.5rem; }
:deep(.rich-text-content a) { color: var(--p-primary-500); text-decoration: underline; }
:deep(.rich-text-content a:hover) { color: var(--p-primary-600); }
:deep(.rich-text-content blockquote) { border-left: 4px solid var(--p-primary-300); padding-left: 1rem; font-style: italic; color: var(--p-surface-500); margin: 1.5rem 0; }
:deep(.rich-text-content strong) { font-weight: 600; color: var(--p-surface-900); }
:deep(.rich-text-content u) { text-decoration: underline; }

/* Para Dark Mode */
@media (prefers-color-scheme: dark) {
    :deep(.rich-text-content h1), :deep(.rich-text-content h2), :deep(.rich-text-content h3), :deep(.rich-text-content strong) {
        color: var(--p-surface-0);
    }
}
</style>