<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useToast } from 'primevue/usetoast';
import { v4 as uuidv4 } from 'uuid';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    template: Object,
    branches: Array,
    templateImages: Array,
    customFieldDefinitions: Array,
});

const toast = useToast();
const localTemplateImages = ref([...props.templateImages]);

const form = useForm({
    name: props.template.name,
    type: props.template.type,
    branch_ids: props.template.branches.map(b => b.id),
    content: props.template.content,
});

const templateElements = ref(props.template.content.elements || []);
const selectedElement = ref(null);

watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

const submit = () => {
    form.put(route('print-templates.update', props.template.id));
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

const addElement = (type) => {
    const newElement = { id: uuidv4(), type: type, data: { align: 'left' } };
    if (type === 'text') newElement.data = { text: 'Texto de ejemplo', align: 'left' };
    if (type === 'image') newElement.data = { url: 'https://placehold.co/300x150', width: 300, align: 'center' };
    if (type === 'local_image') newElement.data = { url: '', width: 300, align: 'center', isUploading: false };
    if (type === 'barcode') newElement.data = { type: 'CODE128', value: '{{v.folio}}', align: 'center' };
    if (type === 'qr') newElement.data = { value: '{{os.folio}}', align: 'center' };
    templateElements.value.push(newElement);
    selectedElement.value = newElement;
};

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

const getInitialPlaceholderOptions = () => ([
    {
        group: 'Venta',
        items: [
            { label: 'Folio', value: '{{v.folio}}' }, { label: 'Fecha', value: '{{v.fecha}}' }, { label: 'Hora', value: '{{v.hora}}' },
            { label: 'Fecha y Hora', value: '{{v.fecha_hora}}' }, { label: 'Subtotal', value: '{{v.subtotal}}' }, { label: 'Descuentos', value: '{{v.descuentos}}' },
            { label: 'Impuestos', value: '{{v.impuestos}}' }, { label: 'Total', value: '{{v.total}}' }, { label: 'Métodos de Pago', value: '{{v.metodos_pago}}' },
            { label: 'Notas de Venta', value: '{{v.notas_venta}}' },
        ]
    },
    {
        group: 'Orden de servicio',
        items: [
            { label: 'Folio', value: '{{os.folio}}' }, { label: 'Fecha recepción', value: '{{os.fecha_recepcion}}' }, { label: 'Hora recepción', value: '{{os.hora_recepcion}}' },
            { label: 'Fecha y Hora recepción', value: '{{os.fecha_hora_recepcion}}' }, { label: 'Cliente', value: '{{os.cliente.nombre}}' }, { label: 'Problemas reportados', value: '{{os.problemas_reportados}}' },
            { label: 'Equipo/Máquina', value: '{{os.item_description}}' }, { label: 'Subtotal', value: '{{os.subtotal}}' }, { label: 'Descuento', value: '{{os.descuento}}' }, { label: 'Total', value: '{{os.total}}' },
        ]
    },
    {
        group: 'Negocio',
        items: [
            { label: 'Nombre del Negocio', value: '{{negocio.nombre}}' }, { label: 'Razón Social', value: '{{negocio.razon_social}}' },
            { label: 'Dirección del Negocio', value: '{{negocio.direccion}}' }, { label: 'Teléfono del Negocio', value: '{{negocio.telefono}}' },
        ]
    },
    {
        group: 'Sucursal',
        items: [
            { label: 'Nombre Sucursal', value: '{{sucursal.nombre}}' }, { label: 'Dirección Sucursal', value: '{{sucursal.direccion}}' },
            { label: 'Teléfono Sucursal', value: '{{sucursal.telefono}}' },
        ]
    },
    {
        group: 'Cliente',
        items: [
            { label: 'Nombre del Cliente', value: '{{cliente.nombre}}' }, { label: 'Teléfono del Cliente', value: '{{cliente.telefono}}' },
            { label: 'Email del Cliente', value: '{{cliente.email}}' }, { label: 'Empresa del Cliente', value: '{{cliente.empresa}}' },
        ]
    },
    {
        group: 'Vendedor',
        items: [{ label: 'Nombre del Vendedor', value: '{{vendedor.nombre}}' }]
    },
]);

const placeholderOptions = computed(() => {
    const options = getInitialPlaceholderOptions();
    const customFieldsByModule = {};

    if (props.customFieldDefinitions) {
        props.customFieldDefinitions.forEach(field => {
            if (!customFieldsByModule[field.module]) {
                customFieldsByModule[field.module] = [];
            }
            customFieldsByModule[field.module].push(field);
        });
    }

    for (const moduleKey in customFieldsByModule) {
        if (moduleKey === 'service_orders') {
            options.push({
                group: 'Campos Personalizados (Orden de Servicio)',
                items: customFieldsByModule[moduleKey].map(field => ({
                    label: field.name,
                    value: `{{os.custom.${field.key}}}`
                }))
            });
        }
    }
    return options;
});

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
                        <InputLabel value="Nombre de la Plantilla *" />
                        <InputText v-model="form.name" class="w-full mt-1" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Asignar a Sucursal(es) *" />
                        <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id"
                            placeholder="Selecciona" class="w-full mt-1" />
                        <InputError :message="form.errors.branch_ids" class="mt-1" />
                    </div>
                    <div class="border-t dark:border-gray-600 pt-4">
                        <InputLabel value="Ancho del Papel" />
                        <div class="flex flex-wrap gap-4 mt-2">
                            <div v-for="width in ['80mm', '58mm']" :key="width" class="flex items-center">
                                <RadioButton v-model="form.content.config.paperWidth" :inputId="width" :value="width" />
                                <label :for="width" class="ml-2">{{ width }}</label>
                            </div>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Líneas en blanco al final" />
                        <InputNumber v-model="form.content.config.feedLines" :min="0" :max="10" showButtons
                            class="mt-2" />
                    </div>
                </div>

                <h3 class="font-bold mb-4 mt-6">Elementos</h3>
                <div class="space-y-2">
                    <Button v-for="el in availableElements" :key="el.id" @click="addElement(el.id)" :label="el.name"
                        :icon="`pi ${el.icon}`" outlined class="w-full justify-start" />
                </div>
                <div class="mt-6 border-t dark:border-gray-700 pt-4 flex justify-end gap-2">
                    <Link :href="route('print-templates.index')">
                    <Button label="Cancelar" severity="secondary" text />
                    </Link>
                    <Button @click="submit" label="Guardar Cambios" :loading="form.processing" />
                </div>
            </div>
            <!-- Columna Central: Vista Previa -->
            <div class="w-1/2 p-4 overflow-y-auto bg-gray-50 dark:bg-gray-900">
                <div class="bg-white dark:bg-gray-800 shadow-lg mx-auto p-4"
                    :class="form.content.config.paperWidth === '80mm' ? 'max-w-md' : 'max-w-xs'">
                    <div v-for="element in templateElements" :key="element.id" @click="selectedElement = element"
                        class="py-1 border-2 border-transparent hover:border-dashed rounded-md cursor-pointer relative group"
                        :class="{ '!border-blue-500 border-solid': selectedElement?.id === element.id }">

                        <Button @click.stop="removeElement(element.id)" icon="pi pi-times" severity="danger" text
                            rounded size="small"
                            class="absolute top-0 right-0 opacity-0 group-hover:opacity-100 z-10" />

                        <div :class="`text-${element.data.align}`">
                            <div v-if="element.type === 'text'"
                                class="whitespace-pre-wrap font-mono text-sm break-words">{{ element.data.text }}</div>
                            <img v-if="element.type === 'image' || element.type === 'local_image'"
                                :src="element.data.url || 'https://placehold.co/300x150?text=Imagen'" alt="Imagen"
                                :style="{ maxWidth: (element.data.width * 0.5) + 'px' }"
                                :class="{ 'mx-auto': element.data.align === 'center', 'ml-auto': element.data.align === 'right' }">
                            <div v-if="element.type === 'separator'"
                                class="w-full border-t border-dashed border-gray-400 my-2"></div>
                            <div v-if="element.type === 'barcode'" class="p-2 bg-gray-200 inline-block">
                                <p class="text-xs font-mono tracking-widest">{{ element.data.value }}</p>
                                <p class="text-center text-[8px] uppercase">{{ element.data.type }}</p>
                            </div>
                            <div v-if="element.type === 'line_break'"
                                class="text-center text-xs text-gray-400 my-1 py-1 border-y border-dashed">
                                [Salto de Línea]
                            </div>
                            <div v-if="element.type === 'qr'" class="p-2 bg-gray-200 inline-block">
                                <i class="pi pi-qrcode text-4xl"></i>
                                <p class="text-center text-[8px]">{{ element.data.value }}</p>
                            </div>
                            <div v-if="element.type === 'sales_table'"
                                class="border-2 border-dashed p-4 text-center text-gray-400 font-mono text-sm">
                                <p>[-- Tabla de Productos --]</p>
                                <p class="text-xs">Cantidad, Nombre, Total</p>
                            </div>
                        </div>
                    </div>
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
                        <SelectButton v-model="selectedElement.data.align" :options="alignmentOptions"
                            optionValue="value" class="mt-1 w-full">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <InputLabel class="mt-4">Contenido del Texto</InputLabel>
                        <Textarea v-model="selectedElement.data.text" rows="5" class="w-full mt-1 font-mono text-sm" />
                        <Accordion :activeIndex="null">
                            <AccordionTab header="Insertar Variable">
                                <div class="space-y-2 max-h-64 overflow-y-auto">
                                    <div v-for="group in placeholderOptions" :key="group.group">
                                        <p class="text-xs font-bold text-gray-500 mb-1">{{ group.group }}</p>
                                        <div class="flex flex-wrap gap-1">
                                            <Button v-for="item in group.items" :key="item.value"
                                                @click="insertPlaceholder(item.value)" :label="item.label"
                                                severity="secondary" outlined size="small" />
                                        </div>
                                    </div>
                                </div>
                            </AccordionTab>
                        </Accordion>
                    </div>
                    <div v-if="selectedElement.type === 'image'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <SelectButton v-model="selectedElement.data.align" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>URL de la Imagen</InputLabel>
                            <InputText v-model="selectedElement.data.url" class="w-full mt-1 text-xs" />
                        </div>
                        <div>
                            <InputLabel>Ancho (px)</InputLabel>
                            <InputNumber v-model="selectedElement.data.width" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'local_image'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <SelectButton v-model="selectedElement.data.align" :options="alignmentOptions"
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
                            <InputNumber v-model="selectedElement.data.width" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'barcode'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <SelectButton v-model="selectedElement.data.align" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>Tipo de Código</InputLabel>
                            <Dropdown v-model="selectedElement.data.type" :options="barcodeTypeOptions"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel>Valor</InputLabel>
                            <InputText v-model="selectedElement.data.value" class="w-full mt-1" />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'qr'" class="space-y-4">
                        <InputLabel>Alineación</InputLabel>
                        <SelectButton v-model="selectedElement.data.align" :options="alignmentOptions"
                            optionValue="value" class="mt-1">
                            <template #option="slotProps"> <i :class="slotProps.option.icon"></i> </template>
                        </SelectButton>
                        <div>
                            <InputLabel>Valor</InputLabel>
                            <InputText v-model="selectedElement.data.value" class="w-full mt-1" />
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