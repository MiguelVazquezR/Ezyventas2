<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useToast } from 'primevue/usetoast';
import { v4 as uuidv4 } from 'uuid';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Menu from 'primevue/menu'; // <-- AÑADIDO
import { useTemplateVariables } from '@/Composables/useTemplateVariables';

const props = defineProps({
    template: Object,
    branches: Array,
    templateImages: Array,
    customFieldDefinitions: Array,
});

const toast = useToast();
const localTemplateImages = ref([...props.templateImages]);

const form = useForm({
    _method: 'PUT', // <-- AÑADIDO para el método de actualización
    name: props.template.name,
    type: props.template.type,
    branch_ids: props.template.branches.map(b => b.id),
    content: props.template.content,
});

// Asegurarse de que los elementos tengan IDs únicos si no los tienen (para compatibilidad)
const initializeElements = (elements) => {
    return (elements || []).map(el => ({
        ...el,
        id: el.id || uuidv4() // Asigna un ID si no existe
    }));
};

const templateElements = ref(initializeElements(props.template.content.elements));
const selectedElement = ref(null);

watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

const submit = () => {
    // Usamos form.post con _method: 'PUT'
    form.post(route('print-templates.update', props.template.id));
};

const availableElements = ref([
    { id: 'text', name: 'Texto', icon: 'pi pi-align-left' },
    { id: 'image', name: 'Imagen de Internet', icon: 'pi pi-image' },
    { id: 'local_image', name: 'Subir Imagen', icon: 'pi pi-upload' },
    { id: 'separator', name: 'Separador', icon: 'pi pi-minus' },
    { id: 'line_break', name: 'Salto de Línea', icon: 'pi pi-arrow-down' },
    { id: 'barcode', name: 'Código de Barras', icon: 'pi pi-barcode' },
    { id: 'qr', name: 'Código QR', icon: 'pi pi-qrcode' },
    { id: 'sales_table', name: 'Tabla de Venta', icon: 'pi pi-table' },
]);

// --- NUEVA LÓGICA DE INSERCIÓN (COPIADA DE CREATETICKET) ---
const addMenuRef = ref(null);
const currentInsertionTarget = ref({ id: null, position: 'after' });

const addMenuTemplate = computed(() => {
    return availableElements.value.map(el => ({
        label: el.name,
        icon: el.icon,
        command: () => {
            addElementRelative(el.id);
        }
    }));
});

const openAddMenu = (event, elementId, position) => {
    currentInsertionTarget.value = { id: elementId, position: position };
    if (addMenuRef.value) {
        addMenuRef.value.toggle(event);
    }
};

const createElementOfType = (type) => {
    const newElement = { id: uuidv4(), type: type, data: { align: 'left' } };
    if (type === 'text') newElement.data = { text: 'Texto de ejemplo', align: 'left' };
    if (type === 'image') newElement.data = { url: 'https://placehold.co/300x150', width: 300, align: 'center' };
    if (type === 'local_image') newElement.data = { url: '', width: 300, align: 'center', isUploading: false };
    if (type === 'barcode') newElement.data = { type: 'CODE128', value: '{{v.folio}}', align: 'center', height: 80 }; // Added height
    if (type === 'qr') newElement.data = { value: '{{os.folio}}', align: 'center', size: 5 }; // Added size
    return newElement;
};

const addElementRelative = (type) => {
    const newElement = createElementOfType(type);
    const { id: targetId, position } = currentInsertionTarget.value;

    const targetIndex = templateElements.value.findIndex(el => el.id === targetId);
    if (targetIndex === -1) {
        templateElements.value.push(newElement);
        return;
    }

    if (position === 'before') {
        templateElements.value.splice(targetIndex, 0, newElement);
    } else {
        templateElements.value.splice(targetIndex + 1, 0, newElement);
    }
    selectedElement.value = newElement;
};

const addElementToEnd = (type) => {
    const newElement = createElementOfType(type);
    templateElements.value.push(newElement);
    selectedElement.value = newElement;
};
// --- FIN NUEVA LÓGICA DE INSERCIÓN ---

const removeElement = (elementId) => {
    templateElements.value = templateElements.value.filter(el => el.id !== elementId);
    if (selectedElement.value?.id === elementId) {
        selectedElement.value = null;
    }
};

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
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Imagen subida.', life: 3000 });
        uploader.clear();
    } catch (error) {
        // Handle error
    } finally {
        selectedElement.value.data.isUploading = false;
    }
};

const insertPlaceholder = (placeholder) => {
    if (selectedElement.value && selectedElement.value.type === 'text') {
        selectedElement.value.data.text = (selectedElement.value.data.text || '') + placeholder;
    }
};

const { placeholderOptions } = useTemplateVariables(() => props.customFieldDefinitions);

const alignmentOptions = ref([{ icon: 'pi pi-align-left', value: 'left' }, { icon: 'pi pi-align-center', value: 'center' }, { icon: 'pi pi-align-right', value: 'right' }]);
const barcodeTypeOptions = ref(['CODE128', 'CODE39', 'EAN13', 'UPC-A']);

</script>

<template>
    <AppLayout title="Editar plantilla de ticket">
        <div class="flex h-[calc(100vh-8rem)]">
            <!-- Columna de Herramientas -->
            <div class="w-1/4 border-r dark:border-gray-700 p-4 overflow-y-auto">
                <h3 class="font-bold mb-4">Configuración de ticket</h3>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Nombre de la plantilla *" />
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <InputText :model-value="form.name" @update:model-value="form.name = $event" class="w-full mt-1" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Asignar a sucursal(es) *" />
                        <!-- ACTUALIZADO: v-model a :model-value y size="large" -->
                        <MultiSelect :model-value="form.branch_ids" @update:model-value="form.branch_ids = $event"
                            :options="branches" optionLabel="name" optionValue="id"
                            placeholder="Selecciona" class="w-full mt-1" size="large" />
                        <InputError :message="form.errors.branch_ids" class="mt-1" />
                    </div>
                    <div class="border-t dark:border-gray-600 pt-4">
                        <InputLabel value="Ancho del papel" />
                        <div class="flex flex-wrap gap-4 mt-2">
                            <div v-for="width in ['80mm', '58mm']" :key="width" class="flex items-center">
                                <!-- ACTUALIZADO: v-model a :model-value -->
                                <RadioButton :model-value="form.content.config.paperWidth"
                                    @update:model-value="form.content.config.paperWidth = $event" :inputId="width" :value="width" />
                                <label :for="width" class="ml-2">{{ width }}</label>
                            </div>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Líneas en blanco al final" />
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <InputNumber :model-value="form.content.config.feedLines"
                            @update:model-value="form.content.config.feedLines = $event" :min="0" :max="10" showButtons
                            class="mt-2" />
                    </div>
                </div>

                <h3 class="font-bold mb-4 mt-6">Elementos</h3>
                <div class="space-y-2">
                    <!-- ACTUALIZADO: @click="addElementToEnd" y v-tooltip -->
                    <Button v-for="el in availableElements" :key="el.id" @click="addElementToEnd(el.id)" :label="el.name"
                        :icon="`pi ${el.icon}`" outlined class="w-full justify-start"
                        v-tooltip.right="'Añadir al final'"
                        />
                </div>
                <div class="mt-6 border-t dark:border-gray-700 pt-4 flex justify-end gap-2">
                    <Link :href="route('print-templates.index')">
                    <Button label="Cancelar" severity="secondary" text />
                    </Link>
                    <Button @click="submit" label="Guardar cambios" :loading="form.processing" />
                </div>
            </div>
            
            <!-- Columna Central: Vista Previa -->
            <!-- ACTUALIZADO: @click.self para deseleccionar -->
            <div class="w-1/2 p-4 overflow-y-auto bg-gray-50 dark:bg-gray-900 relative"
                 @click.self="selectedElement = null">

                <!-- AÑADIDO: Menú para inserción -->
                <Menu ref="addMenuRef" :model="addMenuTemplate" :popup="true" />
                
                <!-- ACTUALIZADO: @click.self para deseleccionar -->
                <div class="bg-white dark:bg-gray-800 shadow-lg mx-auto p-4"
                    :class="form.content.config.paperWidth === '80mm' ? 'max-w-md' : 'max-w-xs'"
                    @click.self="selectedElement = null">

                    <!-- ACTUALIZADO: Bucle de elementos con py-px y nuevos botones -->
                    <div v-for="element in templateElements" :key="element.id" @click="selectedElement = element"
                        class="py-px border-2 border-transparent hover:border-dashed rounded-md cursor-pointer relative group"
                        :class="{ '!border-blue-500 border-solid': selectedElement?.id === element.id }">

                        <!-- AÑADIDO: Botón para insertar ANTES -->
                        <div v-if="selectedElement?.id === element.id"
                            class="absolute -top-3 left-1/2 -translate-x-1/2 z-20 opacity-100" @click.stop>
                            <Button icon="pi pi-plus-circle" rounded severity="info" size="small"
                                class="!size-5 !bg-blue-500" @click.stop="openAddMenu($event, element.id, 'before')"
                                v-tooltip.top="'Añadir antes'" />
                        </div>

                        <!-- ACTUALIZADO: Botón de eliminar con !absolute y !size-5 -->
                        <Button @click.stop="removeElement(element.id)" icon="pi pi-times" severity="danger" text
                            rounded size="small"
                            class="!absolute top-0 right-0 opacity-0 group-hover:opacity-100 z-10 !size-5" />

                        <!-- Contenido del elemento (sin cambios) -->
                        <div :class="`text-${element.data.align}`">
                            <div v-if="element.type === 'text'"
                                class="whitespace-pre-wrap font-mono text-sm break-words">{{ element.data.text }}</div>
                            <img v-if="element.type === 'image' || element.type === 'local_image'"
                                :src="element.data.url || 'https://placehold.co/300x150?text=Imagen'" alt="Imagen"
                                :style="{ maxWidth: (element.data.width * 0.5) + 'px' }"
                                :class="{ 'mx-auto': element.data.align === 'center', 'ml-auto': element.data.align === 'right' }">
                            <div v-if="element.type === 'separator'"
                                class="w-full border-t border-dashed border-gray-400 my-2"></div>
                            <!-- MODIFICADO: Cambiado minHeight por height para un escalado preciso -->
                            <div v-if="element.type === 'barcode'" class="p-2 bg-gray-200 inline-flex items-center justify-center"
                                :style="{ height: (element.data.height || 80) / 1.5 + 'px' }">
                                <div>
                                    <p class="text-[10px] font-mono tracking-widest m-0">{{ element.data.value }}</p>
                                    <p class="text-center text-[7px] uppercase m-0">{{ element.data.type }}</p>
                                </div>
                            </div>
                            <div v-if="element.type === 'line_break'"
                                class="text-center text-xs text-gray-400 my-1 py-1 border-y border-dashed">
                                [Salto de Línea]
                            </div>
                            <div v-if="element.type === 'qr'" class="p-2 bg-gray-200 inline-block">
                                <!-- MODIFICADO: Añadido style para tamaño de ícono -->
                                <i class="pi pi-qrcode" :style="{ fontSize: ((element.data.size || 5) * 6) + 'px' }"></i>
                                <p class="text-center text-[8px]">{{ element.data.value }}</p>
                            </div>
                            <div v-if="element.type === 'sales_table'"
                                class="border-2 border-dashed p-4 text-center text-gray-400 font-mono text-sm">
                                <p>[-- Tabla de Productos --]</p>
                                <p class="text-xs">Cantidad, Nombre, Total</p>
                            </div>
                        </div>

                        <!-- AÑADIDO: Botón para insertar DESPUÉS -->
                        <div v-if="selectedElement?.id === element.id"
                            class="absolute -bottom-3 left-1/2 -translate-x-1/2 z-20 opacity-100" @click.stop>
                            <Button icon="pi pi-plus-circle" rounded severity="info" size="small"
                                class="!size-5 !bg-blue-500" @click.stop="openAddMenu($event, element.id, 'after')"
                                v-tooltip.bottom="'Añadir después'" />
                        </div>
                    </div>
                    <!-- Fin del bucle v-for -->

                    <p v-if="!templateElements || templateElements.length === 0" class="text-center text-gray-400 py-16">Añade elementos
                        desde el panel izquierdo.</p>
                </div>
            </div>
            
            <!-- Columna Derecha: Propiedades -->
            <div class="w-1/4 border-l dark:border-gray-700 p-4 overflow-y-auto">
                <h3 class="font-bold mb-4">Propiedades</h3>
                <div v-if="selectedElement" class="space-y-4">
                    <div v-if="selectedElement.type === 'text'">
                        <InputLabel>Alineación</InputLabel>
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <SelectButton :model-value="selectedElement.data.align"
                            @update:model-value="selectedElement.data.align = $event" :options="alignmentOptions"
                            optionValue="value" class="mt-1 w-full">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <InputLabel class="mt-4">Contenido del Texto</InputLabel>
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <Textarea :model-value="selectedElement.data.text"
                            @update:model-value="selectedElement.data.text = $event" rows="5"
                            class="w-full mt-1 font-mono text-sm" />
                        <Accordion :activeIndex="null">
                            <AccordionPanel value="0">
                                <AccordionHeader>Insertar variable</AccordionHeader>
                                <AccordionContent>
                                    <div class="space-y-2 max-h-72 overflow-y-auto">
                                        <div v-for="group in placeholderOptions" :key="group.group">
                                            <p class="text-xs font-bold text-gray-500 mb-1">{{ group.group }}</p>
                                            <div class="flex flex-wrap gap-1">
                                                <Button v-for="item in group.items" :key="item.value"
                                                    @click="insertPlaceholder(item.value)" :label="item.label"
                                                    severity="secondary" outlined size="small" />
                                            </div>
                                        </div>
                                    </div>
                                </AccordionContent>
                            </AccordionPanel>
                        </Accordion>
                    </div>
                    <div v-if="selectedElement.type === 'image'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <SelectButton :model-value="selectedElement.data.align"
                            @update:model-value="selectedElement.data.align = $event" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>URL de la Imagen</InputLabel>
                            <!-- ACTUALIZADO: v-model a :model-value -->
                            <InputText :model-value="selectedElement.data.url"
                                @update:model-value="selectedElement.data.url = $event" class="w-full mt-1 text-xs" />
                        </div>
                        <div>
                            <InputLabel>Ancho (px)</InputLabel>
                            <!-- ACTUALIZADO: v-model a :model-value -->
                            <InputNumber :model-value="selectedElement.data.width"
                                @update:model-value="selectedElement.data.width = $event" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'local_image'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <SelectButton :model-value="selectedElement.data.align"
                            @update:model-value="selectedElement.data.align = $event" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>Galería de Imágenes</InputLabel>
                            <div
                                class="mt-2 grid grid-cols-3 gap-2 max-h-48 overflow-y-auto border dark:border-gray-600 p-2 rounded-md">
                                <img v-for="image in localTemplateImages" :key="image.id" :src="image.url"
                                    :alt="image.name" @click="selectedElement.data.url = image.url"
                                    class="w-full h-16 object-cover rounded-md cursor-pointer border-2"
                                    :class="selectedElement.data.url === image.url ? 'border-blue-500' : 'border-transparent'">
                            </div>
                            <p v-if="localTemplateImages.length === 0" class="text-xs text-center text-gray-500 py-4">No
                                hay imágenes en la galería.</p>
                        </div>
                        <div>
                            <InputLabel>o Subir Nueva Imagen</InputLabel>
                            <FileUpload @uploader="handleImageUpload" :multiple="false" accept="image/*"
                                :showUploadButton="false" :showCancelButton="false" customUpload mode="basic"
                                chooseLabel="Seleccionar Archivo" :auto="true"
                                :loading="selectedElement.data.isUploading" />
                        </div>
                        <div>
                            <InputLabel>Ancho (px)</InputLabel>
                            <!-- ACTUALIZADO: v-model a :model-value -->
                            <InputNumber :model-value="selectedElement.data.width"
                                @update:model-value="selectedElement.data.width = $event" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'barcode'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <SelectButton :model-value="selectedElement.data.align"
                            @update:model-value="selectedElement.data.align = $event" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>Tipo de Código</InputLabel>
                            <!-- ACTUALIZADO: v-model a :model-value -->
                            <Dropdown :model-value="selectedElement.data.type"
                                @update:model-value="selectedElement.data.type = $event" :options="barcodeTypeOptions"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel>Valor</InputLabel>
                            <!-- ACTUALIZADO: v-model a :model-value -->
                            <InputText :model-value="selectedElement.data.value"
                                @update:model-value="selectedElement.data.value = $event" class="w-full mt-1" />
                        </div>
                        <!-- AÑADIDO: Input para altura de código de barras -->
                        <div>
                            <InputLabel>Altura (1-255)</InputLabel>
                            <InputNumber :model-value="selectedElement.data.height"
                                @update:model-value="selectedElement.data.height = $event" class="w-full mt-1" showButtons :min="1" :max="255" />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'qr'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <!-- ACTUALIZADO: v-model a :model-value -->
                        <SelectButton :model-value="selectedElement.data.align"
                            @update:model-value="selectedElement.data.align = $event" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>Valor</InputLabel>
                            <!-- ACTUALIZADO: v-model a :model-value -->
                            <InputText :model-value="selectedElement.data.value"
                                @update:model-value="selectedElement.data.value = $event" class="w-full mt-1" />
                        </div>
                        <!-- AÑADIDO: Input para tamaño de QR -->
                        <div>
                            <InputLabel>Tamaño (1-16)</InputLabel>
                            <InputNumber :model-value="selectedElement.data.size"
                                @update:model-value="selectedElement.data.size = $event" class="w-full mt-1" showButtons :min="1" :max="16" />
                        </div>
                    </div>
                </div>
                <div v-else class="text-center text-sm text-gray-500 mt-8">
                    <p>Selecciona un elemento de la vista previa para editar sus propiedades.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>