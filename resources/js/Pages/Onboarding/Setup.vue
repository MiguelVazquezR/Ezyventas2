<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLogo from '@/Components/AuthenticationCardLogo.vue';
import Step1BusinessInfo from './Partials/Step1BusinessInfo.vue';
import Step2Limits from './Partials/Step2Limits.vue';
import Step3BankAccounts from './Partials/Step3BankAccounts.vue';
import HoursModal from './Partials/HoursModal.vue';

// --- Props ---
const props = defineProps({
    subscription: Object,
    currentLimits: Object,
    availableModules: Array,
    availableLimits: Array,
    activeModuleKeys: Array,
});

// --- State ---
const page = usePage();

const initialStep = sessionStorage.getItem('onboardingStep') ? parseInt(sessionStorage.getItem('onboardingStep')) : 0;
const activeStep = ref(initialStep);

watch(activeStep, (newStep) => {
    sessionStorage.setItem('onboardingStep', newStep);
});

const saving = ref(false);
const hoursModalVisible = ref(false);
const currentBranchIndex = ref(null);

const daysOfWeek = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

// --- Helpers ---
const createDefaultHours = () => {
    return daysOfWeek.map(day => ({
        day,
        open: false,
        from: '09:00',
        to: '18:00',
    }));
};

const getInitialAddress = (addr) => {
    if (!addr) return '';
    if (typeof addr === 'object') return addr.text || addr.line1 || '';
    return addr;
};

// --- Form ---
const form = useForm({
    subscription: {
        commercial_name: props.subscription.commercial_name,
        business_name: props.subscription.business_name || '',
        contact_phone: props.subscription.contact_phone || '',
        address: getInitialAddress(props.subscription.address),
    },
    branches: props.subscription.branches.map(branch => ({
        ...branch,
        name: (branch.name || '').replace('Sucursal ', ''),
        address: branch.address || '',
        operating_hours: (Array.isArray(branch.operating_hours) && branch.operating_hours.length === 7)
            ? branch.operating_hours
            : createDefaultHours(),
    })),
    limits: {
        limit_users: Math.max(1, props.currentLimits?.limit_users?.quantity ?? 5),
        limit_cash_registers: Math.max(1, props.currentLimits?.limit_cash_registers?.quantity ?? 3),
        limit_products: Math.max(1200, props.currentLimits?.limit_products?.quantity ?? 1100),
        limit_services: Math.max(100, props.currentLimits?.limit_services?.quantity ?? 100),
        limit_print_templates: Math.max(2, props.currentLimits?.limit_print_templates?.quantity ?? 2),
    },
    modules: [
        ...new Set([
            'module_ai_agent',
            ...props.activeModuleKeys,
        ]),
    ],
    bank_accounts: props.subscription.bank_accounts.map(account => ({
        ...account,
        balance: parseFloat(account.balance) || 0.00,
        branch_ids: account.branches ? account.branches.map(b => b.id) : [],
    })),
});

// --- Branch options for MultiSelect ---
const branchOptions = computed(() => {
    return form.branches.map((b, index) => {
        if (!b.id) b.id = `temp_${index}`;
        return {
            label: b.name ? `Sucursal ${b.name}` : 'Nueva Sucursal',
            value: b.id,
        };
    });
});

// --- Branch CRUD ---
const addBranch = () => {
    form.branches.push({
        id: null, name: '', contact_phone: '', contact_email: '',
        is_main: false, address: '', operating_hours: createDefaultHours(),
    });
};

const removeBranch = (index) => {
    if (form.branches.length <= 1) {
        alert('Debes tener al menos una sucursal.');
        return;
    }
    form.branches.splice(index, 1);
};

const setMainBranch = (indexToSet) => {
    form.branches.forEach((branch, index) => {
        branch.is_main = (index === indexToSet);
    });
};

// --- Bank account CRUD ---
const addBankAccount = () => {
    form.bank_accounts.push({
        id: null, bank_name: '', owner_name: '', account_name: '',
        balance: 0.00, account_number: '', clabe: '', branch_ids: [],
    });
};

const removeBankAccount = (index) => {
    form.bank_accounts.splice(index, 1);
};

// --- Hours modal ---
const openHoursModal = (index) => {
    currentBranchIndex.value = index;
    hoursModalVisible.value = true;
};

// --- Step actions ---
const saveStep = (step, nextStep = true) => {
    saving.value = true;
    let routeName, data;

    if (step === 0) {
        routeName = route('onboarding.store.step1');
        data = {
            subscription: form.subscription,
            branches: form.branches.map(b => ({
                ...b,
                id: (b.id && b.id.toString().startsWith('temp_')) ? null : b.id,
                name: b.name,
            })),
        };
    } else if (step === 1) {
        routeName = route('onboarding.store.step2');
        data = { limits: form.limits, modules: form.modules };
    }

    form.post(routeName, {
        data,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { if (nextStep) activeStep.value++; },
        onError: (err) => { console.log(err); },
        onFinish: () => { saving.value = false; },
    });
};

const finishOnboarding = () => {
    saving.value = true;
    form.post(route('onboarding.finish'), {
        data: { bank_accounts: form.bank_accounts },
        preserveScroll: true,
        onSuccess: () => { sessionStorage.removeItem('onboardingStep'); },
        onError: (err) => { console.error('Error al finalizar onboarding:', err); },
        onFinish: () => { saving.value = false; },
    });
};

// --- Current branch hours for modal ---
const currentBranchHours = computed(() => {
    if (currentBranchIndex.value === null) return null;
    return form.branches[currentBranchIndex.value]?.operating_hours ?? null;
});
</script>

<template>
    <Head title="Configuración Inicial" />

    <div class="min-h-screen bg-gray-100 dark:bg-[#1a1a1a] flex items-center justify-center p-4">
        <div class="w-full max-w-4xl bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] shadow-2xl overflow-hidden">

            <!-- Header -->
            <div class="px-6 pt-6 pb-4 text-center border-b border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a]">
                <AppLogo class="h-9 w-auto mx-auto mb-3" />
                <h1 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    ¡Bienvenido, {{ page.props.auth.user.name }}!
                </h1>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                    Configura tu negocio en 3 pasos
                </p>
            </div>

            <!-- Stepper -->
            <div class="px-5 pt-5">
                <Stepper v-model:value="activeStep" linear>
                    <StepList>
                        <Step v-slot="{ activateCallback, value, a11yAttrs }" asChild :value="0">
                            <div class="flex flex-row flex-auto gap-2" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 bg-transparent border-0 cursor-pointer"
                                    @click="activateCallback(0)" v-bind="a11yAttrs.header">
                                    <span
                                        :class="['rounded-full size-7 flex items-center justify-center text-[11px] font-bold transition-all duration-300',
                                            value <= activeStep
                                                ? 'bg-primary-500 text-white shadow-[0_0_10px_rgba(59,130,246,0.4)]'
                                                : 'border-2 border-gray-200 dark:border-[#3a3a3a] text-gray-400'
                                        ]">
                                        {{ value <= activeStep ? '✓' : 1 }}
                                    </span>
                                    <span class="text-[11px] uppercase tracking-widest font-bold"
                                        :class="value <= activeStep ? 'text-gray-900 dark:text-white' : 'text-gray-400'">
                                        Negocio y sucursales
                                    </span>
                                </button>
                                <Divider />
                            </div>
                        </Step>
                        <Step v-slot="{ activateCallback, value, a11yAttrs }" asChild :value="1">
                            <div class="flex flex-row flex-auto gap-2" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 bg-transparent border-0 cursor-pointer"
                                    @click="activateCallback(1)" v-bind="a11yAttrs.header">
                                    <span
                                        :class="['rounded-full size-7 flex items-center justify-center text-[11px] font-bold transition-all duration-300',
                                            value <= activeStep
                                                ? 'bg-primary-500 text-white shadow-[0_0_10px_rgba(59,130,246,0.4)]'
                                                : 'border-2 border-gray-200 dark:border-[#3a3a3a] text-gray-400'
                                        ]">
                                        {{ value <= activeStep ? '✓' : 2 }}
                                    </span>
                                    <span class="text-[11px] uppercase tracking-widest font-bold"
                                        :class="value <= activeStep ? 'text-gray-900 dark:text-white' : 'text-gray-400'">
                                        Funciones en suscripción
                                    </span>
                                </button>
                                <Divider />
                            </div>
                        </Step>
                        <Step v-slot="{ activateCallback, value, a11yAttrs }" asChild :value="2">
                            <div class="flex flex-row flex-auto gap-2" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 bg-transparent border-0 cursor-pointer"
                                    @click="activateCallback(2)" v-bind="a11yAttrs.header">
                                    <span
                                        :class="['rounded-full size-7 flex items-center justify-center text-[11px] font-bold transition-all duration-300',
                                            value <= activeStep
                                                ? 'bg-primary-500 text-white shadow-[0_0_10px_rgba(59,130,246,0.4)]'
                                                : 'border-2 border-gray-200 dark:border-[#3a3a3a] text-gray-400'
                                        ]">
                                        {{ value <= activeStep ? '✓' : 3 }}
                                    </span>
                                    <span class="text-[11px] uppercase tracking-widest font-bold"
                                        :class="value <= activeStep ? 'text-gray-900 dark:text-white' : 'text-gray-400'">
                                        Cuentas bancarias
                                    </span>
                                </button>
                            </div>
                        </Step>
                    </StepList>

                    <StepPanels>
                        <!-- PASO 1 -->
                        <StepPanel :value="0">
                            <Step1BusinessInfo
                                :form="form"
                                :saving="saving"
                                @add-branch="addBranch"
                                @remove-branch="removeBranch"
                                @set-main-branch="setMainBranch"
                                @open-hours="openHoursModal"
                                @save-step="saveStep"
                            />
                        </StepPanel>

                        <!-- PASO 2 -->
                        <StepPanel :value="1">
                            <Step2Limits
                                :form="form"
                                :saving="saving"
                                :available-modules="availableModules"
                                :available-limits="availableLimits"
                                @save-step="saveStep"
                                @go-back="activeStep = 0"
                            />
                        </StepPanel>

                        <!-- PASO 3 -->
                        <StepPanel :value="2">
                            <Step3BankAccounts
                                :form="form"
                                :branch-options="branchOptions"
                                :saving="saving"
                                @add-account="addBankAccount"
                                @remove-account="removeBankAccount"
                                @finish="finishOnboarding"
                                @go-back="activeStep = 1"
                            />
                        </StepPanel>
                    </StepPanels>
                </Stepper>
            </div>
        </div>
    </div>

    <!-- Modal de Horarios -->
    <HoursModal
        v-model:visible="hoursModalVisible"
        :operating-hours="currentBranchHours"
    />
</template>