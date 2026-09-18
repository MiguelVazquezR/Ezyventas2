<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceForm from './Partials/InvoiceForm.vue';

const props = defineProps({
    invoice: Object,
    customers: Array,
    fiscalProfiles: Array,
    hasFiscalProfiles: Boolean,
    ppdInvoices: Array,
    products: Array,
    services: Array,
});

// Includes the folio so several prefacturas can be told apart in the browser.
const pageTitle = computed(() => {
    const folio = [props.invoice.series, props.invoice.folio].filter(Boolean).join(' ');

    return folio ? `Editar prefactura ${folio}` : 'Editar prefactura';
});

function handleSubmit({ form }) {
    form.put(route('billing.invoices.update', props.invoice.id));
}
</script>

<template>
    <AppLayout :title="pageTitle">
        <Breadcrumb :home="{ icon: 'pi pi-home', url: route('dashboard') }" :model="[{ label: 'Lista de facturas', url: route('billing.invoices.index') }, { label: 'Editar prefactura' }]" class="!bg-transparent !p-0 !mb-1" />

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-2 mb-6">
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">Editar prefactura</h1>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1 m-0">
                    Folio {{ invoice.folio }} &middot; Modifica los datos y guarda los cambios
                </p>
            </div>
            <div class="flex gap-2 w-full sm:w-auto">
                <Button label="Cancelar" severity="secondary" text class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold !justify-center w-full sm:w-auto" @click="$inertia.visit(route('billing.invoices.show', invoice.id))" />
            </div>
        </div>

        <InvoiceForm
            mode="edit"
            :invoice="invoice"
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
