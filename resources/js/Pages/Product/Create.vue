<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    categories: Array,
    brands: Array,
    attributeDefinitions: Array,
});

// --- Estado del Formulario ---
const form = useForm({
    // Información general
    name: '',
    description: '',
    sku: '',
    category_id: null,
    brand_id: null,
    // Precios
    cost_price: null,
    provider_id: null, // Asumiendo que tendrás un modelo Provider
    selling_price: null,
    // Inventario y variantes
    product_type: 'simple', // 'simple' o 'variant'
    current_stock: null,
    min_stock: null,
    max_stock: null,
    measure_unit: 'pieza',
    general_images: [], // Renombrado de 'images'
    variant_images: {}, // Nuevo: para guardar imágenes por opción de variante
    // Gestión de variantes
    variant_attributes: [], // IDs de las definiciones de atributo seleccionadas
    variants_matrix: [], // Aquí se guardarán las combinaciones con su stock/precio
    // Opciones para tienda en línea
    show_online: false,
    online_price: null,
    is_on_sale: false,
    sale_price: null,
    sale_start_date: null,
    sale_end_date: null,
    requires_shipping: true,
    weight: null,
    length: null,
    width: null,
    height: null,
    tags: [],
});

const productTypeOptions = ref([
    { label: 'Producto Simple', value: 'simple' },
    { label: 'Producto con Variantes', value: 'variant' }
]);

// --- Lógica de Variantes e Imágenes ---
const availableAttributes = computed(() => {
    if (!form.category_id) return [];
    return props.attributeDefinitions.filter(attr => attr.category_id === form.category_id);
});

// Filtra los atributos seleccionados que requieren imagen
const imageRequiringAttributes = computed(() => {
    if (form.product_type !== 'variant') return [];
    return availableAttributes.value.filter(
        attr => form.variant_attributes.includes(attr.id) && attr.requires_image
    );
});

// Genera la matriz de combinaciones de variantes
const variantCombinations = computed(() => {
    if (form.product_type !== 'variant' || form.variant_attributes.length === 0) return [];

    const selectedAttrs = props.attributeDefinitions.filter(
        attr => form.variant_attributes.includes(attr.id)
    );

    if (selectedAttrs.length === 0) return [];

    const generate = (attrs, index = 0, current = {}) => {
        if (index === attrs.length) {
            const combination = { ...current, selected: true, sku_suffix: '', current_stock: 0, min_stock: 0, max_stock: 0, selling_price: form.selling_price };
            // Generamos un ID único para la fila
            combination.row_id = Object.values(combination).join('-');
            return [combination];
        }

        let results = [];
        const attr = attrs[index];
        attr.options.forEach(option => {
            let next = { ...current };
            next[attr.name] = option.value;
            results = results.concat(generate(attrs, index + 1, next));
        });
        return results;
    };

    const combinations = generate(selectedAttrs);
    // Sincronizamos con form.variants_matrix
    form.variants_matrix = combinations;
    return combinations;
});


// --- Lógica del Formulario ---
const submit = () => {
    form.post(route('products.store'), {
        // La opción `forceFormData` es necesaria para enviar archivos
        forceFormData: true,
    });
};

// --- Manejo de Imágenes ---
const onSelectGeneralImages = (event) => {
    form.general_images = [...form.general_images, ...event.files];
};
const onRemoveGeneralImage = (event) => {
    form.general_images = form.general_images.filter(img => img.objectURL !== event.file.objectURL);
};
const onSelectVariantImage = (event, optionValue) => {
    // Guardamos el archivo en el objeto, usando el valor de la opción como clave
    form.variant_images[optionValue] = event.files[0];
};
const onRemoveVariantImage = (optionValue) => {
    delete form.variant_images[optionValue];
};

</script>

<template>

    <Head title="Agregar Nuevo Producto" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-6">Agregar nuevo producto</h1>

                <form @submit.prevent="submit">
                    <!-- Sección de Información General -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Información general</h2>

                        <div class="grid grid-cols-1 gap-6">
                            <div>
                                <label for="name"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nombre del
                                    producto*</label>
                                <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                            </div>
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300">Descripción</label>
                                <Editor v-model="form.description" editorStyle="height: 150px" class="mt-1" />
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="sku"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU (Código
                                        de barras)</label>
                                    <InputText id="sku" v-model="form.sku" class="mt-1 w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label
                                        class="flex justify-between items-center mb-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <span>Categoría*</span>
                                        <Button icon="pi pi-plus" label="Nueva" text size="small" />
                                    </label>
                                    <Select v-model="form.category_id" size="large" :options="categories"
                                        optionLabel="name" optionValue="id" placeholder="Selecciona una categoría"
                                        class="w-full" />
                                </div>
                                <div>
                                    <label
                                        class="flex justify-between items-center mb-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <span>Marca</span>
                                        <Button icon="pi pi-plus" label="Nueva" text size="small" />
                                    </label>
                                    <Select v-model="form.brand_id" size="large" :options="brands" optionLabel="name"
                                        optionValue="id" placeholder="Selecciona una marca" class="w-full" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sección de Precios -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Precios</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Precio de
                                    compra</label>
                                <InputNumber v-model="form.cost_price" mode="currency" currency="MXN" locale="es-MX"
                                    class="w-full mt-1" />
                            </div>
                            <div>
                                <label
                                    class="flex justify-between items-center mb-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <span>Proveedor</span>
                                    <Button icon="pi pi-plus" label="Nuevo" text size="small" />
                                </label>
                                <Select v-model="form.provider_id" size="large" :options="[]"
                                    placeholder="Selecciona un proveedor" class="w-full" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Precio de
                                    venta al
                                    público*</label>
                                <InputNumber v-model="form.selling_price" mode="currency" currency="MXN" locale="es-MX"
                                    class="w-full mt-1" />
                            </div>
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300">Impuestos</label>
                                <Select :options="[]" size="large" placeholder="Selecciona el tipo de impuesto"
                                    class="w-full mt-1" />
                            </div>
                        </div>
                    </div>

                    <!-- Sección de Inventario y Variantes -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Inventario y variantes</h2>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tipo de
                                producto</label>
                            <SelectButton v-model="form.product_type" :options="productTypeOptions" optionLabel="label"
                                optionValue="value" />
                        </div>

                        <!-- Campos para Producto Simple -->
                        <div v-if="form.product_type === 'simple'" class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stock
                                    actual</label>
                                <InputNumber v-model="form.current_stock" class="w-full mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stock
                                    mínimo</label>
                                <InputNumber v-model="form.min_stock" class="w-full mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stock
                                    máximo</label>
                                <InputNumber v-model="form.max_stock" class="w-full mt-1" />
                            </div>
                        </div>

                        <!-- Campos para Producto con Variantes -->
                        <div v-if="form.product_type === 'variant' && form.category_id" class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Atributos para
                                variantes</label>
                            <MultiSelect v-model="form.variant_attributes" :options="availableAttributes"
                                optionLabel="name" optionValue="id" placeholder="Selecciona atributos"
                                class="w-full mt-1" />

                            <div v-if="variantCombinations.length > 0" class="mt-6">
                                <h3 class="font-semibold text-gray-800 dark:text-gray-200">Gestión de variantes</h3>
                                <DataTable :value="form.variants_matrix" dataKey="row_id" class="p-datatable-sm mt-2">
                                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                                    <Column
                                        v-for="attr in availableAttributes.filter(a => form.variant_attributes.includes(a.id))"
                                        :key="attr.id" :field="attr.name" :header="attr.name"></Column>
                                    <Column header="Stock Actual">
                                        <template #body="{ data }">
                                            <InputNumber v-model="data.current_stock" inputClass="w-20" />
                                        </template>
                                    </Column>
                                    <Column header="Stock Mínimo">
                                        <template #body="{ data }">
                                            <InputNumber v-model="data.min_stock" inputClass="w-20" />
                                        </template>
                                    </Column>
                                    <Column header="Precio Venta">
                                        <template #body="{ data }">
                                            <InputNumber v-model="data.selling_price" mode="currency" currency="MXN"
                                                locale="es-MX" inputClass="w-28" />
                                        </template>
                                    </Column>
                                </DataTable>
                            </div>
                        </div>
                        <div v-if="form.product_type === 'variant' && !form.category_id"
                            class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-300 rounded-md">
                            Por favor, selecciona una categoría, arriba en la sección de "Información general" para
                            gestionar sus variantes.
                        </div>

                        <!-- Pestañas para Carga de Imágenes -->
                        <div class="mt-6">
                            <Tabs>
                                <TabPanel header="Imágenes Generales">
                                    <FileUpload name="general_images[]" @select="onSelectGeneralImages"
                                        @remove="onRemoveGeneralImage" :multiple="true" accept="image/*"
                                        :maxFileSize="5000000">
                                        <template #empty>
                                            <p>Arrastra y suelta hasta 5 imágenes generales del producto.</p>
                                        </template>
                                    </FileUpload>
                                </TabPanel>
                                <TabPanel header="Imágenes por Variante"
                                    :disabled="imageRequiringAttributes.length === 0">
                                    <div v-if="imageRequiringAttributes.length > 0" class="space-y-4">
                                        <div v-for="attr in imageRequiringAttributes" :key="attr.id">
                                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Imágenes para
                                                {{ attr.name }}</h4>
                                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                                <div v-for="option in attr.options" :key="option.id"
                                                    class="text-center">
                                                    <label
                                                        class="text-sm font-medium text-gray-600 dark:text-gray-400">{{
                                                            option.value
                                                        }}</label>
                                                    <FileUpload mode="basic" name="variant_image[]" accept="image/*"
                                                        :maxFileSize="1000000" :auto="true" :customUpload="true"
                                                        @uploader="onSelectVariantImage($event, option.value)"
                                                        @remove="onRemoveVariantImage(option.value)"
                                                        chooseLabel="Elegir" class="mt-1" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="text-center text-gray-500 dark:text-gray-400 p-4">
                                        <p>Ninguna de las variantes seleccionadas requiere una imagen específica.</p>
                                        <p class="text-sm">Selecciona una categoría y un atributo como "Color" para
                                            activar esta sección.
                                        </p>
                                    </div>
                                </TabPanel>
                            </Tabs>
                        </div>

                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Unidad de
                                medida</label>
                            <Select v-model="form.measure_unit"
                                :options="['pieza', 'kg', 'g', 'litro', 'ml', 'caja', 'paquete']"
                                class="w-full md:w-1/3 mt-1" />
                        </div>
                    </div>

                    <!-- Sección Tienda en Línea -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Opciones para tienda en
                                línea</h2>
                            <ToggleSwitch v-model="form.show_online" />
                        </div>
                        <div v-if="form.show_online"
                            class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Precio de
                                    venta en línea</label>
                                <InputNumber v-model="form.online_price" mode="currency" currency="MXN" locale="es-MX"
                                    class="w-full mt-1" placeholder="Usar precio de venta general" />
                            </div>
                            <div class="flex items-center gap-2 pt-5">
                                <Checkbox v-model="form.requires_shipping" :binary="true" inputId="requires_shipping" />
                                <label for="requires_shipping">Requiere envío físico</label>
                            </div>
                            <div v-if="form.requires_shipping"
                                class="md:col-span-2 grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Peso
                                        (kg)</label>
                                    <InputNumber v-model="form.weight" class="w-full mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Largo
                                        (cm)</label>
                                    <InputNumber v-model="form.length" class="w-full mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Ancho
                                        (cm)</label>
                                    <InputNumber v-model="form.width" class="w-full mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Alto
                                        (cm)</label>
                                    <InputNumber v-model="form.height" class="w-full mt-1" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button type="submit" label="Crear producto" icon="pi pi-check" severity="warning"
                            :loading="form.processing" />
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<style>
.p-editor-container .p-editor-content .ql-editor {
    background-color: theme('colors.gray.50');
}

.dark .p-editor-container .p-editor-content .ql-editor {
    background-color: theme('colors.gray.900');
    color: theme('colors.gray.200');
}

.dark .p-editor-container .p-editor-toolbar {
    background-color: theme('colors.gray.700');
}
</style>