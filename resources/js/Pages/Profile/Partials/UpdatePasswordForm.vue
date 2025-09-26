<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Password from 'primevue/password';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('user-password.update'), {
        errorBag: 'updatePassword',
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                // CAMBIO: Se accede al elemento '.input' del componente
                //passwordInput.value.input.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                // CAMBIO: Se accede al elemento '.input' del componente
                //currentPasswordInput.value.input.focus();
            }
        },
    });
};
</script>

<template>
     <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-1">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Actualizar Contraseña</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Asegúrate de que tu cuenta utiliza una contraseña larga y aleatoria para mantenerla segura.
            </p>
        </div>

        <div class="md:col-span-2">
            <form @submit.prevent="updatePassword">
                <div class="p-6 bg-white dark:bg-gray-800 sm:rounded-lg">
                    <div class="space-y-6">
                        <div>
                            <InputLabel for="current_password" value="Contraseña Actual" />
                            <Password fluid id="current_password" ref="currentPasswordInput" v-model="form.current_password" class="mt-1 block w-full" autocomplete="current-password" toggleMask :feedback="false"/>
                            <InputError :message="form.errors.current_password" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="password" value="Nueva Contraseña" />
                            <Password fluid id="password" ref="passwordInput" v-model="form.password" class="mt-1 block w-full" autocomplete="new-password" toggleMask :feedback="false"/>
                            <InputError :message="form.errors.password" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="password_confirmation" value="Confirmar Contraseña" />
                            <Password fluid id="password_confirmation" v-model="form.password_confirmation" class="mt-1 block w-full" autocomplete="new-password" toggleMask :feedback="false"/>
                            <InputError :message="form.errors.password_confirmation" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-6">
                         <transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                            <p v-if="form.recentlySuccessful" class="text-sm text-gray-600 dark:text-gray-400 mr-3">Guardado.</p>
                        </transition>
                        <Button :class="{ 'opacity-25': form.processing }" :disabled="form.processing" type="submit" label="Guardar" />
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>