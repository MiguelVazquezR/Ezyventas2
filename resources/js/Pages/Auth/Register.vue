<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import { ref } from 'vue';

const form = useForm({
    business_name: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

// Función para redirigir al registro con Google
const redirectToGoogle = () => {
    window.location.href = route('auth.google');
};
</script>

<style>
/* Inyectamos tus colores primarios en las variables CSS de PrimeVue. */
:root {
    --p-primary-color: #f68c0f;
    --p-primary-color-text: #1A1A1A;
    --p-primary-500: #f68c0f;
    --p-primary-600: #e07e0e;
    --p-primary-700: #c9700d;
}

/* Estilos para el botón secundario (Google/Apple) como en la imagen */
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

    <Head title="Crear Cuenta" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <!-- Contenedor principal de la tarjeta -->
        <div
            class="w-full max-w-6xl grid grid-cols-1 md:grid-cols-2 bg-white dark:bg-surface-900 shadow-2xl rounded-2xl overflow-hidden">

            <!-- Columna Izquierda: Formulario --><!-- Reducido el padding y el gap general para hacer el formulario más compacto -->
            <div class="p-8 md:p-10 flex flex-col justify-center space-y-5">
                <div class="flex justify-center md:justify-start mb-4">
                    <AuthenticationCardLogo class="h-10 w-auto" />
                </div>

                <div>
                    <h2 class="text-xl font-bold tracking-tight text-surface-900 dark:text-surface-0 m-0">
                        Crea tu cuenta gratis
                    </h2>
                    <p class="mt-1 text-sm text-surface-600 dark:text-surface-400">
                        Comienza a gestionar tu negocio en minutos.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Campos en dos columnas para pantallas md y superiores -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4">
                        <!-- Campo Nombre del Negocio -->
                        <div>
                            <label for="business_name"
                                class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                                Nombre del negocio
                            </label>
                            <div class="mt-1">
                                <InputText id="business_name" v-model="form.business_name"
                                    :invalid="!!form.errors.business_name" required autofocus
                                    placeholder="Ej. Mi tiendita" fluid />
                            </div>
                            <InputError class="mt-1" :message="form.errors.business_name" />
                        </div>

                        <!-- Campo Tu Nombre -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                                Tu nombre completo
                            </label>
                            <div class="mt-1">
                                <InputText id="name" v-model="form.name" :invalid="!!form.errors.name" required
                                    placeholder="Ej. Juan Pérez" fluid />
                            </div>
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>

                        <!-- Campo de Email -->
                        <div class="md:col-span-2">
                            <label for="email" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                                Correo electrónico
                            </label>
                            <div class="mt-1">
                                <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email"
                                    required placeholder="tu@email.com" fluid autocomplete="off" readonly
                                    @focus="$event.target.removeAttribute('readonly')" />
                            </div>
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>

                        <!-- Campo de Contraseña -->
                        <div>
                            <label for="password"
                                class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                                Contraseña
                            </label>
                            <div class="mt-1">
                                <Password id="password" v-model="form.password" toggleMask :feedback="true" fluid
                                    :invalid="!!form.errors.password" inputClass="w-full">
                                    <template #header>
                                        <h6>Elige una contraseña segura</h6>
                                    </template>
                                    <template #footer>
                                        <ul class="pl-2 ml-2 text-xs mt-1 text-gray-600">
                                            <li>Al menos una minúscula</li>
                                            <li>Al menos una mayúscula</li>
                                            <li>Al menos un número</li>
                                            <li>Mínimo 8 caracteres</li>
                                        </ul>
                                    </template>
                                </Password>
                            </div>
                            <InputError class="mt-1" :message="form.errors.password" />
                        </div>

                        <!-- Campo de Confirmar Contraseña -->
                        <div>
                            <label for="password_confirmation"
                                class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                                Confirmar Contraseña
                            </label>
                            <div class="mt-1">
                                <Password id="password_confirmation" v-model="form.password_confirmation"
                                    :invalid="!!form.errors.password_confirmation" toggleMask :feedback="false"
                                    inputClass="w-full" fluid required placeholder="Confirma tu contraseña" />
                            </div>
                            <InputError class="mt-1" :message="form.errors.password_confirmation" />
                        </div>
                    </div>

                    <!-- Términos y Condiciones -->
                    <div v-if="$page.props.jetstream.hasTermsAndPrivacyPolicyFeature" class="flex items-start">
                        <Checkbox id="terms" v-model="form.terms" :binary="true" :invalid="!!form.errors.terms"
                            class="mr-2 mt-0.5" />
                        <label for="terms" class="text-sm text-surface-600 dark:text-surface-400">
                            Acepto los <a target="_blank" :href="route('terms.show')"
                                class="font-medium text-primary-600 hover:text-primary-500">Términos de
                                Servicio</a>
                            y las <a target="_blank" :href="route('policy.show')"
                                class="font-medium text-primary-600 hover:text-primary-500">Políticas de
                                Privacidad</a>
                        </label>
                        <InputError class="mt-1" :message="form.errors.terms" />
                    </div>


                    <div>
                        <Button type="submit" label="Crear mi cuenta" class="w-full font-bold"
                            :loading="form.processing" :disabled="!form.terms" />
                    </div>
                </form>

                <!-- Divisor "O continúa con" -->
                <div class="relative mt-4">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-surface-300 dark:border-surface-700" />
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="bg-white dark:bg-surface-900 px-2 text-surface-500 dark:text-surface-400">
                            O regístrate con
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

                <!-- Enlace de Login -->
                <p class="text-center text-sm text-surface-600 dark:text-surface-400 mt-4">
                    ¿Ya tienes una cuenta?
                    <Link :href="route('login')" class="font-medium text-primary-600 hover:text-primary-500">
                    Inicia sesión aquí
                    </Link>
                </p>

            </div>

            <!-- Columna Derecha: Imagen y Marketing -->
            <div class="hidden md:flex flex-col justify-center items-center bg-[#4b4b4c]">
                <div class="text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-white pt-4">
                        Tu negocio, un solo lugar
                    </h2>
                    <p class="mt-4 text-lg text-gray-300">
                        Únete y obtén control total sobre tus ventas, inventario, clientes y reportes financieros.
                    </p>
                </div>
                <img src="@/../../public/images/banner_register.png" :draggable="false"
                    alt="Panel de SaaS POS" class="mt-8 object-cover h-full select-none"
                    onerror="this.src='https://placehold.co/600x400?text=Error+al+cargar+imagen'">
            </div>

        </div>
    </div>
</template>