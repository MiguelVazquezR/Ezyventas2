<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { v4 as uuidv4 } from 'uuid';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    branches: Array,
    templateLimit: Number,
    templateUsage: Number,
    customFieldDefinitions: Array,
});

const limitReached = computed(() => {
    if (props.templateLimit === -1) return false;
    return props.templateUsage >= props.templateLimit;
});

const form = useForm({
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

watch(templateElements, (newElements) => {
    form.content.elements = newElements;
}, { deep: true });

const submit = () => {
    form.post(route('print-templates.store'));
};

const availableElements = ref([
    { id: 'text', name: 'Texto', icon: 'pi pi-align-left' },
    { id: 'barcode', name: 'Código de Barras', icon: 'pi pi-barcode' },
    { id: 'qr', name: 'Código QR', icon: 'pi pi-qrcode' },
]);

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
            { label: 'Equipo/Máquina', value: '{{os.item_description}}' }, { label: 'Total', value: '{{os.total}}' },
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

const placeholderOptions = computed(() => {
    const options = getInitialPlaceholderOptions();
    const customFieldsByModule = {};

    props.customFieldDefinitions.forEach(field => {
        if (!customFieldsByModule[field.module]) {
            customFieldsByModule[field.module] = [];
        }
        customFieldsByModule[field.module].push(field);
    });

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
        newElement.data.value = '{{p.sku}}';
        newElement.data.type = '128';
        newElement.data.height = 50;
    }
    if (type === 'qr') {
        newElement.data.value = '{{p.url}}';
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

const previewContainerRef = ref(null);
const previewContainerSize = ref({ width: 0, height: 0 });
let resizeObserver = null;

onMounted(() => {
    if (previewContainerRef.value) {
        resizeObserver = new ResizeObserver(entries => {
            const entry = entries[0];
            previewContainerSize.value = {
                width: entry.contentRect.width - 32,
                height: entry.contentRect.height - 32,
            };
        });
        resizeObserver.observe(previewContainerRef.value);
    }
});

onUnmounted(() => {
    if (resizeObserver && previewContainerRef.value) {
        resizeObserver.unobserve(previewContainerRef.value);
    }
});

const dotsPerMm = computed(() => form.content.config.dpi / 25.4);

const displayScale = computed(() => {
    if (!previewContainerSize.value.width || !form.content.config.width || !form.content.config.height) {
        return 4;
    }
    const scaleX = previewContainerSize.value.width / form.content.config.width;
    const scaleY = previewContainerSize.value.height / form.content.config.height;
    return Math.min(scaleX, scaleY);
});

const previewStyle = computed(() => {
    return {
        width: `${form.content.config.width * displayScale.value}px`,
        height: `${form.content.config.height * displayScale.value}px`,
    };
});

const tsplFontDotHeights = { 1: 12, 2: 20, 3: 24, 4: 32, 5: 48, 6: 64, 7: 80, 8: 96 };

const getElementStyle = (element) => {
    const baseStyle = {
        position: 'absolute',
        left: `${element.data.x * displayScale.value}px`,
        top: `${element.data.y * displayScale.value}px`,
        transform: `rotate(${element.data.rotation}deg)`,
        transformOrigin: 'top left',
        border: '1px dashed #9ca3af',
        padding: '2px',
        cursor: 'grab',
        overflow: 'hidden',
    };

    if (element.type === 'text') {
        const fontDotHeight = tsplFontDotHeights[element.data.font_size] || 12;
        const fontHeightMm = fontDotHeight / dotsPerMm.value;
        const fontHeightPx = fontHeightMm * displayScale.value;
        baseStyle.fontSize = `${fontHeightPx}px`;
        baseStyle.lineHeight = '1';
        baseStyle.whiteSpace = 'nowrap';
    }
    if (element.type === 'barcode') {
        const heightMm = element.data.height / dotsPerMm.value;
        baseStyle.height = `${heightMm * displayScale.value}px`;
        const widthMm = form.content.config.width - element.data.x;
        baseStyle.width = `${widthMm * displayScale.value}px`;
    }
    if (element.type === 'qr') {
        const size = element.data.magnification * 3 * (displayScale.value / 4);
        baseStyle.fontSize = `${Math.max(12, size)}px`;
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

    const newX = elementStart.value.x + (deltaX / displayScale.value);
    const newY = elementStart.value.y + (deltaY / displayScale.value);

    selectedElement.value.data.x = Math.max(0, newX);
    selectedElement.value.data.y = Math.max(0, newY);
};

const onDragEnd = () => {
    isDragging.value = false;
    window.removeEventListener('mousemove', onDragMove);
    window.removeEventListener('mouseup', onDragEnd);
};

const dpiOptions = ref([203, 300, 600]);
</script>

<template>

    <Head title="Crear Plantilla de Etiqueta" />
    <AppLayout>
        <div v-if="limitReached" class="h-[calc(100vh-6rem)] flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl mx-auto text-center">
                <i class="pi pi-exclamation-triangle !text-6xl text-amber-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Límite de Plantillas Alcanzado</h1>
                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Has alcanzado el límite de <strong>{{ templateLimit }} plantillas</strong> permitido por tu plan
                    actual. Para agregar más, por favor mejora tu plan.
                </p>
                <div class="flex justify-center items-center gap-4">
                    <Link :href="route('print-templates.index')">
                    <Button label="Volver a Plantillas" severity="secondary" outlined />
                    </Link>
                    <a :href="route('subscription.upgrade.show')" target="_blank" rel="noopener noreferrer">
                        <Button label="Mejorar Mi Plan" icon="pi pi-arrow-up" />
                    </a>
                </div>
            </div>
        </div>

        <div v-else class="flex h-[calc(100vh-6rem)]">
            <div class="w-1/4 border-r dark:border-gray-700 p-4 overflow-y-auto">
                <h3 class="font-bold mb-4">Configuración de Etiqueta</h3>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Nombre de la Plantilla *" />
                        <InputText v-model="form.name" class="w-full mt-1" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div>
                        <InputLabel value="Asignar a Sucursal(es) *" />
                        <MultiSelect v-model="form.branch_ids" :options="branches" optionLabel="name" size="large"
                            optionValue="id" placeholder="Selecciona" class="w-full mt-1" />
                        <InputError :message="form.errors.branch_ids" />
                    </div>
                    <div class="grid grid-cols-2 gap-4 border-t pt-4">
                        <div>
                            <InputLabel value="Ancho (mm)" />
                            <InputNumber showButtons fluid v-model="form.content.config.width" class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Alto (mm)" />
                            <InputNumber showButtons fluid v-model="form.content.config.height" class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Resolución (DPI)" />
                            <Select v-model="form.content.config.dpi" :options="dpiOptions" size="large"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Espacio (mm)" />
                            <InputNumber showButtons fluid v-model="form.content.config.gap" class="w-full mt-1" />
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
                    <Button @click="submit" label="Crear Plantilla" :loading="form.processing" />
                </div>
            </div>

            <div ref="previewContainerRef"
                class="w-1/2 p-4 overflow-auto bg-gray-50 dark:bg-gray-900 flex items-center justify-center">
                <div class="bg-white rounded-md shadow-lg relative border shrink-0" :style="previewStyle">
                    <p v-if="templateElements.length === 0"
                        class="text-center text-gray-400 absolute inset-0 flex items-center justify-center">Vista Previa
                        de Etiqueta</p>

                    <div v-for="element in templateElements" :key="element.id"
                        @mousedown.prevent="onDragStart($event, element)" :style="getElementStyle(element)"
                        class="flex items-center"
                        :class="{ '!border-blue-500 !border-solid': selectedElement?.id === element.id }">

                        <div v-if="element.type === 'text'" class="h-full w-full flex items-center">
                            <span>{{ element.data.value }}</span>
                        </div>
                        <div v-if="element.type === 'barcode'"
                            class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                            <i class="pi pi-barcode text-xl text-gray-500"></i>
                        </div>
                        <div v-if="element.type === 'qr'"
                            class="w-full h-full flex items-center justify-center">
                            <i class="pi pi-qrcode text-gray-500"
                                :style="{ fontSize: getElementStyle(element).fontSize }"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-1/4 border-l dark:border-gray-700 p-4 overflow-y-auto">
                <h3 class="font-bold mb-4">Propiedades</h3>
                <div v-if="selectedElement" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="X (mm)" />
                            <InputNumber v-model="selectedElement.data.x" class="w-full mt-1" showButtons
                                inputClass="w-full" :step="0.1" :min="0" :minFractionDigits="1" :maxFractionDigits="2" />
                        </div>
                        <div>
                            <InputLabel value="Y (mm)" />
                            <InputNumber v-model="selectedElement.data.y" class="w-full mt-1" showButtons
                                inputClass="w-full" :step="0.1" :min="0" :minFractionDigits="1" :maxFractionDigits="2" />
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
                            <InputNumber v-model="selectedElement.data.font_size" class="w-full mt-1" showButtons :min="1" :max="8" />
                        </div>
                        <Accordion :activeIndex="null">
                            <AccordionTab header="Insertar variable">
                                <div class="space-y-2 max-h-72 overflow-y-auto">
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
                            <InputLabel value="Altura (dots)" />
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
                            <InputNumber v-model="selectedElement.data.magnification" class="w-full mt-1" showButtons :min="1" :max="10" />
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