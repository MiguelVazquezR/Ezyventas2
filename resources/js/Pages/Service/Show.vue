<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DiffViewer from '@/Components/DiffViewer.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    service: Object,
    activities: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Catálogo de Servicios', url: route('services.index') },
    { label: props.service.name }
]);

const deleteService = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el servicio "${props.service.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('services.destroy', props.service.id));
        }
    });
};

const actionItems = ref([
    { label: 'Crear nuevo', icon: 'pi pi-plus', command: () => router.get(route('services.create')), visible: hasPermission('services.catalog.create') },
    { label: 'Editar servicio', icon: 'pi pi-pencil', command: () => router.get(route('services.edit', props.service.id)), visible: hasPermission('services.catalog.edit') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteService, visible: hasPermission('services.catalog.delete') },
]);

const mainImage = computed(() =>
    props.service.media && props.service.media.length > 0 ? props.service.media[0].original_url : null
);

</script>

<template>

    <Head :title="`Servicio: ${service.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ service.name }}</h1>
            <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0">
            </SplitButton>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal: Detalles -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                        <!-- Imagen -->
                        <div class="md:col-span-2">
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Imagen del Servicio</h2>
                            <img v-if="mainImage" :src="mainImage" :alt="service.name"
                                class="w-full h-64 object-cover rounded-lg border">
                            <div v-else
                                class="w-full h-64 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center text-gray-400">
                                <i class="pi pi-image text-5xl"></i>
                            </div>
                        </div>

                        <!-- Información -->
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información General</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span class="text-gray-500">Categoría</span> <span
                                        class="font-medium">{{ service.category?.name || 'N/A' }}</span></li>
                                <li class="flex justify-between"><span class="text-gray-500">Duración Estimada</span>
                                    <span class="font-medium">{{ service.duration_estimate || 'N/A' }}</span></li>
                                <li class="flex justify-between">
                                    <span class="text-gray-500">Visible en Tienda</span>
                                    <Tag :value="service.show_online ? 'Sí' : 'No'"
                                        :severity="service.show_online ? 'success' : 'secondary'"></Tag>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Precio</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between items-baseline">
                                    <span class="text-gray-500">Precio Base</span>
                                    <span class="font-semibold text-2xl">{{ new Intl.NumberFormat('es-MX', {
                                        style:
                                            'currency', currency: 'MXN' }).format(service.base_price) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div v-if="service.description" class="mt-6 pt-4 border-t">
                        <h3 class="font-semibold mb-2">Descripción</h3>
                        <div class="prose prose-sm dark:prose-invert max-w-none" v-html="service.description"></div>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Historial -->
            <div class="lg:col-span-1">
                <!-- <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-6">Historial de Actividad</h2>
                    <div v-if="activities && activities.length > 0" class="relative max-h-[300px] overflow-y-auto pr-2">
                        <div class="relative pl-6">
                            <div class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                            </div>

                            <div class="space-y-8">
                                <div v-for="activity in activities" :key="activity.id" class="relative">
                                    <div class="absolute left-0 top-1.5 -translate-x-1/2">
                                        <span
                                            class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                                            :class="{
                                                'bg-blue-500': activity.event === 'created',
                                                'bg-orange-500': activity.event === 'updated',
                                                'bg-red-500': activity.event === 'deleted',
                                            }">
                                            <i :class="{
                                                'pi pi-plus': activity.event === 'created',
                                                'pi pi-pencil': activity.event === 'updated',
                                                'pi pi-trash': activity.event === 'deleted',
                                            }"></i>
                                        </span>
                                    </div>

                                    <div class="ml-10">
                                        <h3 class="font-semibold text-gray-800 dark:text-gray-200 text-lg m-0">{{
                                            activity.description }}</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Por {{ activity.causer }} -
                                            {{
                                                activity.timestamp }}</p>

                                        <div v-if="activity.event === 'updated' && Object.keys(activity.changes.after).length > 0"
                                            class="mt-3 text-sm space-y-2">
                                            <div v-for="(value, key) in activity.changes.after" :key="key">
                                                <p class="font-medium text-gray-700 dark:text-gray-300">{{ key }}</p>
                                                <div v-if="key === 'Descripción'">
                                                    <DiffViewer :oldValue="activity.changes.before[key]"
                                                        :newValue="value" />
                                                </div>
                                                <div v-else class="flex items-center gap-2 text-xs">
                                                    <span
                                                        class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 px-2 py-0.5 rounded line-through">{{
                                                            activity.changes.before[key] || 'Vacío' }}</span>
                                                    <i class="pi pi-arrow-right text-gray-400"></i>
                                                    <span
                                                        class="bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 px-2 py-0.5 rounded font-medium">{{
                                                            value }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-500 py-8"> No hay actividades registradas. </div>
                </div> -->
            </div>
        </div>
    </AppLayout>
</template>