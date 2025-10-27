<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import { ref } from 'vue';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

// Función para redirigir al login con Google
const redirectToGoogle = () => {
    window.location.href = route('auth.google');
};

// SVG Icono de Apple
const AppleIcon = ref(`
    <svg class="w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12.01,2.07c-2.48,0-4.49,2.01-4.49,4.49s2.01,4.49,4.49,4.49s4.49-2.01,4.49-4.49S14.49,2.07,12.01,2.07z M17.42,12.19 c-1.68-1.68-3.92-2.61-6.3-2.61c-0.1,0-0.2,0-0.29,0c-2.48,0.09-4.7,1.08-6.3,2.78c-0.09,0.09-0.17,0.19-0.24,0.29 c-1.37,1.83-2.2,4.06-2.2,6.46c0,0.27,0,0.54,0.02,0.81h17.82c0.01-0.27,0.02-0.54,0.02-0.81C19.62,16.25,18.79,14.02,17.42,12.19z" transform="scale(1.2) translate(-2, -3)"/>
        <path d="M19.11,10.15c-0.86,0-1.67-0.22-2.39-0.61c-0.54,1.05-1.2,2.01-2.01,2.83c0.9,0.61,1.6,1.44,2.07,2.44 c0.86,0.05,1.72,0.22,2.54,0.52c0.61-1.18,0.94-2.49,0.94-3.87C20.26,10.97,19.74,10.46,19.11,10.15z"/>
        <path d="M12.01,11.08c-0.63,0-1.22,0.2-1.74,0.55c-0.69-0.95-1.21-1.99-1.54-3.1c-1,0.27-1.92,0.76-2.68,1.43 c0.33,1.13,0.83,2.19,1.5,3.15c-0.51,0.36-1.1,0.57-1.72,0.57c-0.19,0-0.38-0.02-0.56-0.05c-0.86,1.47-1.4,3.1-1.57,4.85h10.5 c-0.17-1.75-0.71-3.38-1.57-4.85C12.39,11.06,12.2,11.08,12.01,11.08z"/>
    </svg>
`);

</script>

<!-- 
  Inyectamos tus colores primarios en las variables CSS de PrimeVue.
  Esto hará que los botones (sin 'severity') y los enlaces 
  usen tu color #f68c0f automáticamente.
-->
<style>
:root {
    --p-primary-color: #f68c0f;
    --p-primary-color-text: #1A1A1A;
    --p-primary-500: #f68c0f;
    --p-primary-600: #e07e0e; /* Un tono más oscuro para hover */
    --p-primary-700: #c9700d; /* Un tono más oscuro para active */
}

/* Estilos para el botón secundario (Google/Apple) como en la imagen */
.p-button.p-button-secondary.p-button-outlined {
    --p-button-outlined-border-color: #cbd5e1; /* gris claro */
    --p-button-outlined-hover-border-color: #94a3b8;
    --p-button-outlined-hover-bg: #f8fafc; /* fondo muy claro en hover */
    --p-button-outlined-color: #334155; /* color de texto oscuro */
}

.dark .p-button.p-button-secondary.p-button-outlined {
    --p-button-outlined-border-color: #4b5563; /* gris oscuro */
    --p-button-outlined-hover-border-color: #6b7280;
    --p-button-outlined-hover-bg: #1f2937; /* fondo oscuro en hover */
    --p-button-outlined-color: #e5e7eb; /* color de texto claro */
}
</style>

<template>
    <Head title="Iniciar sesión" />

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
                    <h4 class="text-xl font-bold tracking-tight text-surface-900 dark:text-surface-0 m-0">
                        Bienvenido de nuevo
                    </h4>
                    <p class="mt-2 text-sm text-surface-600 dark:text-surface-400">
                        Ingresa tu email y contraseña para acceder a tu cuenta.
                    </p>
                </div>

                <Message v-if="status" severity="success" :closable="false">{{ status }}</Message>

                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Campo de Email (Sin FloatLabel) -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Correo electrónico
                        </label>
                        <div class="mt-1">
                            <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email"
                                required autofocus fluid placeholder="tu@email.com" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                            Contraseña
                        </label>
                        <div class="mt-1">
                            <Password id="password" v-model="form.password" :invalid="!!form.errors.password"
                                toggleMask :feedback="false" inputClass="w-full" fluid required
                                placeholder="Tu contraseña" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <Checkbox id="remember" v-model="form.remember" :binary="true" class="mr-2" />
                            <label for="remember"
                                class="text-sm text-surface-600 dark:text-surface-400">Recuérdame</label>
                        </div>

                        <div v-if="canResetPassword">
                            <Link :href="route('password.request')"
                                class="text-sm font-medium text-primary-600 hover:text-primary-500">
                            ¿Olvidaste tu contraseña?
                            </Link>
                        </div>
                    </div>

                    <div>
                        <!-- Este botón usará tu color primario #f68c0f gracias al <style> -->
                        <Button type="submit" label="Iniciar sesión" class="w-full font-bold"
                            :loading="form.processing" />
                    </div>
                </form>

                <!-- Divisor "O continúa con" -->
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-surface-300 dark:border-surface-700" />
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="bg-white dark:bg-surface-900 px-2 text-surface-500 dark:text-surface-400">
                            O inicia sesión con
                        </span>
                    </div>
                </div>

                <!-- Botones de Redes Sociales -->
                <div class="grid grid-cols-1 sm:grid-cols-1 gap-4">
                    <Button @click="redirectToGoogle" label="Google" severity="secondary" outlined class="w-full">
                        <template #icon>
                            <svg class="w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"
                                width="48px" height="48px">
                                <path fill="#FFC107"
                                    d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12s5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24s8.955,20,20,20s20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z" />
                                <path fill="#FF3D00"
                                    d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z" />
                                <path fill="#4CAF50"
                                    d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.222,0-9.657-3.356-11.303-7.962l-6.571,4.819C9.656,39.663,16.318,44,24,44z" />
                                <path fill="#1976D2"
                                    d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.574l6.19,5.238C39.901,36.627,44,30.638,44,24C44,22.659,43.862,21.35,43.611,20.083z" />
                            </svg>
                        </template>
                    </Button>
                </div>

                <!-- Enlace de Registro -->
                <p class="text-center text-sm text-surface-600 dark:text-surface-400">
                    ¿Aún no tienes una cuenta?
                    <Link :href="route('register')" class="font-medium text-primary-600 hover:text-primary-500">
                    Crea una cuenta gratis
                    </Link>
                </p>

            </div>

            <!-- Columna Derecha: Imagen y Marketing -->
            <div class="hidden md:flex flex-col justify-center items-center pt-4"
                :style="{ backgroundColor: '#000000' }">
                <div class="text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-white">
                        Gestiona tu negocio sin esfuerzo
                    </h2>
                    <p class="m-6 text-lg text-gray-300">
                        Todo lo que necesitas: Punto de venta, inventario, clientes, reportes y más.
                    </p>
                </div>
                <img src="@/../../public/images/banner_login.png" :draggable="false"
                    alt="Panel de SaaS POS" class="mt-8 object-cover h-full select-none"
                    onerror="this.src='https://placehold.co/600x400?text=Error+al+cargar+imagen'">
            </div>

        </div>
    </div>
</template>