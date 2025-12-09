<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
import { useDebounceFn } from '@vueuse/core'; // Asegúrate de tener instalado @vueuse/core
import { useToast } from "primevue/usetoast";

const props = defineProps({
    products: Object,
    categories: Array,
    filters: Object,
    totalImportedCount: Number,
});

const toast = useToast();
const search = ref(props.filters.search || '');

// CAMBIO 1: Ahora es un array para selección múltiple.
// Convertimos a números enteros porque a veces vienen como strings del query param
const selectedCategories = ref(
    props.filters.category_ids ? props.filters.category_ids.map(id => parseInt(id)) : []
);

const loadingImport = ref(false);
const selectedProducts = ref([]); // Persistente

const formatNumber = (num) => {
    return new Intl.NumberFormat('es-MX').format(num);
};

// --- Lógica de Búsqueda y Filtrado ---
const onSearch = useDebounceFn(() => { fetchProducts(1); }, 500);

const fetchProducts = (page = 1) => {
    // CAMBIO 2: NO limpiamos selectedProducts aquí. Mantenemos la selección entre páginas.
    
    router.get(route('products.base-catalog.index'), {
        search: search.value,
        category_ids: selectedCategories.value, // Enviamos array
        page: page
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true
    });
};

// Observar cambios profundos en el array de categorías
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

// Seleccionar todos los visibles (Select All on Page)
const toggleSelectAllPage = () => {
    const importableIds = props.products.data
        .filter(p => !p.is_imported)
        .map(p => p.id);
        
    // Verificamos si todos los importables de ESTA página ya están seleccionados
    const allSelected = importableIds.every(id => selectedProducts.value.includes(id));
    
    if (allSelected) {
        // Deseleccionar solo los de esta página
        selectedProducts.value = selectedProducts.value.filter(id => !importableIds.includes(id));
    } else {
        // Agregar los que falten
        importableIds.forEach(id => {
            if (!selectedProducts.value.includes(id)) selectedProducts.value.push(id);
        });
    }
};

// Seleccionar toda una categoría (Helper visual para el usuario)
const selectCategoryBatch = (catId) => {
    // Nota: Esto solo selecciona lo que está visible en la página actual que coincida con la categoría.
    // Seleccionar "todo lo de la base de datos" requeriría lógica backend compleja que evitamos por seguridad.
    const pageItemsInCategory = props.products.data
        .filter(p => p.category_id === catId || p.category === props.categories.find(c=>c.id===catId)?.name) // Ajuste según tu estructura de datos
        .map(p => p.id);
        
    pageItemsInCategory.forEach(id => {
        if(!selectedProducts.value.includes(id)) selectedProducts.value.push(id);
    });
};

// --- Acción de Importar ---
const importProducts = () => {
    if (selectedProducts.value.length === 0) return;

    loadingImport.value = true;
    
    router.post(route('products.base-catalog.import'), { 
        products: selectedProducts.value 
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Éxito', detail: 'Productos importados correctamente', life: 3000 });
            selectedProducts.value = []; // Ahora sí limpiamos
        },
        onError: () => {
             toast.add({ severity: 'error', summary: 'Error', detail: 'Hubo un problema al importar', life: 3000 });
        },
        onFinish: () => {
            loadingImport.value = false;
        }
    });
};

// Helper para limpiar selección manual
const clearSelection = () => selectedProducts.value = [];

</script>

<template>
    <Head title="Catálogo Base" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 dark:bg-gray-900 min-h-screen relative pb-32">
            <Toast />
            
            <div class="mb-4">
                <ProductNavigation />
            </div>

            <div class="flex flex-col lg:flex-row gap-6">
                <div class="w-full lg:w-60 flex-shrink-0 space-y-4">
                    
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 p-4 rounded-lg shadow-sm">
                        <div class="flex items-center gap-3 mb-1">
                            <div class="size-8 bg-blue-100 dark:bg-blue-800 rounded-full text-blue-600 dark:text-blue-200 flex items-center justify-center">
                                <i class="pi pi-shop !text-lg"></i>
                            </div>
                            <div>
                                <p class="text-xs text-blue-600 dark:text-blue-300 uppercase font-bold tracking-wider m-0">Tu Tienda</p>
                                <p class="text-xl font-extrabold text-blue-800 dark:text-white m-0">
                                    {{ formatNumber(totalImportedCount) }}
                                </p>
                            </div>
                        </div>
                        <p class="text-xs text-blue-600/80 dark:text-blue-300/80 leading-tight">
                            Productos del catálogo base ya disponibles para venta.
                        </p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 p-3 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Buscar</span>
                        <span class="p-input-icon-left w-full">
                            <InputText v-model="search" @input="onSearch" placeholder="Nombre / SKU" class="w-full p-inputtext-sm text-sm" />
                        </span>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 p-3 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
                        <div class="flex justify-between items-center mb-2">
                            <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Categorías</span>
                            <span v-if="selectedCategories.length > 0" class="text-xs text-primary-600 cursor-pointer hover:underline" @click="selectedCategories = []">Limpiar</span>
                        </div>
                        <div class="max-h-[400px] overflow-y-auto custom-scrollbar space-y-1 pl-1">
                             <div v-for="cat in categories" :key="cat.id" class="flex items-center gap-2 py-1">
                                <Checkbox v-model="selectedCategories" :inputId="'cat'+cat.id" :value="cat.id" />
                                <label :for="'cat'+cat.id" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer select-none flex-1 truncate hover:text-primary-600">{{ cat.name }}</label>
                             </div>
                        </div>
                    </div>
                </div>

                <div class="flex-1">
                    <div class="flex flex-wrap justify-between items-center mb-3 bg-white dark:bg-gray-800 p-3 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
                        <div class="text-sm text-gray-500">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ formatNumber(products.total) }}</span> resultados encontrados
                        </div>
                        <div class="flex gap-2">
                             <Button label="Seleccionar página" icon="pi pi-check-square" size="small" severity="secondary" text class="!text-xs" @click="toggleSelectAllPage" />
                        </div>
                    </div>

                    <DataView :value="products.data" layout="grid" :pt="{ content: { class: '!bg-transparent' } }">
                        <template #grid="slotProps">
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 w-full">
                                <div v-for="(item, index) in slotProps.items" :key="item.id" 
                                     class="group relative bg-white dark:bg-gray-800 rounded-md transition-all duration-150 overflow-hidden flex flex-col select-none border"
                                     :class="[
                                        isSelected(item.id) 
                                            ? 'ring-2 ring-primary-500 border-primary-500 shadow-md z-10' 
                                            : '',
                                        // NUEVO: Estilo 'En Tienda' muy notorio (Borde verde + fondo sutil verde)
                                        item.is_imported 
                                            ? 'border-green-500 dark:border-green-500 bg-green-50/30 dark:bg-green-900/10' 
                                            : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:shadow-md cursor-pointer bg-white dark:bg-gray-800'
                                     ]"
                                     @click="!item.is_imported && toggleSelection(item.id)"
                                >
                                    
                                    <div v-if="item.is_imported" class="absolute top-0 inset-x-0 bg-green-500 text-white text-[10px] font-bold text-center py-0.5 z-20 shadow-sm tracking-widest uppercase">
                                        En Tu Tienda
                                    </div>

                                    <div v-if="!item.is_imported" class="absolute top-2 right-2 z-20">
                                        <div class="w-5 h-5 rounded border flex items-center justify-center transition-all duration-200 shadow-sm"
                                             :class="isSelected(item.id) ? 'bg-primary-600 border-primary-600 scale-110' : 'bg-white border-gray-300 group-hover:border-primary-400'">
                                            <i v-if="isSelected(item.id)" class="pi pi-check text-white" style="font-size: 0.7rem; font-weight: 900;"></i>
                                        </div>
                                    </div>

                                    <div class="relative h-32 flex items-center justify-center p-2 border-b border-gray-50 dark:border-gray-700"
                                         :class="item.is_imported ? 'mt-4' : ''"> <img v-if="item.image_url" :src="item.image_url" class="max-h-full max-w-full object-contain" :class="item.is_imported ? 'opacity-80 grayscale-[0.3]' : ''" />
                                        <i v-else class="pi pi-image !text-3xl text-gray-400"></i>
                                        
                                        <span class="absolute bottom-1 left-1 text-[10px] bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-1.5 py-0.5 rounded">
                                            {{ item.category }}
                                        </span>
                                    </div>

                                    <div class="p-2 flex-1 flex flex-col">
                                        <div class="mb-1">
                                            <h4 class="font-medium text-gray-800 dark:text-gray-200 text-xs leading-tight line-clamp-2 h-8" :title="item.name">
                                                {{ item.name }}
                                            </h4>
                                        </div>
                                        
                                        <div class="mt-auto flex items-end justify-between">
                                            <div>
                                                <div class="text-[10px] text-gray-400">{{ item.sku }}</div>
                                                <span class="text-sm font-bold text-gray-900 dark:text-white">${{ formatNumber(item.suggested_price) }}</span>
                                            </div>
                                            
                                            <div v-if="item.is_imported">
                                                <i class="pi pi-check-circle text-green-600 text-lg"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </DataView>

                    <div class="mt-6 flex justify-center">
                         <Paginator :rows="products.per_page" :totalRecords="products.total" :first="(products.current_page - 1) * products.per_page" @page="onPageChange" 
                            :pt="{ root: { class: '!bg-transparent !border-none' }, pageButton: ({context}) => ({ class: context.active ? '!bg-primary-100 !text-primary-700 !font-bold' : '' }) }" />
                    </div>
                </div>
            </div>

            <transition enter-active-class="transform transition duration-300 ease-out" enter-from-class="translate-y-full opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transform transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-full opacity-0">
                <div v-if="selectedProducts.length > 0" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-2 rounded-full shadow-xl border border-gray-200 dark:border-gray-700 flex items-center gap-4 max-w-[90vw]">
                    <div class="flex items-center gap-2 pl-2">
                        <span class="bg-primary-600 text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ selectedProducts.length }}</span>
                        <span class="text-sm font-medium hidden sm:inline">seleccionados</span>
                    </div>
                    <div class="h-4 w-px bg-gray-300 dark:bg-gray-600"></div>
                    <div class="flex items-center gap-2">
                         <Button icon="pi pi-times" text rounded size="small" severity="secondary" aria-label="Cancelar" @click="clearSelection" />
                        <Button :label="loadingImport ? 'Importando...' : 'Importar'" icon="pi pi-download" size="small" rounded :loading="loadingImport" @click="importProducts()" class="!px-4 !py-1 !text-sm" />
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
.group:hover { transform: translateY(-2px); }
</style>