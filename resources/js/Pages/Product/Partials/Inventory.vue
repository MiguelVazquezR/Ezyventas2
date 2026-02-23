<script setup>
import { ref, computed, watch } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    form: Object,
    attributeDefinitions: Array,
});

const emit = defineEmits(['open-attributes']);
const confirm = useConfirm();

let localIdCounter = 0;

// --- LÓGICA DE ATRIBUTOS POR CATEGORÍA ---
const selectedAttributeValues = ref({});

// Filtra las definiciones de atributos según la categoría seleccionada
const categoryAttributes = computed(() => {
    if (!props.form.category_id) return [];
    // Usamos == en lugar de === para evitar fallos por tipos de datos (String vs Number)
    return props.attributeDefinitions.filter(attr => attr.category_id == props.form.category_id);
});

// Función robusta para mapear las opciones al MultiSelect
const getOptions = (attr) => {
    if (!attr || !attr.options) return [];
    return attr.options.map(opt => opt.value);
};

// Limpia los valores seleccionados si el usuario cambia de categoría
watch(() => props.form.category_id, () => {
    selectedAttributeValues.value = {};
});

// --- GENERADOR DE MATRIZ DE VARIANTES ---
const generateMatrix = () => {
    const keys = Object.keys(selectedAttributeValues.value).filter(k => selectedAttributeValues.value[k].length > 0);
    
    if (keys.length === 0) return;

    const arrays = keys.map(k => selectedAttributeValues.value[k].map(v => ({ [k]: v })));
    const cartesian = arrays.reduce((a, b) => a.flatMap(d => b.map(e => ({ ...d, ...e }))), [{}]);

    const newMatrix = cartesian.map(combo => {
        const existing = props.form.variants_matrix.find(v => {
            const existingKeys = Object.keys(v.attributes);
            if (existingKeys.length !== keys.length) return false;
            return existingKeys.every(k => v.attributes[k] === combo[k]);
        });

        if (existing) return existing; 

        return {
            _localId: `gen_${localIdCounter++}`,
            attributes: combo,
            sku: '',
            selling_price_modifier: 0,
            current_stock: 0,
        };
    });

    props.form.variants_matrix = newMatrix;
    
    first.value = 0;
    variantSearch.value = '';
};

// --- AGREGAR Y ELIMINAR VARIANTES ---
const addManualVariant = () => {
    props.form.variants_matrix.unshift({
        _localId: `new_${localIdCounter++}`,
        attributes: { 'Detalle': '' },
        sku: '',
        selling_price_modifier: 0,
        current_stock: 0,
    });
};

const confirmRemoveVariant = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        group: 'inventory-variant-delete',
        message: '¿Estás seguro de eliminar esta variante?',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger p-button-sm',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            props.form.variants_matrix.splice(index, 1);
        }
    });
};

// --- OPTIMIZACIÓN DE RENDERIZADO (BÚSQUEDA Y PAGINACIÓN) ---
const variantSearch = ref('');
const first = ref(0);
const rows = ref(10);

const filteredVariants = computed(() => {
    if (!variantSearch.value.trim()) return props.form.variants_matrix;
    const term = variantSearch.value.toLowerCase().trim();
    return props.form.variants_matrix.filter(v => {
        const attrs = Object.values(v.attributes).join(' ').toLowerCase();
        const sku = (v.sku || '').toLowerCase();
        return attrs.includes(term) || sku.includes(term);
    });
});

const paginatedVariants = computed(() => {
    return filteredVariants.value.slice(first.value, first.value + rows.value);
});

const onPage = (event) => {
    first.value = event.first;
    rows.value = event.rows;
};

watch(variantSearch, () => {
    first.value = 0;
});
</script>

<template>
    <div id="inventory" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md scroll-mt-24">
        <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
            Inventario y Variantes
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="col-span-full">
                <InputLabel value="Tipo de producto *" />
                <div class="flex gap-4 mt-2">
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_simple" value="simple" />
                        <label for="type_simple" class="ml-2 cursor-pointer font-medium">Producto simple</label>
                    </div>
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_variant" value="variant" />
                        <label for="type_variant" class="ml-2 cursor-pointer font-medium">Producto con variantes</label>
                    </div>
                </div>
            </div>

            <!-- PRODUCTO SIMPLE -->
            <template v-if="form.product_type === 'simple'">
                <div>
                    <InputLabel for="current_stock" value="Stock inicial *" />
                    <InputNumber v-model="form.current_stock" id="current_stock" class="w-full mt-1" />
                    <InputError :message="form.errors.current_stock" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="measure_unit" value="Unidad de medida *" />
                    <Select v-model="form.measure_unit" id="measure_unit" :options="['Pza', 'Kg', 'Lts', 'Mts']" class="w-full mt-1" />
                    <InputError :message="form.errors.measure_unit" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="min_stock" value="Stock mínimo (Opcional)" />
                    <InputNumber v-model="form.min_stock" id="min_stock" class="w-full mt-1" />
                </div>
                <div>
                    <InputLabel for="max_stock" value="Stock máximo (Opcional)" />
                    <InputNumber v-model="form.max_stock" id="max_stock" class="w-full mt-1" />
                </div>
            </template>

            <!-- PRODUCTO CON VARIANTES -->
            <template v-else>
                
                <!-- SECCIÓN: GENERADOR DE ATRIBUTOS -->
                <div class="col-span-full bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-lg p-4 mb-2">
                    <div class="flex justify-between items-center mb-1">
                        <h3 class="font-bold text-gray-800 dark:text-gray-200 m-0">Generador Automático de Variantes</h3>
                        <Button @click="$emit('open-attributes')" :disabled="!form.category_id" label="Configurar Atributos" icon="pi pi-cog" size="small" outlined />
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Selecciona las características (ej. Colores, Tallas) y haz clic en "Generar combinaciones" para crear automáticamente todas las versiones de tu producto.
                    </p>

                    <div v-if="!form.category_id" class="text-sm text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg flex items-center gap-2">
                        <i class="pi pi-info-circle"></i>
                        <span>Selecciona primero una <strong>Categoría</strong> en la sección "Información general" para ver los atributos disponibles.</span>
                    </div>

                    <div v-else-if="categoryAttributes.length === 0" class="text-sm text-gray-500 italic text-center p-2">
                        Esta categoría no tiene atributos configurados. Haz clic en "Configurar Atributos" para crear los tuyos.
                    </div>

                    <div v-else class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="attr in categoryAttributes" :key="attr.id" class="flex flex-col gap-1">
                                <InputLabel :value="attr.name" class="font-semibold" />
                                <MultiSelect 
                                    v-model="selectedAttributeValues[attr.name]" 
                                    :options="getOptions(attr)" 
                                    :placeholder="`Seleccionar ${attr.name.toLowerCase()}`" 
                                    display="chip" 
                                    class="w-full"
                                />
                            </div>
                        </div>
                        
                        <div class="flex justify-end pt-2">
                            <Button @click="generateMatrix" label="Generar combinaciones" icon="pi pi-sync" severity="info" :disabled="Object.keys(selectedAttributeValues).length === 0" />
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: TABLA DE VARIANTES GENERADAS -->
                <div class="col-span-full bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800 mt-2">
                    
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-2 gap-4">
                        <div>
                            <h3 class="font-bold text-blue-800 dark:text-blue-200 m-0">Matriz de Variantes ({{ filteredVariants.length }})</h3>
                            <p class="text-sm text-blue-600 dark:text-blue-400 m-0">
                                Asigna inventario inicial, código SKU y modifica el precio si es necesario.
                            </p>
                        </div>
                        
                        <div class="flex gap-2 w-full sm:w-auto">
                            <Button @click="addManualVariant" label="Añadir variante manual" icon="pi pi-plus" size="small" severity="secondary" />
                        </div>
                    </div>

                    <!-- Buscador con explicación -->
                    <div class="mb-4 bg-white dark:bg-gray-800 p-2 rounded border dark:border-gray-700 flex flex-col sm:flex-row gap-3 items-center">
                        <IconField iconPosition="left" class="w-full sm:w-64 shrink-0">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="variantSearch" placeholder="Buscar variante o SKU..." class="w-full text-sm" />
                        </IconField>
                        <span class="text-xs text-gray-500">
                            <i class="pi pi-lightbulb text-yellow-500 mr-1"></i> Usa el buscador para encontrar rápidamente modelos específicos si tienes un catálogo muy grande.
                        </span>
                    </div>

                    <!-- Paginador Superior -->
                    <Paginator 
                        v-if="filteredVariants.length > rows" 
                        :rows="rows" 
                        :totalRecords="filteredVariants.length" 
                        :first="first" 
                        @page="onPage" 
                        :rowsPerPageOptions="[10, 25, 50]" 
                        class="mb-2 !bg-transparent" 
                    />

                    <!-- Lista Dinámica de Variantes -->
                    <div class="flex flex-col gap-3">
                        <div v-if="filteredVariants.length === 0" class="text-center py-6 text-blue-500 italic bg-white dark:bg-gray-800 rounded shadow-sm">
                            No hay combinaciones en la matriz.
                        </div>

                        <!-- Encabezados de Tabla (Solo visibles en Escritorio) -->
                        <div v-if="filteredVariants.length > 0" class="hidden md:flex gap-3 px-3 pb-2 border-b border-blue-200 dark:border-blue-800 text-sm font-semibold text-blue-800 dark:text-blue-200 mt-2">
                            <div class="w-4/12">Variante / Atributos</div>
                            <div class="w-3/12" v-tooltip.top="'Agrega un valor si esta variante es más cara (ej. 100)'">Mod. de Precio (+/-)</div>
                            <div class="w-2/12">Stock Inicial</div>
                            <div class="w-2/12">SKU</div>
                            <div class="w-1/12 text-right">Acción</div>
                        </div>

                        <!-- Filas (Rows) -->
                        <div v-for="(variant) in paginatedVariants" :key="variant._localId"
                            class="flex flex-col md:flex-row gap-3 items-start md:items-center bg-white dark:bg-gray-800 p-3 rounded shadow-sm border border-gray-200 dark:border-gray-700 hover:border-blue-300 transition-colors">
                            
                            <!-- ATRIBUTOS -->
                            <div class="w-full md:w-4/12 flex flex-wrap gap-1">
                                <template v-if="Object.keys(variant.attributes).length === 1 && Object.keys(variant.attributes)[0] === 'Detalle'">
                                    <InputText v-model="variant.attributes['Detalle']" placeholder="Ej: 128GB - Rojo" class="w-full text-sm" required />
                                </template>
                                <template v-else>
                                    <Tag v-for="(val, key) in variant.attributes" :key="key" :value="`${key}: ${val}`" severity="info" rounded class="!text-xs" />
                                </template>
                            </div>

                            <div class="w-full md:w-3/12">
                                <InputLabel :value="'Modificador de Precio'" class="text-xs !mb-1 md:hidden text-gray-500" />
                                <InputNumber v-model="variant.selling_price_modifier" mode="currency" currency="MXN" locale="es-MX"
                                    placeholder="Ej: 100.00" class="w-full text-sm" inputClass="!w-full" />
                            </div>

                            <div class="w-full md:w-2/12">
                                <InputLabel :value="'Stock Inicial'" class="text-xs !mb-1 md:hidden text-gray-500" />
                                <InputNumber v-model="variant.current_stock" placeholder="Stock" class="w-full text-sm" inputClass="!w-full" />
                            </div>
                            
                            <div class="w-full md:w-2/12">
                                <InputLabel :value="'SKU'" class="text-xs !mb-1 md:hidden text-gray-500" />
                                <InputText v-model="variant.sku" placeholder="SKU Variante" class="w-full text-sm" />
                            </div>

                            <div class="w-full md:w-1/12 flex justify-end">
                                <Button icon="pi pi-trash" severity="danger" text rounded @click="confirmRemoveVariant($event, form.variants_matrix.indexOf(variant))" v-tooltip.top="'Eliminar variante'" />
                            </div>
                        </div>
                    </div>

                    <!-- Paginador Inferior -->
                    <Paginator 
                        v-if="filteredVariants.length > rows && paginatedVariants.length > 5" 
                        :rows="rows" 
                        :totalRecords="filteredVariants.length" 
                        :first="first" 
                        @page="onPage" 
                        :rowsPerPageOptions="[10, 25, 50]" 
                        class="mt-3 !bg-transparent border-t border-blue-200 dark:border-blue-800 pt-2" 
                    />
                    
                    <InputError :message="form.errors.variants_matrix" class="mt-2" />
                </div>
            </template>
        </div>
        
        <!-- ConfirmPopup local y específico para no hacer conflicto con el de precios -->
        <ConfirmPopup group="inventory-variant-delete"></ConfirmPopup>
    </div>
</template>