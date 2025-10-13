<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import InputLabel from './InputLabel.vue';

// Componentes de PrimeVue (auto-importados)

const props = defineProps({
    visible: Boolean,
    dataSource: Object,
    availableTemplates: Array,
});
const emit = defineEmits(['update:visible']);

const toast = useToast();
const printers = ref([]);
const selectedTicketPrinter = ref(localStorage.getItem('selectedTicketPrinter') || null);
const selectedLabelPrinter = ref(localStorage.getItem('selectedLabelPrinter') || null);
const printJobs = ref([]);
const isPrinting = ref(false);
const error = ref(null);
const isLoadingPrinters = ref(false);

const labelOffsetX = ref(0.0);
const labelOffsetY = ref(0.0);

const fetchPrinters = async () => {
    isLoadingPrinters.value = true;
    error.value = null;
    printers.value = [];
    try {
        const response = await fetch('http://localhost:8000/impresoras');
        if (!response.ok) throw new Error('No se pudo conectar con el plugin.');
        const printerList = await response.json();
        printers.value = printerList;

        if (printerList.length > 0) {
            if (!selectedTicketPrinter.value || !printerList.includes(selectedTicketPrinter.value)) {
                selectedTicketPrinter.value = printerList[0];
            }
            if (!selectedLabelPrinter.value || !printerList.includes(selectedLabelPrinter.value)) {
                selectedLabelPrinter.value = printerList[0];
            }
        }
    } catch (e) {
        error.value = 'Error al obtener impresoras. Asegúrate de que el plugin de impresión esté en ejecución.';
        console.error(e);
    } finally {
        isLoadingPrinters.value = false;
    }
};

// --- Lógica de Trabajos de Impresión ---
const addJob = (template) => {
    printJobs.value.push({
        id: `job-${Date.now()}`,
        template,
        copies: 1,
    });
};

const hasTicketJobs = computed(() => printJobs.value.some(job => job.template.type === 'ticket_venta'));
const hasLabelJobs = computed(() => printJobs.value.some(job => job.template.type === 'etiqueta'));

const canPrint = computed(() => {
    if (printJobs.value.length === 0) return false;
    if (hasTicketJobs.value && !selectedTicketPrinter.value) return false;
    if (hasLabelJobs.value && !selectedLabelPrinter.value) return false;
    return true;
});

const print = async () => {
    isPrinting.value = true;
    error.value = null;

    for (const job of printJobs.value) {
        let printerToUse;
        if (job.template.type === 'ticket_venta') {
            printerToUse = selectedTicketPrinter.value;
        } else if (job.template.type === 'etiqueta') {
            printerToUse = selectedLabelPrinter.value;
        }

        if (!printerToUse) {
            error.value = `No se ha seleccionado una impresora para la plantilla "${job.template.name}".`;
            isPrinting.value = false;
            return;
        }

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

                const payloadResponse = await axios.post(route('print.payload'), payload);

                const printData = payloadResponse.data;
                const pluginResponse = await fetch("http://localhost:8000/imprimir", {
                    method: "POST",
                    body: JSON.stringify({
                        nombreImpresora: printerToUse,
                        operaciones: printData.operations,
                        anchoImpresora: printData.paperWidth,
                    }),
                    headers: { "Content-Type": "application/json" },
                });

                const result = await pluginResponse.json();
                if (!result.ok) throw new Error(result.message);

            } catch (e) {
                error.value = `Error al imprimir "${job.template.name}": ${e.message}`;
                isPrinting.value = false;
                return;
            }
        }
    }

    isPrinting.value = false;
    toast.add({ severity: 'success', summary: 'Éxito', detail: 'Trabajos de impresión enviados.', life: 3000 });
    closeModal();
};

const closeModal = () => {
    emit('update:visible', false);
    printJobs.value = [];
    labelOffsetX.value = 0.0;
    labelOffsetY.value = 0.0;
};

// --- Helpers de UI ---
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


// --- INICIO: Lógica de guardado y carga de desfases en localStorage ---

// Función para cargar los desfases de una impresora específica desde localStorage
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
            console.error("Error al parsear desfases guardados:", e);
            labelOffsetX.value = 0.0;
            labelOffsetY.value = 0.0;
        }
    } else {
        labelOffsetX.value = 0.0;
        labelOffsetY.value = 0.0;
    }
};

// Función para guardar los desfases para la impresora de etiquetas actual
const saveCurrentLabelOffsets = () => {
    if (selectedLabelPrinter.value) {
        const offsetKey = `printer_offset_${selectedLabelPrinter.value}`;
        const offsets = {
            x: labelOffsetX.value,
            y: labelOffsetY.value,
        };
        localStorage.setItem(offsetKey, JSON.stringify(offsets));
    }
};

// Observadores para guardar automáticamente los desfases cuando el usuario los cambia
watch(labelOffsetX, saveCurrentLabelOffsets);
watch(labelOffsetY, saveCurrentLabelOffsets);

// Observador para guardar la selección de la impresora de tickets
watch(selectedTicketPrinter, (newVal) => { if (newVal) localStorage.setItem('selectedTicketPrinter', newVal); });

// Observador para guardar la selección de la impresora de etiquetas y cargar sus desfases
watch(selectedLabelPrinter, (newPrinterName) => {
    if (newPrinterName) {
        localStorage.setItem('selectedLabelPrinter', newPrinterName);
        loadOffsetsForPrinter(newPrinterName);
    }
});

// Observador para cuando el modal se hace visible
watch(() => props.visible, (newVal) => {
    if (newVal) {
        // Busca impresoras y, una vez que termina, carga los desfases de la impresora seleccionada
        fetchPrinters().then(() => {
            loadOffsetsForPrinter(selectedLabelPrinter.value);
        });
    }
});

// --- FIN: Lógica de guardado y carga de desfases ---

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Imprimir" :style="{ width: '40rem' }">
        <div class="p-2 space-y-4">
            <div>
                <div v-if="printJobs.length > 0" class="flex justify-between items-center mb-1">
                    <InputLabel value="Si no se muestran impresoras disponibles, recarga con el siguiente botón" />
                    <Button icon="pi pi-refresh" text rounded severity="secondary" @click="fetchPrinters"
                        v-tooltip.bottom="'Recargar lista de impresoras'" :loading="isLoadingPrinters" />
                </div>
                <div v-else class="mb-1">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Puedes imprimir más de una plantilla de ticket y de etiqueta.
                        Selecciona la(s) plantilla(s) y después la(s) impresora(s) para cada tipo. <br>
                        Las plantillas las selecciona el sistema inteligentemente según el contexto.
                    </p>
                </div>
                <div class="space-y-3">
                    <div v-if="hasTicketJobs">
                        <InputLabel for="ticket-printer" value="Impresora de Tickets" />
                        <Select id="ticket-printer" v-model="selectedTicketPrinter" :options="printers"
                            :placeholder="isLoadingPrinters ? 'Buscando...' : 'Selecciona una impresora'"
                            class="w-full mt-1" :loading="isLoadingPrinters"
                            :disabled="printers.length === 0 && !isLoadingPrinters" />
                    </div>

                    <div v-if="hasLabelJobs">
                        <InputLabel for="label-printer" value="Impresora de Etiquetas" />
                        <Select id="label-printer" v-model="selectedLabelPrinter" :options="printers"
                            :placeholder="isLoadingPrinters ? 'Buscando...' : 'Selecciona una impresora'"
                            class="w-full mt-1" :loading="isLoadingPrinters"
                            :disabled="printers.length === 0 && !isLoadingPrinters" />

                        <!-- INICIO: Entradas de Calibración -->
                         <!--<div class="mt-3 p-3 border rounded-md bg-gray-50 dark:bg-slate-800">
                            <div class="flex items-center space-x-2">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 m-0">
                                    Ajuste de desface para impresora de etiquetas (mm)</p>
                                <i class="pi pi-info-circle text-gray-400 cursor-pointer"
                                    v-tooltip.top="'Si la impresión está desfasada, corrígela aquí. El ajuste se guardará para esta impresora en este navegador.'">
                                </i>
                            </div>
                            <div class="grid grid-cols-2 gap-3 mt-2">
                                <div>
                                    <InputLabel for="offset-x" value="Offset Horizontal (X)" class="text-xs" />
                                    <InputNumber id="offset-x" v-model="labelOffsetX" class="w-full mt-1"
                                        inputId="horizontal-offset" mode="decimal" :minFractionDigits="1"
                                        :maxFractionDigits="2" showButtons :step="0.5" />
                                </div>
                                <div>
                                    <InputLabel for="offset-y" value="Offset Vertical (Y)" class="text-xs" />
                                    <InputNumber id="offset-y" v-model="labelOffsetY" class="w-full mt-1"
                                        inputId="vertical-offset" mode="decimal" :minFractionDigits="1"
                                        :maxFractionDigits="2" showButtons :step="0.5" />
                                </div>
                            </div>
                            <small class="text-gray-500 dark:text-gray-400 mt-2 block">Usa valores positivos para mover
                                a la derecha/abajo y negativos para la izquierda/arriba.</small>
                        </div>-->
                        <!-- FIN: Entradas de Calibración -->
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="font-semibold mb-2 text-base">Plantillas Disponibles</h3>
                    <div class="space-y-2">
                        <Button v-for="template in availableTemplates" :key="template.id" @click="addJob(template)"
                            icon="pi pi-plus" outlined class="!w-full justify-start !px-1">
                            <div class="flex justify-between items-center !w-full">
                                <span class="truncate pr-2">{{ template.name }}</span>
                                <Tag :value="getTemplateTypeText(template.type)"
                                    :severity="getTemplateTypeSeverity(template.type)" />
                            </div>
                        </Button>
                    </div>
                </div>
                <div>
                    <h3 class="font-semibold mb-2 text-base">Trabajos de Impresión</h3>
                    <div v-if="printJobs.length === 0" class="text-center text-gray-500 border rounded-lg p-8">
                        <i class="pi pi-arrow-left text-4xl mb-4"></i>
                        <p>Añade plantillas para imprimir.</p>
                    </div>
                    <div v-else class="space-y-2">
                        <div v-for="(job, index) in printJobs" :key="job.id"
                            class="flex items-center justify-between p-2 bg-gray-100 dark:bg-gray-800 rounded-md">
                            <span class="text-sm font-medium">{{ job.template.name }}</span>
                            <div class="flex items-center gap-2">
                                <InputNumber v-model="job.copies" :min="1"
                                    inputStyle="width: 3rem; text-align: center;" />
                                <Button @click="printJobs.splice(index, 1)" icon="pi pi-times" severity="danger" text
                                    rounded size="small" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        </div>
        <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="closeModal" />
            <Button label="Imprimir" icon="pi pi-print" @click="print" :disabled="!canPrint" :loading="isPrinting" />
        </template>
    </Dialog>
</template>