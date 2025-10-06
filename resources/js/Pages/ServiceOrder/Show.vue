<script setup>
import { ref, computed, watch, provide } from 'vue';
import { Head, router, usePage, useForm } from '@inertiajs/vue3'; // Added useForm
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from "primevue/usetoast";
import DiffViewer from '@/Components/DiffViewer.vue';
import PatternLock from '@/Components/PatternLock.vue';
import PrintModal from '@/Components/PrintModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    serviceOrder: Object,
    activities: Array,
    availableTemplates: Array,
    customFieldDefinitions: Array,
    // Se recibe la sesión activa desde el controlador.
    activeSession: Object,
});

const page = usePage();
const confirm = useConfirm();
const toast = useToast();
const { hasPermission } = usePermissions();

// Se provee la sesión activa para que componentes anidados (como PaymentModal) puedan inyectarla.
provide('activeSession', computed(() => props.activeSession));

// --- Lógica de Modales ---
const isPrintModalVisible = ref(false);
const isPaymentModalVisible = ref(false);
const isInPostCreationFlow = ref(false);
const isDiagnosisModalVisible = ref(false); // NEW: Diagnosis modal visibility

const openPrintModal = () => isPrintModalVisible.value = true;
const openPaymentModal = () => isPaymentModalVisible.value = true;
const openDiagnosisModal = () => { // NEW: Method to open diagnosis modal
    diagnosisForm.technician_diagnosis = props.serviceOrder.technician_diagnosis || '';
    diagnosisForm.closing_evidence_images = [];
    isDiagnosisModalVisible.value = true;
};

// Watch for initial payment modal show from flash message
watch(() => page.props.flash.show_payment_modal, (showModal) => {
    if (showModal) {
        isInPostCreationFlow.value = true;
        openPaymentModal();
        page.props.flash.show_payment_modal = null;
    }
}, { immediate: true });

const handlePaymentSubmit = (payload) => {
    // Se cambia la ruta y se envía el payload completo, que ahora incluye cash_register_session_id.
    router.post(route('payments.store', props.serviceOrder.transaction.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            handlePaymentModalClosed();
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'No se pudo registrar el pago.';
            toast.add({ severity: 'error', summary: 'Error', detail: errorMsg, life: 4000 });
        }
    });
};

const handlePaymentModalClosed = () => {
    isPaymentModalVisible.value = false;
    if (isInPostCreationFlow.value) {
        openPrintModal();
    }
};

const handlePrintModalClosed = () => {
    isPrintModalVisible.value = false;
    if (isInPostCreationFlow.value) {
        isInPostCreationFlow.value = false;
    }
};

// NEW: Diagnosis Form and submission logic
const MAX_CLOSING_EVIDENCE_IMAGES = 5;
const diagnosisForm = useForm({
    technician_diagnosis: props.serviceOrder.technician_diagnosis || '',
    closing_evidence_images: [],
});

const handleDiagnosisSubmit = () => {
    diagnosisForm.post(route('service-orders.saveDiagnosis', props.serviceOrder.id), {
        onSuccess: () => {
            isDiagnosisModalVisible.value = false;
            router.reload({ only: ['serviceOrder'] }); // Reload serviceOrder prop to update diagnosis/media
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'No se pudo guardar el diagnóstico.';
            toast.add({ severity: 'error', summary: 'Error', detail: errorMsg, life: 4000 });
        }
    });
};

const onSelectClosingImages = (event) => {
    const currentImagesCount = diagnosisForm.closing_evidence_images.length;
    const newImages = event.files.slice(0, MAX_CLOSING_EVIDENCE_IMAGES - currentImagesCount);
    diagnosisForm.closing_evidence_images = [...diagnosisForm.closing_evidence_images, ...newImages];
};

const onRemoveClosingImage = (event) => {
    diagnosisForm.closing_evidence_images = diagnosisForm.closing_evidence_images.filter(img => img.objectURL !== event.file.objectURL);
};


const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Órdenes de Servicio', url: route('service-orders.index') },
    { label: `Orden #${props.serviceOrder.folio || props.serviceOrder.id}` }
]);

const steps = ref([
    { label: 'Pendiente', value: 'pendiente', icon: 'pi pi-inbox' },
    { label: 'En Progreso', value: 'en_progreso', icon: 'pi pi-cog' },
    { label: 'Esperando Refacción', value: 'esperando_refaccion', icon: 'pi pi-wrench' },
    { label: 'Terminado', value: 'terminado', icon: 'pi pi-check-circle' },
    { label: 'Entregado', value: 'entregado', icon: 'pi pi-send' }
]);

const activeIndex = computed(() => {
    const index = steps.value.findIndex(step => step.value === props.serviceOrder.status);
    return index >= 0 ? index + 1 : 0;
});

const isCancelled = computed(() => props.serviceOrder.status === 'cancelado');

const totalPaid = computed(() => {
    return props.serviceOrder.transaction?.payments.reduce((sum, payment) => sum + parseFloat(payment.amount), 0) || 0;
});

const amountDue = computed(() => {
    return parseFloat(props.serviceOrder.final_total) - totalPaid.value;
});

const deliveryDate = computed(() => {
    // Verifica que el estatus sea 'entregado' y que existan pagos.
    if (props.serviceOrder.status === 'entregado' && props.serviceOrder.transaction?.payments?.length > 0) {
        // Encuentra el pago más reciente comparando las fechas.
        const latestPayment = props.serviceOrder.transaction.payments.reduce((latest, current) => {
            return new Date(current.payment_date) > new Date(latest.payment_date) ? current : latest;
        });
        return latestPayment.payment_date;
    }
    return null; // Devuelve null si no se cumplen las condiciones.
});

// --- INICIO: LÓGICA DE GANANCIA ---
const technicianCommissionCostNumeric = computed(() => {
    if (!props.serviceOrder.technician_name || !props.serviceOrder.technician_commission_value) {
        return 0;
    }
    const value = parseFloat(props.serviceOrder.technician_commission_value);
    const total = parseFloat(props.serviceOrder.final_total);

    if (props.serviceOrder.technician_commission_type === 'percentage') {
        return (total * value) / 100;
    } else if (props.serviceOrder.technician_commission_type === 'fixed') {
        return value;
    }
    return 0;
});

const partsCost = computed(() => {
    if (!props.serviceOrder.items || props.serviceOrder.items.length === 0) {
        return 0;
    }
    return props.serviceOrder.items.reduce((total, item) => {
        // Suma el costo solo si el item es un producto (refacción)
        if (item.itemable_type === 'App\\Models\\Product') {
            // Usa '|| 0' para manejar casos donde cost_price es null o undefined, evitando NaN.
            const cost = parseFloat(item.unit_price) || 0;
            const quantity = parseFloat(item.quantity) || 0;
            return total + (cost * quantity);
        }
        return total;
    }, 0);
});

const profitAnalysis = computed(() => {
    const totalRevenue = parseFloat(props.serviceOrder.final_total) || 0;
    const commission = technicianCommissionCostNumeric.value;
    const parts = partsCost.value;

    const totalCosts = commission + parts;
    const netProfit = totalRevenue - totalCosts;
    const margin = totalRevenue > 0 ? (netProfit / totalRevenue) * 100 : 0;

    return {
        totalRevenue,
        commission,
        parts,
        totalCosts,
        netProfit,
        margin,
    };
});
// --- FIN: LÓGICA DE GANANCIA ---

const technicianCommission = computed(() => {
    if (technicianCommissionCostNumeric.value === 0 && (!props.serviceOrder.technician_name || !props.serviceOrder.technician_commission_value)) {
        return 'N/A';
    }
    const value = parseFloat(props.serviceOrder.technician_commission_value);
    const formattedAmount = formatCurrency(technicianCommissionCostNumeric.value);

    if (props.serviceOrder.technician_commission_type === 'percentage') {
        return `${formattedAmount} (${value}%)`;
    }
    return formattedAmount;
});

const changeStatus = (newStatusValue, newIndex) => {
    if (!hasPermission('services.orders.change_status') || newIndex < activeIndex.value || isCancelled.value) return;
    const newStatusLabel = steps.value.find(s => s.value === newStatusValue)?.label || newStatusValue;
    confirm.require({
        message: `¿Estás seguro de que quieres cambiar el estatus a "${newStatusLabel}"?`,
        header: 'Confirmar Cambio de Estatus',
        icon: 'pi pi-sync',
        accept: () => {
            router.patch(route('service-orders.updateStatus', props.serviceOrder.id), { status: newStatusValue }, {
                preserveScroll: true,
                onSuccess: () => {
                    if (newStatusValue === 'entregado' && amountDue.value > 0.01) {
                        openPaymentModal();
                    }
                }
            });
        }
    });
};

const cancelOrder = () => {
    confirm.require({
        message: 'Al cancelar la orden, su estatus cambiará a "Cancelado" y no podrá seguir avanzando en el flujo. ¿Estás seguro?',
        header: 'Confirmar Cancelación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, cancelar orden',
        rejectLabel: 'No, mantener',
        accept: () => {
            router.patch(route('service-orders.updateStatus', props.serviceOrder.id), { status: 'cancelado' }, { preserveScroll: true });
        }
    });
};

const deleteOrder = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar esta orden de servicio? Esta acción no se puede deshacer.`,
        header: 'Confirmar Eliminación',
        accept: () => {
            router.delete(route('service-orders.destroy', props.serviceOrder.id));
        }
    });
};

const actionItems = ref([
    { label: 'Crear nueva orden', icon: 'pi pi-plus', command: () => router.get(route('service-orders.create')), visible: hasPermission('services.orders.create') },
    { label: 'Editar orden', icon: 'pi pi-pencil', command: () => router.get(route('service-orders.edit', props.serviceOrder.id)), visible: hasPermission('services.orders.edit') },
    { label: 'Registrar Diagnóstico y Evidencia', icon: 'pi pi-file-edit', command: openDiagnosisModal, visible: computed(() => !isCancelled.value && hasPermission('services.orders.edit')) },
    { label: 'Registrar Pago', icon: 'pi pi-dollar', command: openPaymentModal, visible: computed(() => amountDue.value > 0.01 && props.serviceOrder.final_total > 0) },
    { label: 'Imprimir', icon: 'pi pi-print', command: openPrintModal },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteOrder, visible: hasPermission('services.orders.delete') },
]);

const whatsappLink = computed(() => {
    if (!props.serviceOrder.customer_phone) return '#';
    const sanitizedPhone = props.serviceOrder.customer_phone.replace(/\D/g, '');
    return `https://wa.me/${sanitizedPhone.length === 10 ? `52${sanitizedPhone}` : sanitizedPhone}`;
});

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });
};

const getStatusSeverity = (status) => {
    const map = { pendiente: 'info', en_progreso: 'warning', esperando_refaccion: 'secondary', terminado: 'success', entregado: 'primary', cancelado: 'danger' };
    return map[status] || 'secondary';
};

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const getCustomFieldDefinition = (key) => {
    return props.customFieldDefinitions?.find(def => def.key === key);
};

const initialEvidenceImages = computed(() => {
    return props.serviceOrder.media?.filter(m => m.collection_name === 'initial-service-order-evidence') || [];
});

const closingEvidenceImages = computed(() => {
    return props.serviceOrder.media?.filter(m => m.collection_name === 'closing-service-order-evidence') || [];
});

// --- NUEVA FUNCIÓN AUXILIAR ---
const getItemType = (itemableType) => {
    if (itemableType === 'App\\Models\\Product') {
        return { text: 'Refacción', severity: 'info' };
    }
    if (itemableType === 'App\\Models\\Service') {
        return { text: 'Servicio', severity: 'success' };
    }
    return { text: 'Otro', severity: 'secondary' };
};

</script>

<template>

    <Head :title="`Orden de Servicio #${serviceOrder.folio || serviceOrder.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Orden de Servicio #{{ serviceOrder.folio || serviceOrder.id }}
                </h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Cliente: {{ serviceOrder.customer_name }}</p>
            </div>
            <div class="flex items-center gap-2 mt-4 sm:mt-0">
                <Button v-if="!isCancelled && hasPermission('services.orders.change_status')" @click="cancelOrder"
                    label="Cancelar Orden" severity="danger" outlined />
                <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined></SplitButton>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="col-span-full bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                <h2 class="text-lg font-semibold border-b pb-3 mb-6">Flujo de Estatus</h2>
                <div v-if="isCancelled" class="text-center p-4 bg-red-50 dark:bg-red-900/20 rounded-md">
                    <i class="pi pi-times-circle text-red-500 text-3xl"></i>
                    <p class="mt-2 font-semibold text-red-700 dark:text-red-300">Esta orden ha sido cancelada.</p>
                </div>
                <Stepper v-else v-model:value="activeIndex" class="basis-full">
                    <StepList>
                        <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1" v-slot="{ value }"
                            asChild>
                            <div class="flex flex-row flex-auto">
                                <button class="bg-transparent border-0 inline-flex flex-col gap-2 items-center"
                                    :class="index == 4 ? 'w-32' : 'w-60'" @click="changeStatus(step.value, value)">
                                    <span
                                        :class="['size-12 rounded-full border-2 flex items-center justify-center transition-colors duration-200', { 'bg-primary border-primary text-primary-contrast': value <= activeIndex, 'border-surface-200 dark:border-surface-700': value > activeIndex, 'cursor-pointer hover:border-primary': value > activeIndex && hasPermission('services.orders.change_status') }]">
                                        <i :class="step.icon" />
                                    </span>
                                    <span :class="['font-medium text-xs', { 'text-primary': value <= activeIndex }]">{{
                                        step.label }}</span>
                                </button>
                                <Divider v-if="index != 4" />
                            </div>
                        </Step>
                    </StepList>
                </Stepper>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div v-if="hasPermission('services.orders.see_customer_info')">
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información del Cliente</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex items-center"><i class="pi pi-user w-6 text-gray-500"></i><span
                                        class="font-medium">{{ serviceOrder.customer_name }}</span></li>
                                <li v-if="serviceOrder.customer_phone" class="flex items-center">
                                    <i class="pi pi-phone w-6 text-gray-500"></i>
                                    <span class="font-medium">{{ serviceOrder.customer_phone }}</span>
                                    <a :href="whatsappLink" target="_blank" class="ml-auto">
                                        <Button icon="pi pi-whatsapp" severity="success" text rounded
                                            v-tooltip.bottom="'Enviar WhatsApp'" />
                                    </a>
                                </li>
                                <li class="flex items-center"><i class="pi pi-envelope w-6 text-gray-500"></i><span
                                        class="font-medium">{{ serviceOrder.customer_email || 'N/A' }}</span></li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información de la Orden</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span>Estatus Actual</span>
                                    <Tag :value="serviceOrder.status.replace('_', ' ')"
                                        :severity="getStatusSeverity(serviceOrder.status)" class="capitalize" />
                                </li>
                                <li class="flex justify-between"><span>Fecha de Recepción</span><span>{{
                                    formatDate(serviceOrder.received_at) }}</span></li>
                                <li class="flex justify-between"><span>Fecha Promesa</span><span>{{
                                    formatDate(serviceOrder.promised_at) }}</span></li>
                                <li v-if="deliveryDate" class="flex justify-between">
                                    <span>Fecha de Entrega</span>
                                    <span class="font-semibold">{{ formatDate(deliveryDate) }}</span>
                                </li>
                                <li class="flex justify-between"><span>Técnico Asignado</span><span>{{
                                    serviceOrder.technician_name || 'Sin asignar' }}</span></li>
                                <li v-if="serviceOrder.technician_name" class="flex justify-between">
                                    <span>Comisión del Técnico:</span>
                                    <span class="font-semibold">{{ technicianCommission }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-if="serviceOrder.custom_fields && Object.keys(serviceOrder.custom_fields).length > 0"
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles Adicionales</h2>
                    <ul class="space-y-4 text-sm">
                        <li v-for="(value, key) in serviceOrder.custom_fields" :key="key">
                            <span class="font-semibold capitalize">{{ key.replace(/_/g, ' ') }}</span>
                            <div v-if="getCustomFieldDefinition(key)?.type === 'pattern'" class="mt-2">
                                <PatternLock :modelValue="value" :edit="false" />
                            </div>
                            <div v-else-if="getCustomFieldDefinition(key)?.type === 'select'">
                                <p class="text-gray-700 dark:text-gray-300">{{ value || 'N/A' }}</p>
                            </div>
                            <div v-else-if="getCustomFieldDefinition(key)?.type === 'checkbox'"
                                class="flex flex-col gap-2 mt-2">
                                <div v-for="option in getCustomFieldDefinition(key)?.options" :key="option"
                                    class="flex items-center">
                                    <i
                                        :class="[value && value.includes(option) ? 'pi pi-check-circle text-green-500' : 'pi pi-times-circle text-red-500', 'mr-2']"></i>
                                    <span
                                        :class="{ 'font-medium': value && value.includes(option), 'text-gray-500': !value || !value.includes(option) }">{{
                                            option }}</span>
                                </div>
                            </div>
                            <p v-else class="text-gray-700 dark:text-gray-300">{{ value === true ? 'Sí' : value ===
                                false ? 'No' : value || 'N/A' }}</p>
                        </li>
                    </ul>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y mano de obra</h2>
                    <DataTable :value="serviceOrder.items" class="p-datatable-sm">
                        <Column header="Tipo" style="width: 8rem">
                            <template #body="{ data }">
                                <Tag :value="getItemType(data.itemable_type).text" :severity="getItemType(data.itemable_type).severity" />
                            </template>
                        </Column>
                        <Column field="description" header="Descripción"></Column>
                        <Column field="quantity" header="Cantidad" style="width: 6rem" class="text-center"></Column>
                        <Column field="unit_price" header="Precio Unit." style="width: 10rem" class="text-right">
                            <template #body="{ data }">{{ formatCurrency(data.unit_price) }}</template>
                        </Column>
                        <Column field="line_total" header="Total" style="width: 10rem" class="text-right font-semibold">
                            <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
                        </Column>
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No se han agregado refacciones o mano de obra.
                            </div>
                        </template>
                    </DataTable>
                    <div class="flex justify-end mt-4">
                        <div class="w-full max-w-xs text-right space-y-2">
                            <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2">
                                <span>Total Final:</span>
                                <span>{{ formatCurrency(serviceOrder.final_total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles del Equipo y Falla</h2>
                    <div>
                        <p class="font-semibold">Descripción del Equipo</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.item_description }}</p>
                    </div>
                    <div class="mt-4">
                        <p class="font-semibold">Problemas Reportados por el Cliente</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.reported_problems }}
                        </p>
                    </div>
                    <div v-if="serviceOrder.technician_diagnosis" class="mt-4 pt-4 border-t">
                        <p class="font-semibold">Diagnóstico del Técnico</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.technician_diagnosis }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Estado de Cuenta</h2>
                    <ul class="space-y-2 text-sm">
                        <li class="flex justify-between"><span>Total de la Orden:</span><span class="font-semibold">{{
                            formatCurrency(serviceOrder.final_total) }}</span></li>
                        <li class="flex justify-between"><span>Total Pagado:</span><span
                                class="font-semibold text-green-600">{{
                                    formatCurrency(totalPaid) }}</span></li>
                        <li class="flex justify-between text-base font-bold border-t pt-2 mt-2"
                            :class="amountDue > 0.01 ? 'text-red-500' : 'text-gray-800 dark:text-gray-200'">
                            <span>Saldo Pendiente:</span>
                            <span>{{ formatCurrency(amountDue) }}</span>
                        </li>
                    </ul>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Análisis de Ganancia</h2>
                    <ul class="space-y-2 text-sm">
                        <li class="flex justify-between">
                            <span>Total cobrado:</span>
                            <span class="font-semibold">{{ formatCurrency(profitAnalysis.totalRevenue) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Costo de refacciones:</span>
                            <span class="text-red-500">(-) {{ formatCurrency(profitAnalysis.parts) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Comisión del técnico:</span>
                            <span class="text-red-500">(-) {{ formatCurrency(profitAnalysis.commission) }}</span>
                        </li>
                        <li class="flex justify-between font-medium border-t pt-2 mt-2">
                            <span>Costos totales:</span>
                            <span>{{ formatCurrency(profitAnalysis.totalCosts) }}</span>
                        </li>
                        <li class="flex justify-between text-base font-bold text-green-600 border-t pt-2 mt-2">
                            <span>Ganancia neta:</span>
                            <span>{{ formatCurrency(profitAnalysis.netProfit) }}</span>
                        </li>
                        <li class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mt-2">
                            <span>Margen de ganancia:</span>
                            <span>{{ profitAnalysis.margin.toFixed(2) }}%</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Pagos Registrados</h2>
                    <DataTable :value="serviceOrder.transaction?.payments" class="p-datatable-sm"
                        responsiveLayout="scroll">
                        <Column field="payment_date" header="Fecha"><template #body="{ data }">{{
                            formatDate(data.payment_date) }}</template></Column>
                        <Column field="payment_method" header="Método" style="width: 8rem"><template #body="{ data }">
                                <Tag :value="data.payment_method" class="capitalize" />
                            </template></Column>
                        <Column field="amount" header="Monto" style="width: 8rem" class="text-right"><template
                                #body="{ data }">{{ formatCurrency(data.amount) }}</template></Column>
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No se han registrado pagos.
                            </div>
                        </template>
                    </DataTable>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia Inicial</h2>
                    <Galleria :value="initialEvidenceImages" :numVisible="4" containerStyle="max-width: 100%"
                        :showThumbnails="false" :showIndicators="true">
                        <template #item="slotProps"><img :src="slotProps.item.original_url"
                                :alt="`Evidencia ${slotProps.index}`"
                                class="w-full max-h-96 object-contain" /></template>
                    </Galleria>
                    <div v-if="initialEvidenceImages.length === 0" class="text-center text-gray-500 py-4">No se
                        adjuntaron imágenes.
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia de Cierre de Servicio</h2>
                    <Galleria :value="closingEvidenceImages" :numVisible="4" containerStyle="max-width: 100%"
                        :showThumbnails="false" :showIndicators="true">
                        <template #item="slotProps"><img :src="slotProps.item.original_url"
                                :alt="`Evidencia de Cierre ${slotProps.index}`"
                                class="w-full max-h-96 object-contain" /></template>
                    </Galleria>
                    <div v-if="closingEvidenceImages.length === 0" class="text-center text-gray-500 py-4">No se
                        adjuntaron imágenes
                        de cierre.</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-6">Historial de Actividad</h2>
                    <div v-if="activities && activities.length > 0" class="relative max-h-[300px] overflow-y-auto pr-2">
                        <div class="relative pl-6">
                            <div class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                            </div>
                            <div class="space-y-8">
                                <div v-for="activity in activities" :key="activity.id" class="relative">
                                    <div class="absolute left-0 top-1.5 -translate-x-1/2">
                                        <span
                                            class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                                            :class="{ 'bg-blue-400': activity.event === 'created', 'bg-orange-400': activity.event === 'updated', 'bg-red-400': activity.event === 'deleted', 'bg-indigo-400': activity.event === 'status_changed' }">
                                            <i
                                                :class="{ 'pi pi-plus': activity.event === 'created', 'pi pi-pencil': activity.event === 'updated', 'pi pi-trash': activity.event === 'deleted', 'pi pi-refresh': activity.event === 'status_changed' }"></i>
                                        </span>
                                    </div>
                                    <div class="ml-10">
                                        <h3 class="font-semibold text-gray-800 dark:text-gray-200 text-lg m-0">{{
                                            activity.description }}</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Por {{ activity.causer }} -
                                            {{
                                                activity.timestamp }}</p>
                                        <div v-if="activity.event === 'updated' && Object.keys(activity.changes.after).length > 0"
                                            class="mt-3 text-sm space-y-2">
                                            <div v-for="(value, key) in activity.changes.after" :key="key">
                                                <p class="font-medium text-gray-700 dark:text-gray-300">{{ key }}</p>
                                                <DiffViewer v-if="key === 'Descripción'"
                                                    :oldValue="activity.changes.before[key]" :newValue="value" />
                                                <div v-else class="flex items-center gap-2 text-xs">
                                                    <span
                                                        class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 px-2 py-0.5 rounded line-through">{{
                                                            activity.changes.before[key] || 'Vacío' }}</span>
                                                    <i class="pi pi-arrow-right text-gray-400"></i>
                                                    <span
                                                        class="bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 px-2 py-0.5 rounded font-medium">{{
                                                            value }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-500 py-8"> No hay actividades registradas. </div>
                </div>
            </div>
        </div>

        <PaymentModal v-if="serviceOrder.transaction" v-model:visible="isPaymentModalVisible" :total-amount="amountDue"
            :client="serviceOrder.customer" payment-mode="flexible" @submit="handlePaymentSubmit"
            @update:visible="(val) => { if (!val) handlePaymentModalClosed(); }" />
        <PrintModal v-if="serviceOrder" v-model:visible="isPrintModalVisible"
            :data-source="{ type: 'service_order', id: serviceOrder.id }" :available-templates="availableTemplates"
            @hide="handlePrintModalClosed" />

        <Dialog v-model:visible="isDiagnosisModalVisible" modal header="Registrar Diagnóstico y Evidencia"
            :style="{ width: '40rem' }">
            <div class="p-fluid formgrid grid">
                <div class="field col-12 mb-4">
                    <label for="technician_diagnosis" class="font-semibold">Diagnóstico del Técnico</label>
                    <Textarea id="technician_diagnosis" v-model="diagnosisForm.technician_diagnosis" rows="5"
                        class="w-full mt-2" :class="{ 'p-invalid': diagnosisForm.errors.technician_diagnosis }" />
                    <small class="p-error" v-if="diagnosisForm.errors.technician_diagnosis">{{
                        diagnosisForm.errors.technician_diagnosis }}</small>
                </div>

                <div class="field col-12">
                    <label class="font-semibold mb-2 block">Evidencia de Cierre (Máx. 5 imágenes)</label>
                    <FileUpload name="closing_evidence_images[]" @select="onSelectClosingImages"
                        @remove="onRemoveClosingImage" :multiple="true" :show-upload-button="false" accept="image/*"
                        :maxFileSize="2000000" :fileLimit="MAX_CLOSING_EVIDENCE_IMAGES"
                        :invalidFileSizeMessage="'{0}: El tamaño del archivo excede el límite de 2MB.'"
                        :invalidFileLimitMessage="'Máximo {0} archivos permitidos.'">
                        <template #empty>
                            <p>Arrastra y suelta las imágenes finales del servicio aquí.</p>
                        </template>
                    </FileUpload>
                    <small class="p-error" v-if="diagnosisForm.errors.closing_evidence_images">{{
                        diagnosisForm.errors.closing_evidence_images }}</small>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text @click="isDiagnosisModalVisible = false" />
                <Button label="Guardar Diagnóstico" @click="handleDiagnosisSubmit"
                    :loading="diagnosisForm.processing" />
            </template>
        </Dialog>

    </AppLayout>
</template>