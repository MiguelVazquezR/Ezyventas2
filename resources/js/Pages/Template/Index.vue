<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    templates: Array,
    templateLimit: Number,
    templateUsage: Number,
});

// Lógica para verificar si se alcanzó el límite
const limitReached = computed(() => {
    if (props.templateLimit === -1) return false;
    return props.templateUsage >= props.templateLimit;
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const confirmDelete = (template) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la plantilla "${template.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('print-templates.destroy', template.id), { preserveScroll: true });
        }
    });
};

// Menú desplegable para "Nueva Plantilla" (Reemplaza al SplitButton)
const newTemplateMenu = ref();
const toggleNewTemplateMenu = (event) => {
    newTemplateMenu.value.toggle(event);
};

const newTemplateOptions = computed(() => {
    const options = [
        {
            label: 'Nuevo ticket de venta',
            icon: 'pi pi-receipt',
            command: () => router.get(route('print-templates.create', { type: 'ticket_venta' }))
        },
        {
            label: 'Nueva etiqueta',
            icon: 'pi pi-tags',
            command: () => router.get(route('print-templates.create', { type: 'etiqueta' }))
        }
    ];

    if (hasPermission('quotes.access')) {
        options.push({
            label: 'Nueva cotización (Carta/A4)',
            icon: 'pi pi-file-pdf',
            command: () => router.get(route('print-templates.create', { type: 'cotizacion' }))
        });
    }

    if (hasPermission('services.orders.access')) {
        options.push({
            label: 'Nuevo recibo de servicio (Carta/A4)',
            icon: 'pi pi-wrench',
            command: () => router.get(route('print-templates.create', { type: 'recibo_servicio' }))
        });
    }

    return options;
});

// Menú de acciones por fila
const templateActionMenu = ref();
const selectedTemplate = ref(null);

const toggleTemplateActionMenu = (event, data) => {
    selectedTemplate.value = data;
    templateActionMenu.value.toggle(event);
};

const templateActionItems = computed(() => {
    if (!selectedTemplate.value) return [];
    const t = selectedTemplate.value;
    return [
        { label: 'Editar plantilla', icon: 'pi pi-pencil', command: () => router.get(route('print-templates.edit', t.id)), visible: hasPermission('settings.templates.edit') },
        { separator: true },
        { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: () => confirmDelete(t), visible: hasPermission('settings.templates.delete') }
    ];
});

// Helper para obtener configuración visual según el tipo
const getTypeConfig = (type) => {
    const config = {
        'ticket_venta': { 
            label: 'Ticket Venta', 
            severity: 'info',
            icon: 'pi pi-receipt' 
        },
        'etiqueta': { 
            label: 'Etiqueta', 
            severity: 'warn',
            icon: 'pi pi-tags' 
        },
        'cotizacion': { 
            label: 'Cotización', 
            severity: 'danger',
            icon: 'pi pi-file-pdf' 
        },
        'recibo_servicio': {
            label: 'Recibo Servicio',
            severity: 'success',
            icon: 'pi pi-wrench'
        }
    };

    return config[type] || { 
        label: type?.replace('_', ' ') || 'Desconocido', 
        severity: 'secondary', 
        icon: 'pi pi-file' 
    };
};

const getContextLabel = (type) => {
    const labels = {
        'pos': 'Punto de venta',
        'transaction': 'Historial de ventas',
        'product': 'Productos',
        'service_order': 'Órdenes de servicio',
        'quote': 'Cotizaciones',
        'customer': 'Clientes',
        'general': 'General',
    };
    return labels[type] || 'General';
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' }
};
</script>

<template>
    <Head title="Plantillas personalizadas" />
    <AppLayout title="Plantillas personalizadas">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Banner de Alerta de Límite -->
            <div v-if="limitReached" class="bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div class="flex items-center gap-3">
                    <i class="pi pi-exclamation-circle text-orange-500 !text-xl"></i>
                    <div>
                        <p class="font-bold text-sm text-orange-800 dark:text-orange-400 m-0">Límite de plantillas alcanzado</p>
                        <p class="text-xs text-orange-700 dark:text-orange-300/80 m-0 mt-0.5">Has alcanzado el límite de plantillas personalizadas de tu plan actual.</p>
                    </div>
                </div>
                <Link :href="route('subscription.manage')">
                    <Button label="Mejorar plan" size="small" severity="warning" class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                </Link>
            </div>

            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Plantillas personalizadas</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-500 shadow-[0_0_8px_rgba(168,85,247,0.8)] animate-pulse"></span>
                        Diseño de tickets, etiquetas y documentos
                    </p>
                </div>

                <!-- Barra de Herramientas (Alineada a la derecha) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-end bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <span v-tooltip.bottom="limitReached ? `Límite de ${templateLimit} plantillas alcanzado` : 'Crear nueva plantilla'">
                            <Button v-if="hasPermission('settings.templates.create')" 
                                label="Nueva plantilla"
                                icon="pi pi-plus" 
                                iconPos="right"
                                severity="warning" 
                                :disabled="limitReached"
                                @click="toggleNewTemplateMenu"
                                class="!rounded-xl !text-xs !uppercase !tracking-wider w-full md:w-auto" 
                            />
                        </span>
                        <Menu ref="newTemplateMenu" :model="newTemplateOptions" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Tabla de Plantillas -->
                <DataTable :value="templates" responsiveLayout="scroll" rowHover :pt="dataTablePt">
                    
                    <Column field="name" header="Nombre">
                        <template #body="{ data }">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                        </template>
                    </Column>
                    
                    <Column field="type" header="Tipo">
                        <template #body="{ data }">
                            <Tag 
                                :value="getTypeConfig(data.type).label" 
                                :severity="getTypeConfig(data.type).severity" 
                                :icon="getTypeConfig(data.type).icon"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>

                    <Column header="Contexto (Módulo)">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                <i class="pi pi-box !text-[10px] text-gray-400"></i>
                                <span class="font-medium">{{ getContextLabel(data.context_type) }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Autoselección" alignFrozen="center" style="text-align: center;">
                        <template #body="{ data }">
                            <i v-if="data.is_default" class="pi pi-check-circle text-green-500 !text-sm" v-tooltip.top="'Se selecciona automáticamente al imprimir'"></i>
                            <i v-else class="pi pi-minus text-gray-300 dark:text-gray-600 !text-xs" v-tooltip.top="'No seleccionada por defecto'"></i>
                        </template>
                    </Column>

                    <Column header="Sucursales asignadas">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1.5">
                                <Tag v-for="branch in data.branches" :key="branch.id" :value="branch.name" severity="secondary" :pt="tagPt" />
                                <span v-if="data.branches.length === 0" class="text-gray-500 italic m-0">Ninguna</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button @click.stop="toggleTemplateActionMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" />
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10 opacity-60">
                            <i class="pi pi-palette !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No has registrado ninguna plantilla de impresión.</p>
                        </div>
                    </template>
                </DataTable>

                <!-- Menú contextual para las acciones de cada fila -->
                <Menu ref="templateActionMenu" :model="templateActionItems" :popup="true" :pt="menuPt" />
            </div>
        </div>
    </AppLayout>
</template>