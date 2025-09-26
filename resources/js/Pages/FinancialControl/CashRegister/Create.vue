<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Control Financiero', url: route('financial-control.index') },
    { label: 'Cajas', url: route('cash-registers.index') },
    { label: 'Crear' }
]);

const form = useForm({
    name: '',
    is_active: true,
});

const submit = () => {
    form.post(route('cash-registers.store'));
};
</script>

<template>
    <Head title="Crear Caja Registradora" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4"><h1 class="text-2xl font-bold">Crear Nueva Caja Registradora</h1></div>

        <form @submit.prevent="submit" class="mt-6 max-w-lg mx-auto bg-white p-6 rounded-lg shadow-md">
            <div class="space-y-6">
                 <div>
                    <InputLabel for="name" value="Nombre de la Caja *" />
                    <InputText id="name" v-model="form.name" class="mt-1 w-full" placeholder="Ej: Caja 1, Mostrador" />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>
                <div class="flex items-center gap-4">
                     <ToggleSwitch v-model="form.is_active" inputId="is_active" />
                     <InputLabel for="is_active" value="Caja Activa" />
                </div>
            </div>
             <div class="flex justify-end mt-6">
                <Button type="submit" label="Crear Caja" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>