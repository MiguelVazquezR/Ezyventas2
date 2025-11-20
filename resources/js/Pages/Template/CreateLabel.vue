<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import { v4 as uuidv4 } from 'uuid';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useTemplateVariables } from '@/Composables/useTemplateVariables';

// Props unificadas
const props = defineProps([
    'branches',
    'templateLimit',
    'templateUsage',
    'customFieldDefinitions',
    'printTemplate' // Prop para modo edición
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

// --- ESTADO DE VIEWPORT (ZOOM Y PAN) ---
const canvasContainerRef = ref(null);
const zoomScale = ref(1.5); // Zoom inicial
const pan = ref({ x: 0, y: 50 });
const isPanning = ref(false);
const lastMousePos = ref({ x: 0, y: 0 });
const isSpacePressed = ref(false);

// --- ARRASTRE DE ELEMENTOS (DRAG & DROP) ---
const isDraggingElement = ref(false);
const dragStartPos = ref({ x: 0, y: 0 });
const elementStartPos = ref({ x: 0, y: 0 });
// Estado para prevenir deselección accidental tras arrastrar
const isJustFinishedDragging = ref(false);

// Formulario Unificado
const form = useForm({
    name: '',
    type: 'etiqueta',
    branch_ids: [],
    content: {
        config: {
            width: 50, // mm
            height: 25, // mm
            unit: 'mm',
            dpi: 203,
            gap: 2,
        },
        elements: [],
    },
});

// Inicialización
onMounted(() => {
    if (props.printTemplate) {
        // Modo Edición
        form.name = props.printTemplate.name;
        form.branch_ids = props.printTemplate.branches ? props.printTemplate.branches.map(b => b.id) : [];
        
        if (props.printTemplate.content) {
            form.content.config = { ...form.content.config, ...props.printTemplate.content.config };
            templateElements.value = (props.printTemplate.content.elements || []).map(el => ({
                ...el,
                id: el.id || uuidv4()
            }));
        }
    }
    
    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('keyup', handleKeyUp);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    window.removeEventListener('keyup', handleKeyUp);
});

watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

// --- NAVEGACIÓN DEL CANVAS (PAN/ZOOM) ---

const handleKeyDown = (e) => {
    if (e.code === 'Space' && !isSpacePressed.value) {
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

// Lógica de Deselección Global
const onCanvasClick = () => {
    if (!isSpacePressed.value && !isPanning.value && !isJustFinishedDragging.value) {
        selectedElement.value = null;
    }
};

const zoomIn = () => zoomScale.value = Math.min(zoomScale.value + 0.1, 5.0);
const zoomOut = () => zoomScale.value = Math.max(zoomScale.value - 0.1, 0.5);
const resetView = () => {
    zoomScale.value = 1.5;
    pan.value = { x: 0, y: 50 };
};

const zoomPercentage = computed({
    get: () => Math.round(zoomScale.value * 100),
    set: (val) => {
        if (!val) return;
        let newScale = val / 100;
        if (newScale < 0.1) newScale = 0.1;
        if (newScale > 5.0) newScale = 5.0;
        zoomScale.value = newScale;
    }
});

// --- LÓGICA DE ARRASTRE DE ELEMENTOS (ABSOLUTE DRAG) ---
const startDragElement = (event, element) => {
    if (isSpacePressed.value) return; // Prioridad al paneo
    event.stopPropagation();

    selectedElement.value = element;
    isDraggingElement.value = true;
    dragStartPos.value = { x: event.clientX, y: event.clientY };
    elementStartPos.value = { x: element.data.x, y: element.data.y };

    window.addEventListener('mousemove', onDragElementMove);
    window.addEventListener('mouseup', onDragElementEnd);
};

const onDragElementMove = (event) => {
    if (!isDraggingElement.value || !selectedElement.value) return;

    const deltaX = event.clientX - dragStartPos.value.x;
    const deltaY = event.clientY - dragStartPos.value.y;

    // Ajustamos el delta dividiendo por el zoom para que el movimiento del mouse coincida con el del objeto visualmente
    const newX = elementStartPos.value.x + (deltaX / zoomScale.value / pxPerMm.value);
    const newY = elementStartPos.value.y + (deltaY / zoomScale.value / pxPerMm.value);

    selectedElement.value.data.x = Math.max(0, parseFloat(newX.toFixed(2)));
    selectedElement.value.data.y = Math.max(0, parseFloat(newY.toFixed(2)));
};

const onDragElementEnd = () => {
    isDraggingElement.value = false;
    
    // Activamos bandera para evitar que el evento 'click' subsiguiente deseleccione el elemento
    isJustFinishedDragging.value = true;
    setTimeout(() => { isJustFinishedDragging.value = false; }, 100);

    window.removeEventListener('mousemove', onDragElementMove);
    window.removeEventListener('mouseup', onDragElementEnd);
};


// --- Utils y CRUD ---
const submit = () => {
    const routeName = props.printTemplate ? 'print-templates.update' : 'print-templates.store';
    const routeParams = props.printTemplate ? props.printTemplate.id : {};
    const method = props.printTemplate ? 'put' : 'post';

    form[method](route(routeName, routeParams), {
        // onSuccess: () => toast.add({ severity: 'success', summary: 'Guardado', detail: 'Plantilla guardada correctamente', life: 3000 }),
        onError: () => toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa los campos obligatorios', life: 3000 })
    });
};

const availableElements = ref([
    { id: 'text', name: 'Texto', icon: 'pi pi-align-left' },
    { id: 'barcode', name: 'Código de barras', icon: 'pi pi-barcode' },
    { id: 'qr', name: 'Código QR', icon: 'pi pi-qrcode' },
]);

const addElement = (type) => {
    const newElement = {
        id: uuidv4(),
        type: type,
        data: { x: 2, y: 2, rotation: 0 } // Posición inicial en mm
    };
    
    if (type === 'text') {
        newElement.data.value = 'Texto';
        newElement.data.font_size = 3; // TSPL font size index
    }
    if (type === 'barcode') {
        newElement.data.value = '{{p.sku}}';
        newElement.data.type = '128';
        newElement.data.height = 50; // dots
    }
    if (type === 'qr') {
        newElement.data.value = '{{p.url}}';
        newElement.data.magnification = 4;
    }
    templateElements.value.push(newElement);
    selectedElement.value = newElement;
};

const removeElement = (id) => {
    templateElements.value = templateElements.value.filter(el => el.id !== id);
    if (selectedElement.value?.id === id) selectedElement.value = null;
};

// --- Conversiones de Unidades Visuales ---
// Usamos una constante de visualización para convertir mm a pixeles en pantalla.
// 1mm = 3.78px (approx a 96dpi). Esto es SOLO para visualización CSS.
// La propiedad "dpi" del config es para la impresora real, pero aquí usamos px para dibujar.
const pxPerMm = computed(() => 3.78); 

const labelStyle = computed(() => ({
    width: `${form.content.config.width * pxPerMm.value}px`,
    height: `${form.content.config.height * pxPerMm.value}px`,
}));

// Simulaciones de fuentes TSPL para visualización aproximada (height en dots)
const tsplFontDotHeights = { 1: 12, 2: 20, 3: 24, 4: 32, 5: 48, 6: 64, 7: 80, 8: 96 };

const getElementStyle = (element) => {
    const xPx = element.data.x * pxPerMm.value;
    const yPx = element.data.y * pxPerMm.value;
    
    const baseStyle = {
        position: 'absolute',
        left: `${xPx}px`,
        top: `${yPx}px`,
        transform: `rotate(${element.data.rotation}deg)`,
        transformOrigin: 'top left',
    };

    // Simulaciones visuales basadas en las propiedades
    const visualDpiScale = 1; // Factor de ajuste visual

    if (element.type === 'text') {
        const dotHeight = tsplFontDotHeights[element.data.font_size] || 24;
        // Convertir dots a px visuales (aprox 8 dots = 1mm en 203dpi)
        const mmHeight = dotHeight / 8; 
        baseStyle.fontSize = `${mmHeight * pxPerMm.value * visualDpiScale}px`;
        baseStyle.lineHeight = '1';
        baseStyle.whiteSpace = 'nowrap';
    }
    if (element.type === 'barcode') {
        // Altura en dots -> mm -> px
        const heightMm = element.data.height / 8; 
        baseStyle.height = `${heightMm * pxPerMm.value}px`;
        // Ancho base aproximado
        baseStyle.minWidth = `${20 * pxPerMm.value}px`; 
        baseStyle.backgroundColor = '#e5e7eb';
        baseStyle.display = 'flex';
        baseStyle.alignItems = 'center';
        baseStyle.justifyContent = 'center';
    }
    if (element.type === 'qr') {
        // Maginification controla el tamaño del módulo
        const sizeMm = element.data.magnification * 3; // Estimación
        const sizePx = sizeMm * pxPerMm.value;
        baseStyle.width = `${sizePx}px`;
        baseStyle.height = `${sizePx}px`;
    }

    return baseStyle;
};


// Variables
const { placeholderOptions } = useTemplateVariables(() => props.customFieldDefinitions);
const dpiOptions = [203, 300, 600];

</script>

<template>
    <AppLayout :title="props.printTemplate ? 'Editar Plantilla de Etiqueta' : 'Crear Plantilla de Etiqueta'">
        
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
                        
                        <div class="grid grid-cols-2 gap-2 border-t dark:border-gray-700 pt-4">
                            <div>
                                <InputLabel value="Ancho (mm)" />
                                <InputNumber fluid v-model="form.content.config.width" class="w-full" :min="10" />
                            </div>
                            <div>
                                <InputLabel value="Alto (mm)" />
                                <InputNumber fluid v-model="form.content.config.height" class="w-full" :min="10" />
                            </div>
                            <div>
                                <InputLabel value="Resolución (DPI)" />
                                <Select v-model="form.content.config.dpi" :options="dpiOptions" class="w-full" size="large" />
                            </div>
                            <div>
                                <InputLabel value="Espacio (GAP)" />
                                <InputNumber fluid v-model="form.content.config.gap" class="w-full" suffix=" mm" :min="0" />
                            </div>
                        </div>
                    </div>

                    <h3 class="font-bold mb-3 text-lg border-t pt-4">Elementos</h3>
                    <div class="grid grid-cols-1 gap-2">
                        <Button v-for="el in availableElements" :key="el.id" @click="addElement(el.id)" severity="secondary" outlined class="!justify-start !text-left !py-3">
                            <div class="flex items-center gap-3">
                                <i :class="el.icon" class="text-xl text-blue-500"></i>
                                <div class="font-bold text-sm">{{ el.name }}</div>
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
                 @click="onCanvasClick"
                 @mousedown="startPan"
                 @mousemove="doPan" 
                 @mouseup="endPan" 
                 @mouseleave="endPan">
                
                <!-- Overlay para Mover con Espacio -->
                <div v-if="isSpacePressed" class="absolute inset-0 z-[100] cursor-grab active:cursor-grabbing bg-transparent"></div>

                <!-- Área Transformable -->
                <div class="transition-transform duration-75 ease-linear origin-center shadow-xl bg-white"
                    :style="{
                        transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoomScale})`,
                        width: labelStyle.width,
                        height: labelStyle.height
                    }">
                    
                    <!-- Label Content -->
                    <div class="w-full h-full relative overflow-hidden bg-white">
                        <div v-for="element in templateElements" :key="element.id"
                            @mousedown.stop="startDragElement($event, element)"
                            @click.stop="selectedElement = element"
                            :style="getElementStyle(element)"
                            class="hover:outline hover:outline-1 hover:outline-blue-300 cursor-move select-none"
                            :class="{ '!outline !outline-2 !outline-blue-600 z-50': selectedElement?.id === element.id }">
                            
                            <!-- Visualización de Elementos -->
                            <div v-if="element.type === 'text'" class="text-black">{{ element.data.value }}</div>
                            
                            <div v-if="element.type === 'barcode'" class="w-full h-full flex items-center justify-center border border-gray-300 px-1 bg-gray-100">
                                <i class="pi pi-barcode text-gray-600 text-xl"></i>
                            </div>
                            
                            <div v-if="element.type === 'qr'" class="w-full h-full flex items-center justify-center bg-white border border-gray-300">
                                <i class="pi pi-qrcode text-black text-2xl"></i>
                            </div>

                            <!-- Botón Eliminar Flotante -->
                            <button v-if="selectedElement?.id === element.id" 
                                @click.stop="removeElement(element.id)" 
                                class="absolute -top-3 -right-3 bg-red-500 text-white rounded-full size-4 flex items-center justify-center shadow hover:bg-red-600 z-[60]"
                                title="Eliminar">
                                <i class="pi pi-times !text-[9px]"></i>
                            </button>

                        </div>
                        
                        <!-- Texto vacío -->
                        <div v-if="templateElements.length === 0" class="absolute inset-0 flex items-center justify-center text-gray-300 text-sm pointer-events-none">
                            Lienzo vacío
                        </div>
                    </div>

                </div>

                <!-- Controles Zoom -->
                <div class="absolute bottom-6 right-6 flex flex-col items-end gap-2 z-50">
                    <div v-if="!isSpacePressed" class="bg-black/70 text-white px-3 py-1.5 rounded-full text-xs shadow-lg animate-fade-in pointer-events-none select-none">
                        <i class="pi pi-info-circle mr-1"></i> Mantén <b>Espacio</b> para mover el lienzo
                    </div>
                    
                   <div class="flex items-center gap-2 bg-white dark:bg-gray-800 p-1.5 rounded-lg shadow-lg border dark:border-gray-700 pointer-events-auto">
                        <Button icon="pi pi-minus" rounded text severity="secondary" @click="zoomOut" />
                        <InputNumber v-model="zoomPercentage" inputClass="!text-center !w-10 !p-1 !text-xs !border-none !ring-0" suffix="%" :min="10" :max="500" />
                        <Button icon="pi pi-plus" rounded text severity="secondary" @click="zoomIn" />
                        <Button icon="pi pi-expand" rounded text severity="info" @click="resetView" v-tooltip.top="'Restablecer vista'" />
                    </div>
                </div>

            </div>

            <!-- PANEL DERECHO: Propiedades -->
            <div class="w-80 border-l dark:border-gray-700 bg-white dark:bg-gray-800 flex flex-col h-full shrink-0 shadow-lg">
                <h3 class="font-bold p-4 border-b text-lg">Propiedades</h3>
                <div v-if="selectedElement" class="flex-1 overflow-y-auto p-4 space-y-6 custom-scrollbar">
                    
                    <!-- Posición (Coordenadas) -->
                    <div class="bg-gray-50 p-3 rounded border">
                        <span class="text-xs font-bold uppercase text-gray-500 mb-2 block">Posición (mm)</span>
                        <div class="grid grid-cols-2 gap-2">
                            <div><InputLabel value="X" class="text-xs" /><InputNumber fluid v-model="selectedElement.data.x" class="w-full h-8" inputClass="!py-1" :min="0" :minFractionDigits="1" :maxFractionDigits="2" :step="0.5" /></div>
                            <div><InputLabel value="Y" class="text-xs" /><InputNumber fluid v-model="selectedElement.data.y" class="w-full h-8" inputClass="!py-1" :min="0" :minFractionDigits="1" :maxFractionDigits="2" :step="0.5" /></div>
                        </div>
                        <div class="mt-2">
                            <InputLabel value="Rotación (°)" class="text-xs" />
                            <InputNumber fluid v-model="selectedElement.data.rotation" class="w-full h-8" inputClass="!py-1" :step="90" :min="0" :max="270" />
                        </div>
                    </div>

                    <!-- Propiedades Específicas -->
                    <div v-if="selectedElement.type === 'text'">
                        <InputLabel value="Contenido" />
                        <Textarea v-model="selectedElement.data.value" rows="3" class="w-full mt-1 text-sm font-mono" />
                        
                        <InputLabel value="Tamaño Fuente (TSPL)" class="mt-4" />
                        <InputNumber v-model="selectedElement.data.font_size" class="w-full" showButtons :min="1" :max="10" />
                        <small class="text-gray-500 text-xs">Índice de fuente interna de impresora.</small>

                        <Accordion class="mt-4"><AccordionPanel value="0"><AccordionHeader>Variables</AccordionHeader><AccordionContent>
                            <div class="flex flex-wrap gap-1">
                                <Button v-for="item in placeholderOptions.flatMap(g => g.items)" :key="item.value" 
                                    @click="selectedElement.data.value = (selectedElement.data.value || '') + item.value" 
                                    :label="item.label" severity="secondary" outlined size="small" class="!text-xs !py-1 !px-2" />
                            </div>
                        </AccordionContent></AccordionPanel></Accordion>
                    </div>

                    <div v-if="selectedElement.type === 'barcode'">
                        <InputLabel value="Valor" />
                        <InputText v-model="selectedElement.data.value" class="w-full mt-1" />
                        
                        <InputLabel value="Altura (dots)" class="mt-4" />
                        <InputNumber v-model="selectedElement.data.height" class="w-full" :min="10" />
                    </div>

                    <div v-if="selectedElement.type === 'qr'">
                        <InputLabel value="Valor" />
                        <InputText v-model="selectedElement.data.value" class="w-full mt-1" />
                        
                        <InputLabel value="Magnificación" class="mt-4" />
                        <InputNumber v-model="selectedElement.data.magnification" class="w-full" :min="1" :max="10" showButtons />
                    </div>

                </div>
                <div v-else class="text-center text-gray-400 py-20 italic flex-1 flex items-center justify-center px-6"><p>Selecciona un elemento.</p></div>
            </div>
        </div>
    </AppLayout>
</template>