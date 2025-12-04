<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';
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
    { label: 'Catálogo de servicios', url: route('services.index') },
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
    <AppLayout :title="`Servicio: ${service.name}`">
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
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Imagen del servicio</h2>
                            <img v-if="mainImage" :src="mainImage" :alt="service.name"
                                class="w-full h-64 object-cover rounded-lg border">
                            <div v-else
                                class="w-full h-64 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center text-gray-400">
                                <i class="pi pi-image text-5xl"></i>
                            </div>
                        </div>

                        <!-- Información -->
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información general</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span class="text-gray-500">Categoría</span> <span
                                        class="font-medium">{{ service.category?.name || 'N/A' }}</span></li>
                                <li class="flex justify-between"><span class="text-gray-500">Duración estimada</span>
                                    <span class="font-medium">{{ service.duration_estimate || 'N/A' }}</span></li>
                                <!-- <li class="flex justify-between">
                                    <span class="text-gray-500">Visible en Tienda</span>
                                    <Tag :value="service.show_online ? 'Sí' : 'No'"
                                        :severity="service.show_online ? 'success' : 'secondary'"></Tag>
                                </li> -->
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
                <ActivityHistory :activities="activities" title="Historial de actividad" />
            </div>
        </div>
    </AppLayout>
</template>