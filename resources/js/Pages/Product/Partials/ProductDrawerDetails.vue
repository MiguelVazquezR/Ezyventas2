<script setup>
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Image from 'primevue/image';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    canSeeDetails: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['goToDetails']);

// --- FORMATTERS ---
const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

// --- HELPER FUNCTIONS PARA STOCK Y VARIANTES ---
const getVariants = (product) => {
    if (!product) return [];
    return product.product_attributes || product.productAttributes || [];
};

const hasVariants = (product) => {
    return getVariants(product).length > 0;
};

const isComposite = (product) => {
    return product.components && product.components.length > 0;
};

const isBulk = (product) => {
    return product.is_bulk;
};

const getCalculatedStock = (product) => {
    if (!product) return 0;
    if (hasVariants(product)) {
        return getVariants(product).reduce((sum, v) => sum + (Number(v.current_stock) || 0), 0);
    }
    return Number(product.current_stock) || 0;
};

const getCalculatedReserved = (product) => {
    if (!product) return 0;
    if (hasVariants(product)) {
        return getVariants(product).reduce((sum, v) => sum + (Number(v.reserved_stock) || 0), 0);
    }
    return Number(product.reserved_stock) || 0;
};

const getAvailableStock = (product) => {
    return getCalculatedStock(product) - getCalculatedReserved(product);
};

// Helpers para extraer los datos de la relación polimórfica de los componentes
const getComponentName = (component) => {
    if (!component.componentable) return 'Componente no encontrado';
    if (component.componentable_type === 'App\\Models\\ProductAttribute') {
        const parentName = component.componentable.product?.name || 'Producto';
        const attrString = Object.values(component.componentable.attributes || {}).join(' ');
        return `${parentName} - ${attrString}`;
    }
    return component.componentable.name;
};

const getComponentSku = (component) => {
    if (!component.componentable) return 'N/A';
    if (component.componentable_type === 'App\\Models\\ProductAttribute') {
        return component.componentable.sku_suffix || component.componentable.product?.sku || 'N/A';
    }
    return component.componentable.sku || 'N/A';
};

// --- TESLA UI PASS-THROUGH (PT) ---
const tagPt = {
    root: { class: '!rounded-full !px-2 !py-0.5 !text-[9px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[9px] !mr-1' }
};
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Contenedor scrolleable -->
        <div class="flex-grow space-y-4 overflow-y-auto pb-6 px-6 pt-6 custom-scrollbar">

            <!-- Tarjeta Principal (Imagen, nombre, precio) -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex gap-4 items-start">
                    <div class="w-24 h-24 shrink-0 bg-white dark:bg-[#232323] rounded-xl overflow-hidden border border-gray-200 dark:border-[#3a3a3a] flex items-center justify-center">
                        <img v-if="product.media && product.media.length > 0"
                            :src="product.media[0].original_url"
                            class="w-full h-full object-cover" />
                        <i v-else class="pi pi-image !text-3xl text-gray-300 dark:text-gray-600"></i>
                    </div>
                    
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-col gap-1 mb-2">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white leading-tight m-0 truncate" :title="product.name">
                                {{ product.name }}
                            </h3>
                            
                            <div class="flex flex-wrap gap-1 items-center">
                                <Tag v-if="product.show_in_pos" severity="success" value="POS" icon="pi pi-shop" :pt="tagPt" v-tooltip.top="'Visible en Punto de Venta'" />
                                <Tag v-else severity="secondary" value="Insumo" icon="pi pi-eye-slash" v-tooltip.top="'No visible en punto de venta'" :pt="tagPt" />
                                
                                <Tag v-if="product.show_online" severity="success" value="En línea" icon="pi pi-globe" :pt="tagPt" v-tooltip.top="'Visible en tienda en línea'" />
                                
                                <Tag v-if="product.is_featured" severity="warn" value="Destacado" icon="pi pi-star-fill" :pt="tagPt" v-tooltip.top="'Destacado en tienda en línea'" />
                                
                                <Tag v-if="isComposite(product)" severity="contrast" value="Kit/Combo" icon="pi pi-link" :pt="tagPt" />
                                <Tag v-else-if="isBulk(product)" severity="warning" value="Venta a granel" :pt="tagPt" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 mt-3 pt-3 border-t border-gray-200 dark:border-[#2a2a2a]">
                            <div>
                                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Código / SKU</span>
                                <span class="font-mono text-xs text-gray-800 dark:text-gray-200 block truncate">{{ product.sku || 'N/A' }}</span>
                            </div>
                            <div>
                                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0">Categoría</span>
                                <span class="text-xs text-gray-800 dark:text-gray-200 block truncate" :title="product.category?.name">{{ product.category?.name || 'N/A' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-end">
                    <div>
                        <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block mb-1">Precio general</span>
                        <div class="flex flex-wrap gap-1">
                            <Tag v-for="branch in product.branches" :key="branch.id" :value="branch.name" severity="info" :pt="tagPt" />
                        </div>
                    </div>
                    <span class="font-light tracking-tight text-3xl leading-none m-0 text-primary-600 dark:text-primary-400">
                        {{ formatCurrency(product.selling_price) }}
                    </span>
                </div>

                <!-- Precio en línea (si es diferente) -->
                <div v-if="product.show_online && product.online_price && Number(product.online_price) !== Number(product.selling_price)"
                    class="mt-3 pt-3 border-t border-dashed border-gray-200 dark:border-[#2a2a2a] flex justify-between items-end">
                    <div>
                        <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block mb-1">Precio en línea</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="pi pi-globe !text-[10px]" /> Tienda en línea
                        </span>
                    </div>
                    <span class="font-light tracking-tight text-2xl leading-none m-0 text-emerald-600 dark:text-emerald-400">
                        {{ formatCurrency(product.online_price) }}
                    </span>
                </div>
            </div>

            <!-- SI ES PRODUCTO COMPUESTO: Mostrar Componentes -->
            <div v-if="isComposite(product)" class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 border border-gray-100 dark:border-[#3a3a3a]">
                <div class="mb-4">
                    <h4 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-1 flex items-center gap-1.5">
                        <i class="pi pi-list !text-[10px]"></i> Componentes del Kit/Combo
                    </h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                        Este producto es dinámico. Al venderse, se descontará el stock de los siguientes artículos:
                    </p>
                </div>

                <div class="space-y-2">
                    <div v-for="component in product.components" :key="component.id" class="bg-white dark:bg-[#232323] p-3 rounded-xl border border-gray-100 dark:border-[#3a3a3a] flex justify-between items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm text-gray-900 dark:text-gray-100 leading-tight truncate m-0">{{ getComponentName(component) }}</div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-1 font-mono m-0">SKU: {{ getComponentSku(component) }}</div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <span class="text-[9px] text-gray-400 uppercase tracking-widest block m-0 mb-0.5">Descuenta</span>
                            <span class="font-bold text-lg text-primary-500 dark:text-primary-400 leading-none m-0">x{{ component.quantity }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SI ES PRODUCTO SIMPLE O CON VARIANTES: Mostrar Stock Normal -->
            <template v-else>
                <!-- Resumen de Inventario General -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 border border-gray-100 dark:border-[#3a3a3a]">
                    <h4 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-4 flex items-center gap-1.5">
                        <i class="pi pi-warehouse !text-[10px]"></i> Inventario local
                        <span v-if="hasVariants(product)" class="bg-gray-200 dark:bg-[#2a2a2a] text-gray-600 dark:text-gray-400 px-1.5 py-0.5 rounded-md lowercase tracking-normal font-medium" v-tooltip.top="'Cálculo sumando todas las variantes'">
                            (Total variables)
                        </span>
                    </h4>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="border-b border-gray-200 dark:border-[#2a2a2a] pb-2">
                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0 mb-1">Stock físico</span>
                            <span class="font-light tracking-tight text-2xl text-gray-900 dark:text-white m-0">{{ getCalculatedStock(product) }}</span>
                        </div>
                        <div class="border-b border-gray-200 dark:border-[#2a2a2a] pb-2">
                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0 mb-1">Disponible</span>
                            <span class="font-light tracking-tight text-2xl text-green-500 m-0">{{ getAvailableStock(product) }}</span>
                        </div>
                        <div>
                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0 mb-1">Apartados / Reservas</span>
                            <span class="font-light tracking-tight text-xl text-purple-500 m-0">{{ getCalculatedReserved(product) }}</span>
                        </div>
                        <div>
                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 block m-0 mb-1">Ubicación (Pasillo)</span>
                            <span class="font-medium text-sm text-gray-900 dark:text-white m-0 flex items-center h-full pb-1">
                                {{ hasVariants(product) ? 'Múltiples' : (product.location || 'No asignada') }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN DINÁMICA DE VARIANTES EN EL DRAWER -->
                <div v-if="hasVariants(product)" class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl p-5 border border-gray-100 dark:border-[#3a3a3a]">
                    <h4 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-4 flex items-center justify-between">
                        <span class="flex items-center gap-1.5"><i class="pi pi-sitemap !text-[10px]"></i> Variantes registradas</span>
                        <span class="bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2 py-0.5 rounded-full">{{ getVariants(product).length }}</span>
                    </h4>
                    
                    <div class="space-y-3">
                        <div v-for="variant in getVariants(product)" :key="variant.id"
                            class="bg-white dark:bg-[#232323] p-4 rounded-xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between gap-3 transition-colors hover:border-gray-300 dark:hover:border-gray-600">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap gap-1.5 mb-2">
                                    <Tag v-for="(val, key) in variant.attributes" :key="key"
                                        :value="`${key}: ${val}`" severity="secondary" :pt="tagPt" />
                                </div>
                                
                                <div class="font-medium text-primary-600 dark:text-primary-400 text-base mb-1 m-0 tracking-tight">
                                    {{ formatCurrency(Number(product.selling_price) + Number(variant.price_modifier || variant.selling_price_modifier || 0)) }}
                                </div>
                                
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-[10px] text-gray-500 font-mono m-0">SKU: {{ variant.sku || variant.sku_suffix || 'N/A' }}</span>
                                    <span v-if="variant.location" class="text-[10px] text-gray-500 m-0"><i class="pi pi-map-marker !text-[9px] mr-1"></i>{{ variant.location }}</span>
                                </div>
                            </div>
                            
                            <div class="text-left sm:text-right border-t sm:border-t-0 border-gray-100 dark:border-[#3a3a3a] pt-3 sm:pt-0 sm:pl-3 flex flex-col justify-center shrink-0">
                                <div class="flex justify-between sm:justify-end items-center gap-3 sm:gap-2 mb-1">
                                    <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0">Físico</span>
                                    <span class="font-mono text-sm text-gray-900 dark:text-white m-0">{{ variant.current_stock || 0 }}</span>
                                </div>
                                <div class="flex justify-between sm:justify-end items-center gap-3 sm:gap-2 mb-1">
                                    <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0">Disp.</span>
                                    <span class="font-mono text-sm font-bold text-green-500 m-0">{{ (variant.current_stock || 0) - (variant.reserved_stock || 0) }}</span>
                                </div>
                                <div v-if="variant.reserved_stock > 0" class="flex justify-between sm:justify-end items-center gap-3 sm:gap-2">
                                    <span class="text-[9px] uppercase tracking-widest text-purple-400/80 m-0">Reser.</span>
                                    <span class="font-mono text-[11px] text-purple-500 m-0">{{ variant.reserved_stock }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Footer Fijo (Botón flotante en el Drawer) -->
        <div class="p-6 border-t border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323]">
            <Button 
                label="Ver detalles completos" 
                icon="pi pi-eye" 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('goToDetails', product.id)"
                :disabled="!canSeeDetails" 
            />
        </div>
    </div>
</template>