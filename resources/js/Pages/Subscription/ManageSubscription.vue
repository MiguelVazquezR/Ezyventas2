<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// --- Props ---
const props = defineProps({
    subscription: Object,
    currentVersion: Object, // Puede ser null si es una renovación desde cero
    allPlanItems: Array,
    mode: String, // 'upgrade' o 'renew'
    currentBillingPeriod: String, // 'anual' o 'mensual'
});

// --- Estado Básico ---
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Suscripción', url: route('subscription.show') },
    { label: props.mode === 'upgrade' ? 'Mejorar Suscripción' : 'Renovar Suscripción' }
]);

// --- Estado del Formulario ---
const billingPeriod = ref(props.currentBillingPeriod || 'anual'); // 'mensual' o 'anual'
const selectedModules = ref([]);
const limitValues = ref({});
const form = useForm({
    billing_period: billingPeriod.value,
    items: [],
    total_amount: 0,
    mode: props.mode,
});

// --- Listas de Items ---
const activeItemKeys = computed(() => 
    new Set(props.currentVersion?.items.map(item => item.item_key) || [])
);
const availableModules = computed(() => 
    props.allPlanItems.filter(item => item.type === 'module' && (props.mode === 'renew' || !activeItemKeys.value.has(item.key)))
);
const allLimitItems = computed(() => 
    props.allPlanItems.filter(item => item.type === 'limit')
);

// --- Inicialización del Estado ---
onMounted(() => {
    // Pre-seleccionar items de la versión actual
    if (props.currentVersion) {
        const currentModules = props.currentVersion.items
            .filter(item => item.item_type === 'module')
            .map(item => item.item_key);
        selectedModules.value = currentModules;

        allLimitItems.value.forEach(limit => {
            const currentItem = props.currentVersion.items.find(item => item.item_key === limit.key);
            limitValues.value[limit.key] = currentItem ? currentItem.quantity : 0;
        });
    } else {
         // Valores por defecto si no hay versión (primera vez)
        allLimitItems.value.forEach(limit => {
            limitValues.value[limit.key] = 0;
        });
    }
});

// --- Lógica de Precios ---
const getPrice = (item) => {
    if (!item) return 0;
    const basePrice = parseFloat(item.monthly_price) || 0;
    // Asumimos 10 meses por pago anual (2 meses gratis)
    return billingPeriod.value === 'anual' ? basePrice * 10 : basePrice;
};

const getLimitPackagePrice = (limitItem) => {
    if (!limitItem) return 0;
    const packageQuantity = limitItem.meta?.quantity || 1;
    const packagePrice = getPrice(limitItem);
    // Devuelve el precio por unidad (ej. precio por producto)
    return packagePrice / packageQuantity;
};

// --- Lógica de Costos (Upgrade vs Renew) ---

// Días restantes (solo para 'upgrade')
const remainingDays = computed(() => {
    if (props.mode !== 'upgrade' || !props.currentVersion) return 0;
    const endDate = new Date(props.currentVersion.end_date);
    const today = new Date();
    return Math.max(0, Math.ceil((endDate - today) / (1000 * 60 * 60 * 24)));
});

// Días totales del periodo actual (solo para 'upgrade')
const totalDaysInPeriod = computed(() => {
    if (props.mode !== 'upgrade' || !props.currentVersion) return 365;
    return props.currentBillingPeriod === 'anual' ? 365 : 30; // Simplificación
});

// --- Sincronización de Formulario y UI ---
watch([selectedModules, limitValues, billingPeriod], () => {
    form.billing_period = billingPeriod.value;
    const newItems = [];
    let totalCost = 0;

    // 1. Calcular Módulos
    selectedModules.value.forEach(key => {
        const planItem = props.allPlanItems.find(item => item.key === key);
        if (!planItem) return;

        newItems.push({ key: planItem.key, quantity: 1 });
        
        if (props.mode === 'upgrade') {
            // Si es 'upgrade' y el módulo es NUEVO
            if (!activeItemKeys.value.has(key)) {
                const periodPrice = props.currentBillingPeriod === 'anual' ? (planItem.monthly_price * 10) : planItem.monthly_price;
                const proratedCost = (periodPrice / totalDaysInPeriod.value) * remainingDays.value;
                totalCost += proratedCost;
            }
        } else {
            // Si es 'renew', se cobra el precio completo
            totalCost += getPrice(planItem);
        }
    });

    // 2. Calcular Límites
    allLimitItems.value.forEach(limitItem => {
        const newQuantity = limitValues.value[limitItem.key] || 0;
        if (newQuantity <= 0) return;
        
        newItems.push({ key: limitItem.key, quantity: newQuantity });

        if (props.mode === 'upgrade') {
            const currentItem = props.currentVersion.items.find(item => item.item_key === limitItem.key);
            const currentQuantity = currentItem ? currentItem.quantity : 0;
            const addedQuantity = newQuantity - currentQuantity;

            if (addedQuantity > 0) {
                const unitPrice = (props.currentBillingPeriod === 'anual' ? limitItem.monthly_price * 10 : limitItem.monthly_price) / (limitItem.meta.quantity || 1);
                const proratedCost = (unitPrice / totalDaysInPeriod.value) * remainingDays.value * addedQuantity;
                totalCost += proratedCost;
            }
        } else {
            // Si es 'renew', se cobra el precio completo por la cantidad total
            const unitPrice = getPrice(limitItem) / (limitItem.meta.quantity || 1);
            totalCost += (unitPrice * newQuantity);
        }
    });

    form.items = newItems;
    form.total_amount = totalCost;

}, { deep: true, immediate: true });


// --- INICIO: NUEVA PROPIEDAD COMPUTADA PARA RESUMEN ---
// Esta propiedad calculará la "diferencia" para mostrar en el resumen
const addedItemsForSummary = computed(() => {
    if (props.mode === 'renew') {
        // 1. MODO RENOVACIÓN: Mostrar todo lo seleccionado
        return form.items.map(item => ({
            ...item,
            name: props.allPlanItems.find(i => i.key === item.key)?.name || item.key
        }));
    }

    // 2. MODO MEJORA: Calcular la diferencia (el "delta")
    const added = [];
    // Creamos un mapa de los items actuales para fácil acceso
    const currentItemsMap = new Map(props.currentVersion.items.map(i => [i.item_key, i.quantity]));

    form.items.forEach(item => {
        const planItem = props.allPlanItems.find(i => i.key === item.key);
        if (!planItem) return;

        const currentQuantity = currentItemsMap.get(item.key) || 0;
        const newQuantity = item.quantity;

        if (planItem.type === 'module') {
            // Si es un módulo y no estaba antes (y se añadió), es nuevo
            if (currentQuantity === 0 && newQuantity > 0) {
                added.push({ key: item.key, name: planItem.name, quantity: 1 });
            }
        } else if (planItem.type === 'limit') {
            // Si es un límite, calcular la cantidad añadida
            const addedQuantity = newQuantity - currentQuantity;
            if (addedQuantity > 0) {
                // Añadir solo la cantidad *extra*
                added.push({ key: item.key, name: planItem.name, quantity: addedQuantity });
            }
        }
    });
    return added;
});
// --- FIN: NUEVA PROPIEDAD COMPUTADA PARA RESUMEN ---


// --- Helpers ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

// --- Submit ---
const submit = () => {
    // Aquí iría la lógica de confirmación de pago
    // Por ahora, solo enviamos el formulario.
    form.post(route('subscription.manage.store'), {
        onError: (errors) => {
            console.error('Error al procesar la suscripción:', errors);
        }
    });
};

</script>

<template>
    <Head :title="mode === 'upgrade' ? 'Mejorar Suscripción' : 'Renovar Suscripción'" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ mode === 'upgrade' ? 'Mejorar Suscripción' : 'Renovar Suscripción' }}</h1>
                <p v-if="mode === 'upgrade'" class="text-gray-500 dark:text-gray-400 mt-1">
                    Añade módulos o incrementa los límites de tu plan actual.
                </p>
                <p v-else class="text-gray-500 dark:text-gray-400 mt-1">
                    Tu plan ha vencido. Selecciona tus módulos y límites para el nuevo periodo.
                </p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Selección -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Selector de Periodo (solo en renovación) -->
                    <Card v-if="mode === 'renew'">
                        <template #title>Periodo de Facturación</template>
                        <template #content>
                            <SelectButton 
                                v-model="billingPeriod" 
                                :options="[{label: 'Mensual', value: 'mensual'}, {label: 'Anual (2 meses gratis)', value: 'anual'}]" 
                                optionLabel="label" 
                                optionValue="value" 
                                aria-labelledby="billing-period" 
                                class="w-full"
                            />
                        </template>
                    </Card>

                    <!-- Módulos -->
                    <Card>
                        <template #title>Módulos</template>
                        <template #content>
                            <div v-if="availableModules.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div v-for="module in availableModules" :key="module.key" class="border dark:border-gray-700 rounded-lg p-4 flex items-center gap-4">
                                    <Checkbox v-model="selectedModules" :inputId="module.key" :value="module.key" />
                                    <label :for="module.key" class="flex-grow cursor-pointer">
                                        <div class="flex items-center gap-2">
                                            <i :class="module.meta.icon" class="text-orange-500"></i>
                                            <span class="font-semibold">{{ module.name }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500">{{ formatCurrency(getPrice(module)) }}/{{ billingPeriod === 'anual' ? 'año' : 'mes' }}</p>
                                    </label>
                                </div>
                            </div>
                            <div v-else class="text-center py-4">
                                <p class="text-gray-500 dark:text-gray-400">¡Felicidades! Ya tienes todos los módulos disponibles.</p>
                            </div>
                        </template>
                    </Card>

                    <!-- Límites -->
                    <Card>
                        <template #title>Límites</template>
                        <template #content>
                            <div class="space-y-4">
                                <div v-for="limit in allLimitItems" :key="limit.key" class="flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold">{{ limit.name }}</p>
                                        <p class="text-xs text-gray-500">{{ formatCurrency(getPrice(limit) / limit.meta.quantity) }} por c/u / {{ billingPeriod === 'anual' ? 'año' : 'mes' }}</p>
                                    </div>
                                    <InputNumber 
                                        v-model="limitValues[limit.key]"
                                        :min="0"
                                        :step="limit.key === 'limit_products' ? 50 : 1" 
                                        showButtons
                                        buttonLayout="horizontal" 
                                        decrementButtonClass="p-button-secondary"
                                        incrementButtonClass="p-button-secondary" 
                                        incrementButtonIcon="pi pi-plus"
                                        decrementButtonIcon="pi pi-minus"
                                        inputStyle="width: 5rem; text-align: center;" 
                                    />
                                </div>
                            </div>
                        </template>
                    </Card>
                </div>

                <!-- Columna de Resumen -->
                <div class="lg:col-span-1">
                    <Card class="sticky top-24">
                        <template #title>Resumen de Pago</template>
                        <template #content>
                            <div class="space-y-3">
                                <!-- Mensaje de Prorrateo -->
                                <p v-if="mode === 'upgrade'" class="text-sm text-gray-600 dark:text-gray-300">
                                    Se te cobrará un monto prorrateado por los <b>{{ remainingDays }} días</b> restantes de tu ciclo de facturación ({{ currentBillingPeriod }}).
                                </p>
                                <!-- Mensaje de Renovación -->
                                <p v-else class="text-sm text-gray-600 dark:text-gray-300">
                                    Estás pagando un nuevo periodo. Tu próxima fecha de corte será en <b>1 {{ billingPeriod === 'anual' ? 'año' : 'mes' }}</b>.
                                </p>

                                <Divider />
                                
                                <!-- --- INICIO: SECCIÓN DE RESUMEN MODIFICADA --- -->
                                <div v-if="addedItemsForSummary.length > 0" class="space-y-2 text-sm max-h-48 overflow-y-auto">
                                    <div v-for="item in addedItemsForSummary" :key="item.key" class="flex justify-between">
                                        <span>
                                            {{ item.name }}
                                            <!-- Mostrar (xN) solo si la cantidad es mayor a 1 -->
                                            <span v-if="item.quantity > 1">
                                                (x{{ item.quantity }})
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-center text-gray-500 py-4">
                                    {{ mode === 'upgrade' ? 'No has añadido items nuevos.' : 'Selecciona tus items.' }}
                                </p>
                                <!-- --- FIN: SECCIÓN DE RESUMEN MODIFICADA --- -->
                                
                                <Divider />
                                <div class="flex justify-between items-center font-bold text-lg">
                                    <span>{{ mode === 'upgrade' ? 'Total a Pagar Hoy' : 'Total del Periodo' }}:</span>
                                    <span>{{ formatCurrency(form.total_amount) }}</span>
                                </div>
                                <Button 
                                    @click="submit" 
                                    :disabled="form.items.length === 0 || form.processing || form.total_amount <= 0"
                                    :loading="form.processing" 
                                    :label="mode === 'upgrade' ? 'Confirmar y Pagar' : 'Renovar y Pagar'" 
                                    class="w-full mt-4" 
                                />
                            </div>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>