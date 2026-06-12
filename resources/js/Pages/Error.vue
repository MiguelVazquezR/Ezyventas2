<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    status: Number,
});

const title = computed(() => {
    return {
        503: 'Servicio No Disponible',
        500: 'Error del Servidor',
        404: 'Página No Encontrada',
        403: 'Acceso Denegado',
        419: 'Página Expirada', // Agregado
    }[props.status];
});

const description = computed(() => {
    return {
        503: 'Disculpa, estamos realizando mantenimiento. Por favor, vuelve más tarde.',
        500: 'Vaya, algo salió mal en nuestros servidores.',
        404: 'Disculpa, la página que estás buscando no pudo ser encontrada.',
        403: 'Lo sentimos, no tienes los permisos necesarios para acceder a esta ruta.',
        419: 'Tu sesión ha expirado por inactividad. Por favor, recarga la página para restaurar tu sesión o iniciar sesión nuevamente.', // Agregado
    }[props.status];
});

</script>

<template>
    <Head :title="title" />
    <div class="bg-gray-100 dark:bg-neutral-700 flex items-center justify-center min-h-screen font-sans">
        <div class="w-full max-w-md p-8 text-center">
            <div class="mb-6 flex justify-center">
                <!-- Ajusta la ruta de la imagen según tu estructura pública si es necesario -->
                <img src="/images/error.png" alt="Error" draggable="false" class="select-none h-48 w-auto">                
            </div>
            
            <h1 class="text-6xl font-bold text-primary-600 dark:text-primary-400">{{ status }}</h1>
            <h2 class="mt-4 text-2xl font-semibold text-gray-800 dark:text-gray-100">{{ title }}</h2>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ description }}
            </p>
            <div class="mt-8">

                <!-- Botón para otros errores (Ir a Inicio) -->
                <Link :href="route('dashboard')">
                    <Button label="Regresar al Inicio" icon="pi pi-home" />
                </Link>
            </div>
        </div>
    </div>
</template>