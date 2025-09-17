<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from "primevue/usetoast";
import DiffViewer from '@/Components/DiffViewer.vue';

const props = defineProps({
    product: Object,
    activities: Array,
});

const toast = useToast();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: props.product.name }
]);

const actionItems = ref([
    { label: 'Crear Nuevo', icon: 'pi pi-plus', command: () => router.get(route('products.create')) },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('products.edit', props.product.id)) },
    { label: 'Agregar Promoción', icon: 'pi pi-tag' },
    { label: 'Dar Entrada a Producto', icon: 'pi pi-arrow-down' },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500' },
]);

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        toast.add({ severity: 'success', summary: 'Copiado', detail: 'SKU copiado al portapapeles', life: 3000 });
    });
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

</script>

<template>

    <Head :title="`Producto: ${product.name}`" />
    <Toast />
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
                    <Galleria :value="generalImages" :numVisible="3" containerStyle="max-width: 640px">
                        <template #item="slotProps">
                            <img :src="slotProps.item.original_url" :alt="slotProps.item.name"
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
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
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
                                        'currency', currency: 'MXN' }).format(product.online_price || product.selling_price)
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
                </div>
            </div>

            <!-- Columna Derecha -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h2
                                class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                                Información General</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex items-center">
                                    <span class="text-gray-500 dark:text-gray-400 w-24">SKU</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200 mr-2">{{ product.sku ||
                                        'N/A'
                                        }}</span>
                                    <Button v-if="product.sku" @click="copyToClipboard(product.sku)" icon="pi pi-copy"
                                        text rounded size="small" v-tooltip.bottom="'Copiar SKU'"></Button>
                                </li>
                                <li class="flex"><span class="text-gray-500 dark:text-gray-400 w-24">Categoría</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.category?.name
                                        || 'N/A'
                                        }}</span></li>
                                <li class="flex"><span class="text-gray-500 dark:text-gray-400 w-24">Marca</span> <span
                                        class="font-medium text-gray-800 dark:text-gray-200">{{ product.brand?.name ||
                                        'N/A'
                                        }}</span></li>
                            </ul>
                        </div>
                        <div>
                            <h2
                                class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                                Precios</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Precio
                                        de
                                        Venta</span> <span
                                        class="font-medium text-lg text-gray-800 dark:text-gray-200">{{ new
                                            Intl.NumberFormat('es-MX', {
                                                style: 'currency', currency: 'MXN'
                                        }).format(product.selling_price) }}</span></li>
                                <li class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Precio
                                        de
                                        Compra</span> <span class="font-medium text-gray-800 dark:text-gray-200">{{ new
                                            Intl.NumberFormat('es-MX', {
                                                style: 'currency', currency: 'MXN'
                                        }).format(product.cost_price) }}</span></li>
                                <li class="flex justify-between"><span
                                        class="text-gray-500 dark:text-gray-400">Proveedor</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.provider?.name
                                        || 'N/A'
                                        }}</span>
                                </li>
                                <li class="flex justify-between"><span
                                        class="text-gray-500 dark:text-gray-400">Impuesto</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ product.tax_rate ?
                                        `${product.tax_rate}%` : 'Sin impuestos' }}</span>
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
                        Inventario y Variantes</h2>

                    <div v-if="!isVariantProduct" class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock Actual</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.current_stock }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock Mínimo</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.min_stock || 'N/A'
                                }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Stock Máximo</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ product.max_stock || 'N/A'
                                }}</p>
                        </div>
                    </div>

                    <div v-else>
                        <DataTable :value="product.product_attributes" class="p-datatable-sm">
                            <Column header="Imagen">
                                <template #body="{ data }">
                                    <img v-if="variantImages[data.attributes.Color]"
                                        :src="variantImages[data.attributes.Color]"
                                        class="w-12 h-12 object-cover rounded-md" />
                                    <div v-else
                                        class="w-12 h-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <i class="pi pi-image text-2xl text-gray-400 dark:text-gray-500"></i>
                                    </div>
                                </template>
                            </Column>
                            <Column v-for="key in Object.keys(product.product_attributes[0]?.attributes || {})"
                                :key="key" :field="`attributes.${key}`" :header="key"></Column>
                            <Column field="current_stock" header="Stock"></Column>
                            <Column header="Precio">
                                <template #body="{ data }">
                                    {{ new Intl.NumberFormat('es-MX', {
                                        style: 'currency', currency: 'MXN'
                                    }).format(parseFloat(product.selling_price) +
                                    parseFloat(data.selling_price_modifier)) }}
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2
                        class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-6 text-gray-800 dark:text-gray-200">
                        Historial de Actividad</h2>
                    <div v-if="activities && activities.length > 0" class="relative max-h-[350px] overflow-y-auto pr-2">
                        <div class="relative pl-6">
                            <!-- Línea vertical del timeline -->
                            <div class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                            </div>

                            <div class="space-y-8">
                                <div v-for="activity in activities" :key="activity.id" class="relative">
                                    <!-- Ícono del evento -->
                                    <div class="absolute left-0 top-1.5 -translate-x-1/2">
                                        <span
                                            class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                                            :class="{ 'bg-blue-500': activity.event === 'created', 'bg-orange-500': activity.event === 'updated', 'bg-red-500': activity.event === 'deleted' }">
                                            <i
                                                :class="{ 'pi pi-plus': activity.event === 'created', 'pi pi-pencil': activity.event === 'updated', 'pi pi-trash': activity.event === 'deleted' }"></i>
                                        </span>
                                    </div>

                                    <!-- Contenido del evento -->
                                    <div class="ml-10">
                                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 m-0">{{
                                            activity.description }}</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Por {{ activity.causer }} -
                                            {{
                                            activity.timestamp }}</p>

                                        <div v-if="activity.event === 'updated' && Object.keys(activity.changes.after).length > 0"
                                            class="text-sm space-y-4">
                                            <div v-for="(value, key) in activity.changes.after" :key="key">
                                                <p class="font-medium text-gray-700 dark:text-gray-300 m-0">{{ key }}</p>
                                                <div v-if="key === 'Descripción'">
                                                   <DiffViewer :oldValue="activity.changes.before[key]"
                                                    :newValue="value" />
                                                </div>
                                                <div v-else class="flex items-center gap-2 text-xs">
                                                    <span
                                                        class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 px-2 py-0.5 rounded line-through">{{
                                                        activity.changes.before[key] || 'Vacío' }}</span>
                                                    <i class="pi pi-arrow-right text-gray-400 !text-xs"></i>
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
                </div>
            </div>
        </div>
    </AppLayout>
</template>