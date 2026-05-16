<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PlanItemForm from './Partials/PlanItemForm.vue';

const form = useForm({
    key: '',
    type: 'module',
    name: '',
    description: '',
    monthly_price: 0.00,
    is_active: true,
    meta: {
        icon: 'pi pi-box',
        quantity: null
    }
});

const submit = () => {
    // Si el tipo es módulo, limpiamos la cantidad. Si es límite, limpiamos el ícono.
    if (form.type === 'module') {
        form.meta.quantity = null;
    } else {
        form.meta.icon = null;
    }

    form.post(route('admin.plan-items.store'));
};
</script>

<template>
    <AppLayout title="Crear ítem de plan">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título (Tesla UI) -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Registrar nuevo ítem</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Añadir capacidad o módulo al sistema SaaS
                    </p>
                </div>

                <!-- Formulario -->
                <PlanItemForm :form="form" :isEdit="false" @submit="submit" />

            </div>
        </div>
    </AppLayout>
</template>