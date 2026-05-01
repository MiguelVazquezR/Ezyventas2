<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    note: {
        type: Object,
        required: true
    }
});

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    // Formato de lectura amigable: "jueves, 2 de mayo de 2024"
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
        <div class="p-4 md:p-6 lg:p-8 bg-surface-50 dark:bg-surface-900 min-h-full">
            <div class="max-w-4xl mx-auto">
                <!-- Botón de regreso -->
                <div class="mb-6">
                    <!-- Si vienes del dashboard o cualquier lado, un simple retroceso del navegador es útil, 
                         o lo mandamos a la ruta del dashboard directamente si no hay índice público aún -->
                    <button @click="() => window.history.back()" class="text-primary-600 hover:text-primary-800 flex items-center gap-2 font-medium transition-colors w-max">
                        <i class="pi pi-arrow-left"></i> Volver
                    </button>
                </div>
                
                <!-- Tarjeta Principal del Contenido -->
                <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-sm border border-surface-100 dark:border-surface-700 overflow-hidden">
                    
                    <!-- Encabezado de la Novedad -->
                    <div class="p-6 md:p-8 border-b border-surface-100 dark:border-surface-700 bg-surface-50/50 dark:bg-surface-800/50">
                        <div class="flex items-center gap-3 mb-4">
                            <Tag v-if="note.version" :value="note.version" severity="info" class="!px-3 !py-1 !text-sm" />
                            <span class="text-sm text-surface-500 font-medium capitalize"><i class="pi pi-calendar mr-1 text-xs"></i> {{ formatDate(note.published_at) }}</span>
                        </div>
                        <h1 class="text-3xl md:text-4xl font-bold text-surface-900 dark:text-surface-0 leading-tight">
                            {{ note.title }}
                        </h1>
                    </div>

                    <!-- Contenido Rico -->
                    <div class="p-6 md:p-8">
                        <!-- Aplicamos la clase rich-text-content para restaurar los estilos base que Tailwind resetea -->
                        <div class="rich-text-content text-surface-700 dark:text-surface-300" v-html="note.content"></div>
                    </div>

                    <!-- Galería de Multimedia -->
                    <div v-if="note.media && note.media.length > 0" class="p-6 md:p-8 border-t border-surface-100 dark:border-surface-700 bg-surface-50/30 dark:bg-surface-800/30">
                        <h3 class="text-lg font-bold text-surface-800 dark:text-surface-200 mb-4 flex items-center gap-2">
                            <i class="pi pi-images"></i> Galería adjunta
                        </h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="mediaItem in note.media" :key="mediaItem.id" class="rounded-xl overflow-hidden border border-surface-200 dark:border-surface-700 shadow-sm bg-white dark:bg-surface-900 flex items-center justify-center">
                                
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
/* * Tailwind resetea todos los estilos de HTML básicos (h1, p, ul, etc.).
 * Como PrimeVue Editor usa HTML plano, necesitamos restaurarlos localmente aquí.
 */
:deep(.rich-text-content h1) { font-size: 2.25rem; font-weight: 700; margin-top: 1.5rem; margin-bottom: 1rem; color: var(--p-surface-900); }
:deep(.rich-text-content h2) { font-size: 1.875rem; font-weight: 700; margin-top: 1.5rem; margin-bottom: 1rem; color: var(--p-surface-800); }
:deep(.rich-text-content h3) { font-size: 1.5rem; font-weight: 600; margin-top: 1.25rem; margin-bottom: 0.75rem; color: var(--p-surface-800); }
:deep(.rich-text-content p) { margin-bottom: 1rem; line-height: 1.75; }
:deep(.rich-text-content ul) { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
:deep(.rich-text-content ol) { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 1rem; }
:deep(.rich-text-content li) { margin-bottom: 0.5rem; }
:deep(.rich-text-content a) { color: var(--p-primary-500); text-decoration: underline; }
:deep(.rich-text-content a:hover) { color: var(--p-primary-600); }
:deep(.rich-text-content blockquote) { border-left: 4px solid var(--p-primary-300); padding-left: 1rem; font-style: italic; color: var(--p-surface-500); margin: 1.5rem 0; }
:deep(.rich-text-content strong) { font-weight: 700; color: var(--p-surface-900); }
:deep(.rich-text-content u) { text-decoration: underline; }

/* Para Dark Mode */
@media (prefers-color-scheme: dark) {
    :deep(.rich-text-content h1), :deep(.rich-text-content h2), :deep(.rich-text-content h3), :deep(.rich-text-content strong) {
        color: var(--p-surface-0);
    }
}
</style>