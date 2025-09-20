<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PatternLock from '@/Components/PatternLock.vue';

const props = defineProps({
    serviceOrder: Object,
    customFieldDefinitions: Array,
    customers: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Órdenes de Servicio', url: route('service-orders.index') },
    { label: `Editar Orden #${props.serviceOrder.id}` }
]);

const form = useForm({
    _method: 'PUT',
    customer_name: props.serviceOrder.customer_name,
    customer_phone: props.serviceOrder.customer_phone,
    item_description: props.serviceOrder.item_description,
    reported_problems: props.serviceOrder.reported_problems,
    promised_at: props.serviceOrder.promised_at ? new Date(props.serviceOrder.promised_at) : null,
    technician_name: props.serviceOrder.technician_name,
    technician_diagnosis: props.serviceOrder.technician_diagnosis,
    final_total: props.serviceOrder.final_total,
    custom_fields: props.serviceOrder.custom_fields || {},
    initial_evidence_images: [],
    deleted_media_ids: [],
});

const existingImages = ref(props.serviceOrder.media.filter(m => m.collection_name === 'initial-service-order-evidence'));

const deleteExistingImage = (mediaId) => {
    form.deleted_media_ids.push(mediaId);
    existingImages.value = existingImages.value.filter(img => img.id !== mediaId);
};

const onSelectImages = (event) => {
    form.initial_evidence_images = [...form.initial_evidence_images, ...event.files];
};
const onRemoveImage = (event) => {
    form.initial_evidence_images = form.initial_evidence_images.filter(img => img.objectURL !== event.file.objectURL);
};


// Lógica para el AutoComplete de clientes
const filteredCustomers = ref();
const searchCustomer = (event) => {
    setTimeout(() => {
        if (!event.query.trim().length) {
            filteredCustomers.value = [...props.customers];
        } else {
            filteredCustomers.value = props.customers.filter((customer) => {
                return customer.name.toLowerCase().includes(event.query.toLowerCase());
            });
        }
    }, 250);
}
const onCustomerSelect = (event) => {
    form.customer_name = event.value.name;
    form.customer_phone = event.value.phone;
};

const submit = () => {
    form.post(route('service-orders.update', props.serviceOrder.id), {
        forceFormData: true, // Necesario para enviar archivos
    });
};
</script>

<template>

    <Head :title="`Editar Orden #${serviceOrder.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Editar Orden de Servicio</h1>
        </div>

        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto space-y-6">
            <!-- Información del Cliente y Equipo -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información Principal</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="customer_name" value="Nombre del Cliente *" />
                        <AutoComplete v-model="form.customer_name" :suggestions="filteredCustomers"
                            @complete="searchCustomer" field="name" @item-select="onCustomerSelect" inputClass="w-full"
                            class="w-full mt-1" inputId="customer_name">
                            <template #option="slotProps">
                                <div class="flex flex-col">
                                    <span>{{ slotProps.option.name }}</span>
                                    <small class="text-xs text-gray-500">{{ slotProps.option.phone }}</small>
                                </div>
                            </template>
                        </AutoComplete>
                        <InputError :message="form.errors.customer_name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="customer_phone" value="Teléfono del Cliente" />
                        <InputText id="customer_phone" v-model="form.customer_phone" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="item_description" value="Descripción del Equipo *" />
                        <InputText id="item_description" v-model="form.item_description" class="mt-1 w-full"
                            placeholder="Ej: iPhone 13 Pro, 256GB, Azul Sierra" />
                        <InputError :message="form.errors.item_description" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="reported_problems" value="Falla o Problema Reportado por el Cliente *" />
                        <Textarea id="reported_problems" v-model="form.reported_problems" rows="3"
                            class="mt-1 w-full" />
                        <InputError :message="form.errors.reported_problems" class="mt-2" />
                    </div>
                </div>
            </div>

            <!-- Campos Personalizados (DINÁMICOS) -->
            <div v-if="customFieldDefinitions.length > 0" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles Adicionales</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div v-for="field in customFieldDefinitions" :key="field.id">
                        <InputLabel :for="field.key" :value="field.name" />
                        <InputText v-if="field.type === 'text'" :id="field.key" v-model="form.custom_fields[field.key]"
                            class="mt-1 w-full" />
                        <InputNumber v-if="field.type === 'number'" :id="field.key"
                            v-model="form.custom_fields[field.key]" class="w-full mt-1" />
                        <Textarea v-if="field.type === 'textarea'" :id="field.key"
                            v-model="form.custom_fields[field.key]" rows="2" class="mt-1 w-full" />
                        <ToggleSwitch v-if="field.type === 'boolean'" :id="field.key"
                            v-model="form.custom_fields[field.key]" class="mt-1" />
                        <Select v-if="field.type === 'select'" :id="field.key" v-model="form.custom_fields[field.key]"
                            :options="field.options" class="mt-1 w-full" placeholder="Selecciona una opción" />
                        <PatternLock v-if="field.type === 'pattern'" :id="field.key"
                            v-model="form.custom_fields[field.key]" class="mt-1" />
                        <InputError :message="form.errors[`custom_fields.${field.key}`]" class="mt-2" />
                    </div>
                </div>
            </div>

            <!-- Evidencia Inicial -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia Fotográfica Inicial (Máx. 5)</h2>
                <div v-if="existingImages.length > 0" class="flex flex-wrap gap-4 mb-4">
                    <div v-for="img in existingImages" :key="img.id" class="relative">
                        <img :src="img.original_url" class="w-24 h-24 object-cover rounded-md border">
                        <Button @click="deleteExistingImage(img.id)" icon="pi pi-times" rounded text severity="danger"
                            class="!absolute -top-2 -right-2 bg-white/70 dark:bg-gray-800/70" />
                    </div>
                </div>
                <FileUpload name="initial_evidence_images[]" @select="onSelectImages" @remove="onRemoveImage"
                    :multiple="true" accept="image/*" :maxFileSize="2000000">
                    <template #empty>
                        <p>Arrastra y suelta para añadir más imágenes.</p>
                    </template>
                </FileUpload>
                <InputError :message="form.errors.initial_evidence_images" class="mt-2" />
            </div>

            <!-- Información Interna -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información Interna y Diagnóstico</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="promised_at" value="Fecha Promesa de Entrega" />
                        <Calendar id="promised_at" v-model="form.promised_at" showTime hourFormat="12"
                            class="w-full mt-1" />
                    </div>
                    <div>
                        <InputLabel for="technician_name" value="Técnico Asignado" />
                        <InputText id="technician_name" v-model="form.technician_name" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="technician_diagnosis" value="Diagnóstico del Técnico" />
                        <Textarea id="technician_diagnosis" v-model="form.technician_diagnosis" rows="3"
                            class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel for="final_total" value="Costo Total Final" />
                        <InputNumber id="final_total" v-model="form.final_total" mode="currency" currency="MXN"
                            locale="es-MX" class="w-full mt-1" />
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <Button type="submit" label="Actualizar Orden" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>