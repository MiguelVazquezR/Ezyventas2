<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    // --- AÑADIDO: Props para manejar los límites ---
    cashRegisterLimit: Number,
    cashRegisterUsage: Number,
});

// --- AÑADIDO: Lógica para verificar si se alcanzó el límite ---
const limitReached = computed(() => {
    if (props.cashRegisterLimit === -1) return false;
    return props.cashRegisterUsage >= props.cashRegisterLimit;
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
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
        <!-- AÑADIDO: Contenido condicional basado en el límite -->
        <div v-if="limitReached" class="p-4 md:p-6 lg:p-8">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl mx-auto text-center">
                 <i class="pi pi-exclamation-triangle !text-6xl text-amber-500 mb-4"></i>
                 <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Límite de Cajas Alcanzado</h1>
                 <p class="text-gray-600 dark:text-gray-300 mb-6">
                     Has alcanzado el límite de <strong>{{ cashRegisterLimit }} cajas registradoras</strong> permitido por tu plan actual. Para agregar más, por favor mejora tu plan.
                 </p>
                 <div class="flex justify-center items-center gap-4">
                     <Link :href="route('cash-registers.index')">
                         <Button label="Volver a Cajas" severity="secondary" outlined />
                     </Link>
                     <a :href="route('subscription.manage')" target="_blank" rel="noopener noreferrer">
                          <Button label="Mejorar Mi Plan" icon="pi pi-arrow-up" />
                     </a>
                 </div>
            </div>
        </div>

        <!-- Formulario de creación original -->
        <div v-else class="p-4 md:p-6 lg:p-8">
            <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
            <div class="mt-4"><h1 class="text-2xl font-bold">Crear nueva caja Registradora</h1></div>

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
        </div>
    </AppLayout>
</template>