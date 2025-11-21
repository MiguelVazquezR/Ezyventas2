<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';
import { v4 as uuidv4 } from 'uuid';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useTemplateVariables } from '@/Composables/useTemplateVariables';
import Checkbox from 'primevue/checkbox'; 

const props = defineProps([
    'branches',
    'templateImages',
    'templateLimit',
    'templateUsage',
    'customFieldDefinitions',
    'printTemplate'
]);

const limitReached = computed(() => {
    const limit = props.templateLimit !== undefined ? props.templateLimit : -1;
    const usage = props.templateUsage || 0;
    if (limit === -1) return false;
    if (props.printTemplate) return false;
    return usage >= limit;
});

const toast = useToast();
const templateElements = ref([]);
const selectedElement = ref(null);
const localTemplateImages = ref(props.templateImages ? [...props.templateImages] : []);
const isUploadingImage = ref(false);

const canvasContainerRef = ref(null);
const paperRef = ref(null);

const zoomScale = ref(1);
const pan = ref({ x: 0, y: 0 });
const isPanning = ref(false);
const lastMousePos = ref({ x: 0, y: 0 });
const isSpacePressed = ref(false);

const showLeftDrawer = ref(false);
const showRightDrawer = ref(false);
const lastPinchDistance = ref(null);

const isDraggingElement = ref(false);
const dragStartPos = ref({ x: 0, y: 0 });
const elementStartPos = ref({ x: 0, y: 0 });
const isJustFinishedDragging = ref(false); 

const lastFocusedColumn = ref('col1');

const form = useForm({
    name: '',
    type: 'cotizacion',
    branch_ids: [],
    content: {
        config: {
            pageSize: 'letter',
            orientation: 'portrait',
            margins: '1.5cm',
            primaryColor: '#3B82F6',
            fontFamily: 'sans-serif'
        },
        elements: [],
    },
});

onMounted(() => {
    if (props.printTemplate) {
        form.name = props.printTemplate.name;
        form.type = props.printTemplate.type;
        form.branch_ids = props.printTemplate.branches ? props.printTemplate.branches.map(b => b.id) : [];
        
        if (props.printTemplate.content) {
            form.content.config = { ...form.content.config, ...props.printTemplate.content.config };
            form.content.elements = props.printTemplate.content.elements || [];
            templateElements.value = props.printTemplate.content.elements || [];
        }
    }
    
    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('keyup', handleKeyUp);
    window.addEventListener('resize', fitToScreen);

    if (canvasContainerRef.value) {
        canvasContainerRef.value.addEventListener('touchstart', handleTouchStart, { passive: false });
        canvasContainerRef.value.addEventListener('touchmove', handleTouchMove, { passive: false });
        canvasContainerRef.value.addEventListener('touchend', handleTouchEnd);
    }
    
    setTimeout(fitToScreen, 100);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    window.removeEventListener('keyup', handleKeyUp);
    window.removeEventListener('resize', fitToScreen);

    if (canvasContainerRef.value) {
        canvasContainerRef.value.removeEventListener('touchstart', handleTouchStart);
        canvasContainerRef.value.removeEventListener('touchmove', handleTouchMove);
        canvasContainerRef.value.removeEventListener('touchend', handleTouchEnd);
    }
});

watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

watch(selectedElement, (val) => {
    if (val && window.innerWidth < 1024) {
        showRightDrawer.value = true;
        showLeftDrawer.value = false;
    }
});

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
        if (canvasContainerRef.value) canvasContainerRef.value.style.cursor = 'default';
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
    if (isSpacePressed.value) {
         if (canvasContainerRef.value) canvasContainerRef.value.style.cursor = 'grab';
    } else {
         if (canvasContainerRef.value) canvasContainerRef.value.style.cursor = 'default';
         document.body.style.cursor = '';
    }
};

const onCanvasClick = () => {
    if (!isSpacePressed.value && !isPanning.value && !isJustFinishedDragging.value) {
        selectedElement.value = null;
    }
};

const getDistance = (touch1, touch2) => {
    const dx = touch1.clientX - touch2.clientX;
    const dy = touch1.clientY - touch2.clientY;
    return Math.sqrt(dx * dx + dy * dy);
};

const handleTouchStart = (e) => {
    if (isDraggingElement.value) return;
    if (e.touches.length === 1) {
        if (e.target === canvasContainerRef.value || isSpacePressed.value) {
            isPanning.value = true;
            lastMousePos.value = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
    } else if (e.touches.length === 2) {
        isPanning.value = false;
        lastPinchDistance.value = getDistance(e.touches[0], e.touches[1]);
    }
};

const handleTouchMove = (e) => {
    if (isDraggingElement.value) return;
    if (e.touches.length === 1 && isPanning.value) {
        if (e.cancelable) e.preventDefault(); 
        const deltaX = e.touches[0].clientX - lastMousePos.value.x;
        const deltaY = e.touches[0].clientY - lastMousePos.value.y;
        pan.value.x += deltaX;
        pan.value.y += deltaY;
        lastMousePos.value = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    } else if (e.touches.length === 2 && lastPinchDistance.value) {
        if (e.cancelable) e.preventDefault(); 
        const dist = getDistance(e.touches[0], e.touches[1]);
        const scaleChange = dist / lastPinchDistance.value;
        let newScale = zoomScale.value * scaleChange;
        newScale = Math.min(Math.max(newScale, 0.1), 3.0);
        zoomScale.value = newScale;
        lastPinchDistance.value = dist;
    }
};

const handleTouchEnd = () => {
    isPanning.value = false;
    lastPinchDistance.value = null;
};

const fitToScreen = () => {
    if (!canvasContainerRef.value) return;
    const containerWidth = canvasContainerRef.value.clientWidth - 64; 
    const containerHeight = canvasContainerRef.value.clientHeight - 64;
    const { w, h } = getPaperPixelDimensions();
    const scaleX = containerWidth / w;
    const scaleY = containerHeight / h;
    zoomScale.value = Math.min(scaleX, scaleY, 1.0);
    pan.value = { x: 0, y: 0 };
};

const zoomIn = () => zoomScale.value = Math.min(zoomScale.value + 0.1, 3.0);
const zoomOut = () => zoomScale.value = Math.max(zoomScale.value - 0.1, 0.1);
const resetView = () => fitToScreen();

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

const getPaperPixelDimensions = () => {
    const dpi = 37.8; 
    let w, h;
    if (form.content.config.pageSize === 'a4') {
        w = 21 * dpi; h = 29.7 * dpi;
    } else { 
        w = 21.59 * dpi; h = 27.94 * dpi;
    }
    if (form.content.config.orientation === 'landscape') return { w: h, h: w };
    return { w, h };
};

const createColorComputed = (propName) => computed({
    get: () => {
        const val = selectedElement.value?.data?.[propName];
        return val ? val.toString().replace(/#/g, '') : 'FFFFFF';
    },
    set: (val) => {
        if (selectedElement.value?.data) {
            const hex = val ? val.toString().replace(/#/g, '') : '000000';
            selectedElement.value.data[propName] = '#' + hex;
        }
    }
});
const currentShapeColor = createColorComputed('color');
const currentHeaderColor = createColorComputed('headerColor');
const currentHeaderTextColor = createColorComputed('headerTextColor');
const currentSeparatorColor = createColorComputed('color');

const handleImageUpload = async (event, uploader) => {
    if (!selectedElement.value || selectedElement.value.type !== 'image') return;
    if (isUploadingImage.value) return;
    isUploadingImage.value = true;
    const currentElement = selectedElement.value;
    currentElement.data.isUploading = true;
    const formData = new FormData();
    formData.append('image', event.files[0]);
    try {
        const response = await axios.post(route('print-templates.media.store'), formData);
        currentElement.data.url = response.data.url;
        localTemplateImages.value.unshift(response.data);
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Imagen subida', life: 2000 });
        setTimeout(() => { if (uploader) uploader.clear(); }, 100);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Error al subir imagen', life: 3000 });
    } finally {
        isUploadingImage.value = false;
        currentElement.data.isUploading = false;
    }
};

const deleteImage = async (imgId) => {
    try {
        if (route().has('print-templates.media.destroy')) {
            await axios.delete(route('print-templates.media.destroy', imgId));
        }
        localTemplateImages.value = localTemplateImages.value.filter(img => img.id !== imgId);
        toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Imagen eliminada', life: 2000 });
    } catch (e) {
        localTemplateImages.value = localTemplateImages.value.filter(img => img.id !== imgId);
    }
};

const { placeholderOptions } = useTemplateVariables(() => props.customFieldDefinitions, 'cotizacion');

const insertVariable = (variable) => {
    if (!selectedElement.value) return;
    const tag = ` ${variable} `;
    if (selectedElement.value.type === 'rich_text') {
        selectedElement.value.data.content += tag;
    } else if (selectedElement.value.type === 'columns_2') {
        if (lastFocusedColumn.value === 'col2') selectedElement.value.data.col2 += tag;
        else selectedElement.value.data.col1 += tag;
    } else if (selectedElement.value.type === 'signature') {
         selectedElement.value.data.label += tag;
    } else {
        navigator.clipboard.writeText(variable).then(() => {
            toast.add({ severity: 'info', summary: 'Copiado', detail: 'Variable copiada al portapapeles' });
        });
    }
};

const selectElement = (element) => {
    if (isSpacePressed.value) return;
    selectedElement.value = element;
};

const getClientPos = (e) => {
    return e.touches ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : { x: e.clientX, y: e.clientY };
};

const startDragElement = (event, element) => {
    if (isSpacePressed.value) return;
    if (element.data.positionType !== 'absolute') return;
    if (event.type === 'touchstart') event.preventDefault();
    event.stopPropagation();
    selectedElement.value = element;
    isDraggingElement.value = true;
    const pos = getClientPos(event);
    dragStartPos.value = { x: pos.x, y: pos.y };
    elementStartPos.value = { x: element.data.x, y: element.data.y };
    if (event.type === 'mousedown') {
        window.addEventListener('mousemove', onDragElementMove);
        window.addEventListener('mouseup', onDragElementEnd);
    } else {
        window.addEventListener('touchmove', onDragElementMove, { passive: false });
        window.addEventListener('touchend', onDragElementEnd);
    }
};

const onDragElementMove = (e) => {
    if (!isDraggingElement.value || !selectedElement.value) return;
    if (e.type === 'touchmove' && e.cancelable) e.preventDefault();
    const pos = getClientPos(e);
    const deltaX = (pos.x - dragStartPos.value.x) / zoomScale.value;
    const deltaY = (pos.y - dragStartPos.value.y) / zoomScale.value;
    selectedElement.value.data.x = Math.round((elementStartPos.value.x + deltaX) / 5) * 5;
    selectedElement.value.data.y = Math.round((elementStartPos.value.y + deltaY) / 5) * 5;
};

const onDragElementEnd = () => {
    isDraggingElement.value = false;
    isJustFinishedDragging.value = true;
    setTimeout(() => { isJustFinishedDragging.value = false; }, 100); 
    window.removeEventListener('mousemove', onDragElementMove);
    window.removeEventListener('mouseup', onDragElementEnd);
    window.removeEventListener('touchmove', onDragElementMove);
    window.removeEventListener('touchend', onDragElementEnd);
};

const submit = () => {
    const routeName = props.printTemplate ? 'print-templates.update' : 'print-templates.store';
    const routeParams = props.printTemplate ? props.printTemplate.id : {};
    const method = props.printTemplate ? 'put' : 'post';
    form[method](route(routeName, routeParams), {
        // onSuccess: () => toast.add({ severity: 'success', summary: 'Guardado', detail: 'Plantilla guardada', life: 3000 }),
        onError: () => toast.add({ severity: 'error', summary: 'Error', detail: 'Por favor revisa los campos requeridos', life: 3000 })
    });
};

const availableElements = ref([
    { id: 'rich_text', name: 'Texto Enriquecido', icon: 'pi pi-align-left', description: 'Párrafos, notas (Flujo)', type: 'flow' },
    { id: 'quote_table', name: 'Tabla de Conceptos', icon: 'pi pi-list', description: 'Lista de productos (Flujo)', type: 'flow' },
    { id: 'columns_2', name: '2 Columnas', icon: 'pi pi-pause', description: 'Info lado a lado (Flujo)', type: 'flow' },
    { id: 'separator', name: 'Separador', icon: 'pi pi-minus', description: 'Línea divisoria (Flujo)', type: 'flow' },
    { id: 'signature', name: 'Firma', icon: 'pi pi-pencil', description: 'Línea de firma (Flujo)', type: 'flow' },
    { id: 'image', name: 'Logo / Imagen', icon: 'pi pi-image', description: 'Libre posición', type: 'absolute' },
    { id: 'shape', name: 'Adorno / Figura', icon: 'pi pi-star', description: 'Libre posición', type: 'absolute' },
]);

const paperDimensions = computed(() => {
    const { w, h } = getPaperPixelDimensions();
    return { w: w + 'px', h: h + 'px' };
});

const addElementToEnd = (type) => {
    const newElement = { id: uuidv4(), type, data: { positionType: 'flow' } };
    if (type === 'rich_text') newElement.data = { ...newElement.data, content: '<p>Texto...</p>', align: 'left' };
    if (type === 'quote_table') newElement.data = { ...newElement.data, showImages: false, headerColor: '#f3f4f6', headerTextColor: '#111827', columns: ['sku', 'descripcion', 'cantidad', 'precio', 'total'], showBreakdown: true };
    if (type === 'columns_2') newElement.data = { ...newElement.data, col1: '<p>Emisor...</p>', col2: '<p>Cliente...</p>', gap: '20px' };
    if (type === 'separator') newElement.data = { ...newElement.data, color: '#e5e7eb', height: 2, style: 'solid', margin: '20px' };
    if (type === 'signature') newElement.data = { ...newElement.data, label: 'Firma', align: 'center', lineWidth: '200px' };
    if (type === 'image') newElement.data = { positionType: 'absolute', url: '', width: 150, x: 50, y: 50, isUploading: false };
    if (type === 'shape') newElement.data = { positionType: 'absolute', shapeType: 'rectangle', color: '#3B82F6', width: 100, height: 100, x: 100, y: 100, opacity: 100, rotation: 0 };
    if (newElement.data.positionType === 'absolute') {
        const count = templateElements.value.filter(e => e.data.positionType === 'absolute').length;
        newElement.data.x = 50 + (count * 20);
        newElement.data.y = 50 + (count * 20);
    }
    templateElements.value.push(newElement);
    selectedElement.value = newElement;
    if (window.innerWidth < 1024) {
        showLeftDrawer.value = false;
        showRightDrawer.value = true;
    }
};

const addMenuRef = ref(null);
const currentInsertionTarget = ref({ id: null, position: 'after' });
const addMenuTemplate = computed(() => {
    return availableElements.value.filter(el => el.type === 'flow').map(el => ({
        label: el.name, icon: el.icon, command: () => addElementRelative(el.id)
    }));
});
const openAddMenu = (event, elementId, position) => {
    currentInsertionTarget.value = { id: elementId, position: position };
    if (addMenuRef.value) addMenuRef.value.toggle(event);
};
const addElementRelative = (type) => {
    const temp = { id: uuidv4(), type, data: { positionType: 'flow' } };
    if (type === 'rich_text') temp.data = { ...temp.data, content: '<p>Nuevo...</p>', align: 'left' };
    if (type === 'quote_table') temp.data = { ...temp.data, showImages: false, headerColor: '#f3f4f6', headerTextColor: '#111827', columns: ['sku', 'descripcion', 'cantidad', 'precio', 'total'], showBreakdown: true };
    if (type === 'columns_2') temp.data = { ...temp.data, col1: '<p>Columna 1</p>', col2: '<p>Columna 2</p>', gap: '20px' };
    if (type === 'separator') temp.data = { ...temp.data, color: '#e5e7eb', height: 2, style: 'solid', margin: '20px' };
    if (type === 'signature') temp.data = { ...temp.data, label: 'Firma', align: 'center', lineWidth: '200px' };
    const { id: targetId, position } = currentInsertionTarget.value;
    const targetIndex = templateElements.value.findIndex(el => el.id === targetId);
    if (targetIndex !== -1) {
        if (position === 'before') templateElements.value.splice(targetIndex, 0, temp);
        else templateElements.value.splice(targetIndex + 1, 0, temp);
    }
    selectedElement.value = temp;
    if (window.innerWidth < 1024) showRightDrawer.value = true;
};

const removeElement = (id) => {
    templateElements.value = templateElements.value.filter(el => el.id !== id);
    if (selectedElement.value?.id === id) selectedElement.value = null;
};

const pageSizeOptions = [{label: 'Carta', value: 'letter'}, {label: 'A4', value: 'a4'}];
const orientationOptions = [{label: 'Vertical', value: 'portrait'}, {label: 'Horizontal', value: 'landscape'}];
const shapeOptions = [{label: 'Rectángulo', value: 'rectangle'}, {label: 'Círculo', value: 'circle'}, {label: 'Estrella', value: 'star'}];
const alignOptions = [
    { label: 'Izquierda', value: 'start', icon: 'pi pi-align-left' }, 
    { label: 'Centro', value: 'center', icon: 'pi pi-align-center' }, 
    { label: 'Derecha', value: 'end', icon: 'pi pi-align-right' }
];
</script>

<template>
    <AppLayout :title="props.printTemplate ? 'Editar cotización' : 'Crear cotización'">
        
        <div v-if="limitReached" class="h-[calc(100vh-7rem)] flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl mx-auto text-center">
                <h1 class="text-2xl font-bold">Límite Alcanzado</h1>
                <Link :href="route('print-templates.index')"><Button label="Volver" class="mt-4"/></Link>
            </div>
        </div>

        <div v-else class="flex flex-col lg:flex-row h-[calc(100vh-7rem)] overflow-hidden bg-gray-100 dark:bg-gray-900 select-none relative">
            
            <div v-if="showLeftDrawer || showRightDrawer" 
                 class="fixed inset-0 bg-black/50 z-30 lg:hidden transition-opacity" 
                 @click="showLeftDrawer = false; showRightDrawer = false">
            </div>

            <div class="lg:hidden w-full h-14 bg-white dark:bg-gray-800 border-b dark:border-gray-700 flex items-center justify-between px-4 z-20 shrink-0 shadow-sm">
                <Button icon="pi pi-bars" text rounded severity="secondary" @click="showLeftDrawer = !showLeftDrawer" v-tooltip.bottom="'Configuración y Elementos'" />
                <span class="font-bold text-sm text-gray-700 dark:text-gray-200 truncate">{{ form.name || 'Nueva Cotización' }}</span>
                <div class="relative">
                    <Button icon="pi pi-pencil" text rounded 
                            :severity="selectedElement ? 'primary' : 'secondary'" 
                            @click="selectedElement ? (showRightDrawer = !showRightDrawer) : null"
                            :disabled="!selectedElement"
                            v-tooltip.bottom="'Propiedades'" />
                    <span v-if="selectedElement" class="absolute top-1 right-1 w-2 h-2 bg-blue-500 rounded-full"></span>
                </div>
            </div>

            <div class="fixed inset-y-0 left-0 w-80 lg:w-80 lg:static transition-transform duration-300 ease-in-out z-40 flex flex-col h-full shadow-2xl lg:shadow-lg bg-white dark:bg-gray-800 border-r dark:border-gray-700"
                 :class="showLeftDrawer ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
                
                <div class="lg:hidden flex items-center justify-between p-4 border-b dark:border-gray-700">
                    <h3 class="font-bold">Herramientas</h3>
                    <Button icon="pi pi-times" text rounded severity="secondary" @click="showLeftDrawer = false" />
                </div>

                <div class="flex-1 overflow-y-auto p-4 custom-scrollbar">
                    <h3 class="font-bold mb-4 text-lg">Configuración cotización</h3>
                    <div class="space-y-4 mb-6">
                        <div>
                            <InputLabel value="Nombre *" />
                            <InputText v-model="form.name" class="w-full p-inputtext-sm" :invalid="!!form.errors.name" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Sucursales" />
                            <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id" placeholder="Seleccionar" class="w-full" :maxSelectedLabels="1" :invalid="!!form.errors.branch_ids" />
                            <InputError :message="form.errors.branch_ids" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><InputLabel value="Tamaño" /><Select v-model="form.content.config.pageSize" :options="pageSizeOptions" optionLabel="label" optionValue="value" class="w-full" /></div>
                            <div><InputLabel value="Orientación" /><Select v-model="form.content.config.orientation" :options="orientationOptions" optionLabel="label" optionValue="value" class="w-full" /></div>
                        </div>
                        <div><InputLabel value="Márgenes" /><InputText v-model="form.content.config.margins" class="w-full" placeholder="2.5cm" /></div>
                    </div>
                    <h3 class="font-bold mb-3 text-lg border-t pt-4">Elementos</h3>
                    <div class="grid grid-cols-1 gap-2">
                        <Button v-for="el in availableElements" :key="el.id" @click="addElementToEnd(el.id)" severity="secondary" outlined class="!justify-start !text-left !py-3">
                            <div class="flex items-center gap-3"><i :class="el.icon" class="text-xl text-blue-500"></i><div><div class="text-sm font-bold">{{ el.name }}</div><div class="text-xs text-gray-500">{{ el.description }}</div></div></div>
                        </Button>
                    </div>
                </div>
                <div class="p-4 border-t dark:border-gray-700 dark:bg-gray-800 mt-auto">
                    <Button @click="submit" :label="props.printTemplate ? 'Actualizar' : 'Guardar'" icon="pi pi-save" class="w-full mb-2" :loading="form.processing" />
                    <Link :href="route('print-templates.index')"><Button label="Cancelar" severity="secondary" text class="w-full" /></Link>
                </div>
            </div>

            <div ref="canvasContainerRef" 
                class="flex-1 relative overflow-hidden dark:bg-black/20 flex items-center justify-center cursor-default group-canvas"
                @click="onCanvasClick"
                @mousedown="startPan" 
                @mousemove="doPan" 
                @mouseup="endPan" 
                @mouseleave="endPan"
                @touchstart="handleTouchStart"
                @touchmove="handleTouchMove"
                @touchend="handleTouchEnd"
            >
                <div v-if="isSpacePressed" 
                    class="absolute inset-0 z-[100] cursor-grab active:cursor-grabbing bg-transparent">
                </div>

                <Menu ref="addMenuRef" :model="addMenuTemplate" :popup="true" />

                <div ref="paperRef" class="bg-white shadow-2xl transition-transform duration-75 ease-linear origin-center"
                    :style="{
                        width: paperDimensions.w,
                        height: paperDimensions.h,
                        transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoomScale})`,
                        padding: form.content.config.margins || '2.5cm'
                    }"
                    @mousedown.stop
                    @touchstart.stop
                    @click.self="selectedElement = null"
                >
                    <div class="w-full h-full relative">
                        <template v-for="element in templateElements" :key="element.id">
                            
                            <!-- FLUJO -->
                            <div v-if="element.data.positionType === 'flow'"
                                @click.stop="selectElement(element)"
                                class="relative group border border-transparent hover:border-dashed hover:border-blue-300 transition-all rounded mb-1"
                                :class="{ '!border-blue-500 bg-blue-50/10': selectedElement?.id === element.id }"
                            >
                                <!-- Controles de Inserción (VISIBILIDAD CONTROLADA) -->
                                <div class="absolute -right-9 top-0 flex flex-col gap-1 z-50"
                                    v-if="selectedElement?.id === element.id">
                                     <Button icon="pi pi-arrow-up" class="!w-7 !h-7 !p-0" rounded severity="secondary" @click.stop="openAddMenu($event, element.id, 'before')" v-tooltip.left="'Insertar Antes'" />
                                     <Button icon="pi pi-arrow-down" class="!w-7 !h-7 !p-0" rounded severity="secondary" @click.stop="openAddMenu($event, element.id, 'after')" v-tooltip.left="'Insertar Después'" />
                                     <Button icon="pi pi-trash" class="!w-7 !h-7 !p-0" rounded severity="danger" @click.stop="removeElement(element.id)" v-tooltip.left="'Eliminar'" />
                                </div>

                                <div v-if="element.type === 'rich_text'" v-html="element.data.content" class="prose max-w-none break-words text-sm pointer-events-none"></div>
                                
                                <div v-if="element.type === 'quote_table'" class="w-full overflow-x-auto pointer-events-none">
                                    <table class="w-full border-collapse text-xs">
                                        <thead>
                                            <tr :style="{ backgroundColor: element.data.headerColor, color: element.data.headerTextColor }">
                                                <th v-if="element.data.showImages" class="p-1 border-b text-center w-10">Img</th>
                                                <th class="p-1 border-b">Cant.</th>
                                                <th class="p-1 border-b text-left">Descripción</th>
                                                <th class="p-1 border-b text-right">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="border-b">
                                                <td v-if="element.data.showImages" class="p-1 text-center"><div class="w-8 h-8 bg-gray-200 rounded mx-auto"></div></td>
                                                <td class="p-1 text-center">1</td>
                                                <td class="p-1">Ejemplo</td>
                                                <td class="p-1 text-right">$100</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    
                                    <!-- MOCKUP DESGLOSE EN EDITOR -->
                                    <div v-if="element.data.showBreakdown !== false" class="flex justify-end mt-2 text-xs text-gray-500 border-t pt-1">
                                        <div class="w-32 text-right space-y-1">
                                            <div class="flex justify-between"><span>Subtotal:</span><span>$100.00</span></div>
                                            <div class="flex justify-between font-bold"><span>Total:</span><span>$116.00</span></div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="element.type === 'columns_2'" class="flex" :style="{ gap: element.data.gap }">
                                    <div class="flex-1 prose text-sm pointer-events-none" v-html="element.data.col1"></div>
                                    <div class="flex-1 prose text-sm pointer-events-none" v-html="element.data.col2"></div>
                                </div>

                                <div v-if="element.type === 'separator'" :style="{ borderTop: `${element.data.height}px ${element.data.style} ${element.data.color}`, margin: `${element.data.margin} 0` }"></div>
                                
                                <div v-if="element.type === 'signature'" 
                                    class="flex flex-col mt-8"
                                    :class="`items-${element.data.align || 'center'}`"
                                >
                                    <div class="border-t border-black pt-1" :style="{ width: element.data.lineWidth }"></div>
                                    <span class="text-xs">{{ element.data.label }}</span>
                                </div>
                            </div>

                            <!-- ABSOLUTOS -->
                            <div v-else-if="element.data.positionType === 'absolute'"
                                @mousedown="startDragElement($event, element)"
                                @touchstart.stop.prevent="startDragElement($event, element)"
                                @click.stop="selectElement(element)" 
                                class="absolute cursor-move group"
                                :style="{ left: element.data.x + 'px', top: element.data.y + 'px', zIndex: selectedElement?.id === element.id ? 50 : 10 }"
                            >
                                <div class="absolute -inset-2 border-2 border-transparent rounded pointer-events-none" :class="{ '!border-blue-500': selectedElement?.id === element.id, 'group-hover:border-blue-300 dashed': selectedElement?.id !== element.id }"></div>
                                <button v-if="selectedElement?.id === element.id" @click.stop="removeElement(element.id)" @touchstart.stop.prevent="removeElement(element.id)" class="absolute -top-4 -right-4 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center shadow z-50"><i class="pi pi-times text-[10px]"></i></button>

                                <div v-if="element.type === 'image'" :style="{ width: element.data.width + 'px' }">
                                    <div class="relative w-full">
                                        <img :src="element.data.url || 'https://placehold.co/150x150'" class="w-full h-auto pointer-events-none select-none" />
                                        <div v-if="element.data.isUploading" class="absolute inset-0 bg-white/70 flex items-center justify-center"><i class="pi pi-spin pi-spinner text-blue-500 text-2xl"></i></div>
                                    </div>
                                </div>

                                <div v-if="element.type === 'shape'">
                                    <div :style="{ width: element.data.width + 'px', height: element.data.height + 'px', backgroundColor: element.data.shapeType !== 'star' ? element.data.color : 'transparent', opacity: element.data.opacity/100, transform: `rotate(${element.data.rotation}deg)`, borderRadius: element.data.shapeType === 'circle' ? '50%' : '0' }">
                                        <svg v-if="element.data.shapeType === 'star'" viewBox="0 0 24 24" class="w-full h-full" :style="{ fill: element.data.color }"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="absolute bottom-6 right-6 flex flex-col items-end gap-2 z-50">
                    <div v-if="!isSpacePressed" class="bg-black/70 text-white px-3 py-1.5 rounded-full text-xs shadow-lg animate-fade-in pointer-events-none select-none hidden lg:block">
                        <i class="pi pi-info-circle mr-1"></i> Mantén <b>Espacio</b> para mover el lienzo
                    </div>
                    
                   <div class="flex items-center gap-2 bg-white dark:bg-gray-800 p-1.5 rounded-lg shadow-lg border dark:border-gray-700 pointer-events-auto">
                        <Button icon="pi pi-minus" rounded text severity="secondary" @click="zoomOut" />
                        <InputNumber v-model="zoomPercentage" inputClass="!text-center !w-10 !p-1 !text-xs !border-none !ring-0" suffix="%" :min="10" :max="300" />
                        <Button icon="pi pi-plus" rounded text severity="secondary" @click="zoomIn" />
                        <Button icon="pi pi-expand" rounded text severity="info" @click="resetView" v-tooltip.top="'Ajustar a pantalla'" />
                    </div>
                </div>
            </div>

            <div class="fixed inset-y-0 right-0 w-80 lg:w-80 lg:static transition-transform duration-300 ease-in-out flex flex-col h-full shadow-2xl lg:shadow-lg bg-white dark:bg-gray-800 border-l dark:border-gray-700"
                 :class="showRightDrawer ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'">
                
                <div class="lg:hidden flex items-center justify-between p-4 border-b dark:border-gray-700">
                    <h3 class="font-bold">Propiedades</h3>
                    <Button icon="pi pi-times" text rounded severity="secondary" @click="showRightDrawer = false" />
                </div>

                <h3 class="hidden lg:block font-bold p-4 border-b text-lg">Propiedades</h3>
                <div v-if="selectedElement" class="flex-1 overflow-y-auto p-4 space-y-6 custom-scrollbar">
                    
                    <div v-if="selectedElement.data.positionType === 'absolute'" class="bg-gray-50 p-3 rounded border">
                        <span class="text-xs font-bold uppercase text-gray-500 mb-2 block">Posición</span>
                        <div class="grid grid-cols-2 gap-2">
                            <div><InputLabel value="X" class="text-xs" /><InputNumber fluid v-model="selectedElement.data.x" class="w-full h-8" inputClass="!py-1" /></div>
                            <div><InputLabel value="Y" class="text-xs" /><InputNumber fluid v-model="selectedElement.data.y" class="w-full h-8" inputClass="!py-1" /></div>
                        </div>
                    </div>

                    <div v-if="['rich_text', 'columns_2'].includes(selectedElement.type)">
                        <div v-if="selectedElement.type === 'rich_text'">
                            <InputLabel value="Contenido" class="mb-2"/>
                            <Editor v-model="selectedElement.data.content" editorStyle="height: 200px" />
                        </div>
                        <div v-if="selectedElement.type === 'columns_2'" class="space-y-4">
                             <div @click="lastFocusedColumn = 'col1'" :class="{'ring-2 ring-blue-200 rounded': lastFocusedColumn === 'col1'}"><InputLabel value="Columna Izquierda" /><Editor v-model="selectedElement.data.col1" editorStyle="height: 100px" /></div>
                             <div @click="lastFocusedColumn = 'col2'" :class="{'ring-2 ring-blue-200 rounded': lastFocusedColumn === 'col2'}"><InputLabel value="Columna Derecha" /><Editor v-model="selectedElement.data.col2" editorStyle="height: 100px" /></div>
                             <div><InputLabel value="Espacio" /><InputText v-model="selectedElement.data.gap" class="w-full" /></div>
                        </div>
                        <Accordion class="mt-4"><AccordionPanel value="0"><AccordionHeader>Variables</AccordionHeader><AccordionContent>
                            <div class="flex flex-col gap-3">
                                <div v-for="group in placeholderOptions" :key="group.group">
                                    <div class="text-xs font-bold text-gray-500 mb-1 uppercase">{{ group.group }}</div>
                                    <div class="flex flex-wrap gap-1">
                                        <Button v-for="item in group.items" :key="item.value" @click="insertVariable(item.value)" :label="item.label" severity="secondary" outlined size="small" class="!text-xs !py-1 !px-2" />
                                    </div>
                                </div>
                            </div>
                        </AccordionContent></AccordionPanel></Accordion>
                    </div>

                    <div v-if="selectedElement.type === 'image'">
                        <InputLabel value="Ancho (px)" /><InputNumber v-model="selectedElement.data.width" class="w-full" :min="20" />
                        
                        <div class="mt-4 p-3 border rounded bg-gray-50">
                             <div class="grid grid-cols-3 gap-2 max-h-32 overflow-y-auto mb-2">
                                <div v-for="img in localTemplateImages" :key="img.id" class="relative group">
                                    <img :src="img.url" @click="selectedElement.data.url = img.url" class="h-12 w-full object-cover border-2 cursor-pointer rounded hover:border-blue-500" :class="selectedElement.data.url === img.url ? 'border-blue-500' : 'border-transparent'" />
                                    <button @click.stop="deleteImage(img.id)" class="absolute top-0 right-0 bg-red-500 text-white p-0.5 rounded-bl opacity-0 group-hover:opacity-100 transition-opacity"><i class="pi pi-trash text-[10px]"></i></button>
                                </div>
                            </div>
                            <div class="relative">
                                <FileUpload mode="basic" :auto="true" customUpload @uploader="handleImageUpload" accept="image/*" class="w-full" chooseLabel="Subir Imagen" :disabled="isUploadingImage" />
                                <div v-if="isUploadingImage" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10"><i class="pi pi-spin pi-spinner text-blue-500"></i></div>
                            </div>
                        </div>
                    </div>

                    <div v-if="selectedElement.type === 'shape'">
                        <InputLabel value="Forma" /><Select v-model="selectedElement.data.shapeType" :options="shapeOptions" optionLabel="label" optionValue="value" class="w-full" />
                        <InputLabel value="Color" class="mt-4" />
                        <div class="flex items-center gap-2 border p-2 rounded bg-white">
                            <ColorPicker v-model="currentShapeColor" format="hex" />
                            <span class="text-gray-400">#</span><InputText v-model="currentShapeColor" class="!border-none !p-0 w-full uppercase font-mono text-sm" maxlength="6" />
                        </div>
                        <div class="grid grid-cols-2 gap-2 mt-4">
                            <div><InputLabel value="Ancho" /><InputNumber fluid v-model="selectedElement.data.width" class="w-full" /></div>
                            <div><InputLabel value="Alto" /><InputNumber fluid v-model="selectedElement.data.height" class="w-full" /></div>
                        </div>
                        <InputLabel value="Opacidad" class="mt-4" /><div class="flex items-center gap-4"><Slider v-model="selectedElement.data.opacity" class="w-full" :min="0" :max="100" /><span class="text-sm w-8">{{ selectedElement.data.opacity }}%</span></div>
                        <InputLabel value="Rotación" class="mt-4" /><InputNumber v-model="selectedElement.data.rotation" class="w-full" :min="0" :max="360" suffix="°" />
                    </div>

                    <div v-if="selectedElement.type === 'quote_table'">
                        <InputLabel value="Fondo Cabecera" />
                        <div class="flex items-center gap-2 mt-1"><ColorPicker v-model="currentHeaderColor" format="hex" /><InputText v-model="currentHeaderColor" class="w-24 h-8 text-sm" /></div>
                        <InputLabel value="Texto Cabecera" class="mt-4" />
                        <div class="flex items-center gap-2 mt-1"><ColorPicker v-model="currentHeaderTextColor" format="hex" /><InputText v-model="currentHeaderTextColor" class="w-24 h-8 text-sm" /></div>
                        
                        <div class="flex items-center gap-2 mt-6">
                            <Checkbox v-model="selectedElement.data.showImages" binary />
                            <label class="text-sm text-gray-700 dark:text-gray-300">Mostrar imágenes</label>
                        </div>

                        <div class="flex items-center gap-2 mt-2">
                            <Checkbox v-model="selectedElement.data.showBreakdown" binary />
                            <label class="text-sm text-gray-700 dark:text-gray-300">Mostrar desglose de totales</label>
                        </div>
                    </div>
                    
                    <div v-if="selectedElement.type === 'separator'">
                        <InputLabel value="Color" />
                        <div class="flex items-center gap-2 mt-1"><ColorPicker v-model="currentSeparatorColor" format="hex" /><InputText v-model="currentSeparatorColor" class="w-24 h-8 text-sm" /></div>
                        <InputLabel value="Grosor" class="mt-4" /><InputNumber v-model="selectedElement.data.height" class="w-full" />
                    </div>

                    <div v-if="selectedElement.type === 'signature'">
                        <InputLabel value="Texto debajo de línea" />
                        <InputText v-model="selectedElement.data.label" class="w-full mb-4" />
                        
                        <InputLabel value="Ancho de Línea" />
                        <InputText v-model="selectedElement.data.lineWidth" class="w-full mb-4" placeholder="Ej: 200px o 50%" v-tooltip.top="'Puede usar px o %'" />

                        <InputLabel value="Alineación" />
                        <SelectButton v-model="selectedElement.data.align" :options="alignOptions" optionLabel="label" optionValue="value" class="w-full">
                             <template #option="slotProps"><i :class="slotProps.option.icon"></i></template>
                        </SelectButton>
                        
                        <div class="mt-4">
                             <Accordion><AccordionPanel value="0"><AccordionHeader>Variables</AccordionHeader><AccordionContent>
                                <div class="flex flex-col gap-3">
                                    <div v-for="group in placeholderOptions" :key="group.group">
                                        <div class="text-xs font-bold text-gray-500 mb-1 uppercase">{{ group.group }}</div>
                                        <div class="flex flex-wrap gap-1">
                                            <Button v-for="item in group.items" :key="item.value" @click="insertVariable(item.value)" :label="item.label" severity="secondary" outlined size="small" class="!text-xs !py-1 !px-2" />
                                        </div>
                                    </div>
                                </div>
                            </AccordionContent></AccordionPanel></Accordion>
                        </div>
                    </div>

                </div>
                <div v-else class="text-center text-gray-400 py-20 italic flex-1 flex items-center justify-center px-6"><p>Selecciona un elemento.</p></div>
            </div>
        </div>
    </AppLayout>
</template>