<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { startOfWeek, endOfWeek, startOfMonth, endOfMonth, startOfYear, endOfYear, isSameDay, isToday, format } from 'date-fns';

const props = defineProps({
    fiscalProfiles: { type: Array, default: () => [] },
    totalStampsUsed: { type: Number, default: 0 },
    totalInvoices: { type: Number, default: 0 },
    canceledInvoices: { type: Number, default: 0 },
    totalAmount: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({ startDate: format(new Date(), 'yyyy-MM-dd'), endDate: format(new Date(), 'yyyy-MM-dd') }) },
});

// --- STATE ---
const dates = ref();
const selectedRange = ref('day');
const isExporting = ref(false);

// --- DATE RANGE ---
const rangeOptions = ref([
    { label: 'Hoy', value: 'day' },
    { label: 'Semana', value: 'week' },
    { label: 'Mes', value: 'month' },
    { label: 'Año', value: 'year' },
    { label: 'Personalizado', value: 'custom' },
]);

const setDateRange = (period) => {
    const today = new Date();
    let startDate, endDate;
    switch (period) {
        case 'week': startDate = startOfWeek(today, { weekStartsOn: 1 }); endDate = endOfWeek(today, { weekStartsOn: 1 }); break;
        case 'month': startDate = startOfMonth(today); endDate = endOfMonth(today); break;
        case 'year': startDate = startOfYear(today); endDate = endOfYear(today); break;
        case 'day': default: startDate = today; endDate = today; break;
    }
    dates.value = [startDate, endDate];
};

watch(selectedRange, (newPeriod) => {
    if (newPeriod !== 'custom') {
        setDateRange(newPeriod);
    }
});

// --- DATA FETCHING ---
const fetchData = () => {
    if (dates.value && dates.value[0] && dates.value[1]) {
        router.get(route('billing.dashboard'), {
            start_date: format(dates.value[0], 'yyyy-MM-dd'),
            end_date: format(dates.value[1], 'yyyy-MM-dd'),
        }, { preserveState: true, replace: true });
    }
};

watch(dates, (newDates, oldDates) => {
    if (newDates && newDates[0] && newDates[1]) {
        if (!oldDates || !isSameDay(newDates[0], oldDates[0]) || !isSameDay(newDates[1], oldDates[1])) {
            fetchData();
        }
    }
}, { deep: true });

// --- EXPORT ---
const handleExport = () => {
    // TODO: implement export logic
};

// --- INIT ---
onMounted(() => {
    const initialStartDate = props.filters.startDate ? new Date(props.filters.startDate.replace(/-/g, '/')) : new Date();
    const initialEndDate = props.filters.endDate ? new Date(props.filters.endDate.replace(/-/g, '/')) : new Date();
    dates.value = [initialStartDate, initialEndDate];

    if (isSameDay(initialStartDate, initialEndDate) && isToday(initialStartDate)) {
        selectedRange.value = 'day';
    } else if (isSameDay(initialStartDate, startOfWeek(initialStartDate, { weekStartsOn: 1 })) && isSameDay(initialEndDate, endOfWeek(initialStartDate, { weekStartsOn: 1 }))) {
        selectedRange.value = 'week';
    } else if (isSameDay(initialStartDate, startOfMonth(initialStartDate)) && isSameDay(initialEndDate, endOfMonth(initialStartDate))) {
        selectedRange.value = 'month';
    } else if (isSameDay(initialStartDate, startOfYear(initialStartDate)) && isSameDay(initialEndDate, endOfYear(initialStartDate))) {
        selectedRange.value = 'year';
    } else {
        selectedRange.value = 'custom';
    }
});

// --- HELPERS ---
const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

const formatNumber = (value) =>
    new Intl.NumberFormat('es-MX').format(value || 0);

const activeProfiles = computed(() =>
    props.fiscalProfiles.filter(p => p.is_active),
);

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <Head title="Facturación — Resumen" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-2">
                <div>
                    <h1 class="text-4xl md:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Facturación
                    </h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mt-2 m-0 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                        Resumen general &middot; CFDI 4.0
                    </p>
                </div>

                <div class="flex items-center gap-3 flex-wrap bg-white dark:bg-[#232323] p-2 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] shadow-sm">
                    <SelectButton
                        v-model="selectedRange"
                        :options="rangeOptions"
                        optionLabel="label"
                        optionValue="value"
                        :pt="{
                            root: { class: 'bg-gray-100 dark:bg-[#1a1a1a] rounded-full p-1 border border-gray-200 dark:border-[#3a3a3a] flex' },
                            button: { class: 'rounded-full px-4 py-2 transition-colors focus:ring-0 !border-none text-xs font-medium' },
                        }"
                    />

                    <DatePicker
                        v-if="selectedRange === 'custom'"
                        v-model="dates"
                        selectionMode="range"
                        dateFormat="dd/mm/yy"
                        class="!w-64"
                        @update:modelValue="selectedRange = 'custom'"
                        :pt="{
                            input: { root: { class: '!rounded-full !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors text-sm' } },
                        }"
                    />

                    <Button
                        label="Exportar"
                        icon="pi pi-file-excel"
                        severity="secondary"
                        @click="handleExport"
                        :loading="isExporting"
                        class="!rounded-full !px-5 !text-xs !font-bold !uppercase !tracking-wider"
                    />
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Total comprobantes -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                            <i class="pi pi-receipt !text-sm text-blue-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Comprobantes</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(totalInvoices) }}</span>
                    <span class="text-xs text-gray-400">Total de CFDI emitidos</span>
                </div>

                <!-- Timbres usados -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                            <i class="pi pi-check-circle !text-sm text-emerald-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Timbres usados</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(totalStampsUsed) }}</span>
                    <span class="text-xs text-gray-400">Facturas certificadas</span>
                </div>

                <!-- Canceladas -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                            <i class="pi pi-times-circle !text-sm text-red-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Canceladas</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(canceledInvoices) }}</span>
                    <span class="text-xs text-gray-400">CFDI cancelados</span>
                </div>

                <!-- Total facturado -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                            <i class="pi pi-dollar !text-sm text-amber-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Total facturado</span>
                    </div>
                    <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatCurrency(totalAmount) }}</span>
                    <span class="text-xs text-gray-400">MXN facturas certificadas</span>
                </div>
            </div>

            <!-- Fiscal Profiles Table -->
            <div v-if="fiscalProfiles.length > 0" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                        <i class="pi pi-building !text-sm text-purple-500"></i>
                    </div>
                    <div>
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Perfiles fiscales</h2>
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">{{ fiscalProfiles.length }} {{ fiscalProfiles.length === 1 ? 'RFC registrado' : 'RFCs registrados' }}</p>
                    </div>
                </div>

                <DataTable
                    :value="fiscalProfiles"
                    stripedRows
                    class="!border-none !bg-transparent"
                    :pt="{
                        root: { class: '!bg-transparent !border-none' },
                        thead: { class: '!bg-transparent' },
                        th: { class: '!bg-gray-50 dark:!bg-[#1a1a1a] !border-none !py-3 !px-5 !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-500 first:!rounded-l-2xl last:!rounded-r-2xl' },
                        td: { class: '!border-b !border-gray-50 dark:!border-[#2a2a2a] !py-4 !px-5 !text-sm !text-gray-900 dark:!text-gray-100' },
                        tbody: { class: '!border-none' },
                    }"
                >
                    <Column field="rfc" header="RFC">
                        <template #body="{ data }">
                            <span class="font-medium text-gray-900 dark:text-white">{{ data.rfc }}</span>
                        </template>
                    </Column>
                    <Column field="razon_social" header="Razón social">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.razon_social }}</span>
                        </template>
                    </Column>
                    <Column field="regimen_fiscal" header="Régimen fiscal">
                        <template #body="{ data }">
                            <span class="text-gray-500 dark:text-gray-400">{{ data.regimen_fiscal }}</span>
                        </template>
                    </Column>
                    <Column header="Estado PAC">
                        <template #body="{ data }">
                            <Tag
                                :value="data.sw_user_id ? 'Activo' : 'Pendiente'"
                                :severity="data.sw_user_id ? 'success' : 'warn'"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>
                    <Column header="Acciones">
                        <template #body>
                            <Link
                                :href="route('billing.settings.index')"
                                class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-primary-500 transition-colors no-underline"
                            >
                                <i class="pi pi-cog !text-[10px]"></i>
                                Administrar
                            </Link>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <!-- Empty state -->
            <div
                v-else
                class="bg-white dark:bg-[#232323] p-10 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] text-center"
            >
                <div class="w-16 h-16 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center mx-auto mb-4 border border-gray-100 dark:border-[#3a3a3a]">
                    <i class="pi pi-building !text-2xl text-gray-400"></i>
                </div>
                <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0 mb-2">
                    Sin perfiles fiscales
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 m-0 max-w-md mx-auto mb-6">
                    Agrega tu primer RFC emisor para comenzar a facturar.
                </p>
                <Link
                    :href="route('billing.settings.index')"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors no-underline"
                >
                    <i class="pi pi-plus !text-sm"></i>
                    Agregar perfil fiscal
                </Link>
            </div>

        </div>
    </AppLayout>
</template>
