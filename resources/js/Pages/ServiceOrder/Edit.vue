<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';

// Lógica de navegación
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';

// Partials refactorizados
import MainInformation from './Partials/Form/MainInformation.vue';
import OrderItems from './Partials/Form/OrderItems.vue';
import AdditionalDetails from './Partials/Form/AdditionalDetails.vue';

const props = defineProps({
    serviceOrder: Object,
    customFieldDefinitions: Array,
    customers: Array,
    products: Array,
    services: Array,
    errors: Object,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Órdenes de Servicio', url: route('service-orders.index') },
    { label: `Editar Orden #${props.serviceOrder.folio || props.serviceOrder.id}` }
]);

const form = useForm({
    _method: 'PUT',
    customer_id: props.serviceOrder.customer_id,
    customer_name: props.serviceOrder.customer_name,
    customer_phone: props.serviceOrder.customer_phone,
    customer_email: props.serviceOrder.customer_email,
    create_customer: false,
    credit_limit: 0,
    item_description: props.serviceOrder.item_description,
    reported_problems: props.serviceOrder.reported_problems,
    promised_at: props.serviceOrder.promised_at ? new Date(props.serviceOrder.promised_at) : null,
    items: props.serviceOrder.items || [],
    subtotal: props.serviceOrder.subtotal,
    discount_type: props.serviceOrder.discount_type || 'fixed',
    discount_value: props.serviceOrder.discount_value || 0,
    discount_amount: props.serviceOrder.discount_amount || 0,
    final_total: props.serviceOrder.final_total,
    custom_fields: props.serviceOrder.custom_fields || {},
    initial_evidence_images: [],
    deleted_media_ids: [],
    assign_technician: !!props.serviceOrder.technician_name,
    technician_name: props.serviceOrder.technician_name,
    technician_commission_type: props.serviceOrder.technician_commission_type || 'percentage',
    technician_commission_value: props.serviceOrder.technician_commission_value,
});

// --- Lógica del menú lateral ---
const formSections = [
    { id: 'general', label: 'Información principal' },
    { id: 'items', label: 'Refacciones y mano de obra' },
    { id: 'additional', label: 'Técnico y detalles adicionales' },
    { id: 'evidence', label: 'Evidencia inicial' }
];
const { activeSection, scrollTo } = useScrollspy(formSections.map(s => s.id));

// Evidencias (ahora sí cargarán tras corregir el controlador)
const existingMedia = ref((props.serviceOrder.media || []).filter(m => m.collection_name === 'initial-service-order-evidence'));

const removeExistingImage = (mediaId) => {
    form.deleted_media_ids.push(mediaId);
    existingMedia.value = existingMedia.value.filter(m => m.id !== mediaId);
};
const onSelectImages = (event) => form.initial_evidence_images = event.files;
const onRemoveImage = (event) => form.initial_evidence_images = form.initial_evidence_images.filter(img => img.objectURL !== event.file.objectURL);

const submit = () => {
    form.post(route('service-orders.update', props.serviceOrder.id));
};
</script>

<template>
    <AppLayout :title="`Editar orden de servicio #${serviceOrder.folio || serviceOrder.id}`">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Editar orden de servicio #{{ serviceOrder.folio || serviceOrder.id }}</h1>
        </div>

        <div class="mt-6 flex flex-col md:flex-row gap-6 items-start relative">
            
            <FormNavigationSidebar :sections="formSections" :activeSection="activeSection" @scrollTo="scrollTo" />

            <!-- Contenedor del Formulario -->
            <div class="w-full md:w-3/4">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <div id="general">
                        <MainInformation :form="form" :customers="props.customers" />
                    </div>
                    
                    <div id="items">
                        <OrderItems :form="form" :products="props.products" :services="props.services" />
                    </div>

                    <div id="additional">
                        <AdditionalDetails :form="form" :customFieldDefinitions="props.customFieldDefinitions" />
                    </div>

                    <!-- Evidencia Fotográfica Inicial -->
                    <div id="evidence" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <div class="flex items-center justify-between border-b pb-3 mb-4">
                            <h2 class="text-lg font-semibold">Evidencia fotográfica inicial</h2>
                        </div>
                        
                        <div v-if="existingMedia.length > 0" class="mb-6">
                            <p class="text-sm text-gray-500 mb-3">Imágenes guardadas actualmente:</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                <div v-for="media in existingMedia" :key="media.id" class="relative group rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 shadow-sm">
                                    <img :src="media.original_url" alt="Evidencia existente" class="object-cover w-full h-32 transition-transform duration-300 group-hover:scale-105">
                                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                                        <Button @click="removeExistingImage(media.id)" icon="pi pi-trash" rounded severity="danger" size="small" v-tooltip.top="'Eliminar imagen'" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-sm text-gray-500 mb-3">Añadir nuevas imágenes (Máx. total 5):</p>
                            <FileUpload name="initial_evidence_images[]" @select="onSelectImages" @remove="onRemoveImage"
                                :multiple="true" :show-upload-button="false" accept="image/*" :maxFileSize="2000000">
                                <template #empty>
                                    <p>Arrastra y suelta imágenes aquí para añadirlas a la evidencia.</p>
                                </template>
                            </FileUpload>
                        </div>

                        <InputError :message="form.errors.initial_evidence_images" class="mt-2" />
                        <InputError :message="form.errors.deleted_media_ids" class="mt-2" />
                    </div>

                    <div class="flex justify-start sticky bottom-4 z-20">
                        <Button type="submit" label="Guardar cambios" icon="pi pi-check" :loading="form.processing" severity="warning" size="large" class="shadow-xl" />
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>