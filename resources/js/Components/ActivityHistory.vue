<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import DiffViewer from '@/Components/DiffViewer.vue';

const props = defineProps({
    activities: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'Historial de actividad',
    },
    maxHeight: {
        type: String,
        default: '500px',
    }
});

const isFetching = ref(false);

// --- LÓGICA DE FILTRADO POR FECHA DESDE EL SERVIDOR ---
const dateRange = ref(null);
let isInitializing = true;

const formatForServer = (date) => {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const fetchActivities = (start, end) => {
    isFetching.value = true;
    router.get(window.location.pathname, {
        start_date: formatForServer(start),
        end_date: formatForServer(end)
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['activities'],
        onFinish: () => { isFetching.value = false; }
    });
};

const setThisWeek = () => {
    const today = new Date();
    const day = today.getDay();
    const diff = today.getDate() - day + (day === 0 ? -6 : 1);
    const start = new Date(today);
    start.setDate(diff);
    start.setHours(0, 0, 0, 0);

    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    end.setHours(23, 59, 59, 999);

    dateRange.value = [start, end];
};

const setThisMonth = () => {
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    const end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    end.setHours(23, 59, 59, 999);
    dateRange.value = [start, end];
};

const clearFilter = () => {
    dateRange.value = null;
    isFetching.value = true;
    router.get(window.location.pathname, { all_activities: 1 }, {
        preserveState: true,
        preserveScroll: true,
        only: ['activities'],
        onFinish: () => { isFetching.value = false; }
    });
};

// Sincronizar el componente con la URL inicial
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('start_date') && params.get('end_date')) {
        // T00:00:00 se agrega para forzar zona horaria local limpia en JS
        dateRange.value = [
            new Date(params.get('start_date') + 'T00:00:00'), 
            new Date(params.get('end_date') + 'T00:00:00')
        ];
    } else if (params.get('all_activities')) {
        dateRange.value = null;
    } else {
        // Si no hay parámetros, el backend nos envió la semana actual por defecto. 
        // Configuramos la UI en la semana actual silenciosamente.
        const today = new Date();
        const day = today.getDay();
        const diff = today.getDate() - day + (day === 0 ? -6 : 1);
        const start = new Date(today);
        start.setDate(diff);
        start.setHours(0, 0, 0, 0);

        const end = new Date(start);
        end.setDate(start.getDate() + 6);
        end.setHours(23, 59, 59, 999);

        dateRange.value = [start, end];
    }
    
    setTimeout(() => { isInitializing = false; }, 200);
});

// Observar el rango para peticiones cuando el usuario lo cambie manualmente en el DatePicker
watch(dateRange, (newVal) => {
    if (isInitializing) return;
    if (newVal && newVal[0] && newVal[1]) {
        fetchActivities(newVal[0], newVal[1]);
    }
});

const isThisWeek = computed(() => {
    if (!dateRange.value || !dateRange.value[0]) return false;
    const today = new Date();
    const diff = today.getDate() - today.getDay() + (today.getDay() === 0 ? -6 : 1);
    const thisWeekStart = new Date(today);
    thisWeekStart.setDate(diff);
    thisWeekStart.setHours(0, 0, 0, 0);
    return dateRange.value[0].getTime() === thisWeekStart.getTime();
});

const isThisMonth = computed(() => {
    if (!dateRange.value || !dateRange.value[0]) return false;
    const today = new Date();
    const thisMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    return dateRange.value[0].getTime() === thisMonthStart.getTime();
});


// --- LÓGICA PARA CONCEPTOS DE STOCK Y FECHA ---
const getReason = (properties) => {
    if (!properties) return null;
    return properties.reason || properties.concepto || properties.attributes?.reason || properties.attributes?.concepto;
};

const getOperation = (properties) => {
    if (!properties) return null;
    return properties.operation || properties.type || properties.attributes?.operation || properties.attributes?.type;
};

const entryReasons = [
    'Compra / Reabastecimiento', 'Devolución de cliente', 'Ajuste de inventario (+)', 'Inventario inicial', 'Producción interna'
];

const exitReasons = [
    'Venta externa', 'Merma / Caducado', 'Producto dañado', 'Robo / Pérdida', 'Uso interno', 'Ajuste de inventario (-)'
];

const isEntryMovement = (properties) => {
    const op = getOperation(properties);
    if (op === 'entry') return true;
    if (op === 'exit') return false;
    
    const reason = getReason(properties);
    if (!reason) return true; 
    if (entryReasons.includes(reason)) return true;
    if (exitReasons.includes(reason)) return false;
    if (reason.toLowerCase().includes('entrada') || reason.toLowerCase().includes('(+)')) return true;
    if (reason.toLowerCase().includes('salida') || reason.toLowerCase().includes('(-)')) return false;
    
    return true; 
};

const getMovementClass = (properties) => {
    return isEntryMovement(properties)
        ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-900/20 dark:border-green-800' 
        : 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/20 dark:border-red-800';
};

const getMovementIcon = (properties) => {
    return isEntryMovement(properties) ? 'pi pi-arrow-down-left' : 'pi pi-arrow-up-right';
};

const getMovementLabel = (properties) => {
    const isEntry = isEntryMovement(properties);
    const operationText = isEntry ? 'Entrada' : 'Salida';
    const reason = getReason(properties) || '';
    return reason ? `${operationText}: ${reason}` : operationText;
};

const formatDateTime = (dateString) => {
    if (!dateString) return '';
    
    if (typeof dateString === 'string' && !dateString.includes('-') && dateString.includes(' ')) return dateString;

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    const day = String(date.getDate()).padStart(2, '0');
    const month = date.toLocaleString('es-MX', { month: 'long' });
    const capitalizedMonth = month.charAt(0).toUpperCase() + month.slice(1);
    const year = date.getFullYear();
    
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'pm' : 'am';
    
    hours = hours % 12;
    hours = hours ? hours : 12; 
    
    return `${day} ${capitalizedMonth}, ${year} - ${hours}:${minutes}${ampm}`;
};

// --- DICCIONARIO DE TRADUCCIONES ---
const fieldTranslations = {
    // --- Generales ---
    'name': 'Nombre',
    'description': 'Descripción',
    'slug': 'URL / Slug',
    'is_active': 'Estado activo',
    'status': 'Estatus',
    'notes': 'Notas',
    'image': 'Imagen',
    'folio': 'Folio',
    'custom_fields': 'Campos personalizados',
    'created_at': 'Fecha creación',
    'updated_at': 'Fecha actualización',
    'deleted_at': 'Fecha eliminación',
    
    // --- Productos / Inventario ---
    'sku': 'SKU',
    'selling_price': 'Precio de venta',
    'cost_price': 'Precio de compra',
    'current_stock': 'Stock físico',
    'min_stock': 'Stock mínimo',
    'max_stock': 'Stock máximo',
    'reserved_stock': 'Stock apartado',
    'available_stock': 'Stock disponible',
    'barcode': 'Código de barras',
    'price_tiers': 'Precios de mayoreo',
    'product_type': 'Tipo de producto',
    'brand': 'Marca',
    'category': 'Categoría',
    'location': 'Ubicación',
    'online_price': 'Precio en línea',
    
    // --- Relaciones (IDs) ---
    'category_id': 'Categoría',
    'brand_id': 'Marca',
    'provider_id': 'Proveedor',
    'user_id': 'Usuario',
    'customer_id': 'Cliente',
    'transaction_id': 'Transacción',
    'quote_id': 'Cotización',
    'service_order_id': 'Orden de servicio',
    
    // --- Servicios / Cotizaciones / OS ---
    'duration': 'Duración',
    'duration_estimate': 'Duración estimada',
    'base_price': 'Precio base',
    'show_online': 'Mostrar en línea',
    'valid_until': 'Válido hasta',
    'expiry_date': 'Fecha de vencimiento',
    'total_amount': 'Monto total',
    'subtotal': 'Subtotal',
    'total_discount': 'Descuento total',
    'total_tax': 'Impuestos totales',
    'shipping_cost': 'Costo de envío',
    'recipient_name': 'Nombre destinatario',
    'shipping_address': 'Dirección de envío',
    'customer_name': 'Nombre del cliente',
    'technician_name': 'Técnico asignado',
    'item_description': 'Equipo / Dispositivo',
    'reported_problems': 'Fallas reportadas',
    'technician_diagnosis': 'Diagnóstico técnico',
    'customer_phone': 'Teléfono del cliente',
    'customer_email': 'Correo del cliente',
    'received_at': 'Fecha de recepción',
    'promised_at': 'Fecha de entrega prometida',
    'promised_at': 'Fecha de entrega prometida',
    'technician_commission_type': 'Tipo de comisión del técnico',
    'technician_commission_value': 'Valor de comisión del técnico',
    'discount_amount': 'Monto de descuento',
    'final_total': 'Monto final',
    'recipient_email': 'Correo del destinatario',
    'recipient_phone': 'Teléfono del destinatario',
};

// --- HELPERS UI ---
const stripHtml = (html) => {
    if (!html) return '';
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
    return doc.body.textContent || "";
};

const getActivityTitle = (activity) => {
    if (activity.description) return activity.description;
    const map = { created: 'Registro creado', updated: 'Registro actualizado', deleted: 'Registro eliminado' };
    return map[activity.event] || 'Actividad registrada';
};

const getActivityIcon = (activity) => {
    const map = { created: 'pi-plus', updated: 'pi-pencil', deleted: 'pi-trash' };
    return map[activity.event] || 'pi-info-circle';
};

const getActivityColor = (activity) => {
    const map = { created: 'bg-green-100 text-green-600', updated: 'bg-blue-100 text-blue-600', deleted: 'bg-red-100 text-red-600' };
    return map[activity.event] || 'bg-gray-100 text-gray-600';
};
</script>

<template>
    <div class="flex flex-col h-full relative">
        
        <!-- BARRA DE FILTROS -->
        <div class="flex flex-col xl:flex-row justify-between gap-3 mb-4 bg-gray-50 dark:bg-gray-800/40 p-3 rounded-lg border border-gray-100 dark:border-gray-700 items-start xl:items-center">
            <div class="flex items-center gap-2 overflow-x-auto w-full xl:w-auto pb-1 xl:pb-0">
                <Button label="Esta semana" size="small" @click="setThisWeek" :severity="isThisWeek ? 'primary' : 'secondary'" :outlined="!isThisWeek" class="whitespace-nowrap" />
                <Button label="Mes actual" size="small" @click="setThisMonth" :severity="isThisMonth ? 'primary' : 'secondary'" :outlined="!isThisMonth" class="whitespace-nowrap" />
                <Button label="Todo" size="small" @click="clearFilter" :severity="!dateRange ? 'primary' : 'secondary'" :outlined="!!dateRange" class="whitespace-nowrap" />
            </div>
            <div class="w-full xl:w-auto flex-shrink-0">
                <DatePicker 
                    v-model="dateRange" 
                    selectionMode="range" 
                    placeholder="Rango personalizado" 
                    :manualInput="false" 
                    showIcon 
                    dateFormat="dd/mm/yy" 
                    class="w-full xl:w-64" 
                />
            </div>
        </div>

        <!-- CONTENEDOR HISTORIAL -->
        <div class="overflow-y-auto custom-scrollbar px-2 relative" :style="{ maxHeight: maxHeight }">
            
            <!-- Loading Overlay -->
            <div v-if="isFetching" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-gray-900/60 rounded-lg">
                <i class="pi pi-spin pi-spinner text-3xl text-primary-500"></i>
            </div>

            <div v-if="activities.length > 0" class="relative border-l border-gray-200 dark:border-gray-700 ml-3 md:ml-4 space-y-6 pb-4" :class="{'opacity-50 pointer-events-none': isFetching}">
                <div v-for="activity in activities" :key="activity.id" class="relative pl-6 md:pl-8">
                    
                    <span class="absolute -left-[17px] flex items-center justify-center w-8 h-8 rounded-full ring-4 ring-white dark:ring-gray-800" :class="getActivityColor(activity)">
                        <i class="pi" :class="getActivityIcon(activity)"></i>
                    </span>

                    <div class="bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                        
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-semibold text-gray-800 dark:text-gray-200 text-sm">
                                {{ getActivityTitle(activity) }}
                            </span>
                            <span class="text-xs text-gray-500 bg-gray-100 dark:bg-gray-700/50 px-2 py-0.5 rounded-full flex items-center gap-1 border dark:border-gray-600">
                                <i class="pi pi-clock !text-[10px]"></i>
                                {{ formatDateTime(activity.created_at || activity.timestamp) }}
                            </span>
                        </div>
                        
                        <div class="text-xs text-gray-600 dark:text-gray-400 mb-2 flex items-center gap-1.5">
                            <div class="w-5 h-5 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-700 dark:text-primary-400">
                                <i class="pi pi-user !text-[10px]"></i>
                            </div>
                            Por <span class="font-medium text-gray-700 dark:text-gray-300">{{ activity.causer }}</span>
                        </div>

                        <!-- Concepto de Movimiento de Stock -->
                        <div v-if="getReason(activity.properties)" class="mb-3">
                            <div class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-xs font-medium border"
                                :class="getMovementClass(activity.properties)">
                                <i :class="getMovementIcon(activity.properties)"></i>
                                <span>{{ getMovementLabel(activity.properties) }}</span>
                            </div>
                        </div>

                        <!-- Diff Viewer -->
                        <div v-if="activity.changes && (Object.keys(activity.changes.after || {}).length > 0)"
                             class="mt-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700">
                             
                             <div v-for="(value, key) in activity.changes.after" :key="key" class="mb-2 last:mb-0">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                                    <div class="font-semibold text-gray-500 uppercase tracking-wider md:col-span-1 flex items-center">
                                        {{ fieldTranslations[key] || key }}
                                    </div>
                                    <div class="md:col-span-2 flex items-center gap-2">
                                        <div v-if="activity.changes.before && activity.changes.before[key] !== undefined" class="flex-1 bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400 px-2 py-0.5 rounded border border-red-100 dark:border-red-900/20 line-through truncate max-w-[150px]" :title="stripHtml(activity.changes.before[key])">
                                            {{ stripHtml(activity.changes.before[key]) }}
                                        </div>
                                        <i v-if="activity.changes.before && activity.changes.before[key] !== undefined" class="pi pi-arrow-right text-gray-400 !text-[10px]"></i>
                                        <div class="flex-1 bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400 px-2 py-0.5 rounded border border-green-100 dark:border-green-900/20 font-medium truncate max-w-[150px]" :title="stripHtml(value)">
                                            {{ stripHtml(value) }}
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
                <div class="w-16 h-16 rounded-full bg-gray-50 dark:bg-gray-700/50 flex items-center justify-center mb-3" :class="{'opacity-50': isFetching}">
                    <i class="pi pi-history text-2xl opacity-50"></i>
                </div>
                <p class="text-sm font-medium">No se encontraron movimientos</p>
                <p v-if="dateRange" class="text-xs mt-2 max-w-xs mx-auto">
                    Prueba cambiando el rango de fechas o haz clic en 
                    <button @click="clearFilter" class="text-blue-500 font-bold hover:underline">Ver todo</button>.
                </p>
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