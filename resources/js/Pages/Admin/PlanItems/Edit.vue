<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PlanItemForm from './Partials/PlanItemForm.vue';

const props = defineProps({
    planItem: {
        type: Object,
        required: true
    }
});

// Inicializamos el formulario asegurando que la propiedad 'meta' exista
const form = useForm({
    key: props.planItem.key,
    type: props.planItem.type,
    name: props.planItem.name,
    description: props.planItem.description || '',
    monthly_price: Number(props.planItem.monthly_price),
    is_active: props.planItem.is_active,
    meta: {
        icon: props.planItem.meta?.icon || 'pi pi-box',
        quantity: props.planItem.meta?.quantity || null
    }
});

const submit = () => {
    // Si el tipo es módulo, limpiamos la cantidad. Si es límite, limpiamos el ícono.
    if (form.type === 'module') {
        form.meta.quantity = null;
    } else {
        form.meta.icon = null;
    }

    form.put(route('admin.plan-items.update', props.planItem.id));
};
</script>

<template>
    <AppLayout title="Editar ítem de plan">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título (Tesla UI) -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Editar ítem</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.8)] animate-pulse"></span>
                        Modificando configuración del ítem: <span class="text-gray-300 font-mono">{{ planItem.key }}</span>
                    </p>
                </div>

                <!-- Formulario -->
                <PlanItemForm :form="form" :isEdit="true" @submit="submit" />

            </div>
        </div>
    </AppLayout>
</template>