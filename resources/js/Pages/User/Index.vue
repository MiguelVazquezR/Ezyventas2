<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    users: Object,
    filters: Object,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const searchTerm = ref(props.filters.search || '');
const menu = ref();
const selectedUserForMenu = ref(null);

// El menú de acciones ahora es dinámico y protege al admin
const menuItems = computed(() => {
    if (!selectedUserForMenu.value) return [];
    
    // Un usuario es considerado "protegido" si no tiene roles.
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
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
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
    return new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};
</script>

<template>
    <Head title="Usuarios" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                     <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Gestión de Usuarios</h1>
                     <div class="flex items-center gap-2 w-full md:w-auto">
                        <IconField iconPosition="left" class="w-full md:w-80">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por nombre o email..." class="w-full" />
                        </IconField>
                        <Link :href="route('users.create')">
                            <Button label="Crear Usuario" icon="pi pi-plus" />
                        </Link>
                     </div>
                </div>

                <!-- Tabla de Usuarios -->
                <DataTable :value="users.data" lazy paginator
                    :totalRecords="users.total" :rows="users.per_page"
                    :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" @sort="onSort"
                    removableSort tableStyle="min-width: 50rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} usuarios">
                    
                    <Column field="name" header="Nombre" sortable style="width: 25%"></Column>
                    <Column field="email" header="Email" sortable style="width: 25%"></Column>
                    <Column field="roles" header="Rol" style="width: 20%">
                        <template #body="{ data }">
                            <Tag v-if="data.roles && data.roles.length > 0" :value="data.roles[0].name" severity="info" />
                            <Tag v-else value="Admin Principal" severity="success" />
                        </template>
                    </Column>
                     <Column field="is_active" header="Estatus" style="width: 15%">
                        <template #body="{ data }">
                            <Tag :value="data.is_active ? 'Activo' : 'Inactivo'" :severity="data.is_active ? 'success' : 'danger'" />
                        </template>
                    </Column>
                     <Column field="created_at" header="Registro" sortable style="width: 15%">
                        <template #body="{ data }"> {{ formatDate(data.created_at) }} </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" />
                        </template>
                    </Column>
                </DataTable>
                
                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>