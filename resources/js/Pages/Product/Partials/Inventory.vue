<script setup>
import { ref, computed, watch, onMounted } from 'vue';
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

// --- LÓGICA DE INICIALIZACIÓN (MÚLTIPLE PROPÓSITO) ---
onMounted(() => {
    if (props.form.variants_matrix && props.form.variants_matrix.length > 0) {
        // 1. Inicializar precios finales y campos faltantes
        props.form.variants_matrix.forEach(v => {
            if (v.final_price === undefined) {
                v.final_price = (props.form.selling_price || 0) + Number(v.selling_price_modifier || 0);
            }
            if (v.location === undefined) v.location = '';
            if (v.min_stock === undefined) v.min_stock = null;
            if (v.max_stock === undefined) v.max_stock = null;
        });

        // 2. MODO EDICIÓN: Autocompletar los selectores basados en las variantes existentes
        const initialSelected = {};
        props.form.variants_matrix.forEach(v => {
            // Ignoramos las variantes manuales que solo tienen "Detalle"
            if (v.attributes && !(Object.keys(v.attributes).length === 1 && v.attributes['Detalle'] !== undefined)) {
                Object.entries(v.attributes).forEach(([key, val]) => {
                    if (!initialSelected[key]) initialSelected[key] = new Set();
                    initialSelected[key].add(val);
                });
            }
        });
        
        // Asignamos los valores reconstruidos a los MultiSelects
        Object.keys(initialSelected).forEach(key => {
            selectedAttributeValues.value[key] = Array.from(initialSelected[key]);
        });
    }
});

// Auto-actualizar Precio Final
watch(() => props.form.selling_price, (newPrice) => {
    if (props.form.variants_matrix) {
        props.form.variants_matrix.forEach(v => {
            v.final_price = (newPrice || 0) + Number(v.selling_price_modifier || 0);
        });
    }
});

const updateModifier = (variant, newFinalPrice) => {
    variant.selling_price_modifier = (newFinalPrice || 0) - (props.form.selling_price || 0);
};

// --- LÓGICA DE ATRIBUTOS POR CATEGORÍA ---
const selectedAttributeValues = ref({});

const categoryAttributes = computed(() => {
    if (!props.form.category_id) return [];
    return props.attributeDefinitions.filter(attr => attr.category_id == props.form.category_id);
});

const getOptions = (attr) => {
    if (!attr || !attr.options) return [];
    return attr.options.map(opt => opt.value);
};

// Limpia solo si el cambio es real (no en la carga inicial)
watch(() => props.form.category_id, (newVal, oldVal) => {
    if (oldVal !== null) {
        selectedAttributeValues.value = {};
    }
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
            location: '',
            selling_price_modifier: 0,
            final_price: props.form.selling_price || 0,
            current_stock: 0,
            min_stock: null, // Inicializado como null
            max_stock: null, // Inicializado como null
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
        location: '',
        selling_price_modifier: 0,
        final_price: props.form.selling_price || 0,
        current_stock: 0,
        min_stock: null,
        max_stock: null,
    });
};

const confirmRemoveVariant = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        group: 'inventory-variant-delete',
        message: '¿Estás seguro de eliminar esta variante de la lista?',
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
                        <label for="type_simple" class="ml-2 cursor-pointer font-medium text-gray-700 dark:text-gray-300">Producto simple</label>
                    </div>
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_variant" value="variant" />
                        <label for="type_variant" class="ml-2 cursor-pointer font-medium text-gray-700 dark:text-gray-300">Producto con variantes</label>
                    </div>
                </div>
            </div>

            <!-- PRODUCTO SIMPLE -->
            <template v-if="form.product_type === 'simple'">
                <div>
                    <InputLabel for="current_stock" value="Stock actual" />
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
                <div class="col-span-full bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-lg p-5 mb-2">
                    <div class="flex justify-between items-center mb-1">
                        <h5 class="font-bold text-gray-800 dark:text-gray-200 m-0">Generador automático de variantes</h5>
                        <Button @click="$emit('open-attributes')" :disabled="!form.category_id" label="Configurar atributos" icon="pi pi-cog" size="small" outlined severity="secondary" />
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5 max-w-3xl">
                        Selecciona las características (ej. Colores, Tallas) y haz clic en "Generar combinaciones". El sistema creará automáticamente una matriz con todas las opciones posibles de tu producto.
                    </p>

                    <div v-if="!form.category_id" class="text-sm text-gray-500 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 rounded-lg flex items-center gap-3">
                        <i class="pi pi-info-circle text-gray-400 text-xl"></i>
                        <span>Para comenzar, selecciona primero una <strong>Categoría</strong> en la sección "Información general".</span>
                    </div>

                    <div v-else-if="categoryAttributes.length === 0" class="text-sm text-gray-500 italic p-2">
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
                            <Button @click="generateMatrix" label="Generar combinaciones" icon="pi pi-sync" severity="secondary" :disabled="Object.keys(selectedAttributeValues).length === 0" />
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: TABLA DE VARIANTES GENERADAS -->
                <div class="col-span-full border border-gray-200 dark:border-gray-700 rounded-lg p-5 mt-2">
                    
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                        <div>
                            <h5 class="font-bold text-gray-800 dark:text-gray-200 m-0">Matriz de variantes ({{ filteredVariants.length }})</h5>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0 mt-1">
                                Asigna inventario inicial (y sus límites), código SKU, ubicación y precio final de venta para cada opción.
                            </p>
                        </div>
                        
                        <div class="flex gap-2 w-full sm:w-auto">
                            <Button @click="addManualVariant" label="Añadir variante manual" icon="pi pi-plus" size="small" outlined severity="secondary" />
                        </div>
                    </div>

                    <!-- Buscador con explicación -->
                    <div class="mb-4 bg-gray-50 dark:bg-gray-800/50 p-3 rounded border border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row gap-3 items-center">
                        <IconField iconPosition="left" class="w-full sm:w-72 shrink-0">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="variantSearch" placeholder="Buscar variante o SKU..." class="w-full text-sm bg-white dark:bg-gray-800" />
                        </IconField>
                        <span class="text-xs text-gray-500 leading-tight">
                            <i class="pi pi-filter mr-1"></i> Utiliza esta barra para encontrar rápidamente modelos específicos entre tu matriz.
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
                        class="mb-2 !bg-transparent !p-0" 
                    />

                    <!-- Lista Dinámica de Variantes -->
                    <div class="flex flex-col gap-2">
                        <div v-if="filteredVariants.length === 0" class="text-center py-8 text-gray-400 italic bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded">
                            No hay combinaciones registradas o que coincidan con la búsqueda.
                        </div>

                        <!-- Encabezados de Tabla (Solo visibles en Escritorio) -->
                        <div v-if="filteredVariants.length > 0" class="hidden md:flex gap-3 px-4 pb-2 border-b border-gray-200 dark:border-gray-700 text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mt-2">
                            <div class="w-2/12">Variante / Atributos</div>
                            <div class="w-2/12">Precio Final</div>
                            <div class="w-3/12">Inventario (Act / Mín / Máx)</div>
                            <div class="w-2/12">SKU</div>
                            <div class="w-2/12">Ubicación</div>
                            <div class="w-1/12 text-right">Acciones</div>
                        </div>

                        <!-- Filas (Rows) -->
                        <div v-for="(variant) in paginatedVariants" :key="variant._localId"
                            class="flex flex-col md:flex-row gap-3 items-start md:items-center bg-white dark:bg-gray-800 p-4 md:p-3 rounded shadow-sm border border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                            
                            <!-- ATRIBUTOS -->
                            <div class="w-full md:w-2/12 flex flex-wrap gap-1">
                                <template v-if="Object.keys(variant.attributes).length === 1 && Object.keys(variant.attributes)[0] === 'Detalle'">
                                    <InputText v-model="variant.attributes['Detalle']" placeholder="Ej: 128GB - Rojo" class="w-full text-sm" required />
                                </template>
                                <template v-else>
                                    <Tag v-for="(val, key) in variant.attributes" :key="key" :value="`${key}: ${val}`" severity="secondary" rounded class="!text-xs" />
                                </template>
                            </div>

                            <div class="w-full md:w-2/12">
                                <InputLabel :value="'Precio Final'" class="text-xs !mb-1 md:hidden text-gray-500 font-semibold" />
                                <InputNumber v-model="variant.final_price" @update:modelValue="updateModifier(variant, $event)" mode="currency" currency="MXN" locale="es-MX"
                                    placeholder="$0.00" class="w-full text-sm" inputClass="!w-full" />
                            </div>

                            <!-- INVENTARIO AGRUPADO (Actual / Mín / Máx) -->
                            <div class="w-full md:w-3/12 flex gap-2">
                                <div class="w-1/3" v-tooltip.top="'Stock Actual'">
                                    <InputLabel :value="'Actual'" class="text-xs !mb-1 md:hidden text-gray-500 font-semibold" />
                                    <InputNumber v-model="variant.current_stock" placeholder="Act" class="w-full text-sm" inputClass="!w-full" />
                                </div>
                                <div class="w-1/3" v-tooltip.top="'Stock Mínimo'">
                                    <InputLabel :value="'Mínimo'" class="text-xs !mb-1 md:hidden text-gray-500 font-semibold" />
                                    <InputNumber v-model="variant.min_stock" placeholder="Mín" class="w-full text-sm" inputClass="!w-full" />
                                </div>
                                <div class="w-1/3" v-tooltip.top="'Stock Máximo'">
                                    <InputLabel :value="'Máximo'" class="text-xs !mb-1 md:hidden text-gray-500 font-semibold" />
                                    <InputNumber v-model="variant.max_stock" placeholder="Máx" class="w-full text-sm" inputClass="!w-full" />
                                </div>
                            </div>
                            
                            <div class="w-full md:w-2/12">
                                <InputLabel :value="'SKU'" class="text-xs !mb-1 md:hidden text-gray-500 font-semibold" />
                                <InputText v-model="variant.sku" placeholder="Ej: SKU-001" class="w-full text-sm" />
                            </div>
                            
                            <div class="w-full md:w-2/12">
                                <InputLabel :value="'Ubicación'" class="text-xs !mb-1 md:hidden text-gray-500 font-semibold" />
                                <InputText v-model="variant.location" placeholder="Ej: A-3" class="w-full text-sm" />
                            </div>

                            <div class="w-full md:w-1/12 flex justify-end">
                                <Button icon="pi pi-trash" severity="secondary" text rounded @click="confirmRemoveVariant($event, form.variants_matrix.indexOf(variant))" v-tooltip.top="'Eliminar variante'" />
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
                        class="mt-4 !bg-transparent border-t border-gray-200 dark:border-gray-700 pt-2 !p-0" 
                    />
                    
                    <InputError :message="form.errors.variants_matrix" class="mt-2" />
                </div>
            </template>
        </div>
        
        <!-- ConfirmPopup local para eliminar variantes de la matriz -->
        <ConfirmPopup group="inventory-variant-delete"></ConfirmPopup>
    </div>
</template>