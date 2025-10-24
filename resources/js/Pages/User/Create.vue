<script setup>
import { ref, markRaw, nextTick, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import RolePermissionsModal from '@/Components/RolePermissionsModal.vue';
import CreateRoleModal from '@/Components/CreateRoleModal.vue';

const props = defineProps({
    roles: Array,
    permissions: Object,
    userLimit: Number,
    userUsage: Number,
    bankAccounts: Array,
});

// --- AÑADIDO: Lógica para verificar si se alcanzó el límite ---
const limitReached = computed(() => {
    if (props.userLimit === -1) return false; // Si es ilimitado, nunca se alcanza
    return props.userUsage >= props.userLimit;
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role_id: null,
    bank_account_ids: [],
});

// --- Lógica para el modal de roles ---
const localRoles = ref([...props.roles]);
const isCreateRoleModalVisible = ref(false);

const handleRoleCreated = (newRole) => {
    localRoles.value.push(markRaw(newRole));
    nextTick(() => {
        form.role_id = newRole.id;
    });
};

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
    const role = localRoles.value.find(r => r.id === roleId);
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
            <!-- AÑADIDO: Mensaje cuando se alcanza el límite -->
            <div v-if="limitReached"
                class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl mx-auto text-center">
                <i class="pi pi-exclamation-triangle !text-6xl text-amber-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Límite de Usuarios Alcanzado</h1>
                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Has alcanzado el límite de <strong>{{ userLimit }} usuarios</strong> permitido por tu plan actual.
                    Para agregar más usuarios, por favor mejora tu plan.
                </p>
                <div class="flex justify-center items-center gap-4">
                    <Link :href="route('users.index')">
                    <Button label="Volver a Usuarios" severity="secondary" outlined />
                    </Link>
                    <a :href="route('subscription.manage')" target="_blank" rel="noopener noreferrer">
                        <Button label="Mejorar Mi Plan" icon="pi pi-arrow-up" />
                    </a>
                </div>
            </div>

            <!-- Formulario de creación original -->
            <div v-else class="max-w-2xl mx-auto bg-white dark:bg-gray-800 rounded-lg shadow-md">
                <div class="p-6 border-b dark:border-gray-700">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Crear nuevo usuario</h1>
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
                                autocomplete="off" readonly @focus="$event.target.removeAttribute('readonly')" />
                            <InputError :message="form.errors.email" />
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
                            <InputError :message="form.errors.role_id" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <InputLabel for="bank_accounts" value="Cuentas Bancarias Permitidas" />
                                <i class="pi pi-info-circle text-gray-400" v-tooltip.right="'Son las cuentas que el usuario puede administrar (Ver y editar saldo, registrar gastos y pagos).'" />
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
        <CreateRoleModal v-model:visible="isCreateRoleModalVisible" :permissions="permissions"
            @created="handleRoleCreated" />
    </AppLayout>
</template>