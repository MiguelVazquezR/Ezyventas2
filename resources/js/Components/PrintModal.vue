<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import { router } from '@inertiajs/vue3';
import InputLabel from './InputLabel.vue';

// --- Importar Composables ---
import { usePrintPlugin } from '@/Composables/usePrintPlugin';

// --- Props y Emits ---
const props = defineProps({
    visible: Boolean,
    dataSource: Object,
    availableTemplates: Array,
});
const emit = defineEmits(['update:visible']);

// --- Instanciar Composables ---
const toast = useToast();
const {
    printers: pluginPrinters,
    selectedTicketPrinter,
    selectedLabelPrinter,
    isLoadingPrinters: isLoadingPluginPrinters,
    pluginError,
    fetchPrinters: fetchPluginPrinters,
    sendToPlugin,
} = usePrintPlugin();

// --- Estado del Modal ---
const printJobs = ref([]);
const isPrinting = ref(false);
const generalError = ref(null);
const openDrawer = ref(true);

// --- Cargar impresoras al montar ---
onMounted(() => {
    fetchPluginPrinters();
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

// --- Lógica de Offsets ---
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
    const ticketsOk = !hasTicketJobs.value || (hasTicketJobs.value && !!selectedTicketPrinter.value);
    const labelsOk = !hasLabelJobs.value || (hasLabelJobs.value && !!selectedLabelPrinter.value);
    return ticketsOk && labelsOk;
});


const print = async () => {
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

// --- Cerrar Modal ---
const closeModal = () => {
    emit('update:visible', false);
    printJobs.value = [];
    openDrawer.value = false; 
};

// --- MEJORA: Auto-selección infalible (Reactiva) ---
const evaluateAutoSelection = () => {
    if (!props.visible) return;
    if (printJobs.value.length > 0) return; // Si ya hay algo añadido, no interferimos

    if (props.availableTemplates && props.availableTemplates.length > 0) {
        if (props.availableTemplates.length === 1) {
            addJob(props.availableTemplates[0]);
        } else {
            // Evaluamos si is_default es true o 1
            const autoSelectTemplates = props.availableTemplates.filter(t => t.is_default || t.is_default === 1);
            if (autoSelectTemplates.length > 0) {
                autoSelectTemplates.forEach(t => addJob(t));
            }
        }
    }
};

// --- NUEVO: Botón de Toggle Rápido de Plantilla Default ---
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
        openDrawer.value = false;

        evaluateAutoSelection();

        fetchPluginPrinters().then(() => {
            loadOffsetsForPrinter(selectedLabelPrinter.value);
        });
    }
}, { immediate: true });

watch(() => props.availableTemplates, () => {
    evaluateAutoSelection();
}, { deep: true, immediate: true });


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

        <div class="p-4 space-y-4 dark:bg-gray-900 rounded-lg">

            <Fieldset legend="1. Selecciona plantillas y copias" :toggleable="false">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Columna Plantillas Disponibles -->
                    <div>
                        <h6 class="font-medium mb-3 text-gray-700 dark:text-gray-300">Plantillas disponibles</h6>
                        <p v-if="availableTemplates.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                            No hay plantillas disponibles.
                        </p>
                        <div v-else class="space-y-2 max-h-60 overflow-y-auto rounded-md bg-white dark:bg-gray-800">
                            <Listbox :options="availableTemplates" optionLabel="name"
                                class="w-full border-none !shadow-none" listStyle="max-height: 200px">
                                <template #option="slotProps">
                                    <div class="flex justify-between items-center w-full p-2 rounded transition-colors"
                                        :class="{
                                            'cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 !bg-transparent': !addedTemplateIds.has(slotProps.option.id),
                                            'opacity-60 cursor-not-allowed bg-gray-50 dark:bg-gray-700/50 !bg-transparent': addedTemplateIds.has(slotProps.option.id)
                                        }"
                                        @click="!addedTemplateIds.has(slotProps.option.id) ? addJob(slotProps.option) : null">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <i :class="addedTemplateIds.has(slotProps.option.id) ? 'pi pi-check-circle text-green-600' : 'pi pi-plus-circle text-blue-600'"></i>
                                            <span class="truncate text-sm">{{ slotProps.option.name }}</span>
                                        </div>
                                        
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <Tag :value="getTemplateTypeText(slotProps.option.type)"
                                                :severity="getTemplateTypeSeverity(slotProps.option.type)"
                                                class="text-[10px]" />
                                            
                                            <!-- Botón de Estrella para activar/desactivar auto-selección -->
                                            <Button 
                                                :icon="slotProps.option.is_default ? 'pi pi-star-fill' : 'pi pi-star'" 
                                                class="!w-7 !h-7 !p-0"
                                                :class="slotProps.option.is_default ? 'text-yellow-500 hover:text-yellow-600' : 'text-gray-300 hover:text-yellow-400'"
                                                text 
                                                rounded 
                                                @click.stop="toggleDefaultTemplate(slotProps.option)"
                                                v-tooltip.top="slotProps.option.is_default ? 'Quitar selección automática' : 'Marcar para auto-selección'"
                                            />
                                        </div>
                                    </div>
                                </template>
                            </Listbox>
                        </div>
                    </div>
                    <!-- Columna Trabajos de Impresión -->
                    <div>
                        <h6 class="font-medium mb-3 text-gray-700 dark:text-gray-300">
                            Trabajos de impresión añadidos
                        </h6>
                        <div v-if="printJobs.length === 0"
                            class="text-center text-gray-500 dark:text-gray-400 border-2 border-dashed rounded-lg p-8 flex flex-col items-center justify-center">
                            <i class="pi pi-print !text-2xl mb-3 text-gray-400"></i>
                            <p class="text-sm">Selecciona plantillas para añadirlas aquí.</p>
                        </div>
                        <div v-else class="space-y-2 max-h-60 overflow-y-auto pr-2">
                            <div v-for="(job, index) in printJobs" :key="job.id"
                                class="relative flex items-center justify-between p-2 pt-5 bg-gray-100 dark:bg-gray-700/50 rounded-lg shadow-sm gap-4">
                                <Tag :value="getTemplateTypeText(job.template.type)"
                                    :severity="getTemplateTypeSeverity(job.template.type)"
                                    class="text-xs flex-shrink-0 !absolute top-0 left-1" />
                                <span class="text-sm font-medium truncate">{{ job.template.name }}</span>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <label :for="'copies-' + job.id"
                                        class="text-xs text-gray-600 dark:text-gray-400 hidden sm:inline">Copias:</label>
                                    <InputNumber :id="'copies-' + job.id" v-model="job.copies" :min="1" :max="10"
                                        mode="decimal" showButtons
                                        :inputStyle="{ width: '4.8rem', textAlign: 'start', height: '2.2rem' }"
                                        size="small" />
                                    <Button @click="printJobs.splice(index, 1)" icon="pi pi-trash" severity="danger"
                                        text rounded size="small" class="w-8 h-8" v-tooltip.right="'Quitar trabajo'" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Fieldset>


            <Fieldset legend="2. Selecciona impresora" :toggleable="false" :disabled="printJobs.length === 0">
                <template #legend>
                    <div class="flex items-center gap-2">
                        <span>2. Selecciona impresora</span>
                        <i v-if="printJobs.length === 0" class="pi pi-info-circle text-gray-400"
                            v-tooltip.top="'Añade plantillas primero'"></i>
                    </div>
                </template>

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
                    No se detectaron impresoras. Asegúrate de que el plugin EzyPrint esté corriendo y las impresoras
                    estén
                    instaladas.
                </Message>
            </Fieldset>

            <Message v-if="generalError && !pluginError" severity="error" :closable="false" class="mt-4">
                {{ generalError }}
            </Message>

        </div>

        <template #footer>
            <div class="flex justify-end items-center gap-2">
                <Button label="Cancelar" text severity="secondary" @click="closeModal" />
                <Button label="Imprimir" icon="pi pi-print" @click="print"
                    :disabled="!canPrint || isPrinting" :loading="isPrinting"
                    severity="primary" />
            </div>
        </template>
    </Dialog>
</template>