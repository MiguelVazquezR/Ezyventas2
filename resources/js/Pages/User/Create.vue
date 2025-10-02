<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import RolePermissionsModal from '@/Components/RolePermissionsModal.vue';

const props = defineProps({
    roles: Array,
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role_id: null,
});

const submit = () => {
    form.post(route('users.store'), {
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

    <Head title="Crear Usuario" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-2xl mx-auto bg-white dark:bg-gray-800 rounded-lg shadow-md">
                <div class="p-6 border-b dark:border-gray-700">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Crear Nuevo Usuario</h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Completa los siguientes datos para registrar un
                        nuevo miembro del equipo.</p>
                </div>

                <form @submit.prevent="submit">
                    <div class="p-6 space-y-6">
                        <div>
                            <InputLabel for="name" value="Nombre completo *" />
                            <InputText id="name" v-model="form.name" type="text" class="mt-1 block w-full" required
                                autofocus autocomplete="name" />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="email" value="Correo electrónico *" />
                            <InputText id="email" v-model="form.email" type="email" class="mt-1 block w-full" required
                                autocomplete="username" />
                            <InputError :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="role" value="Rol del usuario *" />
                            <div class="flex items-end gap-2">
                                <div class="flex-grow">
                                    <Select v-model="form.role_id" :options="roles" optionLabel="name" optionValue="id"
                                        placeholder="Selecciona un rol" class="w-full mt-1" />
                                </div>
                                <Button @click="showRolePermissions(form.role_id)" type="button" icon="pi pi-book"
                                    severity="secondary" outlined :disabled="!form.role_id"
                                    v-tooltip.bottom="'Ver permisos del rol'" />
                            </div>
                            <InputError :message="form.errors.role_id" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="password" value="Contraseña *" />
                                <Password id="password" fluid v-model="form.password" class="mt-1 block w-full" required
                                    autocomplete="new-password" :feedback="false" toggleMask />
                                <InputError :message="form.errors.password" />
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-4 p-4 bg-gray-50 dark:bg-gray-900/50 rounded-b-lg">
                        <Link :href="route('users.index')">
                        <Button label="Cancelar" severity="secondary" text />
                        </Link>
                        <Button type="submit" label="Crear Usuario" :class="{ 'opacity-25': form.processing }"
                            :loading="form.processing" />
                    </div>
                </form>
            </div>
        </div>

        <RolePermissionsModal v-model:visible="isPermissionsModalVisible" :role="selectedRoleForPermissions" />
    </AppLayout>
</template>