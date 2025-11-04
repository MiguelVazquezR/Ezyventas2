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

const form = useForm({
    _method: 'PUT',
    name: props.customer.name,
    company_name: props.customer.company_name,
    email: props.customer.email,
    phone: props.customer.phone,
    tax_id: props.customer.tax_id,
    credit_limit: props.customer.credit_limit,
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
        <form @submit.prevent="submit" class="mt-6 max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-md">
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
                <div>
                    <InputLabel for="credit_limit" value="Límite de crédito" />
                    <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN"
                        locale="es-MX" class="w-full mt-1" />
                </div>
            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar Cliente" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>