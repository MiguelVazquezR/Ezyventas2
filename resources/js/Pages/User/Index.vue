<script setup>
import { ref, watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    users: Object,
    filters: Object,
    userLimit: Number,
    userUsage: Number,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

// --- Lógica para verificar si se alcanzó el límite ---
const limitReached = computed(() => {
    if (props.userLimit === -1) return false;
    return props.userUsage >= props.userLimit;
});

const searchTerm = ref(props.filters.search || '');
const menu = ref();
const selectedUserForMenu = ref(null);

const menuItems = computed(() => {
    if (!selectedUserForMenu.value) return [];
    
    const isProtected = !selectedUserForMenu.value.roles || selectedUserForMenu.value.roles.length === 0;
    const isActive = selectedUserForMenu.value.is_active;

    return [
        { label: 'Editar usuario', icon: 'pi pi-pencil', disabled: isProtected, command: () => router.get(route('users.edit', selectedUserForMenu.value.id)), visible: hasPermission('settings.users.edit') },
        { 
            label: isActive ? 'Desactivar usuario' : 'Activar usuario', 
            icon: isActive ? 'pi pi-ban' : 'pi pi-check-circle',
            disabled: isProtected,
            command: () => toggleUserStatus(selectedUserForMenu.value), visible: hasPermission('settings.users.change_status')
        },
        { separator: true },
        { label: 'Eliminar usuario', icon: 'pi pi-trash', class: 'text-red-500', disabled: isProtected, command: () => confirmDeleteUser(selectedUserForMenu.value), visible: hasPermission('settings.users.delete') },
    ];
});

const toggleMenu = (event, data) => {
    selectedUserForMenu.value = data;
    menu.value.toggle(event);
};

const confirmDeleteUser = (user) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar al usuario "${user.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('users.destroy', user.id), { preserveScroll: true });
        }
    });
};

const toggleUserStatus = (user) => {
    router.patch(route('users.toggleStatus', user.id), {}, { preserveScroll: true });
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.users.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('users.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
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
</script>

<template>
    <Head title="Usuarios" />
    <AppLayout title="Usuarios">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Banner de Alerta de Límite -->
            <div v-if="limitReached" class="bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div class="flex items-center gap-3">
                    <i class="pi pi-exclamation-circle text-orange-500 !text-xl"></i>
                    <div>
                        <p class="font-bold text-sm text-orange-800 dark:text-orange-400 m-0">Límite de usuarios alcanzado</p>
                        <p class="text-xs text-orange-700 dark:text-orange-300/80 m-0 mt-0.5">Has alcanzado el límite de cuentas de usuario de tu plan actual.</p>
                    </div>
                </div>
                <Link :href="route('subscription.manage')">
                    <Button label="Mejorar plan" size="small" severity="warning" class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                </Link>
            </div>

            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Gestión de usuarios</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Cuentas de acceso y roles operativos
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por nombre o email..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <span v-tooltip.bottom="limitReached ? `Límite de ${userLimit} usuarios alcanzado` : 'Crear nuevo usuario'">
                            <Link :href="route('users.create')" :class="{ 'pointer-events-none': limitReached }">
                                <Button v-if="hasPermission('settings.users.create')" label="Crear usuario"
                                    icon="pi pi-plus" severity="warning" :disabled="limitReached"
                                    class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                            </Link>
                        </span>
                    </div>
                </div>

                <!-- Tabla de Usuarios -->
                <DataTable :value="users.data" lazy paginator
                    :totalRecords="users.total" :rows="users.per_page"
                    :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" @sort="onSort"
                    removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} usuarios"
                    class="cursor-pointer" rowHover :pt="dataTablePt">
                    
                    <Column field="name" header="Nombre" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30 text-blue-500 font-bold text-xs">
                                    {{ data.name.charAt(0).toUpperCase() }}
                                </div>
                                <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.name }}</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="email" header="Email" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400 font-mono">{{ data.email }}</span>
                        </template>
                    </Column>
                    
                    <Column field="roles" header="Rol asignado">
                        <template #body="{ data }">
                            <Tag v-if="data.roles && data.roles.length > 0" :value="data.roles[0].name" severity="info" :pt="tagPt" />
                            <Tag v-else value="Súper Admin" severity="success" icon="pi pi-shield" :pt="tagPt" />
                        </template>
                    </Column>
                    
                     <Column field="is_active" header="Estatus">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full" :class="data.is_active ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-gray-400 dark:bg-gray-600'"></span>
                                <span class="text-[10px] uppercase tracking-widest font-bold" :class="data.is_active ? 'text-green-600 dark:text-green-400' : 'text-gray-500'">
                                    {{ data.is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                        </template>
                    </Column>
                    
                     <Column field="created_at" header="Registro" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-500 dark:text-gray-400">{{ formatDate(data.created_at) }}</span>
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
                            <i class="pi pi-users !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay usuarios que coincidan con tu búsqueda.</p>
                        </div>
                    </template>
                </DataTable>
                
                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>
    </AppLayout>
</template>