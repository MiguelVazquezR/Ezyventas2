<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    subscription: Object,
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};

const getStatusTagSeverity = (status) => {
    const map = { activo: 'success', expirado: 'warning', suspendido: 'danger' };
    return map[status] || 'info';
};
</script>

<template>
    <Head title="Mi Suscripción" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Mi Suscripción</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Aquí puedes ver todos los detalles de tu plan y tu historial de pagos.</p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Información General -->
                <div class="lg:col-span-1 space-y-6">
                    <Card>
                        <template #title>Información General</template>
                        <template #content>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Nombre Comercial:</span>
                                    <span class="font-semibold">{{ subscription.commercial_name }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Razón Social:</span>
                                    <span class="font-semibold">{{ subscription.business_name }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Estatus:</span>
                                    <Tag :value="subscription.status" :severity="getStatusTagSeverity(subscription.status)" class="capitalize" />
                                </div>
                            </div>
                        </template>
                    </Card>
                     <Card>
                        <template #title>Contacto</template>
                        <template #content>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Email:</span>
                                    <span class="font-semibold">{{ subscription.contact_email }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Teléfono:</span>
                                    <span class="font-semibold">{{ subscription.contact_phone }}</span>
                                </div>
                            </div>
                        </template>
                    </Card>
                </div>

                <!-- Columna de Sucursales e Historial -->
                <div class="lg:col-span-2 space-y-6">
                    <Fieldset legend="Sucursales" :toggleable="true">
                        <DataTable :value="subscription.branches" stripedRows size="small">
                            <Column field="name" header="Nombre"></Column>
                            <Column field="contact_email" header="Email"></Column>
                            <Column field="contact_phone" header="Teléfono"></Column>
                            <Column header="Principal">
                                <template #body="slotProps">
                                    <i v-if="slotProps.data.is_main" class="pi pi-check-circle text-green-500"></i>
                                </template>
                            </Column>
                        </DataTable>
                    </Fieldset>

                    <Fieldset legend="Historial de Versiones y Pagos" :toggleable="true">
                         <Accordion :activeIndex="0">
                            <AccordionTab v-for="version in subscription.versions" :key="version.id" :header="`Periodo: ${formatDate(version.start_date)} - ${formatDate(version.end_date)}`">
                                <div class="p-4">
                                    <h4 class="font-bold mb-2">Conceptos del Plan</h4>
                                    <DataTable :value="version.items" size="small" class="mb-4">
                                        <Column field="name" header="Concepto"></Column>
                                        <Column field="quantity" header="Cantidad"></Column>
                                        <Column field="unit_price" header="Precio Unit.">
                                            <template #body="slotProps">{{ formatCurrency(slotProps.data.unit_price) }}</template>
                                        </Column>
                                    </DataTable>

                                    <h4 class="font-bold mb-2">Pagos Realizados</h4>
                                     <DataTable :value="version.payments" size="small">
                                        <Column field="created_at" header="Fecha de Pago">
                                             <template #body="slotProps">{{ formatDate(slotProps.data.created_at) }}</template>
                                        </Column>
                                        <Column field="payment_method" header="Método" class="capitalize"></Column>
                                        <Column field="amount" header="Monto">
                                            <template #body="slotProps">{{ formatCurrency(slotProps.data.amount) }}</template>
                                        </Column>
                                    </DataTable>
                                </div>
                            </AccordionTab>
                        </Accordion>
                    </Fieldset>
                </div>
            </div>
        </div>
    </AppLayout>
</template>