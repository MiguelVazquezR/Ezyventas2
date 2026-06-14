<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import InputError from '@/Components/InputError.vue';

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
    isFirstPayment: Boolean,
    referrerActiveDiscountPct: Number,
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
    bank_account_id: null,
    expense_category_id: null,
    referral_code: '',
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

// Separar módulos incluidos (module_pos + precio $0) de los de pago
const includedModules = computed(() =>
    availableModules.value.filter(m => m.key === 'module_pos' || parseFloat(m.monthly_price) === 0)
);

const paidModules = computed(() =>
    availableModules.value.filter(m => m.key !== 'module_pos' && parseFloat(m.monthly_price) > 0)
);

// --- Lógica de Límites Mínimos Inteligente ---
const getMinLimit = (limitKey) => {
    // Valores mínimos base por defecto del sistema
    const baseMins = {
        'limit_branches': 1,
        'limit_users': 1,
        'limit_products': 100,
        'limit_services': 100 // NUEVO MÍNIMO
    };
    const baseMin = baseMins[limitKey] || 0;

    // Si estamos en modo renovación, permitimos bajar al mínimo absoluto permitido
    if (props.mode === 'renew') {
        return baseMin;
    }

    // Si es modo 'upgrade', no se puede bajar de la cantidad actual contratada.
    const baseVersion = versionToCompare.value;
    if (!baseVersion) {
        return baseMin;
    }
    
    const item = baseVersion.items.find(i => i.item_key === limitKey);
    const currentQuantity = item ? item.quantity : 0;
    
    return Math.max(baseMin, currentQuantity);
};

// --- Inicialización del Estado ---
onMounted(() => {
    if (props.currentVersion) {
        const currentModules = props.currentVersion.items
            .filter(item => item.item_type === 'module')
            .map(item => item.item_key);
        selectedModules.value = currentModules;

        allLimitItems.value.forEach(limit => {
            const currentItem = props.currentVersion.items.find(item => item.item_key === limit.key);
            // Aseguramos que respete el mínimo siempre
            limitValues.value[limit.key] = currentItem 
                ? Math.max(currentItem.quantity, getMinLimit(limit.key)) 
                : getMinLimit(limit.key);
        });
    } else {
        allLimitItems.value.forEach(limit => {
            limitValues.value[limit.key] = getMinLimit(limit.key);
        });
        selectedModules.value = [
            'module_pos', 'module_financial_reports', 'module_transactions',
            'module_products', 'module_expenses', 'module_customers',
            'module_services', 'module_cash_registers', 'module_settings' // Módulos default
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
    if (props.mode !== 'upgrade' || !versionToCompare.value) return 0;
    const endDate = new Date(versionToCompare.value.end_date);
    const today = new Date();
    return Math.max(0, Math.ceil((endDate - today) / (1000 * 60 * 60 * 24)));
});

const totalDaysInPeriod = computed(() => {
    if (props.mode !== 'upgrade' || !versionToCompare.value) return 365;
    return props.currentBillingPeriod === 'anual' ? 365 : 30;
});

// --- Sincronización de Formulario y UI ---
watch([selectedModules, limitValues, billingPeriod], () => {
    form.billing_period = billingPeriod.value;

    // Asegurar que los módulos incluidos siempre estén seleccionados
    const includedKeys = includedModules.value.map(m => m.key);
    includedKeys.forEach(key => {
        if (!selectedModules.value.includes(key)) {
            selectedModules.value.push(key);
        }
    });

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
        // Saltar límites que dependen de un módulo que no está activo
        if (limitItem.key === 'limit_services' && !selectedModules.value.includes('module_services')) {
            limitValues.value[limitItem.key] = getMinLimit(limitItem.key);
            return;
        }

        const newQuantity = limitValues.value[limitItem.key] || 0;
        if (newQuantity <= 0) return;

        newItems.push({ key: limitItem.key, quantity: newQuantity });
        const unitPricePerPackage = getPrice(limitItem);
        const pricePerUnit = unitPricePerPackage / (limitItem.meta?.quantity || 1);

        if (props.mode === 'upgrade') {
            const currentItem = versionToCompare.value?.items.find(item => item.item_key === limitItem.key);
            const currentQuantity = currentItem ? currentItem.quantity : 0;
            const addedQuantity = newQuantity - currentQuantity;

            if (addedQuantity > 0) {
                const dailyPricePerUnit = (props.currentBillingPeriod === 'anual'
                    ? (limitItem.monthly_price * 10) / (limitItem.meta?.quantity || 1)
                    : limitItem.monthly_price / (limitItem.meta?.quantity || 1)) / totalDaysInPeriod.value;

                totalCost += (dailyPricePerUnit * remainingDays.value * addedQuantity);
            }
        } else {
            totalCost += (pricePerUnit * newQuantity);
        }
    });

    form.items = newItems;
    form.total_amount = totalCost;

}, { deep: true, immediate: true });

watch(() => form.bank_account_id, (newVal) => {
    if (!newVal) {
        form.expense_category_id = null;
    }
});

// Resumen de items con subtotales
const itemsForSummary = computed(() => {
    const summary = [];
    const currentItemsMap = new Map(versionToCompare.value?.items.map(i => [i.item_key, i.quantity]) || []);
    const isUpgrade = props.mode === 'upgrade';

    form.items.forEach(item => {
        const planItem = props.allPlanItems.find(i => i.key === item.key);
        if (!planItem) return;

        const currentQuantity = currentItemsMap.get(item.key) || 0;
        const newQuantity = item.quantity;
        const displayQuantity = isUpgrade ? newQuantity - currentQuantity : newQuantity;

        if (displayQuantity <= 0) return;

        let subtotal = 0;

        if (planItem.type === 'module') {
            if (isUpgrade && currentQuantity > 0) return;
            subtotal = getPrice(planItem);
        } else {
            const unitPricePerPackage = getPrice(planItem);
            const pricePerUnit = unitPricePerPackage / (planItem.meta?.quantity || 1);
            subtotal = pricePerUnit * displayQuantity;
        }

        summary.push({
            key: item.key,
            name: planItem.name,
            quantity: displayQuantity,
            subtotal,
            planItem,
        });
    });
    return summary;
});

const confirmRevert = () => {
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

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

// --- Cálculos de descuento por referido ---
const referrerDiscountAmount = computed(() => {
    if (!props.referrerActiveDiscountPct || props.referrerActiveDiscountPct <= 0) return 0;
    return form.total_amount * (props.referrerActiveDiscountPct / 100);
});

const totalAfterReferrerDiscount = computed(() => {
    return form.total_amount - referrerDiscountAmount.value;
});

const referralDiscountAmount = computed(() => {
    if (!codeValidation.value?.valid || !codeValidation.value?.discount_pct) return 0;
    return form.total_amount * (codeValidation.value.discount_pct / 100);
});

const finalAmountWithReferral = computed(() => {
    return form.total_amount - referralDiscountAmount.value - referrerDiscountAmount.value;
});

const onFileSelect = (event) => {
    form.proof_of_payment = event.files[0];
};
const onFileRemove = () => {
    form.proof_of_payment = null;
};

// --- Validación de código de referido en tiempo real ---
const validatingCode = ref(false);
const codeValidation = ref(null); // { valid: bool, message: string, discount_pct: number }

let validateTimer = null;
watch(() => form.referral_code, (newCode) => {
    clearTimeout(validateTimer);
    codeValidation.value = null;

    const trimmed = (newCode || '').trim();
    if (trimmed.length < 6) {
        return;
    }

    validatingCode.value = true;
    validateTimer = setTimeout(() => {
        fetch(route('referrals.validate', { code: trimmed }))
            .then(r => r.json())
            .then(data => {
                codeValidation.value = data;
            })
            .catch(() => {
                codeValidation.value = { valid: false, message: 'Error al validar el código.' };
            })
            .finally(() => {
                validatingCode.value = false;
            });
    }, 500);
});

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

             <Message v-if="isRetry" severity="warn" :closable="false" class="mb-6">
                Tu pago anterior fue rechazado. Por favor, verifica tus items y vuelve a enviar el comprobante de pago.
            </Message>
             <Message v-if="hasPendingPayment" severity="info" :closable="false" class="mb-6">
                Ya tienes un pago pendiente de aprobación. No puedes realizar una nueva solicitud hasta que se procese.
            </Message>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna de Selección -->
                <div class="lg:col-span-2 space-y-6">

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
                            <!-- Módulos incluidos (siempre activos, sin checkbox) -->
                            <div v-if="includedModules.length > 0" class="mb-6">
                                <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-3">Incluidos en plan básico</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <div v-for="module in includedModules" :key="module.key"
                                        class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-xl p-3 flex items-center gap-3 opacity-70">
                                        <div class="w-8 h-8 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                                            <i :class="module.meta?.icon" class="!text-sm text-green-600"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ module.name }}</p>
                                            <p class="text-[9px] font-bold text-green-600 uppercase tracking-widest m-0 mt-0.5">Incluido</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Módulos adicionales (con checkbox para contratar) -->
                            <div v-if="paidModules.length > 0">
                                <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-3">Módulos adicionales</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div v-for="module in paidModules" :key="module.key"
                                        class="border dark:border-gray-700 rounded-lg p-4 flex items-center gap-4 transition-all"
                                        :class="{ 'bg-gray-50 dark:bg-gray-700/30': selectedModules.includes(module.key) }">
                                        <Checkbox v-model="selectedModules" :inputId="module.key" :value="module.key"
                                            :disabled="(mode === 'upgrade' && activeItemKeys.has(module.key)) || hasPendingPayment" />
                                        <label :for="module.key" class="flex-grow cursor-pointer">
                                            <div class="flex items-center gap-2">
                                                <i :class="module.meta?.icon" class="text-orange-500"></i>
                                                <span class="font-semibold">{{ module.name }}</span>
                                            </div>
                                            <p class="text-xs text-gray-500">{{ formatCurrency(getPrice(module)) }}/{{
                                                billingPeriod === 'anual' ? 'año' : 'mes' }}</p>
                                        </label>
                                    </div>
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
                                        <!-- NUEVA LÓGICA DE TEXTO DE PRECIOS: Se lee mucho mejor -->
                                        <p class="text-xs text-gray-500 m-0">
                                            {{ formatCurrency(getPrice(limit)) }} por {{ (limit.meta && limit.meta.quantity > 1) ? `cada ${limit.meta.quantity}` : 'c/u' }} / {{ billingPeriod === 'anual' ? 'año' : 'mes' }}
                                        </p>
                                    </div>
                                    <!-- APLICANDO EL STEP CORRESPONDIENTE A PRODUCTOS (100) Y SERVICIOS (100) -->
                                    <InputNumber v-if="limit.key !== 'limit_services' || (limit.key === 'limit_services' && selectedModules.includes('module_services'))" v-model="limitValues[limit.key]"
                                        :min="getMinLimit(limit.key)"
                                        :step="limit.key === 'limit_products' ? 100 : (limit.key === 'limit_services' ? 100 : 1)" 
                                        showButtons
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
                                    <div v-for="item in itemsForSummary" :key="item.key" class="flex justify-between items-center">
                                        <div class="flex flex-col min-w-0">
                                            <span class="truncate font-medium text-gray-900 dark:text-white">
                                                {{ item.name }}
                                                <span v-if="item.quantity > 1 && allLimitItems.some(l => l.key === item.key)" class="text-gray-500 font-normal">
                                                    (x{{ item.quantity }})
                                                </span>
                                            </span>
                                            <span v-if="parseFloat(item.planItem.monthly_price) === 0" class="text-[10px] text-green-600 font-medium">Incluido</span>
                                        </div>
                                        <span class="font-mono text-xs text-gray-600 dark:text-gray-400 ml-2 shrink-0">{{ formatCurrency(item.subtotal) }}</span>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-center text-gray-500 py-4">
                                    {{ mode === 'upgrade' ? 'No has añadido items nuevos.' : 'Selecciona tus items.' }}
                                </p>

                                <Divider />
                                <div class="flex justify-between items-center font-bold text-lg">
                                    <span>{{ mode === 'upgrade' ? 'Total a pagar hoy' : 'Total del periodo' }}:</span>
                                    <span :class="{ 'line-through text-gray-400 text-base font-normal': (billingPeriod === 'mensual' && referrerDiscountAmount > 0) || codeValidation?.valid }">{{ formatCurrency(form.total_amount) }}</span>
                                </div>

                                <!-- Desglose de descuento continuo por ser referidor -->
                                <div v-if="billingPeriod === 'mensual' && referrerDiscountAmount > 0" class="bg-purple-50 dark:bg-purple-900/10 border border-purple-100 dark:border-purple-900/30 rounded-2xl p-4 space-y-2">
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-purple-700 dark:text-purple-300">Descuento por referidos activos ({{ referrerActiveDiscountPct }}%)</span>
                                        <span class="text-purple-700 dark:text-purple-300 font-medium">-{{ formatCurrency(referrerDiscountAmount) }}</span>
                                    </div>
                                    <Divider class="!my-2" v-if="!codeValidation?.valid" />
                                    <div v-if="!codeValidation?.valid" class="flex justify-between items-center font-bold text-lg">
                                        <span class="text-purple-800 dark:text-purple-200">Total con descuento</span>
                                        <span class="text-purple-800 dark:text-purple-200">{{ formatCurrency(totalAfterReferrerDiscount) }}</span>
                                    </div>
                                </div>

                                <!-- Mensaje informativo: descuento solo en mensual -->
                                <div v-if="billingPeriod === 'anual' && referrerActiveDiscountPct > 0" class="bg-purple-50 dark:bg-purple-900/10 border border-purple-100 dark:border-purple-900/30 rounded-2xl p-4">
                                    <div class="flex items-start gap-3">
                                        <i class="pi pi-info-circle mt-0.5 !text-sm text-purple-500"></i>
                                        <div>
                                            <p class="text-xs font-medium text-purple-800 dark:text-purple-200 m-0">Descuento por referido no aplica en pago anual</p>
                                            <p class="text-[11px] text-purple-600 dark:text-purple-400 m-0 mt-1 leading-relaxed">
                                                Tienes un {{ referrerActiveDiscountPct }}% de descuento por tus referidos activos, pero solo está disponible en pagos mensuales, ya que tus referidos podrían no renovar mes con mes y perderías el beneficio. Cambia a pago mensual para aprovecharlo.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Desglose de descuento por referido -->
                                <div v-if="codeValidation?.valid && referralDiscountAmount > 0" class="bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/30 rounded-2xl p-4 space-y-2">
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-green-700 dark:text-green-300">Descuento por referido ({{ codeValidation.discount_pct }}%)</span>
                                        <span class="text-green-700 dark:text-green-300 font-medium">-{{ formatCurrency(referralDiscountAmount) }}</span>
                                    </div>
                                    <Divider class="!my-2" />
                                    <div class="flex justify-between items-center font-bold text-lg">
                                        <span class="text-green-800 dark:text-green-200">Total con descuento</span>
                                        <span class="text-green-800 dark:text-green-200">{{ formatCurrency(finalAmountWithReferral) }}</span>
                                    </div>
                                </div>

                                <!-- --- CAMPO DE CÓDIGO DE REFERIDO --- -->
                                <div v-if="isFirstPayment" class="mt-4 space-y-2">
                                    <Divider />
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">¿Tienes un código de referido?</label>
                                        <div class="relative">
                                            <InputText
                                                v-model="form.referral_code"
                                                placeholder="EZY-XXXXXX"
                                                maxlength="12"
                                                class="w-full"
                                                :disabled="hasPendingPayment"
                                                :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                                            <i v-if="validatingCode" class="pi pi-spin pi-spinner !text-sm text-gray-400 absolute right-3 top-1/2 -translate-y-1/2"></i>
                                        </div>
                                        <!-- Feedback de validación -->
                                        <div v-if="validatingCode" class="flex items-center gap-2 text-xs text-gray-500">
                                            <i class="pi pi-spin pi-spinner !text-xs"></i>
                                            <span>Verificando código...</span>
                                        </div>
                                        <div v-else-if="codeValidation" class="flex items-center gap-2 text-xs" :class="codeValidation.valid ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400'">
                                            <i :class="codeValidation.valid ? 'pi pi-check-circle !text-xs' : 'pi pi-times-circle !text-xs'"></i>
                                            <span>{{ codeValidation.message }}</span>
                                        </div>
                                        <InputError :message="form.errors.referral_code" />
                                    </div>
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
                                        <AccordionPanel v-for="account in ourBankAccounts" :key="account.id" :value="account.id">
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

                                    <!-- --- INICIO: REGISTRO DE GASTO --- -->
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

                                <div v-if="form.payment_method === 'card'" class="mt-4">
                                    <Message severity="warn" :closable="false">
                                        El pago con tarjeta estará disponible próximamente.
                                    </Message>
                                </div>

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