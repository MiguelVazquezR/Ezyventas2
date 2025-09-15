<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';

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

// Función para redirigir al registro con Google (la ruta debe existir en web.php)
const redirectToGoogle = () => {
    window.location.href = route('auth.google');
};
</script>

<template>

    <Head title="Crear Cuenta" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <div class="w-full max-w-md space-y-8">
            <div>
                <div class="flex justify-center">
                    <AuthenticationCardLogo class="h-16 w-auto" />
                </div>
                <h2 class="mt-6 text-center text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">
                    Crea tu cuenta
                </h2>
                <p class="mt-2 text-center text-sm text-surface-600 dark:text-surface-400">
                    ¿Ya tienes una?
                    <Link :href="route('login')" class="font-medium text-primary-600 hover:text-primary-500">
                    Inicia sesión
                    </Link>
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-8">
                <div>
                    <FloatLabel>
                        <InputText id="business_name" v-model="form.business_name" fluid
                            :invalid="!!form.errors.business_name" autofocus />
                        <label for="business_name">Nombre del Negocio</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.business_name" />
                </div>

                <div>
                    <FloatLabel>
                        <InputText id="name" v-model="form.name" :invalid="!!form.errors.name" fluid />
                        <label for="name">Tu Nombre Completo</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <FloatLabel>
                        <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email" fluid />
                        <label for="email">Correo Electrónico</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <FloatLabel>
                        <Password id="password" v-model="form.password" toggleMask :feedback="true" fluid
                            :invalid="!!form.errors.password" inputClass="w-full">
                            <template #header>
                                <h6>Elige una contraseña segura</h6>
                            </template>
                            <template #footer>
                                <ul class="pl-2 ml-2 mt-0 text-sm">
                                    <li>Al menos una minúscula</li>
                                    <li>Al menos una mayúscula</li>
                                    <li>Al menos un número</li>
                                    <li>Mínimo 8 caracteres</li>
                                </ul>
                            </template>
                        </Password>
                        <label for="password">Contraseña</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div v-if="$page.props.jetstream.hasTermsAndPrivacyPolicyFeature" class="flex items-center">
                    <Checkbox id="terms" v-model="form.terms" :binary="true" :invalid="!!form.errors.terms"
                        class="mr-2" />
                    <label for="terms" class="text-sm text-surface-600 dark:text-surface-400">
                        Acepto los <a target="_blank" :href="route('terms.show')"
                            class="underline hover:text-primary-500">Términos</a>
                        y <a target="_blank" :href="route('policy.show')"
                            class="underline hover:text-primary-500">Políticas de
                            Privacidad</a>
                    </label>
                </div>

                <div>
                    <Button type="submit" label="Crear mi cuenta" class="w-full" :loading="form.processing" />
                </div>
            </form>

            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-surface-300 dark:border-surface-700" />
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="bg-surface-50 dark:bg-surface-950 px-2 text-surface-500 dark:text-surface-400 mx-2">
                        O continúa con
                    </span>
                </div>
            </div>

            <div>
                <Button @click="redirectToGoogle" label="Registrarse con Google" severity="secondary" outlined
                    class="w-full">
                    <template #icon>
                        <svg class="mr-2" width="18" height="18" viewBox="0 0 18 18" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M17.64 9.20455C17.64 8.56682 17.5827 7.95273 17.4764 7.36364H9V10.845H13.8436C13.635 11.97 13.0009 12.9232 12.0477 13.5618V15.8195H14.9564C16.6582 14.2527 17.64 11.9455 17.64 9.20455Z"
                                fill="#4285F4"></path>
                            <path
                                d="M9 18C11.43 18 13.4673 17.1941 14.9564 15.8195L12.0477 13.5618C11.2418 14.1018 10.2109 14.4205 9 14.4205C6.65591 14.4205 4.67182 12.8373 3.96409 10.71H0.957272V13.0418C2.43818 15.9873 5.48182 18 9 18Z"
                                fill="#34A853"></path>
                            <path
                                d="M3.96409 10.71C3.78409 10.17 3.68182 9.59318 3.68182 9C3.68182 8.40682 3.78409 7.83 3.96409 7.29H0.957272V9.62182C0.347727 7.545 1.545 5.10818 3.96409 7.29H0.957272C0.347727 5.10818 0.347727 2.45455 0.957272 0.378182V2.71H3.96409C4.67182 4.83273 6.65591 6.41591 9 6.41591C10.2109 6.41591 11.2418 6.73455 12.0477 7.29L14.9564 4.42182C13.4673 3.02864 11.43 2.18182 9 2.18182C5.48182 2.18182 2.43818 4.01273 0.957272 6.95818V9.29C0.957272 9.29 0.957272 9.29 0.957272 9.29V9.62182H0.957272V9.62182H0.957272C0.957272 9.62182 0.957272 9.62182 0.957272 9.62182C0.347727 7.545 0.347727 4.89182 0.957272 2.71C0.347727 4.89182 0.347727 7.545 0.957272 9.62182H3.96409C3.78409 10.17 3.68182 9.59318 3.68182 9C3.68182 8.40682 3.78409 7.83 3.96409 7.29Z"
                                fill="#FBBC05"></path>
                            <path
                                d="M17.4764 0.363636L14.9564 2.71H14.9564C14.1282 2.02273 12.91 1.545 11.4818 1.09091V1.09091C10.5627 0.722727 9.55636 0.5 8.5 0.5V0.5C6.18 0.5 4.14273 1.30591 2.65364 2.71L0.957272 0.378182C0.957272 0.378182 0.957272 0.378182 0.957272 0.378182C2.43818 -0.567273 5.48182 -1.39636 9 -1.39636C11.43 -1.39636 13.4673 -0.590455 14.9564 0.784091L17.4764 3.10909V0.363636Z"
                                fill="#EA4335"></path>
                        </svg>
                    </template>
                </Button>
            </div>
        </div>
    </div>
</template>