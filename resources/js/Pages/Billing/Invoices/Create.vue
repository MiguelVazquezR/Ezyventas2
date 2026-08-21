<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceForm from './Partials/InvoiceForm.vue';

const props = defineProps({
    customers: Array,
    fiscalProfiles: Array,
    hasFiscalProfiles: Boolean,
    ppdInvoices: Array,
    products: Array,
    services: Array,
});

function handleSubmit({ form }) {
    form.post(route('billing.invoices.store'));
}
</script>

<template>
    <AppLayout title="Nueva factura">
        <Breadcrumb :home="{ icon: 'pi pi-home', url: route('dashboard') }" :model="[{ label: 'Lista de facturas', url: route('billing.invoices.index') }, { label: 'Nueva factura' }]" class="!bg-transparent !p-0 !mb-1" />

        <div class="flex items-center justify-between mt-2 mb-6">
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">Nueva factura</h1>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1 m-0">Completa los datos y conceptos para generar el CFDI 4.0</p>
            </div>
            <div class="flex gap-2">
                <Button label="Cancelar" severity="secondary" text class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" @click="$inertia.visit(route('billing.invoices.index'))" />
            </div>
        </div>

        <InvoiceForm
            mode="create"
            :customers="customers"
            :fiscalProfiles="fiscalProfiles"
            :hasFiscalProfiles="hasFiscalProfiles"
            :ppdInvoices="ppdInvoices"
            :products="products"
            :services="services"
            @submit="handleSubmit"
        />
    </AppLayout>
</template>
