<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    products: {
        type: Array,
        default: () => []
    },
});

const emit = defineEmits(['update:visible']);

// Helper para obtener variantes sin importar si vienen en snake_case o camelCase
const getVariants = (prod) => {
    return prod.product_attributes || prod.productAttributes || [];
};

// Detectar si es edición de un solo producto o masiva
const isSingleMode = computed(() => props.products.length === 1);
const singleProduct = computed(() => isSingleMode.value ? props.products[0] : null);

// Detectar si el producto único tiene variantes
const isVariantProduct = computed(() => {
    return isSingleMode.value && getVariants(singleProduct.value).length > 0;
});

const operation = ref('entry'); // 'entry' | 'exit'

// --- LISTAS DE MOTIVOS DINÁMICAS ---
const entryReasons = [
    'Compra / Reabastecimiento',
    'Devolución de cliente',
    'Ajuste de inventario (+)',
    'Inventario inicial',
    'Producción interna',
    'Otro'
];

const exitReasons = [
    'Venta externa',
    'Merma / Caducado',
    'Producto dañado',
    'Robo / Pérdida',
    'Uso interno',
    'Regalo / Cortesía',
    'Ajuste de inventario (-)',
    'Devolución a proveedor',
    'Otro'
];

// Computed que devuelve la lista correcta según la operación
const currentReasons = computed(() => operation.value === 'entry' ? entryReasons : exitReasons);

// Resetear el motivo seleccionado cuando cambia la operación
watch(operation, () => {
    form.reason = null;
});

const form = useForm({
    type: 'simple',
    operation: 'entry',
    reason: null,
    notes: '',
    quantity: 1,
    variants: [],
    products: [],
});

watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        operation.value = 'entry'; 
        
        if (isSingleMode.value) {
            // MODO: UN SOLO PRODUCTO
            form.type = isVariantProduct.value ? 'variant' : 'simple';
            form.quantity = 1;
            
            if (isVariantProduct.value) {
                form.variants = getVariants(singleProduct.value).map(v => ({
                    id: v.id,
                    attributes: v.attributes,
                    current_stock: v.current_stock || 0,
                    quantity: 0
                }));
            } else {
                form.variants = [];
            }
            form.products = [];
        } else {
            // MODO: MASIVO (BATCH) SOPORTA VARIANTES
            form.type = 'simple';
            form.products = props.products.map(p => {
                const variants = getVariants(p);
                const hasVariants = variants.length > 0;
                return {
                    id: p.id,
                    name: p.name,
                    is_variant: hasVariants,
                    current_stock: hasVariants ? 0 : (p.current_stock || 0),
                    quantity: 0,
                    variants: hasVariants ? variants.map(v => ({
                        id: v.id,
                        attributes: v.attributes,
                        current_stock: v.current_stock || 0,
                        quantity: 0
                    })) : []
                };
            });
            form.variants = [];
        }
        
        form.reason = null;
        form.notes = '';
    }
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    form.operation = operation.value;
    
    // Validación básica en frontend
    if (!form.reason) {
        form.setError('reason', 'El motivo es obligatorio.');
        return;
    }

    if (isSingleMode.value) {
        form.post(route('products.stock.store', singleProduct.value.id), {
            onSuccess: () => closeModal(),
            preserveScroll: true
        });
    } else {
        form.post(route('products.stock.batchStore'), {
            onSuccess: () => closeModal(),
            preserveScroll: true
        });
    }
};

const operationLabel = computed(() => operation.value === 'entry' ? 'Dar entrada' : 'Dar salida');
const operationColor = computed(() => operation.value === 'entry' ? 'success' : 'danger');
const iconClass = computed(() => operation.value === 'entry' ? 'pi pi-arrow-down' : 'pi pi-arrow-up');

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="isSingleMode ? `Gestión de stock: ${singleProduct?.name}` : 'Gestión masiva de stock'" :style="{ width: '45rem' }">
        
        <div class="mb-4 flex flex-col gap-4">
            <!-- Selector de Operación -->
            <div class="flex rounded-md overflow-hidden border border-gray-300 dark:border-gray-700">
                <button 
                    type="button" 
                    @click="operation = 'entry'"
                    class="flex-1 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-2"
                    :class="operation === 'entry' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50'"
                >
                    <i class="pi pi-plus-circle"></i> Entrada
                </button>
                <button 
                    type="button" 
                    @click="operation = 'exit'"
                    class="flex-1 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-2"
                    :class="operation === 'exit' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50'"
                >
                    <i class="pi pi-minus-circle"></i> Salida
                </button>
            </div>

            <!-- Selector de Motivo (AHORA SIEMPRE VISIBLE) -->
            <div class="animate-fade-in">
                <InputLabel :value="`Motivo de la ${operation === 'entry' ? 'entrada' : 'salida'} *`" />
                <Select 
                    v-model="form.reason" 
                    :options="currentReasons" 
                    placeholder="Selecciona un motivo" 
                    class="w-full mt-1"
                />
                <InputError :message="form.errors.reason" />
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            
            <!-- CASO 1: PRODUCTO SIMPLE ÚNICO -->
            <div v-if="isSingleMode && !isVariantProduct">
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border dark:border-gray-700 text-center">
                    <p class="text-sm text-gray-500 mb-1">Stock Actual Físico</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ singleProduct.current_stock || 0 }}</p>
                </div>

                <div>
                    <InputLabel for="quantity" :value="`Cantidad a ${operation === 'entry' ? 'agregar' : 'retirar'}`" />
                    <InputNumber fluid id="quantity" v-model="form.quantity" class="w-full mt-1" :min="1" showButtons buttonLayout="horizontal" inputClass="text-center font-bold">
                        <template #incrementbuttonicon>
                            <span class="pi pi-plus" />
                        </template>
                        <template #decrementbuttonicon>
                            <span class="pi pi-minus" />
                        </template>
                    </InputNumber>
                    <InputError :message="form.errors.quantity" />
                </div>
            </div>

            <!-- CASO 2: PRODUCTO CON VARIANTES ÚNICO -->
            <div v-if="isSingleMode && isVariantProduct">
                <Message severity="info" :closable="false" class="mb-2">Ingresa la cantidad para cada variante afectada.</Message>
                <div class="space-y-2 max-h-[50vh] overflow-y-auto pr-1 border rounded-lg p-2 dark:border-gray-700">
                    <div v-for="(variant, index) in form.variants" :key="variant.id" class="flex items-center justify-between p-2 hover:bg-gray-50 dark:hover:bg-gray-800 rounded">
                         <div class="text-sm">
                            <div class="font-semibold text-gray-700 dark:text-gray-200">
                                <span v-for="(value, key) in variant.attributes" :key="key" class="mr-2">
                                    {{ value }}
                                </span>
                            </div>
                            <span class="text-xs text-gray-500">Stock actual: {{ variant.current_stock }}</span>
                         </div>
                        <InputNumber fluid v-model="variant.quantity" :min="0" placeholder="0" class="!w-24" showButtons inputClass="text-center" />
                    </div>
                </div>
                <InputError :message="form.errors.variants" />
            </div>

            <!-- CASO 3: MODO MASIVO (BATCH) -->
            <div v-if="!isSingleMode">
                 <Message :severity="operation === 'entry' ? 'success' : 'warn'" :closable="false" class="mb-2">
                    Ingresa las cantidades para los productos y variantes que deseas afectar.
                 </Message>
                 
                 <div class="space-y-3 max-h-[50vh] overflow-y-auto pr-1">
                    <div v-for="(prod, index) in form.products" :key="prod.id" class="p-3 border rounded-lg dark:border-gray-700 flex flex-col gap-2">
                        
                        <!-- Header del Producto en la lista -->
                        <div class="flex items-center justify-between">
                            <div class="overflow-hidden">
                                <p class="font-bold text-sm truncate">{{ prod.name }}</p>
                                <p v-if="!prod.is_variant" class="text-xs text-gray-500">Stock actual: {{ prod.current_stock }}</p>
                                <p v-else class="text-xs text-indigo-500 font-medium">Contiene variantes</p>
                            </div>
                            <InputNumber v-if="!prod.is_variant" fluid v-model="prod.quantity" :min="0" placeholder="0" class="!w-28" showButtons inputClass="text-center" />
                        </div>

                        <!-- Lista de Variantes Anidadas -->
                        <div v-if="prod.is_variant" class="pl-3 pr-1 mt-1 space-y-2 border-l-2 border-gray-100 dark:border-gray-800">
                            <div v-for="(variant, vIndex) in prod.variants" :key="variant.id" class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 p-2 rounded">
                                <div class="text-xs">
                                    <div class="font-semibold text-gray-700 dark:text-gray-300">
                                        <span v-for="(value, key) in variant.attributes" :key="key" class="mr-1">
                                            {{ value }}
                                        </span>
                                    </div>
                                    <span class="text-gray-500">Stock actual: {{ variant.current_stock }}</span>
                                </div>
                                <InputNumber fluid v-model="variant.quantity" :min="0" placeholder="0" class="!w-24" showButtons inputClass="text-center text-sm" />
                            </div>
                        </div>

                    </div>
                 </div>
                 <InputError :message="form.errors.products" />
            </div>

            <div class="flex justify-end gap-2 mt-6 pt-4 border-t dark:border-gray-700">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" :label="operationLabel" :icon="iconClass" :severity="operationColor" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>

<style scoped>
.animate-fade-in {
    animation: fadeIn 0.3s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>