<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import axios from 'axios';

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


// --- NUEVA LÓGICA PARA PRODUCTOS COMPUESTOS (KITS) ---
const searchResults = ref([]);
const selectedItemSearch = ref(null);
const isSearching = ref(false); // Estado de carga para dar feedback visual

const searchProductsForKit = async (event) => {
    const query = event.query;
    
    // Mejoramos la UX permitiendo buscar desde el primer carácter
    if (!query || query.trim().length === 0) {
        searchResults.value = [];
        return;
    }
    
    isSearching.value = true;
    try {
        // Utilizamos la ruta existente que busca productos para el POS
        const response = await axios.get(route('transactions.search-products'), { params: { query } });
        
        const flatResults = [];
        
        response.data.forEach(p => {
            // Si tiene variantes, agregamos cada variante como opción individual
            if (p.variants && p.variants.length > 0) {
                p.variants.forEach(v => {
                    flatResults.push({
                        id: v.id,
                        product_id: p.id,
                        type: 'App\\Models\\ProductAttribute', // Tipo polimórfico exacto para BD
                        name: `${p.name} - ${Object.values(v.attributes).join(' ')}`,
                        sku: v.sku_suffix || p.sku,
                        price: (p.selling_price || 0) + (v.selling_price_modifier || 0),
                        stock: v.current_stock
                    });
                });
            } else {
                // Producto simple
                flatResults.push({
                    id: p.id,
                    type: 'App\\Models\\Product', // Tipo polimórfico exacto para BD
                    name: p.name,
                    sku: p.sku,
                    price: p.selling_price,
                    stock: p.current_stock
                });
            }
        });
        
        searchResults.value = flatResults;
    } catch (error) {
        console.error("Error buscando productos:", error);
    } finally {
        isSearching.value = false;
    }
};

const onComponentSelect = (event) => {
    const item = event.value;
    
    // Verificar si ya existe en la lista para solo sumar cantidad
    const exists = props.form.composite_items.find(i => i.id === item.id && i.type === item.type);
    
    if (exists) {
        exists.quantity += 1;
    } else {
        props.form.composite_items.push({
            id: item.id,
            type: item.type,
            name: item.name,
            sku: item.sku,
            price: item.price,
            quantity: 1
        });
    }
    
    // Limpiamos la barra de búsqueda tras seleccionar
    setTimeout(() => {
        selectedItemSearch.value = null;
    }, 10);
};

const removeCompositeItem = (index) => {
    props.form.composite_items.splice(index, 1);
};
</script>

<template>
    <div id="inventory" class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] scroll-mt-24">
        <h2 class="text-lg font-semibold mb-6 text-gray-900 dark:text-white m-0">
            Inventario y Variantes
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="col-span-full flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de producto *</label>
                <div class="flex flex-wrap gap-4 mt-2">
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_simple" value="simple" />
                        <label for="type_simple" class="ml-2 cursor-pointer font-medium text-gray-700 dark:text-gray-300">Producto simple</label>
                    </div>
                    <!-- NUEVA OPCIÓN: Granel -->
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_bulk" value="bulk" />
                        <label for="type_bulk" class="ml-2 cursor-pointer font-medium text-gray-700 dark:text-gray-300">Venta a granel</label>
                    </div>
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_variant" value="variant" />
                        <label for="type_variant" class="ml-2 cursor-pointer font-medium text-gray-700 dark:text-gray-300">Con variantes</label>
                    </div>
                    <div class="flex items-center">
                        <RadioButton v-model="form.product_type" inputId="type_composite" value="composite" />
                        <label for="type_composite" class="ml-2 cursor-pointer font-medium text-gray-700 dark:text-gray-300">Kit/Combo</label>
                    </div>
                </div>
            </div>

            <!-- PRODUCTO COMPUESTO (KIT) -->
            <template v-if="form.product_type === 'composite'">
                <div class="col-span-full bg-blue-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-blue-100 dark:border-[#3a3a3a] mb-2">
                    <h3 class="font-bold text-blue-800 dark:text-blue-200 m-0 text-base">Configuración de Kit / Combo</h3>
                    <p class="text-sm text-blue-600 dark:text-blue-300 mt-1 mb-0">
                        Los productos compuestos no tienen un inventario físico directo. Su disponibilidad se calculará basada en el stock de los productos que lo conforman. Al venderse, se descontará automáticamente el inventario de sus componentes.
                    </p>
                </div>

                <div class="col-span-full flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Buscar productos para agregar al kit</label>
                    <AutoComplete 
                        v-model="selectedItemSearch" 
                        :suggestions="searchResults" 
                        @complete="searchProductsForKit" 
                        @item-select="onComponentSelect"
                        optionLabel="name" 
                        placeholder="Escribe el nombre o SKU de un producto..." 
                        class="w-full" 
                        inputClass="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]"
                        forceSelection
                        :delay="300"
                        :minLength="1"
                        :loading="isSearching"
                    >
                        <template #option="slotProps">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium">{{ slotProps.option.name }}</div>
                                    <div class="text-xs text-gray-500">SKU: {{ slotProps.option.sku || 'N/A' }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-green-600">${{ slotProps.option.price }}</div>
                                    <div class="text-xs text-gray-500">Stock físico: {{ slotProps.option.stock }}</div>
                                </div>
                            </div>
                        </template>
                    </AutoComplete>
                </div>

                <div class="col-span-full mt-2">
                    <div v-if="form.composite_items.length === 0" class="text-center p-6 border border-dashed border-gray-300 dark:border-[#3a3a3a] rounded-2xl text-gray-500">
                        <i class="pi pi-box text-3xl mb-2 text-gray-400"></i>
                        <p>Busca y selecciona productos arriba para armar tu kit.</p>
                    </div>
                    <div v-else class="border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 dark:bg-[#1a1a1a] text-gray-600 dark:text-gray-300">
                                <tr>
                                    <th class="px-4 py-3 font-semibold text-xs tracking-wider uppercase">Producto / Variante componente</th>
                                    <th class="px-4 py-3 font-semibold text-xs tracking-wider uppercase w-40 text-center">Cantidad a descontar por venta</th>
                                    <th class="px-4 py-3 w-16"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, index) in form.composite_items" :key="index" class="border-t border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#1a1a1a]">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800 dark:text-gray-200">{{ item.name }}</div>
                                        <div class="text-xs text-gray-500">SKU: {{ item.sku || 'N/A' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <InputNumber v-model="item.quantity" :min="0.01" :maxFractionDigits="2" class="w-full" inputClass="!p-2 text-center !rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]" showButtons />
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <Button icon="pi pi-trash" severity="danger" text rounded @click="removeCompositeItem(index)" v-tooltip.top="'Quitar del kit'" class="!rounded-full" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <Message v-if="form.errors.composite_items" severity="error" variant="simple" size="small">{{ form.errors.composite_items }}</Message>
                </div>
            </template>


            <!-- PRODUCTO SIMPLE O GRANEL (Comparten campos pero Granel acepta decimales) -->
            <template v-else-if="['simple', 'bulk'].includes(form.product_type)">
                
                <div v-if="form.product_type === 'bulk'" class="col-span-full bg-orange-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-orange-100 dark:border-[#3a3a3a] mb-2">
                    <h3 class="font-bold text-orange-800 dark:text-orange-200 m-0 text-base">Venta a granel (Fraccionada)</h3>
                    <p class="text-sm text-orange-600 dark:text-orange-300 mt-1 mb-0">
                        Este producto permitirá ventas e inventarios con cantidades decimales (Ej. 1.5 Kg, 0.25 Lts).
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="current_stock" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Stock actual</label>
                    <InputNumber v-model="form.current_stock" id="current_stock" class="w-full" 
                        :minFractionDigits="0" 
                        :maxFractionDigits="form.product_type === 'bulk' ? 3 : 0" 
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }"
                    />
                    <Message v-if="form.errors.current_stock" severity="error" variant="simple" size="small">{{ form.errors.current_stock }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="measure_unit" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Unidad de medida *</label>
                    <Select v-model="form.measure_unit" id="measure_unit" :options="['Pza', 'Kg', 'Grs', 'Lts', 'Mts', 'Cm']" class="w-full" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                    <Message v-if="form.errors.measure_unit" severity="error" variant="simple" size="small">{{ form.errors.measure_unit }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="min_stock" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Stock mínimo (Opcional)</label>
                    <InputNumber v-model="form.min_stock" id="min_stock" class="w-full" 
                        :minFractionDigits="0" 
                        :maxFractionDigits="form.product_type === 'bulk' ? 3 : 0" 
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="max_stock" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Stock máximo (Opcional)</label>
                    <InputNumber v-model="form.max_stock" id="max_stock" class="w-full" 
                        :minFractionDigits="0" 
                        :maxFractionDigits="form.product_type === 'bulk' ? 3 : 0" 
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }"
                    />
                </div>
            </template>

            <!-- PRODUCTO CON VARIANTES -->
            <template v-else-if="form.product_type === 'variant'">
                
                <!-- SECCIÓN: GENERADOR DE ATRIBUTOS -->
                <div class="col-span-full bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-5 mb-2">
                    <div class="flex justify-between items-center mb-1">
                        <h5 class="font-bold text-gray-800 dark:text-gray-200 m-0">Generador automático de variantes</h5>
                        <Button @click="$emit('open-attributes')" :disabled="!form.category_id" label="Configurar atributos" icon="pi pi-cog" size="small" outlined severity="secondary" class="!rounded-full" />
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5 max-w-3xl">
                        Selecciona las características (ej. Colores, Tallas) y haz clic en "Generar combinaciones". El sistema creará automáticamente una matriz con todas las opciones posibles de tu producto.
                    </p>

                    <div v-if="!form.category_id" class="text-sm text-gray-500 bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-4 rounded-2xl flex items-center gap-3">
                        <i class="pi pi-info-circle text-gray-400 text-xl"></i>
                        <span>Para comenzar, selecciona primero una <strong>Categoría</strong> en la sección "Información general".</span>
                    </div>

                    <div v-else-if="categoryAttributes.length === 0" class="text-sm text-gray-500 italic p-2">
                        Esta categoría no tiene atributos configurados. Haz clic en "Configurar Atributos" para crear los tuyos.
                    </div>

                    <div v-else class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="attr in categoryAttributes" :key="attr.id" class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ attr.name }}</label>
                                <MultiSelect 
                                    v-model="selectedAttributeValues[attr.name]" 
                                    :options="getOptions(attr)" 
                                    :placeholder="`Seleccionar ${attr.name.toLowerCase()}`" 
                                    display="chip" 
                                    class="w-full"
                                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' } }"
                                />
                            </div>
                        </div>
                        
                        <div class="flex justify-end pt-2">
                            <Button @click="generateMatrix" label="Generar combinaciones" icon="pi pi-sync" severity="secondary" :disabled="Object.keys(selectedAttributeValues).length === 0" class="!rounded-full" />
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: TABLA DE VARIANTES GENERADAS -->
                <div class="col-span-full border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-5 mt-2">
                    
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                        <div>
                            <h5 class="font-bold text-gray-800 dark:text-gray-200 m-0">Matriz de variantes ({{ filteredVariants.length }})</h5>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0 mt-1">
                                Asigna inventario inicial (y sus límites), código SKU, ubicación y precio final de venta para cada opción.
                            </p>
                        </div>
                        
                        <div class="flex gap-2 w-full sm:w-auto">
                            <Button @click="addManualVariant" label="Añadir variante manual" icon="pi pi-plus" size="small" outlined severity="secondary" class="!rounded-full" />
                        </div>
                    </div>

                    <!-- Buscador con explicación -->
                    <div class="mb-4 bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row gap-3 items-center">
                        <IconField iconPosition="left" class="w-full sm:w-72 shrink-0">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="variantSearch" placeholder="Buscar variante o SKU..." class="w-full text-sm" :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
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
                        <div v-if="filteredVariants.length === 0" class="text-center py-8 text-gray-400 italic bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl">
                            No hay combinaciones registradas o que coincidan con la búsqueda.
                        </div>

                        <!-- Encabezados de Tabla (Solo visibles en Escritorio) -->
                        <div v-if="filteredVariants.length > 0" class="hidden md:flex gap-3 px-4 pb-2 border-b border-gray-100 dark:border-[#3a3a3a] text-[10px] uppercase tracking-widest font-bold text-gray-500 mt-2">
                            <div class="w-2/12">Variante / Atributos</div>
                            <div class="w-2/12">Precio Final</div>
                            <div class="w-3/12">Inventario (Act / Mín / Máx)</div>
                            <div class="w-2/12">SKU</div>
                            <div class="w-2/12">Ubicación</div>
                            <div class="w-1/12 text-right">Acciones</div>
                        </div>

                        <!-- Filas (Rows) -->
                        <div v-for="(variant) in paginatedVariants" :key="variant._localId"
                            class="flex flex-col md:flex-row gap-3 items-start md:items-center bg-white dark:bg-[#1a1a1a] p-4 md:p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] transition-colors">
                            
                            <!-- ATRIBUTOS -->
                            <div class="w-full md:w-2/12 flex flex-wrap gap-1">
                                <template v-if="Object.keys(variant.attributes).length === 1 && Object.keys(variant.attributes)[0] === 'Detalle'">
                                    <InputText v-model="variant.attributes['Detalle']" placeholder="Ej: 128GB - Rojo" class="w-full text-sm" required :pt="{ root: { class: '!rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                                </template>
                                <template v-else>
                                    <Tag v-for="(val, key) in variant.attributes" :key="key" :value="`${key}: ${val}`" severity="secondary" class="!text-xs !rounded-full" />
                                </template>
                            </div>

                            <div class="w-full md:w-2/12 flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 md:hidden">Precio Final</label>
                                <InputNumber v-model="variant.final_price" @update:modelValue="updateModifier(variant, $event)" mode="currency" currency="MXN" locale="es-MX"
                                    placeholder="$0.00" class="w-full text-sm" inputClass="!w-full !rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]" />
                            </div>

                            <!-- INVENTARIO AGRUPADO (Actual / Mín / Máx) -->
                            <div class="w-full md:w-3/12 flex gap-2">
                                <div class="w-1/3 flex flex-col gap-1.5" v-tooltip.top="'Stock Actual'">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 md:hidden">Actual</label>
                                    <InputNumber v-model="variant.current_stock" placeholder="Act" class="w-full text-sm" inputClass="!w-full !rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]" />
                                </div>
                                <div class="w-1/3 flex flex-col gap-1.5" v-tooltip.top="'Stock Mínimo'">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 md:hidden">Mínimo</label>
                                    <InputNumber v-model="variant.min_stock" placeholder="Mín" class="w-full text-sm" inputClass="!w-full !rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]" />
                                </div>
                                <div class="w-1/3 flex flex-col gap-1.5" v-tooltip.top="'Stock Máximo'">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 md:hidden">Máximo</label>
                                    <InputNumber v-model="variant.max_stock" placeholder="Máx" class="w-full text-sm" inputClass="!w-full !rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]" />
                                </div>
                            </div>
                            
                            <div class="w-full md:w-2/12 flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 md:hidden">SKU</label>
                                <InputText v-model="variant.sku" placeholder="Ej: SKU-001" class="w-full text-sm" :pt="{ root: { class: '!rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                            </div>
                            
                            <div class="w-full md:w-2/12 flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 md:hidden">Ubicación</label>
                                <InputText v-model="variant.location" placeholder="Ej: A-3" class="w-full text-sm" :pt="{ root: { class: '!rounded-xl !bg-gray-50 dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                            </div>

                            <div class="w-full md:w-1/12 flex justify-end">
                                <Button icon="pi pi-trash" severity="secondary" text rounded @click="confirmRemoveVariant($event, form.variants_matrix.indexOf(variant))" v-tooltip.top="'Eliminar variante'" class="!rounded-full" />
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
                        class="mt-4 !bg-transparent border-t border-gray-100 dark:border-[#3a3a3a] pt-2 !p-0" 
                    />
                    
                    <Message v-if="form.errors.variants_matrix" severity="error" variant="simple" size="small">{{ form.errors.variants_matrix }}</Message>
                </div>
            </template>
        </div>
        
        <!-- ConfirmPopup local para eliminar variantes de la matriz -->
        <ConfirmPopup group="inventory-variant-delete"></ConfirmPopup>
    </div>
</template>