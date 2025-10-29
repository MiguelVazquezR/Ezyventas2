<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue'; // <-- AÑADIDO 'watch'
import AppLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

// --- Props ---
const props = defineProps({
    subscription: Object,
    currentLimits: Object,
});

// --- State ---
const page = usePage();

// --- INICIO: SOLUCIÓN para el paso activo ---
// 1. Leer el paso guardado desde sessionStorage o empezar en 0
const initialStep = sessionStorage.getItem('onboardingStep') ? parseInt(sessionStorage.getItem('onboardingStep')) : 0;
const activeStep = ref(initialStep);

// 2. Guardar cualquier cambio en el paso activo en sessionStorage
watch(activeStep, (newStep) => {
    sessionStorage.setItem('onboardingStep', newStep);
});
// --- FIN: SOLUCIÓN ---

const saving = ref(false);
const hoursModalVisible = ref(false);
const currentBranchIndex = ref(null);

const daysOfWeek = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

// --- Helper para Horarios ---
const createDefaultHours = () => {
    return daysOfWeek.map(day => ({
        day: day,
        open: false,
        from: '09:00',
        to: '18:00',
    }));
};

// --- Forms ---
const form = useForm({
    subscription: {
        commercial_name: props.subscription.commercial_name,
        business_name: props.subscription.business_name || '',
    },
    branches: props.subscription.branches.map(branch => ({
        ...branch,
        name: (branch.name || '').replace('Sucursal ', ''), // Quitar prefijo para editar
        address: branch.address || '', // Simplificado
        // Asegurar que operating_hours sea un array
        operating_hours: (Array.isArray(branch.operating_hours) && branch.operating_hours.length === 7)
            ? branch.operating_hours
            : createDefaultHours(),
    })),
    limits: {
        limit_users: props.currentLimits.limit_users.quantity,
        limit_cash_registers: props.currentLimits.limit_cash_registers.quantity,
        limit_products: props.currentLimits.limit_products.quantity,
        limit_print_templates: props.currentLimits.limit_print_templates.quantity,
    },
    bank_accounts: props.subscription.bank_accounts.map(account => ({
        ...account,
        balance: parseFloat(account.balance) || 0.00, // Añadir balance
        branch_ids: account.branches ? account.branches.map(b => b.id) : [],
    })),
});

// --- Opciones para Selects ---
const branchOptions = computed(() => {
    // Generar IDs temporales para sucursales nuevas para que el MultiSelect funcione
    return form.branches.map((b, index) => {
        if (!b.id) {
            b.id = `temp_${index}`;
        }
        return {
            label: b.name ? `Sucursal ${b.name}` : 'Nueva Sucursal',
            value: b.id,
        };
    });
});


// --- Funciones de Formulario ---
const addBranch = () => {
    // --- INICIO: CAMBIO - Límite de sucursales eliminado ---
    // const currentBranches = form.branches.length;
    // const limit = props.currentLimits.limit_branches.quantity;
    // if (currentBranches >= limit) {
    //     alert(`Has alcanzado tu límite de ${limit} sucursales.`);
    //     return;
    // }
    // --- FIN: CAMBIO ---

    form.branches.push({
        id: null,
        name: '',
        contact_phone: '',
        contact_email: '',
        is_main: false,
        address: '',
        operating_hours: createDefaultHours(),
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

const addBankAccount = () => {
    form.bank_accounts.push({
        id: null,
        bank_name: '',
        owner_name: '',
        account_name: '',
        balance: 0.00,
        account_number: '',
        clabe: '',
        branch_ids: [],
    });
};

const removeBankAccount = (index) => {
    form.bank_accounts.splice(index, 1);
};

// --- Funciones de Horario ---
const openHoursModal = (index) => {
    currentBranchIndex.value = index;
    hoursModalVisible.value = true;
};

const getHoursSummary = (hoursArray) => {
    if (!Array.isArray(hoursArray)) {
        return 'Horario no configurado';
    }
    const openDays = hoursArray.filter(d => d.open);
    if (openDays.length === 0) return 'Cerrado';
    if (openDays.length === 7) return 'Abierto todos los días';
    return `Abierto ${openDays.length} días`;
};

// --- Acciones de Pasos ---
const saveStep = (step, nextStep = true) => {
    saving.value = true;
    let routeName;
    let data;

    if (step === 0) {
        routeName = route('onboarding.store.step1');
        data = {
            subscription: form.subscription,
            branches: form.branches.map(b => ({
                ...b,
                id: (b.id && b.id.toString().startsWith('temp_')) ? null : b.id,
                name: b.name
            })),
        };
    } else if (step === 1) {
        routeName = route('onboarding.store.step2');
        data = { limits: form.limits };
    }
    // Paso 2 (Cuentas Bancarias) ahora se guarda al final

    form.post(routeName, {
        data: data,
        preserveScroll: true,
        preserveState: true, // Recargar props
        onSuccess: () => {
            if (nextStep) { activeStep.value++; }
        },
        onError: (err) => { console.log(err); },
        onFinish: () => { saving.value = false; }
    });
};

// --- CAMBIO: Lógica de Finalización (Una sola petición) ---
const finishOnboarding = () => {
    saving.value = true;
    // Se envía todo al endpoint 'finish'
    form.post(route('onboarding.finish'), {
        // Incluimos los datos de las cuentas bancarias aquí
        data: { bank_accounts: form.bank_accounts },
        preserveScroll: true, // Mantener scroll por si hay errores
        // preserveState: false // No es necesario recargar aquí, Inertia redirigirá
        onSuccess: () => {
            sessionStorage.removeItem('onboardingStep');
            // Inertia se encargará de redirigir al dashboard
        },
        onError: (err) => {
            console.error('Error al finalizar onboarding:', err);
            // Mostrar mensaje de error al usuario si es necesario
        },
        onFinish: () => {
            saving.value = false;
        }
    });
};

</script>

<template>

    <Head title="Configuración Inicial" />

    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 flex items-center justify-center p-4">
        <div class="w-full max-w-4xl bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">

            <div class="p-5 text-center border-b dark:border-gray-700">
                <AppLogo class="h-10 w-auto mx-auto mb-4" />
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 m-1">¡Bienvenido, {{
                    page.props.auth.user.name }}!</h1>
                <p class="text-gray-600 dark:text-gray-400">Vamos a configurar tu negocio en 3 simples pasos.</p>
            </div>

            <div class="p-4 sm:p-6">
                <!-- --- ESTRUCTURA DE STEPPER CON 'linear' --- -->
                <Stepper v-model:value="activeStep" linear>
                    <StepList>
                        <!-- PASO 1 HEADER -->
                        <Step v-slot="{ activateCallback, value, a11yAttrs }" asChild :value="0">
                            <div class="flex flex-row flex-auto gap-2" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 bg-transparent border-0"
                                    @click="activateCallback(0)" v-bind="a11yAttrs.header">
                                    <span
                                        :class="['rounded-full border-2 size-6 flex items-center justify-center', { 'bg-primary-500 border-primary-500 text-white': value <= activeStep, 'border-gray-300 dark:border-gray-700': value > activeStep }]">
                                        1
                                    </span>
                                    <span class="font-semibold">Negocio y sucursales</span>
                                </button>
                                <Divider />
                            </div>
                        </Step>

                        <!-- PASO 2 HEADER -->
                        <Step v-slot="{ activateCallback, value, a11yAttrs }" asChild :value="1">
                            <div class="flex flex-row flex-auto gap-2" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 bg-transparent border-0"
                                    @click="activateCallback(1)" v-bind="a11yAttrs.header">
                                    <span
                                        :class="['rounded-full border-2 size-6 flex items-center justify-center', { 'bg-primary-500 border-primary-500 text-white': value <= activeStep, 'border-gray-300 dark:border-gray-700': value > activeStep }]">
                                        2
                                    </span>
                                    <span class="font-semibold">Límites de recursos</span>
                                </button>
                                <Divider />
                            </div>
                        </Step>

                        <!-- PASO 3 HEADER -->
                        <Step v-slot="{ activateCallback, value, a11yAttrs }" asChild :value="2">
                            <div class="flex flex-row flex-auto gap-2" v-bind="a11yAttrs.root">
                                <button class="flex items-center flex-shrink-0 gap-2 p-2 bg-transparent border-0"
                                    @click="activateCallback(2)" v-bind="a11yAttrs.header">
                                    <span
                                        :class="['rounded-full border-2 size-6 flex items-center justify-center', { 'bg-primary-500 border-primary-500 text-white': value <= activeStep, 'border-gray-300 dark:border-gray-700': value > activeStep }]">
                                        3
                                    </span>
                                    <span class="font-semibold">Cuentas bancarias</span>
                                </button>
                            </div>
                        </Step>
                    </StepList>

                    <StepPanels>
                        <!-- PASO 1: NEGOCIO Y SUCURSALES (CONTENIDO) -->
                        <StepPanel :value="0">
                            <div class="p-4 space-y-6">
                                <h3 class="text-lg font-semibold border-b pb-2">Información general</h3>
                                <!-- --- CAMBIO: FloatLabel a InputLabel --- -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel for="commercial_name" value="Nombre del negocio *" />
                                        <InputText id="commercial_name" v-model="form.subscription.commercial_name"
                                            fluid :invalid="!!form.errors['subscription.commercial_name']"
                                            class="mt-1 w-full" />
                                        <InputError class="mt-2"
                                            :message="form.errors['subscription.commercial_name']" />
                                    </div>
                                    <div>
                                        <InputLabel for="business_name" value="Razón social (Opcional)" />
                                        <InputText id="business_name" v-model="form.subscription.business_name" fluid
                                            :invalid="!!form.errors['subscription.business_name']"
                                            class="mt-1 w-full" />
                                        <InputError class="mt-2" :message="form.errors['subscription.business_name']" />
                                    </div>
                                </div>
                                <!-- --- FIN CAMBIO --- -->

                                <h3 class="text-lg font-semibold border-b pb-2 mt-6">Sucursales</h3>
                                <!-- --- INICIO: CAMBIO - Mensaje de Límite --- -->
                                <Message severity="info" :closable="false">
                                    Registra las sucursales de tu negocio. Podrás añadir más en cualquier momento.
                                </Message>
                                <!-- --- FIN: CAMBIO --- -->


                                <div v-for="(branch, index) in form.branches" :key="index"
                                    class="p-4 border rounded-lg space-y-2 relative">
                                    <Button v-if="form.branches.length > 1" icon="pi pi-trash" severity="danger" text
                                        rounded @click="removeBranch(index)" class="!absolute top-2 right-2" />

                                    <div class="flex items-center">
                                        <RadioButton :id="'main_branch_' + index" v-model="branch.is_main" :value="true"
                                            @change="setMainBranch(index)" />
                                        <label :for="'main_branch_' + index" class="ml-2 font-semibold">Marcar como
                                            sucursal principal</label>
                                    </div>

                                    <!-- --- CAMBIO: FloatLabel a InputLabel --- -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel :for="'branch_name_' + index" value="Nombre *" />
                                            <InputGroup class="mt-1">
                                                <InputGroupAddon>Sucursal</InputGroupAddon>
                                                <InputText :id="'branch_name_' + index" v-model="branch.name" fluid
                                                    :invalid="!!form.errors[`branches.${index}.name`]" />
                                            </InputGroup>
                                            <InputError class="mt-2" :message="form.errors[`branches.${index}.name`]" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'branch_phone_' + index" value="Teléfono" />
                                            <InputText :id="'branch_phone_' + index" v-model="branch.contact_phone"
                                                fluid class="mt-1 w-full" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'branch_email_' + index" value="Email" />
                                            <InputText :id="'branch_email_' + index" v-model="branch.contact_email"
                                                fluid class="mt-1 w-full" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'branch_address_' + index" value="Dirección" />
                                            <InputText :id="'branch_address_' + index" v-model="branch.address" fluid
                                                class="mt-1 w-full" />
                                        </div>
                                        <div class="md:col-span-2">
                                            <InputLabel :for="'branch_hours_' + index" value="Horario semanal" />
                                            <InputGroup class="mt-1">
                                                <InputText :value="getHoursSummary(branch.operating_hours)" readonly
                                                    class="w-full" />
                                                <Button label="Establecer horario" severity="contrast"
                                                    icon="pi pi-clock" @click="openHoursModal(index)" />
                                            </InputGroup>
                                            <InputError class="mt-2"
                                                :message="form.errors[`branches.${index}.operating_hours`]" />
                                        </div>
                                    </div>
                                    <!-- --- FIN CAMBIO --- -->
                                </div>

                                <!-- --- INICIO: CAMBIO - v-if eliminado --- -->
                                <Button label="Añadir otra sucursal" icon="pi pi-plus" severity="secondary" outlined
                                    @click="addBranch" />
                                <!-- --- FIN: CAMBIO --- -->


                                <div class="flex justify-end pt-4">
                                    <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                                        @click="saveStep(0)" :loading="saving" />
                                </div>
                            </div>
                        </StepPanel>

                        <!-- PASO 2: LÍMITES (CONTENIDO) -->
                        <StepPanel :value="1">
                            <div class="p-4 space-y-6">
                                <Message severity="info" :closable="false">
                                    Establece los límites totales para tu suscripción. Éstos se compartirán entre todas
                                    tus sucursales.
                                </Message>

                                <!-- --- CAMBIO: label a InputLabel --- -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <InputLabel for="limit_users" value="Usuarios" class="font-semibold" />
                                        <p class="text-sm text-gray-500 mb-2">Cantidad de cuentas que podrán acceder
                                            al sistema.</p>
                                        <InputNumber id="limit_users" v-model="form.limits.limit_users" :min="1"
                                            showButtons fluid />
                                        <InputError class="mt-2" :message="form.errors['limits.limit_users']" />
                                    </div>
                                    <div>
                                        <InputLabel for="limit_products" value="Productos" class="font-semibold" />
                                        <p class="text-sm text-gray-500 mb-2">
                                            Cantidad requerida para registrar todo tu inventario.</p>
                                        <InputNumber id="limit_products" v-model="form.limits.limit_products" :min="1"
                                            showButtons fluid />
                                        <InputError class="mt-2" :message="form.errors['limits.limit_products']" />
                                    </div>
                                    <div>
                                        <InputLabel for="limit_cash_registers" value="Cajas registradoras"
                                            class="font-semibold" />
                                        <p class="text-sm text-gray-500 mb-2">Cantidad de cajas que puedes operar
                                            simultáneamente.</p>
                                        <InputNumber id="limit_cash_registers"
                                            v-model="form.limits.limit_cash_registers" :min="1" showButtons fluid />
                                        <InputError class="mt-2"
                                            :message="form.errors['limits.limit_cash_registers']" />
                                    </div>
                                    <div>
                                        <InputLabel for="limit_print_templates" value="Plantillas de impresión"
                                            class="font-semibold" />
                                        <p class="text-sm text-gray-500 mb-2">Diseños de tickets o etiquetas
                                            personalizadas.</p>
                                        <InputNumber id="limit_print_templates"
                                            v-model="form.limits.limit_print_templates" :min="1" showButtons fluid />
                                        <InputError class="mt-2"
                                            :message="form.errors['limits.limit_print_templates']" />
                                    </div>
                                </div>
                                <!-- --- FIN CAMBIO --- -->

                                <div class="flex justify-between pt-4">
                                    <Button label="Anterior" icon="pi pi-arrow-left" severity="secondary"
                                        @click="activeStep = 0" />
                                    <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                                        @click="saveStep(1)" :loading="saving" />
                                </div>
                            </div>
                        </StepPanel>

                        <!-- PASO 3: CUENTAS BANCARIAS (CONTENIDO) -->
                        <StepPanel :value="2">
                            <div class="p-4 space-y-6">
                                <Message severity="warn" :closable="false">
                                    <span class="font-bold">Importante:</span> Este paso es opcional y
                                    <strong>únicamente para control financiero interno</strong>.
                                    No solicitamos CVV ni información sensible. Esto NO es para pagar tu suscripción.
                                </Message>

                                <div v-for="(account, index) in form.bank_accounts" :key="index"
                                    class="p-4 border rounded-lg space-y-4 relative">
                                    <Button icon="pi pi-trash" severity="danger" text rounded
                                        @click="removeBankAccount(index)" class="!absolute top-2 right-2" />

                                    <!-- --- CAMBIO: FloatLabel a InputLabel --- -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel :for="'bank_name_' + index" value="Nombre del banco *" />
                                            <InputText :id="'bank_name_' + index" v-model="account.bank_name" fluid
                                                :invalid="!!form.errors[`bank_accounts.${index}.bank_name`]"
                                                class="mt-1 w-full" />
                                            <InputError class="mt-2"
                                                :message="form.errors[`bank_accounts.${index}.bank_name`]" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'account_name_' + index"
                                                value="Alias de la cuenta * (Ej. Cheques)" />
                                            <InputText :id="'account_name_' + index" v-model="account.account_name"
                                                fluid :invalid="!!form.errors[`bank_accounts.${index}.account_name`]"
                                                class="mt-1 w-full" />
                                            <InputError class="mt-2"
                                                :message="form.errors[`bank_accounts.${index}.account_name`]" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'owner_name_' + index" value="Nombre del titular *" />
                                            <InputText :id="'owner_name_' + index" v-model="account.owner_name" fluid
                                                :invalid="!!form.errors[`bank_accounts.${index}.owner_name`]"
                                                class="mt-1 w-full" />
                                            <InputError class="mt-2"
                                                :message="form.errors[`bank_accounts.${index}.owner_name`]" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'balance_' + index" value="Saldo inicial (opcional)" />
                                            <InputNumber :id="'balance_' + index" v-model="account.balance"
                                                mode="currency" currency="MXN" locale="es-MX" fluid class="mt-1 w-full"
                                                inputClass="w-full" />
                                            <InputError class="mt-2"
                                                :message="form.errors[`bank_accounts.${index}.balance`]" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'account_number_' + index"
                                                value="No. de cuenta (opcional)" />
                                            <InputText :id="'account_number_' + index" v-model="account.account_number"
                                                fluid class="mt-1 w-full" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'clabe_' + index" value="CLABE (opcional)" />
                                            <InputText :id="'clabe_' + index" v-model="account.clabe" fluid
                                                class="mt-1 w-full" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'bank_branches_' + index"
                                                value="Cuenta disponible en las siguientes sucursales" />
                                            <MultiSelect :id="'bank_branches_' + index" v-model="account.branch_ids"
                                                :options="branchOptions" optionLabel="label" optionValue="value"
                                                placeholder="Seleccionar sucursales" class="w-full mt-1" />
                                            <InputError class="mt-2"
                                                :message="form.errors[`bank_accounts.${index}.branch_ids`]" />
                                        </div>
                                    </div>
                                    <!-- --- FIN CAMBIO --- -->
                                </div>

                                <Button label="Añadir cuenta bancaria" icon="pi pi-plus" severity="secondary" outlined
                                    @click="addBankAccount" />

                                <div class="flex justify-between pt-4">
                                    <Button label="Anterior" icon="pi pi-arrow-left" severity="secondary"
                                        @click="activeStep = 1" />
                                    <Button label="Finalizar configuración" icon="pi pi-check" @click="finishOnboarding"
                                        :loading="saving" />
                                </div>
                            </div>
                        </StepPanel>
                    </StepPanels>
                </Stepper>
                <!-- --- FIN: ESTRUCTURA DE STEPPER CORREGIDA --- -->
            </div>
        </div>
    </div>

    <!-- --- MODAL PARA HORARIOS --- -->
    <Dialog v-model:visible="hoursModalVisible" modal header="Establecer horario semanal" :style="{ width: '38rem' }">
        <div v-if="currentBranchIndex !== null" class="space-y-4 p-2">
            <div v-for="(day, dayIndex) in form.branches[currentBranchIndex].operating_hours" :key="day.day"
                class="flex items-center gap-4">
                <div class="w-40">
                    <Checkbox :id="'day_open_' + dayIndex" v-model="day.open" :binary="true" />
                    <label :for="'day_open_' + dayIndex" class="ml-2 font-semibold">{{ day.day }}</label>
                </div>
                <div class="flex-1">
                    <InputMask v-model="day.from" mask="99:99" placeholder="09:00" :disabled="!day.open" />
                </div>
                <span>-</span>
                <div class="flex-1">
                    <InputMask v-model="day.to" mask="99:99" placeholder="18:00" :disabled="!day.open" />
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Cerrar" icon="pi pi-check" @click="hoursModalVisible = false" autofocus />
        </template>
    </Dialog>
</template>