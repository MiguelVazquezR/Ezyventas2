<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';

// Importaciones de PrimeVue
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';

const props = defineProps({
    email: String,
    token: String,
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<!-- Estilos consistentes con las otras vistas -->
<style>
:root {
    --p-primary-color: #f68c0f;
    --p-primary-color-text: #1A1A1A;
    --p-primary-500: #f68c0f;
    --p-primary-600: #e07e0e;
    --p-primary-700: #c9700d;
}
</style>

<template>

    <Head title="Restablecer Contraseña" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <!-- Contenedor principal de la tarjeta (Una sola columna) -->
        <div
            class="w-full max-w-md bg-white dark:bg-surface-900 shadow-2xl rounded-2xl overflow-hidden">

            <!-- Formulario -->
            <div class="p-8 md:p-10 flex flex-col justify-center space-y-5">
                <div class="flex justify-center">
                    <AuthenticationCardLogo class="h-10 w-auto" />
                </div>

                <div>
                    <h4 class="text-xl text-center font-bold tracking-tight text-surface-900 dark:text-surface-0 m-0 pt-5">
                        Elige tu nueva contraseña
                    </h4>
                    <p class="mt-2 text-sm text-center text-surface-600 dark:text-surface-400">
                        Ingresa tu correo y una contraseña segura para recuperar el acceso a tu cuenta.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Campo de Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Correo electrónico
                        </label>
                        <div class="mt-1">
                            <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email"
                                required autofocus fluid placeholder="tu@email.com" autocomplete="username" disabled />
                        </div>
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <!-- Campo de Contraseña -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Nueva contraseña
                        </label>
                        <div class="mt-1">
                            <Password id="password" v-model="form.password" toggleMask :feedback="true" fluid
                                :invalid="!!form.errors.password" inputClass="w-full"
                                placeholder="Tu nueva contraseña" autocomplete="new-password">
                                <template #header>
                                    <h6>Elige una contraseña segura</h6>
                                </template>
                                <template #footer>
                                    <ul class="pl-2 ml-2 text-xs mt-1 text-gray-600 dark:text-gray-400">
                                        <li>Al menos una minúscula</li>
                                        <li>Al menos una mayúscula</li>
                                        <li>Al menos un número</li>
                                        <li>Mínimo 8 caracteres</li>
                                    </ul>
                                </template>
                            </Password>
                        </div>
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <!-- Campo de Confirmar Contraseña -->
                    <div>
                        <label for="password_confirmation"
                            class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Confirmar contraseña
                        </label>
                        <div class="mt-1">
                            <Password id="password_confirmation" v-model="form.password_confirmation"
                                :invalid="!!form.errors.password_confirmation" toggleMask :feedback="false"
                                inputClass="w-full" fluid required placeholder="Confirma tu nueva contraseña"
                                autocomplete="new-password" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.password_confirmation" />
                    </div>

                    <div class="flex items-center justify-end pt-4">
                        <Button type="submit" label="Restablecer contraseña" class="w-full font-bold"
                            :loading="form.processing" />
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>