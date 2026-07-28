<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReleaseNoteForm from './Partials/ReleaseNoteForm.vue';
</script>

<template>
    <AppLayout title="Crear novedad">
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="max-w-4xl mx-auto">
                <div class="mb-4">
                    <Link :href="route('admin.release-notes.index')" class="text-primary-600 hover:text-primary-800 flex items-center gap-2 font-medium transition-colors w-max">
                        <i class="pi pi-arrow-left"></i> Volver a novedades
                    </Link>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 md:p-8">
                    <div class="mb-8 border-b border-gray-100 dark:border-gray-700 pb-4">
                        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                            <i class="pi pi-megaphone text-blue-500"></i> Redactar nueva actualización
                        </h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Comparte las nuevas características o correcciones con tus suscriptores.</p>
                    </div>

                    <ReleaseNoteForm />
                </div>
            </div>
        </div>
    </AppLayout>
</template>