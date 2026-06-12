<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    subscriptionId: [Number, String],
    planItems: Array,
});

const emit = defineEmits(['update:visible']);

const registerForm = useForm({
    start_date: '',
    end_date: '',
    payment_amount: '',
    payment_method: 'transfer',
    payment_status: 'approved',
    limits: {},
    modules: {},
});

const paymentMethods = [
    { label: 'Transferencia', value: 'transfer' },
    { label: 'Efectivo', value: 'cash' },
    { label: 'Tarjeta', value: 'card' },
    { label: 'Otro', value: 'other' },
];

const paymentStatuses = [
    { label: 'Aprobado', value: 'approved' },
    { label: 'Pendiente', value: 'pending' },
    { label: 'Rechazado', value: 'rejected' },
];

watch(() => props.visible, (newVal) => {
    if (newVal) {
        // Reset form
        registerForm.reset();

        // Set default dates (1 year from today)
        const today = new Date();
        const nextYear = new Date(today);
        nextYear.setFullYear(today.getFullYear() + 1);

        registerForm.start_date = today.toISOString().split('T')[0];
        registerForm.end_date = nextYear.toISOString().split('T')[0];
        registerForm.payment_amount = '';
        registerForm.payment_method = 'transfer';
        registerForm.payment_status = 'approved';

        // Default limits and modules from plan items catalog
        const limitsObj = {};
        const modulesObj = {};

        props.planItems.forEach((item) => {
            if (item.type === 'limit') {
                limitsObj[item.key] = item.meta?.default_quantity || 0;
            }
            if (item.type === 'module') {
                modulesObj[item.key] = true; // default all active
            }
        });

        registerForm.limits = limitsObj;
        registerForm.modules = modulesObj;
    }
});

const submit = () => {
    registerForm.post(route('admin.subscriptions.store-version', props.subscriptionId), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
        },
    });
};

// --- TESLA UI PT ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'p-6 dark:bg-[#232323]' },
    footer: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] px-6 py-4' },
};

const inputNumberPt = {
    input: { root: { class: 'w-full min-w-0 !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm text-gray-900 dark:text-white' } },
};

const inputDatePt = 'w-full min-w-0 rounded-xl bg-white dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a] focus:dark:border-primary-500 transition-colors py-2.5 text-sm text-gray-900 dark:text-white dark:[color-scheme:dark]';

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]' },
};

const togglePt = {
    root: { class: '!w-10 !h-5' },
    input: { class: '!rounded-full' },
};
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal
        :style="{ width: '55rem' }"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 flex-shrink-0">
                    <i class="pi pi-plus-circle !text-lg"></i>
                </div>
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0 mb-0.5">Registrar pago con nueva versión</h2>
                    <p class="text-[9px] uppercase tracking-widest text-gray-500 m-0">Crear versión y pago manualmente</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Sección: Pago -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-money-bill"></i> Datos del pago
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Monto *</label>
                        <InputNumber
                            v-model="registerForm.payment_amount"
                            mode="currency"
                            currency="MXN"
                            fluid
                            :pt="inputNumberPt"
                            required
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Método de pago *</label>
                        <Select
                            v-model="registerForm.payment_method"
                            :options="paymentMethods"
                            optionLabel="label"
                            optionValue="value"
                            fluid
                            :pt="selectPt"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Estado *</label>
                        <Select
                            v-model="registerForm.payment_status"
                            :options="paymentStatuses"
                            optionLabel="label"
                            optionValue="value"
                            fluid
                            :pt="selectPt"
                        />
                    </div>
                </div>
            </div>

            <!-- Sección: Fechas de la versión -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-calendar"></i> Vigencia de la nueva versión
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de inicio *</label>
                        <input type="date" v-model="registerForm.start_date" :class="inputDatePt" required />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de vencimiento *</label>
                        <input type="date" v-model="registerForm.end_date" :class="inputDatePt" required />
                    </div>
                </div>
            </div>

            <!-- Sección: Módulos -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-box"></i> Módulos activos
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div
                        v-for="modItem in planItems.filter(p => p.type === 'module')"
                        :key="modItem.key"
                        class="flex items-center justify-between py-2 px-3 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a]"
                    >
                        <label class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer select-none" :for="'reg-mod-' + modItem.key">
                            {{ modItem.name }}
                        </label>
                        <ToggleSwitch
                            :inputId="'reg-mod-' + modItem.key"
                            v-model="registerForm.modules[modItem.key]"
                            :pt="togglePt"
                        />
                    </div>
                </div>
            </div>

            <!-- Sección: Límites -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-chart-pie"></i> Recursos (Límites)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div
                        v-for="limItem in planItems.filter(p => p.type === 'limit')"
                        :key="limItem.key"
                        class="flex flex-col gap-1.5"
                    >
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate" :title="limItem.name">
                            {{ limItem.name }}
                        </label>
                        <InputNumber v-model="registerForm.limits[limItem.key]" fluid :pt="inputNumberPt" />
                        <small class="text-[9px] text-gray-400">(-1 = Ilimitado)</small>
                    </div>
                </div>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cancelar"
                    icon="pi pi-times"
                    @click="emit('update:visible', false)"
                    severity="secondary"
                    outlined
                    class="!rounded-xl !text-xs !uppercase !tracking-wider"
                    :disabled="registerForm.processing"
                />
                <Button
                    label="Registrar pago y versión"
                    icon="pi pi-check"
                    type="submit"
                    severity="primary"
                    class="!rounded-xl !text-xs !uppercase !tracking-wider"
                    :loading="registerForm.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
