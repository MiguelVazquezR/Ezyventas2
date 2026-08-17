<script setup>
import { ref, computed, watch, provide } from 'vue';
import { router, usePage, useForm, Link } from '@inertiajs/vue3';
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
    printTemplates: Array,
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

// --- LÓGICA DE SELECCIÓN DE PLANTILLA DE IMPRESIÓN (PDF) ---
const showTemplateDialog = ref(false);
const selectedTemplate = ref(null);

const handlePrintAction = () => {
    if (props.printTemplates && props.printTemplates.length > 0) {
        selectedTemplate.value = null;
        showTemplateDialog.value = true;
    } else {
        openPrintWindow();
    }
};

const openPrintWindow = () => {
    const url = route('service-orders.print', {
        serviceOrder: props.serviceOrder.id,
        template_id: selectedTemplate.value,
    });
    window.open(url, '_blank');
    showTemplateDialog.value = false;
};

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

// --- FACTURACIÓN (CFDI) ---
// La venta generada por esta orden es facturable si está completada, pagada
// en su totalidad y aún no tiene factura.
const canInvoice = computed(() => {
    const t = props.serviceOrder.transaction;
    if (!t) return false;
    if (!['completado', 'entregado_por_pagar'].includes(t.status)) return false;
    if (t.invoiced) return false;
    return (parseFloat(t.remaining_due ?? t.total ?? 0) <= 0.01);
});

// Navega a la creación de factura con la venta pre-seleccionada para que
// el formulario se llene automáticamente con los datos de la orden.
const goToInvoice = () => {
    router.get(route('billing.invoices.create', { transaction: props.serviceOrder.transaction.id }));
};

// Una orden ya facturada muestra un enlace a su factura: "Ver factura" si ya
// fue timbrada (certificada) o "Ver prefactura" si aún es borrador/pendiente.
const PREFACTURA_STATUSES = ['borrador', 'pendiente'];
const invoiceLinkInfo = (invoice) => {
    if (!invoice) return null;
    const isPrefactura = PREFACTURA_STATUSES.includes(invoice?.status);
    return {
        label: isPrefactura ? 'Ver prefactura' : 'Ver factura',
        icon: isPrefactura ? 'pi pi-file-edit' : 'pi pi-file-pdf',
    };
};
const goToInvoiceShow = (invoice) => {
    if (!invoice?.id) return;
    router.get(route('billing.invoices.show', invoice.id));
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
    { label: 'Facturar venta', icon: 'pi pi-file-edit', command: goToInvoice, visible: hasPermission('invoices.create') && canInvoice.value },
    {
        label: invoiceLinkInfo(props.serviceOrder.transaction?.invoice)?.label ?? 'Ver factura',
        icon: invoiceLinkInfo(props.serviceOrder.transaction?.invoice)?.icon ?? 'pi pi-file-pdf',
        command: () => goToInvoiceShow(props.serviceOrder.transaction?.invoice),
        visible: !!props.serviceOrder.transaction?.invoice
    },
    { label: 'Ver PDF / Imprimir', icon: 'pi pi-print', command: handlePrintAction },
    { label: 'Imprimir', icon: 'pi pi-print', command: openPrintModal },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteOrder, visible: hasPermission('services.orders.delete') },
]);

const getCustomFieldDefinition = (key) => {
    return props.customFieldDefinitions?.find(def => def.key === key);
};

const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';

const getStatusSeverity = (status) => {
    if (!status) return 'secondary';
    const map = {
        cancelado: 'danger',
        pendiente: 'warn',
        en_progreso: 'info',
        esperando_refaccion: 'secondary',
        terminado: 'success',
        entregado: 'success',
    };
    return map[status] || 'secondary';
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } // Sin blur
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <AppLayout :title="`Orden #${serviceOrder.folio || serviceOrder.id}`">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('service-orders.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al historial de órdenes
                </Link>
            </div>

            <!-- Header Principal -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-4">
                        Orden de Servicio #{{ serviceOrder.folio || serviceOrder.id }}
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <Tag :value="(serviceOrder.status || '').replace('_', ' ')" :severity="getStatusSeverity(serviceOrder.status)" :pt="tagPt" />
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Recepción:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                <i class="pi pi-calendar-plus !text-[10px] text-gray-400"></i>
                                {{ formatDate(serviceOrder.received_at) }}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="w-full sm:w-auto shrink-0 flex gap-2">
                    <Button v-if="!isCancelled && hasPermission('services.orders.change_status')" @click="cancelOrder"
                        label="Cancelar orden" severity="danger" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                        
                    <Button type="button" label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                    <Menu ref="menu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- COMPONENTE: Stepper Dinámico (Adaptaremos luego si es necesario) -->
                <OrderStatusStepper 
                    :service-order="serviceOrder"
                    :amount-due="amountDue"
                    @require-payment="openPaymentModal"
                />

                <!-- Columna Principal (Izquierda) -->
                <div class="lg:col-span-2 space-y-6 lg:space-y-8 flex flex-col">
                    <!-- COMPONENTE: Resumen de Cliente y Orden -->
                    <OrderSummaryPanel 
                        :service-order="serviceOrder"
                        :technician-commission-cost-numeric="technicianCommissionCostNumeric"
                    />

                    <!-- Campos Personalizados -->
                    <div v-if="serviceOrder.custom_fields && Object.keys(serviceOrder.custom_fields).length > 0" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                                <i class="pi pi-list !text-sm text-purple-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Detalles adicionales</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Información personalizada del servicio</p>
                            </div>
                        </div>

                        <ul class="m-0 p-0 list-none space-y-4">
                            <li v-for="(value, key) in serviceOrder.custom_fields" :key="key" class="border-b border-gray-100 dark:border-[#2a2a2a] pb-4 last:border-0 last:pb-0">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 block mb-2">{{ key.replace(/_/g, ' ') }}</span>
                                
                                <div v-if="getCustomFieldDefinition(key)?.type === 'pattern'" class="mt-2 bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-xl border border-gray-200 dark:border-[#3a3a3a] inline-block">
                                    <PatternLock :modelValue="value" :edit="false" />
                                </div>
                                <div v-else-if="getCustomFieldDefinition(key)?.type === 'select'">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ value || 'N/A' }}</p>
                                </div>
                                <div v-else-if="getCustomFieldDefinition(key)?.type === 'checkbox'" class="flex flex-col gap-2 mt-2 bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-xl border border-gray-200 dark:border-[#3a3a3a]">
                                    <div v-for="option in getCustomFieldDefinition(key)?.options" :key="option" class="flex items-center gap-2">
                                        <i :class="[value && value.includes(option) ? 'pi pi-check-circle text-green-500' : 'pi pi-times-circle text-gray-400 dark:text-gray-600', '!text-sm']"></i>
                                        <span class="text-sm m-0" :class="{ 'font-medium text-gray-900 dark:text-white': value && value.includes(option), 'text-gray-500 line-through': !value || !value.includes(option) }">{{ option }}</span>
                                    </div>
                                </div>
                                <p v-else class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ value === true ? 'Sí' : value === false ? 'No' : value || 'N/A' }}</p>
                            </li>
                        </ul>
                    </div>

                    <!-- COMPONENTE: Refacciones y Mano de Obra -->
                    <OrderItemsPanel 
                        :service-order="serviceOrder" 
                        :is-cancelled="isCancelled" 
                    />

                    <!-- Detalles del equipo y fallas -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                                <i class="pi pi-box !text-sm text-orange-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Detalles del equipo y falla</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Reporte inicial y diagnóstico</p>
                            </div>
                        </div>

                        <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden">
                            <div class="p-5 border-b border-gray-200 dark:border-[#2a2a2a]">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 block mb-2">Descripción del equipo</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ serviceOrder.item_description }}</p>
                            </div>
                            
                            <div class="p-5 border-b border-gray-200 dark:border-[#2a2a2a] bg-orange-50/50 dark:bg-orange-900/5">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-orange-600 dark:text-orange-500 m-0 block mb-2 flex items-center gap-1.5"><i class="pi pi-exclamation-triangle !text-[9px]"></i> Problemas reportados por el cliente</span>
                                <p class="text-sm text-gray-800 dark:text-gray-200 m-0 leading-relaxed italic">{{ serviceOrder.reported_problems }}</p>
                            </div>
                            
                            <div v-if="serviceOrder.technician_diagnosis" class="p-5 bg-blue-50/50 dark:bg-blue-900/5">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-blue-600 dark:text-blue-500 m-0 block mb-2 flex items-center gap-1.5"><i class="pi pi-file-edit !text-[9px]"></i> Diagnóstico del técnico</span>
                                <p class="text-sm text-gray-800 dark:text-gray-200 m-0 leading-relaxed">{{ serviceOrder.technician_diagnosis }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Historial de actividad -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center flex-shrink-0 border border-gray-200 dark:border-[#3a3a3a]">
                                <i class="pi pi-history !text-sm text-gray-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Historial de movimientos</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Auditoría y registro de cambios</p>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 lg:p-6 overflow-hidden">
                            <ActivityHistory :activities="activities" />
                        </div>
                    </div>
                </div>

                <!-- Columna Secundaria (Derecha) -->
                <div class="lg:col-span-1 space-y-6 lg:space-y-8 flex flex-col">
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
        </div>

        <!-- Modales -->
        <PaymentModal v-if="serviceOrder.transaction" v-model:visible="isPaymentModalVisible" :total-amount="amountDue"
            :client="serviceOrder.customer" :loading="isPaymentProcessing" payment-mode="flexible"
            @submit="handlePaymentSubmit" @update:visible="(val) => { if (!val) handlePaymentModalClosed(); }" />
            
        <PrintModal v-if="serviceOrder" v-model:visible="isPrintModalVisible"
            :data-source="{ type: 'service_order', id: serviceOrder.id }" :available-templates="availableTemplates"
            @hide="handlePrintModalClosed" />

        <Dialog v-model:visible="isDiagnosisModalVisible" modal class="w-full max-w-lg mx-4" :pt="dialogPt">
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-file-edit !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Registrar diagnóstico</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                            Notas del técnico y evidencia
                        </p>
                    </div>
                </div>
            </template>
            
            <div class="flex flex-col gap-5 pt-2">
                <div class="flex flex-col">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Diagnóstico del técnico</label>
                    <Textarea v-model="diagnosisForm.technician_diagnosis" rows="5" placeholder="Detalla el problema encontrado y las acciones a tomar..." :pt="textareaPt" :class="{ '!border-red-500': diagnosisForm.errors.technician_diagnosis }" />
                    <span class="text-xs text-red-500 font-medium mt-1.5" v-if="diagnosisForm.errors.technician_diagnosis">{{ diagnosisForm.errors.technician_diagnosis }}</span>
                </div>

                <div class="flex flex-col">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Evidencia de cierre (Máx. 5 imágenes)</label>
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-xl border border-gray-200 dark:border-[#3a3a3a] p-2">
                        <FileUpload name="closing_evidence_images[]" @select="onSelectClosingImages"
                            @remove="onRemoveClosingImage" :multiple="true" :show-upload-button="false" accept="image/*"
                            :maxFileSize="10000000" :fileLimit="MAX_CLOSING_EVIDENCE_IMAGES"
                            :invalidFileSizeMessage="'{0}: El tamaño del archivo excede el límite de 10MB.'"
                            :invalidFileLimitMessage="'Máximo {0} archivos permitidos.'"
                            class="!border-none !bg-transparent"
                            :pt="{
                                content: { class: '!bg-transparent !p-4' },
                                chooseButton: { class: '!bg-blue-50 dark:!bg-blue-900/30 !text-blue-600 dark:!text-blue-400 !border-none !rounded-xl !text-xs uppercase font-bold tracking-wider hover:!bg-blue-100 dark:hover:!bg-blue-900/50 transition-colors' },
                                empty: { class: '!flex !items-center !justify-center !text-center !p-6 !text-gray-500 !italic !text-sm' }
                            }">
                            <template #empty>
                                <div class="flex flex-col items-center">
                                    <i class="pi pi-images !text-3xl text-gray-400 mb-2"></i>
                                    <p class="m-0">Arrastra y suelta las imágenes finales del servicio aquí.</p>
                                </div>
                            </template>
                        </FileUpload>
                    </div>
                    <span class="text-xs text-red-500 font-medium mt-1.5" v-if="diagnosisForm.errors.closing_evidence_images">{{ diagnosisForm.errors.closing_evidence_images }}</span>
                </div>
            </div>
            
            <template #footer>
                <div class="flex justify-end items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button label="Cancelar" text @click="isDiagnosisModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button label="Guardar diagnóstico" @click="handleDiagnosisSubmit" :loading="diagnosisForm.processing" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" severity="primary" />
                </div>
            </template>
        </Dialog>

        <!-- MODAL DE SELECCIÓN DE PLANTILLA PDF (Ver PDF / Imprimir) -->
        <Dialog v-model:visible="showTemplateDialog" modal class="w-full max-w-md mx-4" :pt="dialogPt">
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-print !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Formato de impresión</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Selecciona la plantilla PDF</p>
                    </div>
                </div>
            </template>

            <div class="flex flex-col gap-3 pt-2">
                <!-- Opción Default -->
                <div @click="selectedTemplate = null"
                    class="p-4 rounded-2xl border transition-colors cursor-pointer group"
                    :class="selectedTemplate === null ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center mb-1">
                        <RadioButton v-model="selectedTemplate" :value="null" class="pointer-events-none" />
                        <label class="ml-3 font-medium text-sm text-gray-900 dark:text-white cursor-pointer m-0">Estándar del sistema</label>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 ml-8 m-0">Formato limpio y simple generado por defecto.</p>
                </div>

                <!-- Plantillas Personalizadas -->
                <div v-for="tpl in printTemplates" :key="tpl.id" @click="selectedTemplate = tpl.id"
                    class="p-4 rounded-2xl border transition-colors cursor-pointer group"
                    :class="selectedTemplate === tpl.id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center mb-1">
                        <RadioButton v-model="selectedTemplate" :value="tpl.id" class="pointer-events-none" />
                        <label class="ml-3 font-medium text-sm text-gray-900 dark:text-white cursor-pointer m-0">{{ tpl.name }}</label>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 ml-8 m-0">Plantilla personalizada para recibo de servicio.</p>
                </div>

                <div v-if="!printTemplates || printTemplates.length === 0" class="text-center py-4 text-gray-400 text-xs">
                    No hay plantillas personalizadas registradas para recibos de servicio.
                </div>
            </div>

            <template #footer>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Link :href="route('print-templates.create', { type: 'recibo_servicio' })" class="text-[10px] text-primary-500 uppercase tracking-widest font-bold flex items-center gap-1 hover:underline order-2 sm:order-1">
                        <i class="pi pi-plus !text-[9px]"></i> Crear diseño nuevo
                    </Link>
                    <div class="flex justify-end gap-3 order-1 sm:order-2 w-full sm:w-auto">
                        <Button label="Cancelar" text @click="showTemplateDialog = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                        <Button label="Generar PDF" icon="pi pi-file-pdf" @click="openPrintWindow" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" severity="primary" />
                    </div>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>