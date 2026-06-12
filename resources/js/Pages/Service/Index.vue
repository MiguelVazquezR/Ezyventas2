<script setup>
import { ref, watch } from 'vue';
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportServicesModal from './Partials/ImportServicesModal.vue';
import { usePermissions } from '@/Composables';

// Importamos el nuevo componente
import ServiceDrawerDetails from './Partials/ServiceDrawerDetails.vue';

const props = defineProps({
    services: Object,
    filters: Object,
    serviceLimitReached: Boolean,
});

const page = usePage();

const confirm = useConfirm();
const { hasPermission } = usePermissions();

// --- Estado y Lógica ---
const selectedServices = ref([]);
const searchTerm = ref(props.filters.search || '');
const showImportModal = ref(false);

// Estado para el Drawer (Panel lateral)
const isDrawerVisible = ref(false);
const drawerService = ref(null);

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    { label: 'Exportar Servicios', icon: 'pi pi-download', command: () => window.location.href = route('import-export.services.export') },
]);

const menu = ref();
const selectedServiceForMenu = ref(null);

const deleteSingleService = () => {
    if (!selectedServiceForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar "${selectedServiceForMenu.value.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('services.destroy', selectedServiceForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    selectedServices.value = selectedServices.value.filter(s => s.id !== selectedServiceForMenu.value.id);
                    if (drawerService.value?.id === selectedServiceForMenu.value.id) {
                        isDrawerVisible.value = false;
                    }
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
                onSuccess: () => {
                    selectedServices.value = [];
                    isDrawerVisible.value = false;
                },
                preserveScroll: true,
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
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
        return;
    }

    const clickAction = page.props.auth.preferences?.service_table_row_click_action || 'Vista lateral con algunos detalles';

    if (clickAction === 'Redirección a vista de detalles') {
        router.get(route('services.show', event.data.id));
    } else {
        drawerService.value = event.data;
        isDrawerVisible.value = true;
    }
};

const goToDetails = (id) => {
    router.visit(route('services.show', id));
};

const goToEdit = (id) => {
    router.visit(route('services.edit', id));
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl' },
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
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' }
};

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-0 custom-scrollbar' }, 
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <Head title="Catálogo de servicios" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Banner de Alerta de Límite (Estilo Tesla UI) -->
            <div v-if="serviceLimitReached" class="bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div class="flex items-center gap-3">
                    <i class="pi pi-exclamation-circle text-orange-500 !text-xl"></i>
                    <div>
                        <p class="font-bold text-sm text-orange-800 dark:text-orange-400 m-0">Límite de servicios alcanzado</p>
                        <p class="text-xs text-orange-700 dark:text-orange-300/80 m-0 mt-0.5">Has alcanzado el límite de servicios o variantes de tu plan actual.</p>
                    </div>
                </div>
                <Link :href="route('subscription.manage')">
                    <Button label="Mejorar plan" size="small" severity="warning" class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                </Link>
            </div>

            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Catálogo de servicios</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de ofertas y mano de obra
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar servicio..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <span v-tooltip.bottom="serviceLimitReached ? 'Límite alcanzado' : ''" class="flex-grow md:flex-none">
                            <Button v-if="hasPermission('services.catalog.create')" label="Nuevo servicio"
                                icon="pi pi-plus" @click="router.get(route('services.create'))"
                                severity="warning"
                                :disabled="serviceLimitReached"
                                class="!rounded-xl !text-xs !uppercase !tracking-wider w-full md:w-auto" />
                        </span>
                        
                        <Button v-if="hasPermission('services.catalog.import_export')" icon="pi pi-chevron-down"
                            @click="toggleHeaderMenu" severity="warning" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedServices.length > 0" class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-2xl p-3 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 transition-all duration-300">
                    <span class="font-bold text-xs uppercase tracking-widest text-blue-700 dark:text-blue-300 m-0">
                        <i class="pi pi-check-square mr-1"></i> {{ selectedServices.length }} seleccionados
                    </span>
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto custom-scrollbar pb-1 md:pb-0">
                        <Button v-if="hasPermission('services.catalog.delete')" @click="deleteSelectedServices" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />
                    </div>
                </div>

                <!-- Tabla de Servicios -->
                <DataTable :value="services.data" v-model:selection="selectedServices" lazy paginator
                    :totalRecords="services.total" :rows="services.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} servicios"
                    class="cursor-pointer" rowHover @row-click="onRowClick" :pt="dataTablePt">

                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    
                    <Column field="name" header="Nombre del Servicio" sortable>
                        <template #body="{ data }">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                        </template>
                    </Column>
                    
                    <Column field="category.name" header="Categoría" sortable>
                        <template #body="{ data }">
                            <Tag v-if="data.category" :value="data.category.name" severity="info" :pt="tagPt" />
                            <span v-else class="text-xs text-gray-400 italic">Sin categoría</span>
                        </template>
                    </Column>

                    <Column field="base_price" header="Precio Base" sortable>
                        <template #body="{ data }">
                            <span v-if="parseFloat(data.base_price) === 0" class="text-xs font-medium text-gray-500 italic">Variable</span>
                            <span v-else class="font-light tracking-tight text-lg dark:text-white">{{ formatCurrency(data.base_price) }}</span>
                        </template>
                    </Column>
                    
                    <Column field="duration_estimate" header="Duración Estimada" sortable>
                        <template #body="{ data }">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ data.duration_estimate || 'Variable' }}</span>
                        </template>
                    </Column>

                    <Column header="Variantes" style="min-width: 6rem; text-align: center;">
                        <template #body="{ data }">
                            <Tag 
                                :value="data.variants ? data.variants.length : 0" 
                                :severity="data.variants && data.variants.length > 0 ? 'info' : 'secondary'" 
                                :pt="tagPt"
                                v-tooltip.top="data.variants && data.variants.length > 0 ? 'Ver variantes en el detalle' : 'Servicio único sin variantes'"
                            />
                        </template>
                    </Column>

                    <Column header="Sucursales" style="min-width: 12rem">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1.5">
                                <Tag 
                                    v-for="branch in data.branches?.slice(0, 2)" 
                                    :key="branch.id" 
                                    :value="branch.name" 
                                    severity="secondary" 
                                    :pt="tagPt" 
                                />
                                <Tag v-if="data.branches?.length > 2" :value="`+${data.branches.length - 2}`"
                                    severity="secondary" :pt="tagPt" class="cursor-help"
                                    v-tooltip.top="data.branches.slice(2).map(b => b.name).join(', ')" />
                                <span v-if="!data.branches || data.branches.length === 0" class="text-xs text-gray-400 italic">Ninguna</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" aria-controls="overlay_menu" /> 
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-wrench !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay servicios registrados o que coincidan con la búsqueda.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>

        <!-- Drawer de Detalles del Servicio Aislado -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="w-full md:!w-[30rem]" :pt="drawerPt">
            <template #header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-wrench text-blue-500 !text-sm"></i>
                    </div>
                    <span class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Detalles rápidos</span>
                </div>
            </template>
            
            <ServiceDrawerDetails 
                v-if="drawerService"
                :service="drawerService"
                :can-see-details="hasPermission('services.catalog.see_details')"
                :can-edit="hasPermission('services.catalog.edit')"
                @go-to-details="goToDetails(drawerService.id)"
                @go-to-edit="goToEdit(drawerService.id)"
            />
        </Drawer>
        
        <!-- Modal de Importación -->
        <ImportServicesModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>