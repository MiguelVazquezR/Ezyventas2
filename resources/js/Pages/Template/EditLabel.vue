<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import { v4 as uuidv4 } from 'uuid';
import AppLayout from '@/Layouts/AppLayout.vue';

// Componentes Reutilizables
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    template: Object,
    branches: Array,
});

const form = useForm({
    _method: 'PUT',
    name: '',
    type: 'etiqueta',
    branch_ids: [],
    content: {
        config: {
            width: 50,
            height: 25,
            unit: 'mm',
            dpi: 203,
            gap: 2,
        },
        elements: [],
    },
});

const templateElements = ref([]);
const selectedElement = ref(null);

// Al montar el componente, se puebla el formulario y el editor con los datos existentes
onMounted(() => {
    if (props.template) {
        form.name = props.template.name;
        form.type = props.template.type;
        form.branch_ids = props.template.branches.map(b => b.id);
        form.content = props.template.content;
        templateElements.value = props.template.content.elements || [];
    }
});

watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

const submit = () => {
    form.post(route('print-templates.update', props.template.id));
};

const availableElements = ref([
    { id: 'text', name: 'Texto', icon: 'pi pi-align-left' },
    { id: 'barcode', name: 'Código de Barras', icon: 'pi pi-bars' },
    { id: 'qr', name: 'Código QR', icon: 'pi pi-qrcode' },
]);

const placeholderOptions = ref([
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
            { label: 'Equipo/Máquina', value: '{{os.item_description}}' }, { label: 'Total', value: '{{os.final_total}}' },
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
    {
        group: 'Productos (para bucles)',
        items: [
            { label: 'Nombre Producto', value: '{{p.nombre}}' }, { label: 'Cantidad', value: '{{p.cantidad}}' },
            { label: 'Precio Unitario', value: '{{p.precio}}' }, { label: 'Total Producto', value: '{{p.total}}' }
        ]
    },
]);

const addElement = (type) => {
    const newElement = {
        id: uuidv4(),
        type: type,
        data: { x: 5, y: 5, rotation: 0 }
    };
    if (type === 'text') {
        newElement.data.value = 'Texto de Ejemplo';
        newElement.data.font_size = 1;
    }
    if (type === 'barcode') {
        newElement.data.value = '{{producto.sku}}';
        newElement.data.type = '128';
        newElement.data.height = 50;
    }
    if (type === 'qr') {
        newElement.data.value = '{{producto.url}}';
        newElement.data.magnification = 4;
    }
    templateElements.value.push(newElement);
    selectedElement.value = newElement;
};

const removeElement = (elementId) => {
    templateElements.value = templateElements.value.filter(el => el.id !== elementId);
    if (selectedElement.value?.id === elementId) {
        selectedElement.value = null;
    }
};

const scale = 4;
const dotsPerMm = computed(() => form.content.config.dpi / 25.4);

const getElementStyle = (element) => {
    const baseStyle = {
        position: 'absolute',
        left: `${element.data.x * scale}px`,
        top: `${element.data.y * scale}px`,
        transform: `rotate(${element.data.rotation}deg)`,
        transformOrigin: 'top left',
        border: '1px dashed #9ca3af',
        padding: '2px',
        whiteSpace: 'nowrap',
        cursor: 'grab',
    };

    if (element.type === 'text') {
        const fontSizes = { 1: 12, 2: 16, 3: 20, 4: 24, 5: 28, 6: 32, 7: 36, 8: 40 };
        baseStyle.fontSize = `${(fontSizes[element.data.font_size] || 12) * (scale / 4)}px`;
    }
    if (element.type === 'barcode') {
        const heightInMm = element.data.height / dotsPerMm.value;
        baseStyle.height = `${heightInMm * scale}px`;
        baseStyle.width = '80%';
    }
    return baseStyle;
};

const isDragging = ref(false);
const dragStart = ref({ x: 0, y: 0 });
const elementStart = ref({ x: 0, y: 0 });

const onDragStart = (event, element) => {
    isDragging.value = true;
    selectedElement.value = element;
    dragStart.value = { x: event.clientX, y: event.clientY };
    elementStart.value = { x: element.data.x, y: element.data.y };
    window.addEventListener('mousemove', onDragMove);
    window.addEventListener('mouseup', onDragEnd);
};

const onDragMove = (event) => {
    if (!isDragging.value) return;
    const deltaX = event.clientX - dragStart.value.x;
    const deltaY = event.clientY - dragStart.value.y;

    const newX = elementStart.value.x + (deltaX / scale);
    const newY = elementStart.value.y + (deltaY / scale);

    selectedElement.value.data.x = Math.max(0, Math.min(newX, form.content.config.width));
    selectedElement.value.data.y = Math.max(0, Math.min(newY, form.content.config.height));
};

const onDragEnd = () => {
    isDragging.value = false;
    window.removeEventListener('mousemove', onDragMove);
    window.removeEventListener('mouseup', onDragEnd);
};

const dpiOptions = ref([203, 300, 600]);

</script>

<template>

    <Head title="Editar Plantilla de Etiqueta" />
    <AppLayout>
        <div class="flex h-[calc(100vh-6rem)]">
            <!-- Columna de Herramientas -->
            <div class="w-1/4 border-r dark:border-gray-700 p-4 overflow-y-auto">
                <h3 class="font-bold mb-4">Configuración de etiqueta</h3>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Nombre de la Plantilla *" />
                        <InputText v-model="form.name" class="w-full mt-1" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div>
                        <InputLabel value="Asignar a Sucursal(es) *" />
                        <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" optionValue="id"
                            placeholder="Selecciona" size="large" class="w-full mt-1" />
                        <InputError :message="form.errors.branch_ids" />
                    </div>
                    <div class="grid grid-cols-2 gap-4 border-t pt-4">
                        <div>
                            <InputLabel value="Ancho (mm)" />
                            <InputNumber fluid showCButtons v-model="form.content.config.width" class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Alto (mm)" />
                            <InputNumber fluid showCButtons v-model="form.content.config.height" class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Resolución (DPI)" />
                            <Select v-model="form.content.config.dpi" :options="dpiOptions" size="large"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Espacio (mm)" />
                            <InputNumber fluid showCButtons v-model="form.content.config.gap" class="w-full mt-1" />
                        </div>
                    </div>
                </div>

                <h3 class="font-bold mb-4 mt-6">Elementos</h3>
                <div class="space-y-2">
                    <Button v-for="el in availableElements" :key="el.id" @click="addElement(el.id)" :label="el.name"
                        :icon="`pi ${el.icon}`" outlined class="w-full justify-start" />
                </div>

                <div class="mt-6 border-t pt-4 flex justify-end gap-2">
                    <Link :href="route('print-templates.index')"><Button label="Cancelar" severity="secondary" text />
                    </Link>
                    <Button @click="submit" label="Guardar Cambios" :loading="form.processing" />
                </div>
            </div>

            <!-- Vista Previa de la Etiqueta -->
            <div class="w-1/2 p-4 overflow-y-auto bg-gray-50 dark:bg-gray-900 flex items-center justify-center">
                <div class="bg-white rounded-md shadow-lg relative border"
                    :style="{ width: `${form.content.config.width * 4}px`, height: `${form.content.config.height * 4}px` }">
                    <p v-if="templateElements.length === 0"
                        class="text-center text-gray-400 absolute inset-0 flex items-center justify-center">Vista Previa
                        de Etiqueta</p>

                    <div v-for="element in templateElements" :key="element.id"
                        @mousedown.prevent="onDragStart($event, element)" :style="getElementStyle(element)"
                        class="flex items-center"
                        :class="{ '!border-blue-500 !border-solid': selectedElement?.id === element.id }">
                        <div v-if="element.type === 'text'" class="whitespace-pre-line text-[10px]">{{ element.data.value }}</div>
                        <div v-if="element.type === 'barcode'"
                            class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                            <i class="pi pi-bars text-xl text-gray-500"></i>
                        </div>
                        <div v-if="element.type === 'qr'" class="w-full h-full flex items-center justify-center">
                            <i class="pi pi-qrcode text-gray-500"
                                :style="{ fontSize: `${element.data.magnification * 8}px` }"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel de Propiedades -->
            <div class="w-1/4 border-l dark:border-gray-700 p-4 overflow-y-auto">
                <h3 class="font-bold mb-4">Propiedades</h3>
                <div v-if="selectedElement" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="X (mm)" />
                            <InputNumber v-model="selectedElement.data.x" class="w-full mt-1" showButtons
                                inputClass="w-full" :step="0.1" />
                        </div>
                        <div>
                            <InputLabel value="Y (mm)" />
                            <InputNumber v-model="selectedElement.data.y" class="w-full mt-1" showButtons
                                inputClass="w-full" :step="0.1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Rotación (°)" />
                        <InputNumber v-model="selectedElement.data.rotation" class="w-full mt-1" showButtons
                            inputClass="w-full" />
                    </div>
                    <Divider />
                    <div v-if="selectedElement.type === 'text'" class="space-y-4">
                        <div>
                            <InputLabel value="Contenido" /><Textarea v-model="selectedElement.data.value" rows="3"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Tamaño de Fuente" />
                            <InputNumber v-model="selectedElement.data.font_size" class="w-full mt-1" showButtons />
                        </div>
                        <Accordion :activeIndex="null">
                            <AccordionTab header="Insertar Variable">
                                <div class="space-y-2">
                                    <div v-for="group in placeholderOptions" :key="group.group">
                                        <p class="text-xs font-bold text-gray-500 mb-1">{{ group.group }}</p>
                                        <div class="flex flex-wrap gap-1">
                                            <Button v-for="item in group.items" :key="item.value"
                                                @click="selectedElement.data.value = (selectedElement.data.value || '') + item.value"
                                                :label="item.label" severity="secondary" outlined size="small" />
                                        </div>
                                    </div>
                                </div>
                            </AccordionTab>
                        </Accordion>
                    </div>
                    <div v-if="selectedElement.type === 'barcode'" class="space-y-4">
                        <div>
                            <InputLabel value="Contenido" />
                            <InputText v-model="selectedElement.data.value" class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Altura (px)" />
                            <InputNumber v-model="selectedElement.data.height" class="w-full mt-1" showButtons />
                        </div>
                    </div>
                    <div v-if="selectedElement.type === 'qr'" class="space-y-4">
                        <div>
                            <InputLabel value="Contenido" />
                            <InputText v-model="selectedElement.data.value" class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Magnificación" />
                            <InputNumber v-model="selectedElement.data.magnification" class="w-full mt-1" showButtons />
                        </div>
                    </div>

                    <Button @click="removeElement(selectedElement.id)" label="Eliminar Elemento" icon="pi pi-trash"
                        severity="danger" text class="w-full mt-4" />
                </div>
                <div v-else class="text-center text-sm text-gray-500 mt-8">
                    <p>Selecciona un elemento para editar sus propiedades.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>