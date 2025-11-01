<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    pendingPayments: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Pagos pendientes' }
]);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>

<template>
    <AppLayout title="Pagos pendientes">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 mb-6" />

        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Pagos pendientes</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Revisa y aprueba las transferencias bancarias de las suscripciones.
                </p>
            </header>

            <Card>
                <template #content>
                    <DataTable :value="pendingPayments" responsiveLayout="scroll" size="small" stripedRows>
                        <Column field="subscription_version.subscription.commercial_name" header="Negocio"></Column>
                        
                        <Column header="Monto">
                            <template #body="{ data }">
                                {{ formatCurrency(data.amount) }}
                            </template>
                        </Column>

                        <Column header="Fecha de Solicitud">
                            <template #body="{ data }">
                                {{ formatDate(data.created_at) }}
                            </template>
                        </Column>

                        <Column header="Método">
                            <template #body="{ data }">
                                <Tag v-if="data.payment_method === 'transferencia'" value="Transferencia" severity="info" />
                            </template>
                        </Column>

                        <Column header="Acciones">
                            <template #body="{ data }">
                                <Link :href="route('admin.payments.show', data.id)">
                                    <Button label="Revisar" icon="pi pi-search" size="small" />
                                </Link>
                            </template>
                        </Column>

                        <template #empty>
                            <div class="text-center py-4 text-gray-500">
                                ¡Genial! No hay pagos pendientes por revisar.
                            </div>
                        </template>
                    </DataTable>
                </template>
            </Card>
        </div>
    </AppLayout>
</template>