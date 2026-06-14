<script setup>
import { ref, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
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

// --- TESLA UI PASS-THROUGH (PT) ---
const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-0' }, // Quitamos el padding global para hacer sticky borders
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <button type="button" class="relative mr-2 flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors" @click="openReleaseNotes" v-tooltip.bottom="'Novedades'">
        <!-- Anillo de pulso sutil detrás del ícono -->
        <span v-if="notifications.unread_updates > 0" class="absolute inset-0 rounded-full bg-blue-500/20 animate-ping z-0"></span>
        <i class="pi pi-sparkles !text-xl text-gray-400 relative z-10" :class="{'!text-blue-500': notifications.unread_updates > 0}"></i>
        
        <!-- Badge minimalista (Telemetría) -->
        <span v-if="notifications.unread_updates > 0" class="absolute top-1 right-1 flex h-3 w-3 z-20">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500 text-[8px] font-bold text-white items-center justify-center">{{ notifications.unread_updates }}</span>
        </span>
    </button>

    <Drawer v-model:visible="isReleaseNotesDrawerVisible" position="right" class="w-full sm:!w-[30rem]" :pt="drawerPt">
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-sparkles text-blue-500 !text-sm"></i>
                </div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Novedades del sistema</h2>
            </div>
        </template>

        <div class="h-full flex flex-col">
            <!-- Botón Marcar todo como leído -->
            <div class="px-6 py-4 flex justify-between items-center border-b border-gray-100 dark:border-[#3a3a3a] bg-gray-50/50 dark:bg-[#1a1a1a]/50 sticky top-0 z-10" v-if="notifications.unread_updates > 0">
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                    {{ notifications.unread_updates }} pendientes
                </p>
                <Button label="Marcar como leídos" icon="pi pi-check" size="small" text @click="markAllAsReadDirectly" :loading="isMarkingRead" 
                    class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold !px-3 !py-1.5" />
            </div>

            <!-- Loading Skeletons -->
            <div class="p-6 flex flex-col gap-6" v-if="isLoadingNotes">
                <Skeleton width="100%" height="10rem" v-for="i in 3" :key="i" class="!rounded-2xl !bg-gray-100 dark:!bg-[#1a1a1a]"></Skeleton>
            </div>
            
            <!-- Listado de Novedades (Estilo Tesla UI) -->
            <div class="p-6 flex flex-col gap-5 overflow-y-auto" v-else-if="releaseNotes.length > 0">
                <div v-for="note in releaseNotes" :key="note.id" 
                    class="bg-white dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] relative group transition-colors">
                    
                    <!-- Puntito LED de no leído -->
                    <div v-if="!note.is_read" class="absolute top-6 right-6 w-2 h-2 bg-blue-500 rounded-full shadow-[0_0_8px_rgba(59,130,246,0.8)]"></div>
                    
                    <div class="flex items-center gap-3 mb-4">
                        <span v-if="note.version" class="bg-gray-100 dark:bg-[#2a2a2a] text-gray-700 dark:text-gray-300 rounded-full px-2 py-0.5 text-[10px] font-bold font-mono tracking-widest border border-gray-200 dark:border-[#3a3a3a]">{{ note.version }}</span>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">{{ formatNoteDate(note.published_at) }}</span>
                    </div>
                    
                    <h3 class="text-base font-medium text-gray-900 dark:text-white m-0 mb-2 tracking-tight pr-6">{{ note.title }}</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5 line-clamp-2 leading-relaxed m-0">{{ note.excerpt }}</p>
                    
                    <Link :href="route('release-notes.show', note.id)" class="text-[10px] font-bold uppercase tracking-widest text-primary-500 hover:text-primary-400 flex items-center gap-1.5 transition-colors w-max group/link" @click="isReleaseNotesDrawerVisible = false">
                        Saber más detalles <i class="pi pi-arrow-right !text-[10px] group-hover/link:translate-x-1 transition-transform"></i>
                    </Link>
                </div>
            </div>
            
            <!-- Empty State -->
            <div class="p-6 flex flex-col items-center justify-center text-center flex-grow opacity-60" v-else>
                <i class="pi pi-sparkles !text-4xl text-gray-400 mb-4"></i>
                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1 tracking-tight">Sin novedades recientes</p>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Te avisaremos cuando haya actualizaciones.</p>
            </div>
        </div>
    </Drawer>
</template>