<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Clientes', url: route('customers.index') },
    { label: 'Crear Cliente' }
]);

const form = useForm({
    name: '',
    company_name: '',
    email: '',
    phone: '',
    tax_id: '',
    credit_limit: 0,
});

const submit = () => {
    form.post(route('customers.store'));
};
</script>

<template>
    <Head title="Crear Cliente" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar Nuevo Cliente</h1>
        </div>
        <form @submit.prevent="submit" class="mt-6 max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <InputLabel for="name" value="Nombre del Cliente *" />
                    <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="company_name" value="Nombre de la Empresa" />
                    <InputText id="company_name" v-model="form.company_name" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel for="phone" value="Teléfono" />
                    <InputText id="phone" v-model="form.phone" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel for="email" value="Correo Electrónico" />
                    <InputText id="email" v-model="form.email" type="email" class="mt-1 w-full" />
                    <InputError :message="form.errors.email" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="tax_id" value="RFC" />
                    <InputText id="tax_id" v-model="form.tax_id" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel for="credit_limit" value="Límite de Crédito" />
                    <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                </div>
            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar Cliente" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>