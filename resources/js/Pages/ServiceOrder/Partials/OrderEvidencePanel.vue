<script setup>
import { computed } from 'vue';

const props = defineProps({
    serviceOrder: Object,
});

const initialEvidenceImages = computed(() => {
    return props.serviceOrder.media?.filter(m => m.collection_name === 'initial-service-order-evidence') || [];
});

const closingEvidenceImages = computed(() => {
    return props.serviceOrder.media?.filter(m => m.collection_name === 'closing-service-order-evidence') || [];
});
</script>

<template>
    <div class="space-y-6 lg:space-y-8 flex flex-col">
        
        <!-- EVIDENCIA INICIAL -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
            
            <!-- Header -->
            <div class="mb-6 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-camera !text-sm text-blue-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Evidencia inicial</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Estado del equipo al recibirlo</p>
                </div>
            </div>
            
            <!-- Galería -->
            <div v-if="initialEvidenceImages.length > 0" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div v-for="(image, index) in initialEvidenceImages" :key="index" class="relative group aspect-square rounded-2xl overflow-hidden border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a]">
                    <Image :src="image.original_url" :alt="`Evidencia inicial ${index + 1}`" preview
                        imageClass="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110 cursor-pointer"
                        class="w-full h-full block" />
                </div>
            </div>
            
            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center text-center py-10 opacity-60 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a]">
                <i class="pi pi-images !text-3xl text-gray-400 mb-3"></i>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin imágenes</p>
                <p class="text-xs text-gray-400 mt-1 max-w-[200px]">No se adjuntaron fotografías iniciales al recibir el equipo.</p>
            </div>
        </div>
        
        <!-- EVIDENCIA DE CIERRE -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
            
            <!-- Header -->
            <div class="mb-6 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                    <i class="pi pi-check-square !text-sm text-green-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Evidencia de cierre</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Fotografías del servicio terminado</p>
                </div>
            </div>
            
            <!-- Galería -->
            <div v-if="closingEvidenceImages.length > 0" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div v-for="(image, index) in closingEvidenceImages" :key="index" class="relative group aspect-square rounded-2xl overflow-hidden border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a]">
                    <Image :src="image.original_url" :alt="`Evidencia de Cierre ${index + 1}`" preview
                        imageClass="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110 cursor-pointer"
                        class="w-full h-full block" />
                </div>
            </div>
            
            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center text-center py-10 opacity-60 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a]">
                <i class="pi pi-images !text-3xl text-gray-400 mb-3"></i>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin imágenes</p>
                <p class="text-xs text-gray-400 mt-1 max-w-[200px]">No se adjuntaron fotografías tras finalizar el servicio.</p>
            </div>
        </div>
        
    </div>
</template>