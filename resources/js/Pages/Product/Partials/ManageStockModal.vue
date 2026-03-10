<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
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

// Inyectamos propiedades globales de Inertia para bancos y cajas
const page = usePage();
const activeSession = computed(() => page.props.activeSession || null);
const userBankAccounts = computed(() => page.props.userBankAccounts || []);

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
    'Ajuste de inventario (-)',
    'Otro'
];

const paymentMethods = [
    { label: 'Efectivo', value: 'efectivo' },
    { label: 'Tarjeta de Crédito / Débito', value: 'tarjeta' },
    { label: 'Transferencia', value: 'transferencia' },
    { label: 'Otro', value: 'otro' }
];

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

// --- INICIALIZACIÓN DEL FORMULARIO ---
const form = useForm({
    type: 'simple',
    operation: 'entry',
    reason: 'Compra / Reabastecimiento',
    
    // Para modo individual
    quantity: null,
    variants: [],
    
    // Para modo masivo
    products: [],

    // --- Campos de Gasto ---
    register_expense: false,
    expense_amount_type: 'calculated',
    expense_amount: null,
    payment_method: 'efectivo',
    take_from_cash_register: false,
    bank_account_id: null,
    cash_register_session_id: null,
});

const operationLabel = computed(() => operation.value === 'entry' ? 'Registrar entrada' : 'Registrar salida');
const operationColor = computed(() => operation.value === 'entry' ? 'success' : 'danger');
const iconClass = computed(() => operation.value === 'entry' ? 'pi pi-arrow-down-left' : 'pi pi-arrow-up-right');

// Calcular el total esperado si se usa el 'cost_price' (Funciona para individual y masivo)
const calculatedExpenseTotal = computed(() => {
    if (isSingleMode.value && singleProduct.value) {
        const costPrice = parseFloat(singleProduct.value.cost_price) || 0;
        if (form.type === 'simple') {
            return (form.quantity || 0) * costPrice;
        } else {
            return form.variants.reduce((sum, v) => sum + ((v.quantity || 0) * costPrice), 0);
        }
    } else {
        // Cálculo Modo Masivo
        return form.products.reduce((total, p) => {
            const costPrice = parseFloat(p.cost_price) || 0;
            if (p.type === 'simple') {
                return total + ((p.quantity || 0) * costPrice);
            } else {
                const variantTotal = p.variants.reduce((vSum, v) => vSum + ((v.quantity || 0) * costPrice), 0);
                return total + variantTotal;
            }
        }, 0);
    }
});

// Sincronizar automáticamente el ID de la sesión de caja
watch([() => form.take_from_cash_register, activeSession], () => {
    if (form.take_from_cash_register && activeSession.value) {
        form.cash_register_session_id = activeSession.value.id;
    } else {
        form.cash_register_session_id = null;
    }
});

// Apagar el gasto si el motivo cambia a otra cosa diferente a "Compra"
watch(() => form.reason, (newReason) => {
    if (newReason !== 'Compra / Reabastecimiento') {
        form.register_expense = false;
    }
});

watch(() => props.visible, (newVal) => {
    if (newVal) {
        form.reset();
        operation.value = 'entry';
        form.operation = 'entry';
        form.reason = entryReasons[0];

        if (isSingleMode.value) {
            form.products = [];
            if (isVariantProduct.value) {
                form.type = 'variant';
                form.variants = getVariants(singleProduct.value).map(v => ({
                    id: v.id,
                    attributes: v.attributes,
                    quantity: 0,
                    current_stock: v.current_stock
                }));
            } else {
                form.type = 'simple';
            }
        } else {
            // MODO MASIVO: Inicializar lista de productos
            form.products = props.products.map(p => {
                const hasVariants = getVariants(p).length > 0;
                return {
                    id: p.id,
                    name: p.name,
                    cost_price: p.cost_price,
                    type: hasVariants ? 'variant' : 'simple',
                    quantity: hasVariants ? null : 0,
                    current_stock: p.current_stock || 0,
                    variants: hasVariants ? getVariants(p).map(v => ({
                        id: v.id,
                        attributes: v.attributes,
                        quantity: 0,
                        current_stock: v.current_stock || 0
                    })) : []
                };
            });
        }
    }
});

const setOperation = (type) => {
    operation.value = type;
    form.operation = type;
    form.reason = type === 'entry' ? entryReasons[0] : exitReasons[0];
};

const closeModal = () => emit('update:visible', false);

const submit = () => {
    if (isSingleMode.value && singleProduct.value) {
        form.post(route('products.stock.store', singleProduct.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal()
        });
    } else {
        // MODO MASIVO
        form.post(route('products.stock.batchStore'), {
            preserveScroll: true,
            onSuccess: () => closeModal()
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal :header="isSingleMode ? 'Gestión de Stock' : 'Actualización Masiva'" :style="{ width: '45rem' }" :breakpoints="{ '1199px': '75vw', '575px': '90vw' }">
        <form @submit.prevent="submit" class="mt-2">
            
            <div class="flex justify-center mb-6">
                <SelectButton v-model="operation" :options="[{label: 'Dar Entrada', value: 'entry'}, {label: 'Dar Salida', value: 'exit'}]" optionLabel="label" optionValue="value" @change="setOperation($event.value)" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                 <div>
                     <InputLabel value="Motivo / Concepto *" />
                     <Select v-model="form.reason" :options="operation === 'entry' ? entryReasons : exitReasons" class="w-full mt-1" />
                     <InputError :message="form.errors.reason" />
                 </div>

                 <!-- Si es un solo producto y NO tiene variantes -->
                 <div v-if="isSingleMode && !isVariantProduct">
                     <InputLabel :value="operation === 'entry' ? 'Cantidad a ingresar *' : 'Cantidad a descontar *'" />
                     <InputNumber fluid v-model="form.quantity" :min="1" class="w-full mt-1" showButtons />
                     <InputError :message="form.errors.quantity" />
                 </div>
            </div>

            <!-- --- MODO INDIVIDUAL: Variantes --- -->
            <div v-if="isSingleMode && isVariantProduct" class="mt-6">
                 <div class="border dark:border-gray-700 rounded-lg overflow-hidden">
                    <div class="bg-gray-50 dark:bg-gray-800 p-3 border-b dark:border-gray-700 font-semibold text-sm flex justify-between">
                        <span>Variantes disponibles</span>
                        <span class="text-primary-600 dark:text-primary-400">{{ operation === 'entry' ? 'Ingreso' : 'Descuento' }}</span>
                    </div>
                    <div class="p-2 space-y-2 max-h-[300px] overflow-y-auto custom-scrollbar">
                        <div v-for="(variant, index) in form.variants" :key="variant.id" 
                             class="flex items-center justify-between p-3 bg-white dark:bg-gray-900 border dark:border-gray-700 rounded-lg hover:shadow-sm transition-shadow">
                            <div class="flex items-center gap-3 w-full">
                                <div class="flex-grow text-sm md:text-xs">
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

            <!-- --- MODO MASIVO (BATCH) --- -->
            <div v-if="!isSingleMode" class="mt-6">
                <div class="bg-gray-50 dark:bg-gray-800 p-3 border dark:border-gray-700 rounded-t-lg font-semibold text-sm flex justify-between items-center">
                    <span>Productos seleccionados ({{ form.products.length }})</span>
                    <span class="text-primary-600 dark:text-primary-400">{{ operation === 'entry' ? 'Cantidades a ingresar' : 'Cantidades a descontar' }}</span>
                </div>
                <div class="border border-t-0 dark:border-gray-700 rounded-b-lg p-3 space-y-4 max-h-[400px] overflow-y-auto custom-scrollbar bg-white dark:bg-gray-900">
                    
                    <div v-for="(prod, index) in form.products" :key="prod.id" class="border dark:border-gray-700 rounded-lg p-3 bg-gray-50/50 dark:bg-gray-800/50">
                        <div class="font-bold text-gray-800 dark:text-gray-200 mb-3">{{ prod.name }}</div>
                        
                        <!-- Producto Simple en Masa -->
                        <div v-if="prod.type === 'simple'" class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><i class="pi pi-box !text-xs mr-1"></i> Stock actual: {{ prod.current_stock }}</span>
                            <InputNumber fluid v-model="prod.quantity" :min="0" class="!w-32" showButtons inputClass="text-center" />
                        </div>
                        
                        <!-- Producto con Variantes en Masa -->
                        <div v-else class="space-y-2">
                            <div v-for="v in prod.variants" :key="v.id" class="flex items-center justify-between bg-white dark:bg-gray-900 p-2 rounded border dark:border-gray-700">
                                <div class="text-sm">
                                    <span v-for="(val, key) in v.attributes" :key="key" class="mr-1 font-semibold text-gray-700 dark:text-gray-300">{{ val }}</span>
                                    <div class="text-xs text-gray-500 mt-0.5">Stock: {{ v.current_stock }}</div>
                                </div>
                                <InputNumber fluid v-model="v.quantity" :min="0" class="!w-28" showButtons inputClass="text-center text-sm" />
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- --- BLOQUE DE REGISTRO DE GASTO (Aplica para Individual y Masivo) --- -->
            <div v-if="operation === 'entry' && form.reason === 'Compra / Reabastecimiento'" class="mt-6 p-4 border border-blue-100 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800 rounded-lg">
                <div class="flex items-center justify-between mb-3">
                    <span class="font-semibold text-blue-800 dark:text-blue-300">¿Registrar gasto contable por esta compra?</span>
                    <ToggleSwitch v-model="form.register_expense" />
                </div>

                <div v-if="form.register_expense" class="space-y-4 animate-fade-in mt-4 border-t border-blue-200 dark:border-blue-800/50 pt-4">
                    <!-- Tipo de Monto -->
                    <div>
                        <InputLabel value="Monto del gasto" />
                        <div class="flex flex-col gap-3 mt-2">
                            <div class="flex items-center">
                                <RadioButton v-model="form.expense_amount_type" inputId="calc" name="amount_type" value="calculated" />
                                <label for="calc" class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                    Usar precio de compra registrado <span class="font-bold text-green-600 dark:text-green-400">({{ formatCurrency(calculatedExpenseTotal) }})</span>
                                </label>
                            </div>
                            <div class="flex items-center">
                                <RadioButton v-model="form.expense_amount_type" inputId="man" name="amount_type" value="manual" />
                                <label for="man" class="ml-2 text-sm text-gray-700 dark:text-gray-300">Ingresar total de forma manual (Ej. Total del ticket o factura)</label>
                            </div>
                        </div>
                    </div>

                    <!-- Input Monto Manual -->
                    <div v-if="form.expense_amount_type === 'manual'">
                        <InputLabel value="Total pagado" />
                        <InputNumber v-model="form.expense_amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" :min="0" />
                        <InputError :message="form.errors.expense_amount" class="mt-1" />
                    </div>

                    <!-- Método de Pago -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Método de pago" />
                            <Select v-model="form.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" class="w-full mt-1" />
                            <InputError :message="form.errors.payment_method" class="mt-1" />
                        </div>

                        <!-- Cuentas Bancarias -->
                        <div v-if="['tarjeta', 'transferencia'].includes(form.payment_method)">
                            <InputLabel value="Cuenta bancaria (De dónde salió el dinero)" />
                            <Select v-model="form.bank_account_id" :options="userBankAccounts" optionLabel="account_name" optionValue="id" placeholder="Selecciona una cuenta" class="w-full mt-1" />
                            <InputError :message="form.errors.bank_account_id" class="mt-1" />
                        </div>
                    </div>

                    <!-- Opciones Efectivo (Caja) -->
                    <div v-if="form.payment_method === 'efectivo'" class="flex items-start gap-2 bg-white dark:bg-gray-800 p-3 rounded-md border border-gray-200 dark:border-gray-700">
                        <Checkbox v-model="form.take_from_cash_register" :binary="true" inputId="take_cash" :disabled="!activeSession" />
                        <div class="flex flex-col">
                            <label for="take_cash" class="text-sm font-medium cursor-pointer" :class="{'text-gray-400': !activeSession}">Tomar dinero de la caja registradora</label>
                            <span v-if="!activeSession" class="text-xs text-red-500 mt-1">No hay una sesión de caja activa.</span>
                            <span v-else class="text-xs text-gray-500 mt-0.5">Se registrará el egreso automáticamente en la sesión actual para no descuadrar.</span>
                        </div>
                    </div>
                    <InputError v-if="form.payment_method === 'efectivo'" :message="form.errors.take_from_cash_register" class="mt-1" />
                </div>
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

.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #cbd5e1;
    border-radius: 20px;
}
.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #4b5563;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: #94a3b8;
}
</style>