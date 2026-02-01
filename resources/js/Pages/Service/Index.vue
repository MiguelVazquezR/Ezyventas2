<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportServicesModal from './Partials/ImportServicesModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    services: Object,
    filters: Object,
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
    //{ label: 'Importar Servicios', icon: 'pi pi-upload', command: () => showImportModal.value = true },
    { label: 'Exportar Servicios', icon: 'pi pi-download', command: () => window.location.href = route('import-export.services.export') },
]);

const menu = ref();
const selectedServiceForMenu = ref(null);

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
    { label: 'Ver', icon: 'pi pi-eye', command: () => router.get(route('services.show', selectedServiceForMenu.value.id)), visible: hasPermission('services.catalog.see_details') },
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
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const onRowClick = (event) => {
    // Evitamos navegar si se hizo clic en el botón del menú (acciones)
    // El evento row-click de PrimeVue devuelve { originalEvent, data, index }
    // Verificamos si el target fue un botón o icono
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button')) {
        return;
    }
    
    if (hasPermission('services.catalog.see_details')) {
        router.visit(route('services.show', event.data.id));
    }
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
                            <Button v-if="hasPermission('services.catalog.create')" label="Nuevo servicio" icon="pi pi-plus"
                                @click="router.get(route('services.create'))" severity="warning" />
                            <Button v-if="hasPermission('services.catalog.import_export')" icon="pi pi-chevron-down"
                                @click="toggleHeaderMenu" severity="warning" />
                        </ButtonGroup>
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                    </div>
                </div>

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
                    @row-click="onRowClick">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="name" header="Nombre del Servicio" sortable></Column>
                    <Column field="category.name" header="Categoría" sortable></Column>
                    <Column field="base_price" header="Precio Base" sortable>
                        <template #body="{ data }"> {{ formatCurrency(data.base_price) }} </template>
                    </Column>
                    <Column field="duration_estimate" header="Duración Estimada" sortable></Column>
                    <!-- <Column field="show_online" header="Visible en Tienda" sortable>
                        <template #body="{ data }">
                            <i class="pi"
                                :class="{ 'pi-check-circle text-green-500': data.show_online, 'pi-times-circle text-red-500': !data.show_online }"></i>
                        </template>
                    </Column> -->
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> </template>
                    </Column>
                    <template #empty>
                        <div class="text-center py-4">No hay servicios registrados.</div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- Modal de Importación -->
        <ImportServicesModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>