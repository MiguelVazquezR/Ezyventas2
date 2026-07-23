<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { format } from 'date-fns';
import { es } from 'date-fns/locale';
import AdjustStampModal from '@/Components/AdjustStampModal.vue';

// ──────────────────────────────────────
// Props
// ──────────────────────────────────────
const props = defineProps({
    fiscalProfile: Object,
    fiscalProfiles: {
        type: Array,
        default: () => [],
    },
    tiers: {
        type: Array,
        default: () => [],
    },
    movements: Array,
    currentBalance: [Number, null],
    balanceError: [String, null],
    filters: Object,
});

// ──────────────────────────────────────
// Adjust Stamp Modal
// ──────────────────────────────────────
const showAdjustModal = ref(false);
const adjustPreselectedId = ref(null);

function openAdjustModal() {
    adjustPreselectedId.value = props.fiscalProfile?.id ?? null;
    showAdjustModal.value = true;
}

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Gestión de timbres', url: route('admin.stamps.index') },
    { label: `Historial — ${props.fiscalProfile?.razon_social ?? ''}` },
]);

// ──────────────────────────────────────
// Date filter state
// ──────────────────────────────────────
const startDate = ref(props.filters?.start_date ?? '');
const endDate = ref(props.filters?.end_date ?? '');

function applyFilters() {
    router.get(
        route('admin.stamps.history', props.fiscalProfile.id),
        {
            start_date: startDate.value || undefined,
            end_date: endDate.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function clearFilters() {
    startDate.value = '';
    endDate.value = '';
    router.get(
        route('admin.stamps.history', props.fiscalProfile.id),
        {},
        { preserveState: true, replace: true },
    );
}

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function formatDate(dateStr) {
    if (!dateStr) return '—';
    try {
        return format(new Date(dateStr), 'dd/MM/yyyy HH:mm', { locale: es });
    } catch {
        return dateStr;
    }
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-MX').format(value || 0);
}

function typeIcon(type) {
    return type === 'purchase' ? 'pi pi-arrow-circle-down' : 'pi pi-arrow-circle-up';
}

function typeColor(type) {
    return type === 'purchase' ? 'text-emerald-500' : 'text-amber-500';
}

</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-5xl mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Historial de timbres
                    </h1>
                    <div class="mt-2">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white m-0">
                            {{ fiscalProfile.razon_social }}
                        </h2>
                        <p class="text-sm text-gray-400 m-0 mt-0.5">
                            RFC {{ fiscalProfile.rfc }}
                            <span class="mx-1.5 text-gray-300 dark:text-gray-600">·</span>
                            {{ fiscalProfile.subscription?.business_name ?? '—' }}
                        </p>
                    </div>
                </div>
                <Button
                    icon="pi pi-cog"
                    label="Ajuste manual"
                    severity="secondary"
                    class="!rounded-full shrink-0"
                    @click="openAdjustModal()"
                />
            </div>

            <!-- ── Current balance summary ───────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Saldo actual en PAC</p>
                        <p v-if="balanceError" class="text-xs text-red-500 mt-1 m-0">{{ balanceError }}</p>
                        <p v-else-if="currentBalance !== null" class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0 mt-1">
                            {{ formatNumber(currentBalance) }}
                        </p>
                        <p v-else class="text-sm text-gray-400 mt-1 m-0">No disponible</p>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-gray-400">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                            {{ movements.filter(m => m.entrada > 0).reduce((s, m) => s + m.entrada, 0).toLocaleString() }} timbres agregados
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
                            {{ movements.filter(m => m.salida > 0).reduce((s, m) => s + m.salida, 0).toLocaleString() }} timbres consumidos
                        </span>
                    </div>
                </div>
            </div>

            <!-- ── Filters ──────────────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-5">
                <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                    <div class="flex flex-col gap-1.5 flex-1">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha inicial</label>
                        <Calendar
                            v-model="startDate"
                            dateFormat="yy-mm-dd"
                            showIcon
                            class="w-full"
                            :pt="{ root: { class: 'w-full' }, input: { root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5 flex-1">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha final</label>
                        <Calendar
                            v-model="endDate"
                            dateFormat="yy-mm-dd"
                            showIcon
                            class="w-full"
                            :pt="{ root: { class: 'w-full' }, input: { root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }"
                        />
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <Button
                            icon="pi pi-search"
                            label="Filtrar"
                            class="!rounded-full"
                            @click="applyFilters"
                        />
                        <Button
                            v-if="startDate || endDate"
                            icon="pi pi-times"
                            label="Limpiar"
                            severity="secondary"
                            class="!rounded-full"
                            @click="clearFilters"
                        />
                    </div>
                </div>
            </div>

            <!-- ── Movements table ──────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] overflow-hidden">
                <DataTable
                    :value="movements"
                    stripedRows
                    class="w-full"
                    :pt="{ root: { class: '!bg-transparent' } }"
                >
                    <Column header="Fecha">
                        <template #body="{ data }">
                            <span class="text-sm text-gray-900 dark:text-white whitespace-nowrap">{{ formatDate(data.date) }}</span>
                        </template>
                    </Column>

                    <Column header="Descripción">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <i :class="[typeIcon(data.type), typeColor(data.type), '!text-sm shrink-0']" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ data.description }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Entrada">
                        <template #body="{ data }">
                            <span v-if="data.entrada > 0" class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                                +{{ formatNumber(data.entrada) }}
                            </span>
                            <span v-else class="text-sm text-gray-300 dark:text-gray-600">—</span>
                        </template>
                    </Column>

                    <Column header="Salida">
                        <template #body="{ data }">
                            <span v-if="data.salida > 0" class="text-sm font-semibold text-red-500">
                                -{{ formatNumber(data.salida) }}
                            </span>
                            <span v-else class="text-sm text-gray-300 dark:text-gray-600">—</span>
                        </template>
                    </Column>

                    <Column header="Saldo">
                        <template #body="{ data }">
                            <span class="text-sm font-bold text-gray-900 dark:text-white">
                                {{ formatNumber(data.saldo) }}
                            </span>
                        </template>
                    </Column>
                </DataTable>

                <!-- Empty state -->
                <div v-if="movements.length === 0" class="flex flex-col items-center justify-center py-16 text-center">
                    <i class="pi pi-history !text-4xl text-gray-300 dark:text-gray-600 mb-4" />
                    <p class="text-sm text-gray-400 m-0">No hay movimientos registrados para este perfil fiscal.</p>
                    <p class="text-xs text-gray-400 mt-1 m-0">
                        {{ startDate || endDate ? 'Intenta con un rango de fechas más amplio.' : '' }}
                    </p>
                </div>
            </div>

        </div>
        <!-- ── Adjust Stamp Modal ─────────────────────── -->
        <AdjustStampModal
            v-model:visible="showAdjustModal"
            :fiscal-profiles="fiscalProfiles"
            :tiers="tiers"
            :preselected-profile-id="adjustPreselectedId"
        />
    </AppLayout>
</template>
