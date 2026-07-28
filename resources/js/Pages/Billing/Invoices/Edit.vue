<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceForm from './Partials/InvoiceForm.vue';

const props = defineProps({
    invoice: Object,
    customers: Array,
    fiscalProfiles: Array,
    hasFiscalProfiles: Boolean,
});

function handleSubmit({ form }) {
    form.put(route('billing.invoices.update', props.invoice.id));
}
</script>

<template>
    <AppLayout title="Editar prefactura">
        <Breadcrumb :home="{ icon: 'pi pi-home', url: route('dashboard') }" :model="[{ label: 'Facturación', url: route('billing.invoices.index') }, { label: 'Editar prefactura' }]" class="!bg-transparent !p-0 !mb-1" />

        <div class="flex items-center justify-between mt-2 mb-6">
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">Editar prefactura</h1>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1 m-0">
                    Folio {{ invoice.folio }} &middot; Modifica los datos y guarda los cambios
                </p>
            </div>
            <div class="flex gap-2">
                <Button label="Cancelar" severity="secondary" text class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" @click="$inertia.visit(route('billing.invoices.show', invoice.id))" />
            </div>
        </div>

        <InvoiceForm
            mode="edit"
            :invoice="invoice"
            :customers="customers"
            :fiscalProfiles="fiscalProfiles"
            :hasFiscalProfiles="hasFiscalProfiles"
            @submit="handleSubmit"
        />
    </AppLayout>
</template>
