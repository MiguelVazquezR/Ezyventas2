<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';

// Importaciones de PrimeVue
import Button from 'primevue/button';
import Password from 'primevue/password'; // Usamos Password para consistencia

const form = useForm({
    password: '',
});

const passwordInput = ref(null);

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
            // Aseguramos que el input interno de PrimeVue reciba el foco
            if (passwordInput.value) {
                passwordInput.value.focus(); // El foco en el componente de PrimeVue puede requerir un ref al input interno si este no funciona
            }
        },
    });
};
</script>

<!-- Estilos consistentes con Login y Register -->
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

    <Head title="Área Segura" />

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
                        Área Segura
                    </h4>
                    <p class="mt-2 text-sm text-center text-surface-600 dark:text-surface-400">
                        Esta es un área segura de la aplicación. Por favor, confirma tu contraseña para continuar.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    <div>
                        <label for="password" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Contraseña
                        </label>
                        <div class="mt-1">
                            <!-- Componente Password de PrimeVue -->
                            <Password id="password" ref="passwordInput" v-model="form.password"
                                :invalid="!!form.errors.password" toggleMask :feedback="false" inputClass="w-full"
                                fluid required placeholder="Tu contraseña" autocomplete="current-password" autofocus />
                        </div>
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div class="flex justify-end mt-4">
                        <!-- Componente Button de PrimeVue -->
                        <Button type="submit" label="Confirmar" class="w-full font-bold"
                            :loading="form.processing" />
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>