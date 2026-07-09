<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
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

// Drawer state for readers
const isReadersDrawerVisible = ref(false);
const selectedNote = ref(null);

const openReadersDrawer = (data) => {
    selectedNote.value = data;
    isReadersDrawerVisible.value = true;
};

// --- Lógica para Data Table ---
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

// --- Acciones ---
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

// --- Helpers Visuales ---
const formatDate = (dateString) => {
    if (!dateString) return '--';
    return new Date(dateString).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getStatusSeverity = (isPublished) => {
    return isPublished ? 'success' : 'secondary';
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
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <Head title="Novedades (Changelog)" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Novedades (Changelog)</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-500 shadow-[0_0_8px_rgba(168,85,247,0.8)] animate-pulse"></span>
                        Gestión de actualizaciones y comunicados
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por título o contenido..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button label="Nueva novedad" icon="pi pi-plus" 
                                @click="router.get(route('admin.release-notes.create'))" 
                                severity="primary"
                                class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none shadow-sm" />
                    </div>
                </div>

                <!-- Tabla de Novedades -->
                <DataTable :value="notes?.data || []" lazy paginator
                    :totalRecords="notes?.total || 0" :rows="notes?.per_page || 20" :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} novedades"
                    class="cursor-pointer" rowHover :pt="dataTablePt"
                    @row-click="(event) => openReadersDrawer(event.data)">

                    <Column field="version" header="Versión" style="width: 8rem" sortable>
                        <template #body="{ data }">
                            <span v-if="data.version" class="font-mono text-xs font-bold text-gray-900 dark:text-gray-100 bg-gray-100 dark:bg-[#3a3a3a] px-2 py-1 rounded-md border border-gray-200 dark:border-[#4a4a4a]">
                                {{ data.version }}
                            </span>
                            <span v-else class="text-gray-400 text-xs italic">--</span>
                        </template>
                    </Column>

                    <Column field="title" header="Título y extracto" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <span class="font-medium text-gray-900 dark:text-white m-0 tracking-tight">{{ data.title }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400 line-clamp-1 m-0">{{ data.excerpt }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="is_published" header="Estado" style="width: 11rem" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-1.5">
                                <Tag :value="data.is_published ? 'Publicado' : 'Borrador'" 
                                     :severity="getStatusSeverity(data.is_published)" :pt="tagPt" />
                                <Tag v-if="data.is_banner" value="Banner" severity="info" :pt="tagPt" />
                            </div>
                        </template>
                    </Column>

                    <Column field="published_at" header="Fecha de Pub." style="width: 12rem" sortable>
                        <template #body="{ data }">
                            <span class="text-xs text-gray-600 dark:text-gray-400">
                                <i v-if="data.is_published" class="pi pi-calendar !text-[10px] mr-1"></i>
                                {{ data.is_published ? formatDate(data.published_at) : 'Pendiente' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Acciones por Fila -->
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10 opacity-60">
                            <i class="pi pi-sparkles !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin novedades</p>
                            <p class="text-xs text-gray-400 mt-1">No hay actualizaciones registradas en el sistema.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />

                <!-- Drawer: Lectores de la novedad -->
                <Drawer v-model:visible="isReadersDrawerVisible" position="right" class="w-full sm:!w-[32rem]" :pt="drawerPt">
                    <template #header>
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                                <i class="pi pi-users text-purple-500 !text-sm"></i>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Lectores de la novedad</h2>
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate max-w-[250px]">{{ selectedNote?.title }}</p>
                            </div>
                        </div>
                    </template>

                    <div v-if="selectedNote" class="space-y-4">
                        <!-- Resumen -->
                        <div class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <div class="flex flex-col gap-0.5 flex-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Total de lectores</span>
                                <span class="text-2xl font-light tracking-tight text-gray-900 dark:text-white">{{ selectedNote.readers_list?.length || 0 }}</span>
                            </div>
                            <div class="flex flex-col gap-0.5 flex-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Versión</span>
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ selectedNote.version || '--' }}</span>
                            </div>
                        </div>

                        <!-- Lista de lectores -->
                        <div v-if="selectedNote.readers_list && selectedNote.readers_list.length > 0" class="space-y-2">
                            <div
                                v-for="reader in selectedNote.readers_list"
                                :key="reader.id"
                                class="p-4 bg-white dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] hover:bg-gray-50 dark:hover:bg-[#232323] transition-colors"
                            >
                                <div class="flex items-start gap-3">
                                    <!-- Avatar -->
                                    <div class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0 border border-purple-200 dark:border-purple-800/50">
                                        <span class="text-xs font-bold text-purple-600 dark:text-purple-400">{{ reader.name?.charAt(0)?.toUpperCase() }}</span>
                                    </div>
                                    <!-- Info -->
                                    <div class="flex flex-col gap-0.5 flex-1 min-w-0">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ reader.name }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 m-0">{{ reader.email }}</span>
                                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                            <span v-if="reader.branch" class="inline-flex items-center gap-1 text-[10px] uppercase tracking-widest font-bold text-gray-500 bg-gray-100 dark:bg-[#232323] px-2 py-0.5 rounded-full">
                                                <i class="pi pi-building !text-[8px]"></i>
                                                {{ reader.branch }}
                                            </span>
                                            <span v-if="reader.subscription" class="inline-flex items-center gap-1 text-[10px] uppercase tracking-widest font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 px-2 py-0.5 rounded-full">
                                                <i class="pi pi-briefcase !text-[8px]"></i>
                                                {{ reader.subscription }}
                                            </span>
                                        </div>
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500 mt-1 m-0">
                                            <i class="pi pi-clock !text-[8px] mr-1"></i>
                                            {{ formatDate(reader.read_at) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div v-else class="flex flex-col items-center justify-center text-center py-12 opacity-60">
                            <i class="pi pi-users !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin lectores</p>
                            <p class="text-xs text-gray-400 mt-1">Ningún usuario ha leído esta novedad aún.</p>
                        </div>
                    </div>
                </Drawer>
            </div>
        </div>
    </AppLayout>
</template>