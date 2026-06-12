<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
import { useDebounceFn } from '@vueuse/core';
import { useToast } from "primevue/usetoast";
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Checkbox from 'primevue/checkbox';
import DataView from 'primevue/dataview';
import Paginator from 'primevue/paginator';
import Toast from 'primevue/toast';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';

const props = defineProps({
    products: Object,
    categories: Array,
    filters: Object,
    totalImportedCount: Number,
});

const toast = useToast();
const search = ref(props.filters.search || '');

// Selección múltiple persistente
const selectedCategories = ref(
    props.filters.category_ids ? props.filters.category_ids.map(id => parseInt(id)) : []
);

const loadingImport = ref(false);
const selectedProducts = ref([]);

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const formatNumber = (num) => {
    return new Intl.NumberFormat('es-MX').format(num || 0);
};

// --- Lógica de Búsqueda y Filtrado ---
const onSearch = useDebounceFn(() => { fetchProducts(1); }, 500);

const fetchProducts = (page = 1) => {
    router.get(route('products.base-catalog.index'), {
        search: search.value,
        category_ids: selectedCategories.value,
        page: page
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true
    });
};

watch(selectedCategories, () => fetchProducts(1), { deep: true });

const onPageChange = (event) => fetchProducts(event.page + 1);

// --- Lógica de Selección ---
const toggleSelection = (productId) => {
    const index = selectedProducts.value.indexOf(productId);
    if (index === -1) {
        selectedProducts.value.push(productId);
    } else {
        selectedProducts.value.splice(index, 1);
    }
};

const isSelected = (id) => selectedProducts.value.includes(id);

const toggleSelectAllPage = () => {
    const importableIds = props.products.data
        .filter(p => !p.is_imported)
        .map(p => p.id);
        
    const allSelected = importableIds.every(id => selectedProducts.value.includes(id));
    
    if (allSelected) {
        selectedProducts.value = selectedProducts.value.filter(id => !importableIds.includes(id));
    } else {
        importableIds.forEach(id => {
            if (!selectedProducts.value.includes(id)) selectedProducts.value.push(id);
        });
    }
};

const importProducts = () => {
    if (selectedProducts.value.length === 0) return;

    loadingImport.value = true;
    
    router.post(route('products.base-catalog.import'), { 
        products: selectedProducts.value 
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedProducts.value = [];
        },
        onError: () => {
             toast.add({ severity: 'error', summary: 'Error', detail: 'Hubo un problema al importar', life: 3000 });
        },
        onFinish: () => {
            loadingImport.value = false;
        }
    });
};

const clearSelection = () => selectedProducts.value = [];

// --- TESLA UI PASS-THROUGH (PT) ---
const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const checkboxPt = {
    box: { class: 'dark:!bg-[#232323] dark:!border-[#3a3a3a] hover:dark:!border-primary-500 !rounded-md transition-colors' }
};

const paginatorPt = { 
    root: { class: 'dark:!bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] !rounded-2xl !p-2 mt-6' },
    pageButton: ({context}) => ({ class: context.active ? '!bg-primary-500 !text-white !font-bold !rounded-xl' : '!rounded-xl dark:!text-gray-400 hover:dark:!bg-[#2a2a2a]' }),
    firstPageButton: { class: '!rounded-xl dark:!text-gray-400 hover:dark:!bg-[#2a2a2a]' },
    previousPageButton: { class: '!rounded-xl dark:!text-gray-400 hover:dark:!bg-[#2a2a2a]' },
    nextPageButton: { class: '!rounded-xl dark:!text-gray-400 hover:dark:!bg-[#2a2a2a]' },
    lastPageButton: { class: '!rounded-xl dark:!text-gray-400 hover:dark:!bg-[#2a2a2a]' },
};
</script>

<template>
    <Head title="Catálogo Base" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6 relative pb-32">
            <Toast />
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Catálogo base</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-teal-500 shadow-[0_0_8px_rgba(20,184,166,0.8)] animate-pulse"></span>
                        Explora e importa productos a tu inventario
                    </p>
                </div>

                <ProductNavigation class="mb-8" />

                <!-- Layout Principal: Sidebar y Grid -->
                <div class="flex flex-col lg:flex-row gap-6 lg:gap-8">
                    
                    <!-- Sidebar Filtros -->
                    <div class="w-full lg:w-72 flex-shrink-0 flex flex-col gap-6">
                        
                        <!-- Tarjeta Informativa -->
                        <div class="bg-blue-50 dark:bg-blue-900/10 p-5 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                            <div class="flex items-center gap-4 mb-3">
                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-800/40 rounded-full text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-200 dark:border-blue-700/50">
                                    <i class="pi pi-shop !text-lg"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] text-blue-600 dark:text-blue-400 uppercase font-bold tracking-widest m-0">Tu inventario</p>
                                    <p class="text-2xl font-light tracking-tight text-blue-900 dark:text-white m-0 leading-none">
                                        {{ formatNumber(totalImportedCount) }}
                                    </p>
                                </div>
                            </div>
                            <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                                Productos globales actualmente importados en tu catálogo local.
                            </p>
                        </div>

                        <!-- Buscador -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 block mb-3 m-0">Búsqueda global</span>
                            <IconField iconPosition="left">
                                <InputIcon class="pi pi-search !text-sm text-gray-400"></InputIcon>
                                <InputText v-model="search" @input="onSearch" placeholder="Nombre o SKU..." :pt="inputPt" class="!pl-10" />
                            </IconField>
                        </div>
                        
                        <!-- Categorías -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex-grow flex flex-col max-h-[500px]">
                            <div class="flex justify-between items-center mb-4">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Categorías</span>
                                <button v-if="selectedCategories.length > 0" @click="selectedCategories = []" class="text-[10px] font-bold uppercase tracking-widest text-primary-500 hover:text-primary-400 transition-colors bg-transparent border-none p-0 cursor-pointer">
                                    Limpiar
                                </button>
                            </div>
                            <div class="overflow-y-auto custom-scrollbar flex-grow pr-2">
                                <ul class="m-0 p-0 list-none space-y-3">
                                    <li v-for="cat in categories" :key="cat.id" class="flex items-center gap-3 group">
                                        <Checkbox v-model="selectedCategories" :inputId="'cat'+cat.id" :value="cat.id" :pt="checkboxPt" />
                                        <label :for="'cat'+cat.id" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer select-none truncate group-hover:text-primary-500 transition-colors m-0">
                                            {{ cat.name }}
                                        </label>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Main Grid -->
                    <div class="flex-1 flex flex-col min-w-0">
                        
                        <!-- Top Bar del Grid -->
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Explorando <span class="font-bold text-gray-900 dark:text-white font-mono">{{ formatNumber(products.total) }}</span> productos base
                            </div>
                            <Button label="Seleccionar todos (página)" icon="pi pi-check-square" size="small" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold w-full sm:w-auto" @click="toggleSelectAllPage" />
                        </div>

                        <!-- DataView Grid -->
                        <DataView :value="products.data" layout="grid" :pt="{ content: { class: '!bg-transparent' } }">
                            <template #grid="slotProps">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 w-full">
                                    <div v-for="item in slotProps.items" :key="item.id" 
                                         class="group relative bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl transition-all duration-200 overflow-hidden flex flex-col select-none border cursor-pointer h-[280px]"
                                         :class="[
                                            isSelected(item.id) 
                                                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10 shadow-[0_0_15px_rgba(59,130,246,0.15)] z-10' 
                                                : (item.is_imported 
                                                    ? 'border-green-500 dark:border-green-800/60 bg-green-50/50 dark:bg-green-900/10 opacity-80' 
                                                    : 'border-gray-200 dark:border-[#3a3a3a] hover:border-gray-300 dark:hover:border-gray-500')
                                         ]"
                                         @click="!item.is_imported && toggleSelection(item.id)"
                                    >
                                        
                                        <!-- Etiqueta Importado -->
                                        <div v-if="item.is_imported" class="absolute top-0 inset-x-0 bg-green-500 text-white text-[9px] font-bold text-center py-1 z-20 shadow-sm tracking-widest uppercase">
                                            <i class="pi pi-check-circle !text-[9px] mr-1"></i> Ya en tu tienda
                                        </div>

                                        <!-- Checkbox visual superior derecho -->
                                        <div v-if="!item.is_imported" class="absolute top-3 right-3 z-20">
                                            <div class="w-6 h-6 rounded-md border flex items-center justify-center transition-all duration-200 shadow-sm"
                                                 :class="isSelected(item.id) ? 'bg-primary-500 border-primary-500 scale-110' : 'bg-white dark:bg-[#232323] border-gray-300 dark:border-[#4a4a4a] group-hover:border-primary-400'">
                                                <i v-if="isSelected(item.id)" class="pi pi-check text-white !text-xs font-bold"></i>
                                            </div>
                                        </div>

                                        <!-- Imagen -->
                                        <div class="relative h-40 flex items-center justify-center p-4 bg-white dark:bg-[#232323] border-b border-gray-100 dark:border-[#2a2a2a] shrink-0" :class="item.is_imported ? 'mt-6' : ''"> 
                                            <img v-if="item.image_url" :src="item.image_url" class="max-h-full max-w-full object-contain mix-blend-multiply dark:mix-blend-normal" :class="item.is_imported ? 'grayscale-[0.5]' : ''" />
                                            <i v-else class="pi pi-image !text-4xl text-gray-300 dark:text-gray-600"></i>
                                            
                                            <span class="absolute bottom-2 left-2 text-[9px] uppercase tracking-widest font-bold bg-gray-100 dark:bg-[#1a1a1a] text-gray-600 dark:text-gray-400 px-2 py-1 rounded-full border border-gray-200 dark:border-[#3a3a3a] max-w-[80%] truncate">
                                                {{ item.category }}
                                            </span>
                                        </div>

                                        <!-- Info Producto -->
                                        <div class="p-4 flex-1 flex flex-col justify-between min-h-0 bg-transparent">
                                            <h4 class="font-medium text-gray-900 dark:text-gray-100 text-sm leading-snug line-clamp-2 m-0" :title="item.name">
                                                {{ item.name }}
                                            </h4>
                                            
                                            <div class="mt-auto flex items-end justify-between pt-2">
                                                <div class="flex flex-col">
                                                    <span class="text-[10px] text-gray-500 font-mono m-0 mb-0.5">SKU: {{ item.sku }}</span>
                                                    <span class="text-base font-bold text-primary-600 dark:text-primary-400 m-0 leading-none">{{ formatCurrency(item.suggested_price) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </DataView>

                        <div class="mt-auto pt-6 flex justify-center">
                             <Paginator :rows="products.per_page" :totalRecords="products.total" :first="(products.current_page - 1) * products.per_page" @page="onPageChange" :pt="paginatorPt" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Floating Action Bar (Aparece cuando hay items seleccionados) -->
            <transition 
                enter-active-class="transform transition duration-300 ease-out" 
                enter-from-class="translate-y-full opacity-0" 
                enter-to-class="translate-y-0 opacity-100" 
                leave-active-class="transform transition duration-200 ease-in" 
                leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-full opacity-0">
                
                <div v-if="selectedProducts.length > 0" class="fixed bottom-8 left-1/2 transform -translate-x-1/2 z-50 bg-white dark:bg-[#232323] p-3 rounded-full shadow-2xl border border-gray-200 dark:border-[#4a4a4a] flex items-center gap-4 max-w-[95vw] sm:max-w-[90vw]">
                    
                    <div class="flex items-center gap-3 pl-3">
                        <div class="w-8 h-8 rounded-full bg-primary-500 text-white flex items-center justify-center font-bold text-sm shadow-[0_0_10px_rgba(59,130,246,0.5)]">
                            {{ selectedProducts.length }}
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 dark:text-gray-400 hidden sm:inline m-0">productos seleccionados</span>
                    </div>
                    
                    <div class="h-8 w-px bg-gray-200 dark:bg-[#3a3a3a]"></div>
                    
                    <div class="flex items-center gap-2 pr-1">
                        <Button icon="pi pi-times" text rounded severity="secondary" v-tooltip.top="'Limpiar selección'" @click="clearSelection" class="!w-10 !h-10 !text-gray-400 hover:!bg-gray-100 dark:hover:!bg-[#1a1a1a]" />
                        <Button :label="loadingImport ? 'Importando...' : 'Importar al inventario'" icon="pi pi-download" :loading="loadingImport" @click="importProducts()" severity="primary" class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" />
                    </div>
                </div>
            </transition>
        </div>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 20px; }
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #4b5563; }
</style>