<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    customer: Object,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Clientes', url: route('customers.index') },
    { label: `Editar: ${props.customer.name}` }
]);

// Inicializamos el formulario con los datos existentes
// Si 'address' viene nulo de la BD, inicializamos con campos vacíos para evitar errores
const form = useForm({
    _method: 'PUT',
    name: props.customer.name,
    company_name: props.customer.company_name,
    email: props.customer.email,
    phone: props.customer.phone,
    tax_id: props.customer.tax_id,
    credit_limit: props.customer.credit_limit,
    address: props.customer.address || {
        street: '',
        exterior_number: '',
        interior_number: '',
        neighborhood: '',
        zip_code: '',
        city: '',
        state: '',
        cross_streets: '',
        notes: ''
    }
});

const submit = () => {
    form.put(route('customers.update', props.customer.id));
};
</script>

<template>
    <AppLayout :title="`Editar cliente: ${customer.name}`">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Editar cliente</h1>
        </div>
        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto bg-white p-6 rounded-lg shadow-md">
            
            <!-- SECCIÓN 1: DATOS GENERALES -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-700 border-b pb-2 mb-4">Datos Generales</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="name" value="Nombre del cliente *" />
                        <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="company_name" value="Nombre de la empresa" />
                        <InputText id="company_name" v-model="form.company_name" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel for="phone" value="Teléfono" />
                        <InputText id="phone" v-model="form.phone" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Correo electrónico" />
                        <InputText id="email" v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="tax_id" value="RFC" />
                        <InputText id="tax_id" v-model="form.tax_id" class="mt-1 w-full" />
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 2: DIRECCIÓN -->
            <div class="mb-6">
                <div class="flex items-center justify-between border-b pb-2 mb-4">
                    <h2 class="text-lg font-semibold text-gray-700">Domicilio / Dirección</h2>
                    <small class="text-gray-500">Útil para envíos y localización</small>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <div class="md:col-span-4">
                        <InputLabel for="street" value="Calle" />
                        <InputText id="street" v-model="form.address.street" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="ext_num" value="No. Exterior" />
                        <InputText id="ext_num" v-model="form.address.exterior_number" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="int_num" value="No. Interior" />
                        <InputText id="int_num" v-model="form.address.interior_number" class="mt-1 w-full" />
                    </div>

                    <div class="md:col-span-2">
                        <InputLabel for="neighborhood" value="Colonia" />
                        <InputText id="neighborhood" v-model="form.address.neighborhood" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="city" value="Ciudad / Municipio" />
                        <InputText id="city" v-model="form.address.city" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="state" value="Estado" />
                        <InputText id="state" v-model="form.address.state" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="zip_code" value="C.P." />
                        <InputText id="zip_code" v-model="form.address.zip_code" class="mt-1 w-full" />
                    </div>
                    
                    <div class="md:col-span-6">
                        <InputLabel for="cross_streets" value="Entre calles y referencias" />
                        <Textarea id="cross_streets" v-model="form.address.cross_streets" rows="2" class="mt-1 w-full" placeholder="Entre Calle A y Calle B, fachada color..." />
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 3: CONFIGURACIÓN FINANCIERA -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-700 border-b pb-2 mb-4">Configuración Financiera</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="credit_limit" value="Límite de crédito" />
                        <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN"
                            locale="es-MX" class="w-full mt-1" />
                        <small class="text-gray-500">Monto máximo que el cliente puede deber.</small>
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t">
                <Button type="button" label="Cancelar" severity="secondary" text class="mr-2" @click="() => router.visit(route('customers.index'))" />
                <Button type="submit" label="Guardar Cambios" :loading="form.processing" severity="warning" icon="pi pi-save" />
            </div>
        </form>
    </AppLayout>
</template>