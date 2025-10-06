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
                        <InputText id="email" v-model="form.email" type="email" autocomplete="off" readonly
                            @focus="$event.target.removeAttribute('readonly')" :invalid="!!form.errors.email" fluid />
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

                <!-- CAMPO DE CONFIRMACIÓN DE CONTRASEÑA AÑADIDO -->
                <div>
                    <FloatLabel>
                        <Password id="password_confirmation" v-model="form.password_confirmation" toggleMask
                            :feedback="false" fluid :invalid="!!form.errors.password_confirmation"
                            inputClass="w-full" />
                        <label for="password_confirmation">Confirmar Contraseña</label>
                    </FloatLabel>
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
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

            <!-- <div class="relative">
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