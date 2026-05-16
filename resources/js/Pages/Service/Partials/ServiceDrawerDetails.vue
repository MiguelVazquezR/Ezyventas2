<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    service: {
        type: Object,
        required: true
    },
    canSeeDetails: {
        type: Boolean,
        default: false
    },
    canEdit: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['go-to-details', 'go-to-edit']);

const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

// --- LÓGICA DE PAGINACIÓN VIRTUAL Y BÚSQUEDA DE VARIANTES ---
const variantSearch = ref('');
const visibleVariantsCount = ref(50); // Mostramos solo 50 iniciales para no congelar el DOM

const filteredVariants = computed(() => {
    if (!props.service || !props.service.variants) return [];
    
    let variants = props.service.variants;
    if (variantSearch.value.trim()) {
        const term = variantSearch.value.toLowerCase().trim();
        variants = variants.filter(v => v.name.toLowerCase().includes(term));
    }
    return variants;
});

const displayedVariants = computed(() => {
    return filteredVariants.value.slice(0, visibleVariantsCount.value);
});

watch(() => props.service, () => {
    variantSearch.value = '';
    visibleVariantsCount.value = 50;
});

const loadMoreVariants = () => {
    visibleVariantsCount.value += 50;
};

// --- TESLA UI PASS-THROUGH (PT) ---
const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Scrollable Content -->
        <div class="flex-grow space-y-6 overflow-y-auto pb-6 px-6 pt-6 custom-scrollbar">
            
            <!-- Info Header -->
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50">
                    <i class="pi pi-wrench !text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">{{ service.name }}</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1.5 flex items-center gap-1">
                        <i class="pi pi-tag !text-[9px]"></i> {{ service.category?.name || 'General' }}
                    </p>
                </div>
            </div>

            <!-- Detalles y Sucursales -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-4">Parámetros operativos</h3>
                
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Duración estimada</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-white flex items-center gap-1">
                        <i class="pi pi-clock !text-xs text-gray-400"></i>
                        {{ service.duration_estimate || 'Variable' }}
                    </span>
                </div>

                <div class="pt-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 block mb-2">Disponible en sucursales:</span>
                    <div class="flex flex-wrap gap-1.5">
                        <Tag 
                            v-for="branch in service.branches" 
                            :key="branch.id" 
                            :value="branch.name" 
                            severity="secondary" 
                            :pt="tagPt" 
                        />
                        <span v-if="!service.branches || service.branches.length === 0" class="text-gray-500 italic text-xs">No asignado</span>
                    </div>
                </div>
            </div>

            <!-- Precios y Variantes -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-4">Estructura de precios</h3>
                
                <!-- Precio Fijo -->
                <div v-if="parseFloat(service.base_price) > 0" class="flex justify-between items-end">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Precio general</span>
                    <span class="font-light tracking-tight text-3xl leading-none m-0 text-gray-900 dark:text-white">
                        {{ formatCurrency(service.base_price) }}
                    </span>
                </div>

                <!-- Variantes -->
                <div v-else-if="parseFloat(service.base_price) === 0" class="flex flex-col gap-3">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-blue-500 m-0">Variantes disponibles ({{ service.variants?.length || 0 }})</span>
                        <i class="pi pi-sitemap text-blue-500 !text-sm"></i>
                    </div>

                    <!-- Buscador Interno -->
                    <IconField iconPosition="left" class="w-full mb-1" v-if="service.variants && service.variants.length > 10">
                        <InputIcon class="pi pi-search !text-sm text-gray-400"></InputIcon>
                        <InputText v-model="variantSearch" placeholder="Buscar variante..." :pt="inputPt" class="!pl-10" />
                    </IconField>

                    <!-- Lista de Variantes -->
                    <ul v-if="displayedVariants.length > 0" class="flex flex-col gap-2 m-0 p-0 list-none mt-2 border-t border-gray-200 dark:border-[#2a2a2a] pt-3">
                        <li v-for="variant in displayedVariants" :key="variant.id" class="flex justify-between items-center group hover:bg-gray-100 dark:hover:bg-[#232323] p-1.5 rounded-lg transition-colors">
                            <span class="font-medium text-sm text-gray-700 dark:text-gray-300 truncate pr-2" :title="variant.name">- {{ variant.name }}</span>
                            <span class="font-mono text-sm text-gray-900 dark:text-white">{{ formatCurrency(variant.price) }}</span>
                        </li>
                    </ul>
                    
                    <div v-else-if="variantSearch" class="text-xs text-center text-gray-500 py-3 italic">
                        No se encontraron coincidencias.
                    </div>

                    <div v-if="filteredVariants.length > visibleVariantsCount" class="mt-2 text-center border-t border-gray-200 dark:border-[#2a2a2a] pt-3">
                        <Button label="Cargar más modelos" size="small" text @click="loadMoreVariants" icon="pi pi-refresh" class="!text-[10px] !uppercase !tracking-widest !font-bold" />
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="space-y-3 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0">Descripción del servicio</h3>
                <div v-if="service.description" 
                     class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 m-0 break-words" 
                     v-html="service.description">
                </div>
                <p v-else class="text-xs text-gray-400 italic m-0">
                    No se ha proporcionado una descripción detallada.
                </p>
            </div>
        </div>

        <!-- Actions Footer -->
        <div class="p-6 border-t border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3 bg-white dark:bg-[#232323]">
            <Button 
                v-if="canSeeDetails" 
                label="Ver detalles completos" 
                icon="pi pi-eye" 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-details')" 
            />
            <Button 
                v-if="canEdit" 
                label="Editar información" 
                icon="pi pi-pencil" 
                severity="secondary" 
                outlined 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-edit')" 
            />
        </div>
    </div>
</template>