<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    product: Object,
    isComposite: Boolean,
    isVariantProduct: Boolean,
    activeLayaways: Array,
    hasManageStockPermission: Boolean
});

const emit = defineEmits(['adjust-stock', 'adjust-layaways']);

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDateOnly = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        return new Date(dateString).toLocaleDateString('es-MX', { dateStyle: 'medium' });
    } catch (e) {
        return dateString;
    }
};

const isExpired = (dateString) => {
    if (!dateString) return false;
    const expiration = new Date(dateString + 'T00:00:00');
    const today = new Date();
    today.setHours(0,0,0,0);
    return expiration < today;
};

// --- Totales dinámicos (agrupan variantes o muestran el producto simple) ---
const totalStock = computed(() => {
    if (props.isVariantProduct) {
        return props.product.product_attributes.reduce((sum, v) => sum + (Number(v.current_stock) || 0), 0);
    }
    return Number(props.product.current_stock) || 0;
});

const totalReserved = computed(() => {
    if (props.isVariantProduct) {
        return props.product.product_attributes.reduce((sum, v) => sum + (Number(v.reserved_stock) || 0), 0);
    }
    return Number(props.product.reserved_stock) || 0;
});

const totalAvailable = computed(() => {
    if (props.isVariantProduct) {
        return props.product.product_attributes.reduce((sum, v) => sum + (Number(v.available_stock) || 0), 0);
    }
    return Number(props.product.available_stock) || 0;
});

const totalMinStock = computed(() => {
    if (props.isVariantProduct) {
        const min = props.product.product_attributes.reduce((sum, v) => sum + (Number(v.min_stock) || 0), 0);
        return min > 0 ? min : '--';
    }
    return props.product.min_stock || '--';
});

// --- Helpers para Componentes de Kits ---
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

// Obtiene el ID del producto principal para poder redirigir al enlace de detalles
const getComponentProductId = (component) => {
    if (!component.componentable) return null;
    if (component.componentable_type === 'App\\Models\\ProductAttribute') {
        return component.componentable.product_id || component.componentable.product?.id;
    }
    return component.componentable.id;
};

// --- Imágenes de las Variantes ---
const variantImages = computed(() => {
    const media = props.product.media || [];
    const images = media.filter(m => m.collection_name === 'product-variant-images');
    const imageMap = {};
    images.forEach(img => {
        const properties = img.custom_properties || {};
        const option = properties.variant_key || properties.variant_option;
        if (option) {
            imageMap[String(option)] = img.original_url;
        }
    });
    return imageMap;
});

const getVariantImage = (variant) => {
    if (!variant) return null;
    
    if (variant.id && variantImages.value[String(variant.id)]) {
        return variantImages.value[String(variant.id)];
    }
    
    let attrs = variant.attributes;
    if (typeof attrs === 'string') {
        try { attrs = JSON.parse(attrs); } catch (e) { return null; }
    }
    if (!attrs || typeof attrs !== 'object') return null;

    for (const [key, value] of Object.entries(attrs)) {
        const formattedKey = `${key}_${value}`;
        if (variantImages.value[formattedKey]) {
            return variantImages.value[formattedKey];
        }
        const valStr = String(value);
        if (variantImages.value[valStr]) {
            return variantImages.value[valStr];
        }
    }
    return null;
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
        <div class="flex justify-between items-center mb-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 flex items-center gap-2">
                <i v-if="isComposite" class="pi pi-link text-blue-500"></i>
                <i v-else class="pi pi-box text-blue-500"></i>
                {{ isComposite ? 'Componentes del Kit/Combo' : 'Inventario y variantes' }}
            </h3>
            <span v-if="hasManageStockPermission && !isComposite" class="flex items-center gap-2">
                <Button label="Ajustar apartados" icon="pi pi-sliders-h" size="small" outlined severity="secondary" @click="$emit('adjust-layaways')" class="!py-1" />
                <Button label="Ajustar stock" icon="pi pi-sort-alt" size="small" outlined @click="$emit('adjust-stock')" class="!py-1" />
            </span>
        </div>

        <!-- SI ES PRODUCTO COMPUESTO: Mostrar Componentes en vez de Stock Físico -->
        <div v-if="isComposite">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Este producto es dinámico. Al venderse en caja, se descontará el stock de los siguientes artículos de forma proporcional:</p>
            <div class="border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden">
                <DataTable :value="product.components" class="p-datatable-sm" stripedRows>
                    <Column header="Componente">
                        <template #body="{ data }">
                            <!-- Enlace al componente -->
                            <Link v-if="getComponentProductId(data)" 
                                :href="route('products.show', getComponentProductId(data))" 
                                class="font-semibold text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 hover:underline flex items-center gap-1.5 transition-colors w-fit">
                                {{ getComponentName(data) }}
                                <i class="pi pi-external-link text-[10px]"></i>
                            </Link>
                            <div v-else class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ getComponentName(data) }}
                            </div>
                        </template>
                    </Column>
                    <Column header="SKU">
                        <template #body="{ data }">
                            <span class="font-mono text-sm text-gray-600 dark:text-gray-400">{{ getComponentSku(data) }}</span>
                        </template>
                    </Column>
                    <Column header="Cant. a descontar" headerClass="text-right" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-bold text-primary-600 dark:text-primary-400">x{{ data.quantity }}</span>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>

        <!-- SI ES PRODUCTO SIMPLE/CON VARIANTES -->
        <template v-else>
            <!-- Indicadores (Cards) -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-center items-center text-center transition-colors hover:bg-gray-100 dark:hover:bg-[#232323]">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Stock físico</span>
                    <span class="text-2xl font-black text-gray-800 dark:text-gray-100">{{ totalStock }}</span>
                </div>
                <div class="bg-green-50/50 dark:bg-green-900/10 p-4 rounded-2xl border border-green-100 dark:border-green-900/30 flex flex-col justify-center items-center text-center transition-colors hover:bg-green-50 dark:hover:bg-green-900/20">
                    <span class="text-[11px] font-bold text-green-600 dark:text-green-500 uppercase tracking-wider mb-1">Disponible</span>
                    <span class="text-2xl font-black text-green-600 dark:text-green-400">{{ totalAvailable }}</span>
                </div>
                <div class="bg-indigo-50/50 dark:bg-indigo-900/10 p-4 rounded-2xl border border-indigo-100 dark:border-indigo-900/30 flex flex-col justify-center items-center text-center transition-colors hover:bg-indigo-50 dark:hover:bg-indigo-900/20">
                    <span class="text-[11px] font-bold text-indigo-600 dark:text-indigo-500 uppercase tracking-wider mb-1">Apartados</span>
                    <span class="text-2xl font-black text-indigo-600 dark:text-indigo-400">{{ totalReserved }}</span>
                </div>
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-center items-center text-center transition-colors hover:bg-gray-100 dark:hover:bg-[#232323]">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Stock mínimo</span>
                    <span class="text-2xl font-black text-gray-400 dark:text-gray-500">{{ totalMinStock }}</span>
                </div>
            </div>

            <!-- Tabla de Variantes (Solo si existen) -->
            <div v-if="isVariantProduct" class="overflow-hidden rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <DataTable :value="product.product_attributes" class="p-datatable-sm" stripedRows>
                    <Column headerStyle="width: 4rem" bodyStyle="padding: 0.5rem">
                    <template #body="{ data }">
                            <Image v-if="getVariantImage(data)"
                                :src="getVariantImage(data)"
                                preview
                                imageClass="w-10 h-10 object-cover rounded-lg border border-gray-100 dark:border-[#3a3a3a] cursor-pointer" />
                            <div v-else
                                class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-100 dark:border-[#3a3a3a]">
                                <i class="pi pi-image text-gray-400 text-sm"></i>
                            </div>
                        </template>
                    </Column>
                    
                    <!-- Atributos Dinámicos -->
                    <Column v-for="key in Object.keys(product.product_attributes[0]?.attributes || {})"
                        :key="key" :field="`attributes.${key}`" :header="key" class="font-medium text-sm"></Column>
                    
                    <!-- Stocks -->
                    <Column field="current_stock" header="Físico" sortable class="text-sm"></Column>
                    <Column field="reserved_stock" header="Apartado" sortable class="text-sm">
                        <template #body="{ data }">
                            <span v-if="data.reserved_stock > 0" class="text-indigo-600 font-bold bg-indigo-50 dark:bg-indigo-900/30 px-2 py-0.5 rounded-md">{{ data.reserved_stock }}</span>
                            <span v-else class="text-gray-400">-</span>
                        </template>
                    </Column>
                    <Column field="available_stock" header="Disp." sortable class="text-sm">
                        <template #body="{ data }">
                            <span :class="data.available_stock > 0 ? 'text-green-600 font-semibold' : 'text-red-500 font-bold'">{{ data.available_stock }}</span>
                        </template>
                    </Column>
                    
                    <Column field="location" header="Ubicación" sortable class="text-sm">
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400">{{ data.location || '--' }}</span>
                        </template>
                    </Column>

                    <Column header="Precio final" class="text-sm font-semibold text-right" headerClass="text-right">
                        <template #body="{ data }">
                            {{ formatCurrency(parseFloat(product.selling_price) + parseFloat(data.selling_price_modifier)) }}
                        </template>
                    </Column>
                </DataTable>
            </div>
        </template>

        <!-- Apartados Activos (Tabla Minimalista) -->
        <div v-if="activeLayaways && activeLayaways.length > 0" class="mt-8">
            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                <i class="pi pi-clock text-indigo-400"></i> Detalle de apartados activos
            </h4>
            <div class="border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden">
                <DataTable :value="activeLayaways" class="p-datatable-sm" responsiveLayout="scroll" sortField="date" :sortOrder="-1">
                    <Column field="date" header="Fecha" sortable class="text-xs">
                        <template #body="{ data }">{{ formatDateOnly(data.date) }}</template>
                    </Column>
                    <Column field="folio" header="Folio" sortable class="text-xs font-mono">
                        <template #body="{ data }">
                            <Link :href="route('transactions.show', data.transaction_id)" class="text-primary-600 hover:text-primary-700 font-bold">{{ data.folio }}</Link>
                        </template>
                    </Column>
                    <Column field="customer_name" header="Cliente" class="text-xs">
                        <template #body="{ data }">
                            <Link v-if="data.customer_id" :href="route('customers.show', data.customer_id)" class="text-gray-700 dark:text-gray-300 hover:text-primary-600">{{ data.customer_name }}</Link>
                            <span v-else class="text-gray-500 italic">{{ data.customer_name }}</span>
                        </template>
                    </Column>
                    <Column field="quantity" header="Cant." headerClass="text-center" bodyClass="text-center text-xs font-bold"></Column>
                    <Column field="layaway_expiration_date" header="Vence" sortable class="text-xs text-right" headerClass="text-right">
                        <template #body="{ data }">
                            <span :class="isExpired(data.layaway_expiration_date) ? 'text-red-500 font-bold bg-red-50 dark:bg-red-900/20 px-2 py-0.5 rounded' : 'text-gray-500'">
                                {{ formatDateOnly(data.layaway_expiration_date) }}
                            </span>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>

<style scoped>
:deep(.p-datatable.p-datatable-sm .p-datatable-tbody > tr > td) {
    padding: 0.75rem 0.5rem;
}
</style>