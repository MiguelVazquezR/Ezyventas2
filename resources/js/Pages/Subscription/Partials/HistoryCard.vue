<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Accordion from 'primevue/accordion';
import AccordionPanel from 'primevue/accordionpanel';
import AccordionHeader from 'primevue/accordionheader';
import AccordionContent from 'primevue/accordioncontent';

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

// --- TESLA UI PASS-THROUGH (PT) ---
const accordionPt = {
    root: { class: 'space-y-4' },
    panel: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] overflow-hidden' },
    header: { class: 'bg-transparent dark:text-white' },
    headerAction: { class: 'p-5 hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors flex items-center justify-between outline-none focus:ring-0 text-sm font-medium dark:text-gray-200' },
    content: { class: 'p-5 pt-0 bg-transparent dark:text-gray-400' }
};

const subDataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-100 dark:bg-[#111111]' },
    headerCell: { class: 'bg-transparent text-[9px] uppercase tracking-widest text-gray-500 font-bold py-3 px-3 border-b border-gray-200 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#1a1a1a] hover:bg-gray-100 dark:hover:bg-[#232323] transition-colors text-xs text-gray-600 dark:text-gray-400 group' },
    bodyCell: { class: 'py-3 px-3 border-b border-gray-100 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-2 !py-0.5 !text-[9px] !uppercase !tracking-widest !font-bold' }
};

const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } 
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                <i class="pi pi-history !text-sm text-blue-500"></i>
            </div>
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Historial de versiones y pagos</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Auditoría de tu suscripción</p>
            </div>
        </div>

        <Accordion :value="['0']" :multiple="true" :pt="accordionPt">
            <AccordionPanel v-for="(version, index) in subscription.versions" :key="version.id" :value="String(index)">
                <AccordionHeader>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-calendar !text-xs text-gray-400"></i>
                        <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">Periodo: {{ formatDate(version.start_date) }} - {{ formatDate(version.end_date) }}</span>
                    </div>
                </AccordionHeader>
                <AccordionContent>
                    <div class="pt-2">
                        
                        <!-- Tabla Conceptos -->
                        <div class="mb-6">
                            <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Conceptos del plan</h4>
                            <DataTable :value="version.processed_items" responsiveLayout="scroll" :pt="subDataTablePt">
                                <Column field="name" header="Concepto">
                                    <template #body="{ data }"><span class="font-medium text-gray-900 dark:text-white m-0">{{ data.name }}</span></template>
                                </Column>
                                <Column header="Cantidad">
                                    <template #body="{ data }">
                                        <div v-if="data.status === 'upgraded'" class="flex items-center gap-1.5 font-mono text-sm">
                                            <span class="text-gray-400 line-through">{{ data.previous_quantity }}</span>
                                            <i class="pi pi-arrow-right !text-[10px] text-primary-500"></i>
                                            <span class="font-bold text-primary-600 dark:text-primary-400">{{ data.quantity }}</span>
                                        </div>
                                        <div v-else-if="data.status === 'downgraded'" class="flex items-center gap-1.5 font-mono text-sm">
                                            <span class="text-gray-400 line-through">{{ data.previous_quantity }}</span>
                                            <i class="pi pi-arrow-right !text-[10px] text-orange-500"></i>
                                            <span class="font-bold text-orange-600 dark:text-orange-400">{{ data.quantity }}</span>
                                        </div>
                                        <span v-else class="font-mono text-sm dark:text-gray-300">
                                            {{ data.quantity }}
                                        </span>
                                    </template>
                                </Column>
                                <Column header="Estado">
                                    <template #body="{ data }">
                                        <Tag v-if="data.status === 'new'" value="Nuevo" severity="success" :pt="tagPt" />
                                        <Tag v-else-if="data.status === 'upgraded'" value="Mejora" severity="info" :pt="tagPt" />
                                        <Tag v-else-if="data.status === 'unchanged'" value="Sin cambio" severity="secondary" :pt="tagPt" />
                                        <Tag v-else-if="data.status === 'downgraded'" value="Reducción" severity="warning" :pt="tagPt" />
                                    </template>
                                </Column>
                                <Column field="billing_period" header="Periodo">
                                    <template #body="{ data }">
                                        <span class="capitalize text-xs">{{ data.billing_period }}</span>
                                    </template>
                                </Column>
                                <Column header="P. Unitario" class="text-right">
                                    <template #body="{ data }">
                                        <span class="font-mono">{{ formatCurrency(data.unit_price) }}</span>
                                    </template>
                                </Column>
                            </DataTable>
                        </div>
                        
                        <!-- Tabla Pagos -->
                        <div>
                            <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Pagos realizados</h4>
                            <DataTable :value="version.payments" responsiveLayout="scroll" :pt="subDataTablePt">
                                <Column field="created_at" header="Fecha de Pago">
                                    <template #body="{ data }">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(data.created_at) }}</span>
                                    </template>
                                </Column>
                                <Column field="payment_method" header="Método">
                                    <template #body="{ data }"><span class="capitalize">{{ data.payment_method }}</span></template>
                                </Column>
                                <Column field="amount" header="Monto" class="text-right">
                                    <template #body="{ data }">
                                        <div v-if="data.referral_discount_pct" class="flex flex-col items-end gap-0.5">
                                            <span class="text-xs text-gray-400 line-through font-mono">{{ formatCurrency(parseFloat(data.amount) + parseFloat(data.referral_discount_amount || 0)) }}</span>
                                            <span class="text-[10px] text-green-600 dark:text-green-400">-{{ data.referral_discount_pct }}% ref.</span>
                                            <span class="font-mono font-medium text-gray-900 dark:text-white">{{ formatCurrency(data.amount) }}</span>
                                        </div>
                                        <span v-else class="font-mono font-medium text-gray-900 dark:text-white">{{ formatCurrency(data.amount) }}</span>
                                    </template>
                                </Column>
                                <Column field="status" header="Estado">
                                    <template #body="{ data }">
                                        <Tag :value="getPaymentStatusTag(data.status).text"
                                             :severity="getPaymentStatusTag(data.status).severity"
                                             class="capitalize" :pt="tagPt" />
                                    </template>
                                </Column>
                                <Column field="invoice_status" header="Factura" class="text-center">
                                    <template #body="{ data }">
                                        <div v-if="data.status === 'approved' && data.invoice_status === 'no_solicitada'">
                                            <Button @click="confirmRequestInvoice(data.id)"
                                                label="Solicitar" size="small" outlined
                                                :disabled="!fiscalDocumentUrl"
                                                v-tooltip.top="!fiscalDocumentUrl ? 'Debes subir tu constancia fiscal' : 'Solicitar factura'" 
                                                class="!rounded-xl !text-[9px] !uppercase !tracking-widest !font-bold !py-1 !px-3" />
                                        </div>
                                        <Tag v-else-if="data.status === 'approved'"
                                            :value="getInvoiceStatusTag(data.invoice_status).text"
                                            :severity="getInvoiceStatusTag(data.invoice_status).severity"
                                            class="capitalize" :pt="tagPt" />
                                        <span v-else class="text-gray-400">-</span>
                                    </template>
                                </Column>
                                <template #empty>
                                    <div class="text-center text-gray-500 py-6 text-xs italic">
                                        No hay pagos registrados para este periodo.
                                    </div>
                                </template>
                            </DataTable>
                        </div>

                    </div>
                </AccordionContent>
            </AccordionPanel>
        </Accordion>

        <!-- Modal de Confirmación de Factura (Estilo Tesla UI) -->
        <Dialog v-model:visible="isInvoiceModalVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-file-pdf !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Solicitar factura</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Verificación de datos</p>
                    </div>
                </div>
            </template>
            
            <div class="pt-2">
                <div class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl flex items-start gap-3 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-info-circle mt-0.5 !text-lg text-blue-500"></i>
                    <div>
                        <p class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 mb-1">Información fiscal</p>
                        <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                            Antes de continuar, por favor asegúrate de que la Constancia de Situación Fiscal que subiste esté actualizada. La factura se generará con los datos de ese documento.
                        </p>
                    </div>
                </div>
            </div>
            
            <template #footer>
                <div class="flex justify-end gap-3 mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a] w-full">
                    <Button label="Cancelar" text severity="secondary" @click="isInvoiceModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button label="Confirmar y solicitar" icon="pi pi-check" @click="requestInvoice" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" />
                </div>
            </template>
        </Dialog>
    </div>
</template>