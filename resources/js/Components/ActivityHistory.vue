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
        default: '350px',
    }
});

// --- DICCIONARIO DE TRADUCCIONES ---
// Actualizado para incluir campos de Ordenes de Servicio y Cotizaciones
const fieldTranslations = {
    // --- Generales ---
    'name': 'Nombre',
    'description': 'Descripción',
    'slug': 'URL / Slug',
    'is_active': 'Activo',
    'status': 'Estatus',
    'notes': 'Notas',
    'image': 'Imagen',
    'folio': 'Folio',
    'custom_fields': 'Campos Personalizados',
    'created_at': 'Fecha Creación',
    'updated_at': 'Fecha Actualización',
    
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
    
    // --- Relaciones (IDs) ---
    'category_id': 'Categoría',
    'brand_id': 'Marca',
    'provider_id': 'Proveedor',
    'user_id': 'Usuario',
    'customer_id': 'Cliente',
    'transaction_id': 'Transacción',
    'quote_id': 'Cotización',
    'service_order_id': 'Orden de Servicio',
    
    // --- Servicios ---
    'duration': 'Duración',
    'duration_estimate': 'Duración Estimada',
    'base_price': 'Precio Base',
    'show_online': 'Mostrar en Línea',
    
    // --- Cotizaciones (Nuevos campos) ---
    'valid_until': 'Válido hasta',
    'expiry_date': 'Fecha de Vencimiento',
    'total_amount': 'Monto Total',
    'subtotal': 'Subtotal',
    'total_discount': 'Descuento Total',
    'total_tax': 'Impuestos Totales',
    'tax_type': 'Tipo de Impuesto',
    'tax_rate': 'Tasa de Impuesto',
    'shipping_cost': 'Costo de Envío',
    'recipient_name': 'Nombre Destinatario',
    'recipient_email': 'Email Destinatario',
    'recipient_phone': 'Teléfono Destinatario',
    'shipping_address': 'Dirección de Envío',
    
    // --- Órdenes de Servicio (Nuevos campos) ---
    'customer_name': 'Nombre del Cliente',
    'customer_email': 'Email del Cliente',
    'customer_phone': 'Teléfono del Cliente',
    'customer_address': 'Dirección del Cliente',
    'technician_name': 'Técnico Asignado',
    'technician_commission_type': 'Tipo de Comisión',
    'technician_commission_value': 'Valor de Comisión',
    'received_at': 'Fecha de Recepción',
    'promised_at': 'Fecha Promesa de Entrega',
    'item_description': 'Equipo / Dispositivo',
    'reported_problems': 'Fallas Reportadas',
    'technician_diagnosis': 'Diagnóstico Técnico',
    'final_total': 'Total Final',
    'discount_type': 'Tipo de Descuento',
    'discount_value': 'Valor del Descuento',
    'discount_amount': 'Monto Descontado',
};

const getEventStyle = (event) => {
    switch (event) {
        case 'created':
            return { color: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400', icon: 'pi pi-plus', border: 'border-blue-200 dark:border-blue-800' };
        case 'updated':
            return { color: 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400', icon: 'pi pi-file-edit', border: 'border-orange-200 dark:border-orange-800' };
        case 'deleted':
            return { color: 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400', icon: 'pi pi-trash', border: 'border-red-200 dark:border-red-800' };
        case 'restored':
            return { color: 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400', icon: 'pi pi-refresh', border: 'border-teal-200 dark:border-teal-800' };
        case 'status_changed':
            return { color: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400', icon: 'pi pi-sync', border: 'border-indigo-200 dark:border-indigo-800' };
        case 'venta_generada':
            return { color: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400', icon: 'pi pi-dollar', border: 'border-green-200 dark:border-green-800' };
        case 'promo':
            return { color: 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400', icon: 'pi pi-bolt', border: 'border-purple-200 dark:border-purple-800' };
        default:
            return { color: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400', icon: 'pi pi-info', border: 'border-gray-200 dark:border-gray-700' };
    }
};

// Función para formatear las claves (keys) de inglés a español
const formatKey = (key) => {
    if (!key) return '';

    const lowerKey = key.toLowerCase();

    // 1. Buscamos en el diccionario
    if (fieldTranslations[lowerKey]) {
        return fieldTranslations[lowerKey];
    }

    // 2. Si es un ID genérico (ej: something_id)
    if (lowerKey.endsWith('_id')) {
        const base = lowerKey.replace('_id', '');
        // Si existe traducción para la base (ej: brand_id -> brand -> Marca)
        if (fieldTranslations[base]) return fieldTranslations[base];
        // Si no, formato "bonito": category_id -> Category
        return base.charAt(0).toUpperCase() + base.slice(1);
    }

    // 3. Fallback: Reemplazar guiones bajos y capitalizar
    // "PRICE_TIERS" -> "Price Tiers"
    return key.replace(/_/g, ' ')
        .toLowerCase()
        .replace(/\b\w/g, l => l.toUpperCase());
};
</script>

<template>
    <div
        class="bg-white dark:bg-gray-800 rounded-lg shadow-md border border-gray-100 dark:border-gray-700 min-h-40 max-h-96 flex flex-col">
        <!-- Header -->
        <div
            class="p-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center rounded-t-lg">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2 m-0">
                {{ title }}
            </h2>
            <span v-if="activities.length"
                class="text-xs px-2 py-1 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                {{ activities.length }} eventos
            </span>
        </div>

        <!-- Timeline Content -->
        <div class="p-5 overflow-y-auto custom-scrollbar" :style="{ maxHeight: maxHeight }">
            <div v-if="activities && activities.length > 0" class="relative">

                <!-- Línea vertical conectora -->
                <div class="absolute left-4 top-2 bottom-2 w-0.5 bg-gray-200 dark:bg-gray-700"></div>

                <div class="space-y-8 relative">
                    <div v-for="(activity, index) in activities" :key="activity.id || index" class="relative group">

                        <!-- Icono del evento -->
                        <div class="absolute left-0 top-0">
                            <div class="flex w-8 h-8 items-center justify-center rounded-full border-2 ring-4 ring-white dark:ring-gray-800 z-10 relative transition-transform group-hover:scale-110"
                                :class="[getEventStyle(activity.event).color, getEventStyle(activity.event).border]">
                                <i :class="[getEventStyle(activity.event).icon, 'text-xs font-bold']"></i>
                            </div>
                        </div>

                        <!-- Contenido de la tarjeta -->
                        <div class="ml-12">
                            <!-- Cabecera del item -->
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start mb-1">
                                <h3
                                    class="m-0 text-sm font-semibold text-gray-800 dark:text-gray-200 group-hover:text-primary-600 transition-colors">
                                    {{ activity.description }}
                                </h3>
                                <time class="text-xs text-gray-400 whitespace-nowrap mt-0.5 sm:mt-0 font-mono">
                                    {{ activity.timestamp }}
                                </time>
                            </div>

                            <!-- Usuario causante -->
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-5 h-5 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-[10px] text-gray-500 font-bold uppercase">
                                    {{ activity.causer ? activity.causer.substring(0, 2) : 'S' }}
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ activity.causer || 'Sistema' }}
                                </p>
                            </div>

                            <!-- Detalles de cambios -->
                            <div v-if="activity.event === 'updated' && activity.changes?.after && Object.keys(activity.changes.after).length > 0"
                                class="bg-gray-50 dark:bg-gray-900/50 rounded-md border border-gray-100 dark:border-gray-700 p-3 text-sm space-y-3 shadow-inner">

                                <div v-for="(value, key) in activity.changes.after" :key="key" class="group/field">
                                    <p
                                        class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1 flex items-center gap-1">
                                        {{ formatKey(key) }}
                                    </p>

                                    <!-- 
                                        Lógica visual:
                                        - Si el texto es largo, es descripción o es un objeto complejo -> DiffViewer
                                        - Si es corto -> Vista inline simple
                                    -->
                                    <div
                                        v-if="key.toLowerCase().includes('descripc') || (typeof value === 'string' && value.length > 50) || typeof value === 'object'">
                                        <DiffViewer :oldValue="activity.changes.before[key]" :newValue="value" />
                                    </div>

                                    <!-- Vista inline simplificada -->
                                    <div v-else class="flex flex-wrap items-center gap-2 text-xs">
                                        <div
                                            class="bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 px-2 py-1 rounded border border-red-100 dark:border-red-900/30 line-through opacity-75">
                                            {{ activity.changes.before[key] || 'Vacío' }}
                                        </div>
                                        <i class="pi pi-arrow-right text-gray-300 dark:text-gray-600 text-[10px]"></i>
                                        <div
                                            class="bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 px-2 py-1 rounded border border-green-100 dark:border-green-900/30 font-medium">
                                            {{ value }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estado Vacío -->
            <div v-else class="flex flex-col items-center justify-center py-12 text-center text-gray-400">
                <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-3">
                    <i class="pi pi-clock text-xl opacity-50"></i>
                </div>
                <p class="text-sm font-medium">No hay actividad reciente</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Personalización sutil del scrollbar */
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #e5e7eb;
    border-radius: 20px;
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #374151;
}
</style>