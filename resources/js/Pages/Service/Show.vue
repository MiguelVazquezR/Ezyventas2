<script setup>
import { ref, computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    service: Object,
    activities: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Catálogo de servicios', url: route('services.index') },
    { label: props.service.name }
]);

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

// Lógica para el nuevo Menú de Acciones
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

</script>

<template>
    <Head :title="`Servicio: ${service.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ service.name }}</h1>
            
            <!-- Botón y Menú de Acciones -->
            <div class="mt-4 sm:mt-0">
                <Button label="Acciones" icon="pi pi-chevron-down" iconPos="right" severity="secondary" outlined @click="toggleMenu" />
                <Menu ref="menu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Columna Izquierda (Imagen Principal) -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div v-if="mainImage">
                        <div class="flex justify-center mb-2 bg-gray-50 dark:bg-gray-700 rounded-lg overflow-hidden border border-gray-100 dark:border-gray-600">
                            <Image :src="mainImage" :alt="service.name" preview imageClass="w-full h-80 object-contain p-2" />
                        </div>
                        <p class="text-xs text-center text-gray-400 mt-2"><i class="pi pi-search-plus mr-1"></i> Clic para ampliar</p>
                    </div>
                    <div v-else class="text-center text-gray-500 py-8 flex flex-col items-center">
                        <i class="pi pi-image !text-5xl mb-2 text-gray-300"></i>
                        <p>No hay imagen registrada.</p>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha (Información, Variantes e Historial) -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Tarjeta: Información General y Precios -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                                Información general
                            </h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Categoría</span> 
                                    <Tag v-if="service.category" :value="service.category.name" severity="info" rounded />
                                    <span v-else class="font-medium text-gray-400 italic">Sin categoría</span>
                                </li>
                                <li class="flex items-center justify-between mt-3">
                                    <span class="text-gray-500 dark:text-gray-400">Duración estimada</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200 flex items-center gap-1">
                                        <i v-if="service.duration_estimate" class="pi pi-clock text-gray-400"></i>
                                        {{ service.duration_estimate || 'No especificada' }}
                                    </span>
                                </li>
                                <!-- SECCIÓN SUCURSALES -->
                                <li class="flex flex-col mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                    <span class="text-gray-500 dark:text-gray-400 mb-2 font-medium">Disponible en:</span>
                                    <div class="flex flex-wrap gap-1">
                                        <Tag 
                                            v-for="branch in service.branches" 
                                            :key="branch.id" 
                                            :value="branch.name" 
                                            severity="secondary" 
                                            rounded 
                                        />
                                        <span v-if="!service.branches || service.branches.length === 0" class="text-gray-400 italic text-sm">No configurado</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        
                        <div>
                            <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                                Precio
                            </h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between items-baseline">
                                    <span class="text-gray-500 dark:text-gray-400">Precio General</span>
                                    
                                    <span v-if="parseFloat(service.base_price) > 0" class="font-bold text-2xl text-green-600 dark:text-green-400">
                                        {{ formatCurrency(service.base_price) }}
                                    </span>
                                    
                                    <span v-else-if="service.variants && service.variants.length > 0" class="font-semibold text-lg text-gray-500 italic">
                                        Variable
                                    </span>
                                    
                                    <span v-else class="font-bold text-2xl text-gray-800 dark:text-gray-200">
                                        $0.00
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <div v-if="service.description" class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h5 class="font-semibold mb-2 text-gray-800 dark:text-gray-200">Descripción</h5>
                        <div class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300" v-html="service.description"></div>
                    </div>
                </div>

                <!-- Tarjeta: Variantes de Servicio -->
                <div v-if="service.variants && service.variants.length > 0" class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 border-b border-gray-200 dark:border-gray-700 pb-4 mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2 m-0">
                            <i class="pi pi-sitemap text-blue-500"></i>
                            Modelos y Variantes ({{ filteredVariants.length }})
                        </h2>
                        
                        <!-- Buscador Inteligente -->
                        <IconField iconPosition="left" class="w-full sm:w-auto">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="variantSearch" placeholder="Buscar variante/modelo..." class="w-72" />
                        </IconField>
                    </div>
                    
                    <!-- Tabla Optimizada con Paginación -->
                    <DataTable 
                        :value="filteredVariants" 
                        class="p-datatable-sm" 
                        responsiveLayout="scroll" 
                        rowHover 
                        stripedRows
                        paginator 
                        :rows="10" 
                        :rowsPerPageOptions="[10, 25, 50, 100]"
                        paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                        currentPageReportTemplate="Mostrando {first} al {last} de {totalRecords}"
                    >
                        <template #empty>
                            <div class="text-center p-4 text-gray-500 italic">
                                No se encontraron modelos que coincidan con la búsqueda.
                            </div>
                        </template>
                        
                        <Column field="name" header="Variante / Modelo">
                            <template #body="{ data }">
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ data.name }}</span>
                            </template>
                        </Column>
                        <Column field="duration_estimate" header="Duración">
                            <template #body="{ data }">
                                <span class="text-gray-600 dark:text-gray-400">{{ data.duration_estimate || 'N/A' }}</span>
                            </template>
                        </Column>
                        <Column field="price" header="Precio" class="text-right" headerClass="text-right">
                            <template #body="{ data }">
                                <span class="font-semibold text-green-600 dark:text-green-400">
                                    {{ formatCurrency(data.price) }}
                                </span>
                            </template>
                        </Column>
                    </DataTable>
                </div>

                <!-- Tarjeta: Historial de actividad -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                        <i class="pi pi-history text-gray-400"></i> Historial de movimientos
                    </h3>
                    <ActivityHistory :activities="activities" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>