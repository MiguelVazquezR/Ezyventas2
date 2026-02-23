<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportServicesModal from './Partials/ImportServicesModal.vue';
import { usePermissions } from '@/Composables';
import Drawer from 'primevue/drawer'; // Importación del Drawer
import Tag from 'primevue/tag';
import Divider from 'primevue/divider';

const props = defineProps({
    services: Object,
    filters: Object,
    serviceLimitReached: Boolean, // Recibimos el candado desde el backend
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const selectedServices = ref([]);
const searchTerm = ref(props.filters.search || '');
const showImportModal = ref(false);

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    { label: 'Exportar Servicios', icon: 'pi pi-download', command: () => window.location.href = route('import-export.services.export') },
]);

const menu = ref();
const selectedServiceForMenu = ref(null);

// --- DRAWER DE VISTA RÁPIDA ---
const isDrawerVisible = ref(false);
const drawerService = ref(null);

const deleteSingleService = () => {
    if (!selectedServiceForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar "${selectedServiceForMenu.value.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('services.destroy', selectedServiceForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    selectedServices.value = selectedServices.value.filter(s => s.id !== selectedServiceForMenu.value.id);
                }
            });
        }
    });
};

const deleteSelectedServices = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar los ${selectedServices.value.length} servicios seleccionados?`,
        header: 'Eliminación Masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToDelete = selectedServices.value.map(s => s.id);
            router.post(route('services.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => selectedServices.value = []
            });
        }
    });
};

const menuItems = ref([
    { label: 'Ver detalles', icon: 'pi pi-eye', command: () => router.get(route('services.show', selectedServiceForMenu.value.id)), visible: hasPermission('services.catalog.see_details') },
    { label: 'Editar servicio', icon: 'pi pi-pencil', command: () => router.get(route('services.edit', selectedServiceForMenu.value.id)), visible: hasPermission('services.catalog.edit') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleService, visible: hasPermission('services.catalog.delete') },
]);

const toggleMenu = (event, data) => {
    selectedServiceForMenu.value = data;
    menu.value.toggle(event);
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.services.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('services.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

const onRowClick = (event) => {
    // Evitamos navegar si se hizo clic en el botón del menú (acciones)
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
        return;
    }
    
    // Asignar el servicio y abrir el Drawer en lugar de navegar directo
    drawerService.value = event.data;
    isDrawerVisible.value = true;
};
</script>

<template>
    <AppLayout title="Catálogo de servicios">
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Catálogo de Servicios</h1>
                    <div class="flex items-center gap-2">
                        <IconField iconPosition="left" class="w-full md:w-auto">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar servicio..." class="w-full" />
                        </IconField>
                        <ButtonGroup>
                            <!-- El Tooltip le explicará al usuario por qué el botón se deshabilitó -->
                            <span v-tooltip.bottom="serviceLimitReached ? 'Límite de servicios alcanzado en tu plan actual. Mejora tu suscripción para agregar más.' : ''">
                                <Button 
                                    v-if="hasPermission('services.catalog.create')" 
                                    label="Nuevo servicio" 
                                    icon="pi pi-plus"
                                    @click="router.get(route('services.create'))" 
                                    severity="warning" 
                                    :disabled="serviceLimitReached"
                                />
                            </span>
                            <Button v-if="hasPermission('services.catalog.import_export')" icon="pi pi-chevron-down"
                                @click="toggleHeaderMenu" severity="warning" />
                        </ButtonGroup>
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                    </div>
                </div>

                <!-- Barra de Alerta si el límite fue alcanzado -->
                <Message v-if="serviceLimitReached" severity="warn" :closable="false" class="mb-4">
                    <div class="flex items-center justify-between w-full">
                        <span>Has alcanzado el límite de servicios de tu plan.</span>
                        <Link :href="route('subscription.manage')">
                            <Button label="Mejorar plan" size="small" outlined severity="warning" class="ml-4" />
                        </Link>
                    </div>
                </Message>

                <!-- Barra de Acciones Masivas -->
                <div v-if="selectedServices.length > 0"
                    class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-2 mb-4 flex justify-between items-center">
                    <span class="font-semibold text-sm text-blue-800 dark:text-blue-200">{{ selectedServices.length }}
                        servicio(s) seleccionado(s)</span>
                    <Button v-if="hasPermission('services.catalog.delete')" @click="deleteSelectedServices" label="Eliminar" icon="pi pi-trash" size="small"
                        severity="danger" outlined />
                </div>

                <!-- Tabla de Servicios -->
                <DataTable :value="services.data" v-model:selection="selectedServices" lazy paginator
                    :totalRecords="services.total" :rows="services.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} servicios"
                    rowHover
                    class="cursor-pointer"
                    @row-click="onRowClick">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="name" header="Nombre del Servicio" sortable></Column>
                    <Column field="category.name" header="Categoría" sortable>
                        <template #body="{ data }">
                            <Tag v-if="data.category" :value="data.category.name" severity="info" rounded />
                            <span v-else class="text-gray-400 italic">Sin categoría</span>
                        </template>
                    </Column>
                    <Column field="base_price" header="Precio Base" sortable>
                        <template #body="{ data }"> 
                            <span v-if="parseFloat(data.base_price) === 0" class="text-gray-500 italic text-sm">Variable</span>
                            <span v-else class="font-medium">{{ formatCurrency(data.base_price) }}</span>
                        </template>
                    </Column>
                    <Column field="duration_estimate" header="Duración Estimada" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-500 italic text-sm">{{ data.duration_estimate || 'Variable' }}</span>
                        </template>
                    </Column>
                    <Column header="Sucursales" style="min-width: 12rem">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag 
                                    v-for="branch in data.branches" 
                                    :key="branch.id" 
                                    :value="branch.name" 
                                    severity="secondary" 
                                    rounded 
                                />
                                <span v-if="!data.branches || data.branches.length === 0" class="text-gray-400 italic text-sm">Ninguna</span>
                            </div>
                        </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> </template>
                    </Column>
                    <template #empty>
                        <div class="text-center py-4">No hay servicios registrados.</div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- DRAWER DE VISTA RÁPIDA DE SERVICIO -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="!w-full md:!w-[400px]">
            <template #header>
                <div class="flex items-center gap-2">
                    <i class="pi pi-wrench text-xl text-primary-500"></i>
                    <span class="font-bold text-lg truncate max-w-[250px]" :title="drawerService?.name">
                        {{ drawerService?.name }}
                    </span>
                </div>
            </template>
            
            <div v-if="drawerService" class="flex flex-col h-full">
                <!-- Contenido Principal -->
                <div class="flex-1 overflow-y-auto custom-scrollbar flex flex-col gap-5 pb-6">
                    
                    <!-- Info Rápida -->
                    <div class="grid grid-cols-2 gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-lg border border-gray-100 dark:border-gray-700">
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Categoría</span>
                            <div><Tag :value="drawerService.category?.name || 'General'" severity="info" rounded /></div>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Duración</span>
                            <span class="font-medium text-sm flex items-center gap-1">
                                <i class="pi pi-clock text-gray-400"></i>
                                {{ drawerService.duration_estimate || 'No especificada' }}
                            </span>
                        </div>
                        <!-- Mostrar Sucursales -->
                        <div class="col-span-2 flex flex-col gap-1 border-t dark:border-gray-700 pt-3 mt-1">
                            <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Disponible en:</span>
                            <div class="flex flex-wrap gap-1 mt-1">
                                <Tag 
                                    v-for="branch in drawerService.branches" 
                                    :key="branch.id" 
                                    :value="branch.name" 
                                    severity="secondary" 
                                    rounded 
                                />
                                <span v-if="!drawerService.branches || drawerService.branches.length === 0" class="text-gray-400 italic text-sm">No disponible</span>
                            </div>
                        </div>
                    </div>

                    <Divider class="!my-0" />

                    <!-- Precio / Variantes -->
                    <div class="flex flex-col gap-2">
                        <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Precio y Variantes</span>
                        
                        <div v-if="parseFloat(drawerService.base_price) > 0" class="flex items-center justify-between bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 p-3 rounded border border-green-200 dark:border-green-800">
                            <span class="font-semibold">Precio General:</span>
                            <span class="font-bold text-lg">{{ formatCurrency(drawerService.base_price) }}</span>
                        </div>

                        <div v-else-if="parseFloat(drawerService.base_price) === 0" class="bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 p-3 rounded border border-blue-200 dark:border-blue-800">
                            <div class="flex items-center gap-2 mb-1">
                                <i class="pi pi-sitemap"></i>
                                <span class="font-semibold">Servicio con Variantes ({{ drawerService.variants ? drawerService.variants.length : 0 }} variantes)</span>
                            </div>
                            <p class="text-xs opacity-80">Este servicio tiene precios y/o duraciones variables dependiendo del equipo o especificación. Ingresa a los detalles para ver el desglose completo.</p>
                            
                            <ul v-if="drawerService.variants && drawerService.variants.length > 0" class="mt-3 flex flex-col gap-2 border-t border-blue-200 dark:border-blue-800 pt-3">
                                <li v-for="variant in drawerService.variants" :key="variant.id" class="flex justify-between items-center text-sm">
                                    <span class="font-medium truncate pr-2" :title="variant.name">- {{ variant.name }}</span>
                                    <span class="font-bold">{{ formatCurrency(variant.price) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <Divider class="!my-0" />

                    <!-- Descripción -->
                    <div class="flex flex-col gap-2 overflow-hidden">
                        <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Descripción del Servicio</span>
                        <div v-if="drawerService.description" 
                             class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-800 p-3 rounded border dark:border-gray-700 break-words overflow-x-auto" 
                             v-html="drawerService.description">
                        </div>
                        <div v-else class="text-sm text-gray-400 italic bg-gray-50 dark:bg-gray-800 p-3 rounded border dark:border-gray-700 text-center">
                            No se ha proporcionado una descripción detallada.
                        </div>
                    </div>
                </div>

                <!-- Footer (Botones de Acción) -->
                <div class="mt-auto pt-4 border-t dark:border-gray-700 bg-white dark:bg-gray-900 flex gap-2 shrink-0">
                    <Button 
                        v-if="hasPermission('services.catalog.see_details')"
                        label="Ver información completa" 
                        icon="pi pi-external-link" 
                        class="w-full" 
                        severity="primary"
                        @click="router.visit(route('services.show', drawerService.id))" 
                    />
                </div>
            </div>
        </Drawer>

        <!-- Modal de Importación -->
        <ImportServicesModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>