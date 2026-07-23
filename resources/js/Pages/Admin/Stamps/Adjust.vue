<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

// ──────────────────────────────────────
// Props
// ──────────────────────────────────────
const props = defineProps({
    fiscalProfiles: {
        type: Array,
        default: () => [],
    },
});

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Gestión de timbres', url: route('admin.stamps.index') },
    { label: 'Ajuste manual' },
]);

// ──────────────────────────────────────
// Form state
// ──────────────────────────────────────
const form = useForm({
    fiscal_profile_id: null,
    stamp_quantity: 100,
    adjustment_type: 'add',
    admin_note: '',
});

const adjustmentTypeLabel = (type) => type === 'add' ? 'Agregar timbres' : 'Retirar timbres';

// ──────────────────────────────────────
// Selected profile (reactivo desde los props)
// ──────────────────────────────────────
const selectedProfile = computed(() =>
    props.fiscalProfiles.find(p => p.id === form.fiscal_profile_id) ?? null
);

// ──────────────────────────────────────
// Stamp balance lookup
// ──────────────────────────────────────
const stampBalance = ref(null);
const balanceLoading = ref(false);
const balanceError = ref(null);

watch(() => form.fiscal_profile_id, async (newId) => {
    if (!newId) {
        stampBalance.value = null;
        balanceError.value = null;
        return;
    }

    balanceLoading.value = true;
    balanceError.value = null;
    stampBalance.value = null;

    try {
        const { data } = await axios.get(route('admin.stamps.balance', newId));
        // data.balance is an object: { stampsBalance, stampsUsed, stampsAssigned, ... }
        stampBalance.value = data.balance?.stampsBalance ?? null;
        if (stampBalance.value === null) {
            balanceError.value = 'No se pudo interpretar el saldo.';
        }
    } catch (err) {
        balanceError.value = err.response?.data?.error ?? 'No se pudo consultar el saldo.';
    } finally {
        balanceLoading.value = false;
    }
});
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-lg mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div>
                <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    Ajuste manual de timbres
                </h1>
                <p class="text-sm text-gray-500 mt-1 m-0">
                    Agrega o retira timbres de un perfil fiscal sin pasar por el flujo de compra normal.
                </p>
            </div>

            <!-- ── Form Card ────────────────────────────── -->
            <form @submit.prevent="form.post(route('admin.stamps.manual-adjustment'), { preserveScroll: true })"
                class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6 space-y-5">

                <!-- Fiscal Profile -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Perfil fiscal *</label>
                    <Select
                        v-model="form.fiscal_profile_id"
                        :options="fiscalProfiles"
                        optionLabel="razon_social"
                        optionValue="id"
                        placeholder="Selecciona un perfil fiscal"
                        class="w-full"
                        :pt="{ 
                            root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors' },
                        }"
                    >
                        <template #value>
                            <div v-if="selectedProfile" class="flex flex-col">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ selectedProfile.razon_social }}</span>
                                <span class="text-[11px] text-gray-400">{{ selectedProfile.rfc }} · {{ selectedProfile.subscription_name }}</span>
                            </div>
                            <span v-else class="text-gray-400">Selecciona un perfil fiscal</span>
                        </template>
                        <template #option="{ option }">
                            <div class="flex flex-col py-0.5">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ option.razon_social }}</span>
                                <span class="text-[11px] text-gray-400">{{ option.rfc }} · {{ option.subscription_name }}</span>
                            </div>
                        </template>
                    </Select>
                    <Message v-if="form.errors.fiscal_profile_id" severity="error" variant="simple" size="small">{{ form.errors.fiscal_profile_id }}</Message>

                    <!-- Current stamp balance -->
                    <div v-if="form.fiscal_profile_id" class="flex items-center gap-2 mt-1">
                        <template v-if="balanceLoading">
                            <i class="pi pi-spin pi-spinner !text-xs text-gray-400" />
                            <span class="text-xs text-gray-400">Consultando saldo…</span>
                        </template>
                        <template v-else-if="stampBalance !== null">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 dark:bg-emerald-900/20 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                <i class="pi pi-ticket !text-xs" />
                                Timbres disponibles: {{ stampBalance.toLocaleString() }}
                            </span>
                        </template>
                        <template v-else-if="balanceError">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 dark:bg-red-900/20 px-3 py-1 text-xs font-semibold text-red-600 dark:text-red-300 border border-red-200 dark:border-red-800">
                                <i class="pi pi-exclamation-triangle !text-xs" />
                                {{ balanceError }}
                            </span>
                        </template>
                    </div>
                </div>

                <!-- Quantity -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad de timbres *</label>
                    <InputNumber v-model="form.stamp_quantity" :min="1" :max="999999" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-2xl !font-light !text-gray-900 dark:!text-white' } } }" />
                    <Message v-if="form.errors.stamp_quantity" severity="error" variant="simple" size="small">{{ form.errors.stamp_quantity }}</Message>
                </div>

                <!-- Adjustment type -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de ajuste *</label>
                    <div class="flex gap-4">
                        <div class="flex items-center gap-2">
                            <RadioButton v-model="form.adjustment_type" value="add" inputId="adj_add" />
                            <label for="adj_add" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">Agregar</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <RadioButton v-model="form.adjustment_type" value="remove" inputId="adj_remove" />
                            <label for="adj_remove" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">Retirar</label>
                        </div>
                    </div>
                    <Message v-if="form.errors.adjustment_type" severity="error" variant="simple" size="small">{{ form.errors.adjustment_type }}</Message>
                </div>

                <!-- Note -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nota administrativa *</label>
                    <Textarea v-model="form.admin_note" placeholder="Razón del ajuste..." rows="3" class="w-full"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }" />
                    <Message v-if="form.errors.admin_note" severity="error" variant="simple" size="small">{{ form.errors.admin_note }}</Message>
                </div>

                <!-- Info alert -->
                <div class="flex items-start gap-3 p-4 rounded-2xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800">
                    <i class="pi pi-info-circle !text-sm text-blue-500 mt-0.5" />
                    <div>
                        <p class="text-xs text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                            <template v-if="form.adjustment_type === 'add'"><strong>Agregar timbres:</strong> descuenta de la cuenta maestra y acredita al perfil fiscal.</template>
                            <template v-else><strong>Retirar timbres:</strong> devuelve timbres del perfil fiscal a la cuenta maestra.</template>
                        </p>
                        <p class="text-xs text-blue-600 dark:text-blue-400 m-0 mt-2">
                            La cuenta maestra debe tener saldo suficiente para agregar timbres. Los cambios se aplican de inmediato y se reflejan en el PAC en breve.
                        </p>
                    </div>
                </div>

                <!-- Submit -->
                <Button
                    type="submit"
                    :label="adjustmentTypeLabel(form.adjustment_type)"
                    icon="pi pi-check"
                    :loading="form.processing"
                    class="!rounded-full w-full"
                    :severity="form.adjustment_type === 'remove' ? 'danger' : 'primary'"
                />
            </form>

        </div>
    </AppLayout>
</template>
