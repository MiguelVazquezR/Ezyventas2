<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    subscription: Object,
    planItems: Array, // Se recibe el catálogo de planes
});

const toast = useToast();

const currentVersion = computed(() => props.subscription?.versions?.[0] || null);

// --- Lógica para combinar el plan actual con el catálogo disponible ---
const displayPlanItems = computed(() => {
    const activeItemKeys = new Set(currentVersion.value?.items.map(item => item.item_key) || []);

    return props.planItems.map(planItem => ({
        ...planItem,
        is_active: activeItemKeys.has(planItem.key),
    }));
});

const activeModules = computed(() => displayPlanItems.value.filter(item => item.type === 'module'));

// Muestra los límites que vienen de la versión actual de la suscripción
const activeLimits = computed(() => {
    if (!currentVersion.value) return [];
    return currentVersion.value.items.filter(item => item.item_type === 'limit');
});

// --- Lógica de Edición de Información ---
const isEditModalVisible = ref(false);
const infoForm = useForm({
    commercial_name: props.subscription.commercial_name,
    business_name: props.subscription.business_name,
});
const submitInfoForm = () => {
    infoForm.put(route('subscription.update'), {
        onSuccess: () => isEditModalVisible.value = false,
    });
};

// --- Lógica de Documento Fiscal ---
const fiscalDocumentUrl = computed(() => props.subscription.media[0]?.original_url || null);
const docForm = useForm({ fiscal_document: null });
const fileUploadRef = ref(null);
const onFileSelect = (event) => { docForm.fiscal_document = event.files[0]; };
const uploadDocument = () => {
    docForm.post(route('subscription.documents.store'), {
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Éxito', detail: 'Documento fiscal actualizado.', life: 3000 });
            docForm.reset();
            if (fileUploadRef.value) fileUploadRef.value.clear();
        }
    });
};

// --- Lógica de Solicitud de Factura ---
const isInvoiceModalVisible = ref(false);
const paymentToRequest = ref(null);
const confirmRequestInvoice = (paymentId) => {
    paymentToRequest.value = paymentId;
    isInvoiceModalVisible.value = true;
};
const requestInvoice = () => {
    if (paymentToRequest.value) {
        router.post(route('subscription.payments.request-invoice', paymentToRequest.value), {}, {
            preserveScroll: true,
            onSuccess: () => {
                isInvoiceModalVisible.value = false;
                paymentToRequest.value = null;
            }
        });
    }
};

// --- Helpers ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
const getStatusTagSeverity = (status) => ({ activo: 'success', expirado: 'warning', suspendido: 'danger' })[status] || 'info';
const getFileIcon = (type) => {
    if (type.includes('pdf')) return 'pi pi-file-pdf text-red-500 text-4xl';
    if (type.includes('image')) return 'pi pi-image text-blue-500 text-4xl';
    return 'pi pi-file text-gray-500 text-4xl';
};
const getInvoiceStatusTag = (status) => {
    return {
        'no_solicitada': { text: 'No Solicitada', severity: 'secondary' },
        'solicitada': { text: 'Solicitada', severity: 'info' },
        'generada': { text: 'Generada', severity: 'success' },
    }[status] || { text: status, severity: 'secondary' };
};
</script>

<template>

    <Head title="Mi Suscripción" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Mi Suscripción</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Aquí puedes ver los detalles de tu plan, historial de
                    pagos e información fiscal.</p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna Izquierda -->
                <div class="lg:col-span-1 space-y-6">
                    <Card>
                        <template #title>
                            <div class="flex justify-between items-center">
                                <span>Información General</span>
                                <Button icon="pi pi-pencil" text rounded severity="secondary"
                                    @click="isEditModalVisible = true" v-tooltip.bottom="'Editar Información'" />
                            </div>
                        </template>
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
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-500">Estatus:</span>
                                    <Tag :value="subscription.status"
                                        :severity="getStatusTagSeverity(subscription.status)" class="capitalize" />
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Miembro desde:</span>
                                    <span class="font-semibold">{{ formatDate(subscription.created_at) }}</span>
                                </div>
                            </div>
                        </template>
                    </Card>
                    <Card>
                        <template #title>Información Fiscal</template>
                        <template #content>
                            <div v-if="fiscalDocumentUrl" class="space-y-4">
                                <p class="text-sm text-gray-600 dark:text-gray-300">Tu Constancia de Situación Fiscal
                                    está registrada.</p>
                                <a :href="fiscalDocumentUrl" target="_blank" rel="noopener noreferrer">
                                    <Button label="Ver Documento Actual" icon="pi pi-file-pdf" outlined
                                        severity="secondary" />
                                </a>
                                <p class="text-xs text-gray-500 pt-4 border-t dark:border-gray-700">Para actualizar,
                                    simplemente sube un nuevo archivo.</p>
                            </div>
                            <p v-else class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                                Sube tu Constancia de Situación Fiscal para solicitar facturas.
                            </p>
                            <FileUpload ref="fileUploadRef" name="fiscal_document" @select="onFileSelect"
                                :showUploadButton="false" :showCancelButton="false" customUpload
                                accept=".pdf,.jpg,.jpeg,.png" :maxFileSize="2048000">
                                <template #thumbnail="{ file }">
                                    <div
                                        class="w-full h-full flex items-center justify-center border-2 border-dashed rounded-md p-4">
                                        <i :class="getFileIcon(file.type)"></i>
                                    </div>
                                </template>
                                <template #empty>
                                    <p class="text-sm text-center text-gray-500">Arrastra y suelta tu archivo aquí o haz
                                        clic para seleccionar.</p>
                                </template>
                            </FileUpload>
                            <Button v-if="docForm.fiscal_document" @click="uploadDocument" label="Subir Nuevo Documento"
                                class="w-full mt-4" :loading="docForm.processing" />
                        </template>
                    </Card>
                </div>

                <!-- Columna Derecha -->
                <div class="lg:col-span-2 space-y-6">
                    <Card v-if="currentVersion">
                        <template #title>
                            <div class="flex justify-between items-center">
                                <span>Plan Actual y Módulos</span>
                                <Link :href="route('subscription.upgrade.show')">
                                <Button label="Mejorar Suscripción" icon="pi pi-arrow-up" size="small" />
                                </Link>
                            </div>
                        </template>
                        <template #subtitle>
                            Vigencia: {{ formatDate(currentVersion.start_date) }} - {{
                                formatDate(currentVersion.end_date) }}
                        </template>
                        <template #content>
                            <div class="mb-6">
                                <h3 class="font-bold mb-4 text-gray-800 dark:text-gray-200">Módulos</h3>
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <div v-for="module in activeModules" :key="module.key"
                                        class="p-4 rounded-lg text-center flex flex-col items-center justify-center transition-all"
                                        :class="module.is_active ? 'bg-gray-50 dark:bg-gray-800' : 'bg-gray-100 dark:bg-gray-900 opacity-60'">
                                        <div class="relative">
                                            <!-- SOLUCIÓN: Se combinan las clases en un solo array -->
                                            <i
                                                :class="[module.meta.icon, 'text-3xl mb-2', module.is_active ? 'text-orange-500' : 'text-gray-500']"></i>
                                            <i v-if="module.is_active"
                                                class="pi pi-check-circle text-green-500 absolute -top-1 -right-1 bg-white dark:bg-gray-800 rounded-full"></i>
                                        </div>
                                        <span class="font-semibold text-sm">{{ module.name }}</span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h3 class="font-bold mb-4 text-gray-800 dark:text-gray-200">Límites del Plan</h3>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div v-for="limit in activeLimits" :key="limit.item_key"
                                        class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg text-center">
                                        <p class="text-2xl font-bold">{{ limit.quantity }}</p>
                                        <p class="text-sm text-gray-500">{{ limit.name }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Card>
                    <Card>
                        <template #title>Historial de Versiones y Pagos</template>
                        <template #content>
                            <Accordion :activeIndex="0">
                                <AccordionTab v-for="version in subscription.versions" :key="version.id"
                                    :header="`Periodo: ${formatDate(version.start_date)} - ${formatDate(version.end_date)}`">
                                    <div class="p-4">
                                        <h4 class="font-bold mb-2">Conceptos del Plan</h4>
                                        <DataTable :value="version.items" size="small" class="mb-6">
                                            <Column field="name" header="Concepto"></Column>
                                            <Column field="billing_period" header="Periodo">
                                                <template #body="slotProps"><span class="capitalize">{{
                                                        slotProps.data.billing_period }}</span></template>
                                            </Column>
                                            <Column field="unit_price" header="Precio">
                                                <template #body="slotProps">{{ formatCurrency(slotProps.data.unit_price)
                                                    }}</template>
                                            </Column>
                                        </DataTable>
                                        <h4 class="font-bold mb-2">Pagos Realizados</h4>
                                        <DataTable :value="version.payments" size="small">
                                            <Column field="created_at" header="Fecha de Pago">
                                                <template #body="slotProps">{{ formatDate(slotProps.data.created_at)
                                                    }}</template>
                                            </Column>
                                            <Column field="payment_method" header="Método" class="capitalize"></Column>
                                            <Column field="amount" header="Monto">
                                                <template #body="slotProps">{{ formatCurrency(slotProps.data.amount)
                                                    }}</template>
                                            </Column>
                                            <Column field="invoice_status" header="Factura">
                                                <template #body="{ data }">
                                                    <div v-if="data.invoice_status === 'no_solicitada'">
                                                        <Button @click="confirmRequestInvoice(data.id)"
                                                            label="Solicitar" size="small" outlined
                                                            :disabled="!fiscalDocumentUrl"
                                                            v-tooltip.bottom="!fiscalDocumentUrl ? 'Debes subir tu constancia fiscal' : 'Solicitar factura'" />
                                                    </div>
                                                    <Tag v-else :value="getInvoiceStatusTag(data.invoice_status).text"
                                                        :severity="getInvoiceStatusTag(data.invoice_status).severity"
                                                        class="capitalize" />
                                                </template>
                                            </Column>
                                        </DataTable>
                                    </div>
                                </AccordionTab>
                            </Accordion>
                        </template>
                    </Card>
                </div>
            </div>
        </div>

        <Dialog v-model:visible="isEditModalVisible" modal header="Editar Información" :style="{ width: '30rem' }">
            <form @submit.prevent="submitInfoForm" class="p-2 space-y-4">
                <div>
                    <InputLabel for="commercial_name" value="Nombre Comercial" />
                    <InputText id="commercial_name" v-model="infoForm.commercial_name" class="w-full mt-1" />
                </div>
                <div>
                    <InputLabel for="business_name" value="Razón Social" />
                    <InputText id="business_name" v-model="infoForm.business_name" class="w-full mt-1" />
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <Button type="button" label="Cancelar" severity="secondary" @click="isEditModalVisible = false"
                        text />
                    <Button type="submit" label="Guardar Cambios" :loading="infoForm.processing" />
                </div>
            </form>
        </Dialog>

        <Dialog v-model:visible="isInvoiceModalVisible" modal header="Confirmar Solicitud de Factura"
            :style="{ width: '35rem' }">
            <div class="p-4 text-center">
                <i class="pi pi-info-circle text-5xl text-blue-500 mb-4"></i>
                <h3 class="text-lg font-bold mb-2">Verifica tu Información Fiscal</h3>
                <p class="text-gray-600 dark:text-gray-300">
                    Antes de continuar, por favor asegúrate de que la Constancia de Situación Fiscal que subiste esté
                    actualizada. La factura se generará con los datos de este documento.
                </p>
            </div>
            <template #footer>
                <Button label="Cancelar" text severity="secondary" @click="isInvoiceModalVisible = false" />
                <Button label="Confirmar y Solicitar" icon="pi pi-check" @click="requestInvoice" />
            </template>
        </Dialog>
    </AppLayout>
</template>