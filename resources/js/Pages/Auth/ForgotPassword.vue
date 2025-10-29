<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    status: String,
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
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

.p-button.p-button-secondary.p-button-outlined {
    --p-button-outlined-border-color: #cbd5e1;
    --p-button-outlined-hover-border-color: #94a3b8;
    --p-button-outlined-hover-bg: #f8fafc;
    --p-button-outlined-color: #334155;
}

.dark .p-button.p-button-secondary.p-button-outlined {
    --p-button-outlined-border-color: #4b5563;
    --p-button-outlined-hover-border-color: #6b7280;
    --p-button-outlined-hover-bg: #1f2937;
    --p-button-outlined-color: #e5e7eb;
}
</style>

<template>

    <Head title="Recuperar Contraseña" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <!-- Contenedor principal de la tarjeta -->
        <div
            class="w-full max-w-6xl grid grid-cols-1 md:grid-cols-2 bg-white dark:bg-surface-900 shadow-2xl rounded-2xl overflow-hidden">

            <!-- Columna Izquierda: Formulario -->
            <div class="p-8 md:p-10 flex flex-col justify-center space-y-5">
                <div class="flex justify-center md:justify-start">
                    <AuthenticationCardLogo class="h-10 w-auto" />
                </div>

                <div>
                    <h4 class="text-xl font-bold tracking-tight text-surface-900 dark:text-surface-0 m-0 pt-5">
                        Recupera tu contraseña
                    </h4>
                    <p class="mt-2 text-sm text-surface-600 dark:text-surface-400">
                        ¿Olvidaste tu contraseña? No hay problema. Ingresa tu email y te enviaremos un enlace para
                        restablecerla.
                    </p>
                </div>

                <!-- Mensaje de estado (ej. email enviado) -->
                <Message v-if="status" severity="success" :closable="false">
                    {{ status }}. <br>
                    A veces el correo se puede ir a Spam, revisar antes de solicitar otro.
                </Message>

                <form @submit.prevent="submit" class="space-y-6">
                    <div>
                        <label for="email" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Correo electrónico
                        </label>
                        <div class="mt-1">
                            <!-- Componente InputText de PrimeVue -->
                            <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email"
                                required autofocus fluid placeholder="tu@email.com" autocomplete="username" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <!-- Componente Button de PrimeVue -->
                        <Button type="submit" label="Enviar enlace de recuperación" class="w-full font-bold"
                            :loading="form.processing" />
                    </div>
                </form>

                <!-- Enlace para volver a Iniciar Sesión -->
                <p class="text-center text-sm text-surface-600 dark:text-surface-400 pt-4">
                    ¿Recordaste tu contraseña?
                    <Link :href="route('login')" class="font-medium text-primary-600 hover:text-primary-500">
                    Inicia sesión aquí
                    </Link>
                </p>

            </div>

            <!-- Columna Derecha: Imagen y Marketing (Reutilizada de Login.vue para consistencia) -->
            <div class="hidden md:flex flex-col justify-center items-center pt-4"
                :style="{ backgroundColor: '#000000' }">
                <div class="text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-white">
                        Tu seguridad es nuestra prioridad
                    </h2>
                    <p class="m-6 text-lg text-gray-300">
                        Utilizamos las mejores prácticas y cifrado de datos para proteger la información de tu negocio.
                    </p>
                </div>
                <img src="@/../../public/images/banner_forgot_password.png" :draggable="false" alt="Panel de SaaS POS"
                    class="mt-8 object-cover h-full select-none mb-6"
                    onerror="this.src='https://placehold.co/600x400?text=Error+al+cargar+imagen'">
            </div>

        </div>
    </div>
</template>