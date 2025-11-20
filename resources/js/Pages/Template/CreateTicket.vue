<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import { v4 as uuidv4 } from 'uuid';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Menu from 'primevue/menu';
import { useTemplateVariables } from '@/Composables/useTemplateVariables';

// Props unificadas para Crear y Editar
const props = defineProps([
    'branches',
    'templateImages',
    'templateLimit',
    'templateUsage',
    'customFieldDefinitions',
    'printTemplate' // Si existe, estamos en modo edición
]);

// --- Lógica de Límite ---
const limitReached = computed(() => {
    const limit = props.templateLimit !== undefined ? props.templateLimit : -1;
    const usage = props.templateUsage || 0;
    if (limit === -1) return false;
    if (props.printTemplate) return false; // Si editamos, no aplica límite
    return usage >= limit;
});

const toast = useToast();
const templateElements = ref([]);
const selectedElement = ref(null);
const localTemplateImages = ref(props.templateImages ? [...props.templateImages] : []);

// Estado para inserción relativa
const addMenuRef = ref(null);
const currentInsertionTarget = ref({ id: null, position: 'after' });

// --- ESTADO DE VIEWPORT (ZOOM Y PAN) ---
const canvasContainerRef = ref(null);
const zoomScale = ref(1.2); // ZOOM POR DEFECTO MÁS GRANDE (150%)
const pan = ref({ x: 0, y: -50 }); // Un poco de margen superior inicial
const isPanning = ref(false);
const lastMousePos = ref({ x: 0, y: 0 });
const isSpacePressed = ref(false);

// Formulario Unificado
const form = useForm({
    name: '',
    type: 'ticket_venta',
    branch_ids: [],
    content: {
        config: { 
            paperWidth: '80mm', 
            feedLines: 3, 
            codepage: 'cp850' 
        },
        elements: [],
    },
});

// Inicialización (Montaje)
onMounted(() => {
    if (props.printTemplate) {
        // Modo Edición: Cargar datos
        form.name = props.printTemplate.name;
        form.type = props.printTemplate.type;
        form.branch_ids = props.printTemplate.branches ? props.printTemplate.branches.map(b => b.id) : [];
        
        if (props.printTemplate.content) {
            form.content.config = { ...form.content.config, ...props.printTemplate.content.config };
            // Asegurar IDs únicos
            templateElements.value = (props.printTemplate.content.elements || []).map(el => ({
                ...el,
                id: el.id || uuidv4()
            }));
        }
    }

    // Event listeners para Paneo y Zoom
    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('keyup', handleKeyUp);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    window.removeEventListener('keyup', handleKeyUp);
});

// Sincronizar elementos con el formulario
watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

// --- Lógica de Navegación (Zoom & Pan) ---

const handleKeyDown = (e) => {
    if (e.code === 'Space' && !isSpacePressed.value) {
        // Evitar activar si estamos escribiendo en un input
        if (document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA' && !document.activeElement.isContentEditable) {
            e.preventDefault();
            isSpacePressed.value = true;
        }
    }
};

const handleKeyUp = (e) => {
    if (e.code === 'Space') {
        isSpacePressed.value = false;
        isPanning.value = false;
        document.body.style.cursor = '';
    }
};

const startPan = (e) => {
    // Permitir pan si se presiona espacio O si se hace click directo en el fondo gris (canvas)
    if (isSpacePressed.value || e.target === canvasContainerRef.value) {
        isPanning.value = true;
        lastMousePos.value = { x: e.clientX, y: e.clientY };
        if (canvasContainerRef.value) canvasContainerRef.value.style.cursor = 'grabbing';
        e.preventDefault();
    }
};

const doPan = (e) => {
    if (!isPanning.value) return;
    e.preventDefault();
    const deltaX = e.clientX - lastMousePos.value.x;
    const deltaY = e.clientY - lastMousePos.value.y;
    pan.value.x += deltaX;
    pan.value.y += deltaY;
    lastMousePos.value = { x: e.clientX, y: e.clientY };
};

const endPan = () => {
    isPanning.value = false;
    if (canvasContainerRef.value) canvasContainerRef.value.style.cursor = isSpacePressed.value ? 'grab' : 'default';
};

const zoomIn = () => zoomScale.value = Math.min(zoomScale.value + 0.1, 3.0);
const zoomOut = () => zoomScale.value = Math.max(zoomScale.value - 0.1, 0.5);
const resetView = () => {
    zoomScale.value = 1.2;
    pan.value = { x: 0, y: -50 };
};

const zoomPercentage = computed({
    get: () => Math.round(zoomScale.value * 100),
    set: (val) => {
        if (!val) return;
        let newScale = val / 100;
        if (newScale < 0.1) newScale = 0.1;
        if (newScale > 3.0) newScale = 3.0;
        zoomScale.value = newScale;
    }
});


// --- Acciones CRUD ---
const submit = () => {
    const routeName = props.printTemplate ? 'print-templates.update' : 'print-templates.store';
    const routeParams = props.printTemplate ? props.printTemplate.id : {};
    const method = props.printTemplate ? 'put' : 'post';

    form[method](route(routeName, routeParams), {
        // onSuccess: () => toast.add({ severity: 'success', summary: 'Guardado', detail: 'Plantilla guardada correctamente', life: 3000 }),
        onError: () => toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa los campos obligatorios', life: 3000 })
    });
};

// --- Gestión de Elementos ---
const availableElements = ref([
    { id: 'text', name: 'Texto', icon: 'pi pi-align-left', description: 'Párrafos, datos' },
    { id: 'image', name: 'Imagen URL', icon: 'pi pi-image', description: 'Desde internet' },
    { id: 'local_image', name: 'Subir Imagen', icon: 'pi pi-upload', description: 'Local / Galería' },
    { id: 'separator', name: 'Separador', icon: 'pi pi-minus', description: 'Línea divisoria' },
    { id: 'line_break', name: 'Salto de Línea', icon: 'pi pi-arrow-down', description: 'Espacio vacío' },
    { id: 'barcode', name: 'Código Barras', icon: 'pi pi-barcode', description: 'Folios, SKU' },
    { id: 'qr', name: 'Código QR', icon: 'pi pi-qrcode', description: 'Enlaces, Info' },
    { id: 'sales_table', name: 'Tabla Venta', icon: 'pi pi-table', description: 'Lista productos' },
]);

const createElementOfType = (type) => {
    const newElement = { id: uuidv4(), type: type, data: { align: 'left' } };
    if (type === 'text') newElement.data = { text: 'Texto de ejemplo', align: 'left' };
    if (type === 'image') newElement.data = { url: 'https://placehold.co/300x150', width: 300, align: 'center' };
    if (type === 'local_image') newElement.data = { url: '', width: 300, align: 'center', isUploading: false };
    if (type === 'barcode') newElement.data = { type: 'CODE128', value: '{{v.folio}}', align: 'center', height: 80 };
    if (type === 'qr') newElement.data = { value: '{{os.folio}}', align: 'center', size: 5 };
    return newElement;
};

const addElementToEnd = (type) => {
    const newElement = createElementOfType(type);
    templateElements.value.push(newElement);
    selectedElement.value = newElement;
};

const addElementRelative = (type) => {
    const newElement = createElementOfType(type);
    const { id: targetId, position } = currentInsertionTarget.value;
    const targetIndex = templateElements.value.findIndex(el => el.id === targetId);
    
    if (targetIndex !== -1) {
        if (position === 'before') templateElements.value.splice(targetIndex, 0, newElement);
        else templateElements.value.splice(targetIndex + 1, 0, newElement);
        selectedElement.value = newElement;
    }
};

const removeElement = (elementId) => {
    templateElements.value = templateElements.value.filter(el => el.id !== elementId);
    if (selectedElement.value?.id === elementId) selectedElement.value = null;
};

// Menú Contextual de Inserción
const addMenuTemplate = computed(() => {
    return availableElements.value.map(el => ({
        label: el.name, icon: el.icon, command: () => addElementRelative(el.id)
    }));
});

const openAddMenu = (event, elementId, position) => {
    currentInsertionTarget.value = { id: elementId, position: position };
    if (addMenuRef.value) addMenuRef.value.toggle(event);
};

// --- Imágenes ---
const handleImageUpload = async (event, uploader) => {
    if (selectedElement.value?.type !== 'local_image') return;
    selectedElement.value.data.isUploading = true;
    const formData = new FormData();
    formData.append('image', event.files[0]);

    try {
        const response = await axios.post(route('print-templates.media.store'), formData);
        const newImage = response.data;
        selectedElement.value.data.url = newImage.url;
        localTemplateImages.value.unshift(newImage);
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Imagen subida', life: 2000 });
        if (uploader) uploader.clear();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Error al subir imagen', life: 3000 });
    } finally {
        selectedElement.value.data.isUploading = false;
    }
};

// --- Variables ---
const { placeholderOptions } = useTemplateVariables(() => props.customFieldDefinitions);

const insertPlaceholder = (placeholder) => {
    if (selectedElement.value && selectedElement.value.type === 'text') {
        selectedElement.value.data.text = (selectedElement.value.data.text || '') + placeholder;
    }
};

// Opciones
const alignmentOptions = [
    { icon: 'pi pi-align-left', value: 'left' }, 
    { icon: 'pi pi-align-center', value: 'center' }, 
    { icon: 'pi pi-align-right', value: 'right' }
];
const barcodeTypeOptions = ['CODE128', 'CODE39', 'EAN13', 'UPC-A'];

</script>

<template>
    <AppLayout :title="props.printTemplate ? 'Editar Plantilla de Ticket' : 'Crear Plantilla de Ticket'">
        
        <!-- Estado Límite Alcanzado -->
        <div v-if="limitReached" class="h-[calc(100vh-7rem)] flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl mx-auto text-center">
                <h1 class="text-2xl font-bold">Límite Alcanzado</h1>
                <p class="text-gray-600 mt-2 mb-4">Has alcanzado el límite de plantillas permitido.</p>
                <Link :href="route('print-templates.index')"><Button label="Volver" /></Link>
            </div>
        </div>

        <!-- Editor -->
        <div v-else class="flex h-[calc(100vh-7rem)] overflow-hidden bg-gray-100 dark:bg-gray-900 select-none">
            
            <!-- PANEL IZQUIERDO: Configuración y Elementos -->
            <div class="w-80 border-r dark:border-gray-700 bg-white dark:bg-gray-800 z-20 flex flex-col h-full shrink-0 shadow-lg">
                <div class="flex-1 overflow-y-auto p-4 custom-scrollbar">
                    <h3 class="font-bold mb-4 text-lg">Configuración</h3>
                    <div class="space-y-4 mb-6">
                        <div>
                            <InputLabel value="Nombre *" />
                            <InputText v-model="form.name" class="w-full p-inputtext-sm" :invalid="!!form.errors.name" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Sucursales *" />
                            <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id" placeholder="Seleccionar" class="w-full" :maxSelectedLabels="1" :invalid="!!form.errors.branch_ids" />
                            <InputError :message="form.errors.branch_ids" class="mt-1" />
                        </div>
                        <div class="border-t dark:border-gray-700 pt-4">
                            <InputLabel value="Ancho del Papel" />
                            <div class="flex gap-4 mt-2">
                                <div class="flex items-center">
                                    <RadioButton v-model="form.content.config.paperWidth" inputId="80mm" value="80mm" />
                                    <label for="80mm" class="ml-2 text-sm">80mm</label>
                                </div>
                                <div class="flex items-center">
                                    <RadioButton v-model="form.content.config.paperWidth" inputId="58mm" value="58mm" />
                                    <label for="58mm" class="ml-2 text-sm">58mm</label>
                                </div>
                            </div>
                        </div>
                        <div>
                            <InputLabel value="Líneas finales (feed)" />
                            <InputNumber v-model="form.content.config.feedLines" :min="0" :max="10" showButtons class="w-full" />
                        </div>
                    </div>

                    <h3 class="font-bold mb-3 text-lg border-t pt-4">Elementos</h3>
                    <div class="grid grid-cols-1 gap-2">
                        <Button v-for="el in availableElements" :key="el.id" @click="addElementToEnd(el.id)" severity="secondary" outlined class="!justify-start !text-left !py-3">
                            <div class="flex items-center gap-3">
                                <i :class="el.icon" class="text-xl text-blue-500"></i>
                                <div>
                                    <div class="text-sm font-bold">{{ el.name }}</div>
                                    <div class="text-xs text-gray-500">{{ el.description }}</div>
                                </div>
                            </div>
                        </Button>
                    </div>
                </div>
                <div class="p-4 border-t dark:border-gray-700 dark:bg-gray-800 mt-auto">
                    <Button @click="submit" :label="props.printTemplate ? 'Actualizar' : 'Guardar'" icon="pi pi-save" class="w-full mb-2" :loading="form.processing" />
                    <Link :href="route('print-templates.index')"><Button label="Cancelar" severity="secondary" text class="w-full" /></Link>
                </div>
            </div>

            <!-- PANEL CENTRAL (CANVAS) -->
            <div ref="canvasContainerRef"
                 class="flex-1 relative overflow-hidden dark:bg-black/20 flex items-center justify-center bg-gray-100 dark:bg-gray-900 group-canvas"
                 @mousedown="startPan"
                 @mousemove="doPan" 
                 @mouseup="endPan" 
                 @mouseleave="endPan">
                
                <!-- Overlay para Mover con Espacio -->
                <div v-if="isSpacePressed" 
                    class="absolute inset-0 z-[100] cursor-grab active:cursor-grabbing bg-transparent">
                </div>

                <!-- Menú Contextual -->
                <Menu ref="addMenuRef" :model="addMenuTemplate" :popup="true" />

                <!-- Área Transformable del Ticket -->
                <div class="transition-transform duration-75 ease-linear origin-top"
                    :style="{
                        transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoomScale})`
                    }">
                    
                    <!-- El "Papel" del Ticket -->
                    <div class="bg-white dark:bg-gray-800 shadow-2xl min-h-[10cm] relative mx-auto"
                        :class="form.content.config.paperWidth === '80mm' ? 'w-[80mm]' : 'w-[58mm]'"
                        style="padding: 5px 0; height: fit-content;"
                        @mousedown.stop
                        @click.self="selectedElement = null">

                        <div v-for="element in templateElements" :key="element.id" @click="selectedElement = element"
                            class="relative group border border-transparent hover:border-dashed hover:border-blue-300 transition-all rounded-sm px-1"
                            :class="{ '!border-blue-500 bg-blue-50/10': selectedElement?.id === element.id }">

                            <!-- Controles de Inserción/Eliminación (Estilo Hover Flotante) -->
                            <div v-if="selectedElement?.id === element.id" class="absolute -right-8 top-0 flex flex-col gap-1 z-50">
                                <Button icon="pi pi-arrow-up" class="!w-6 !h-6 !p-0" rounded severity="secondary" @click.stop="openAddMenu($event, element.id, 'before')" v-tooltip.left="'Insertar Antes'" />
                                <Button icon="pi pi-arrow-down" class="!w-6 !h-6 !p-0" rounded severity="secondary" @click.stop="openAddMenu($event, element.id, 'after')" v-tooltip.left="'Insertar Después'" />
                                <Button icon="pi pi-trash" class="!w-6 !h-6 !p-0" rounded severity="danger" @click.stop="removeElement(element.id)" v-tooltip.left="'Eliminar'" />
                            </div>

                            <!-- Renderizado del Elemento -->
                            <div :class="`text-${element.data.align} pointer-events-none`">
                                
                                <!-- Texto -->
                                <div v-if="element.type === 'text'" class="whitespace-pre-wrap font-mono text-xs leading-tight break-words">
                                    {{ element.data.text || '(Vacío)' }}
                                </div>

                                <!-- Imagen -->
                                <img v-if="['image', 'local_image'].includes(element.type)"
                                    :src="element.data.url || 'https://placehold.co/300x150?text=IMG'" 
                                    class="object-contain inline-block"
                                    :style="{ maxWidth: element.data.width ? (element.data.width + 'px') : '100%' }"
                                />

                                <!-- Separador -->
                                <div v-if="element.type === 'separator'" class="w-full border-t border-dashed border-black my-1"></div>
                                
                                <!-- Salto -->
                                <div v-if="element.type === 'line_break'" class="h-4 text-[10px] text-gray-300 flex items-center justify-center border border-dashed border-gray-200 my-1">
                                    [Espacio]
                                </div>

                                <!-- Barcode -->
                                <div v-if="element.type === 'barcode'" class="inline-flex flex-col items-center">
                                    <div class="bg-gray-200 w-full min-w-[100px] flex items-center justify-center" :style="{ height: (element.data.height || 50) + 'px' }">
                                        <span class="font-mono text-[10px] tracking-widest">|| ||| || |||</span>
                                    </div>
                                    <span class="text-[10px] font-mono">{{ element.data.value }}</span>
                                </div>

                                <!-- QR -->
                                <div v-if="element.type === 'qr'" class="inline-block p-1">
                                    <i class="pi pi-qrcode" :style="{ fontSize: ((element.data.size || 5) * 8) + 'px' }"></i>
                                </div>

                                <!-- Tabla -->
                                <div v-if="element.type === 'sales_table'" class="text-xs font-mono w-full">
                                    <div class="flex border-b border-black border-dashed pb-1 mb-1">
                                        <span class="w-8">Cant</span>
                                        <span class="flex-1">Desc</span>
                                        <span class="w-12 text-right">Total</span>
                                    </div>
                                    <div class="flex text-gray-400 italic">
                                        <span class="w-8">1</span>
                                        <span class="flex-1">Producto...</span>
                                        <span class="w-12 text-right">$0.00</span>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Placeholder Vacio -->
                        <div v-if="templateElements.length === 0" class="text-center py-10 text-gray-400 text-xs italic">
                            Plantilla vacía.<br>Añade elementos desde la izquierda.
                        </div>

                    </div>
                </div>

                <!-- Controles Zoom & Info -->
                <div class="absolute bottom-6 right-6 flex flex-col items-end gap-2 z-50">
                    <div v-if="!isSpacePressed" class="bg-black/70 text-white px-3 py-1.5 rounded-full text-xs shadow-lg animate-fade-in pointer-events-none select-none">
                        <i class="pi pi-info-circle mr-1"></i> Mantén <b>Espacio</b> para mover el lienzo
                    </div>
                    
                   <div class="flex items-center gap-2 bg-white dark:bg-gray-800 p-1.5 rounded-lg shadow-lg border dark:border-gray-700 pointer-events-auto">
                        <Button icon="pi pi-minus" rounded text severity="secondary" @click="zoomOut" />
                        <InputNumber v-model="zoomPercentage" inputClass="!text-center !w-10 !p-1 !text-xs !border-none !ring-0" suffix="%" :min="10" :max="300" />
                        <Button icon="pi pi-plus" rounded text severity="secondary" @click="zoomIn" />
                        <Button icon="pi pi-expand" rounded text severity="info" @click="resetView" v-tooltip.top="'Restablecer vista'" />
                    </div>
                </div>

            </div>

            <!-- PANEL DERECHO: Propiedades -->
            <div class="w-80 border-l dark:border-gray-700 bg-white dark:bg-gray-800 flex flex-col h-full shrink-0 shadow-lg">
                <h3 class="font-bold p-4 border-b text-lg">Propiedades</h3>
                <div v-if="selectedElement" class="flex-1 overflow-y-auto p-4 space-y-6 custom-scrollbar">
                    
                    <!-- Propiedad Alineación (Común) -->
                    <div v-if="['text', 'image', 'local_image', 'barcode', 'qr'].includes(selectedElement.type)">
                        <InputLabel value="Alineación" />
                        <SelectButton v-model="selectedElement.data.align" :options="alignmentOptions" optionLabel="label" optionValue="value" class="w-full">
                            <template #option="slotProps"><i :class="slotProps.option.icon"></i></template>
                        </SelectButton>
                    </div>

                    <!-- TEXTO -->
                    <div v-if="selectedElement.type === 'text'">
                        <InputLabel value="Contenido" class="mt-4" />
                        <Textarea v-model="selectedElement.data.text" rows="6" class="w-full font-mono text-sm" />
                        
                        <Accordion class="mt-4"><AccordionPanel value="0"><AccordionHeader>Variables</AccordionHeader><AccordionContent>
                            <div class="space-y-3">
                                <div v-for="group in placeholderOptions" :key="group.group">
                                    <div class="text-xs font-bold text-gray-500 mb-1">{{ group.group }}</div>
                                    <div class="flex flex-wrap gap-1">
                                        <Button v-for="item in group.items" :key="item.value" @click="insertPlaceholder(item.value)" :label="item.label" severity="secondary" outlined size="small" class="!text-xs !py-1 !px-2" />
                                    </div>
                                </div>
                            </div>
                        </AccordionContent></AccordionPanel></Accordion>
                    </div>

                    <!-- IMAGENES -->
                    <div v-if="['image', 'local_image'].includes(selectedElement.type)">
                        <div v-if="selectedElement.type === 'image'">
                            <InputLabel value="URL Imagen" class="mt-4" />
                            <InputText v-model="selectedElement.data.url" class="w-full text-sm" />
                        </div>

                        <div v-if="selectedElement.type === 'local_image'" class="mt-4 p-3 border rounded bg-gray-50">
                            <div class="grid grid-cols-3 gap-2 max-h-32 overflow-y-auto mb-2">
                                <div v-for="img in localTemplateImages" :key="img.id" class="relative group">
                                    <img :src="img.url" @click="selectedElement.data.url = img.url" class="h-12 w-full object-cover border-2 cursor-pointer rounded hover:border-blue-500" :class="selectedElement.data.url === img.url ? 'border-blue-500' : 'border-transparent'" />
                                </div>
                            </div>
                            <div class="relative">
                                <FileUpload mode="basic" :auto="true" customUpload @uploader="handleImageUpload" accept="image/*" class="w-full" chooseLabel="Subir Imagen" :disabled="selectedElement.data.isUploading" />
                            </div>
                        </div>

                        <InputLabel value="Ancho Máximo (px)" class="mt-4" />
                        <InputNumber v-model="selectedElement.data.width" class="w-full" :min="10" />
                    </div>

                    <!-- CODIGO BARRAS -->
                    <div v-if="selectedElement.type === 'barcode'">
                        <InputLabel value="Tipo" class="mt-4" />
                        <Select v-model="selectedElement.data.type" :options="barcodeTypeOptions" class="w-full" />
                        
                        <InputLabel value="Valor" class="mt-4" />
                        <InputText v-model="selectedElement.data.value" class="w-full" />
                        
                        <InputLabel value="Altura (px)" class="mt-4" />
                        <InputNumber v-model="selectedElement.data.height" class="w-full" :min="20" :max="200" />
                    </div>

                    <!-- QR -->
                    <div v-if="selectedElement.type === 'qr'">
                        <InputLabel value="Valor / URL" class="mt-4" />
                        <InputText v-model="selectedElement.data.value" class="w-full" />
                        
                        <InputLabel value="Tamaño (1-16)" class="mt-4" />
                        <InputNumber v-model="selectedElement.data.size" class="w-full" :min="1" :max="16" showButtons />
                    </div>

                    <!-- TABLA VENTA -->
                    <div v-if="selectedElement.type === 'sales_table'">
                        <p class="text-sm text-gray-500 italic mt-4">Este elemento renderiza automáticamente la lista de productos vendidos.</p>
                    </div>

                </div>
                <div v-else class="text-center text-gray-400 py-20 italic flex-1 flex items-center justify-center px-6"><p>Selecciona un elemento.</p></div>
            </div>
        </div>
    </AppLayout>
</template>