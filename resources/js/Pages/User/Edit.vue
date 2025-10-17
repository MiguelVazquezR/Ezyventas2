<script setup>
import { ref, nextTick, markRaw } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import RolePermissionsModal from '@/Components/RolePermissionsModal.vue';
import CreateRoleModal from '@/Components/CreateRoleModal.vue';

const props = defineProps({
    user: Object,
    roles: Array,
    permissions: Object, // Se recibe la nueva prop de permisos
    bankAccounts: Array,
});

const form = useForm({
    _method: 'PUT',
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    role_id: props.user.roles[0]?.id || null,
    bank_account_ids: props.user.bank_accounts?.map(acc => acc.id) || [],
});

const submit = () => {
    form.post(route('users.update', props.user.id), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

// --- Lógica para el modal de visualización de permisos ---
const isPermissionsModalVisible = ref(false);
const selectedRoleForPermissions = ref(null);

const showRolePermissions = (roleId) => {
    if (!roleId) return;
    const role = localRoles.value.find(r => r.id === roleId); // Usa la lista local
    if (role) {
        selectedRoleForPermissions.value = role;
        isPermissionsModalVisible.value = true;
    }
};

// --- Lógica para el modal de creación de roles ---
const isCreateRoleModalVisible = ref(false);
const localRoles = ref([...props.roles]);

const handleRoleCreated = (newRole) => {
    localRoles.value.push(markRaw(newRole));
    nextTick(() => {
        form.role_id = newRole.id;
    });
};
</script>

<template>

    <Head title="Editar Usuario" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-2xl mx-auto bg-white dark:bg-gray-800 rounded-lg shadow-md">
                <div class="p-6 border-b dark:border-gray-700">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Editar usuario</h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Modifica los datos del miembro del equipo.</p>
                </div>

                <form @submit.prevent="submit">
                    <div class="p-6 space-y-6">
                        <div>
                            <InputLabel for="name" value="Nombre completo *" />
                            <InputText id="name" v-model="form.name" type="text" class="mt-1 block w-full" required
                                autofocus autocomplete="name" />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="email" value="Correo electrónico *" />
                            <InputText id="email" v-model="form.email" type="email" class="mt-1 block w-full" required
                                autocomplete="off" readonly @focus="$event.target.removeAttribute('readonly')" />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="role" value="Rol del usuario *" />
                            <div class="flex items-end gap-2">
                                <div class="flex-grow">
                                    <Select v-model="form.role_id" :options="localRoles" optionLabel="name"
                                        optionValue="id" placeholder="Selecciona un rol" class="w-full mt-1" />
                                </div>
                                <Button @click="isCreateRoleModalVisible = true" type="button" icon="pi pi-plus"
                                    severity="secondary" v-tooltip.bottom="'Crear nuevo rol'" />
                                <Button @click="showRolePermissions(form.role_id)" type="button" icon="pi pi-book"
                                    severity="secondary" outlined :disabled="!form.role_id"
                                    v-tooltip.bottom="'Ver permisos del rol'" />
                            </div>
                            <InputError class="mt-2" :message="form.errors.role_id" />
                        </div>

                        <div>
                            <div class="flex items-center gap-2">
                                <InputLabel for="bank_accounts" value="Cuentas Bancarias Permitidas" />
                                <i class="pi pi-info-circle text-gray-400"
                                    v-tooltip.right="'Son las cuentas que el usuario puede administrar (Ver y editar saldo, registrar gastos y pagos).'" />
                            </div>
                            <MultiSelect v-model="form.bank_account_ids" :options="bankAccounts"
                                optionLabel="account_name" optionValue="id" placeholder="Selecciona las cuentas"
                                class="w-full mt-1">
                                <template #option="slotProps">
                                    <div class="flex flex-col">
                                        <span>{{ slotProps.option.account_name }}</span>
                                        <small class="text-gray-500">{{ slotProps.option.bank_name }}</small>
                                    </div>
                                </template>
                            </MultiSelect>
                            <InputError :message="form.errors.bank_account_ids" />
                        </div>

                        <Divider />

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Completa el siguiente campo solo si deseas cambiar la contraseña.
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="password" value="Nueva contraseña" />
                                <Password id="password" fluid v-model="form.password" class="mt-1 block w-full"
                                    autocomplete="new-password" :feedback="false" toggleMask />
                                <InputError class="mt-2" :message="form.errors.password" />
                            </div>
                        </div>

                    </div>
                    <div class="flex items-center justify-end gap-4 p-4 bg-gray-50 dark:bg-gray-900/50 rounded-b-lg">
                        <Link :href="route('users.index')">
                        <Button label="Cancelar" severity="secondary" text />
                        </Link>
                        <Button type="submit" label="Guardar Cambios" :class="{ 'opacity-25': form.processing }"
                            :loading="form.processing" />
                    </div>
                </form>
            </div>
        </div>
        <RolePermissionsModal v-model:visible="isPermissionsModalVisible" :role="selectedRoleForPermissions" />
        <CreateRoleModal v-model:visible="isCreateRoleModalVisible" :permissions="permissions"
            @created="handleRoleCreated" />
    </AppLayout>
</template>