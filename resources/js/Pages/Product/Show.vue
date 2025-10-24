<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from "primevue/usetoast";
import { useConfirm } from "primevue/useconfirm";
import DiffViewer from '@/Components/DiffViewer.vue';
import AddStockModal from './Partials/AddStockModal.vue';
import PrintModal from '@/Components/PrintModal.vue'; // <-- 1. Importar el modal
import { usePermissions } from '@/Composables';

const props = defineProps({
    product: Object,
    activities: Array,
    promotions: Array,
    availableTemplates: Array, // <-- 2. Aceptar la nueva prop
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
const showAddStockModal = ref(false);

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
    { label: 'Crear Nuevo', icon: 'pi pi-plus', command: () => router.get(route('products.create')), visible: hasPermission('products.create') },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('products.edit', props.product.id)), visible: hasPermission('products.edit') },
    { label: 'Agregar Promoción', icon: 'pi pi-tag', command: () => router.get(route('products.promotions.create', props.product.id)), visible: hasPermission('products.manage_promos') },
    { label: 'Dar Entrada a Producto', icon: 'pi pi-arrow-down', command: () => showAddStockModal.value = true, visible: hasPermission('products.manage_stock') },
    // --- Añadido botón de imprimir al menú de acciones ---
    { label: 'Imprimir Etiqueta', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Eliminar Producto', icon: 'pi pi-trash', class: 'text-red-500', command: () => deleteProduct(), visible: hasPermission('products.delete') },
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

// --- AÑADIDO: Función para formatear moneda ---
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
    props.product.media.filter(m => m.collection_name === 'product-general-images')
);

const variantImages = computed(() => {
    const images = props.product.media.filter(m => m.collection_name === 'product-variant-images');
    const imageMap = {};
    images.forEach(img => {
        const option = img.custom_properties.variant_option;
        if (!imageMap[option]) {
            imageMap[option] = img.original_url;
        }
    });
    return imageMap;
});

const isVariantProduct = computed(() => props.product.product_attributes.length > 0);

// --- AÑADIDO: Computed property para los niveles de precio ---
const priceTiers = computed(() => {
    // Asegurarse de que exista y sea un array antes de ordenar
    if (!props.product.price_tiers || !Array.isArray(props.product.price_tiers)) {
        return [];
    }
    // Ordenar por cantidad mínima ascendente
    return [...props.product.price_tiers].sort((a, b) => a.min_quantity - b.min_quantity);
});

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
            <!-- Columna Izquierda -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <Galleria :value="generalImages" :numVisible="3" containerStyle="max-width: 640px;">
                        <template #item="slotProps">
                            <img :src="slotProps.item.original_url" :alt="slotProps.item.name" class="h-80 object-contain"
                                style="width: 100%; display: block;" />
                        </template>
                        <template #thumbnail="slotProps">
                            <img :src="slotProps.item.original_url" :alt="slotProps.item.name" style="display: block;"
                                class="h-16 w-16 object-cover" />
                        </template>
                    </Galleria>
                    <div v-if="generalImages.length === 0" class="text-center text-gray-500 py-8">
                        No hay imágenes generales.
                    </div>
                </div>
                <!-- <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2
                        class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                        Tienda en Línea</h2>
                    <ul class="space-y-3 text-sm">
                        <li class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Mostrar en tienda</span>
                            <Tag :value="product.show_online ? 'Sí' : 'No'"
                                :severity="product.show_online ? 'success' : 'danger'">
                            </Tag>
                        </li>
                        <li v-if="product.show_online" class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Precio en línea</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ new Intl.NumberFormat('es-MX',
                                {
                                    style:
                                        'currency', currency: 'MXN'
                                }).format(product.online_price || product.selling_price)
                                }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Requiere envío</span>
                            <Tag :value="product.requires_shipping ? 'Sí' : 'No'"
                                :severity="product.requires_shipping ? 'info' : 'secondary'"></Tag>
                        </li>
                        <li v-if="product.requires_shipping" class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Peso</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.weight || 'N/A' }}
                                kg</span>
                        </li>
                    </ul>
                </div> -->
            </div>

            <!-- Columna Derecha -->
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
                                <!-- --- INICIO: Mostrar Precios de Mayoreo --- -->
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
                                <!-- --- FIN: Mostrar Precios de Mayoreo --- -->
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

                    <div v-if="!isVariantProduct" class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock actual</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.current_stock }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock mínimo</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.min_stock || 'N/A' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock máximo</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.max_stock || 'N/A' }}</p>
                        </div>
                    </div>

                    <div v-else>
                        <DataTable :value="product.product_attributes" class="p-datatable-sm">
                            <Column header="Imagen">
                                <template #body="{ data }">
                                    <img v-if="variantImages[data.attributes.Color]"
                                        :src="variantImages[data.attributes.Color]"
                                        class="size-12 object-contain rounded-md" />
                                    <div v-else
                                        class="size-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <i class="pi pi-image text-2xl text-gray-400 dark:text-gray-500"></i>
                                    </div>
                                </template>
                            </Column>
                            <Column v-for="key in Object.keys(product.product_attributes[0]?.attributes || {})"
                                :key="key" :field="`attributes.${key}`" :header="key"></Column>
                            <Column field="current_stock" header="Stock"></Column>
                            <Column header="Precio">
                                <template #body="{ data }">
                                    {{ formatCurrency(parseFloat(product.selling_price) + parseFloat(data.selling_price_modifier)) }}
                                </template>
                            </Column>
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
                <!-- <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2
                        class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-6 text-gray-800 dark:text-gray-200">
                        Historial de Actividad</h2>
                    <div v-if="activities && activities.length > 0" class="relative max-h-[300px] overflow-y-auto pr-2">
                        <div class="relative pl-6">
                            <div class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                            </div>

                            <div class="space-y-8">
                                <div v-for="activity in activities" :key="activity.id" class="relative">
                                    <div class="absolute left-0 top-1.5 -translate-x-1/2">
                                        <span
                                            class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                                            :class="{
                                                'bg-blue-500': activity.event === 'created',
                                                'bg-orange-500': activity.event === 'updated',
                                                'bg-red-500': activity.event === 'deleted',
                                                'bg-purple-500': activity.event === 'promo'
                                            }">
                                            <i :class="{
                                                'pi pi-plus': activity.event === 'created',
                                                'pi pi-pencil': activity.event === 'updated',
                                                'pi pi-trash': activity.event === 'deleted',
                                                'pi pi-bolt': activity.event === 'promo'
                                            }"></i>
                                        </span>
                                    </div>

                                    <div class="ml-10">
                                        <h3 class="font-semibold text-gray-800 dark:text-gray-200 text-lg m-0">{{
                                            activity.description }}</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Por {{ activity.causer }} -
                                            {{
                                                activity.timestamp }}</p>

                                        <div v-if="activity.event === 'updated' && Object.keys(activity.changes.after).length > 0"
                                            class="mt-3 text-sm space-y-2">
                                            <div v-for="(value, key) in activity.changes.after" :key="key">
                                                <p class="font-medium text-gray-700 dark:text-gray-300">{{ key }}</p>
                                                <div v-if="key === 'Descripción'">
                                                    <DiffViewer :oldValue="activity.changes.before[key]"
                                                        :newValue="value" />
                                                </div>
                                                <div v-else class="flex items-center gap-2 text-xs">
                                                    <span
                                                        class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 px-2 py-0.5 rounded line-through">{{
                                                            activity.changes.before[key] || 'Vacío' }}</span>
                                                    <i class="pi pi-arrow-right text-gray-400"></i>
                                                    <span
                                                        class="bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 px-2 py-0.5 rounded font-medium">{{
                                                            value }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-500 py-8">
                        No hay actividades registradas para este producto.
                    </div>
                </div> -->

            </div>
        </div>
        <AddStockModal v-if="product" :visible="showAddStockModal" :product="product"
            @update:visible="showAddStockModal = false" />
            
        <!-- Instancia del Modal de Impresión -->
        <PrintModal 
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />
    </AppLayout>
</template>
