<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLogo from '@/Components/AuthenticationCardLogo.vue';
import { AI_MODULE_KEY, FREE_MODULE_KEYS } from '@/constants/modules';
import Step1BusinessInfo from './Partials/Step1BusinessInfo.vue';
import Step2Limits from './Partials/Step2Limits.vue';
import HoursModal from './Partials/HoursModal.vue';
import WelcomeQuick from './Partials/WelcomeQuick.vue';

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

const parsedStep = parseInt(sessionStorage.getItem('onboardingStep') ?? '0', 10) || 0;
const activeStep = ref(Math.min(parsedStep, 1));

// Vista inicial: la pantalla de bienvenida (rápida). Si el usuario ya venía
// avanzando en el wizard, lo reanudamos directamente.
const view = ref(parsedStep > 0 ? 'wizard' : 'welcome');

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

// --- AI agent module: included while its plan item stays free ---
const aiModule = computed(() => {
    return (props.availableModules || []).find(m => m.key === AI_MODULE_KEY) || null;
});

const aiAgentIsFree = !!(aiModule.value && (parseFloat(aiModule.value.monthly_price) || 0) <= 0);

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
    // Additional modules start disabled; only the essential plan arrives
    // active, and the AI agent joins only while its plan item stays free.
    modules: [
        ...new Set([
            ...(aiAgentIsFree ? [AI_MODULE_KEY] : []),
            ...props.activeModuleKeys.filter(key => FREE_MODULE_KEYS.includes(key)),
        ]),
    ],
});

// --- Branch CRUD ---
const addBranch = () => {
    form.branches.push({
        id: null, name: '', contact_phone: '', contact_email: '',
        is_main: false, address: '', operating_hours: createDefaultHours(),
    });
};

const removeBranch = (index) => {
    if (form.branches.length <= 1) return;
    form.branches.splice(index, 1);
};

const setMainBranch = (indexToSet) => {
    form.branches.forEach((branch, index) => {
        branch.is_main = (index === indexToSet);
    });
};

// --- Hours modal ---
const openHoursModal = (index) => {
    currentBranchIndex.value = index;
    hoursModalVisible.value = true;
};

// --- Step actions ---
const saveStep1 = () => {
    saving.value = true;

    form.post(route('onboarding.store.step1'), {
        data: {
            subscription: form.subscription,
            branches: form.branches.map(b => ({
                ...b,
                id: (b.id && b.id.toString().startsWith('temp_')) ? null : b.id,
                name: b.name,
            })),
        },
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { activeStep.value = 1; },
        onError: (err) => { console.log(err); },
        onFinish: () => { saving.value = false; },
    });
};

// Clicking a step navigates directly: back is free, forward saves step 1 first.
const goToStep = (step) => {
    if (saving.value || form.processing || step === activeStep.value) return;

    if (step < activeStep.value) {
        activeStep.value = step;
        return;
    }

    saveStep1();
};

const finishOnboarding = () => {
    saving.value = true;
    form.post(route('onboarding.finish'), {
        data: { limits: form.limits, modules: form.modules },
        preserveScroll: true,
        onSuccess: () => { sessionStorage.removeItem('onboardingStep'); },
        onError: (err) => { console.error('Error al finalizar onboarding:', err); },
        onFinish: () => { saving.value = false; },
    });
};

// Omite la configuración: conserva los valores por defecto creados al
// registrarse (plan básico, sucursal principal) y entra al dashboard.
const skipForm = useForm({});
const skipping = ref(false);

const skipOnboarding = () => {
    if (skipping.value || saving.value || form.processing) return;
    skipping.value = true;
    skipForm.post(route('onboarding.skip'), {
        preserveScroll: true,
        onSuccess: () => { sessionStorage.removeItem('onboardingStep'); },
        onFinish: () => { skipping.value = false; },
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

        <!-- Pantalla de bienvenida (rápida — por defecto) -->
        <WelcomeQuick
            v-if="view === 'welcome'"
            :subscription="subscription"
            :user-name="page.props.auth.user.name"
            :ai-module="aiModule"
            @start-wizard="view = 'wizard'"
        />

        <!-- Wizard de configuración detallada (opcional) -->
        <div v-else class="w-full max-w-4xl bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] shadow-2xl overflow-hidden">

            <!-- Header -->
            <div class="px-6 pt-6 pb-4 text-center border-b border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] relative">
                <div class="absolute top-5 right-6">
                    <Button label="Omitir por ahora" icon="pi pi-forward" text
                        @click="skipOnboarding" :loading="skipping"
                        class="!rounded-full !text-[10px] !uppercase !tracking-wider !text-primary-500 hover:!bg-primary-500/10 hover:!text-primary-600 dark:!text-primary-400 dark:hover:!text-primary-300" />
                </div>
                <AppLogo class="h-14 w-auto mx-auto mb-3" />
                <h1 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    Configura tu negocio
                </h1>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                    Opcional — 2 pasos, puedes completarlos después
                </p>
            </div>

            <!-- Stepper -->
            <div class="px-5 pt-5">
                <Stepper v-model:value="activeStep">
                    <StepList>
                        <Step v-slot="{ value, a11yAttrs }" asChild :value="0">
                            <div class="flex flex-1 flex-row items-center justify-end gap-1" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 pr-3 bg-transparent border-0 cursor-pointer rounded-full transition-colors hover:bg-gray-100 dark:hover:bg-[#2a2a2a]"
                                    @click="goToStep(0)" v-bind="a11yAttrs.header">
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
                                        Tu negocio
                                    </span>
                                </button>
                                <i class="pi pi-angle-right !text-[10px] text-gray-300 dark:text-[#4a4a4a]" aria-hidden="true"></i>
                            </div>
                        </Step>
                        <Step v-slot="{ value, a11yAttrs }" asChild :value="1">
                            <div class="flex flex-1 flex-row items-center justify-start gap-1" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 pr-3 bg-transparent border-0 cursor-pointer rounded-full transition-colors hover:bg-gray-100 dark:hover:bg-[#2a2a2a]"
                                    @click="goToStep(1)" v-bind="a11yAttrs.header">
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
                                        Tus módulos
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
                                @save-step="saveStep1"
                            />
                        </StepPanel>

                        <!-- PASO 2 -->
                        <StepPanel :value="1">
                            <Step2Limits
                                :form="form"
                                :saving="saving"
                                :available-modules="availableModules"
                                :available-limits="availableLimits"
                                @finish="finishOnboarding"
                                @go-back="activeStep = 0"
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