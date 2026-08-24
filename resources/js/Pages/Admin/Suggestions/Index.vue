<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    suggestions: Object,
    filters: Object,
});

const confirm = useConfirm();
const searchTerm = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || null);
const categoryFilter = ref(props.filters?.category || null);

const menu = ref();
const selectedSuggestion = ref(null);
const isDetailDrawerVisible = ref(false);
const notesModel = ref('');

const pendingCount = ref(0);

// Status options
const statusOptions = [
    { label: 'Pendiente', value: 'pending' },
    { label: 'Revisado', value: 'reviewed' },
    { label: 'Planeado', value: 'planned' },
    { label: 'Implementado', value: 'implemented' },
    { label: 'Rechazado', value: 'declined' },
];

const categoryOptions = [
    { label: 'Funcionalidad', value: 'feature' },
    { label: 'Error', value: 'bug' },
    { label: 'Mejora', value: 'improvement' },
    { label: 'Capacidad faltante', value: 'capability_request' },
    { label: 'Otro', value: 'other' },
];

const priorityOptions = [
    { label: 'Baja', value: 'low' },
    { label: 'Media', value: 'medium' },
    { label: 'Alta', value: 'high' },
];

// Lazy data fetching
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || (props.suggestions?.per_page || 20),
        sortField: options.sortField || props.filters?.sortField || 'created_at',
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
        status: statusFilter.value,
        category: categoryFilter.value,
    };
    router.get(route('admin.suggestions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());
watch(statusFilter, () => fetchData());
watch(categoryFilter, () => fetchData());

// Drawer
const openDetailDrawer = (data) => {
    selectedSuggestion.value = data;
    notesModel.value = data.admin_notes || '';
    isDetailDrawerVisible.value = true;
};

// Menu
const toggleMenu = (event, data) => {
    selectedSuggestion.value = data;
    menu.value.toggle(event);
};

// Update status
const updateStatus = (suggestion, newStatus) => {
    router.put(route('admin.suggestions.update-status', suggestion.id), { status: newStatus }, {
        preserveScroll: true,
    });
};

// Update priority
const updatePriority = (suggestion, newPriority) => {
    router.put(route('admin.suggestions.update-priority', suggestion.id), { priority: newPriority }, {
        preserveScroll: true,
    });
};

// Save notes
const saveNotes = () => {
    if (!selectedSuggestion.value) return;
    router.put(route('admin.suggestions.update-notes', selectedSuggestion.value.id), { admin_notes: notesModel.value }, {
        preserveScroll: true,
        onSuccess: () => {
            if (selectedSuggestion.value) {
                selectedSuggestion.value.admin_notes = notesModel.value;
            }
        },
    });
};

// Helpers
const formatDate = (dateString) => {
    if (!dateString) return '--';
    return new Date(dateString).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getCategoryIcon = (category) => {
    switch (category) {
        case 'feature': return 'pi pi-star';
        case 'bug': return 'pi pi-exclamation-triangle';
        case 'improvement': return 'pi pi-pencil';
        case 'capability_request': return 'pi pi-cog';
        default: return 'pi pi-ellipsis-h';
    }
};

const getCategoryLabel = (category) => {
    switch (category) {
        case 'feature': return 'Funcionalidad';
        case 'bug': return 'Error';
        case 'improvement': return 'Mejora';
        case 'capability_request': return 'Capacidad faltante';
        default: return 'Otro';
    }
};

const getCategorySeverity = (category) => {
    switch (category) {
        case 'feature': return 'info';
        case 'bug': return 'danger';
        case 'improvement': return 'warn';
        case 'capability_request': return 'secondary';
        default: return 'secondary';
    }
};

const getStatusSeverity = (status) => {
    switch (status) {
        case 'pending': return 'warn';
        case 'reviewed': return 'info';
        case 'planned': return 'info';
        case 'implemented': return 'success';
        case 'declined': return 'danger';
        default: return 'secondary';
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'pending': return 'Pendiente';
        case 'reviewed': return 'Revisado';
        case 'planned': return 'Planeado';
        case 'implemented': return 'Implementado';
        case 'declined': return 'Rechazado';
        default: return status;
    }
};

const getPrioritySeverity = (priority) => {
    switch (priority) {
        case 'low': return 'secondary';
        case 'medium': return 'warn';
        case 'high': return 'danger';
        default: return 'secondary';
    }
};

const getPriorityLabel = (priority) => {
    switch (priority) {
        case 'low': return 'Baja';
        case 'medium': return 'Media';
        case 'high': return 'Alta';
        default: return priority;
    }
};

// Menu items per row
const menuItems = ref([
    {
        label: 'Cambiar estado',
        icon: 'pi pi-tag',
        items: [
            { label: 'Pendiente', icon: 'pi pi-circle-fill', className: 'text-amber-500', command: () => updateStatus(selectedSuggestion.value, 'pending') },
            { label: 'Revisado', icon: 'pi pi-circle-fill', className: 'text-blue-500', command: () => updateStatus(selectedSuggestion.value, 'reviewed') },
            { label: 'Planeado', icon: 'pi pi-circle-fill', className: 'text-blue-400', command: () => updateStatus(selectedSuggestion.value, 'planned') },
            { label: 'Implementado', icon: 'pi pi-circle-fill', className: 'text-green-500', command: () => updateStatus(selectedSuggestion.value, 'implemented') },
            { label: 'Rechazado', icon: 'pi pi-circle-fill', className: 'text-red-500', command: () => updateStatus(selectedSuggestion.value, 'declined') },
        ],
    },
    {
        label: 'Cambiar prioridad',
        icon: 'pi pi-flag',
        items: [
            { label: 'Baja', icon: 'pi pi-flag-fill', className: 'text-gray-400', command: () => updatePriority(selectedSuggestion.value, 'low') },
            { label: 'Media', icon: 'pi pi-flag-fill', className: 'text-amber-400', command: () => updatePriority(selectedSuggestion.value, 'medium') },
            { label: 'Alta', icon: 'pi pi-flag-fill', className: 'text-red-400', command: () => updatePriority(selectedSuggestion.value, 'high') },
        ],
    },
]);

// PT styles
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } },
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' },
};

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};

const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' },
    submenuHeader: { class: 'dark:!bg-[#1a1a1a] !rounded-xl' },
};

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' },
};
</script>

<template>
    <Head title="Sugerencias y feedback" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">

                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Sugerencias y feedback</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(249,115,22,0.8)] animate-pulse"></span>
                        Sugerencias, bugs y comentarios de tus usuarios
                    </p>
                </div>

                <!-- Filters toolbar -->
                <div class="flex flex-col md:flex-row gap-3 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <div class="flex items-center gap-3 w-full md:w-auto">
                        <IconField iconPosition="left" class="w-full md:w-72">
                            <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por título o descripción..." :pt="inputPt" class="!pl-10" />
                        </IconField>
                        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
                            placeholder="Estado" showClear class="w-36" :pt="selectPt" />
                        <Select v-model="categoryFilter" :options="categoryOptions" optionLabel="label" optionValue="value"
                            placeholder="Categoría" showClear class="w-40" :pt="selectPt" />
                    </div>
                </div>

                <!-- DataTable -->
                <DataTable :value="suggestions?.data || []" lazy paginator
                    :totalRecords="suggestions?.total || 0" :rows="suggestions?.per_page || 20" :rowsPerPageOptions="[20, 50, 100]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 70rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} sugerencias"
                    class="cursor-pointer" rowHover :pt="dataTablePt"
                    @row-click="(event) => openDetailDrawer(event.data)">

                    <Column field="category" header="Categoría" style="width: 8rem" sortable>
                        <template #body="{ data }">
                            <Tag :value="getCategoryLabel(data.category)" :severity="getCategorySeverity(data.category)" :pt="tagPt">
                                <template #icon>
                                    <i :class="getCategoryIcon(data.category) + ' !text-[10px] mr-1'"></i>
                                </template>
                            </Tag>
                        </template>
                    </Column>

                    <Column field="title" header="Título" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5">
                                <span class="font-medium text-gray-900 dark:text-white m-0 tracking-tight">{{ data.title }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400 line-clamp-1 m-0 max-w-xs">{{ data.description }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="user_name" header="Usuario" style="width: 12rem" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-200 m-0">{{ data.user_name || 'Anónimo' }}</span>
                                <span v-if="data.user_email" class="text-[10px] text-gray-400 m-0">{{ data.user_email }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="branch_name" header="Sucursal" style="width: 10rem" sortable>
                        <template #body="{ data }">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ data.branch_name || '--' }}</span>
                        </template>
                    </Column>

                    <Column field="status" header="Estado" style="width: 8rem" sortable>
                        <template #body="{ data }">
                            <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" :pt="tagPt" />
                        </template>
                    </Column>

                    <Column field="priority" header="Prioridad" style="width: 6rem" sortable>
                        <template #body="{ data }">
                            <Tag :value="getPriorityLabel(data.priority)" :severity="getPrioritySeverity(data.priority)" :pt="tagPt" class="!px-2" />
                        </template>
                    </Column>

                    <Column field="created_at" header="Fecha" style="width: 10rem" sortable>
                        <template #body="{ data }">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                <i class="pi pi-calendar !text-[10px] mr-1"></i>
                                {{ formatDate(data.created_at) }}
                            </span>
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column headerStyle="width: 4rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors"
                                aria-haspopup="true" aria-controls="overlay_menu" />
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10 opacity-60">
                            <i class="pi pi-inbox !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin sugerencias</p>
                            <p class="text-xs text-gray-400 mt-1">No hay sugerencias o comentarios registrados aún.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />

                <!-- Detail Drawer -->
                <Drawer v-model:visible="isDetailDrawerVisible" position="right" class="w-full sm:!w-[36rem]" :pt="drawerPt">
                    <template #header>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-8 h-8 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                                <i class="pi pi-lightbulb text-primary-500 !text-sm"></i>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Detalle de sugerencia</h2>
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate max-w-[250px]">{{ selectedSuggestion?.title }}</p>
                            </div>
                        </div>
                    </template>

                    <div v-if="selectedSuggestion" class="space-y-5">
                        <!-- Info summary cards -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Categoría</span>
                                <div class="mt-1">
                                    <Tag :value="getCategoryLabel(selectedSuggestion.category)" :severity="getCategorySeverity(selectedSuggestion.category)" :pt="tagPt">
                                        <template #icon>
                                            <i :class="getCategoryIcon(selectedSuggestion.category) + ' !text-[10px] mr-1'"></i>
                                        </template>
                                    </Tag>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Estado</span>
                                <div class="mt-1">
                                    <Tag :value="getStatusLabel(selectedSuggestion.status)" :severity="getStatusSeverity(selectedSuggestion.status)" :pt="tagPt" />
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Prioridad</span>
                                <div class="mt-1">
                                    <Tag :value="getPriorityLabel(selectedSuggestion.priority)" :severity="getPrioritySeverity(selectedSuggestion.priority)" :pt="tagPt" class="!px-2" />
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Fecha</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 mt-1">{{ formatDate(selectedSuggestion.created_at) }}</p>
                            </div>
                        </div>

                        <!-- Title & Description -->
                        <div class="p-4 bg-white dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Título</span>
                            <p class="text-base font-semibold text-gray-900 dark:text-white m-0 mt-1">{{ selectedSuggestion.title }}</p>
                        </div>

                        <div class="p-4 bg-white dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Descripción</span>
                            <p class="text-sm text-gray-700 dark:text-gray-300 m-0 mt-1 whitespace-pre-wrap leading-relaxed">{{ selectedSuggestion.description }}</p>
                        </div>

                        <!-- User / Branch info -->
                        <div class="p-4 bg-white dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Información del remitente</span>
                            <div class="mt-2 space-y-1.5">
                                <div class="flex items-center gap-2">
                                    <i class="pi pi-user !text-xs text-gray-400"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-200">{{ selectedSuggestion.user_name || 'Anónimo' }}</span>
                                    <span v-if="selectedSuggestion.user_email" class="text-xs text-gray-400">({{ selectedSuggestion.user_email }})</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i class="pi pi-building !text-xs text-gray-400"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-200">{{ selectedSuggestion.branch_name || '--' }}</span>
                                </div>
                                <div v-if="selectedSuggestion.subscription_name" class="flex items-center gap-2">
                                    <i class="pi pi-briefcase !text-xs text-gray-400"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-200">{{ selectedSuggestion.subscription_name }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Admin notes -->
                        <div class="p-4 bg-white dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Notas internas</span>
                            <Textarea v-model="notesModel" placeholder="Agrega notas internas sobre esta sugerencia..."
                                class="w-full mt-2"
                                :autoResize="true" rows="3"
                                :pt="{
                                    root: {
                                        class: '!rounded-2xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm',
                                    },
                                }" />
                            <Button label="Guardar notas" icon="pi pi-check" size="small"
                                class="!rounded-xl !text-xs !font-bold mt-2"
                                @click="saveNotes" />
                        </div>

                        <!-- Quick actions: status change -->
                        <div class="p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mb-3 block">Cambiar estado</span>
                            <div class="flex flex-wrap gap-2">
                                <Button v-for="opt in statusOptions" :key="opt.value"
                                    :label="opt.label"
                                    :severity="getStatusSeverity(opt.value) === 'warn' ? 'warn' : getStatusSeverity(opt.value) === 'success' ? 'success' : getStatusSeverity(opt.value) === 'danger' ? 'danger' : 'info'"
                                    :outlined="selectedSuggestion.status !== opt.value"
                                    size="small"
                                    class="!rounded-full !text-[10px] !font-bold"
                                    @click="updateStatus(selectedSuggestion, opt.value)" />
                            </div>
                        </div>
                    </div>
                </Drawer>
            </div>
        </div>
    </AppLayout>
</template>