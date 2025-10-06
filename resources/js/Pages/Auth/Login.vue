<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';

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
</script>

<template>

    <Head title="Iniciar Sesión" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <div class="w-full max-w-md space-y-8">
            <div>
                <div class="flex justify-center">
                    <AuthenticationCardLogo class="h-16 w-auto" />
                </div>
                <h2 class="mt-6 text-center text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">
                    Inicia sesión en tu cuenta
                </h2>
                <p class="mt-2 text-center text-sm text-surface-600 dark:text-surface-400">
                    ¿Aún no tienes una?
                    <Link :href="route('register')" class="font-medium text-primary-600 hover:text-primary-500">
                    Crea una cuenta gratis
                    </Link>
                </p>
            </div>

            <Message v-if="status" severity="success" :closable="false">{{ status }}</Message>

            <form @submit.prevent="submit" class="space-y-7">
                <div>
                    <FloatLabel>
                        <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email" required
                            autofocus fluid />
                        <label for="email">Correo Electrónico</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <FloatLabel>
                        <Password id="password" v-model="form.password" :invalid="!!form.errors.password" toggleMask
                            :feedback="false" inputClass="w-full" fluid required />
                        <label for="password">Contraseña</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <Checkbox id="remember" v-model="form.remember" :binary="true" class="mr-2" />
                        <label for="remember" class="text-sm text-surface-600 dark:text-surface-400">Recuérdame</label>
                    </div>

                    <div v-if="canResetPassword">
                        <Link :href="route('password.request')"
                            class="text-sm font-medium text-primary-600 hover:text-primary-500">
                        ¿Olvidaste tu contraseña?
                        </Link>
                    </div>
                </div>

                <div>
                    <Button type="submit" label="Iniciar Sesión" class="w-full" :loading="form.processing" />
                </div>
            </form>

            <!-- <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-surface-300 dark:border-surface-700" />
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="bg-surface-50 dark:bg-surface-950 px-2 text-surface-500 dark:text-surface-400">O
                        continúa con</span>
                </div>
            </div>

            <div>
                <Button @click="redirectToGoogle" label="Iniciar Sesión con Google" severity="secondary" outlined
                    class="w-full">
                    <template #icon>
                        <svg class="w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="48px"
                            height="48px">
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
            </div> -->
        </div>
    </div>
</template>