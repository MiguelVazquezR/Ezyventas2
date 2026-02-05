<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from "primevue/usetoast";
import { useConfirm } from "primevue/useconfirm";
import ManageStockModal from './Partials/ManageStockModal.vue'; // <-- CAMBIO: Importar nuevo modal
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

// composables
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: props.product.name }
]);
const localPromotions = ref([...props.promotions]);
const promoMenus = ref({});
const showManageStockModal = ref(false); // <-- CAMBIO: Nombre de variable actualizado

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


const actionItems = ref([
    { label: 'Crear nuevo', icon: 'pi pi-plus', command: () => router.get(route('products.create')), visible: hasPermission('products.create') },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('products.edit', props.product.id)), visible: hasPermission('products.edit') },
    { label: 'Agregar promoción', icon: 'pi pi-tag', command: () => router.get(route('products.promotions.create', props.product.id)), visible: hasPermission('products.manage_promos') },
    // --- CAMBIO: Opciones de Stock separadas para mejor UX ---
    { separator: true },
    { label: 'Entrada/salida de stock', icon: 'pi pi-box', class: 'text-green-600', command: () => showManageStockModal.value = true, visible: hasPermission('products.manage_stock') },
    // ---------------------------------------------------------
    { separator: true },
    { label: 'Imprimir etiqueta', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Eliminar producto', icon: 'pi pi-trash', class: 'text-red-500', command: () => deleteProduct(), visible: hasPermission('products.delete') },
]);

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        toast.add({ severity: 'success', summary: 'Copiado', detail: 'SKU copiado al portapapeles', life: 7000 });
    });
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};

// --- Funciones auxiliares para fecha ---
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

// --- Función para formatear moneda ---
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
        header: 'Confirmación de Eliminación',
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
        header: 'Confirmación de Eliminación',
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

// --- NUEVA LÓGICA DE GALERÍA CON ZOOM ---
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
// ----------------------------------------

// Genera un mapa de 'Opción' -> 'URL Imagen' (ej. 'Rojo' -> 'url...')
const variantImages = computed(() => {
    const media = props.product.media || [];
    const images = media.filter(m => m.collection_name === 'product-variant-images');
    const imageMap = {};
    images.forEach(img => {
        // Acceso seguro a custom_properties
        const properties = img.custom_properties || {};
        const option = properties.variant_option;
        if (option) {
            // Convertimos a string para asegurar coincidencias (ej. "24" vs 24)
            imageMap[String(option)] = img.original_url;
        }
    });
    return imageMap;
});

// Función auxiliar para obtener la imagen de una variante buscando coincidencias en sus atributos
const getVariantImage = (attributes) => {
    if (!attributes) return null;
    
    // Manejar caso donde attributes venga como string JSON
    let attrs = attributes;
    if (typeof attrs === 'string') {
        try {
            attrs = JSON.parse(attrs);
        } catch (e) {
            return null;
        }
    }

    if (!attrs || typeof attrs !== 'object') return null;

    // Iteramos sobre los valores de los atributos (ej. "Rojo", "M", "Algodón")
    for (const value of Object.values(attrs)) {
        // Convertimos el valor a string para buscar en el mapa
        const valStr = String(value);
        if (variantImages.value[valStr]) {
            return variantImages.value[valStr];
        }
    }
    return null;
};

const isVariantProduct = computed(() => props.product.product_attributes && props.product.product_attributes.length > 0);

// --- Computed property para los niveles de precio ---
const priceTiers = computed(() => {
    // Asegurarse de que exista y sea un array antes de ordenar
    if (!props.product.price_tiers || !Array.isArray(props.product.price_tiers)) {
        return [];
    }
    // Ordenar por cantidad mínima ascendente
    return [...props.product.price_tiers].sort((a, b) => a.min_quantity - b.min_quantity);
});

// --- Computed para totales (para variantes) ---
// El backend ya nos da estos totales en el `product` padre
const totalStock = computed(() => props.product.current_stock);
const totalReserved = computed(() => props.product.reserved_stock);
const totalAvailable = computed(() => props.product.available_stock);
</script>

<template>

    <Head :title="`Producto: ${product.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="items" class="!bg-transparent !p-0" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ product.name }}</h1>
            <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0">
            </SplitButton>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Izquierda (Imágenes) -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <!-- REEMPLAZO DE GALLERIA POR COMPONENTE IMAGE CON PREVIEW -->
                    <div v-if="generalImages.length > 0">
                        <div class="flex justify-center mb-4 bg-gray-50 dark:bg-gray-700 rounded-lg overflow-hidden border border-gray-100 dark:border-gray-600">
                            <!-- El componente Image tiene la propiedad 'preview' para zoom/fullscreen -->
                            <Image :src="currentGeneralImage?.original_url" :alt="product.name" preview 
                                imageClass="w-full h-80 object-contain p-2" />
                        </div>
                        
                        <!-- Tira de Miniaturas -->
                        <div v-if="generalImages.length > 1" class="flex gap-2 overflow-x-auto py-2 px-1">
                            <button v-for="(img, index) in generalImages" :key="img.id" 
                                @click="selectedImageIndex = index"
                                class="relative rounded-md overflow-hidden border-2 transition-all flex-shrink-0 focus:outline-none"
                                :class="selectedImageIndex === index ? 'border-primary-500 ring-2 ring-primary-200' : 'border-transparent opacity-70 hover:opacity-100'">
                                <img :src="img.original_url" :alt="img.name" class="w-16 h-16 object-cover bg-gray-50" />
                            </button>
                        </div>
                    </div>
                    
                    <div v-else class="text-center text-gray-500 py-8 flex flex-col items-center">
                        <i class="pi pi-image text-4xl mb-2 text-gray-300"></i>
                        <p>No hay imágenes generales.</p>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha (Información) -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h2
                                class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                                Información general</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex items-center">
                                    <span class="text-gray-500 dark:text-gray-400 w-24">SKU</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200 mr-2">{{ product.sku || 'N/A' }}</span>
                                    <Button v-if="product.sku" @click="copyToClipboard(product.sku)" icon="pi pi-copy"
                                        text rounded size="small" v-tooltip.bottom="'Copiar SKU'"></Button>
                                    <Button v-if="product.sku && hasPermission('pos.access')" @click="openPrintModal" icon="pi pi-print"
                                        text rounded size="small" v-tooltip.bottom="'Imprimir Etiqueta'"></Button>
                                </li>
                                <!-- AÑADIDO: Campo de Ubicación -->
                                <li class="flex">
                                    <span class="text-gray-500 dark:text-gray-400 w-24">Ubicación</span> 
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.location || 'N/A' }}</span>
                                </li>
                                <!-- FIN AÑADIDO -->
                                <li class="flex"><span class="text-gray-500 dark:text-gray-400 w-24">Categoría</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.category?.name || 'N/A' }}</span>
                                </li>
                                <li class="flex"><span class="text-gray-500 dark:text-gray-400 w-24">Marca</span> <span
                                        class="font-medium text-gray-800 dark:text-gray-200">{{ product.brand?.name || 'N/A' }}</span></li>
                            </ul>
                        </div>
                        <div>
                            <h2
                                class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                                Precios</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between items-center">
                                    <span class="text-gray-500 dark:text-gray-400">Precio (1 pieza)</span> 
                                    <span class="font-medium text-lg text-gray-800 dark:text-gray-200">{{ formatCurrency(product.selling_price) }}</span>
                                </li>
                                <!-- Mostrar Precios de Mayoreo -->
                                <li v-if="priceTiers.length > 0" class="pt-3 border-t border-gray-200 dark:border-gray-700">
                                    <span class="text-gray-500 dark:text-gray-400 block mb-2 font-medium">Precios de mayoreo:</span>
                                    <table class="w-full text-xs text-left">
                                        <thead class="text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th class="px-2 py-1">Desde (cant.)</th>
                                                <th class="px-2 py-1 text-right">Precio unitario</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(tier, index) in priceTiers" :key="index" class="border-b dark:border-gray-700">
                                                <td class="px-2 py-1">{{ tier.min_quantity }}</td>
                                                <td class="px-2 py-1 text-right font-semibold">{{ formatCurrency(tier.price) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </li>
                                <li v-if="hasPermission('products.see_cost_price')" class="flex justify-between pt-3 mt-3">
                                    <span class="text-gray-500 dark:text-gray-400">Precio de Compra</span> 
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatCurrency(product.cost_price) }}</span>
                                </li>
                                <li class="flex justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Proveedor</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.provider?.name || 'N/A' }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div v-if="product.description" class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold mb-2 text-gray-800 dark:text-gray-200">Descripción</h3>
                        <div class="prose prose-sm dark:prose-invert max-w-none" v-html="product.description"></div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2
                        class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                        Inventario y variantes</h2>

                    <!-- Vista para Producto Simple -->
                    <div v-if="!isVariantProduct" class="grid grid-cols-4 gap-4 text-center">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock Físico</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ totalStock }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Apartados</p>
                            <p class="text-2xl font-bold" :class="totalReserved > 0 ? 'text-blue-600' : 'text-gray-800 dark:text-gray-200'">
                                {{ totalReserved }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Disponible</p>
                            <p class="text-2xl font-bold" :class="totalAvailable > 0 ? 'text-green-600' : 'text-red-600'">
                                {{ totalAvailable }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock mínimo</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.min_stock || 'N/A' }}</p>
                        </div>
                    </div>

                    <!-- Vista para Producto con Variantes -->
                    <div v-else>
                        <DataTable :value="product.product_attributes" class="p-datatable-sm">
                            <Column header="Imagen">
                               <template #body="{ data }">
                                    <img v-if="getVariantImage(data.attributes)"
                                        :src="getVariantImage(data.attributes)"
                                        class="size-12 object-contain rounded-md" />
                                    <div v-else
                                        class="size-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <i class="pi pi-image text-2xl text-gray-400 dark:text-gray-500"></i>
                                    </div>
                                </template>
                            </Column>
                            <Column v-for="key in Object.keys(product.product_attributes[0]?.attributes || {})"
                                :key="key" :field="`attributes.${key}`" :header="key"></Column>
                            
                            <Column field="current_stock" header="Físico" sortable></Column>
                            <Column field="reserved_stock" header="Apartado" sortable>
                                <template #body="{ data }">
                                    <span :class="data.reserved_stock > 0 ? 'text-blue-600 font-semibold' : ''">{{ data.reserved_stock }}</span>
                                </template>
                            </Column>
                            <Column field="available_stock" header="Disponible" sortable>
                                 <template #body="{ data }">
                                    <span :class="data.available_stock > 0 ? 'text-green-600' : 'text-red-600 font-semibold'">{{ data.available_stock }}</span>
                                </template>
                            </Column>

                            <Column header="Precio">
                                 <template #body="{ data }">
                                    {{ formatCurrency(parseFloat(product.selling_price) + parseFloat(data.selling_price_modifier)) }}
                                </template>
                            </Column>
                        </DataTable>

                        <!-- Totales para producto con variantes -->
                        <div class="grid grid-cols-4 gap-4 text-center mt-6 pt-4 border-t dark:border-gray-700">
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Total físico</p>
                                <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ totalStock }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Total apartados</p>
                                <p class="text-2xl font-bold text-blue-600">{{ totalReserved }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Total disponible</p>
                                <p class="text-2xl font-bold" :class="totalAvailable > 0 ? 'text-green-600' : 'text-red-600'">
                                    {{ totalAvailable }}
                                </p>
                            </div>
                             <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Stock mínimo</p>
                                <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.min_stock || 'N/A' }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla de Apartados Activos -->
                    <div v-if="activeLayaways && activeLayaways.length > 0" class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h5 class="font-semibold mb-3 text-gray-800 dark:text-gray-200">Unidades apartadas (detalle)</h5>
                        <DataTable :value="activeLayaways" class="p-datatable-sm" responsiveLayout="scroll" sortField="date" :sortOrder="-1">
                            <Column field="date" header="Fecha apartado" sortable>
                                <template #body="{ data }">
                                    {{ formatDate(data.date) }}
                                </template>
                            </Column>
                            
                            <Column field="layaway_expiration_date" header="Vencimiento" sortable>
                                <template #body="{ data }">
                                    <span :class="{'text-red-500 font-bold': isExpired(data.layaway_expiration_date), 'text-gray-700 dark:text-gray-300': !isExpired(data.layaway_expiration_date)}">
                                        {{ formatDateOnly(data.layaway_expiration_date) }}
                                    </span>
                                </template>
                            </Column>

                            <Column field="customer_name" header="Cliente" sortable>
                                    <template #body="{ data }">
                                    <Link v-if="data.customer_id" :href="route('customers.show', data.customer_id)" class="text-blue-500 hover:underline">
                                        {{ data.customer_name }}
                                    </Link>
                                    <span v-else>{{ data.customer_name }}</span>
                                </template>
                            </Column>
                            <Column field="folio" header="Apartado #" sortable>
                                <template #body="{ data }">
                                    <Link :href="route('transactions.show', data.transaction_id)" class="text-blue-500 hover:underline">
                                        {{ data.folio }}
                                    </Link>
                                </template>
                            </Column>
                            <Column field="quantity" header="Cant." headerClass="text-center" bodyClass="text-center"></Column>
                            <Column field="description" header="Descripción (Item)"></Column>
                        </DataTable>
                    </div>

                </div>

                <!-- Promociones -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2
                        class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                        Promociones</h2>
                    <div v-if="localPromotions && localPromotions.length > 0" class="space-y-4">
                        <div v-for="promo in localPromotions" :key="promo.id" class="p-3 rounded-lg transition-colors"
                            :class="promo.is_active
                                ? 'border border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-900/20'
                                : 'border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-700/20 opacity-60'">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-bold"
                                        :class="promo.is_active ? 'text-yellow-800 dark:text-yellow-200' : 'text-gray-600 dark:text-gray-400'">
                                        {{ promo.name }}</p>
                                    <p class="text-sm mt-1"
                                        :class="promo.is_active ? 'text-yellow-700 dark:text-yellow-300' : 'text-gray-500 dark:text-gray-400'">
                                        {{ getPromotionSummary(promo) }}</p>
                                    <div class="text-xs mt-2 space-x-4"
                                        :class="promo.is_active ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-400 dark:text-gray-500'">
                                        <span v-if="promo.start_date"><i
                                                class="pi pi-calendar-plus mr-1"></i><strong>Inicio:</strong> {{
                                                    formatDate(promo.start_date) }}</span>
                                        <span v-if="promo.end_date"><i
                                                class="pi pi-calendar-times mr-1"></i><strong>Fin:</strong>
                                            {{ formatDate(promo.end_date) }}</span>
                                    </div>
                                </div>
                                <div class="flex-shrink-0 ml-4 flex items-center gap-2">
                                    <Tag :value="promo.is_active ? 'Activa' : 'Inactiva'"
                                        :severity="promo.is_active ? 'warning' : 'secondary'"></Tag>
                                    <Button v-if="hasPermission('products.manage_promos')" icon="pi pi-ellipsis-v" text rounded severity="secondary"
                                        @click="promoMenus[promo.id].toggle($event)" />
                                    <Menu :ref="el => { if (el) promoMenus[promo.id] = el }" :model="[
                                        { label: promo.is_active ? 'Inactivar' : 'Reactivar', icon: promo.is_active ? 'pi pi-power-off' : 'pi pi-check', command: () => togglePromotionStatus(promo) },
                                        { label: 'Eliminar', icon: 'pi pi-trash', command: () => deletePromotion(promo) }
                                    ]" :popup="true" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-500 dark:text-gray-400 py-4">
                        Este producto no tiene promociones asignadas.
                    </div>
                </div>

                <!-- historial -->
                <ActivityHistory :activities="activities" title="Historial de movimientos" />

            </div>
        </div>
        <!-- CAMBIO: Uso del nuevo modal unificado ManageStockModal -->
        <ManageStockModal v-if="product" :visible="showManageStockModal" :products="[product]"
            @update:visible="showManageStockModal = false" />
            
        <!-- Instancia del Modal de Impresión -->
        <PrintModal 
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />
    </AppLayout>
</template>