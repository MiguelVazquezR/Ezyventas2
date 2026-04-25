<script setup>
import { ref, computed, watch, provide } from 'vue';
import { router, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from "primevue/usetoast";
import PatternLock from '@/Components/PatternLock.vue';
import PrintModal from '@/Components/PrintModal.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import { usePermissions } from '@/Composables';

// --- Partials Importados ---
import OrderItemsPanel from './Partials/OrderItemsPanel.vue';
import OrderFinancialPanel from './Partials/OrderFinancialPanel.vue';
import OrderEvidencePanel from './Partials/OrderEvidencePanel.vue';
import OrderStatusStepper from './Partials/OrderStatusStepper.vue';
import OrderSummaryPanel from './Partials/OrderSummaryPanel.vue';

const props = defineProps({
    serviceOrder: Object,
    activities: Array,
    availableTemplates: Array,
    customFieldDefinitions: Array,
    activeSession: Object,
});

const page = usePage();
const confirm = useConfirm();
const toast = useToast();
const { hasPermission } = usePermissions();

provide('activeSession', computed(() => props.activeSession));

const isPrintModalVisible = ref(false);
const isPaymentModalVisible = ref(false);
const isInPostCreationFlow = ref(false);
const isDiagnosisModalVisible = ref(false);
const isPaymentProcessing = ref(false);

const openPrintModal = () => isPrintModalVisible.value = true;
const openPaymentModal = () => isPaymentModalVisible.value = true;
const openDiagnosisModal = () => {
    diagnosisForm.technician_diagnosis = props.serviceOrder.technician_diagnosis || '';
    diagnosisForm.closing_evidence_images = [];
    isDiagnosisModalVisible.value = true;
};

watch(() => page.props.flash.show_payment_modal, (showModal) => {
    if (showModal) {
        isInPostCreationFlow.value = true;
        openPaymentModal();
        page.props.flash.show_payment_modal = null;
    }
}, { immediate: true });

const handlePaymentSubmit = (paymentData) => {
    if (!props.activeSession) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No hay una sesión de caja activa para registrar el pago.', life: 5000 });
        return;
    }

    const payload = {
        ...paymentData,
        cash_register_session_id: props.activeSession.id
    };

    isPaymentProcessing.value = true;

    router.post(route('payments.store', props.serviceOrder.transaction.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            handlePaymentModalClosed();
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'No se pudo registrar el pago.';
            toast.add({ severity: 'error', summary: 'Error', detail: errorMsg, life: 4000 });
        },
        onFinish: () => {
            isPaymentProcessing.value = false;
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

const MAX_CLOSING_EVIDENCE_IMAGES = 5;
const diagnosisForm = useForm({
    technician_diagnosis: props.serviceOrder.technician_diagnosis || '',
    closing_evidence_images: [],
});

const handleDiagnosisSubmit = () => {
    diagnosisForm.post(route('service-orders.saveDiagnosis', props.serviceOrder.id), {
        onSuccess: () => {
            isDiagnosisModalVisible.value = false;
            router.reload({ only: ['serviceOrder'] });
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
    { label: 'Órdenes de servicio', url: route('service-orders.index') },
    { label: `Orden #${props.serviceOrder.folio || props.serviceOrder.id}` }
]);

const isCancelled = computed(() => props.serviceOrder.status === 'cancelado');

const totalPaid = computed(() => {
    return props.serviceOrder.transaction?.payments.reduce((sum, payment) => sum + parseFloat(payment.amount), 0) || 0;
});

const amountDue = computed(() => {
    return parseFloat(props.serviceOrder.final_total) - totalPaid.value;
});

const technicianCommissionCostNumeric = computed(() => {
    if (!props.serviceOrder.technician_name || !props.serviceOrder.technician_commission_value) {
        return 0;
    }
    const value = parseFloat(props.serviceOrder.technician_commission_value);
    const subtotal = parseFloat(props.serviceOrder.subtotal);

    if (props.serviceOrder.technician_commission_type === 'percentage') {
        return (subtotal * value) / 100;
    } else if (props.serviceOrder.technician_commission_type === 'fixed') {
        return value;
    }
    return 0;
});

const cancelOrder = () => {
    confirm.require({
        message: 'Al cancelar la orden, su estatus cambiará a "Cancelado", no podrá seguir avanzando en el flujo y se regresará el stock de las refacciones registradas. ¿Continuar?',
        header: 'Confirmar cancelación',
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
        header: 'Confirmar eliminación',
        accept: () => {
            router.delete(route('service-orders.destroy', props.serviceOrder.id));
        }
    });
};

// --- LÓGICA DE MENÚ ACCIONES ---
const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const actionItems = computed(() => [
    { label: 'Crear nueva orden', icon: 'pi pi-plus', command: () => router.get(route('service-orders.create')), visible: hasPermission('services.orders.create') },
    { label: 'Editar orden', icon: 'pi pi-pencil', command: () => router.get(route('service-orders.edit', props.serviceOrder.id)), visible: hasPermission('services.orders.edit') },
    { label: 'Registrar diagnóstico y evidencia', icon: 'pi pi-file-edit', command: openDiagnosisModal, visible: !isCancelled.value && hasPermission('services.orders.edit') },
    { label: 'Registrar pago', icon: 'pi pi-dollar', command: openPaymentModal, visible: amountDue.value > 0.01 && props.serviceOrder.final_total > 0 },
    { label: 'Imprimir', icon: 'pi pi-print', command: openPrintModal },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteOrder, visible: hasPermission('services.orders.delete') },
]);

const getCustomFieldDefinition = (key) => {
    return props.customFieldDefinitions?.find(def => def.key === key);
};
</script>

<template>
    <AppLayout :title="`Orden de servicio #${serviceOrder.folio || serviceOrder.id}`">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">
                    Orden de Servicio #{{ serviceOrder.folio || serviceOrder.id }}
                </h1>
            </div>
            <div class="flex items-center gap-2 mt-4 sm:mt-0">
                <Button v-if="!isCancelled && hasPermission('services.orders.change_status')" @click="cancelOrder"
                    label="Cancelar orden" severity="danger" outlined />
                    
                <Button @click="toggleMenu" label="Acciones" icon="pi pi-chevron-down" iconPos="right" severity="secondary" outlined />
                <Menu ref="menu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- COMPONENTE: Stepper Dinámico -->
            <OrderStatusStepper 
                :service-order="serviceOrder"
                :amount-due="amountDue"
                @require-payment="openPaymentModal"
            />

            <!-- Columna Principal (Izquierda) -->
            <div class="lg:col-span-2 space-y-6">
                <!-- COMPONENTE: Resumen de Cliente y Orden -->
                <OrderSummaryPanel 
                    :service-order="serviceOrder"
                    :technician-commission-cost-numeric="technicianCommissionCostNumeric"
                />

                <!-- Campos Personalizados -->
                <div v-if="serviceOrder.custom_fields && Object.keys(serviceOrder.custom_fields).length > 0" class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles adicionales</h2>
                    <ul class="space-y-4 text-sm">
                        <li v-for="(value, key) in serviceOrder.custom_fields" :key="key">
                            <span class="font-semibold capitalize">{{ key.replace(/_/g, ' ') }}</span>
                            <div v-if="getCustomFieldDefinition(key)?.type === 'pattern'" class="mt-2">
                                <PatternLock :modelValue="value" :edit="false" />
                            </div>
                            <div v-else-if="getCustomFieldDefinition(key)?.type === 'select'">
                                <p class="text-gray-700 dark:text-gray-300">{{ value || 'N/A' }}</p>
                            </div>
                            <div v-else-if="getCustomFieldDefinition(key)?.type === 'checkbox'" class="flex flex-col gap-2 mt-2">
                                <div v-for="option in getCustomFieldDefinition(key)?.options" :key="option" class="flex items-center">
                                    <i :class="[value && value.includes(option) ? 'pi pi-check-circle text-green-500' : 'pi pi-times-circle text-red-500', 'mr-2']"></i>
                                    <span :class="{ 'font-medium': value && value.includes(option), 'text-gray-500': !value || !value.includes(option) }">{{ option }}</span>
                                </div>
                            </div>
                            <p v-else class="text-gray-700 dark:text-gray-300">{{ value === true ? 'Sí' : value === false ? 'No' : value || 'N/A' }}</p>
                        </li>
                    </ul>
                </div>

                <!-- COMPONENTE: Refacciones y Mano de Obra -->
                <OrderItemsPanel 
                    :service-order="serviceOrder" 
                    :is-cancelled="isCancelled" 
                />

                <!-- Detalles del equipo y fallas -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles del equipo y falla</h2>
                    <div>
                        <p class="font-semibold m-0">Descripción del equipo</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.item_description }}</p>
                    </div>
                    <div class="mt-4">
                        <p class="font-semibold m-0">Problemas reportados por el cliente</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.reported_problems }}</p>
                    </div>
                    <div v-if="serviceOrder.technician_diagnosis" class="mt-4 pt-4 border-t">
                        <p class="font-semibold m-0">Diagnóstico del técnico</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.technician_diagnosis }}</p>
                    </div>
                </div>

                <!-- Historial de actividad -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 mt-6">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                        <i class="pi pi-history text-gray-400"></i> Historial de movimientos
                    </h3>
                    <ActivityHistory :activities="activities" />
                </div>
            </div>

            <!-- Columna Secundaria (Derecha) -->
            <div class="lg:col-span-1 space-y-6">
                <!-- COMPONENTE: Paneles Financieros y Pagos -->
                <OrderFinancialPanel 
                    :service-order="serviceOrder"
                    :total-paid="totalPaid"
                    :amount-due="amountDue"
                    :technician-commission-cost-numeric="technicianCommissionCostNumeric"
                />

                <!-- COMPONENTE: Galería de Evidencias -->
                <OrderEvidencePanel 
                    :service-order="serviceOrder"
                />
            </div>
        </div>

        <!-- Modales -->
        <PaymentModal v-if="serviceOrder.transaction" v-model:visible="isPaymentModalVisible" :total-amount="amountDue"
            :client="serviceOrder.customer" :loading="isPaymentProcessing" payment-mode="flexible"
            @submit="handlePaymentSubmit" @update:visible="(val) => { if (!val) handlePaymentModalClosed(); }" />
            
        <PrintModal v-if="serviceOrder" v-model:visible="isPrintModalVisible"
            :data-source="{ type: 'service_order', id: serviceOrder.id }" :available-templates="availableTemplates"
            @hide="handlePrintModalClosed" />

        <Dialog v-model:visible="isDiagnosisModalVisible" modal header="Registrar Diagnóstico y Evidencia" :style="{ width: '40rem' }">
            <div class="p-fluid formgrid grid">
                <div class="field col-12 mb-4">
                    <label for="technician_diagnosis" class="font-semibold">Diagnóstico del técnico</label>
                    <Textarea id="technician_diagnosis" v-model="diagnosisForm.technician_diagnosis" rows="5"
                        class="w-full mt-2" :class="{ 'p-invalid': diagnosisForm.errors.technician_diagnosis }" />
                    <small class="p-error" v-if="diagnosisForm.errors.technician_diagnosis">{{ diagnosisForm.errors.technician_diagnosis }}</small>
                </div>

                <div class="field col-12">
                    <label class="font-semibold mb-2 block">Evidencia de cierre (Máx. 5 imágenes)</label>
                    <FileUpload name="closing_evidence_images[]" @select="onSelectClosingImages"
                        @remove="onRemoveClosingImage" :multiple="true" :show-upload-button="false" accept="image/*"
                        :maxFileSize="10000000" :fileLimit="MAX_CLOSING_EVIDENCE_IMAGES"
                        :invalidFileSizeMessage="'{0}: El tamaño del archivo excede el límite de 10MB.'"
                        :invalidFileLimitMessage="'Máximo {0} archivos permitidos.'">
                        <template #empty>
                            <p>Arrastra y suelta las imágenes finales del servicio aquí.</p>
                        </template>
                    </FileUpload>
                    <small class="p-error" v-if="diagnosisForm.errors.closing_evidence_images">{{ diagnosisForm.errors.closing_evidence_images }}</small>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text @click="isDiagnosisModalVisible = false" />
                <Button label="Guardar diagnóstico" @click="handleDiagnosisSubmit" :loading="diagnosisForm.processing" />
            </template>
        </Dialog>
    </AppLayout>
</template>