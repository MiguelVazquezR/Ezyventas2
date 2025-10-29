<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

const props = defineProps({
    status: String,
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
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

    <Head title="Verificar Correo Electrónico" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <!-- Tarjeta única y centrada, similar a ConfirmPassword.vue -->
        <div class="w-full max-w-xl bg-white dark:bg-surface-900 shadow-2xl rounded-2xl overflow-hidden p-8 md:p-10">
            <div class="flex flex-col items-center justify-center space-y-6">

                <AuthenticationCardLogo class="h-10 w-auto" />

                <div class="text-center pt-6">
                    <h4 class="text-xl font-bold tracking-tight text-surface-900 dark:text-surface-0 m-0">
                        Verifica tu correo electrónico
                    </h4>
                    <p class="mt-3 text-sm text-surface-600 dark:text-surface-400">
                        ¡Gracias por registrarte! Antes de continuar, ¿podrías verificar tu dirección de correo electrónico
                        haciendo clic en el enlace que te acabamos de enviar?
                    </p>
                    <p class="mt-2 text-sm text-surface-600 dark:text-surface-400">
                        Si no recibiste el correo, con gusto te enviaremos otro.
                    </p>
                </div>

                <!-- Mensaje de estado (email reenviado) -->
                <Message v-if="verificationLinkSent" severity="success" :closable="false" class="w-full">
                    Se ha enviado un nuevo enlace de verificación al correo electrónico que proporcionaste durante el
                    registro.
                </Message>

                <form @submit.prevent="submit" class="w-full">
                    <div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <!-- Botón principal de PrimeVue -->
                        <Button type="submit" label="Reenviar correo de verificación" class="w-full sm:w-auto font-bold"
                            :class="{ 'opacity-25': form.processing }" :disabled="form.processing"
                            :loading="form.processing" />

                        <div class="flex gap-4">
                             <Link
                                :href="route('profile.show')"
                                class="text-sm font-medium text-primary-600 hover:text-primary-500"
                            >
                                Editar Perfil
                            </Link>
                            
                            <!-- Botón secundario para Cerrar Sesión -->
                            <Link :href="route('logout')" method="post" as="button"
                                class="text-sm font-medium text-surface-600 hover:text-surface-900 dark:text-surface-400 dark:hover:text-surface-200">
                            Cerrar Sesión
                            </Link>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>