<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    payment: Object,
    proofUrl: String, // URL temporal del comprobante
});

const confirm = useConfirm();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Pagos pendientes', url: route('admin.payments.index') },
    { label: 'Revisar pago' }
]);

// Formulario para aprobar
const approveForm = useForm({});

const approvePayment = () => {
    confirm.require({
        message: '¿Estás seguro de que quieres APROBAR este pago? Esta acción activará la suscripción del cliente.',
        header: 'Confirmar Aprobación',
        icon: 'pi pi-check-circle',
        acceptLabel: 'Sí, Aprobar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-success',
        accept: () => {
            approveForm.post(route('admin.payments.approve', props.payment.id), {
                preserveScroll: true
            });
        }
    });
};

// Formulario para rechazar
const rejectForm = useForm({
    rejection_reason: '',
});

const rejectPayment = () => {
    confirm.require({
        message: '¿Estás seguro de que quieres RECHAZAR este pago? El cliente será notificado.',
        header: 'Confirmar Rechazo',
        icon: 'pi pi-times-circle',
        acceptLabel: 'Sí, Rechazar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: () => {
            rejectForm.post(route('admin.payments.reject', props.payment.id), {
                preserveScroll: true
            });
        }
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};
</script>

<template>
    <AppLayout title="Revisar Pago">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 mb-6" />

        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Revisar pago</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Verifica los detalles y el comprobante antes de aprobar o rechazar.
                </p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Detalles -->
                <div class="lg:col-span-2 space-y-6">
                    <Card>
                        <template #title>Detalles del pago</template>
                        <template #content>
                            <ul class="space-y-3 text-gray-700 dark:text-gray-300">
                                <li class="flex justify-between">
                                    <span class="font-semibold">Negocio:</span>
                                    <span>{{ payment.subscription_version.subscription.commercial_name }}</span>
                                </li>
                                <li class="flex justify-between">
                                    <span class="font-semibold">Monto a pagar:</span>
                                    <span class="font-bold text-lg">{{ formatCurrency(payment.amount) }}</span>
                                </li>
                                <li class="flex justify-between">
                                    <span class="font-semibold">Método:</span>
                                    <span class="capitalize">{{ payment.payment_method }}</span>
                                </li>
                                <li class="flex justify-between">
                                    <span class="font-semibold">Fecha de solicitud:</span>
                                    <span>{{ formatDate(payment.created_at) }}</span>
                                </li>
                            </ul>
                        </template>
                    </Card>

                    <Card>
                        <template #title>Conceptos del plan</template>
                        <template #content>
                            <DataTable :value="payment.subscription_version.items" size="small">
                                <Column field="name" header="Concepto"></Column>
                                <Column field="quantity" header="Cantidad/Límite"></Column>
                                <Column field="billing_period" header="Periodo">
                                    <template #body="{ data }">
                                        <span class="capitalize">{{ data.billing_period }}</span>
                                    </template>
                                </Column>
                                <Column header="Precio Unitario">
                                    <template #body="{ data }">
                                        {{ formatCurrency(data.unit_price) }}
                                    </template>
                                </Column>
                            </DataTable>
                        </template>
                    </Card>
                </div>

                <!-- Columna de Acciones y Comprobante -->
                <div class="lg:col-span-1 space-y-6">
                    <Card class="sticky top-24">
                        <template #title>Comprobante de pago</template>
                        <template #content>
                            <div v-if="proofUrl" class="space-y-4">
                                <!-- Vista previa para imágenes -->
                                <Image :src="proofUrl" alt="Comprobante" preview />
                                
                                <!-- Enlace para PDF u otros -->
                                <a :href="proofUrl" target="_blank" rel="noopener noreferrer">
                                    <Button label="Ver comprobante (Nueva Pestaña)" icon="pi pi-external-link" outlined class="w-full" />
                                </a>
                            </div>
                            <div v-else>
                                <p class="text-center text-gray-500">No se adjuntó ningún comprobante.</p>
                            </div>
                        </template>
                    </Card>

                    <Card>
                        <template #title>Acciones</template>
                        <template #content>
                            <div class="space-y-4">
                                <Button @click="approvePayment" 
                                        label="Aprobar Pago" 
                                        icon="pi pi-check" 
                                        severity="success" 
                                        class="w-full"
                                        :loading="approveForm.processing" />
                                
                                <Divider />

                                <div class="space-y-2">
                                    <label for="rejection_reason" class="font-semibold">Motivo del rechazo (requerido)</label>
                                    <Textarea id="rejection_reason" 
                                              v-model="rejectForm.rejection_reason" 
                                              rows="3" 
                                              class="w-full"
                                              :invalid="!!rejectForm.errors.rejection_reason" />
                                    <InputError :message="rejectForm.errors.rejection_reason" />
                                </div>
                                
                                <Button @click="rejectPayment" 
                                        label="Rechazar pago" 
                                        icon="pi pi-times" 
                                        severity="danger" 
                                        outlined 
                                        class="w-full"
                                        :disabled="!rejectForm.rejection_reason"
                                        :loading="rejectForm.processing" />
                            </div>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>