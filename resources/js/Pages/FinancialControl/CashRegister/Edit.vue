<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    cashRegister: Object,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Cajas', url: route('cash-registers.index') },
    { label: `Editar: ${props.cashRegister.name}` }
]);

const form = useForm({
    _method: 'PUT',
    name: props.cashRegister.name,
    is_active: props.cashRegister.is_active,
});

const submit = () => {
    form.put(route('cash-registers.update', props.cashRegister.id));
};
</script>

<template>
    <Head :title="`Editar Caja: ${cashRegister.name}`" />
    <AppLayout>
         <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4"><h1 class="text-2xl font-bold">Editar Caja Registradora</h1></div>

        <form @submit.prevent="submit" class="mt-6 max-w-lg mx-auto bg-white p-6 rounded-lg shadow-md">
            <div class="space-y-6">
                 <div>
                    <InputLabel for="name" value="Nombre de la Caja *" />
                    <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>
                <div class="flex items-center gap-4">
                     <ToggleSwitch v-model="form.is_active" inputId="is_active" />
                     <InputLabel for="is_active" value="Caja Activa" />
                </div>
            </div>
             <div class="flex justify-end mt-6">
                <Button type="submit" label="Actualizar Caja" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>