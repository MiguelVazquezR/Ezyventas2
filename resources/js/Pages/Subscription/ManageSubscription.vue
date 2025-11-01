<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
// --- AÑADIDO: Importar componentes de PrimeVue ---
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import Divider from 'primevue/divider';
import SelectButton from 'primevue/selectbutton';
import FileUpload from 'primevue/fileupload';
import Message from 'primevue/message';
import Accordion from 'primevue/accordion';
import AccordionPanel from 'primevue/accordionpanel';
import Breadcrumb from 'primevue/breadcrumb';
// ---

// --- Props ---
const props = defineProps({
    subscription: Object,
    currentVersion: Object, // Puede ser null si es una renovación desde cero
    allPlanItems: Array,
    mode: String, // 'upgrade' o 'renew'
    currentBillingPeriod: String, // 'anual' o 'mensual'
    adminBankAccounts: Array, // AÑADIDO: Cuentas bancarias del admin
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

// AÑADIDO: Nuevos campos en el formulario
const form = useForm({
    billing_period: billingPeriod.value,
    items: [],
    total_amount: 0,
    mode: props.mode,
    payment_method: 'transfer', // 'card' o 'transfer'
    proof_of_payment: null, // Archivo del comprobante
});

// --- Listas de Items ---
const activeItemKeys = computed(() =>
    new Set(props.currentVersion?.items.map(item => item.item_key) || [])
);
const availableModules = computed(() =>
    props.allPlanItems.filter(item => item.type === 'module') // Mostrar todos en renovación
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
            // Valores mínimos por defecto para un plan nuevo
            if (limit.key === 'limit_branches') limitValues.value[limit.key] = 1;
            else if (limit.key === 'limit_users') limitValues.value[limit.key] = 1;
            else if (limit.key === 'limit_products') limitValues.value[limit.key] = 50; // Como en tu seeder
            else if (limit.key === 'limit_cash_registers') limitValues.value[limit.key] = 1;
            else if (limit.key === 'limit_print_templates') limitValues.value[limit.key] = 1;
            else limitValues.value[limit.key] = 0;
        });
        // Módulos base
        selectedModules.value = [
            'module_pos', 'module_financial_reports', 'module_transactions',
            'module_products', 'module_expenses', 'module_customers',
            'module_cash_registers', 'module_settings'
        ];
    }
});

// --- Lógica de Precios ---
const getPrice = (item) => {
    if (!item) return 0;
    const basePrice = parseFloat(item.monthly_price) || 0;
    return billingPeriod.value === 'anual' ? basePrice * 10 : basePrice;
};

// --- Lógica de Costos (Upgrade vs Renew) ---
const remainingDays = computed(() => {
    if (props.mode !== 'upgrade' || !props.currentVersion) return 0;
    const endDate = new Date(props.currentVersion.end_date);
    const today = new Date();
    return Math.max(0, Math.ceil((endDate - today) / (1000 * 60 * 60 * 24)));
});

const totalDaysInPeriod = computed(() => {
    if (props.mode !== 'upgrade' || !props.currentVersion) return 365;
    // Simplificación (anual = 365, mensual = 30)
    return props.currentBillingPeriod === 'anual' ? 365 : 30;
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
            if (!activeItemKeys.value.has(key)) {
                const periodPrice = props.currentBillingPeriod === 'anual' ? (planItem.monthly_price * 10) : planItem.monthly_price;
                const proratedCost = (periodPrice / totalDaysInPeriod.value) * remainingDays.value;
                totalCost += proratedCost;
            }
        } else {
            totalCost += getPrice(planItem);
        }
    });

    // 2. Calcular Límites
    allLimitItems.value.forEach(limitItem => {
        const newQuantity = limitValues.value[limitItem.key] || 0;
        if (newQuantity <= 0) return;

        // El item se añade con la cantidad total
        newItems.push({ key: limitItem.key, quantity: newQuantity });
        const unitPricePerPackage = getPrice(limitItem);
        const pricePerUnit = unitPricePerPackage / (limitItem.meta.quantity || 1);

        if (props.mode === 'upgrade') {
            const currentItem = props.currentVersion.items.find(item => item.item_key === limitItem.key);
            const currentQuantity = currentItem ? currentItem.quantity : 0;
            const addedQuantity = newQuantity - currentQuantity;

            if (addedQuantity > 0) {
                // Precio prorrateado de las unidades añadidas
                const dailyPricePerUnit = (props.currentBillingPeriod === 'anual'
                    ? (limitItem.monthly_price * 10) / (limitItem.meta.quantity || 1)
                    : limitItem.monthly_price / (limitItem.meta.quantity || 1)) / totalDaysInPeriod.value;

                totalCost += (dailyPricePerUnit * remainingDays.value * addedQuantity);
            }
        } else {
            // Precio completo por el total de unidades
            totalCost += (pricePerUnit * newQuantity);
        }
    });

    form.items = newItems;
    form.total_amount = totalCost;

}, { deep: true, immediate: true });


// Resumen de items (diferencia en 'upgrade', total en 'renew')
const itemsForSummary = computed(() => {
    const summary = [];
    const currentItemsMap = new Map(props.currentVersion?.items.map(i => [i.item_key, i.quantity]) || []);

    form.items.forEach(item => {
        const planItem = props.allPlanItems.find(i => i.key === item.key);
        if (!planItem) return;

        const currentQuantity = currentItemsMap.get(item.key) || 0;
        const newQuantity = item.quantity;

        if (props.mode === 'upgrade') {
            if (planItem.type === 'module' && currentQuantity === 0 && newQuantity > 0) {
                summary.push({ key: item.key, name: planItem.name, quantity: 1 });
            } else if (planItem.type === 'limit') {
                const addedQuantity = newQuantity - currentQuantity;
                if (addedQuantity > 0) {
                    summary.push({ key: item.key, name: planItem.name, quantity: addedQuantity });
                }
            }
        } else { // mode === 'renew'
            summary.push({ key: item.key, name: planItem.name, quantity: item.quantity });
        }
    });
    return summary;
});


// --- Helpers ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

// --- AÑADIDO: Manejo de subida de archivo ---
const onFileSelect = (event) => {
    form.proof_of_payment = event.files[0];
};
const onFileRemove = () => {
    form.proof_of_payment = null;
};

// --- Submit ---
const submit = () => {
    // El post ahora incluye el archivo si existe
    form.post(route('subscription.manage.store'), {
        onError: (errors) => {
            console.error('Error al procesar la suscripción:', errors);
        }
    });
};

</script>

<template>
    <AppLayout :title="mode === 'upgrade' ? 'Mejorar suscripción' : 'Renovar suscripción'">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 mb-6" />

        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">
                    {{ mode === 'upgrade' ? 'Mejorar suscripción' : 'Renovar suscripción' }}
                </h1>
                <p v-if="mode === 'upgrade'" class="text-gray-500 dark:text-gray-400 mt-1">
                    Añade módulos o incrementa los límites de tu plan actual.
                </p>
                <p v-else class="text-gray-500 dark:text-gray-400 mt-1">
                    Tu plan ha vencido o está por vencer. Selecciona tus módulos y límites para el nuevo periodo.
                </p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Selección -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Selector de Periodo (siempre visible en renovación, deshabilitado en mejora) -->
                    <Card>
                        <template #title>Periodo de facturación</template>
                        <template #content>
                            <SelectButton v-model="billingPeriod"
                                :options="[{ label: 'Mensual', value: 'mensual' }, { label: 'Anual (2 meses gratis)', value: 'anual' }]"
                                optionLabel="label" optionValue="value" aria-labelledby="billing-period" class="w-full"
                                :disabled="mode === 'upgrade'" />
                            <small v-if="mode === 'upgrade'" class="text-gray-500 mt-2 block">
                                El periodo se alinea con tu ciclo de facturación actual ({{ currentBillingPeriod }}).
                            </small>
                        </template>
                    </Card>

                    <!-- Módulos -->
                    <Card>
                        <template #title>Módulos</template>
                        <template #content>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div v-for="module in availableModules" :key="module.key"
                                    class="border dark:border-gray-700 rounded-lg p-4 flex items-center gap-4 transition-all"
                                    :class="{ 'bg-gray-50 dark:bg-gray-700/30': selectedModules.includes(module.key) }">
                                    <Checkbox v-model="selectedModules" :inputId="module.key" :value="module.key"
                                        :disabled="mode === 'upgrade' && activeItemKeys.has(module.key)" />
                                    <label :for="module.key" class="flex-grow cursor-pointer">
                                        <div class="flex items-center gap-2">
                                            <i :class="module.meta.icon" class="text-orange-500"></i>
                                            <span class="font-semibold">{{ module.name }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500">{{ formatCurrency(getPrice(module)) }}/{{
                                            billingPeriod === 'anual' ? 'año' : 'mes' }}</p>
                                    </label>
                                </div>
                            </div>
                        </template>
                    </Card>

                    <!-- Límites -->
                    <Card>
                        <template #title>Límites</template>
                        <template #content>
                            <div class="space-y-4">
                                <div v-for="limit in allLimitItems" :key="limit.key"
                                    class="flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold m-0">{{ limit.name }}</p>
                                        <p class="text-xs text-gray-500 m-0">{{ formatCurrency(getPrice(limit) /
                                            (limit.meta.quantity || 1)) }} por c/u / {{ billingPeriod === 'anual' ?
                                                'año' : 'mes' }}</p>
                                    </div>
                                    <InputNumber v-model="limitValues[limit.key]"
                                        :min="mode === 'upgrade' ? (currentVersion.items.find(i => i.item_key === limit.key)?.quantity || 0) : 0"
                                        :step="limit.key === 'limit_products' ? 50 : 1" showButtons
                                        buttonLayout="horizontal" decrementButtonClass="p-button-secondary"
                                        incrementButtonClass="p-button-secondary" incrementButtonIcon="pi pi-plus"
                                        decrementButtonIcon="pi pi-minus"
                                        :inputStyle="{width: '5rem', textAlign: 'center'}" />
                                </div>
                            </div>
                        </template>
                    </Card>

                    <!-- SECCIÓN DE PAGO MOVIDA -->

                </div>

                <!-- Columna de Resumen -->
                <div class="lg:col-span-1">
                    <Card class="sticky top-24">
                        <template #title>Resumen de pago</template>
                        <template #content>
                            <div class="space-y-4"> <!-- Ajustado space-y -->
                                <p v-if="mode === 'upgrade'" class="text-sm text-gray-600 dark:text-gray-300">
                                    Se te cobrará un monto prorrateado por los <b>{{ remainingDays }} días</b> restantes
                                    de tu ciclo ({{ currentBillingPeriod }}).
                                </p>
                                <p v-else class="text-sm text-gray-600 dark:text-gray-300">
                                    Total de tu nuevo periodo <b>{{ billingPeriod === 'anual' ? 'anual' : 'mes' }}</b>.
                                </p>

                                <Divider />

                                <div v-if="itemsForSummary.length > 0"
                                    class="space-y-2 text-sm max-h-48 overflow-y-auto p-1">
                                    <div v-for="item in itemsForSummary" :key="item.key" class="flex justify-between">
                                        <span>
                                            {{ item.name }}
                                            <span
                                                v-if="item.quantity > 1 && allLimitItems.some(l => l.key === item.key)">
                                                (x{{ item.quantity }})
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-center text-gray-500 py-4">
                                    {{ mode === 'upgrade' ? 'No has añadido items nuevos.' : 'Selecciona tus items.' }}
                                </p>

                                <Divider />
                                <div class="flex justify-between items-center font-bold text-lg">
                                    <span>{{ mode === 'upgrade' ? 'Total a pagar hoy' : 'Total del periodo' }}:</span>
                                    <span>{{ formatCurrency(form.total_amount) }}</span>
                                </div>

                                <!-- --- INICIO SECCIÓN MOVIDA --- -->
                                <Divider />
                                <h5 class="font-semibold text-gray-800 dark:text-gray-200 pt-2">Método de pago</h5>

                                <SelectButton v-model="form.payment_method"
                                    :options="[{ label: 'Transferencia Bancaria', value: 'transfer' }, { label: 'Tarjeta (Próximamente)', value: 'card' }]"
                                    optionLabel="label" optionValue="value" aria-labelledby="payment-method"
                                    class="w-full" :disabled="true" />

                                <!-- Detalles para Transferencia -->
                                <div v-if="form.payment_method === 'transfer'" class="mt-4 space-y-4">
                                    <Message severity="info" :closable="false">
                                        Realiza tu pago a cualquiera de las siguientes cuentas y sube tu comprobante. Tu
                                        plan se activará una vez que validemos el pago (usualmente 1 hora hábil).
                                    </Message>

                                    <!-- Cuentas Bancarias del Admin -->
                                    <Accordion :activeIndex="0">
                                        <AccordionPanel v-for="account in adminBankAccounts" :key="account.id">
                                            <AccordionHeader>
                                                <span class="flex items-center gap-2 w-full">
                                                    <i class="pi pi-building-columns"></i>
                                                    <span>{{ account.bank_name }}</span>
                                                </span>
                                            </AccordionHeader>
                                            <AccordionContent>
                                                <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                                    <li><strong>Titular:</strong> {{ account.owner_name }}</li>
                                                    <li><strong>No. Cuenta:</strong> {{ account.account_number }}</li>
                                                    <li v-if="account.clabe"><strong>CLABE:</strong> {{ account.clabe }}
                                                    </li>
                                                    <li v-if="account.card_number"><strong>No. Tarjeta:</strong> {{
                                                        account.card_number }}</li>
                                                </ul>
                                            </AccordionContent>
                                        </AccordionPanel>
                                    </Accordion>

                                    <!-- Subida de Comprobante -->
                                    <div>
                                        <h5 class="font-semibold mb-2 text-sm">Sube tu comprobante*</h5>
                                        <FileUpload name="proof_of_payment" @select="onFileSelect"
                                            @remove="onFileRemove" :showUploadButton="false" :showCancelButton="false"
                                            :multiple="false" accept="image/*,application/pdf" :maxFileSize="10000000">
                                            <template #empty>
                                                <p class="text-sm text-center text-gray-500 p-4">Arrastra y suelta tu
                                                    archivo (PDF, JPG, PNG).</p>
                                            </template>
                                        </FileUpload>
                                        <small v-if="form.errors.proof_of_payment" class="p-error">{{
                                            form.errors.proof_of_payment }}</small>
                                    </div>
                                </div>

                                <!-- Mensaje para Tarjeta -->
                                <div v-if="form.payment_method === 'card'" class="mt-4">
                                    <Message severity="warn" :closable="false">
                                        El pago con tarjeta estará disponible próximamente.
                                    </Message>
                                </div>
                                <!-- --- FIN SECCIÓN MOVIDA --- -->

                                <Button @click="submit"
                                    :disabled="form.items.length === 0 || form.processing || form.total_amount <= 0 || (form.payment_method === 'transfer' && !form.proof_of_payment) || form.payment_method === 'card'"
                                    :loading="form.processing"
                                    :label="mode === 'upgrade' ? 'Confirmar y pagar' : 'Enviar comprobante'"
                                    class="w-full mt-6" />
                            </div>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>