<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    terms: String,
});
</script>

<template>
    <Head title="Términos de Servicio" />

    <div class="font-sans text-gray-900 dark:text-gray-100 antialiased bg-gray-50 dark:bg-black min-h-screen">
        <div class="pt-8 pb-12 sm:pt-16 sm:pb-20">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col items-center">
                
                <!-- Logo con más espacio y animación sutil -->
                <div class="mb-8 transition-transform hover:scale-105 duration-300">
                    <AuthenticationCardLogo class="w-20 h-20" />
                </div>

                <!-- Tarjeta de contenido estilo Apple -->
                <div class="w-full bg-white dark:bg-gray-900 shadow-2xl shadow-gray-200/50 dark:shadow-none overflow-hidden sm:rounded-2xl ring-1 ring-gray-200 dark:ring-gray-800">
                    <div class="p-8 sm:p-12 lg:p-16">
                        <!-- El uso de prose-lg mejora la legibilidad (letra más grande) -->
                        <!-- prose-slate da un tono de gris más elegante -->
                        <div 
                            class="prose prose-lg prose-slate dark:prose-invert max-w-none
                            prose-headings:font-semibold prose-headings:tracking-tight prose-headings:text-gray-900 dark:prose-headings:text-white
                            prose-p:text-gray-600 dark:prose-p:text-gray-400 prose-p:leading-relaxed
                            prose-a:text-blue-600 hover:prose-a:text-blue-500 prose-a:no-underline hover:prose-a:underline transition-colors" 
                            v-html="terms" 
                        />
                    </div>
                </div>

                <!-- Pie de página sutil (Opcional, para regresar) -->
                <div class="mt-8 text-center">
                    <a href="/" class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                        &larr; Volver al inicio
                    </a>
                </div>

            </div>
        </div>
    </div>
</template>