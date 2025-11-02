<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import InputError from '@/Components/InputError.vue'; // AÑADIDO

// --- Props ---
const props = defineProps({
    subscription: Object,
    currentVersion: Object,
    previousVersion: Object,
    isRetry: Boolean,
    allPlanItems: Array,
    mode: String,
    currentBillingPeriod: String,
    ourBankAccounts: Array,
    hasPendingPayment: Boolean,
    // --- AÑADIDO: Props para el nuevo Gasto ---
    userBankAccounts: Array, // Cuentas del suscriptor
    expenseCategories: Array, // Categorías de gasto del suscriptor
});

// --- Estado Básico ---
const confirm = useConfirm();
const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Suscripción', url: route('subscription.show') },
    { label: props.mode === 'upgrade' ? 'Mejorar suscripción' : 'Renovar suscripción' }
]);

// --- Estado del Formulario ---
const billingPeriod = ref(props.currentBillingPeriod || 'anual');
const selectedModules = ref([]);
const limitValues = ref({});

const form = useForm({
    billing_period: billingPeriod.value,
    items: [],
    total_amount: 0,
    mode: props.mode,
    payment_method: 'transferencia',
    proof_of_payment: null,
    // --- AÑADIDO: Campos para el Gasto ---
    bank_account_id: null, // ID de la cuenta del suscriptor
    expense_category_id: null, // ID de la categoría de gasto
});

// --- Lógica de Versión de Comparación ---
const versionToCompare = computed(() =>
    props.isRetry ? props.previousVersion : props.currentVersion
);

// --- Listas de Items ---
const activeItemKeys = computed(() =>
    new Set(versionToCompare.value?.items.map(item => item.item_key) || [])
);
const availableModules = computed(() =>
    props.allPlanItems.filter(item => item.type === 'module')
);
const allLimitItems = computed(() =>
    props.allPlanItems.filter(item => item.type === 'limit')
);

// --- Inicialización del Estado ---
onMounted(() => {
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
        // ... (valores por defecto sin cambios)
        allLimitItems.value.forEach(limit => {
            if (limit.key === 'limit_branches') limitValues.value[limit.key] = 1;
            else if (limit.key === 'limit_users') limitValues.value[limit.key] = 1;
            else if (limit.key === 'limit_products') limitValues.value[limit.key] = 50;
            else if (limit.key === 'limit_cash_registers') limitValues.value[limit.key] = 1;
            else if (limit.key === 'limit_print_templates') limitValues.value[limit.key] = 1;
            else limitValues.value[limit.key] = 0;
        });
        selectedModules.value = [
            'module_pos', 'module_financial_reports', 'module_transactions',
            'module_products', 'module_expenses', 'module_customers',
            'module_cash_registers', 'module_settings'
        ];
    }
});

// --- Lógica de Precios ---
const getPrice = (item) => {
    // ... (sin cambios)
    if (!item) return 0;
    const basePrice = parseFloat(item.monthly_price) || 0;
    return billingPeriod.value === 'anual' ? basePrice * 10 : basePrice;
};

// --- Lógica de Costos (Upgrade vs Renew) ---
const remainingDays = computed(() => {
    // ... (sin cambios)
    if (props.mode !== 'upgrade' || !versionToCompare.value) return 0;
    const endDate = new Date(versionToCompare.value.end_date);
    const today = new Date();
    return Math.max(0, Math.ceil((endDate - today) / (1000 * 60 * 60 * 24)));
});

const totalDaysInPeriod = computed(() => {
    // ... (sin cambios)
    if (props.mode !== 'upgrade' || !versionToCompare.value) return 365;
    return props.currentBillingPeriod === 'anual' ? 365 : 30;
});

// --- Sincronización de Formulario y UI ---
watch([selectedModules, limitValues, billingPeriod], () => {
    // ... (lógica de cálculo de costos sin cambios)
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

        newItems.push({ key: limitItem.key, quantity: newQuantity });
        const unitPricePerPackage = getPrice(limitItem);
        const pricePerUnit = unitPricePerPackage / (limitItem.meta.quantity || 1);

        if (props.mode === 'upgrade') {
            const currentItem = versionToCompare.value?.items.find(item => item.item_key === limitItem.key);
            const currentQuantity = currentItem ? currentItem.quantity : 0;
            const addedQuantity = newQuantity - currentQuantity;

            if (addedQuantity > 0) {
                const dailyPricePerUnit = (props.currentBillingPeriod === 'anual'
                    ? (limitItem.monthly_price * 10) / (limitItem.meta.quantity || 1)
                    : limitItem.monthly_price / (limitItem.meta.quantity || 1)) / totalDaysInPeriod.value;

                totalCost += (dailyPricePerUnit * remainingDays.value * addedQuantity);
            }
        } else {
            totalCost += (pricePerUnit * newQuantity);
        }
    });

    form.items = newItems;
    form.total_amount = totalCost;

}, { deep: true, immediate: true });

// --- AÑADIDO: Limpiar categoría si se quita la cuenta ---
watch(() => form.bank_account_id, (newVal) => {
    if (!newVal) {
        form.expense_category_id = null;
    }
});


// Resumen de items
const itemsForSummary = computed(() => {
    // ... (sin cambios)
    const summary = [];
    const currentItemsMap = new Map(versionToCompare.value?.items.map(i => [i.item_key, i.quantity]) || []);

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

// --- Lógica del botón Revertir ---
const confirmRevert = () => {
    // ... (sin cambios)
    confirm.require({
        message: '¿Estás seguro de que quieres cancelar esta actualización y volver a tu plan anterior? Se eliminará este intento de pago.',
        header: 'Confirmar Cancelación',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, cancelar',
        rejectLabel: 'No',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('subscription.revert'), {
                preserveScroll: true
            });
        }
    });
};


// --- Helpers ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const getMinLimit = (limitKey) => {
    // ... (sin cambios)
    const baseVersion = versionToCompare.value;
    if (!baseVersion) {
        if (limitKey === 'limit_branches') return 1;
        if (limitKey === 'limit_users') return 1;
        return 0;
    }
    const item = baseVersion.items.find(i => i.item_key === limitKey);
    return item ? item.quantity : 0;
};


// --- Manejo de subida de archivo ---
const onFileSelect = (event) => {
    form.proof_of_payment = event.files[0];
};
const onFileRemove = () => {
    form.proof_of_payment = null;
};

// --- Submit ---
const submit = () => {
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
            <header class="mb-6 flex justify-between items-center">
                <!-- ... (header sin cambios) ... -->
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">
                        {{ mode === 'upgrade' ? 'Mejorar suscripción' : 'Renovar suscripción' }}
                    </h1>
                    <p v-if="mode === 'upgrade'" class="text-gray-500 dark:text-gray-400 mt-1">
                        Añade módulos o incrementa los límites de tu plan actual.
                    </p>
                    <p v-else class="text-gray-500 dark:text-gray-400 mt-1">
                        Tu plan ha vencido o está por vencer. Selecciona tus módulos y límites para el nuevo periodo.
                    </p>
                </div>
            </header>

            <!-- ... (Mensajes de Reintento y Pago Pendiente sin cambios) ... -->
             <Message v-if="isRetry" severity="warn" :closable="false" class="mb-6">
                Tu pago anterior fue rechazado. Por favor, verifica tus items y vuelve a enviar el comprobante de pago.
            </Message>
             <Message v-if="hasPendingPayment" severity="info" :closable="false" class="mb-6">
                Ya tienes un pago pendiente de aprobación. No puedes realizar una nueva solicitud hasta que se procese.
            </Message>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Selección -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- ... (Selector de Periodo, Módulos, Límites sin cambios) ... -->
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

                    <Card>
                        <template #title>Módulos</template>
                        <template #content>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div v-for="module in availableModules" :key="module.key"
                                    class="border dark:border-gray-700 rounded-lg p-4 flex items-center gap-4 transition-all"
                                    :class="{ 'bg-gray-50 dark:bg-gray-700/30': selectedModules.includes(module.key) }">
                                    <Checkbox v-model="selectedModules" :inputId="module.key" :value="module.key"
                                        :disabled="(mode === 'upgrade' && activeItemKeys.has(module.key)) || hasPendingPayment" />
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
                                        :min="getMinLimit(limit.key)"
                                        :step="limit.key === 'limit_products' ? 50 : 1" showButtons
                                        buttonLayout="horizontal" decrementButtonClass="p-button-secondary"
                                        incrementButtonClass="p-button-secondary" incrementButtonIcon="pi pi-plus"
                                        decrementButtonIcon="pi pi-minus"
                                        :inputStyle="{width: '5rem', textAlign: 'center'}"
                                        :disabled="hasPendingPayment"
                                        />
                                </div>
                            </div>
                        </template>
                    </Card>
                </div>

                <!-- Columna de Resumen -->
                <div class="lg:col-span-1">
                    <Card class="sticky top-24">
                        <template #title>Resumen de pago</template>
                        <template #content>
                            <div class="space-y-4">
                                <!-- ... (Resumen de pago sin cambios) ... -->
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
                                    <!-- ... (itemsForSummary loop sin cambios) ... -->
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

                                <!-- --- INICIO SECCIÓN DE PAGO --- -->
                                <Divider />
                                <h5 class="font-semibold text-gray-800 dark:text-gray-200 pt-2">Método de pago</h5>

                                <SelectButton v-model="form.payment_method"
                                    :options="[{ label: 'Transferencia Bancaria', value: 'transferencia' }, { label: 'Tarjeta (Próximamente)', value: 'card' }]"
                                    optionLabel="label" optionValue="value" aria-labelledby="payment-method"
                                    class="w-full" :disabled="true" />

                                <!-- Detalles para Transferencia -->
                                <div v-if="form.payment_method === 'transferencia'" class="mt-4 space-y-4">
                                    <Message severity="info" :closable="false">
                                        Realiza tu pago a cualquiera de las siguientes cuentas y sube tu comprobante.
                                    </Message>

                                    <!-- Cuentas Bancarias del Admin -->
                                    <Accordion :activeIndex="0">
                                        <!-- ... (loop ourBankAccounts sin cambios) ... -->
                                        <AccordionPanel v-for="account in ourBankAccounts" :key="account.id">
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

                                    <!-- --- INICIO: REGISTRO DE GASTO (NUEVO) --- -->
                                    <div class="space-y-4 border-t dark:border-gray-700 pt-4">
                                        <h5 class="font-semibold text-sm">Registrar Gasto (Opcional)</h5>
                                        <p class="text-xs text-gray-500 -mt-3">
                                            Selecciona la cuenta de la que transferiste para registrar el gasto
                                            automáticamente.
                                        </p>

                                        <!-- Selector de Cuenta Bancaria del Usuario -->
                                        <div class="flex flex-col gap-2">
                                            <label for="bank_account_id" class="text-sm font-medium">Cuenta de
                                                origen</label>
                                            <Select v-model="form.bank_account_id" :options="userBankAccounts"
                                                optionLabel="account_name" optionValue="id"
                                                placeholder="Selecciona una cuenta" class="w-full" showClear
                                                :invalid="!!form.errors.bank_account_id"
                                                :disabled="hasPendingPayment">
                                                <template #option="{ option }">
                                                    <div class="flex flex-col">
                                                        <span>{{ option.account_name }}</span>
                                                        <small class="text-gray-500">{{ option.bank_name }}</small>
                                                    </div>
                                                </template>
                                            </Select>
                                            <InputError :message="form.errors.bank_account_id" />
                                        </div>

                                        <!-- Selector de Categoría de Gasto (condicional) -->
                                        <div v-if="form.bank_account_id" class="flex flex-col gap-2">
                                            <label for="expense_category_id" class="text-sm font-medium">Categoría del
                                                gasto *</label>
                                            <Select v-model="form.expense_category_id" :options="expenseCategories"
                                                optionLabel="name" optionValue="id"
                                                placeholder="Selecciona una categoría" class="w-full"
                                                :invalid="!!form.errors.expense_category_id"
                                                :disabled="hasPendingPayment" />
                                            <InputError :message="form.errors.expense_category_id" />
                                        </div>
                                    </div>
                                    <!-- --- FIN: REGISTRO DE GASTO (NUEVO) --- -->


                                    <!-- Subida de Comprobante -->
                                    <div class="border-t dark:border-gray-700 pt-4">
                                        <h5 class="font-semibold mb-2 text-sm">Sube tu comprobante*</h5>
                                        <FileUpload name="proof_of_payment" @select="onFileSelect"
                                            @remove="onFileRemove" :showUploadButton="false" :showCancelButton="false"
                                            :multiple="false" accept="image/*,application/pdf" :maxFileSize="10000000"
                                            :disabled="hasPendingPayment">
                                            <template #empty>
                                                <p class="text-sm text-center text-gray-500 p-4">Arrastra y suelta tu
                                                    archivo (PDF, JPG, PNG).</p>
                                            </template>
                                        </FileUpload>
                                        <InputError :message="form.errors.proof_of_payment" />
                                    </div>
                                </div>
                                <!-- --- FIN SECCIÓN DE PAGO --- -->


                                <!-- ... (Mensaje de Tarjeta sin cambios) ... -->
                                <div v-if="form.payment_method === 'card'" class="mt-4">
                                    <Message severity="warn" :closable="false">
                                        El pago con tarjeta estará disponible próximamente.
                                    </Message>
                                </div>

                                <!-- ... (Botón de Revertir sin cambios) ... -->
                                <Button v-if="isRetry && !hasPendingPayment" @click="confirmRevert" label="Cancelar y volver al plan anterior"
                                    severity="danger" outlined class="w-full mt-2" />

                                <Button @click="submit"
                                    :disabled="form.items.length === 0 || form.processing || form.total_amount <= 0 || (form.payment_method === 'transferencia' && !form.proof_of_payment) || form.payment_method === 'card' || hasPendingPayment || (form.bank_account_id && !form.expense_category_id)"
                                    :loading="form.processing"
                                    :label="isRetry ? 'Reintentar pago' : (mode === 'renew' ? 'Confirmar y pagar' : 'Enviar comprobante')"
                                    class="w-full mt-2"
                                    v-tooltip.bottom="(form.bank_account_id && !form.expense_category_id) ? 'Debes seleccionar una categoría de gasto si seleccionaste una cuenta' : ''"
                                    />
                            </div>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

