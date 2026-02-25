<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from "primevue/usetoast";
import { useConfirm } from "primevue/useconfirm";
import ManageStockModal from './Partials/ManageStockModal.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';
import PrintModal from '@/Components/PrintModal.vue';
import { usePermissions } from '@/Composables';
import Image from 'primevue/image'; 

const props = defineProps({
    product: Object,
    activities: Array,
    promotions: Array,
    availableTemplates: Array,
    activeLayaways: Array,
});

const toast = useToast();
const confirm = useConfirm();
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: props.product.name }
]);

const localPromotions = ref([...props.promotions]);
const promoMenus = ref({});
const showManageStockModal = ref(false);

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

const openPrintModal = () => {
    printDataSource.value = {
        type: 'product',
        id: props.product.id
    };
    isPrintModalVisible.value = true;
};
// --- Fin de la lógica de impresión ---

// --- Lógica para Menú de Acciones ---
const actionMenu = ref(null);

const actionItems = ref([
    { label: 'Crear nuevo', icon: 'pi pi-plus', command: () => router.get(route('products.create')), visible: hasPermission('products.create') },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('products.edit', props.product.id)), visible: hasPermission('products.edit') },
    { label: 'Agregar promoción', icon: 'pi pi-tag', command: () => router.get(route('products.promotions.create', props.product.id)), visible: hasPermission('products.manage_promos') },
    { separator: true },
    { label: 'Entrada/salida stock', icon: 'pi pi-box', class: 'text-green-600', command: () => showManageStockModal.value = true, visible: hasPermission('products.manage_stock') },
    { separator: true },
    { label: 'Imprimir etiqueta', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Eliminar producto', icon: 'pi pi-trash', class: 'text-red-500', command: () => deleteProduct(), visible: hasPermission('products.delete') },
]);

const toggleActionMenu = (event) => {
    actionMenu.value.toggle(event);
};
// -----------------------------------

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        toast.add({ severity: 'success', summary: 'Copiado', detail: 'SKU copiado al portapapeles', life: 3000 });
    });
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
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

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const togglePromotionStatus = (promo) => {
    router.patch(route('promotions.update', promo.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            const updatedPromo = localPromotions.value.find(p => p.id === promo.id);
            if (updatedPromo) {
                updatedPromo.is_active = !updatedPromo.is_active;
            }
        }
    });
};

const deleteProduct = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar "${props.product.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('products.destroy', props.product.id));
        }
    });
};

const deletePromotion = (promo) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la promoción "${promo.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('promotions.destroy', promo.id), {
                preserveScroll: true,
                onSuccess: () => {
                    localPromotions.value = localPromotions.value.filter(p => p.id !== promo.id);
                }
            });
        }
    });
};

const getPromotionSummary = (promo) => {
    switch (promo.type) {
        case 'ITEM_DISCOUNT': {
            const effect = promo.effects[0];
            if (effect.type === 'PERCENTAGE_DISCOUNT') return `Aplica un ${effect.value}% de descuento.`;
            if (effect.type === 'FIXED_DISCOUNT') return `Aplica un descuento de ${formatCurrency(effect.value)}.`;
            return 'Descuento especial aplicado.';
        }
        case 'BOGO': {
            const rule = promo.rules[0];
            const effect = promo.effects[0];
            return `Compra ${rule.value} de "${rule.itemable.name}" y llévate ${effect.value} de "${effect.itemable.name}" gratis.`;
        }
        case 'BUNDLE_PRICE': {
            const effect = promo.effects[0];
            const productDetails = promo.rules.map(rule => `${rule.value} x ${rule.itemable.name}`).join(' + ');
            return `Paquete (${productDetails}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};

const generalImages = computed(() =>
    (props.product.media || []).filter(m => m.collection_name === 'product-general-images')
);

const selectedImageIndex = ref(0);

const currentGeneralImage = computed(() => {
    if (generalImages.value && generalImages.value.length > 0) {
        if (selectedImageIndex.value >= generalImages.value.length) {
            selectedImageIndex.value = 0;
        }
        return generalImages.value[selectedImageIndex.value];
    }
    return null;
});

// Extrae las imágenes de variantes mapeadas por variant_key
const variantImages = computed(() => {
    const media = props.product.media || [];
    const images = media.filter(m => m.collection_name === 'product-variant-images');
    const imageMap = {};
    images.forEach(img => {
        const properties = img.custom_properties || {};
        // El controlador guarda la propiedad bajo 'variant_key'
        const option = properties.variant_key || properties.variant_option;
        if (option) {
            imageMap[String(option)] = img.original_url;
        }
    });
    return imageMap;
});

const getVariantImage = (variant) => {
    if (!variant) return null;
    
    // Primero, buscar si la key de la imagen coincide con el ID de la variante
    if (variant.id && variantImages.value[String(variant.id)]) {
        return variantImages.value[String(variant.id)];
    }
    
    // Si no, buscar por valor de atributo (como "Rojo" o "L")
    let attrs = variant.attributes;
    if (typeof attrs === 'string') {
        try { attrs = JSON.parse(attrs); } catch (e) { return null; }
    }
    if (!attrs || typeof attrs !== 'object') return null;

    for (const value of Object.values(attrs)) {
        const valStr = String(value);
        if (variantImages.value[valStr]) {
            return variantImages.value[valStr];
        }
    }
    return null;
};

const isVariantProduct = computed(() => props.product.product_attributes && props.product.product_attributes.length > 0);

const priceTiers = computed(() => {
    if (!props.product.price_tiers || !Array.isArray(props.product.price_tiers)) return [];
    return [...props.product.price_tiers].sort((a, b) => a.min_quantity - b.min_quantity);
});

const totalStock = computed(() => props.product.current_stock);
const totalReserved = computed(() => props.product.reserved_stock);
const totalAvailable = computed(() => props.product.available_stock);
</script>

<template>
    <Head :title="`Producto: ${product.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="items" class="!bg-transparent !p-0" />

        <!-- Header Minimalista -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end mt-2 mb-6 gap-4">
            <div>
                <div class="flex items-center gap-3 mb-1">
                    <Tag v-if="product.is_on_sale" severity="danger" value="En Oferta" rounded></Tag>
                    <Tag v-if="product.is_featured" severity="info" value="Destacado" rounded></Tag>
                    <span class="text-xs font-semibold text-gray-500 tracking-wider uppercase">
                        {{ product.category?.name || 'Sin categoría' }}
                    </span>
                </div>
                <h1 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight leading-none">{{ product.name }}</h1>
            </div>
            
            <!-- Reemplazo de SplitButton por Button + Menu -->
            <div>
                <Button label="Acciones" icon="pi pi-cog" @click="toggleActionMenu" severity="secondary" outlined class="w-full sm:w-auto" />
                <Menu ref="actionMenu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- COLUMNA IZQUIERDA: Galería e Info Rápida -->
            <div class="lg:col-span-3 space-y-6">
                <!-- Galería Limpia -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 p-4">
                    <div v-if="generalImages.length > 0">
                        <div class="flex justify-center mb-3 bg-gray-50 dark:bg-gray-900/50 rounded-xl overflow-hidden">
                            <Image :src="currentGeneralImage?.original_url" :alt="product.name" preview 
                                imageClass="w-full h-56 object-contain p-2 transition-transform duration-300 hover:scale-105" />
                        </div>
                        
                        <!-- Miniaturas -->
                        <div v-if="generalImages.length > 1" class="flex gap-2 overflow-x-auto py-1">
                            <button v-for="(img, index) in generalImages" :key="img.id" 
                                @click="selectedImageIndex = index"
                                class="relative rounded-lg overflow-hidden border-2 transition-all flex-shrink-0 focus:outline-none h-14 w-14"
                                :class="selectedImageIndex === index ? 'border-primary-500' : 'border-transparent opacity-60 hover:opacity-100'">
                                <img :src="img.original_url" :alt="img.name" class="w-full h-full object-cover bg-gray-100 dark:bg-gray-700" />
                            </button>
                        </div>
                    </div>
                    
                    <div v-else class="text-center text-gray-400 dark:text-gray-500 py-12 flex flex-col items-center bg-gray-50 dark:bg-gray-900/30 rounded-xl">
                        <i class="pi pi-image text-4xl mb-3 opacity-50"></i>
                        <span class="text-sm font-medium">Sin imagen general</span>
                    </div>
                </div>

                <!-- Tarjetas de Información Rápida (Grid) -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                        <div class="flex justify-between items-start mb-1">
                            <span class="text-[10px] text-gray-500 uppercase font-bold tracking-wider">SKU</span>
                            <div class="flex gap-1">
                                <i v-if="product.sku" @click="copyToClipboard(product.sku)" class="pi pi-copy text-gray-400 hover:text-primary-500 cursor-pointer text-xs transition-colors" v-tooltip.top="'Copiar'"></i>
                                <i v-if="product.sku && hasPermission('pos.access')" @click="openPrintModal" class="pi pi-print text-gray-400 hover:text-primary-500 cursor-pointer text-xs transition-colors" v-tooltip.top="'Imprimir'"></i>
                            </div>
                        </div>
                        <div class="font-mono font-medium text-gray-900 dark:text-gray-100 truncate text-sm" :title="product.sku">{{ product.sku || 'N/A' }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                        <span class="text-[10px] block text-gray-500 uppercase font-bold tracking-wider mb-1">Ubicación</span>
                        <div class="font-medium text-gray-900 dark:text-gray-100 text-sm truncate" :title="product.location">{{ product.location || '--' }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                        <span class="text-[10px] block text-gray-500 uppercase font-bold tracking-wider mb-1">Marca</span>
                        <div class="font-medium text-gray-900 dark:text-gray-100 text-sm truncate">{{ product.brand?.name || '--' }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                        <span class="text-[10px] block text-gray-500 uppercase font-bold tracking-wider mb-1">Proveedor</span>
                        <div class="font-medium text-gray-900 dark:text-gray-100 text-sm truncate">{{ product.provider?.name || '--' }}</div>
                    </div>
                </div>

                <!-- Detalles de Precios Avanzados -->
                <div class="bg-white dark:bg-gray-800 p-5 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <i class="pi pi-tag text-primary-500"></i> Estructura de precios
                    </h3>
                    
                    <div class="flex justify-between items-center py-2">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Precio de venta</span>
                        <span class="text-xl font-bold text-gray-900 dark:text-white">{{ formatCurrency(product.selling_price) }}</span>
                    </div>

                    <div v-if="hasPermission('products.see_cost_price')" class="flex justify-between items-center py-2 border-t border-gray-100 dark:border-gray-700/50 mt-1">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Precio de costo</span>
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ formatCurrency(product.cost_price) }}</span>
                    </div>

                    <div v-if="priceTiers.length > 0" class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                        <span class="text-xs text-gray-500 dark:text-gray-400 block mb-3 font-semibold">Precios por volumen:</span>
                        <div class="space-y-2">
                            <div v-for="(tier, index) in priceTiers" :key="index" class="flex justify-between items-center text-sm bg-gray-50 dark:bg-gray-900/40 px-3 py-2 rounded-lg">
                                <span class="text-gray-600 dark:text-gray-400">Desde <span class="font-bold">{{ tier.min_quantity }}</span> uds</span>
                                <span class="font-bold text-primary-600 dark:text-primary-400">{{ formatCurrency(tier.price) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- COLUMNA DERECHA: Inventario, Descripción, Promociones y Layouts -->
            <div class="lg:col-span-9 space-y-6">
                
                <!-- Sección: Descripción (Minimalista) -->
                <div v-if="product.description" class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
                        <i class="pi pi-align-left text-gray-400"></i> Descripción del producto
                    </h3>
                    <div class="prose prose-sm prose-gray dark:prose-invert max-w-none text-gray-600 dark:text-gray-400 leading-relaxed" v-html="product.description"></div>
                </div>

                <!-- Sección: Inventario y Variantes -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <div class="flex justify-between items-center mb-5">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                            <i class="pi pi-box text-blue-500"></i> Inventario y variantes
                        </h3>
                        <Button v-if="hasPermission('products.manage_stock')" label="Ajustar stock" icon="pi pi-sort-alt" size="small" outlined @click="showManageStockModal = true" class="!py-1" />
                    </div>

                    <!-- Indicadores (Cards) -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                        <div class="bg-gray-50 dark:bg-gray-900/40 p-4 rounded-xl border border-gray-100 dark:border-gray-700/50 flex flex-col justify-center items-center text-center transition-colors hover:bg-gray-100 dark:hover:bg-gray-900/60">
                            <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Stock físico</span>
                            <span class="text-2xl font-black text-gray-800 dark:text-gray-100">{{ totalStock }}</span>
                        </div>
                        <div class="bg-green-50/50 dark:bg-green-900/10 p-4 rounded-xl border border-green-100 dark:border-green-900/30 flex flex-col justify-center items-center text-center transition-colors hover:bg-green-50 dark:hover:bg-green-900/20">
                            <span class="text-[11px] font-bold text-green-600 dark:text-green-500 uppercase tracking-wider mb-1">Disponible</span>
                            <span class="text-2xl font-black text-green-600 dark:text-green-400">{{ totalAvailable }}</span>
                        </div>
                        <div class="bg-indigo-50/50 dark:bg-indigo-900/10 p-4 rounded-xl border border-indigo-100 dark:border-indigo-900/30 flex flex-col justify-center items-center text-center transition-colors hover:bg-indigo-50 dark:hover:bg-indigo-900/20">
                            <span class="text-[11px] font-bold text-indigo-600 dark:text-indigo-500 uppercase tracking-wider mb-1">Apartados</span>
                            <span class="text-2xl font-black text-indigo-600 dark:text-indigo-400">{{ totalReserved }}</span>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-900/40 p-4 rounded-xl border border-gray-100 dark:border-gray-700/50 flex flex-col justify-center items-center text-center transition-colors hover:bg-gray-100 dark:hover:bg-gray-900/60">
                            <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Stock mínimo</span>
                            <span class="text-2xl font-black text-gray-400 dark:text-gray-500">{{ product.min_stock || '--' }}</span>
                        </div>
                    </div>

                    <!-- Tabla de Variantes (Solo si existen) -->
                    <div v-if="isVariantProduct" class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                        <DataTable :value="product.product_attributes" class="p-datatable-sm" stripedRows>
                            <Column headerStyle="width: 4rem" bodyStyle="padding: 0.5rem">
                               <template #body="{ data }">
                                    <img v-if="getVariantImage(data)"
                                        :src="getVariantImage(data)"
                                        class="w-10 h-10 object-cover rounded-md border border-gray-100 dark:border-gray-700" />
                                    <div v-else
                                        class="w-10 h-10 rounded-md bg-gray-100 dark:bg-gray-800 flex items-center justify-center border border-gray-100 dark:border-gray-700">
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

                            <Column header="Precio final" class="text-sm font-semibold text-right" headerClass="text-right">
                                 <template #body="{ data }">
                                    {{ formatCurrency(parseFloat(product.selling_price) + parseFloat(data.selling_price_modifier)) }}
                                </template>
                            </Column>
                        </DataTable>
                    </div>

                    <!-- Apartados Activos (Tabla Minimalista) -->
                    <div v-if="activeLayaways && activeLayaways.length > 0" class="mt-8">
                        <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                            <i class="pi pi-clock text-indigo-400"></i> Detalle de apartados activos
                        </h4>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
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

                <!-- Sección: Promociones (Tarjetas Limpias) -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                        <i class="pi pi-percentage text-yellow-500"></i> Promociones vinculadas
                    </h3>
                    
                    <div v-if="localPromotions && localPromotions.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div v-for="promo in localPromotions" :key="promo.id" 
                            class="relative bg-white dark:bg-gray-900/30 rounded-xl border shadow-sm transition-all flex flex-col justify-between"
                            :class="promo.is_active ? 'border-l-4 border-l-yellow-400 border-gray-200 dark:border-gray-700' : 'border-l-4 border-l-gray-300 border-gray-200 dark:border-gray-700 opacity-70'">
                            
                            <div class="p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <h4 class="font-bold text-gray-800 dark:text-gray-100 line-clamp-1 pr-2" :title="promo.name">{{ promo.name }}</h4>
                                    <!-- Menú de opciones (3 puntos) -->
                                    <Button v-if="hasPermission('products.manage_promos')" icon="pi pi-ellipsis-v" text rounded size="small" class="!w-6 !h-6 !text-gray-400" @click="promoMenus[promo.id].toggle($event)" />
                                    <Menu :ref="el => { if (el) promoMenus[promo.id] = el }" :model="[
                                        { label: promo.is_active ? 'Inactivar' : 'Reactivar', icon: promo.is_active ? 'pi pi-power-off' : 'pi pi-check', command: () => togglePromotionStatus(promo) },
                                        { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: () => deletePromotion(promo) }
                                    ]" :popup="true" />
                                </div>
                                
                                <p class="text-sm text-gray-600 dark:text-gray-400 leading-snug mb-4 h-10 line-clamp-2" :title="getPromotionSummary(promo)">
                                    {{ getPromotionSummary(promo) }}
                                </p>
                                
                                <div class="flex justify-between items-end mt-auto">
                                    <div class="text-[10px] text-gray-500 uppercase font-semibold">
                                        <div v-if="promo.start_date || promo.end_date">
                                            Vence: <span class="text-gray-700 dark:text-gray-300">{{ formatDateOnly(promo.end_date) || 'Sin fecha' }}</span>
                                        </div>
                                    </div>
                                    <Tag :value="promo.is_active ? 'Activa' : 'Inactiva'" :severity="promo.is_active ? 'success' : 'secondary'" class="!text-[10px]"></Tag>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-400 dark:text-gray-500 py-6 border border-dashed border-gray-200 dark:border-gray-700 rounded-xl">
                        <span class="text-sm">No hay promociones activas para este producto.</span>
                    </div>
                </div>

                <!-- Sección: Historial de Actividad -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2 flex items-center gap-2">
                        <i class="pi pi-history text-gray-400"></i> Historial de movimientos
                    </h3>
                    <ActivityHistory :activities="activities" />
                </div>

            </div>
        </div>

        <ManageStockModal v-if="product" :visible="showManageStockModal" :products="[product]"
            @update:visible="showManageStockModal = false" />
            
        <PrintModal 
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />
    </AppLayout>
</template>

<style scoped>
.prose { max-width: 100%; }
:deep(.p-datatable.p-datatable-sm .p-datatable-tbody > tr > td) {
    padding: 0.75rem 0.5rem;
}
</style>