<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    customFieldDefinitions: Array,
    customers: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Órdenes de Servicio', url: route('service-orders.index') },
    { label: 'Crear Orden' }
]);

const form = useForm({
    customer_name: '',
    customer_phone: '',
    item_description: '',
    reported_problems: '',
    promised_at: null,
    technician_name: '',
    custom_fields: {},
});

// Lógica para el AutoComplete de clientes
const filteredCustomers = ref();
const searchCustomer = (event) => {
    setTimeout(() => {
        if (!event.query.trim().length) {
            filteredCustomers.value = [...props.customers];
        } else {
            filteredCustomers.value = props.customers.filter((customer) => {
                return customer.name.toLowerCase().startsWith(event.query.toLowerCase());
            });
        }
    }, 250);
}
const onCustomerSelect = (event) => {
    // Al seleccionar un cliente del objeto, llenamos los campos del formulario
    form.customer_name = event.value.name;
    form.customer_phone = event.value.phone;
};

// Inicializar el objeto de custom_fields en el formulario
props.customFieldDefinitions.forEach(field => {
    form.custom_fields[field.key] = null;
});

const submit = () => {
    form.post(route('service-orders.store'));
};
</script>

<template>

    <Head title="Crear Orden de Servicio" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar Nueva Orden de Servicio</h1>
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
                                <div>{{ slotProps.option.name }}</div>
                                <div class="text-xs text-gray-500">{{ slotProps.option.phone }}</div>
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
                        <InputError :message="form.errors[`custom_fields.${field.key}`]" class="mt-2" />
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <Button type="submit" label="Crear Orden" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>