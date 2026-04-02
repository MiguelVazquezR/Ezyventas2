<script setup>
import { computed } from 'vue';
import Image from 'primevue/image';

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
    <div class="space-y-6">
        <!-- EVIDENCIA INICIAL -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold border-b pb-3 mb-4 flex items-center gap-2">
                <i class="pi pi-camera text-gray-500"></i> Evidencia inicial
            </h2>
            
            <div v-if="initialEvidenceImages.length > 0" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div v-for="(image, index) in initialEvidenceImages" :key="index" class="relative group aspect-square">
                    <!-- PrimeVue Image con atributo 'preview' activa el zoom y rotación -->
                    <Image :src="image.original_url" :alt="`Evidencia inicial ${index + 1}`" preview
                        imageClass="w-full h-full object-cover rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm transition-all duration-300 group-hover:brightness-90 cursor-pointer"
                        class="w-full h-full block" />
                </div>
            </div>
            
            <div v-else class="text-center text-gray-500 py-6 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">
                <i class="pi pi-image text-3xl mb-2 text-gray-400"></i>
                <p>No se adjuntaron imágenes iniciales.</p>
            </div>
        </div>
        
        <!-- EVIDENCIA DE CIERRE -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold border-b pb-3 mb-4 flex items-center gap-2">
                <i class="pi pi-check-square text-green-500"></i> Evidencia de cierre de servicio
            </h2>
            
            <div v-if="closingEvidenceImages.length > 0" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div v-for="(image, index) in closingEvidenceImages" :key="index" class="relative group aspect-square">
                    <Image :src="image.original_url" :alt="`Evidencia de Cierre ${index + 1}`" preview
                        imageClass="w-full h-full object-cover rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm transition-all duration-300 group-hover:brightness-90 cursor-pointer"
                        class="w-full h-full block" />
                </div>
            </div>
            
            <div v-else class="text-center text-gray-500 py-6 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">
                <i class="pi pi-image text-3xl mb-2 text-gray-400"></i>
                <p>No se adjuntaron imágenes de cierre.</p>
            </div>
        </div>
    </div>
</template>