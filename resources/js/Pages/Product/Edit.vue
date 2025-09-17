<script setup>
import { ref, computed, nextTick, markRaw, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CreateCategoryModal from './Partials/CreateCategoryModal.vue';
import CreateBrandModal from './Partials/CreateBrandModal.vue';
import CreateProviderModal from './Partials/CreateProviderModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    product: Object,
    categories: Array,
    brands: Array,
    providers: Array,
    attributeDefinitions: Array,
});

// --- Estado del Formulario ---
const form = useForm({
    _method: 'PUT',
    name: props.product.name,
    description: props.product.description,
    sku: props.product.sku,
    category_id: props.product.category_id,
    brand_id: props.product.brand_id,
    cost_price: props.product.cost_price,
    provider_id: props.product.provider_id,
    selling_price: props.product.selling_price,
    product_type: props.product.product_attributes.length > 0 ? 'variant' : 'simple',
    current_stock: props.product.current_stock,
    min_stock: props.product.min_stock,
    max_stock: props.product.max_stock,
    measure_unit: props.product.measure_unit,
    general_images: [],
    variant_images: {},
    variant_attributes: [],
    variants_matrix: [],
    show_online: props.product.show_online,
    online_price: props.product.online_price,
    requires_shipping: props.product.requires_shipping,
    weight: props.product.weight,
    length: props.product.length,
    width: props.product.width,
    height: props.product.height,
    tags: props.product.tags || [],
    deleted_media_ids: [],
});

// --- Lógica de Variantes ---

// Mapa para buscar rápidamente los datos de las variantes guardadas
const savedAttributesMap = computed(() => {
    const map = new Map();
    props.product.product_attributes.forEach(pa => {
        const key = Object.entries(pa.attributes).sort().map(entry => entry.join(':')).join('|');
        map.set(key, pa);
    });
    return map;
});

// Genera las combinaciones y las fusiona con los datos guardados
const variantCombinations = computed(() => {
    if (form.product_type !== 'variant' || form.variant_attributes.length === 0) return [];
    const selectedAttrs = props.attributeDefinitions.filter(attr => form.variant_attributes.includes(attr.id));
    if (selectedAttrs.length === 0) return [];

    const generate = (attrs, index = 0, current = {}) => {
        if (index === attrs.length) {
            const key = Object.entries(current).sort().map(entry => entry.join(':')).join('|');
            const savedData = savedAttributesMap.value.get(key);
            let combination;
            if (savedData) {
                // Si encontramos datos guardados, los usamos
                combination = {
                    ...current,
                    sku_suffix: savedData.sku_suffix,
                    current_stock: savedData.current_stock,
                    min_stock: savedData.min_stock,
                    max_stock: savedData.max_stock,
                    selling_price: parseFloat(props.product.selling_price) + parseFloat(savedData.selling_price_modifier),
                };
            } else {
                // Si no, usamos valores por defecto
                combination = { ...current, sku_suffix: '', current_stock: 0, min_stock: 0, max_stock: 0, selling_price: form.selling_price };
            }
            combination.row_id = key;
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
    return generate(selectedAttrs);
});

// --- Estado e Inicialización ---
const selectedVariants = ref([]);

// Inicializar la selección de ATRIBUTOS en el MultiSelect
if (form.product_type === 'variant' && props.product.product_attributes.length > 0) {
    const firstVariantAttributes = props.product.product_attributes[0]?.attributes ?? {};
    const attributeNames = Object.keys(firstVariantAttributes);
    form.variant_attributes = props.attributeDefinitions
        .filter(def => attributeNames.includes(def.name) && def.category_id === form.category_id)
        .map(def => def.id);
}

// Observador para preseleccionar las VARIANTES guardadas en la tabla
watch(variantCombinations, (newCombinations) => {
    console.log(newCombinations)
    if (newCombinations.length > 0) {
        selectedVariants.value = newCombinations.filter(combo => savedAttributesMap.value.has(combo.row_id));
    }
}, { deep: true, immediate: true });


const submit = () => {
    const matrixWithSelection = variantCombinations.value.map(combo => ({
        ...combo,
        selected: selectedVariants.value.some(sel => sel.row_id === combo.row_id)
    }));
    form.variants_matrix = matrixWithSelection;
    form.post(route('products.update', props.product.id));
};

// ... (El resto del script, incluyendo manejo de imágenes y modales, es idéntico y no necesita cambios) ...
const existingGeneralImages = ref(props.product.media.filter(m => m.collection_name === 'product-general-images'));
const existingVariantImages = ref(props.product.media.filter(m => m.collection_name === 'product-variant-images'));
const variantImagePreviews = ref({});
existingVariantImages.value.forEach(img => { variantImagePreviews.value[img.custom_properties.variant_option] = img.original_url; });
const productTypeOptions = ref([{ label: 'Producto Simple', value: 'simple' }, { label: 'Producto con Variantes', value: 'variant' }]);
const availableAttributes = computed(() => { if (!form.category_id) return []; return props.attributeDefinitions.filter(attr => attr.category_id === form.category_id); });
const imageRequiringAttributes = computed(() => { if (form.product_type !== 'variant') return []; return availableAttributes.value.filter(attr => form.variant_attributes.includes(attr.id) && attr.requires_image); });
const deleteExistingImage = (mediaId) => { form.deleted_media_ids.push(mediaId); existingGeneralImages.value = existingGeneralImages.value.filter(img => img.id !== mediaId); };
const deleteExistingVariantImage = (mediaId, optionValue) => { form.deleted_media_ids.push(mediaId); existingVariantImages.value = existingVariantImages.value.filter(img => img.id !== mediaId); delete variantImagePreviews.value[optionValue]; };
const onSelectGeneralImages = (event) => { form.general_images = [...form.general_images, ...event.files]; };
const onRemoveGeneralImage = (event) => { form.general_images = form.general_images.filter(img => img.objectURL !== event.file.objectURL); };
const onSelectVariantImage = (event, optionValue) => { const file = event.files[0]; form.variant_images[optionValue] = file; variantImagePreviews.value[optionValue] = URL.createObjectURL(file); };
const localCategories = ref([...props.categories]);
const localBrands = ref(JSON.parse(JSON.stringify(props.brands)));
const localProviders = ref([...props.providers]);
const showCategoryModal = ref(false);
const showBrandModal = ref(false);
const showProviderModal = ref(false);
const handleNewCategory = (newCategory) => { localCategories.value.push(markRaw(newCategory)); nextTick(() => { form.category_id = newCategory.id; }); };
const handleNewBrand = (newBrand) => { const myBrandsGroup = localBrands.value.find(g => g.label === 'Mis Marcas'); if (myBrandsGroup) { myBrandsGroup.items.push(markRaw(newBrand)); } nextTick(() => { form.brand_id = newBrand.id; }); };
const handleNewProvider = (newProvider) => { localProviders.value.push(markRaw(newProvider)); nextTick(() => { form.provider_id = newProvider.id; }); };
</script>

<template>

    <Head :title="`Editar: ${form.name}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-6">Editar producto</h1>
                <form @submit.prevent="submit">
                    <!-- Sección de Información General -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Información general</h2>
                        <div class="grid grid-cols-1 gap-6">
                            <div>
                                <InputLabel for="name" value="Nombre del producto*" />
                                <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                                <InputError class="mt-2" :message="form.errors.name" />
                            </div>
                            <div>
                                <InputLabel value="Descripción" />
                                <Editor v-model="form.description" editorStyle="height: 150px" class="mt-1" />
                                <InputError class="mt-2" :message="form.errors.description" />
                            </div>
                            <div>
                                <InputLabel for="sku" value="SKU (Código de barras)" />
                                <InputText id="sku" v-model="form.sku" class="mt-1 w-full" />
                                <InputError class="mt-2" :message="form.errors.sku" />
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <InputLabel for="category_id" value="Categoría*" />
                                        <Button @click="showCategoryModal = true" icon="pi pi-plus" label="Nueva" text
                                            size="small" />
                                    </div>
                                    <Select v-model="form.category_id" id="category_id" size="large"
                                        :options="localCategories" filter optionLabel="name" optionValue="id"
                                        placeholder="Selecciona una categoría" class="w-full" />
                                    <InputError class="mt-2" :message="form.errors.category_id" />
                                </div>
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <InputLabel for="brand_id" value="Marca" />
                                        <Button @click="showBrandModal = true" icon="pi pi-plus" label="Nueva" text
                                            size="small" />
                                    </div>
                                    <Select v-model="form.brand_id" id="brand_id" size="large" :options="localBrands"
                                        filter optionLabel="name" optionValue="id" placeholder="Selecciona una marca"
                                        class="w-full" optionGroupLabel="label" optionGroupChildren="items">
                                        <template #optiongroup="{ option }">
                                            <div
                                                class="flex items-center font-bold px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                                {{ option.label }}</div>
                                        </template>
                                        <template #option="{ option }">
                                            <div class="px-2 py-1">{{ option.name }}</div>
                                        </template>
                                    </Select>
                                    <InputError class="mt-2" :message="form.errors.brand_id" />
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
                            <div>
                                <InputLabel for="cost_price" value="Precio de compra" />
                                <InputNumber v-model="form.cost_price" id="cost_price" mode="currency" currency="MXN"
                                    locale="es-MX" class="w-full mt-1" />
                                <InputError class="mt-2" :message="form.errors.cost_price" />
                            </div>
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <InputLabel for="provider_id" value="Proveedor" />
                                    <Button @click="showProviderModal = true" icon="pi pi-plus" label="Nuevo" text
                                        size="small" />
                                </div>
                                <Select v-model="form.provider_id" id="provider_id" size="large"
                                    :options="localProviders" filter optionLabel="name" optionValue="id"
                                    placeholder="Selecciona un proveedor" class="w-full" />
                                <InputError class="mt-2" :message="form.errors.provider_id" />
                            </div>
                            <div>
                                <InputLabel for="selling_price" value="Precio de venta al público*" />
                                <InputNumber v-model="form.selling_price" id="selling_price" mode="currency"
                                    currency="MXN" locale="es-MX" class="w-full mt-1" />
                                <InputError class="mt-2" :message="form.errors.selling_price" />
                            </div>
                        </div>
                    </div>
                    <!-- Sección de Inventario y Variantes -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Inventario y variantes</h2>
                        <div>
                            <InputLabel value="Tipo de producto" class="mb-2" />
                            <SelectButton v-model="form.product_type" :options="productTypeOptions" optionLabel="label"
                                optionValue="value" />
                        </div>
                        <div v-if="form.product_type === 'simple'" class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <InputLabel for="current_stock_simple" value="Stock actual" />
                                <InputNumber v-model="form.current_stock" id="current_stock_simple"
                                    class="w-full mt-1" />
                                <InputError class="mt-2" :message="form.errors.current_stock" />
                            </div>
                            <div>
                                <InputLabel for="min_stock_simple" value="Stock mínimo" />
                                <InputNumber v-model="form.min_stock" id="min_stock_simple" class="w-full mt-1" />
                            </div>
                            <div>
                                <InputLabel for="max_stock_simple" value="Stock máximo" />
                                <InputNumber v-model="form.max_stock" id="max_stock_simple" class="w-full mt-1" />
                            </div>
                        </div>
                        <div v-if="form.product_type === 'variant' && form.category_id" class="mt-6">
                            <InputLabel for="variant_attributes" value="Atributos para variantes" />
                            <MultiSelect v-model="form.variant_attributes" id="variant_attributes"
                                :options="availableAttributes" optionLabel="name" optionValue="id"
                                placeholder="Selecciona atributos" class="w-full mt-1" />
                            <div v-if="variantCombinations.length > 0" class="mt-6">
                                <h3 class="font-semibold text-gray-800 dark:text-gray-200">Gestión de variantes</h3>
                                <DataTable :value="variantCombinations" v-model:selection="selectedVariants"
                                    dataKey="row_id" class="p-datatable-sm mt-2">
                                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                                    <Column
                                        v-for="attr in availableAttributes.filter(a => form.variant_attributes.includes(a.id))"
                                        :key="attr.id" :field="attr.name" :header="attr.name"></Column>
                                    <Column header="Stock Actual"><template #body="{ data }">
                                            <InputNumber v-model="data.current_stock" inputClass="w-20" />
                                        </template>
                                    </Column>
                                    <Column header="Stock Mínimo"><template #body="{ data }">
                                            <InputNumber v-model="data.min_stock" inputClass="w-20" />
                                        </template></Column>
                                    <Column header="Precio Venta"><template #body="{ data }">
                                            <InputNumber v-model="data.selling_price" mode="currency" currency="MXN"
                                                locale="es-MX" inputClass="w-28" />
                                        </template>
                                    </Column>
                                </DataTable>
                            </div>
                        </div>
                        <div class="mt-6">
                            <Tabs>
                                <TabPanel header="Imágenes Generales">
                                    <div v-if="existingGeneralImages.length > 0" class="flex flex-wrap gap-4 mb-4">
                                        <div v-for="img in existingGeneralImages" :key="img.id" class="relative">
                                            <img :src="img.original_url"
                                                class="w-24 h-24 object-cover rounded-md border">
                                            <Button @click="deleteExistingImage(img.id)" icon="pi pi-times" rounded text
                                                severity="danger"
                                                class="!absolute -top-2 -right-2 bg-white/70 dark:bg-gray-800/70" />
                                        </div>
                                    </div>
                                    <FileUpload name="general_images[]" @select="onSelectGeneralImages"
                                        @remove="onRemoveGeneralImage" :multiple="true" accept="image/*"
                                        :maxFileSize="5000000">
                                        <template #empty>
                                            <p>Arrastra y suelta para añadir más imágenes.</p>
                                        </template>
                                    </FileUpload>
                                    <InputError class="mt-2" :message="form.errors.general_images" />
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
                                                    <InputLabel :value="option.value" class="text-sm" />
                                                    <div class="mt-1 flex flex-col items-center">
                                                        <div class="relative w-20 h-20 mb-2">
                                                            <img v-if="variantImagePreviews[option.value]"
                                                                :src="variantImagePreviews[option.value]"
                                                                class="w-full h-full object-cover rounded-md border">
                                                            <div v-else
                                                                class="w-full h-full bg-gray-100 dark:bg-gray-700 rounded-md flex items-center justify-center text-gray-400">
                                                                <i class="pi pi-image text-2xl"></i>
                                                            </div>
                                                            <Button
                                                                v-if="existingVariantImages.find(i => i.custom_properties.variant_option === option.value)"
                                                                @click="deleteExistingVariantImage(existingVariantImages.find(i => i.custom_properties.variant_option === option.value).id, option.value)"
                                                                icon="pi pi-times" rounded text severity="danger"
                                                                class="!absolute -top-2 -right-2 bg-white/70 dark:bg-gray-800/70" />
                                                        </div>
                                                        <FileUpload mode="basic" name="variant_image[]" accept="image/*"
                                                            :maxFileSize="1000000" :auto="true" :customUpload="true"
                                                            @select="onSelectVariantImage($event, option.value)"
                                                            chooseLabel="Elegir" class="w-20" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="text-center text-gray-500 dark:text-gray-400 p-4 text-sm">
                                        <p class="m-0">Ninguna de las variantes seleccionadas requiere una imagen
                                            específica.</p>
                                    </div>
                                    <InputError class="mt-2" :message="form.errors.variant_images" />
                                </TabPanel>
                            </Tabs>
                        </div>
                        <div class="mt-6">
                            <InputLabel for="measure_unit" value="Unidad de medida" />
                            <Select v-model="form.measure_unit" id="measure_unit" size="large"
                                :options="['pieza', 'kg', 'g', 'litro', 'ml', 'caja', 'paquete']"
                                class="w-full md:w-1/3 mt-1" />
                            <InputError class="mt-2" :message="form.errors.measure_unit" />
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
                                <InputLabel for="online_price" value="Precio de venta en línea" />
                                <InputNumber v-model="form.online_price" id="online_price" mode="currency"
                                    currency="MXN" locale="es-MX" class="w-full mt-1"
                                    placeholder="Usar precio de venta general" />
                            </div>
                            <div class="flex items-center gap-2 pt-5">
                                <Checkbox v-model="form.requires_shipping" :binary="true" inputId="requires_shipping" />
                                <InputLabel for="requires_shipping" value="Requiere envío físico" />
                            </div>
                            <div v-if="form.requires_shipping"
                                class="md:col-span-2 grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <InputLabel for="weight" value="Peso (kg)" />
                                    <InputNumber v-model="form.weight" id="weight" mode="decimal" :minFractionDigits="0"
                                        :maxFractionDigits="3" class="w-full mt-1" />
                                    <InputError class="mt-2" :message="form.errors.weight" />
                                </div>
                                <div>
                                    <InputLabel for="length" value="Largo (cm)" />
                                    <InputNumber v-model="form.length" id="length" class="w-full mt-1" />
                                </div>
                                <div>
                                    <InputLabel for="width" value="Ancho (cm)" />
                                    <InputNumber v-model="form.width" id="width" class="w-full mt-1" />
                                </div>
                                <div>
                                    <InputLabel for="height" value="Alto (cm)" />
                                    <InputNumber v-model="form.height" id="height" class="w-full mt-1" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" label="Actualizar producto" icon="pi pi-check" severity="warning"
                            :loading="form.processing" />
                    </div>
                </form>
                <!-- Modales -->
                <CreateCategoryModal v-model:visible="showCategoryModal" @created="handleNewCategory" />
                <CreateBrandModal v-model:visible="showBrandModal" @created="handleNewBrand" />
                <CreateProviderModal v-model:visible="showProviderModal" @created="handleNewProvider" />
            </div>
        </div>
    </AppLayout>
</template>