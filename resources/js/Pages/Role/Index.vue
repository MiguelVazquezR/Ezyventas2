<script setup>
import { ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    roles: Array,
    permissions: Object,
});

const toast = useToast();
const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const selectedRole = ref(props.roles.length > 0 ? props.roles[0] : null);

const permissionsForm = useForm({ permissions: [] });

watch(selectedRole, (newRole) => {
    if (newRole) {
        permissionsForm.permissions = newRole.permissions.map(p => p.id);
    } else {
        permissionsForm.reset();
    }
}, { immediate: true });

const submitPermissions = () => {
    if (selectedRole.value) {
        permissionsForm.put(route('roles.update', selectedRole.value.id), {
            preserveScroll: true,
            onSuccess: () => toast.add({ severity: 'success', summary: 'Éxito', detail: 'Permisos guardados.', life: 3000 })
        });
    }
};

const isCreateRoleModalVisible = ref(false);
const createRoleForm = useForm({ name: '' });
const submitCreateRole = () => {
    createRoleForm.post(route('roles.store'), {
        onSuccess: () => {
            isCreateRoleModalVisible.value = false;
            createRoleForm.reset();
        }
    });
};

const confirmDeleteRole = (role) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el rol "${role.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => useForm({}).delete(route('roles.destroy', role.id))
    });
};

// --- LÓGICA PARA GESTIONAR PERMISOS ---
const isCreatePermissionModalVisible = ref(false);
const isEditPermissionModalVisible = ref(false);
const selectedPermissionForEdit = ref(null);

const createPermissionForm = useForm({ name: '', description: '', module: '' });
const editPermissionForm = useForm({ name: '', description: '', module: '' });

const openEditPermissionModal = (permission) => {
    selectedPermissionForEdit.value = permission;
    editPermissionForm.name = permission.name;
    editPermissionForm.description = permission.description;
    editPermissionForm.module = permission.module;
    isEditPermissionModalVisible.value = true;
};

const submitCreatePermission = () => {
    createPermissionForm.post(route('permissions.store'), {
        onSuccess: () => {
            isCreatePermissionModalVisible.value = false;
            createPermissionForm.reset();
        }
    });
};

const submitEditPermission = () => {
    if (selectedPermissionForEdit.value) {
        editPermissionForm.put(route('permissions.update', selectedPermissionForEdit.value.id), {
            onSuccess: () => isEditPermissionModalVisible.value = false
        });
    }
};

const confirmDeletePermission = (permission) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el permiso "${permission.description}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => useForm({}).delete(route('permissions.destroy', permission.id))
    });
};
</script>

<template>

    <Head title="Roles y Permisos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Gestión de Roles y Permisos</h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Crea roles y asigna permisos para controlar el
                        acceso en tu sucursal.</p>
                </div>
                <Button @click="isCreatePermissionModalVisible = true" label="Crear Permiso" icon="pi pi-plus"
                    severity="contrast" />
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Roles -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-md">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="font-bold text-lg">Roles de la Sucursal</h2>
                            <Button v-if="hasPermission('settings.roles_permissions.manage')" @click="isCreateRoleModalVisible = true" icon="pi pi-plus" size="small"
                                v-tooltip.bottom="'Crear nuevo rol'" />
                        </div>
                        <Listbox v-model="selectedRole" :options="roles" optionLabel="name" class="w-full">
                            <template #option="slotProps">
                                <div class="flex justify-between items-center w-full">
                                    <span>{{ slotProps.option.name }}</span>
                                    <Button v-if="hasPermission('settings.roles_permissions.delete')" @click.stop="confirmDeleteRole(slotProps.option)" icon="pi pi-trash"
                                        severity="danger" text rounded size="small" />
                                </div>
                            </template>
                        </Listbox>
                    </div>
                </div>

                <!-- Columna de Permisos -->
                <div class="lg:col-span-2">
                    <div v-if="selectedRole" class="bg-white dark:bg-gray-800 rounded-lg shadow-md">
                        <div class="p-3 border-b dark:border-gray-700">
                            <h2 class="text-xl font-bold">Permisos para el rol: <span class="text-orange-500">{{
                                selectedRole.name
                            }}</span></h2>
                            <p class="text-sm text-gray-500 mt-1">Selecciona los permisos que tendrá este rol.</p>
                        </div>

                        <form @submit.prevent="submitPermissions">
                            <div class="p-3 max-h-[45vh] overflow-y-auto">
                                <Accordion :multiple="true" :activeIndex="[0, 1, 2, 3, 4, 5]">
                                    <AccordionTab v-for="(perms, moduleName) in permissions" :key="moduleName"
                                        :header="moduleName">
                                        <div class="space-y-4">
                                            <div v-for="permission in perms" :key="permission.id"
                                                class="flex items-center justify-between">
                                                <div class="flex items-center">
                                                    <Checkbox v-model="permissionsForm.permissions"
                                                        :inputId="`perm-${permission.id}`" :value="permission.id"
                                                        :disabled="!hasPermission('settings.roles_permissions.manage')" />
                                                    <label :for="`perm-${permission.id}`" class="ml-3">
                                                        <span class="font-semibold">{{ permission.description }}</span>
                                                        <p class="text-xs text-gray-500">{{ permission.name }}</p>
                                                    </label>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <Button @click.stop="openEditPermissionModal(permission)"
                                                        icon="pi pi-pencil" text rounded size="small" />
                                                    <Button @click.stop="confirmDeletePermission(permission)"
                                                        icon="pi pi-trash" text rounded severity="danger"
                                                        size="small" />
                                                </div>
                                            </div>
                                        </div>
                                    </AccordionTab>
                                </Accordion>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-b-lg flex justify-end">
                                <Button v-if="hasPermission('settings.roles_permissions.manage')" type="submit" label="Guardar Permisos" icon="pi pi-check"
                                    :loading="permissionsForm.processing" :disabled="!permissionsForm.isDirty" />
                            </div>
                        </form>
                    </div>
                    <div v-else
                        class="flex items-center justify-center h-full bg-white dark:bg-gray-800 rounded-lg shadow-md p-8">
                        <div class="text-center">
                            <i class="pi pi-arrow-left text-4xl text-gray-400 mb-4"></i>
                            <h3 class="text-lg font-bold">Selecciona un Rol</h3>
                            <p class="text-gray-500">Elige un rol para ver y editar sus permisos.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modales de Roles y Permisos -->
        <Dialog v-model:visible="isCreateRoleModalVisible" modal header="Crear Nuevo Rol" :style="{ width: '25rem' }">
            <form @submit.prevent="submitCreateRole" class="p-2 space-y-4">
                <div>
                    <label for="role-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nombre del
                        Rol</label>
                    <InputText id="role-name" v-model="createRoleForm.name" class="w-full mt-1" />
                    <InputError :message="createRoleForm.errors.name" class="mt-1" />
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <Button type="button" label="Cancelar" severity="secondary"
                        @click="isCreateRoleModalVisible = false" text></Button>
                    <Button type="submit" label="Crear Rol" :loading="createRoleForm.processing"></Button>
                </div>
            </form>
        </Dialog>
        <Dialog v-model:visible="isCreatePermissionModalVisible" modal header="Crear Nuevo Permiso"
            :style="{ width: '30rem' }">
            <form @submit.prevent="submitCreatePermission" class="p-2 space-y-4">
                <div>
                    <InputLabel value="Módulo" />
                    <InputText v-model="createPermissionForm.module" class="w-full mt-1" placeholder="Ej: Productos" />
                    <InputError :message="createPermissionForm.errors.module" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Descripción" />
                    <InputText v-model="createPermissionForm.description" class="w-full mt-1"
                        placeholder="Ej: Crear nuevos productos" />
                    <InputError :message="createPermissionForm.errors.description" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Nombre Clave (name)" />
                    <InputText v-model="createPermissionForm.name" class="w-full mt-1"
                        placeholder="Ej: products.create" />
                    <InputError :message="createPermissionForm.errors.name" class="mt-1" />
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <Button type="button" label="Cancelar" severity="secondary"
                        @click="isCreatePermissionModalVisible = false" text />
                    <Button type="submit" label="Crear Permiso" :loading="createPermissionForm.processing" />
                </div>
            </form>
        </Dialog>
        <Dialog v-model:visible="isEditPermissionModalVisible" modal header="Editar Permiso"
            :style="{ width: '30rem' }">
            <form @submit.prevent="submitEditPermission" class="p-2 space-y-4">
                <div>
                    <InputLabel value="Módulo" />
                    <InputText v-model="editPermissionForm.module" class="w-full mt-1" />
                    <InputError :message="editPermissionForm.errors.module" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Descripción" />
                    <InputText v-model="editPermissionForm.description" class="w-full mt-1" />
                    <InputError :message="editPermissionForm.errors.description" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Nombre Clave (name)" />
                    <InputText v-model="editPermissionForm.name" class="w-full mt-1" />
                    <InputError :message="editPermissionForm.errors.name" class="mt-1" />
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <Button type="button" label="Cancelar" severity="secondary"
                        @click="isEditPermissionModalVisible = false" text />
                    <Button type="submit" label="Guardar Cambios" :loading="editPermissionForm.processing" />
                </div>
            </form>
        </Dialog>
    </AppLayout>
</template>