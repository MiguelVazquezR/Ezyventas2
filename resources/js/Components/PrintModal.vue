<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import { router } from '@inertiajs/vue3';
import InputLabel from './InputLabel.vue';

// --- Importar Composables ---
import { usePrintPlugin } from '@/Composables/usePrintPlugin';
import { useBluetoothPrinter } from '@/Composables/useBluetoothPrinter';
import { useWhatsAppTicket } from '@/Composables/useWhatsAppTicket';

// --- Props y Emits ---
const props = defineProps({
    visible: Boolean,
    dataSource: Object,
    availableTemplates: Array,
});
const emit = defineEmits(['update:visible']);

// --- Detección de dispositivo móvil ---
const isMobile = computed(() => {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
        || ('ontouchstart' in window && window.innerWidth < 1024);
});

// --- Instanciar Composables ---
const toast = useToast();

// Plugin (escritorio)
const {
    printers: pluginPrinters,
    selectedTicketPrinter,
    selectedLabelPrinter,
    isLoadingPrinters: isLoadingPluginPrinters,
    pluginError,
    fetchPrinters: fetchPluginPrinters,
    sendToPlugin,
} = usePrintPlugin();

// Bluetooth
const {
    device: btDevice,
    isScanning: btScanning,
    isConnecting: btConnecting,
    error: btError,
    isSupported: btSupported,
    isSecure: btSecure,
    isConnected: btIsConnected,
    scanAndConnect: btScanAndConnect,
    sendRawCommands: btSendRaw,
    disconnect: btDisconnect,
} = useBluetoothPrinter();

// WhatsApp (ticket por wa.me)
const {
    enviarTicketWhatsApp,
} = useWhatsAppTicket();

// --- Estado de envío por WhatsApp ---
const isWhatsAppModalVisible = ref(false);
const isSendingWhatsApp = ref(false);
const isSavingWhatsAppPhone = ref(false);
const whatsappTicketData = ref(null);
const whatsappCustomerId = ref(null);
const whatsappPhone = ref('');
const whatsappPhoneError = ref(null);

const canSendWhatsApp = computed(() => {
    return props.dataSource && ['pos', 'transaction'].includes(props.dataSource.type);
});

const hasWhatsAppCustomer = computed(() => !!whatsappCustomerId.value);

// --- Enviar ticket por WhatsApp ---
const handleSendWhatsApp = async () => {
    if (!props.dataSource) return;

    isSendingWhatsApp.value = true;
    whatsappPhoneError.value = null;

    try {
        const response = await axios.post(route('print.whatsapp-ticket'), {
            data_source_type: props.dataSource.type,
            data_source_id: props.dataSource.id,
        });

        const { ticket, customer_phone, customer_id } = response.data;

        if (!ticket) {
            toast.add({
                severity: 'warn',
                summary: 'Sin datos',
                detail: 'No se encontró una venta para enviar por WhatsApp.',
                life: 4000,
            });
            return;
        }

        whatsappTicketData.value = ticket;
        whatsappCustomerId.value = customer_id;

        if (customer_phone) {
            openWhatsAppWithTicket(customer_phone);
        } else {
            whatsappPhone.value = '';
            isWhatsAppModalVisible.value = true;
        }
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'No se pudo generar el ticket para WhatsApp.',
            life: 5000,
        });
    } finally {
        isSendingWhatsApp.value = false;
    }
};

const openWhatsAppWithTicket = (phone) => {
    const win = enviarTicketWhatsApp(phone, whatsappTicketData.value);
    if (!win) {
        toast.add({
            severity: 'warn',
            summary: 'Ventana bloqueada',
            detail: 'Permite las ventanas emergentes para abrir WhatsApp.',
            life: 5000,
        });
    }
};

const submitWhatsAppPhone = async () => {
    if (!whatsappPhone.value.replace(/\D/g, '')) {
        whatsappPhoneError.value = 'Ingresa un número de teléfono.';
        return;
    }

    isSavingWhatsAppPhone.value = true;
    whatsappPhoneError.value = null;

    try {
        if (hasWhatsAppCustomer.value) {
            await axios.post(route('customers.phone', whatsappCustomerId.value), {
                phone: whatsappPhone.value,
            });
        }

        isWhatsAppModalVisible.value = false;
        openWhatsAppWithTicket(whatsappPhone.value);
    } catch (e) {
        whatsappPhoneError.value = e.response?.data?.message || 'No se pudo guardar el teléfono.';
    } finally {
        isSavingWhatsAppPhone.value = false;
    }
};

// --- Estado del Modal ---
const printJobs = ref([]);
const isPrinting = ref(false);
const generalError = ref(null);
const openDrawer = ref(true);

// Modo de impresión: 'plugin' | 'bluetooth'
const printMode = ref(isMobile.value ? 'bluetooth' : 'plugin');

// Estado de preview/AirPrint (fallback móvil)
const showPreview = ref(false);
const ticketHtml = ref('');
const previewLoading = ref(false);

// ¿Necesita fallback? (móvil sin BT)
const needsBtFallback = computed(() => isMobile.value && (!btSupported.value || !btSecure.value));

// ¿Plugin no disponible? (escritorio sin plugin corriendo)
const pluginUnavailable = computed(() => {
    return !isMobile.value && !isLoadingPluginPrinters.value && pluginPrinters.value.length === 0 && !pluginError.value;
});

// --- Cargar impresoras al montar ---
onMounted(() => {
    if (!isMobile.value) {
        fetchPluginPrinters();
    }
});

// --- Lógica de Trabajos ---
const addJob = (template) => {
    if (printJobs.value.some(job => job.template.id === template.id)) return;
    printJobs.value.push({
        id: `job-${Date.now()}-${Math.random()}`,
        template,
        copies: 1,
    });
};
const hasTicketJobs = computed(() => printJobs.value.some(job => job.template.type === 'ticket_venta'));
const hasLabelJobs = computed(() => printJobs.value.some(job => job.template.type === 'etiqueta'));

const addedTemplateIds = computed(() => {
    return new Set(printJobs.value.map(job => job.template.id));
});

// --- Lógica de Offsets (solo plugin) ---
const labelOffsetX = ref(0.0);
const labelOffsetY = ref(0.0);

const loadOffsetsForPrinter = (printerName) => {
    if (!printerName) {
        labelOffsetX.value = 0.0;
        labelOffsetY.value = 0.0;
        return;
    }
    const offsetKey = `printer_offset_${printerName}`;
    const savedOffsets = localStorage.getItem(offsetKey);
    if (savedOffsets) {
        try {
            const offsets = JSON.parse(savedOffsets);
            labelOffsetX.value = offsets.x || 0.0;
            labelOffsetY.value = offsets.y || 0.0;
        } catch (e) {
            labelOffsetX.value = 0.0;
            labelOffsetY.value = 0.0;
        }
    } else {
        labelOffsetX.value = 0.0;
        labelOffsetY.value = 0.0;
    }
};

const saveCurrentLabelOffsets = () => {
    if (selectedLabelPrinter.value) {
        const offsetKey = `printer_offset_${selectedLabelPrinter.value}`;
        localStorage.setItem(offsetKey, JSON.stringify({ x: labelOffsetX.value, y: labelOffsetY.value }));
    }
};

watch(labelOffsetX, saveCurrentLabelOffsets);
watch(labelOffsetY, saveCurrentLabelOffsets);
watch(selectedLabelPrinter, (newPrinterName) => {
    loadOffsetsForPrinter(newPrinterName);
});

// --- Lógica Principal de Impresión ---
const canPrint = computed(() => {
    if (printJobs.value.length === 0) return false;

    if (printMode.value === 'bluetooth') {
        if (needsBtFallback.value) return true; // Siempre se puede previsualizar
        return btIsConnected();
    }

    // Modo plugin
    const ticketsOk = !hasTicketJobs.value || (hasTicketJobs.value && !!selectedTicketPrinter.value);
    const labelsOk = !hasLabelJobs.value || (hasLabelJobs.value && !!selectedLabelPrinter.value);
    return ticketsOk && labelsOk;
});

// --- Cargar HTML del ticket para vista previa (fallback móvil) ---
const loadTicketHtml = async () => {
    if (printJobs.value.length === 0) return;

    previewLoading.value = true;
    ticketHtml.value = '';

    try {
        const job = printJobs.value[0];
        const response = await axios.post(route('print.ticket-html'), {
            template_id: job.template.id,
            data_source_type: props.dataSource.type,
            data_source_id: props.dataSource.id,
        });
        ticketHtml.value = response.data.html;
        showPreview.value = true;
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'No se pudo generar la vista previa del ticket.',
            life: 5000,
        });
    } finally {
        previewLoading.value = false;
    }
};

// --- AirPrint (window.print) ---
const printViaAirPrint = () => {
    if (!ticketHtml.value) return;

    const printWindow = window.open('', '_blank', 'width=320,height=600');
    if (!printWindow) {
        toast.add({
            severity: 'warn',
            summary: 'Ventana bloqueada',
            detail: 'Permite las ventanas emergentes para imprimir el ticket.',
            life: 5000,
        });
        return;
    }

    printWindow.document.write(ticketHtml.value);
    printWindow.document.close();
    printWindow.focus();

    printWindow.onload = () => {
        printWindow.print();
        printWindow.onafterprint = () => printWindow.close();
    };

    setTimeout(() => {
        printWindow.print();
        printWindow.onafterprint = () => printWindow.close();
    }, 800);
};

// --- Descargar HTML ---
const downloadHtml = () => {
    if (!ticketHtml.value) return;

    const blob = new Blob([ticketHtml.value], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `ticket-${props.dataSource.type}-${props.dataSource.id}.html`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    toast.add({
        severity: 'info',
        summary: 'Descargado',
        detail: 'El ticket se descargó como archivo HTML. Ábrelo e imprime con Ctrl+P o Compartir → Imprimir.',
        life: 5000,
    });
};

// --- Impresión vía Bluetooth ---
const printBluetooth = async () => {
    isPrinting.value = true;
    generalError.value = null;

    for (const job of printJobs.value) {
        for (let i = 0; i < job.copies; i++) {
            try {
                const response = await axios.post(route('print.bluetooth-payload'), {
                    template_id: job.template.id,
                    data_source_type: props.dataSource.type,
                    data_source_id: props.dataSource.id,
                    open_drawer: openDrawer.value,
                });

                const { commands_base64 } = response.data;

                if (!commands_base64 || commands_base64.length < 10) {
                    toast.add({
                        severity: 'warn',
                        summary: 'Ticket vacío',
                        detail: 'La plantilla no generó contenido para imprimir.',
                        life: 4000,
                    });
                    continue;
                }

                await btSendRaw(commands_base64);
            } catch (e) {
                const msg = e.response?.data?.message || e.message || 'Error desconocido';
                generalError.value = `Error imprimiendo "${job.template.name}": ${msg}`;
                toast.add({ severity: 'error', summary: 'Error de impresión', detail: generalError.value, life: 7000 });
                isPrinting.value = false;
                return;
            }
        }
    }

    isPrinting.value = false;
    toast.add({ severity: 'success', summary: 'Impresión completada', detail: 'El ticket se envió a la impresora.', life: 3000 });
    closeModal();
};

// --- Impresión vía Plugin (escritorio) ---
const printViaPlugin = async () => {
    isPrinting.value = true;
    generalError.value = null;
    pluginError.value = null;

    for (const job of printJobs.value) {
        const printerIdentifier = job.template.type === 'ticket_venta'
            ? selectedTicketPrinter.value
            : selectedLabelPrinter.value;

        for (let i = 0; i < job.copies; i++) {
            try {
                const payload = {
                    template_id: job.template.id,
                    data_source_type: props.dataSource.type,
                    data_source_id: props.dataSource.id,
                };

                if (job.template.type === 'etiqueta') {
                    payload.offset_x = labelOffsetX.value;
                    payload.offset_y = labelOffsetY.value;
                }

                if (job.template.type === 'ticket_venta') {
                    payload.open_drawer = openDrawer.value;
                }

                const payloadResponse = await axios.post(route('print.payload'), payload);
                const printData = payloadResponse.data;

                await sendToPlugin(printerIdentifier, printData.operations, printData.paperWidth);

            } catch (e) {
                pluginError.value = e.message;
                generalError.value = `Error imprimiendo "${job.template.name}": ${e.message}`;
                toast.add({ severity: 'error', summary: 'Error de Impresión', detail: generalError.value, life: 7000 });
                isPrinting.value = false;
                return;
            }
        }
        if (generalError.value) break;
    }

    isPrinting.value = false;
    if (!generalError.value) {
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Trabajos de impresión enviados.', life: 3000 });
        closeModal();
    }
};

// --- Acción principal de impresión (router) ---
const print = async () => {
    if (printMode.value === 'bluetooth') {
        await printBluetooth();
    } else {
        await printViaPlugin();
    }
};

// --- Cambiar modo de impresión ---
const switchToBluetooth = () => {
    printMode.value = 'bluetooth';
};

const switchToPlugin = () => {
    printMode.value = 'plugin';
    fetchPluginPrinters();
};

// --- Cerrar Modal ---
const closeModal = () => {
    emit('update:visible', false);
    printJobs.value = [];
    openDrawer.value = false;
    showPreview.value = false;
    ticketHtml.value = '';
    generalError.value = null;
    isWhatsAppModalVisible.value = false;
    whatsappPhoneError.value = null;
};

// --- Auto-selección de plantillas ---
const evaluateAutoSelection = () => {
    if (!props.visible) return;
    if (printJobs.value.length > 0) return;

    if (props.availableTemplates && props.availableTemplates.length > 0) {
        if (props.availableTemplates.length === 1) {
            addJob(props.availableTemplates[0]);
        } else {
            const autoSelectTemplates = props.availableTemplates.filter(t => t.is_default || t.is_default === 1);
            if (autoSelectTemplates.length > 0) {
                autoSelectTemplates.forEach(t => addJob(t));
            }
        }
    }
};

// --- Toggle de Plantilla Default ---
const toggleDefaultTemplate = (template) => {
    router.patch(route('print-templates.toggle-default', template.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const isNowDefault = !template.is_default;
            toast.add({
                severity: 'success',
                summary: 'Configuración actualizada',
                detail: isNowDefault ? 'Plantilla marcada para auto-selección' : 'Se removió la auto-selección',
                life: 2000
            });
        },
        onError: () => {
            toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar la plantilla', life: 3000 });
        }
    });
};

// --- Watchers ---
watch(() => props.visible, (newVal) => {
    if (newVal) {
        generalError.value = null;
        pluginError.value = null;
        btError.value = null;
        openDrawer.value = false;
        showPreview.value = false;
        ticketHtml.value = '';

        // En móvil, forzar Bluetooth; en escritorio, plugin por defecto
        printMode.value = isMobile.value ? 'bluetooth' : 'plugin';

        evaluateAutoSelection();

        if (!isMobile.value) {
            fetchPluginPrinters().then(() => {
                loadOffsetsForPrinter(selectedLabelPrinter.value);
            });
        }
    }
}, { immediate: true });

watch(() => props.availableTemplates, () => {
    evaluateAutoSelection();
}, { deep: true, immediate: true });

// --- Helpers ---
const getTemplateTypeText = (type) => {
    if (type === 'ticket_venta') return 'Ticket';
    if (type === 'etiqueta') return 'Etiqueta';
    return type;
};
const getTemplateTypeSeverity = (type) => {
    if (type === 'ticket_venta') return 'info';
    if (type === 'etiqueta') return 'warning';
    return 'secondary';
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Configurar Impresión"
        :style="{ width: '55rem' }" :breakpoints="{ '960px': '75vw', '640px': '95vw' }">

        <!-- ===== VISTA PREVIA (FALLBACK MÓVIL) ===== -->
        <div v-if="showPreview" class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold m-0 text-gray-800 dark:text-gray-200">
                    Vista previa del ticket
                </h3>
                <div class="flex gap-2">
                    <Button
                        label="Volver"
                        icon="pi pi-arrow-left"
                        severity="secondary"
                        text
                        size="small"
                        @click="showPreview = false"
                    />
                    <Button
                        label="AirPrint"
                        icon="pi pi-print"
                        severity="warning"
                        size="small"
                        @click="printViaAirPrint"
                    />
                    <Button
                        label="Descargar"
                        icon="pi pi-download"
                        severity="help"
                        size="small"
                        @click="downloadHtml"
                    />
                </div>
            </div>
            <div class="bg-white border border-gray-200 rounded-2xl p-4 overflow-auto max-h-[60vh]">
                <iframe
                    :srcdoc="ticketHtml"
                    class="w-full border-0"
                    style="min-height: 400px;"
                    sandbox="allow-same-origin"
                />
            </div>
        </div>

        <!-- ===== CONFIGURACIÓN PRINCIPAL ===== -->
        <div v-else class="p-4 space-y-4 dark:bg-[#232323] rounded-2xl">

            <!-- Sección 1: Plantillas -->
            <Fieldset legend="1. Selecciona plantillas y copias" :toggleable="false">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2 block">
                            Plantillas disponibles
                        </label>
                        <p v-if="availableTemplates.length === 0" class="text-sm text-gray-500">
                            No hay plantillas disponibles.
                        </p>
                        <Listbox
                            v-else
                            :options="availableTemplates"
                            optionLabel="name"
                            class="w-full border-none !shadow-none"
                            listStyle="max-height: 200px"
                        >
                            <template #option="slotProps">
                                <div
                                    class="flex justify-between items-center w-full p-2 rounded transition-colors"
                                    :class="{
                                        'cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700': !addedTemplateIds.has(slotProps.option.id),
                                        'opacity-60 cursor-not-allowed': addedTemplateIds.has(slotProps.option.id),
                                    }"
                                    @click="!addedTemplateIds.has(slotProps.option.id) && addJob(slotProps.option)"
                                >
                                    <div class="flex items-center gap-2 min-w-0">
                                        <i
                                            :class="addedTemplateIds.has(slotProps.option.id)
                                                ? 'pi pi-check-circle text-green-600'
                                                : 'pi pi-plus-circle text-blue-600'"
                                        />
                                        <span class="truncate text-sm">{{ slotProps.option.name }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <Tag
                                            :value="getTemplateTypeText(slotProps.option.type)"
                                            :severity="getTemplateTypeSeverity(slotProps.option.type)"
                                            class="text-[10px]"
                                        />
                                        <Button
                                            :icon="slotProps.option.is_default ? 'pi pi-star-fill' : 'pi pi-star'"
                                            class="!w-7 !h-7 !p-0"
                                            :class="slotProps.option.is_default
                                                ? 'text-yellow-500 hover:text-yellow-600'
                                                : 'text-gray-300 hover:text-yellow-400'"
                                            text
                                            rounded
                                            @click.stop="toggleDefaultTemplate(slotProps.option)"
                                            v-tooltip.top="slotProps.option.is_default
                                                ? 'Quitar selección automática'
                                                : 'Marcar para auto-selección'"
                                        />
                                    </div>
                                </div>
                            </template>
                        </Listbox>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2 block">
                            Trabajos de impresión añadidos
                        </label>
                        <div
                            v-if="printJobs.length === 0"
                            class="text-center text-gray-500 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-2xl p-8 flex flex-col items-center justify-center"
                        >
                            <i class="pi pi-print !text-2xl mb-3 text-gray-400" />
                            <p class="text-sm">Selecciona plantillas para añadirlas aquí.</p>
                        </div>
                        <div v-else class="space-y-2 max-h-60 overflow-y-auto pr-2">
                            <div
                                v-for="(job, index) in printJobs"
                                :key="job.id"
                                class="relative flex items-center justify-between p-2 pt-5 bg-gray-100 dark:bg-[#1a1a1a] rounded-2xl shadow-sm gap-4"
                            >
                                <Tag
                                    :value="getTemplateTypeText(job.template.type)"
                                    :severity="getTemplateTypeSeverity(job.template.type)"
                                    class="text-xs flex-shrink-0 !absolute top-0 left-1"
                                />
                                <span class="text-sm font-medium truncate">{{ job.template.name }}</span>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <label class="text-xs text-gray-600 dark:text-gray-400 hidden sm:inline">
                                        Copias:
                                    </label>
                                    <InputNumber
                                        v-model="job.copies"
                                        :min="1"
                                        :max="10"
                                        mode="decimal"
                                        showButtons
                                        :inputStyle="{ width: '4.8rem', textAlign: 'start', height: '2.2rem' }"
                                        size="small"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        severity="danger"
                                        text
                                        rounded
                                        size="small"
                                        class="w-8 h-8"
                                        @click="printJobs.splice(index, 1)"
                                        v-tooltip.right="'Quitar trabajo'"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Fieldset>

            <!-- Sección 2: Impresora -->
            <Fieldset legend="2. Selecciona impresora" :toggleable="false" :disabled="printJobs.length === 0">
                <template #legend>
                    <div class="flex items-center gap-2">
                        <span>2. Selecciona impresora</span>
                        <i v-if="printJobs.length === 0" class="pi pi-info-circle text-gray-400"
                            v-tooltip.top="'Añade plantillas primero'"></i>
                    </div>
                </template>

                <!-- ===== MODO MÓVIL: SOLO BLUETOOTH ===== -->
                <div v-if="isMobile" class="space-y-3">
                    <!-- Bluetooth soportado y conectado -->
                    <div v-if="btIsConnected()" class="flex items-center justify-between p-3 bg-green-50 dark:bg-green-900/30 rounded-2xl border border-green-200 dark:border-green-700">
                        <div class="flex items-center gap-2 text-green-800 dark:text-green-200">
                            <i class="pi pi-bluetooth text-xl" />
                            <span class="font-medium">{{ btDevice?.name ?? 'Impresora' }}</span>
                            <span class="text-xs bg-green-200 dark:bg-green-700 px-2 py-0.5 rounded-full">Conectada</span>
                        </div>
                        <Button
                            icon="pi pi-times"
                            severity="danger"
                            text
                            rounded
                            @click="btDisconnect"
                            v-tooltip.bottom="'Desconectar'"
                            class="w-8 h-8"
                        />
                    </div>

                    <!-- Bluetooth soportado pero no conectado -->
                    <div v-else-if="btSupported && btSecure" class="space-y-3">
                        <Button
                            label="Buscar y conectar impresora Bluetooth"
                            :icon="btScanning || btConnecting ? 'pi pi-spin pi-spinner' : 'pi pi-bluetooth'"
                            severity="info"
                            outlined
                            @click="btScanAndConnect"
                            :loading="btScanning || btConnecting"
                            :disabled="printJobs.length === 0"
                            class="w-full"
                        />
                        <p v-if="printJobs.length === 0" class="text-xs text-gray-400 text-center m-0">
                            Añade plantillas primero para habilitar la conexión.
                        </p>
                    </div>

                    <!-- Fallback: HTTP (navegador compatible pero sin HTTPS) -->
                    <Message v-else-if="btSupported && !btSecure" severity="warn" :closable="false" class="text-sm">
                        <template #default>
                            <div class="space-y-2">
                                <p class="m-0 font-medium">
                                    <i class="pi pi-exclamation-triangle mr-1" />
                                    Conexión insegura — Bluetooth deshabilitado
                                </p>
                                <p class="m-0 text-xs text-gray-600 dark:text-gray-400">
                                    Web Bluetooth solo funciona con HTTPS o localhost. Este sitio se está sirviendo por HTTP.
                                </p>
                            </div>
                        </template>
                    </Message>

                    <!-- Fallback: navegador no compatible (Safari/Firefox/iOS) -->
                    <Message v-else severity="info" :closable="false" class="text-sm">
                        <template #default>
                            <div class="space-y-2">
                                <p class="m-0 font-medium">
                                    <i class="pi pi-info-circle mr-1" />
                                    Bluetooth no disponible en este navegador
                                </p>
                                <p class="m-0 text-xs text-gray-600 dark:text-gray-400">
                                    Web Bluetooth solo funciona en Chrome, Edge, Opera o Samsung Internet.
                                    Safari, Firefox y todos los navegadores en iOS no lo soportan.
                                </p>
                                <ul class="m-0 pl-4 text-xs text-gray-600 dark:text-gray-400 list-disc space-y-1">
                                    <li><strong>AirPrint:</strong> Usa Vista previa para imprimir con la impresora del sistema</li>
                                    <li><strong>Descargar:</strong> Guarda el ticket e imprímelo después</li>
                                </ul>
                            </div>
                        </template>
                    </Message>

                    <!-- Errores Bluetooth -->
                    <InlineMessage v-if="btError" severity="error" class="mt-3 text-sm">
                        {{ btError }}
                    </InlineMessage>
                </div>

                <!-- ===== MODO ESCRITORIO: PLUGIN + ALTERNATIVA BLUETOOTH ===== -->
                <div v-else>
                    <!-- Tabs de modo -->
                    <div v-if="pluginUnavailable" class="flex items-center gap-2 mb-4">
                        <div class="flex bg-gray-100 dark:bg-[#1a1a1a] rounded-full p-1">
                            <button
                                class="px-4 py-1.5 rounded-full text-xs font-medium transition-colors"
                                :class="printMode === 'plugin'
                                    ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                                @click="switchToPlugin"
                            >
                                <i class="pi pi-desktop mr-1" /> Plugin
                            </button>
                            <button
                                class="px-4 py-1.5 rounded-full text-xs font-medium transition-colors"
                                :class="printMode === 'bluetooth'
                                    ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                                @click="switchToBluetooth"
                            >
                                <i class="pi pi-bluetooth mr-1" /> Bluetooth
                            </button>
                        </div>
                    </div>

                    <!-- Modo Plugin -->
                    <div v-if="printMode === 'plugin'">
                        <div class="flex items-center mb-3">
                            <h6 class="font-medium text-gray-800 dark:text-gray-200 m-0">
                                Impresoras (detectadas por plugin)
                            </h6>
                            <Button icon="pi pi-refresh" rounded severity="secondary" @click="fetchPluginPrinters"
                                v-tooltip.bottom="'Recargar lista'" :loading="isLoadingPluginPrinters"
                                class="ml-auto size-8" />
                        </div>
                        <div v-if="!hasTicketJobs && !hasLabelJobs && printJobs.length > 0"
                            class="text-sm text-center text-gray-500 p-4 bg-gray-100 dark:bg-gray-800 rounded-md">
                            Las plantillas añadidas no requieren una impresora específica.
                        </div>
                        <div v-else class="space-y-3">
                            <div v-if="hasTicketJobs">
                                <InputLabel for="ticket-printer" value="Impresora de Tickets" class="text-sm" />
                                <Select id="ticket-printer" v-model="selectedTicketPrinter" :options="pluginPrinters" fluid
                                    :placeholder="isLoadingPluginPrinters ? 'Buscando...' : (pluginPrinters.length === 0 ? 'No hay impresoras (plugin)' : 'Selecciona impresora')"
                                    class="w-full mt-1" :loading="isLoadingPluginPrinters"
                                    :disabled="pluginPrinters.length === 0 || isLoadingPluginPrinters" />

                                <div class="mt-3 ml-1 p-2 bg-blue-50 dark:bg-blue-900/20 rounded border border-blue-100 dark:border-blue-800 flex items-start gap-2">
                                    <i class="pi pi-info-circle text-blue-500 mt-0.5"></i>
                                    <p class="text-xs text-blue-700 dark:text-blue-300 m-0">
                                        Se enviará automáticamente la instrucción para abrir el cajón de dinero. Si tu equipo no cuenta con uno de apertura automática, no hay problema, tu ticket se imprimirá con normalidad.
                                    </p>
                                </div>
                            </div>

                            <div v-if="hasLabelJobs">
                                <InputLabel for="label-printer" value="Impresora de Etiquetas" class="text-sm" />
                                <Select id="label-printer" v-model="selectedLabelPrinter" :options="pluginPrinters" fluid
                                    :placeholder="isLoadingPluginPrinters ? 'Buscando...' : (pluginPrinters.length === 0 ? 'No hay impresoras (plugin)' : 'Selecciona impresora')"
                                    class="w-full mt-1" :loading="isLoadingPluginPrinters"
                                    :disabled="pluginPrinters.length === 0 || isLoadingPluginPrinters" />
                            </div>
                        </div>
                        <InlineMessage v-if="pluginError" severity="error" class="mt-3 text-sm">
                            {{ pluginError }}
                            Si no tienes el plugin, envíanos un whatsapp al +52 33 2170 5650 o un correo a notificaciones@ezyventas.com para compartirtelo.
                        </InlineMessage>
                        <Message
                            v-if="!isLoadingPluginPrinters && pluginPrinters.length === 0 && !pluginError && (hasTicketJobs || hasLabelJobs)"
                            severity="info" :closable="false" class="mt-3 text-sm">
                            <template #default>
                                <div class="space-y-2">
                                    <p class="m-0">
                                        No se detectaron impresoras. Asegúrate de que el plugin EzyPrint esté corriendo y las impresoras estén instaladas.
                                    </p>
                                    <p class="m-0 text-xs">
                                        ¿Estás en una laptop fuera de oficina? También puedes usar
                                        <a href="#" class="text-primary-600 font-medium underline" @click.prevent="switchToBluetooth">impresión por Bluetooth</a>.
                                    </p>
                                </div>
                            </template>
                        </Message>
                    </div>

                    <!-- Modo Bluetooth (escritorio) -->
                    <div v-else class="space-y-3">
                        <!-- Bluetooth conectado -->
                        <div v-if="btIsConnected()" class="flex items-center justify-between p-3 bg-green-50 dark:bg-green-900/30 rounded-2xl border border-green-200 dark:border-green-700">
                            <div class="flex items-center gap-2 text-green-800 dark:text-green-200">
                                <i class="pi pi-bluetooth text-xl" />
                                <span class="font-medium">{{ btDevice?.name ?? 'Impresora' }}</span>
                                <span class="text-xs bg-green-200 dark:bg-green-700 px-2 py-0.5 rounded-full">Conectada</span>
                            </div>
                            <Button
                                icon="pi pi-times"
                                severity="danger"
                                text
                                rounded
                                @click="btDisconnect"
                                v-tooltip.bottom="'Desconectar'"
                                class="w-8 h-8"
                            />
                        </div>

                        <!-- Bluetooth soportado pero no conectado -->
                        <div v-else-if="btSupported && btSecure" class="space-y-3">
                            <Button
                                label="Buscar y conectar impresora Bluetooth"
                                :icon="btScanning || btConnecting ? 'pi pi-spin pi-spinner' : 'pi pi-bluetooth'"
                                severity="info"
                                outlined
                                @click="btScanAndConnect"
                                :loading="btScanning || btConnecting"
                                :disabled="printJobs.length === 0"
                                class="w-full"
                            />
                            <p v-if="printJobs.length === 0" class="text-xs text-gray-400 text-center m-0">
                                Añade plantillas primero para habilitar la conexión.
                            </p>
                        </div>

                        <!-- Fallback HTTP -->
                        <Message v-else-if="btSupported && !btSecure" severity="warn" :closable="false" class="text-sm">
                            <template #default>
                                <div class="space-y-2">
                                    <p class="m-0 font-medium">
                                        <i class="pi pi-exclamation-triangle mr-1" />
                                        Conexión insegura — Bluetooth deshabilitado
                                    </p>
                                    <p class="m-0 text-xs text-gray-600 dark:text-gray-400">
                                        Web Bluetooth requiere HTTPS o localhost. Usa <a href="#" class="text-primary-600 underline" @click.prevent="switchToPlugin">el plugin</a> o cambia a HTTPS.
                                    </p>
                                </div>
                            </template>
                        </Message>

                        <!-- Navegador no compatible -->
                        <Message v-else severity="info" :closable="false" class="text-sm">
                            <template #default>
                                <div class="space-y-2">
                                    <p class="m-0 font-medium">
                                        <i class="pi pi-info-circle mr-1" />
                                        Bluetooth no disponible en este navegador
                                    </p>
                                    <p class="m-0 text-xs text-gray-600 dark:text-gray-400">
                                        Web Bluetooth solo funciona en Chrome, Edge, Opera o Samsung Internet.
                                        <a href="#" class="text-primary-600 underline" @click.prevent="switchToPlugin">Usa el plugin</a> en su lugar.
                                    </p>
                                </div>
                            </template>
                        </Message>

                        <InlineMessage v-if="btError" severity="error" class="mt-3 text-sm">
                            {{ btError }}
                        </InlineMessage>
                    </div>
                </div>
            </Fieldset>

            <Message v-if="generalError && !pluginError" severity="error" :closable="false" class="mt-4">
                {{ generalError }}
            </Message>
        </div>

        <template #footer>
            <div class="flex justify-between items-center gap-2" v-if="!showPreview">
                <!-- Botones de fallback móvil (vista previa / descargar) -->
                <div v-if="isMobile && needsBtFallback" class="flex gap-2">
                    <Button
                        label="Vista previa"
                        icon="pi pi-eye"
                        severity="secondary"
                        outlined
                        :disabled="printJobs.length === 0"
                        :loading="previewLoading"
                        @click="loadTicketHtml"
                    />
                </div>
                <div v-else />

                <div class="flex gap-2">
                    <Button
                        v-if="canSendWhatsApp"
                        label="Enviar por WhatsApp"
                        icon="pi pi-whatsapp"
                        severity="success"
                        outlined
                        :loading="isSendingWhatsApp"
                        :disabled="isSendingWhatsApp"
                        @click="handleSendWhatsApp"
                        v-tooltip.top="'Envía el ticket de la venta al WhatsApp del cliente'"
                    />
                    <Button label="Cancelar" text severity="secondary" @click="closeModal" />
                    <Button
                        label="Imprimir"
                        icon="pi pi-print"
                        @click="print"
                        :disabled="!canPrint || isPrinting"
                        :loading="isPrinting"
                        severity="primary"
                    />
                </div>
            </div>
        </template>
    </Dialog>

    <!-- ===== MODAL: TELÉFONO DEL CLIENTE PARA WHATSAPP ===== -->
    <Dialog v-model:visible="isWhatsAppModalVisible" modal header="Teléfono del cliente"
        :style="{ width: '28rem' }" :breakpoints="{ '640px': '95vw' }">
        <div class="flex flex-col gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300 m-0">
                <template v-if="hasWhatsAppCustomer">
                    Este cliente no tiene teléfono registrado. Agrégalo para enviarle el ticket por WhatsApp; se guardará en su ficha.
                </template>
                <template v-else>
                    Esta venta no tiene un cliente asociado. Ingresa el teléfono al que deseas enviar el ticket por WhatsApp.
                </template>
            </p>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Teléfono *</label>
                <InputText
                    v-model="whatsappPhone"
                    placeholder="Ej. 33 1234 5678"
                    class="w-full"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }"
                />
                <Message v-if="whatsappPhoneError" severity="error" variant="simple" size="small">
                    {{ whatsappPhoneError }}
                </Message>
            </div>
        </div>
        <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="isWhatsAppModalVisible = false" />
            <Button
                :label="hasWhatsAppCustomer ? 'Guardar y enviar' : 'Enviar'"
                icon="pi pi-whatsapp"
                severity="success"
                :loading="isSavingWhatsAppPhone"
                @click="submitWhatsAppPhone"
            />
        </template>
    </Dialog>
</template>