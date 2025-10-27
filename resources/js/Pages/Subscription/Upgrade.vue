<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import Divider from 'primevue/divider';

const props = defineProps({
    subscription: Object,
    currentVersion: Object,
    allPlanItems: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Suscripción', url: route('subscription.show') },
    { label: 'Mejorar suscripción' }
]);

const form = useForm({
    added_items: [],
});

// --- Computed Properties para la Lógica de UI ---
const activeItemKeys = computed(() => new Set(props.currentVersion.items.map(item => item.item_key)));
const availableModules = computed(() => props.allPlanItems.filter(item => item.type === 'module' && !activeItemKeys.value.has(item.key)));
const allLimitItems = computed(() => props.allPlanItems.filter(item => item.type === 'limit'));

// --- Estado Reactivo para los Controles del Formulario ---
const selectedModules = ref([]);
const limitValues = ref({});

onMounted(() => {
    allLimitItems.value.forEach(limit => {
        const currentItem = props.currentVersion.items.find(item => item.item_key === limit.key);
        limitValues.value[limit.key] = currentItem ? currentItem.quantity : 0;
    });
});

// --- Sincronización de UI con el Formulario a Enviar ---
watch(selectedModules, (newSelectedKeys) => {
    const limitItemsInForm = form.added_items.filter(item => item.key.startsWith('limit_'));
    const moduleItemsForForm = newSelectedKeys.map(key => ({ key, quantity: 1 }));
    form.added_items = [...limitItemsInForm, ...moduleItemsForForm];
}, { deep: true });

watch(limitValues, (newValues) => {
    const moduleItemsInForm = form.added_items.filter(item => item.key.startsWith('module_'));
    const limitItemsForForm = [];

    allLimitItems.value.forEach(limitItem => {
        const currentItem = props.currentVersion.items.find(item => item.item_key === limitItem.key);
        const currentQuantity = currentItem ? currentItem.quantity : 0;
        const newTotalQuantity = newValues[limitItem.key] || 0;
        const addedQuantity = newTotalQuantity - currentQuantity;

        if (addedQuantity > 0) {
            limitItemsForForm.push({ key: limitItem.key, quantity: addedQuantity });
        }
    });
    form.added_items = [...moduleItemsInForm, ...limitItemsForForm];
}, { deep: true });


// --- Lógica de Cálculo de Prorrateo ---
const endDate = new Date(props.currentVersion.end_date);
const today = new Date();
const remainingDays = Math.max(0, Math.ceil((endDate - today) / (1000 * 60 * 60 * 24)));

const totalProratedCost = computed(() => {
    return form.added_items.reduce((total, added) => {
        const planItem = props.allPlanItems.find(item => item.key === added.key);
        if (!planItem) return total;

        const annualPrice = planItem.monthly_price * 10;
        let unitAnnualPrice = annualPrice;
        if (planItem.type === 'limit' && planItem.meta.quantity > 0) {
            unitAnnualPrice = annualPrice / planItem.meta.quantity;
        }

        const proratedCost = (unitAnnualPrice / 365) * remainingDays * added.quantity;
        return total + proratedCost;
    }, 0);
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const submit = () => {
    form.post(route('subscription.upgrade.store'));
};

</script>

<template>
    <AppLayout title="Mejorar suscripción">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Mejorar Suscripción</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Añade módulos o incrementa los límites de tu plan
                    actual.</p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Selección -->
                <div class="lg:col-span-2 space-y-6">
                    <Card>
                        <template #title>Módulos Adicionales</template>
                        <template #content>
                            <div v-if="availableModules.length > 0"
                                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div v-for="module in availableModules" :key="module.key"
                                    class="border dark:border-gray-700 rounded-lg p-4 flex items-center gap-4">
                                    <Checkbox v-model="selectedModules" :inputId="module.key" :value="module.key" />
                                    <label :for="module.key" class="flex-grow">
                                        <div class="flex items-center gap-2">
                                            <i :class="module.meta.icon" class="text-orange-500"></i>
                                            <span class="font-semibold">{{ module.name }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500">{{ formatCurrency(module.monthly_price * 10)
                                            }}/año</p>
                                    </label>
                                </div>
                            </div>
                            <div v-else class="text-center py-4">
                                <p class="text-gray-500 dark:text-gray-400">¡Felicidades! Ya tienes todos los módulos
                                    disponibles.</p>
                            </div>
                        </template>
                    </Card>
                    <Card>
                        <template #title>Incrementar Límites</template>
                        <template #content>
                            <div class="space-y-4">
                                <div v-for="limit in allLimitItems" :key="limit.key"
                                    class="flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold">{{ limit.name }}</p>
                                        <p class="text-xs text-gray-500">{{ formatCurrency(limit.monthly_price * 10)
                                            }}/año por cada {{ limit.meta.quantity }}</p>
                                    </div>
                                    <InputNumber v-model="limitValues[limit.key]"
                                        :min="(currentVersion.items.find(i => i.item_key === limit.key)?.quantity || 0)"
                                        :step="limit.key === 'limit_products' ? 50 : 1" showButtons
                                        buttonLayout="horizontal" decrementButtonClass="p-button-secondary"
                                        incrementButtonClass="p-button-secondary" incrementButtonIcon="pi pi-plus"
                                        decrementButtonIcon="pi pi-minus"
                                        inputStyle="width: 5rem; text-align: center;" />
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
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    Se te cobrará un monto prorrateado por los <b>{{ remainingDays }} días</b> restantes
                                    de tu ciclo de facturación actual.
                                </p>
                                <Divider />
                                <div v-if="form.added_items.length > 0"
                                    class="space-y-2 text-sm max-h-48 overflow-y-auto">
                                    <div v-for="item in form.added_items" :key="item.key" class="flex justify-between">
                                        <span>
                                            {{allPlanItems.find(i => i.key === item.key).name}}
                                            <span
                                                v-if="item.quantity > 1 && allPlanItems.find(i => i.key === item.key).type === 'limit'">
                                                (x{{ item.quantity }})</span>
                                        </span>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-center text-gray-500 py-4">Selecciona un ítem para ver el
                                    costo.</p>
                                <Divider />
                                <div class="flex justify-between items-center font-bold text-lg">
                                    <span>Total a Pagar Hoy:</span>
                                    <span>{{ formatCurrency(totalProratedCost) }}</span>
                                </div>
                                <Button @click="submit" :disabled="form.added_items.length === 0 || form.processing"
                                    :loading="form.processing" label="Confirmar y Pagar" class="w-full mt-4" />
                            </div>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>