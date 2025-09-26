<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import RolePermissionsModal from '@/Components/RolePermissionsModal.vue';

const props = defineProps({
    user: Object,
    roles: Array,
});

const form = useForm({
    _method: 'PUT',
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    role_id: props.user.roles[0]?.id || null,
});

const submit = () => {
    form.post(route('users.update', props.user.id), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

// --- Lógica para el modal de permisos ---
const isPermissionsModalVisible = ref(false);
const selectedRoleForPermissions = ref(null);

const showRolePermissions = (roleId) => {
    if (!roleId) return;
    const role = props.roles.find(r => r.id === roleId);
    if (role) {
        selectedRoleForPermissions.value = role;
        isPermissionsModalVisible.value = true;
    }
};
</script>

<template>

    <Head title="Editar Usuario" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-2xl mx-auto bg-white dark:bg-gray-800 rounded-lg shadow-md">
                <div class="p-6 border-b dark:border-gray-700">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Editar Usuario</h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Modifica los datos del miembro del equipo.</p>
                </div>

                <form @submit.prevent="submit">
                    <div class="p-6 space-y-6">
                        <div>
                            <InputLabel for="name" value="Nombre Completo" />
                            <InputText id="name" v-model="form.name" type="text" class="mt-1 block w-full" required
                                autofocus autocomplete="name" />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="email" value="Correo Electrónico" />
                            <InputText id="email" v-model="form.email" type="email" class="mt-1 block w-full" required
                                autocomplete="username" />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="role" value="Rol del Usuario" />
                            <div class="flex items-end gap-2">
                                <div class="flex-grow">
                                    <Dropdown v-model="form.role_id" :options="roles" optionLabel="name"
                                        optionValue="id" placeholder="Selecciona un rol" class="w-full mt-1" />
                                </div>
                                <Button @click="showRolePermissions(form.role_id)" type="button" icon="pi pi-eye"
                                    severity="secondary" outlined :disabled="!form.role_id"
                                    v-tooltip.bottom="'Ver permisos del rol'" />
                            </div>
                            <InputError class="mt-2" :message="form.errors.role_id" />
                        </div>

                        <Divider />

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Completa el siguiente campo solo si deseas cambiar la contraseña.
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="password" value="Nueva Contraseña" />
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
    </AppLayout>
</template>