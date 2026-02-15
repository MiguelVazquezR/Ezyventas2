<script setup>
import { Link, router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';
import { computed } from 'vue';

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
        accept: () => {
            router.delete(route('print-templates.destroy', template.id), { preserveScroll: true });
        }
    });
};

// MODIFICADO: Convertimos las opciones en una propiedad computada
// para evaluar los permisos dinámicamente.
const newTemplateOptions = computed(() => {
    // Opciones base siempre disponibles (o sujetas a sus propios permisos si fuera necesario)
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

    // Opción condicional basada en el permiso 'quotes.access'
    if (hasPermission('quotes.access')) {
        options.push({
            label: 'Nueva cotización (Carta/A4)',
            icon: 'pi pi-file-pdf',
            command: () => router.get(route('print-templates.create', { type: 'cotizacion' }))
        });
    }

    return options;
});

// Helper para obtener configuración visual según el tipo
const getTypeConfig = (type) => {
    const config = {
        'ticket_venta': { 
            label: 'Ticket Venta', 
            severity: 'info',    // Azul
            icon: 'pi pi-receipt' 
        },
        'etiqueta': { 
            label: 'Etiqueta', 
            severity: 'warn',    // Naranja/Amarillo
            icon: 'pi pi-tags' 
        },
        'cotizacion': { 
            label: 'Cotización', 
            severity: 'danger',  // Rojo (asociado a PDF)
            icon: 'pi pi-file-pdf' 
        }
    };

    return config[type] || { 
        label: type?.replace('_', ' ') || 'Desconocido', 
        severity: 'secondary', 
        icon: 'pi pi-file' 
    };
};

// Helper para obtener el contexto legible para el usuario
const getContextLabel = (type) => {
    const labels = {
        'pos': 'Punto de venta',
        'transaction': 'Historial de ventas',
        'product': 'Productos',
        'service_order': 'Órdenes de servicio',
        'quote': 'Cotizaciones',
        'customer': 'Clientes'
    };
    return labels[type] || 'General';
};
</script>

<template>
    <AppLayout title="Plantillas personalizadas">
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold">Plantillas personalizadas</h1>
                    <p class="text-gray-500 mt-1">Gestiona las plantillas para tickets, etiquetas y más.</p>
                </div>
                
                <div v-tooltip.bottom="limitReached ? `Límite de ${templateLimit} plantillas alcanzado` : 'Crear nueva plantilla'">
                    <!-- Usamos newTemplateOptions (que ahora es reactivo) -->
                    <SplitButton 
                        v-if="hasPermission('settings.templates.create')" 
                        label="Nuevo ticket" 
                        icon="pi pi-plus"
                        :model="newTemplateOptions" 
                        @click="newTemplateOptions.length > 0 ? newTemplateOptions[0].command() : null" 
                        :disabled="limitReached"
                    />
                </div>
            </header>

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <DataTable :value="templates" responsiveLayout="scroll">
                    <Column field="name" header="Nombre"></Column>
                    
                    <!-- Columna Tipo Actualizada con Colores e Iconos -->
                    <Column field="type" header="Tipo">
                        <template #body="{ data }">
                            <Tag 
                                :value="getTypeConfig(data.type).label" 
                                :severity="getTypeConfig(data.type).severity" 
                                :icon="getTypeConfig(data.type).icon" 
                            />
                        </template>
                    </Column>

                    <!-- Nueva Columna: Contexto -->
                    <Column header="Contexto (Módulo)">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                <i class="pi pi-box text-gray-400"></i>
                                <span class="font-medium">{{ getContextLabel(data.context_type) }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Sucursales asignadas">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag v-for="branch in data.branches" :key="branch.id" :value="branch.name" severity="secondary" />
                                <Tag v-if="data.branches.length === 0" value="Ninguna" severity="contrast" />
                            </div>
                        </template>
                    </Column>
                    <Column>
                        <template #body="{ data }">
                            <div class="flex justify-end gap-2">
                                <Link :href="route('print-templates.edit', data.id)">
                                <Button v-if="hasPermission('settings.templates.edit')" icon="pi pi-pencil" text
                                    rounded />
                                </Link>
                                <Button v-if="hasPermission('settings.templates.delete')" @click="confirmDelete(data)"
                                    icon="pi pi-trash" text rounded severity="danger" />
                            </div>
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-gray-500 py-4">
                            No has registrado ninguna plantilla.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>