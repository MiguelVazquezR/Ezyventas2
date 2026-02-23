<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    subscription: Object,
    fiscalDocumentUrl: String
});

const isInvoiceModalVisible = ref(false);
const paymentToRequest = ref(null);

const confirmRequestInvoice = (paymentId) => {
    paymentToRequest.value = paymentId;
    isInvoiceModalVisible.value = true;
};

const requestInvoice = () => {
    if (paymentToRequest.value) {
        router.post(route('subscription.invoice.request', paymentToRequest.value), {}, {
            preserveScroll: true,
            onSuccess: () => {
                isInvoiceModalVisible.value = false;
                paymentToRequest.value = null;
            }
        });
    }
};

const formatDate = (dateString) => new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const getInvoiceStatusTag = (status) => {
    return {
        'no_solicitada': { text: 'No Solicitada', severity: 'secondary' },
        'solicitada': { text: 'Solicitada', severity: 'info' },
        'generada': { text: 'Generada', severity: 'success' },
    }[status] || { text: status, severity: 'secondary' };
};

const getPaymentStatusTag = (status) => {
    return {
        'pending': { text: 'Pendiente', severity: 'warn' },
        'approved': { text: 'Aprobado', severity: 'success' },
        'rejected': { text: 'Rechazado', severity: 'danger' },
    }[status] || { text: status, severity: 'secondary' };
};
</script>

<template>
    <Card>
        <template #title>Historial de versiones y pagos</template>
        <template #content>
            <Accordion>
                <AccordionPanel v-for="(version, index) in subscription.versions" :key="version.id"
                    :value="index">
                    <AccordionHeader>
                        Periodo: {{ formatDate(version.start_date) + ' - ' +
                            formatDate(version.end_date) }}
                    </AccordionHeader>
                    <AccordionContent>
                        <div class="p-4">
                            <h5 class="font-bold mb-2">Conceptos del plan</h5>
                            <DataTable :value="version.processed_items" size="small" class="mb-6">
                                <Column field="name" header="Concepto"></Column>
                                <Column header="Cantidad">
                                    <template #body="{ data }">
                                        <span v-if="data.status === 'upgraded'">
                                            {{ data.previous_quantity }} &rarr; <strong>{{ data.quantity }}</strong>
                                        </span>
                                            <span v-else-if="data.status === 'downgraded'">
                                            {{ data.previous_quantity }} &rarr; <strong>{{ data.quantity }}</strong>
                                        </span>
                                        <span v-else>
                                            {{ data.quantity }}
                                        </span>
                                    </template>
                                </Column>
                                <Column header="Estado">
                                    <template #body="{ data }">
                                        <Tag v-if="data.status === 'new'" value="Nuevo" severity="success" />
                                        <Tag v-if="data.status === 'upgraded'" value="Mejora" severity="info" />
                                        <Tag v-if="data.status === 'unchanged'" value="Sin cambio" severity="secondary" />
                                        <Tag v-if="data.status === 'downgraded'" value="Reducción" severity="warning" />
                                    </template>
                                </Column>
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
                            
                            <h5 class="font-bold mb-2">Pagos realizados</h5>
                            <DataTable :value="version.payments" size="small">
                                <Column field="created_at" header="Fecha de Pago">
                                    <template #body="slotProps">{{ formatDate(slotProps.data.created_at)
                                    }}</template>
                                </Column>
                                <Column field="payment_method" header="Método" class="capitalize">
                                </Column>
                                <Column field="amount" header="Monto">
                                    <template #body="slotProps">{{ formatCurrency(slotProps.data.amount)
                                    }}</template>
                                </Column>
                                <Column field="status" header="Estado">
                                    <template #body="{ data }">
                                        <Tag :value="getPaymentStatusTag(data.status).text"
                                                :severity="getPaymentStatusTag(data.status).severity"
                                                class="capitalize" />
                                    </template>
                                </Column>
                                <Column field="invoice_status" header="Factura">
                                    <template #body="{ data }">
                                        <div v-if="data.status === 'approved' && data.invoice_status === 'no_solicitada'">
                                            <Button @click="confirmRequestInvoice(data.id)"
                                                label="Solicitar" size="small" outlined
                                                :disabled="!fiscalDocumentUrl"
                                                v-tooltip.bottom="!fiscalDocumentUrl ? 'Debes subir tu constancia fiscal' : 'Solicitar factura'" />
                                        </div>
                                        <Tag v-else-if="data.status === 'approved'"
                                            :value="getInvoiceStatusTag(data.invoice_status).text"
                                            :severity="getInvoiceStatusTag(data.invoice_status).severity"
                                            class="capitalize" />
                                        <span v-else class="text-gray-400">-</span>
                                    </template>
                                </Column>
                                <template #empty>
                                    <div class="text-center text-gray-500 py-4">
                                        No hay pagos registrados aún.
                                    </div>
                                </template>
                            </DataTable>
                        </div>
                    </AccordionContent>
                </AccordionPanel>
            </Accordion>

            <!-- Modal de Confirmación de Factura -->
            <Dialog v-model:visible="isInvoiceModalVisible" modal header="Confirmar solicitud de factura"
                :style="{ width: '35rem' }">
                <div class="p-4 text-center">
                    <i class="pi pi-info-circle !text-5xl text-blue-500 mb-4"></i>
                    <h4 class="text-lg font-bold mb-2">Verifica tu información fiscal</h4>
                    <p class="text-gray-600 dark:text-gray-300">
                        Antes de continuar, por favor asegúrate de que la Constancia de Situación Fiscal que subiste esté
                        actualizada. La factura se generará con los datos de este documento.
                    </p>
                </div>
                <template #footer>
                    <Button label="Cancelar" text severity="secondary" @click="isInvoiceModalVisible = false" />
                    <Button label="Confirmar y solicitar" icon="pi pi-check" @click="requestInvoice" />
                </template>
            </Dialog>
        </template>
    </Card>
</template>