<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import { router } from '@inertiajs/vue3';
import { useBluetoothPrinter } from '@/Composables/useBluetoothPrinter';

const props = defineProps({
    visible: Boolean,
    dataSource: Object,
    availableTemplates: Array,
});
const emit = defineEmits(['update:visible']);

const toast = useToast();
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

// --- Estado del Modal ---
const printJobs = ref([]);
const isPrinting = ref(false);
const printError = ref(null);
const openDrawer = ref(true);
const showPreview = ref(false);
const ticketHtml = ref('');
const previewLoading = ref(false);

// --- iOS / No-Bluetooth detection ---
const needsFallback = computed(() => !btSupported.value || !btSecure.value);

// --- Template helpers ---
const addedTemplateIds = computed(() => new Set(printJobs.value.map(j => j.template.id)));

const addJob = (template) => {
    if (printJobs.value.some(j => j.template.id === template.id)) return;
    printJobs.value.push({
        id: `job-${Date.now()}-${Math.random()}`,
        template,
        copies: 1,
    });
};

const canPrint = computed(() => {
    if (printJobs.value.length === 0) return false;
    if (needsFallback.value) return true; // Siempre se puede previsualizar/imprimir HTML
    return btIsConnected();
});

// --- Auto-selección de templates default ---
const evaluateAutoSelection = () => {
    if (!props.visible) return;
    if (printJobs.value.length > 0) return;
    if (!props.availableTemplates?.length) return;

    if (props.availableTemplates.length === 1) {
        addJob(props.availableTemplates[0]);
        return;
    }

    const defaults = props.availableTemplates.filter(t => t.is_default || t.is_default === 1);
    if (defaults.length > 0) {
        defaults.forEach(t => addJob(t));
    }
};

// --- Toggle default template ---
const toggleDefaultTemplate = (template) => {
    router.patch(route('print-templates.toggle-default', template.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Configuración actualizada',
                detail: template.is_default ? 'Se removió la auto-selección' : 'Plantilla marcada para auto-selección',
                life: 2000,
            });
        },
        onError: () => {
            toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar la plantilla', life: 3000 });
        },
    });
};

// --- Cargar HTML del ticket para vista previa ---
const loadTicketHtml = async () => {
    if (printJobs.value.length === 0) return;

    previewLoading.value = true;
    ticketHtml.value = '';

    try {
        const job = printJobs.value[0]; // Primer template para preview
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

// --- Imprimir vía AirPrint (window.print) ---
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

    // Esperar a que carguen imágenes/recursos antes de imprimir
    printWindow.onload = () => {
        printWindow.print();
        printWindow.onafterprint = () => printWindow.close();
    };

    // Fallback si onload no dispara
    setTimeout(() => {
        printWindow.print();
        printWindow.onafterprint = () => printWindow.close();
    }, 800);
};

// --- Descargar PDF ---
const downloadPdf = () => {
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

// --- Impresión Bluetooth ---
const printBluetooth = async () => {
    isPrinting.value = true;
    printError.value = null;

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
                    // Si el Base64 es muy corto, probablemente no hay contenido real
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
                printError.value = `Error imprimiendo "${job.template.name}": ${msg}`;
                toast.add({ severity: 'error', summary: 'Error de impresión', detail: printError.value, life: 7000 });
                isPrinting.value = false;
                return;
            }
        }
    }

    isPrinting.value = false;
    toast.add({ severity: 'success', summary: 'Impresión completada', detail: 'El ticket se envió a la impresora.', life: 3000 });
    closeModal();
};

// --- Cerrar modal ---
const closeModal = () => {
    emit('update:visible', false);
    printJobs.value = [];
    showPreview.value = false;
    ticketHtml.value = '';
    printError.value = null;
    openDrawer.value = false;
};

// --- Watchers ---
watch(() => props.visible, (newVal) => {
    if (newVal) {
        printError.value = null;
        btError.value = null;
        openDrawer.value = false;
        showPreview.value = false;
        ticketHtml.value = '';
        evaluateAutoSelection();
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
    <Dialog
        :visible="visible"
        @update:visible="closeModal"
        modal
        header="Imprimir ticket (Bluetooth)"
        :style="{ width: '55rem' }"
        :breakpoints="{ '960px': '75vw', '640px': '95vw' }"
    >
        <!-- ===== VISTA PREVIA ===== -->
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
                        @click="downloadPdf"
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
        <div v-else class="space-y-5 dark:bg-[#232323] rounded-2xl p-4">
            <!-- Sección 1: Plantillas -->
            <Fieldset legend="1. Selecciona plantillas y copias" :toggleable="false">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Plantillas disponibles -->
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

                    <!-- Trabajos añadidos -->
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

            <!-- Sección 2: Impresora Bluetooth -->
            <Fieldset legend="2. Conectar impresora Bluetooth" :toggleable="false">
                <!-- Modo: Bluetooth soportado y conectado -->
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

                <!-- Modo: Bluetooth soportado pero no conectado -->
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

                <!-- Modo: Fallback (iOS/Safari) -->
                <div v-else class="space-y-3">
                    <Message severity="info" :closable="false" class="text-sm">
                        <template #default>
                            <div class="space-y-2">
                                <p class="m-0 font-medium">
                                    <i class="pi pi-info-circle mr-1" />
                                    Bluetooth no disponible en este navegador
                                </p>
                                <p class="m-0 text-xs text-gray-600 dark:text-gray-400">
                                    En iOS/Safari, el Web Bluetooth no está soportado. Usa una de estas alternativas:
                                </p>
                                <ul class="m-0 pl-4 text-xs text-gray-600 dark:text-gray-400 list-disc space-y-1">
                                    <li><strong>AirPrint:</strong> Vista previa → imprimir con la impresora del sistema</li>
                                    <li><strong>Descargar:</strong> Guardar como archivo e imprimir después</li>
                                    <li><strong>Chrome:</strong> Abrir este sitio en Chrome para usar Bluetooth</li>
                                </ul>
                            </div>
                        </template>
                    </Message>
                </div>

                <!-- Errores Bluetooth -->
                <InlineMessage v-if="btError" severity="error" class="mt-3 text-sm">
                    {{ btError }}
                </InlineMessage>

                <!-- Contexto inseguro -->
                <Message v-if="!btSecure && btSupported" severity="warn" :closable="false" class="mt-3 text-xs">
                    La impresión Bluetooth requiere HTTPS o localhost. Este sitio no se está sirviendo de forma segura.
                </Message>
            </Fieldset>

            <!-- Errores generales -->
            <Message v-if="printError" severity="error" :closable="false" class="mt-3 text-sm">
                {{ printError }}
            </Message>
        </div>

        <!-- ===== FOOTER ===== -->
        <template #footer>
            <div class="flex justify-between items-center gap-2">
                <!-- Botones de fallback (iOS/Safari) -->
                <div v-if="needsFallback && !showPreview" class="flex gap-2">
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
                <div v-else-if="!needsFallback && !showPreview" />

                <div class="flex gap-2">
                    <Button
                        label="Cancelar"
                        text
                        severity="secondary"
                        @click="closeModal"
                    />
                    <Button
                        v-if="!needsFallback"
                        label="Imprimir"
                        icon="pi pi-print"
                        severity="primary"
                        :disabled="!canPrint || isPrinting"
                        :loading="isPrinting"
                        @click="printBluetooth"
                    />
                </div>
            </div>
        </template>
    </Dialog>
</template>