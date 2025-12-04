<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import DiffViewer from '@/Components/DiffViewer.vue';
import { usePermissions } from '@/Composables';
import PatternLock from '@/Components/PatternLock.vue';
import Dialog from 'primevue/dialog';
import ActivityHistory from '@/Components/ActivityHistory.vue';

const props = defineProps({
    quote: Object,
    activities: Array,
    customFieldDefinitions: Array,
    printTemplates: Array, // <-- AÑADIDO: Plantillas disponibles
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Cotizaciones', url: route('quotes.index') },
    { label: `Cotización #${props.quote.folio}` }
]);

// --- Lógica del Flujo de Estatus ---
const steps = ref([
    { label: 'Borrador', value: 'borrador', icon: 'pi pi-file-edit' },
    { label: 'Enviado', value: 'enviado', icon: 'pi pi-send' },
    { label: 'Autorizada', value: 'autorizada', icon: 'pi pi-check-circle' },
    { label: 'Venta generada', value: 'venta_generada', icon: 'pi pi-dollar' },
]);

const activeIndex = computed(() => {
    const index = steps.value.findIndex(step => step.value === props.quote.status);
    return index >= 0 ? index + 1 : 0;
});

const isTerminalStatus = computed(() => ['cancelada'].includes(props.quote.status));
const showStatusBanner = computed(() => ['rechazada', 'expirada', 'cancelada'].includes(props.quote.status));

const changeStatus = (newStatusValue, newIndex) => {
    if (newIndex < activeIndex.value || isTerminalStatus.value || newStatusValue === 'cancelada') return;
    const newStatusLabel = steps.value.find(s => s.value === newStatusValue)?.label || newStatusValue;
    const isGeneratingSale = newStatusValue === 'venta_generada';
    const message = isGeneratingSale
        ? `Al cambiar el estatus a "Venta generada", el sistema creará automáticamente una nueva venta y descontará el inventario. ¿Deseas continuar?`
        : `¿Estás seguro de que quieres cambiar el estatus a "${newStatusLabel}"?`;

    confirm.require({
        message: message,
        header: 'Confirmar cambio de estatus',
        icon: isGeneratingSale ? 'pi pi-dollar' : 'pi pi-sync',
        accept: () => {
            router.patch(route('quotes.updateStatus', props.quote.id), { status: newStatusValue }, { preserveScroll: true });
        }
    });
};

// --- Lógica de Acciones ---
const convertToSale = () => {
    confirm.require({
        message: `Se creará una nueva venta (Transacción) con los datos de esta cotización. El estatus cambiará a "Venta Generada". ¿Deseas continuar?`,
        header: 'Confirmar Conversión a Venta',
        icon: 'pi pi-dollar',
        acceptClass: 'p-button-success',
        accept: () => {
            router.post(route('quotes.convertToSale', props.quote.id), {}, { preserveScroll: true });
        }
    });
};

const cancelSale = () => {
    confirm.require({
        message: `Esta acción cancelará la venta asociada y devolverá el stock. ¿Estás seguro?`,
        header: 'Confirmar Cancelación',
        icon: 'pi pi-times-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.patch(route('quotes.updateStatus', props.quote.id), { status: 'cancelada' }, { preserveScroll: true });
        }
    });
};

const createNewVersion = () => {
    confirm.require({
        message: 'Se creará una nueva versión en estado "Borrador". ¿Deseas continuar?',
        header: 'Crear Nueva Versión',
        icon: 'pi pi-copy',
        accept: () => router.post(route('quotes.newVersion', props.quote.id))
    });
};
const deleteQuote = () => {
    confirm.require({
        message: `¿Eliminar la cotización #${props.quote.folio}?`,
        header: 'Confirmar Eliminación',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(route('quotes.destroy', props.quote.id))
    });
};

// --- LÓGICA DE SELECCIÓN DE PLANTILLA DE IMPRESIÓN ---
const showTemplateDialog = ref(false);
const selectedTemplate = ref(null);

const handlePrintAction = () => {
    if (props.printTemplates && props.printTemplates.length > 0) {
        selectedTemplate.value = null; // Resetear selección (null = defecto)
        showTemplateDialog.value = true;
    } else {
        // Si no hay plantillas, abrir directo la default
        openPrintWindow();
    }
};

const openPrintWindow = () => {
    const url = route('quotes.print', {
        quote: props.quote.id,
        template_id: selectedTemplate.value // Si es null, el backend carga default
    });
    window.open(url, '_blank');
    showTemplateDialog.value = false;
};

const actionItems = computed(() => {
    const quote = props.quote;
    const items = [];

    if (['borrador', 'enviado', 'autorizada'].includes(quote.status) && hasPermission('quotes.edit')) {
        items.push({ label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('quotes.edit', quote.id)) });
    }

    if (hasPermission('quotes.create')) {
        items.push({ label: 'Crear nueva versión', icon: 'pi pi-copy', command: createNewVersion });
    }

    // Acción Modificada: Ahora llama a handlePrintAction
    items.push({
        label: 'Ver PDF / Imprimir',
        icon: 'pi pi-print',
        command: handlePrintAction
    });

    if (quote.status === 'autorizada' && !quote.transaction_id && hasPermission('quotes.create_sale')) {
        items.push({ label: 'Convertir a venta', icon: 'pi pi-dollar', command: convertToSale });
    }

    if (quote.status === 'venta_generada' && hasPermission('quotes.change_status')) {
        items.push({ label: 'Cancelar venta', icon: 'pi pi-times-circle', class: 'text-orange-500', command: cancelSale });
    }

    items.push({ separator: true });

    if (quote.status !== 'venta_generada' && hasPermission('quotes.delete')) {
        items.push({ label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteQuote });
    }

    return items;
});

// --- Helpers ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};

const getItemType = (itemableType) => {
    if (!itemableType) return 'Servicio';
    return itemableType.includes('Product') ? 'Producto' : 'Servicio';
};

const getFormattedCustomValue = (field, value) => {
    if (value === null || value === undefined) return 'N/A';
    switch (field.type) {
        case 'boolean': return value ? 'Sí' : 'No';
        case 'checkbox': return Array.isArray(value) ? value.join(', ') : value;
        default: return value;
    }
};

const allVersions = computed(() => {
    const parent = props.quote.parent ? [props.quote.parent, ...props.quote.parent.versions.filter(v => v.id !== props.quote.id)] : [];
    const selfAndVersions = [props.quote, ...props.quote.versions];
    const combined = [...parent, ...selfAndVersions];
    return [...new Map(combined.map(item => [item.id, item])).values()].sort((a, b) => a.version_number - b.version_number);
});

const hasDetails = computed(() => {
    const q = props.quote;
    return q.recipient_name || q.recipient_email || q.recipient_phone || q.expiry_date || q.shipping_address || q.notes;
});
</script>

<template>

    <Head :title="`Cotización #${quote.folio}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Cotización #{{ quote.folio }}</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Cliente: {{ quote.customer?.name || 'Sin cliente' }}
                </p>
            </div>
            <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0" />
        </div>

        <!-- MODAL DE SELECCIÓN DE PLANTILLA -->
        <Dialog v-model:visible="showTemplateDialog" modal header="Seleccionar formato de impresión"
            :style="{ width: '30rem' }">
            <p class="text-gray-600 dark:text-gray-300 mb-4">Elige el diseño que deseas usar para este documento.</p>

            <div class="space-y-2">
                <!-- Opción Default -->
                <div @click="selectedTemplate = null"
                    class="p-3 rounded-lg border cursor-pointer transition-colors flex items-center gap-3"
                    :class="selectedTemplate === null ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800'">
                    <RadioButton v-model="selectedTemplate" :value="null" class="pointer-events-none" />
                    <div>
                        <div class="font-semibold text-gray-800 dark:text-gray-200">Estándar del Sistema</div>
                        <div class="text-xs text-gray-500">Formato limpio y simple por defecto.</div>
                    </div>
                </div>

                <!-- Plantillas Personalizadas -->
                <div v-for="tpl in printTemplates" :key="tpl.id" @click="selectedTemplate = tpl.id"
                    class="p-3 rounded-lg border cursor-pointer transition-colors flex items-center gap-3"
                    :class="selectedTemplate === tpl.id ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800'">
                    <RadioButton v-model="selectedTemplate" :value="tpl.id" class="pointer-events-none" />
                    <div>
                        <div class="font-semibold text-gray-800 dark:text-gray-200">{{ tpl.name }}</div>
                        <div class="text-xs text-gray-500">Plantilla personalizada.</div>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <Button label="Cancelar" severity="secondary" text @click="showTemplateDialog = false" />
                <Button label="Generar PDF" icon="pi pi-print" @click="openPrintWindow" />
            </div>

            <div class="mt-4 pt-4 border-t dark:border-gray-700 text-center">
                <Link :href="route('print-templates.create', { type: 'cotizacion' })"
                    class="text-xs text-blue-600 hover:underline">
                ¿Quieres un diseño diferente? Crea una nueva plantilla aquí.
                </Link>
            </div>
        </Dialog>

        <!-- Resto del contenido de Show.vue (sin cambios mayores) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- (Se mantiene el código original del layout grid...) -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Flujo de Estatus -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <!-- ... (Código existente del stepper) ... -->
                    <h2 class="text-lg font-semibold border-b pb-3 mb-6">Flujo de estatus</h2>
                    <div v-if="showStatusBanner" class="text-center p-4 rounded-md"
                        :class="{ 'bg-red-50 dark:bg-red-900/20': quote.status === 'rechazada' || quote.status === 'cancelada', 'bg-yellow-50 dark:bg-yellow-900/20': quote.status === 'expirada' }">
                        <p class="font-semibold"
                            :class="{ 'text-red-700 dark:text-red-300': quote.status === 'rechazada' || quote.status === 'cancelada', 'text-yellow-700 dark:text-yellow-300': quote.status === 'expirada' }">
                            <span v-if="quote.status === 'cancelada'">Esta venta ha sido cancelada. El stock ha sido
                                devuelto.</span>
                            <span v-else>Esta cotización ha sido "{{ quote.status.replace('_', ' ') }}".</span>
                        </p>
                    </div>
                    <Stepper v-if="!isTerminalStatus" v-model:value="activeIndex" class="basis-full">
                        <StepList>
                            <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1" asChild>
                                <template #default="{ value, a11yAttrs }">
                                    <div class="flex-auto relative">
                                        <button
                                            class="bg-transparent border-0 inline-flex flex-col gap-2 items-center text-center w-full"
                                            @click="changeStatus(step.value, value)" v-bind="a11yAttrs.header"
                                            :disabled="!hasPermission('quotes.change_status')">
                                            <span
                                                :class="['w-12 h-12 rounded-full border-2 flex items-center justify-center transition-colors duration-200', { 'bg-primary border-primary text-primary-contrast': value <= activeIndex, 'border-surface-200 dark:border-surface-700': value > activeIndex, 'cursor-pointer hover:border-primary': value > activeIndex && hasPermission('quotes.change_status') }]"><i
                                                    :class="step.icon" /></span>
                                            <span
                                                :class="['font-medium text-sm', { 'text-primary': value <= activeIndex }]">{{
                                                    step.label }}</span>
                                        </button>
                                        <div v-if="index < steps.length - 1"
                                            class="absolute top-6 left-[calc(50%+1.5rem)] w-[calc(100%-3rem)]">
                                            <Divider />
                                        </div>
                                    </div>
                                </template>
                            </Step>
                        </StepList>
                    </Stepper>
                </div>

                <!-- Conceptos y Totales -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <!-- ... (Código existente tabla items) ... -->
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Conceptos</h2>
                    <DataTable :value="quote.items" class="p-datatable-sm">
                        <Column header="Tipo" style="width: 10rem">
                            <template #body="{ data }">
                                <Tag :value="getItemType(data.itemable_type)"
                                    :severity="getItemType(data.itemable_type) === 'Producto' ? 'info' : 'success'" />
                            </template>
                        </Column>
                        <Column field="description" header="Descripción">
                            <template #body="{ data }">
                                <div>{{ data.description }}</div>
                                <div v-if="data.variant_details" class="text-xs text-gray-500 mt-1">({{
                                    Object.values(data.variant_details).join(', ') }})</div>
                            </template>
                        </Column>
                        <Column field="quantity" header="Cantidad" style="width: 6rem" class="text-center"></Column>
                        <Column field="unit_price" header="Precio Unit." style="width: 10rem" class="text-right">
                            <template #body="{ data }">{{ formatCurrency(data.unit_price) }}</template>
                        </Column>
                        <Column field="line_total" header="Total" style="width: 10rem" class="text-right"><template
                                #body="{ data }">{{ formatCurrency(data.line_total) }}</template></Column>
                    </DataTable>
                    <div class="mt-4 flex justify-end">
                        <div class="w-full max-w-sm space-y-2 text-sm">
                            <div class="flex justify-between"><span>Subtotal:</span> <span>{{
                                formatCurrency(quote.subtotal) }}</span>
                            </div>
                            <div class="flex justify-between"><span>Descuento:</span> <span class="text-red-500">- {{
                                formatCurrency(quote.total_discount) }}</span></div>
                            <div class="flex justify-between"><span>Impuestos ({{ quote.tax_type === 'included' ?
                                'Incluidos' :
                                (quote.tax_rate || 0) + '%' }}):</span><span>{{ formatCurrency(quote.total_tax)
                                    }}</span></div>
                            <div class="flex justify-between"><span>Envío:</span> <span>{{
                                formatCurrency(quote.shipping_cost) }}</span>
                            </div>
                            <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2">
                                <span>Total:</span><span>{{
                                    formatCurrency(quote.total_amount) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="customFieldDefinitions && customFieldDefinitions.length > 0"
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles adicionales</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                        <template v-for="def in customFieldDefinitions" :key="def.id">
                            <div v-if="quote.custom_fields && quote.custom_fields[def.key]" class="py-2">
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ def.name }}</span>
                                <div class="mt-1 text-gray-800 dark:text-gray-200">
                                    <PatternLock v-if="def.type === 'pattern'" v-model="quote.custom_fields[def.key]"
                                        read-only />
                                    <span v-else>{{ getFormattedCustomValue(def, quote.custom_fields[def.key]) }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-1 space-y-6">
                <!-- ... (Código existente de tarjetas laterales) ... -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles</h2>
                    <ul v-if="hasDetails" class="space-y-2 text-sm">
                        <li v-if="quote.recipient_name" class="flex justify-between"><span
                                class="font-medium text-gray-500 dark:text-gray-400">Destinatario:</span><span
                                class="text-gray-800 dark:text-gray-200 text-right">{{ quote.recipient_name }}</span>
                        </li>
                        <li v-if="quote.recipient_email" class="flex justify-between"><span
                                class="font-medium text-gray-500 dark:text-gray-400">Email:</span><span
                                class="text-gray-800 dark:text-gray-200 text-right">{{ quote.recipient_email }}</span>
                        </li>
                        <li v-if="quote.recipient_phone" class="flex justify-between"><span
                                class="font-medium text-gray-500 dark:text-gray-400">Teléfono:</span><span
                                class="text-gray-800 dark:text-gray-200 text-right">{{ quote.recipient_phone }}</span>
                        </li>
                        <li v-if="quote.expiry_date" class="flex justify-between"><span
                                class="font-medium text-gray-500 dark:text-gray-400">Expiración:</span><span
                                class="text-gray-800 dark:text-gray-200 text-right">{{ formatDate(quote.expiry_date)
                                }}</span></li>
                        <li v-if="quote.shipping_address" class="flex flex-col"><span
                                class="font-medium text-gray-500 dark:text-gray-400">Dirección de envío:</span>
                            <p class="text-gray-800 dark:text-gray-200 mt-1 whitespace-pre-wrap">{{
                                quote.shipping_address }}</p>
                        </li>
                        <li v-if="quote.notes" class="flex flex-col"><span
                                class="font-medium text-gray-500 dark:text-gray-400">Notas adicionales:</span>
                            <p class="text-gray-800 dark:text-gray-200 mt-1 whitespace-pre-wrap">{{ quote.notes }}</p>
                        </li>
                    </ul>
                    <div v-else class="text-center py-4 text-gray-500 dark:text-gray-400">
                        <i class="pi pi-info-circle mr-2"></i>
                        <span>No hay detalles adicionales registrados para esta cotización.</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Versiones</h2>
                    <ul class="space-y-2">
                        <li v-for="version in allVersions" :key="version.id">
                            <Link :href="route('quotes.show', version.id)"
                                class="block p-2 rounded-md transition-colors"
                                :class="version.id === quote.id ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'hover:bg-gray-100 dark:hover:bg-gray-700'">
                            <div class="flex justify-between font-semibold"><span>Versión {{ version.version_number
                            }}</span><span>{{ formatCurrency(version.total_amount) }}</span></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Creada: {{
                                formatDate(version.created_at) }}
                            </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <ActivityHistory :activities="activities" title="Historial de actividad" />
            </div>
        </div>
    </AppLayout>
</template>