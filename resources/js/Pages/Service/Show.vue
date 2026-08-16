<script setup>
import { ref, computed } from 'vue';
import { router, Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import Button from 'primevue/button';
import Menu from 'primevue/menu';
import Tag from 'primevue/tag';
import Image from 'primevue/image';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';

const props = defineProps({
    service: Object,
    activities: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const hasBilling = computed(() => usePage().props.auth.active_modules?.includes('module_billing'));

const deleteService = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el servicio "${props.service.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('services.destroy', props.service.id));
        }
    });
};

const actionItems = ref([
    { label: 'Crear nuevo', icon: 'pi pi-plus', command: () => router.get(route('services.create')), visible: hasPermission('services.catalog.create') },
    { label: 'Editar servicio', icon: 'pi pi-pencil', command: () => router.get(route('services.edit', props.service.id)), visible: hasPermission('services.catalog.edit') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteService, visible: hasPermission('services.catalog.delete') },
]);

// Lógica para el Menú de Acciones
const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const mainImage = computed(() =>
    props.service.media && props.service.media.length > 0 ? props.service.media[0].original_url : null
);

// --- LÓGICA DE BÚSQUEDA Y OPTIMIZACIÓN DE VARIANTES ---
const variantSearch = ref('');

const filteredVariants = computed(() => {
    if (!props.service.variants) return [];
    let variants = props.service.variants;
    
    if (variantSearch.value.trim()) {
        const term = variantSearch.value.toLowerCase().trim();
        variants = variants.filter(v => v.name.toLowerCase().includes(term));
    }
    
    return variants;
});
// --- FIN LÓGICA DE BÚSQUEDA ---

// Función para formatear moneda
const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <Head :title="`Servicio: ${service.name}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('services.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al catálogo de servicios
                </Link>
            </div>

            <!-- Header Principal -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50">
                        <i class="pi pi-wrench !text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                            {{ service.name }}
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-1.5">
                            <i class="pi pi-tag !text-[10px]"></i> {{ service.category?.name || 'General' }}
                        </p>
                    </div>
                </div>
                
                <div class="w-full sm:w-auto shrink-0 flex gap-2">
                    <Button type="button" label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                    <Menu ref="menu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                
                <!-- Columna Izquierda (Imagen Principal) -->
                <div class="lg:col-span-1 space-y-6 flex flex-col">
                    <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6 lg:p-8 flex flex-col">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Fotografía referencial</h2>
                        <div v-if="mainImage" class="flex-grow flex flex-col items-center justify-center">
                            <div class="w-full aspect-square bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-center overflow-hidden relative group">
                                <Image :src="mainImage" :alt="service.name" preview imageClass="w-full h-full object-contain p-4 transition-transform duration-300 group-hover:scale-105" />
                            </div>
                        </div>
                        <div v-else class="flex flex-col items-center justify-center text-center py-16 opacity-60 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <i class="pi pi-image !text-4xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin imagen</p>
                            <p class="text-xs text-gray-400 mt-1 max-w-[200px]">No se ha subido una imagen para este servicio.</p>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha (Información, Variantes e Historial) -->
                <div class="lg:col-span-2 space-y-6 lg:space-y-8 flex flex-col">
                    
                    <!-- Tarjeta: Información General y Precios -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <!-- Parámetros operativos -->
                            <div>
                                <h2 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-5">Parámetros operativos</h2>
                                <ul class="m-0 p-0 list-none space-y-4">
                                    <li class="flex items-center justify-between border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Categoría</span> 
                                        <Tag v-if="service.category" :value="service.category.name" severity="info" :pt="tagPt" />
                                        <span v-else class="text-xs font-medium text-gray-400 italic">Sin categoría</span>
                                    </li>
                                    <li class="flex items-center justify-between border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Duración estimada</span>
                                        <span class="font-medium text-sm text-gray-900 dark:text-white flex items-center gap-1.5">
                                            <i v-if="service.duration_estimate" class="pi pi-clock !text-xs text-gray-400"></i>
                                            {{ service.duration_estimate || 'No especificada' }}
                                        </span>
                                    </li>
                                    <!-- SECCIÓN SUCURSALES -->
                                    <li class="flex flex-col pt-1">
                                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Disponible en sucursales:</span>
                                        <div class="flex flex-wrap gap-2">
                                            <Tag 
                                                v-for="branch in service.branches" 
                                                :key="branch.id" 
                                                :value="branch.name" 
                                                severity="secondary" 
                                                :pt="tagPt" 
                                            />
                                            <span v-if="!service.branches || service.branches.length === 0" class="text-xs text-gray-400 italic font-medium m-0">No configurado para venta</span>
                                        </div>
                                    </li>

                                    <!-- SAT fiscal codes (only when billing module is active and service has codes) -->
                                    <template v-if="hasBilling && (service.sat_product_code || service.sat_unit_code)">
                                        <li class="flex items-center justify-between border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clave de Servicio (SAT)</span>
                                            <span class="font-mono text-sm font-medium text-gray-900 dark:text-white">{{ service.sat_product_code || '—' }}</span>
                                        </li>
                                        <li class="flex items-center justify-between border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clave Unidad (SAT)</span>
                                            <span class="font-mono text-sm font-medium text-gray-900 dark:text-white">{{ service.sat_unit_code || '—' }}</span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            
                            <!-- Estructura de Precios -->
                            <div>
                                <h2 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-5">Estructura de precios</h2>
                                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 block mb-2">Costo base general</span>
                                    
                                    <span v-if="parseFloat(service.base_price) > 0" class="font-light tracking-tight text-4xl text-green-600 dark:text-green-400 leading-none">
                                        {{ formatCurrency(service.base_price) }}
                                    </span>
                                    
                                    <div v-else-if="service.variants && service.variants.length > 0" class="flex items-center gap-2 text-blue-500">
                                        <i class="pi pi-sitemap !text-xl"></i>
                                        <span class="font-light tracking-tight text-2xl leading-none">Precio variable</span>
                                    </div>
                                    
                                    <span v-else class="font-light tracking-tight text-4xl text-gray-400 dark:text-gray-600 leading-none">
                                        $0.00
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div v-if="service.description" class="mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4">Descripción del servicio</h2>
                            <div class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 leading-relaxed m-0" v-html="service.description"></div>
                        </div>
                    </div>

                    <!-- Tarjeta: Variantes de Servicio -->
                    <div v-if="service.variants && service.variants.length > 0" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        
                        <div class="flex flex-col sm:flex-row sm:justify-between items-start sm:items-center gap-4 mb-6">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 flex items-center gap-2">
                                    <i class="pi pi-sitemap"></i> Variantes y Modelos
                                </h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">{{ filteredVariants.length }} opciones registradas</p>
                            </div>
                            
                            <!-- Buscador Inteligente -->
                            <IconField iconPosition="left" class="w-full sm:w-auto">
                                <InputIcon class="pi pi-search !text-sm text-gray-400"></InputIcon>
                                <InputText v-model="variantSearch" placeholder="Buscar variante/modelo..." :pt="inputPt" class="!pl-10" />
                            </IconField>
                        </div>
                        
                        <!-- Tabla Optimizada con Paginación -->
                        <DataTable 
                            :value="filteredVariants" 
                            responsiveLayout="scroll" 
                            paginator 
                            :rows="10" 
                            :rowsPerPageOptions="[10, 25, 50, 100]"
                            :pt="dataTablePt"
                        >
                            <template #empty>
                                <div class="text-center py-8 text-gray-500 italic text-sm border border-dashed border-gray-200 dark:border-[#3a3a3a] rounded-2xl mx-4">
                                    No se encontraron modelos que coincidan con "{{ variantSearch }}".
                                </div>
                            </template>
                            
                            <Column field="name" header="Variante / Modelo">
                                <template #body="{ data }">
                                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                                </template>
                            </Column>
                            <Column field="duration_estimate" header="Duración">
                                <template #body="{ data }">
                                    <span class="text-xs text-gray-600 dark:text-gray-400">{{ data.duration_estimate || 'N/A' }}</span>
                                </template>
                            </Column>
                            <Column field="price" header="Precio" class="text-right" headerClass="text-right">
                                <template #body="{ data }">
                                    <span class="font-mono font-medium text-green-600 dark:text-green-400 text-sm">
                                        {{ formatCurrency(data.price) }}
                                    </span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>

                    <!-- Tarjeta: Historial de actividad -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center flex-shrink-0 border border-gray-200 dark:border-[#3a3a3a]">
                                <i class="pi pi-history !text-sm text-gray-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Historial de actividad</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Registro de cambios en el catálogo</p>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 lg:p-6 overflow-hidden">
                            <ActivityHistory :activities="activities" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>