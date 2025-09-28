<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import InputLabel from './InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    dataSource: Object,
    availableTemplates: Array,
});
const emit = defineEmits(['update:visible']);

const toast = useToast();
const printers = ref([]);
const selectedPrinter = ref(localStorage.getItem('selectedPrinter') || null);
const printJobs = ref([]);
const isPrinting = ref(false);
const error = ref(null);
const isLoadingPrinters = ref(false);

const fetchPrinters = async () => {
    isLoadingPrinters.value = true;
    error.value = null;
    printers.value = []; // Limpiar la lista antes de una nueva búsqueda
    try {
        const response = await fetch('http://localhost:8000/impresoras');
        if (!response.ok) throw new Error('No se pudo conectar con el plugin.');
        const printerList = await response.json();
        printers.value = printerList;
        
        // Si no hay una impresora seleccionada, o la que estaba guardada ya no existe, selecciona la primera.
        if (printerList.length > 0 && !printerList.includes(selectedPrinter.value)) {
            selectedPrinter.value = printerList[0];
        }
    } catch (e) {
        error.value = 'Error al obtener impresoras. Asegúrate de que el plugin de impresión esté en ejecución.';
        console.error(e);
    } finally {
        isLoadingPrinters.value = false;
    }
};

const addJob = (template) => {
    printJobs.value.push({
        id: `job-${Date.now()}`,
        template,
        copies: 1,
    });
};

const print = async () => {
    isPrinting.value = true;
    error.value = null;

    for (const job of printJobs.value) {
        for (let i = 0; i < job.copies; i++) {
            try {
                const payloadResponse = await axios.post(route('print.payload'), {
                    template_id: job.template.id,
                    data_source_type: props.dataSource.type,
                    data_source_id: props.dataSource.id,
                });
                
                const printData = payloadResponse.data;
                const pluginResponse = await fetch("http://localhost:8000/imprimir", {
                    method: "POST",
                    body: JSON.stringify({
                        nombreImpresora: selectedPrinter.value,
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
};

watch(selectedPrinter, (newVal) => {
    if (newVal) localStorage.setItem('selectedPrinter', newVal);
});

watch(() => props.visible, (newVal) => {
    if (newVal) {
        fetchPrinters();
    }
});
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Imprimir Documentos" :style="{ width: '40rem' }">
        <div class="p-2 space-y-4">
            <div>
                <div class="flex justify-between items-center mb-1">
                    <InputLabel value="Seleccionar Impresora" />
                    <Button 
                        icon="pi pi-refresh" 
                        text 
                        rounded 
                        severity="secondary" 
                        @click="fetchPrinters"
                        v-tooltip.bottom="'Recargar lista de impresoras'"
                        :loading="isLoadingPrinters"
                    />
                </div>
                <Dropdown 
                    v-model="selectedPrinter" 
                    :options="printers" 
                    :placeholder="isLoadingPrinters ? 'Buscando impresoras...' : (printers.length === 0 ? 'No se encontraron impresoras' : 'Selecciona una impresora')" 
                    class="w-full" 
                    :loading="isLoadingPrinters"
                    :disabled="printers.length === 0 && !isLoadingPrinters"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="font-semibold mb-2">Plantillas Disponibles</h3>
                    <div class="space-y-2">
                        <Button v-for="template in availableTemplates" :key="template.id" @click="addJob(template)" :label="template.name" icon="pi pi-plus" outlined class="w-full justify-start" />
                    </div>
                </div>
                 <div>
                     <h3 class="font-semibold mb-2">Trabajos de Impresión</h3>
                     <div v-if="printJobs.length === 0" class="text-center text-gray-500 border-dashed border-2 rounded-lg p-8">
                         <p>Añade plantillas para imprimir.</p>
                     </div>
                     <div v-else class="space-y-2">
                         <div v-for="(job, index) in printJobs" :key="job.id" class="flex items-center justify-between p-2 bg-gray-100 dark:bg-gray-800 rounded-md">
                             <span class="text-sm font-medium">{{ job.template.name }}</span>
                             <div class="flex items-center gap-2">
                                 <InputNumber v-model="job.copies" :min="1" inputStyle="width: 3rem; text-align: center;" />
                                 <Button @click="printJobs.splice(index, 1)" icon="pi pi-times" severity="danger" text rounded size="small" />
                             </div>
                         </div>
                     </div>
                </div>
            </div>
             <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        </div>
         <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="closeModal" />
            <Button label="Imprimir" icon="pi pi-print" @click="print" :disabled="printJobs.length === 0 || !selectedPrinter" :loading="isPrinting" />
        </template>
    </Dialog>
</template>