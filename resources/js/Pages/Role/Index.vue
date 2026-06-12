<script setup>
import { ref, watch, computed } from 'vue';
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
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
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
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => useForm({}).delete(route('permissions.destroy', permission.id))
    });
};

// --- LÓGICA DE SELECCIÓN EN GRUPO ---
const groupPermissionIds = (group) => group.map(p => p.id);

const isGroupFullySelected = (group) => {
    const ids = groupPermissionIds(group);
    return ids.length > 0 && ids.every(id => permissionsForm.permissions.includes(id));
};

const isGroupPartiallySelected = (group) => {
    const ids = groupPermissionIds(group);
    const selectedCount = ids.filter(id => permissionsForm.permissions.includes(id)).length;
    return selectedCount > 0 && selectedCount < ids.length;
};

const toggleGroupSelection = (group) => {
    const ids = groupPermissionIds(group);
    if (isGroupFullySelected(group)) {
        permissionsForm.permissions = permissionsForm.permissions.filter(id => !ids.includes(id));
    } else {
        const newPermissions = [...new Set([...permissionsForm.permissions, ...ids])];
        permissionsForm.permissions = newPermissions;
    }
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } // Sin blur
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm w-full' }
};

const accordionPt = {
    root: { class: 'space-y-4' },
    panel: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] overflow-hidden' },
    header: { class: 'bg-transparent dark:text-white' },
    headerAction: { class: 'p-4 lg:p-5 hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors flex items-center justify-between outline-none focus:ring-0 text-sm font-medium dark:text-gray-200' },
    content: { class: 'p-4 lg:p-5 pt-0 bg-transparent dark:text-gray-400' }
};
</script>

<template>
    <Head title="Roles y Permisos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header General -->
                <div class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Roles y permisos</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                            Gestión de niveles de acceso y seguridad
                        </p>
                    </div>
                    
                    <div class="flex gap-2 w-full md:w-auto">
                        <Button v-if="$page.props.auth.user.id == 1" @click="isCreatePermissionModalVisible = true" 
                            label="Nuevo permiso" icon="pi pi-shield" severity="secondary" 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider w-full md:w-auto" />
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8">
                    
                    <!-- Columna de Roles (Listado Customizado) -->
                    <div class="lg:col-span-4 flex flex-col">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0">Roles disponibles</h2>
                            <Button v-if="hasPermission('settings.roles_permissions.manage')"
                                @click="isCreateRoleModalVisible = true" icon="pi pi-plus" size="small" rounded text
                                class="!w-8 !h-8 !bg-gray-100 dark:!bg-[#1a1a1a] hover:dark:!bg-[#2a2a2a] !text-gray-600 dark:!text-gray-300" v-tooltip.top="'Crear rol'" />
                        </div>

                        <div class="flex flex-col gap-2 max-h-[60vh] overflow-y-auto custom-scrollbar pr-2">
                            <div v-for="role in roles" :key="role.id" 
                                @click="selectedRole = role"
                                class="p-4 rounded-2xl border transition-all cursor-pointer flex items-center justify-between group"
                                :class="selectedRole?.id === role.id ? 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-900/40' : 'bg-gray-50 dark:bg-[#1a1a1a] border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a]'">
                                
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 transition-colors"
                                        :class="selectedRole?.id === role.id ? 'bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400' : 'bg-gray-200 dark:bg-[#2a2a2a] text-gray-500 dark:text-gray-400'">
                                        <i class="pi pi-key !text-xs"></i>
                                    </div>
                                    <span class="font-medium text-sm m-0 transition-colors" 
                                        :class="selectedRole?.id === role.id ? 'text-blue-900 dark:text-blue-300 font-bold' : 'text-gray-900 dark:text-gray-100'">
                                        {{ role.name }}
                                    </span>
                                </div>
                                
                                <Button v-if="hasPermission('settings.roles_permissions.delete')"
                                    @click.stop="confirmDeleteRole(role)" icon="pi pi-trash"
                                    text rounded class="!w-8 !h-8 !p-0 !text-gray-400 hover:!text-red-500 hover:!bg-red-50 dark:hover:!bg-red-900/20 opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            
                            <div v-if="roles.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400 text-sm border border-dashed border-gray-200 dark:border-gray-700 rounded-2xl">
                                No hay roles registrados.
                            </div>
                        </div>
                    </div>

                    <!-- Columna de Permisos (Acordeón) -->
                    <div class="lg:col-span-8 flex flex-col">
                        
                        <div v-if="selectedRole" class="bg-gray-50 dark:bg-[#1a1a1a] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col h-full overflow-hidden">
                            
                            <!-- Cabecera de la sección de permisos -->
                            <div class="p-6 lg:p-8 border-b border-gray-200 dark:border-[#2a2a2a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white dark:bg-[#232323]">
                                <div>
                                    <h2 class="text-xl font-medium text-gray-900 dark:text-white m-0 tracking-tight flex items-center gap-2">
                                        Permisos asignados
                                    </h2>
                                    <p class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-1">
                                        Modificando rol: <span class="font-bold text-primary-500">{{ selectedRole.name }}</span>
                                    </p>
                                </div>
                                
                                <Button v-if="hasPermission('settings.roles_permissions.manage')" 
                                    @click="submitPermissions"
                                    label="Guardar cambios" icon="pi pi-save" :loading="permissionsForm.processing"
                                    :disabled="!permissionsForm.isDirty"
                                    class="!rounded-xl !text-xs !uppercase !tracking-widest !font-bold w-full sm:w-auto" />
                            </div>

                            <!-- Acordeón de Grupos -->
                            <div class="p-6 lg:p-8 overflow-y-auto max-h-[43vh] custom-scrollbar">
                                <Accordion :multiple="true" :value="Object.keys(permissions)" :pt="accordionPt">
                                    <AccordionPanel v-for="(group, groupName) in permissions" :key="groupName" :value="String(groupName)">
                                        <AccordionHeader>
                                            <div class="flex items-center gap-3 w-full" @click.stop>
                                                <Checkbox
                                                    :modelValue="isGroupFullySelected(group)"
                                                    :indeterminate="isGroupPartiallySelected(group)"
                                                    @change="toggleGroupSelection(group)"
                                                    :binary="true"
                                                    :disabled="!hasPermission('settings.roles_permissions.manage')"
                                                />
                                                <div class="flex flex-col">
                                                    <span class="font-bold text-sm tracking-tight capitalize text-gray-900 dark:text-gray-100">{{ groupName }}</span>
                                                    <span class="text-[9px] uppercase tracking-widest text-gray-400 font-normal mt-0.5">{{ group.length }} Permisos</span>
                                                </div>
                                            </div>
                                        </AccordionHeader>
                                        <AccordionContent>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                                                <div v-for="permission in group" :key="permission.id"
                                                    class="flex items-start gap-3 p-3 rounded-xl hover:bg-gray-100 dark:hover:bg-[#232323] border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-colors group/item">
                                                    
                                                    <Checkbox v-model="permissionsForm.permissions" :inputId="`perm-${permission.id}`" :value="permission.id" :disabled="!hasPermission('settings.roles_permissions.manage')" class="mt-0.5" />
                                                    
                                                    <div class="flex flex-col flex-grow cursor-pointer" @click="hasPermission('settings.roles_permissions.manage') ? (permissionsForm.permissions.includes(permission.id) ? permissionsForm.permissions = permissionsForm.permissions.filter(id => id !== permission.id) : permissionsForm.permissions.push(permission.id)) : null">
                                                        <label :for="`perm-${permission.id}`" class="text-sm font-medium text-gray-800 dark:text-gray-200 leading-tight m-0 cursor-pointer">{{ permission.description }}</label>
                                                        <span class="text-[10px] font-mono text-gray-400 dark:text-gray-500 mt-1 block">{{ permission.name }}</span>
                                                    </div>

                                                    <div v-if="$page.props.auth.user.id == 1" class="flex flex-col gap-1 opacity-0 group-hover/item:opacity-100 transition-opacity shrink-0">
                                                        <Button @click.stop="openEditPermissionModal(permission)" icon="pi pi-pencil" text rounded class="!w-6 !h-6 !p-0 !text-gray-400 hover:!text-primary-500" v-tooltip.top="'Editar'" />
                                                        <Button @click.stop="confirmDeletePermission(permission)" icon="pi pi-trash" text rounded class="!w-6 !h-6 !p-0 !text-gray-400 hover:!text-red-500" v-tooltip.top="'Eliminar'" />
                                                    </div>
                                                </div>
                                            </div>
                                        </AccordionContent>
                                    </AccordionPanel>
                                </Accordion>
                            </div>
                        </div>
                        
                        <div v-else class="flex flex-col items-center justify-center h-full bg-gray-50 dark:bg-[#1a1a1a] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-8 text-center opacity-60">
                            <i class="pi pi-key !text-5xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <h3 class="text-xl font-medium tracking-tight text-gray-900 dark:text-white m-0">Selecciona un rol</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-sm mx-auto">Selecciona un rol de la lista lateral para visualizar y gestionar sus permisos de acceso.</p>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Modales de Roles y Permisos (Estilo Tesla UI) -->
        
        <!-- Crear Rol -->
        <Dialog v-model:visible="isCreateRoleModalVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-key !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Nuevo rol</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Crear perfil de acceso</p>
                    </div>
                </div>
            </template>
            <form @submit.prevent="submitCreateRole" class="space-y-5">
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Nombre del rol *</span>
                    <InputText id="role-name" v-model="createRoleForm.name" placeholder="Ej: Gerente de Ventas" :pt="inputPt" />
                    <InputError :message="createRoleForm.errors.name" class="mt-2" />
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button type="button" label="Cancelar" text @click="isCreateRoleModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button type="submit" label="Crear rol" :loading="createRoleForm.processing" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-[0_4px_14px_rgba(59,130,246,0.4)]" />
                </div>
            </form>
        </Dialog>

        <!-- Crear Permiso (Maestro) -->
        <Dialog v-model:visible="isCreatePermissionModalVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 text-purple-500 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                        <i class="pi pi-shield !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Nuevo permiso</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Operación de sistema (Super Admin)</p>
                    </div>
                </div>
            </template>
            <form @submit.prevent="submitCreatePermission" class="space-y-5">
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Grupo o Módulo</span>
                    <InputText v-model="createPermissionForm.module" placeholder="Ej: Productos" :pt="inputPt" />
                    <InputError :message="createPermissionForm.errors.module" class="mt-2" />
                </div>
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Descripción (Visible)</span>
                    <InputText v-model="createPermissionForm.description" placeholder="Ej: Crear nuevos productos" :pt="inputPt" />
                    <InputError :message="createPermissionForm.errors.description" class="mt-2" />
                </div>
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Nombre Clave (System Name)</span>
                    <InputText v-model="createPermissionForm.name" placeholder="Ej: products.create" class="font-mono text-sm" :pt="inputPt" />
                    <InputError :message="createPermissionForm.errors.name" class="mt-2" />
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button type="button" label="Cancelar" text @click="isCreatePermissionModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button type="submit" label="Crear permiso" :loading="createPermissionForm.processing" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-[0_4px_14px_rgba(59,130,246,0.4)]" />
                </div>
            </form>
        </Dialog>

        <!-- Editar Permiso (Maestro) -->
        <Dialog v-model:visible="isEditPermissionModalVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
             <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                        <i class="pi pi-pencil !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Editar permiso</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Ajuste de registro</p>
                    </div>
                </div>
            </template>
            <form @submit.prevent="submitEditPermission" class="space-y-5">
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Módulo</span>
                    <InputText v-model="editPermissionForm.module" :pt="inputPt" />
                    <InputError :message="editPermissionForm.errors.module" class="mt-2" />
                </div>
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Descripción</span>
                    <InputText v-model="editPermissionForm.description" :pt="inputPt" />
                    <InputError :message="editPermissionForm.errors.description" class="mt-2" />
                </div>
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Nombre Clave (name)</span>
                    <InputText v-model="editPermissionForm.name" class="font-mono text-sm" :pt="inputPt" />
                    <InputError :message="editPermissionForm.errors.name" class="mt-2" />
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button type="button" label="Cancelar" text @click="isEditPermissionModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button type="submit" label="Guardar cambios" :loading="editPermissionForm.processing" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6" />
                </div>
            </form>
        </Dialog>

    </AppLayout>
</template>