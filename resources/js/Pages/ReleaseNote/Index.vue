<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    notes: Object, // Las novedades paginadas que vendrán del backend
    filters: Object,
});

const confirm = useConfirm();
const searchTerm = ref(props.filters?.search || '');

const menu = ref();
const selectedNoteForMenu = ref(null);

// Lógica para Data Table
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || (props.notes?.per_page || 20),
        sortField: options.sortField || props.filters?.sortField || 'created_at',
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('admin.release-notes.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const toggleMenu = (event, data) => {
    selectedNoteForMenu.value = data;
    menu.value.toggle(event);
};

// Acciones
const deleteSingleNote = () => {
    if (!selectedNoteForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la novedad "${selectedNoteForMenu.value.title}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('admin.release-notes.destroy', selectedNoteForMenu.value.id), {
                preserveScroll: true,
            });
        }
    });
};

const togglePublishStatus = () => {
    if (!selectedNoteForMenu.value) return;
    // Se enviaría un POST/PUT para alternar is_published
    router.post(route('admin.release-notes.toggle-publish', selectedNoteForMenu.value.id), {}, {
        preserveScroll: true,
    });
};

const menuItems = ref([
    { label: 'Editar', icon: 'pi pi-pencil', command: () => { if (selectedNoteForMenu.value) router.get(route('admin.release-notes.edit', selectedNoteForMenu.value.id)); } },
    { label: 'Publicar / Ocultar', icon: 'pi pi-eye', command: togglePublishStatus },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleNote },
]);

// Helpers Visuales
const formatDate = (dateString) => {
    if (!dateString) return '--';
    return new Date(dateString).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getStatusSeverity = (isPublished) => {
    return isPublished ? 'success' : 'secondary';
};
</script>

<template>
    <AppLayout title="Administrar Novedades">
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                
                <!-- Header y Acciones -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 m-0">Novedades (Changelog)</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Administra las actualizaciones visibles para todos los usuarios.</p>
                    </div>

                    <div class="flex items-center gap-4 w-full md:w-auto">
                        <IconField iconPosition="left" class="w-full md:w-64">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por título..." class="w-full" />
                        </IconField>
                        
                        <Button label="Nueva novedad" icon="pi pi-plus" 
                                @click="router.get(route('admin.release-notes.create'))" 
                                severity="warning" />
                    </div>
                </div>

                <!-- Tabla de Novedades -->
                <DataTable :value="notes?.data || []" lazy paginator
                    :totalRecords="notes?.total || 0" :rows="notes?.per_page || 20" :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} novedades"
                    class="p-datatable-sm cursor-pointer" rowHover>

                    <Column field="version" header="Versión" style="width: 8rem" sortable>
                        <template #body="{ data }">
                            <Tag v-if="data.version" :value="data.version" severity="info" />
                            <span v-else class="text-gray-400 text-sm">--</span>
                        </template>
                    </Column>

                    <Column field="title" header="Título" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-bold text-gray-800 dark:text-gray-200">{{ data.title }}</span>
                                <span class="text-sm text-gray-500 line-clamp-1 mt-1">{{ data.excerpt }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="is_published" header="Estado" style="width: 10rem" sortable alignFrozen="right">
                        <template #body="{ data }">
                            <Tag :value="data.is_published ? 'Publicado' : 'Borrador'" 
                                 :severity="getStatusSeverity(data.is_published)" />
                        </template>
                    </Column>

                    <Column field="published_at" header="Fecha de Publicación" style="width: 12rem" sortable>
                        <template #body="{ data }">
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ data.is_published ? formatDate(data.published_at) : 'Pendiente' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Acciones por Fila -->
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                severity="secondary" aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>

                    <template #empty>
                        <div class="text-center text-gray-500 py-8">
                            <i class="pi pi-inbox !text-4xl mb-4 text-gray-400"></i>
                            <p>No hay novedades registradas.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>