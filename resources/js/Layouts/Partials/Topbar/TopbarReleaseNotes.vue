<script setup>
import { ref, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import Drawer from 'primevue/drawer';
import Badge from 'primevue/badge';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import Button from 'primevue/button';
import axios from 'axios';

const page = usePage();
const notifications = computed(() => page.props.notifications || { unread_updates: 0 });

const isReleaseNotesDrawerVisible = ref(false);
const releaseNotes = ref([]);
const isLoadingNotes = ref(false);
const isMarkingRead = ref(false); 

const openReleaseNotes = async () => {
    isReleaseNotesDrawerVisible.value = true;
    
    if (releaseNotes.value.length === 0) {
        isLoadingNotes.value = true;
        try {
            const response = await axios.get(route('release-notes.index'));
            releaseNotes.value = response.data.data;
        } catch (error) {
            console.error("Error al cargar novedades", error);
        } finally {
            isLoadingNotes.value = false;
        }
    }
};

const markAllAsReadDirectly = async () => {
    isMarkingRead.value = true;
    try {
        await axios.post(route('release-notes.mark-all-read'));
        releaseNotes.value.forEach(note => note.is_read = true);
        router.reload({ only: ['notifications'] });
    } catch(e) {
        console.error(e);
    } finally {
        isMarkingRead.value = false;
    }
};

const formatNoteDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
};
</script>

<template>
    <button type="button" class="layout-topbar-action relative mr-2" @click="openReleaseNotes" v-tooltip.bottom="'Novedades'">
        <!-- Anillo de pulso azul detrás del ícono -->
        <span v-if="notifications.unread_updates > 0" class="absolute inset-0 rounded-full bg-blue-500 animate-ping-pause opacity-50 z-0"></span>
        
        <!-- Ícono principal con animación 'swing' de la campana -->
        <i class="pi pi-sparkles text-xl text-gray-400 relative z-10" :class="{'animate-sparkle !text-blue-500': notifications.unread_updates > 0}"></i>
        
        <!-- Badge contador -->
        <Badge v-if="notifications.unread_updates > 0" :value="notifications.unread_updates" class="absolute top-0 right-0 !bg-blue-500 !text-white transform translate-x-1/4 -translate-y-1/4 z-20" />
    </button>

    <Drawer v-model:visible="isReleaseNotesDrawerVisible" position="right" class="w-full sm:!w-[30rem] !bg-gray-100 dark:!bg-gray-900">
        <template #header>
            <div class="flex items-center gap-2">
                <i class="pi pi-sparkles text-blue-500 text-xl"></i>
                <span class="font-semibold text-lg text-surface-800 dark:text-surface-100 tracking-tight">Novedades del sistema</span>
            </div>
        </template>

        <!-- Botón Marcar todo como leído -->
        <div class="px-5 pt-2 pb-4 flex justify-end" v-if="notifications.unread_updates > 0">
             <Button label="Marcar todos como leídos" icon="pi pi-check-circle" size="small" severity="info" text :loading="isMarkingRead" @click="markAllAsReadDirectly" />
        </div>

        <div class="px-5 pb-5 flex flex-col gap-5" v-if="isLoadingNotes">
            <Skeleton width="100%" height="10rem" v-for="i in 3" :key="i" borderRadius="16px"></Skeleton>
        </div>
        
        <div class="px-5 pb-5 flex flex-col gap-5" v-else-if="releaseNotes.length > 0">
            <!-- Tarjetas macOS -->
            <div v-for="note in releaseNotes" :key="note.id" 
                 class="relative overflow-hidden rounded-2xl p-5 border border-white/60 dark:border-surface-700 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] bg-gradient-to-br from-blue-50/90 to-white/80 dark:from-blue-900/20 dark:to-surface-800/80 backdrop-blur-md transition-all hover:shadow-md hover:-translate-y-0.5">
                
                <!-- Puntito de no leído -->
                <div v-if="!note.is_read" class="absolute top-0 right-0 w-2.5 h-2.5 bg-blue-500 rounded-full m-4 shadow-[0_0_8px_rgba(59,130,246,0.5)]"></div>
                
                <div class="flex items-center gap-3 mb-3">
                    <Tag v-if="note.version" :value="note.version" class="!bg-blue-100 !text-blue-700 dark:!bg-blue-900/50 dark:!text-blue-300 !rounded-md !px-2 !py-0.5 !text-[10px] font-semibold tracking-wide" />
                    <span class="text-xs text-surface-500 font-medium">{{ formatNoteDate(note.published_at) }}</span>
                </div>
                
                <h3 class="font-semibold text-lg text-surface-900 dark:text-surface-0 mb-1.5 leading-tight tracking-tight">{{ note.title }}</h3>
                <p class="text-sm text-surface-600 dark:text-surface-400 mb-4 line-clamp-2 leading-relaxed break-words">{{ note.excerpt }}</p>
                
                <Link :href="route('release-notes.show', note.id)" class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 hover:underline flex items-center gap-1 w-max" @click="isReleaseNotesDrawerVisible = false">
                    Saber más detalles <i class="pi pi-arrow-right text-xs mt-0.5"></i>
                </Link>
            </div>
        </div>
        
        <div class="p-6 flex flex-col items-center justify-center text-center h-full opacity-60" v-else>
            <i class="pi pi-sparkles text-5xl text-surface-400 mb-4"></i>
            <p class="text-surface-600 dark:text-surface-400 font-medium">Aún no hay novedades publicadas.</p>
            <p class="text-sm text-surface-500">Te avisaremos por aquí cuando tengamos nuevas funciones.</p>
        </div>
    </Drawer>
</template>