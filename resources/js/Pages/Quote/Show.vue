<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import { usePermissions } from '@/Composables';
import PatternLock from '@/Components/PatternLock.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';

const props = defineProps({
    quote: Object,
    activities: Array,
    customFieldDefinitions: Array,
    printTemplates: Array, 
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

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
        : `¿Estás seguro de que quieres avanzar el estatus a "${newStatusLabel}"?`;

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
        selectedTemplate.value = null; 
        showTemplateDialog.value = true;
    } else {
        openPrintWindow();
    }
};

const openPrintWindow = () => {
    const url = route('quotes.print', {
        quote: props.quote.id,
        template_id: selectedTemplate.value 
    });
    window.open(url, '_blank');
    showTemplateDialog.value = false;
};

// --- Menú de Acciones ---
const actionsMenu = ref();
const toggleActionsMenu = (event) => actionsMenu.value.toggle(event);

const actionItems = computed(() => {
    const quote = props.quote;
    const items = [];

    if (['borrador', 'enviado', 'autorizada'].includes(quote.status) && hasPermission('quotes.edit')) {
        items.push({ label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('quotes.edit', quote.id)) });
    }

    if (hasPermission('quotes.create')) {
        items.push({ label: 'Crear nueva versión', icon: 'pi pi-copy', command: createNewVersion });
    }

    items.push({ label: 'Ver PDF / Imprimir', icon: 'pi pi-print', command: handlePrintAction });

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
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};

const getStatusSeverity = (status) => {
    const map = { borrador: 'secondary', enviado: 'info', autorizada: 'success', rechazada: 'danger', venta_generada: 'success', expirada: 'warn', cancelada: 'danger' };
    return map[status] || 'secondary';
};

const getItemType = (itemableType) => {
    if (!itemableType) return { text: 'Servicio', icon: 'pi-wrench', severity: 'success' };
    return itemableType.includes('Product') 
        ? { text: 'Producto', icon: 'pi pi-box', severity: 'info' } 
        : { text: 'Servicio', icon: 'pi pi-wrench', severity: 'success' };
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

// --- TESLA UI PASS-THROUGH (PT) ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } 
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[9px] !mr-1.5' }
};

const stepperPt = { root: { class: 'w-full' } };
const stepListPt = { root: { class: 'flex justify-between items-center w-full !bg-transparent !p-0 !border-none' } };
const stepPt = { root: { class: 'flex-1 first:flex-initial last:flex-initial !bg-transparent !border-none !p-0' } };
</script>

<template>
    <Head :title="`Cotización #${quote.folio}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('quotes.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al catálogo de cotizaciones
                </Link>
            </div>

            <!-- Header Principal -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-4">
                        Cotización #{{ quote.folio }}
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <Tag :value="quote.status.replace('_', ' ')" :severity="getStatusSeverity(quote.status)" class="capitalize" :pt="tagPt" />
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Cliente:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                <i class="pi pi-user !text-[10px] text-gray-400"></i>
                                {{ quote.customer?.name || quote.recipient_name || 'Público general' }}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="w-full sm:w-auto shrink-0 flex gap-2">
                    <Button type="button" label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleActionsMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                    <Menu ref="actionsMenu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <!-- Grid Content -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                
                <!-- Columna Principal -->
                <div class="lg:col-span-2 space-y-6 lg:space-y-8 flex flex-col">
                    
                    <!-- Stepper -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col w-full overflow-hidden">
                        <div class="mb-8 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                <i class="pi pi-sitemap !text-sm text-blue-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Flujo de estatus</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Seguimiento de la cotización</p>
                            </div>
                        </div>

                        <!-- Banners de Estados Terminales -->
                        <div v-if="showStatusBanner" class="p-6 rounded-2xl border flex flex-col items-center justify-center text-center"
                            :class="{ 
                                'bg-red-50 dark:bg-red-900/10 border-red-100 dark:border-red-900/30 text-red-700 dark:text-red-400': ['rechazada', 'cancelada'].includes(quote.status), 
                                'bg-orange-50 dark:bg-orange-900/10 border-orange-100 dark:border-orange-900/30 text-orange-700 dark:text-orange-400': quote.status === 'expirada' 
                            }">
                            <i class="pi pi-times-circle !text-4xl mb-3" v-if="['rechazada', 'cancelada'].includes(quote.status)"></i>
                            <i class="pi pi-clock !text-4xl mb-3" v-if="quote.status === 'expirada'"></i>
                            
                            <p class="font-bold text-sm m-0 tracking-tight">
                                <span v-if="quote.status === 'cancelada'">Esta venta ha sido cancelada y el inventario ha sido liberado.</span>
                                <span v-else>Esta cotización se encuentra: "{{ quote.status.replace('_', ' ').toUpperCase() }}".</span>
                            </p>
                        </div>
                        
                        <!-- Stepper Visual -->
                        <div v-if="!isTerminalStatus" class="w-full overflow-x-auto custom-scrollbar pb-2">
                            <Stepper v-model:value="activeIndex" class="min-w-[500px]" :pt="stepperPt">
                                <StepList :pt="stepListPt">
                                    <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1" v-slot="{ value }" asChild :pt="stepPt">
                                        <div class="flex flex-row items-center" :class="index !== steps.length - 1 ? 'w-full' : 'w-auto'">
                                            
                                            <button class="bg-transparent border-0 inline-flex flex-col gap-3 items-center justify-center focus:outline-none shrink-0 w-24"
                                                @click="changeStatus(step.value, value)" 
                                                :disabled="!hasPermission('quotes.change_status')">
                                                
                                                <span :class="[
                                                    'w-12 h-12 rounded-full border-2 flex items-center justify-center transition-all duration-300 relative z-10', 
                                                    { 
                                                        'bg-blue-500 border-blue-500 text-white shadow-[0_0_12px_rgba(59,130,246,0.6)] scale-110': value === activeIndex,
                                                        'bg-blue-500 border-blue-500 text-white': value < activeIndex,
                                                        'bg-gray-50 dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a] text-gray-400': value > activeIndex, 
                                                        'cursor-pointer hover:border-blue-400 hover:text-blue-500 dark:hover:border-blue-500': value > activeIndex && hasPermission('quotes.change_status'),
                                                        'cursor-not-allowed': !hasPermission('quotes.change_status')
                                                    }
                                                ]">
                                                    <i :class="step.icon" class="!text-lg" />
                                                </span>
                                                
                                                <span :class="[
                                                    'text-[10px] uppercase tracking-widest text-center leading-tight m-0', 
                                                    { 
                                                        'text-blue-600 dark:text-blue-400 font-bold': value <= activeIndex, 
                                                        'text-gray-500 font-medium': value > activeIndex 
                                                    }
                                                ]">
                                                    {{ step.label }}
                                                </span>
                                            </button>

                                            <!-- Línea Conectora -->
                                            <div v-if="index !== steps.length - 1" 
                                                 class="h-1 flex-grow rounded-full mx-2 transition-all duration-500 relative -top-3"
                                                 :class="value < activeIndex ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.5)]' : 'bg-gray-100 dark:bg-[#3a3a3a]'">
                                            </div>
                                        </div>
                                    </Step>
                                </StepList>
                            </Stepper>
                        </div>
                    </div>

                    <!-- Conceptos -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex justify-between items-start gap-4">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Conceptos cotizados</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Desglose de productos y servicios</p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0 border border-cyan-100 dark:border-cyan-900/30">
                                <i class="pi pi-box !text-sm text-cyan-500"></i>
                            </div>
                        </div>

                        <DataTable :value="quote.items" responsiveLayout="scroll" :pt="dataTablePt">
                            <Column header="Tipo" style="width: 8rem">
                                <template #body="{ data }">
                                    <Tag 
                                        :value="getItemType(data.itemable_type).text" 
                                        :severity="getItemType(data.itemable_type).severity" 
                                        :icon="getItemType(data.itemable_type).icon"
                                        :pt="tagPt" 
                                    />
                                </template>
                            </Column>
                            <Column field="description" header="Descripción">
                                <template #body="{ data }">
                                    <span class="font-medium text-gray-900 dark:text-gray-100 leading-tight m-0 block">{{ data.description }}</span>
                                    <span v-if="data.variant_details" class="text-[10px] text-gray-500 mt-1 block">
                                        ({{ Object.values(data.variant_details).join(', ') }})
                                    </span>
                                </template>
                            </Column>
                            <Column field="quantity" header="Cant." class="text-center" headerClass="text-center" style="width: 5rem">
                                <template #body="{ data }"><span class="font-mono text-sm">{{ data.quantity }}</span></template>
                            </Column>
                            <Column field="unit_price" header="Precio Unit." class="text-right" headerClass="text-right" style="width: 8rem">
                                <template #body="{ data }"><span class="font-mono text-sm">{{ formatCurrency(data.unit_price) }}</span></template>
                            </Column>
                            <Column field="line_total" header="Total" class="text-right" headerClass="text-right" style="width: 8rem">
                                <template #body="{ data }"><span class="font-mono text-base font-bold text-gray-900 dark:text-white m-0">{{ formatCurrency(data.line_total) }}</span></template>
                            </Column>
                        </DataTable>
                        
                        <!-- Totales -->
                        <div class="flex justify-end mt-6">
                            <div class="w-full sm:w-80 bg-gray-50 dark:bg-[#1a1a1a] p-4 lg:p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Subtotal</span>
                                    <span class="font-mono text-sm text-gray-900 dark:text-gray-300 m-0">{{ formatCurrency(quote.subtotal) }}</span>
                                </div>
                                
                                <div v-if="quote.total_discount > 0" class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1">
                                        <i class="pi pi-tag !text-[9px]"></i> Descuento
                                    </span>
                                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(quote.total_discount) }}</span>
                                </div>

                                <div class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                                        Impuestos {{ quote.tax_type === 'included' ? '(Incluidos)' : '' }}
                                    </span>
                                    <span class="font-mono text-sm text-gray-900 dark:text-gray-300 m-0">
                                        {{ quote.tax_type === 'included' ? '0.00' : '+ ' + formatCurrency(quote.total_tax) }}
                                    </span>
                                </div>

                                <div v-if="quote.shipping_cost > 0" class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Envío / Visita</span>
                                    <span class="font-mono text-sm text-gray-900 dark:text-gray-300 m-0">+ {{ formatCurrency(quote.shipping_cost) }}</span>
                                </div>
                                
                                <div class="border-t border-gray-200 dark:border-[#2a2a2a] my-1"></div>
                                
                                <div class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-900 dark:text-gray-100 m-0">Total neto</span>
                                    <span class="font-light tracking-tight text-xl text-primary-600 dark:text-primary-400 m-0">{{ formatCurrency(quote.total_amount) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Campos Personalizados -->
                    <div v-if="customFieldDefinitions && customFieldDefinitions.length > 0" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                                <i class="pi pi-list !text-sm text-purple-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Detalles adicionales</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Información personalizada de la cotización</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <template v-for="def in customFieldDefinitions" :key="def.id">
                                <div v-if="quote.custom_fields && quote.custom_fields[def.key]" class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 block mb-2">{{ def.name }}</span>
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        <PatternLock v-if="def.type === 'pattern'" v-model="quote.custom_fields[def.key]" read-only class="transform scale-75 origin-top-left" />
                                        <span v-else>{{ getFormattedCustomValue(def, quote.custom_fields[def.key]) }}</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Historial de actividad -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center flex-shrink-0 border border-gray-200 dark:border-[#3a3a3a]">
                                <i class="pi pi-history !text-sm text-gray-500"></i>
                            </div>
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Historial de actividad</h2>
                                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Auditoría y registro de cambios</p>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 lg:p-6 overflow-hidden">
                            <ActivityHistory :activities="activities" />
                        </div>
                    </div>
                </div>

                <!-- Columna Secundaria (Derecha) -->
                <div class="lg:col-span-1 space-y-6 lg:space-y-8 flex flex-col">
                    
                    <!-- Tarjeta de Detalles (Contacto / Envío) -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-5">Detalles comerciales</h2>
                        
                        <ul v-if="hasDetails" class="m-0 p-0 list-none space-y-4">
                            <li v-if="quote.recipient_name" class="flex flex-col border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Atención a:</span>
                                <span class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ quote.recipient_name }}</span>
                            </li>
                            <li v-if="quote.recipient_email" class="flex flex-col border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Email:</span>
                                <span class="font-medium text-sm text-gray-900 dark:text-gray-100 break-all">{{ quote.recipient_email }}</span>
                            </li>
                            <li v-if="quote.recipient_phone" class="flex flex-col border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Teléfono:</span>
                                <span class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ quote.recipient_phone }}</span>
                            </li>
                            <li v-if="quote.expiry_date" class="flex flex-col border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Válida hasta:</span>
                                <span class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ formatDate(quote.expiry_date) }}</span>
                            </li>
                            <li v-if="quote.shipping_address" class="flex flex-col border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Dirección de envío:</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-200 m-0 leading-relaxed whitespace-pre-wrap">{{ quote.shipping_address }}</p>
                            </li>
                            <li v-if="quote.notes" class="flex flex-col pt-1">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Notas adicionales:</span>
                                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                    <p class="text-sm m-0 italic text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap">{{ quote.notes }}</p>
                                </div>
                            </li>
                        </ul>
                        <div v-else class="text-center py-6 text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a]">
                            <i class="pi pi-info-circle !text-xl mb-2"></i>
                            <p class="text-xs m-0">No hay detalles adicionales registrados.</p>
                        </div>
                    </div>

                    <!-- Tarjeta de Versiones -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="mb-5 flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                                <i class="pi pi-copy !text-xs text-orange-500"></i>
                            </div>
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Versiones previas</h2>
                        </div>
                        
                        <ul class="m-0 p-0 list-none space-y-3">
                            <li v-for="version in allVersions" :key="version.id">
                                <Link :href="route('quotes.show', version.id)"
                                    class="block p-4 rounded-2xl border transition-colors group"
                                    :class="version.id === quote.id ? 'bg-primary-50 dark:bg-primary-900/10 border-primary-200 dark:border-primary-800' : 'bg-gray-50 dark:bg-[#1a1a1a] border-gray-100 dark:border-[#3a3a3a] hover:border-gray-300 dark:hover:border-gray-600'">
                                    
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="font-bold text-sm tracking-tight" :class="version.id === quote.id ? 'text-primary-700 dark:text-primary-400' : 'text-gray-900 dark:text-white'">
                                            Versión {{ version.version_number }}
                                        </span>
                                        <span class="font-mono text-sm" :class="version.id === quote.id ? 'text-primary-600 dark:text-primary-500' : 'text-gray-700 dark:text-gray-300'">
                                            {{ formatCurrency(version.total_amount) }}
                                        </span>
                                    </div>
                                    <div class="text-[10px] uppercase tracking-widest" :class="version.id === quote.id ? 'text-primary-500/80' : 'text-gray-500'">
                                        Creada: {{ formatDate(version.created_at) }}
                                    </div>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL DE SELECCIÓN DE PLANTILLA (Tesla UI) -->
        <Dialog v-model:visible="showTemplateDialog" modal class="w-full max-w-md mx-4" :pt="dialogPt">
            <template #header>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-print !text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Formato de impresión</h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Selecciona la plantilla PDF</p>
                    </div>
                </div>
            </template>

            <div class="flex flex-col gap-3 pt-2">
                <!-- Opción Default -->
                <div @click="selectedTemplate = null"
                    class="p-4 rounded-2xl border transition-colors cursor-pointer group"
                    :class="selectedTemplate === null ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center mb-1">
                        <RadioButton v-model="selectedTemplate" :value="null" class="pointer-events-none" />
                        <label class="ml-3 font-medium text-sm text-gray-900 dark:text-white cursor-pointer m-0">Estándar del sistema</label>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 ml-8 m-0">Formato limpio y simple generado por defecto.</p>
                </div>

                <!-- Plantillas Personalizadas -->
                <div v-for="tpl in printTemplates" :key="tpl.id" @click="selectedTemplate = tpl.id"
                    class="p-4 rounded-2xl border transition-colors cursor-pointer group"
                    :class="selectedTemplate === tpl.id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center mb-1">
                        <RadioButton v-model="selectedTemplate" :value="tpl.id" class="pointer-events-none" />
                        <label class="ml-3 font-medium text-sm text-gray-900 dark:text-white cursor-pointer m-0">{{ tpl.name }}</label>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 ml-8 m-0">Plantilla personalizada para cotización.</p>
                </div>
            </div>

            <template #footer>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Link :href="route('print-templates.create', { type: 'cotizacion' })" class="text-[10px] text-primary-500 uppercase tracking-widest font-bold flex items-center gap-1 hover:underline order-2 sm:order-1">
                        <i class="pi pi-plus !text-[9px]"></i> Crear diseño nuevo
                    </Link>
                    <div class="flex justify-end gap-3 order-1 sm:order-2 w-full sm:w-auto">
                        <Button label="Cancelar" text @click="showTemplateDialog = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                        <Button label="Generar PDF" icon="pi pi-file-pdf" @click="openPrintWindow" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" severity="primary" />
                    </div>
                </div>
            </template>
        </Dialog>

    </AppLayout>
</template>