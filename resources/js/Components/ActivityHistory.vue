<script setup>
import { computed } from 'vue';
import DiffViewer from '@/Components/DiffViewer.vue';

const props = defineProps({
    activities: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'Historial de Actividad',
    },
    maxHeight: {
        type: String,
        default: '500px', // Aumenté un poco la altura por defecto para mejor visibilidad
    }
});

// --- DICCIONARIO DE TRADUCCIONES ---
const fieldTranslations = {
    // --- Generales ---
    'name': 'Nombre',
    'description': 'Descripción',
    'slug': 'URL / Slug',
    'is_active': 'Estado Activo',
    'status': 'Estatus',
    'notes': 'Notas',
    'image': 'Imagen',
    'folio': 'Folio',
    'custom_fields': 'Campos Personalizados',
    'created_at': 'Fecha Creación',
    'updated_at': 'Fecha Actualización',
    'deleted_at': 'Fecha Eliminación',
    
    // --- Productos / Inventario ---
    'sku': 'SKU',
    'selling_price': 'Precio Venta',
    'cost_price': 'Precio Compra',
    'current_stock': 'Stock Físico',
    'min_stock': 'Stock Mínimo',
    'max_stock': 'Stock Máximo',
    'reserved_stock': 'Stock Apartado',
    'available_stock': 'Stock Disponible',
    'barcode': 'Código de Barras',
    'price_tiers': 'Precios de Mayoreo',
    'product_type': 'Tipo de Producto',
    'brand': 'Marca',
    'category': 'Categoría',
    'location': 'Ubicación',
    
    // --- Relaciones (IDs) ---
    'category_id': 'Categoría',
    'brand_id': 'Marca',
    'provider_id': 'Proveedor',
    'user_id': 'Usuario',
    'customer_id': 'Cliente',
    'transaction_id': 'Transacción',
    'quote_id': 'Cotización',
    'service_order_id': 'Orden de Servicio',
    
    // --- Servicios / Cotizaciones / OS ---
    'duration': 'Duración',
    'duration_estimate': 'Duración Estimada',
    'base_price': 'Precio Base',
    'show_online': 'Mostrar en Línea',
    'valid_until': 'Válido hasta',
    'expiry_date': 'Fecha de Vencimiento',
    'total_amount': 'Monto Total',
    'subtotal': 'Subtotal',
    'total_discount': 'Descuento Total',
    'total_tax': 'Impuestos Totales',
    'shipping_cost': 'Costo de Envío',
    'recipient_name': 'Nombre Destinatario',
    'shipping_address': 'Dirección de Envío',
    'customer_name': 'Nombre del Cliente',
    'technician_name': 'Técnico Asignado',
    'item_description': 'Equipo / Dispositivo',
    'reported_problems': 'Fallas Reportadas',
    'technician_diagnosis': 'Diagnóstico Técnico',
};

// Función para formatear las claves (keys) de inglés a español
const formatKey = (key) => {
    if (!key) return '';
    const lowerKey = key.toLowerCase();
    
    // 1. Diccionario exacto
    if (fieldTranslations[lowerKey]) return fieldTranslations[lowerKey];
    
    // 2. IDs genéricos
    if (lowerKey.endsWith('_id')) {
        const base = lowerKey.replace('_id', '');
        if (fieldTranslations[base]) return fieldTranslations[base];
        return base.charAt(0).toUpperCase() + base.slice(1);
    }
    
    // 3. Fallback legible
    return key.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, l => l.toUpperCase());
};

// --- LÓGICA INTELIGENTE DE DESCRIPCIÓN ---
const getSmartActivityDetails = (activity) => {
    let title = activity.description;
    let icon = 'pi pi-info';
    let colorClass = 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400';
    let borderClass = 'border-gray-200 dark:border-gray-700';

    // Detectar descripciones genéricas
    const isGenericUpdate = title.toLowerCase().includes('actualizado') || title.toLowerCase().includes('updated');

    // Analizar cambios para mejorar el título
    if (activity.event === 'updated' && activity.changes?.after) {
        const keys = Object.keys(activity.changes.after);
        
        // Prioridad 1: Cambios de Stock
        if (keys.includes('current_stock')) {
            const oldStock = activity.changes.before?.current_stock || 0;
            const newStock = activity.changes.after.current_stock;
            const diff = newStock - oldStock;
            
            if (diff > 0) {
                title = isGenericUpdate ? 'Entrada de Inventario' : title;
                icon = 'pi pi-arrow-circle-up';
                colorClass = 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
                borderClass = 'border-green-200 dark:border-green-800';
            } else {
                title = isGenericUpdate ? 'Salida de Inventario' : title;
                icon = 'pi pi-arrow-circle-down';
                colorClass = 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
                borderClass = 'border-red-200 dark:border-red-800';
            }
        } 
        // Prioridad 2: Precios
        else if (keys.includes('selling_price') || keys.includes('cost_price')) {
            title = isGenericUpdate ? 'Actualización de Precios' : title;
            icon = 'pi pi-tag';
            colorClass = 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300';
            borderClass = 'border-purple-200 dark:border-purple-800';
        }
        // Prioridad 3: Estatus
        else if (keys.includes('status') || keys.includes('is_active')) {
            title = isGenericUpdate ? 'Cambio de Estatus' : title;
            icon = 'pi pi-sync';
            colorClass = 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
            borderClass = 'border-blue-200 dark:border-blue-800';
        }
        // Prioridad 4: Edición simple (1 solo campo)
        else if (keys.length === 1 && isGenericUpdate) {
            title = `Edición de ${formatKey(keys[0])}`;
            icon = 'pi pi-pencil';
            colorClass = 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300';
            borderClass = 'border-orange-200 dark:border-orange-800';
        }
    }

    // Overrides por tipo de evento explícito
    if (activity.event === 'created') {
        icon = 'pi pi-plus';
        colorClass = 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400';
        borderClass = 'border-blue-200 dark:border-blue-800';
    } else if (activity.event === 'deleted') {
        icon = 'pi pi-trash';
        colorClass = 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400';
        borderClass = 'border-red-200 dark:border-red-800';
    } else if (activity.event === 'restored') {
        icon = 'pi pi-refresh';
        colorClass = 'bg-teal-100 text-teal-600 dark:bg-teal-900/40 dark:text-teal-400';
        borderClass = 'border-teal-200 dark:border-teal-800';
    } else if (activity.event === 'stock_in') { // Eventos custom que creamos
        icon = 'pi pi-plus-circle';
        colorClass = 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
        borderClass = 'border-green-200 dark:border-green-800';
    } else if (activity.event === 'stock_out') { // Eventos custom que creamos
        icon = 'pi pi-minus-circle';
        colorClass = 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
        borderClass = 'border-red-200 dark:border-red-800';
    }

    return { title, icon, colorClass, borderClass };
};

// Helper para obtener propiedades extra (reason, notes, etc.)
const getExtraProperties = (activity) => {
    if (!activity.properties) return {};
    // Filtramos las propiedades estándar de Spatie para dejar solo las custom
    const { attributes, old, ...extras } = activity.properties;
    return extras;
};
</script>

<template>
    <div
        class="bg-white dark:bg-gray-800 rounded-lg shadow-md border border-gray-100 dark:border-gray-700 min-h-40 max-h-96 flex flex-col">
        <!-- Header -->
        <div
            class="p-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center rounded-t-lg bg-gray-50/50 dark:bg-gray-800">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2 m-0">
                <i class="pi pi-history text-primary-500"></i>
                {{ title }}
            </h2>
            <span v-if="activities.length"
                class="text-xs px-2 py-1 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 font-medium">
                {{ activities.length }} registros
            </span>
        </div>

        <!-- Timeline Content -->
        <div class="p-5 overflow-y-auto custom-scrollbar flex-grow" :style="{ maxHeight: maxHeight }">
            <div v-if="activities && activities.length > 0" class="relative pl-2">

                <!-- Línea de tiempo -->
                <div class="absolute left-6 top-2 bottom-4 w-0.5 bg-gray-200 dark:bg-gray-700"></div>

                <div class="space-y-6 relative">
                    <div v-for="(activity, index) in activities" :key="activity.id || index" class="relative group">

                        <!-- Preparar datos visuales -->
                        <!-- Usamos const en el template dentro del v-for implícitamente llamando a la función -->
                        <component :is="'script'" setup>
                            const details = getSmartActivityDetails(activity);
                            const extras = getExtraProperties(activity);
                        </component>

                        <div class="flex gap-4">
                            <!-- Icono -->
                            <div class="relative z-10 flex-shrink-0">
                                <div class="flex w-10 h-10 items-center justify-center rounded-full border-2 ring-4 ring-white dark:ring-gray-800 transition-transform group-hover:scale-110 shadow-sm"
                                    :class="[getSmartActivityDetails(activity).colorClass, getSmartActivityDetails(activity).borderClass]">
                                    <i :class="[getSmartActivityDetails(activity).icon, 'text-sm font-bold']"></i>
                                </div>
                            </div>

                            <!-- Contenido -->
                            <div class="flex-grow pt-1">
                                <!-- Cabecera del item -->
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start mb-1 gap-1">
                                    <div>
                                        <h3 class="m-0 text-sm font-bold text-gray-800 dark:text-gray-100 group-hover:text-primary-600 transition-colors">
                                            {{ getSmartActivityDetails(activity).title }}
                                        </h3>
                                        
                                        <!-- Mostrar Motivo si existe (para stock u otros) -->
                                        <div v-if="getExtraProperties(activity).reason" class="mt-1">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                Motivo: {{ getExtraProperties(activity).reason }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="text-right flex-shrink-0">
                                        <time class="text-xs text-gray-400 whitespace-nowrap font-mono flex items-center gap-1 justify-end">
                                            <i class="pi pi-calendar text-[10px]"></i> {{ activity.timestamp }}
                                        </time>
                                        
                                        <!-- Usuario -->
                                        <div class="flex items-center gap-1.5 justify-end mt-1">
                                            <div class="w-4 h-4 rounded-full bg-gray-200 dark:bg-gray-600 flex items-center justify-center text-[8px] text-gray-600 dark:text-gray-300 font-bold uppercase">
                                                {{ activity.causer ? activity.causer.substring(0, 1) : 'S' }}
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[100px]" :title="activity.causer">
                                                {{ activity.causer || 'Sistema' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Detalles de cambios (Diff) -->
                                <div v-if="activity.event === 'updated' && activity.changes?.after && Object.keys(activity.changes.after).length > 0"
                                    class="mt-3 bg-gray-50 dark:bg-gray-900/40 rounded-lg border border-gray-100 dark:border-gray-700/50 p-3 text-sm space-y-2.5">
                                    
                                    <div v-for="(value, key) in activity.changes.after" :key="key" class="group/field grid grid-cols-1 sm:grid-cols-[1fr,auto] gap-1 sm:gap-4">
                                        <!-- Nombre del campo -->
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide self-center flex items-center gap-1">
                                            {{ formatKey(key) }}
                                        </p>

                                        <!-- Valores -->
                                        <div class="w-full">
                                            <!-- Caso especial: Descripción larga o Objetos -> DiffViewer -->
                                            <div v-if="key.toLowerCase().includes('descripc') || (typeof value === 'string' && value.length > 50) || typeof value === 'object'">
                                                <DiffViewer :oldValue="activity.changes.before[key]" :newValue="value" />
                                            </div>
                                            
                                            <!-- Caso especial: Cambio Numérico Simple (Stock / Precio) -->
                                            <div v-else-if="typeof value === 'number' && typeof activity.changes.before[key] === 'number'" class="flex items-center gap-2 text-xs">
                                                <span class="line-through text-red-400 opacity-75">{{ activity.changes.before[key] }}</span>
                                                <i class="pi pi-arrow-right text-gray-400 text-[10px]"></i>
                                                <span class="font-bold text-gray-800 dark:text-gray-200">{{ value }}</span>
                                                
                                                <!-- Diferencia (ej: +5) -->
                                                <span v-if="value - activity.changes.before[key] !== 0" 
                                                    class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold"
                                                    :class="(value - activity.changes.before[key] > 0) ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                                    {{ (value - activity.changes.before[key] > 0 ? '+' : '') }}{{ value - activity.changes.before[key] }}
                                                </span>
                                            </div>

                                            <!-- Caso General: Inline -->
                                            <div v-else class="flex flex-wrap items-center gap-2 text-xs">
                                                <div class="bg-red-50 dark:bg-red-900/10 text-red-600 dark:text-red-400/80 px-2 py-0.5 rounded border border-red-100 dark:border-red-900/20 line-through opacity-75 truncate max-w-[150px]" :title="activity.changes.before[key]">
                                                    {{ activity.changes.before[key] || 'Vacío' }}
                                                </div>
                                                <i class="pi pi-arrow-right text-gray-300 dark:text-gray-600 text-[10px]"></i>
                                                <div class="bg-green-50 dark:bg-green-900/10 text-green-700 dark:text-green-300 px-2 py-0.5 rounded border border-green-100 dark:border-green-900/20 font-medium truncate max-w-[150px]" :title="value">
                                                    {{ value }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estado Vacío -->
            <div v-else class="flex flex-col items-center justify-center py-12 text-center text-gray-400 h-full">
                <div class="w-16 h-16 rounded-full bg-gray-50 dark:bg-gray-700/50 flex items-center justify-center mb-3">
                    <i class="pi pi-history text-2xl opacity-50"></i>
                </div>
                <p class="text-sm font-medium">No hay historial de actividad</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 5px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #cbd5e1;
    border-radius: 20px;
}
.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #4b5563;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: #94a3b8;
}
</style>