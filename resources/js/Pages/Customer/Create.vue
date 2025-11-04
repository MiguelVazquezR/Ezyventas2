<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
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
    initial_balance: 0, // <-- CAMBIO: Añadido Saldo Inicial
});

const submit = () => {
    form.post(route('customers.store'));
};
</script>

<template>
    <AppLayout title="Crear cliente">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar nuevo cliente</h1>
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
                
                <!-- CAMBIO: Campo de Límite de Crédito movido a 1 columna -->
                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="credit_limit" value="Límite de crédito" />
                        <InputNumber id="credit_limit" vB-model="form.credit_limit" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                    </div>

                    <!-- CAMBIO: Nuevo Campo de Saldo Inicial -->
                    <div>
                        <InputLabel for="initial_balance" value="Saldo inicial" />
                        <InputNumber id="initial_balance" v-model="form.initial_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                        <small class="text-gray-500">Valor negativo para deudas, positivo para saldo a favor.</small>
                        <InputError :message="form.errors.initial_balance" class="mt-2" />
                    </div>
                </div>

            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar cliente" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>