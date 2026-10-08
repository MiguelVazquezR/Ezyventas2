<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReferralHeroBanner from '@/Components/Referral/ReferralHeroBanner.vue';

const props = defineProps({
    referralCode: Object,
    referrals: Array,
    pendingRewards: Number,
    totalEarned: Number,
    bankAccount: Object,
    settings: Object,
    subscriptionCost: Number,
    referrerActiveDiscountPct: Number,
    activeReferralsCount: Number,
});

const activeTab = ref(0);

const bankForm = useForm({
    clabe: props.bankAccount?.clabe || '',
    bank_name: props.bankAccount?.bank_name || '',
    account_holder_name: props.bankAccount?.account_holder_name || '',
});

function saveBankAccount() {
    bankForm.post(route('referrals.bank-account'), {
        preserveScroll: true,
    });
}

const rewardLabel = (status) => {
    const map = {
        pending: 'Pendiente',
        paid: 'Pagado',
        cancelled: 'Cancelado',
        trial: 'De prueba',
        expired: 'Expirado',
    };
    return map[status] || status;
};

const rewardSeverity = (status) => {
    const map = {
        pending: 'warn',
        paid: 'success',
        cancelled: 'danger',
        trial: 'info',
        expired: 'danger',
    };
    return map[status] || 'info';
};

const trialCount = computed(() => props.referrals.filter(r => r.reward_status === 'trial').length);

const formatTrialEnd = (date) => date
    ? new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })
    : '';

// Marcar referidos como vistos al cargar la página
const hasUnseen = computed(() => props.referrals.some(r => !r.seen_at));
if (hasUnseen.value) {
    fetch(route('referrals.mark-seen'), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') } });
}
</script>

<template>
    <AppLayout title="Mis referidos">
        <!-- Header -->
        <div class="max-w-4xl mx-auto pt-8 pb-4 px-4">
            <div>
                <h2 class="text-2xl font-light text-gray-900 dark:text-white tracking-tight m-0">Mis referidos</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 m-0">Comparte EzyVentas y gana premios por cada amigo que se suscriba.</p>
            </div>
        </div>

        <!-- Banner principal del sistema de referidos - full width -->
        <div class="px-4 md:px-8 lg:px-12 mb-8 max-w-7xl mx-auto">
            <ReferralHeroBanner
                :referral-code="referralCode"
                :active-referrals-count="activeReferralsCount"
                :subscription-cost="subscriptionCost"
                :referrer-active-discount-pct="referrerActiveDiscountPct"
                :settings="settings"
            />
        </div>

        <!-- Contenido principal -->
        <div class="max-w-4xl mx-auto pb-8 px-4 space-y-8">

            <!-- Metricas -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Total ganado</p>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white mt-2 m-0">${{ totalEarned.toFixed(2) }}</p>
                </div>
                <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Pendiente</p>
                    <p class="text-3xl font-light tracking-tight text-amber-500 mt-2 m-0">${{ pendingRewards.toFixed(2) }}</p>
                </div>
                <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Referidos</p>
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white mt-2 m-0">{{ referrals.length }}</p>
                    <p class="text-[10px] text-gray-500 m-0 mt-1">
                        <span class="text-green-500 font-bold">{{ activeReferralsCount }}</span> activos
                        <span v-if="trialCount > 0" class="text-sky-500 font-bold">• {{ trialCount }} en prueba</span>
                        <span class="text-gray-300 mx-1">•</span>
                        <span class="text-gray-400">{{ Math.max(referrals.length - activeReferralsCount - trialCount, 0) }} sin pago</span>
                    </p>
                </div>
            </div>

            <!-- Datos bancarios -->
            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1">Cuenta bancaria para recibir premios</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mb-4">Necesitamos tus datos para transferirte los premios.</p>

                <form @submit.prevent="saveBankAccount" class="space-y-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clabe (18 dígitos) *</label>
                        <InputText v-model="bankForm.clabe" maxlength="18" placeholder="000000000000000000"
                            class="w-full"
                            :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        <Message v-if="bankForm.errors.clabe" severity="error" variant="simple" size="small">{{ bankForm.errors.clabe }}</Message>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Banco *</label>
                        <InputText v-model="bankForm.bank_name" placeholder="BBVA, Santander..."
                            class="w-full"
                            :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        <Message v-if="bankForm.errors.bank_name" severity="error" variant="simple" size="small">{{ bankForm.errors.bank_name }}</Message>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre del titular *</label>
                        <InputText v-model="bankForm.account_holder_name" placeholder="Nombre tal como aparece en el banco"
                            class="w-full"
                            :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        <Message v-if="bankForm.errors.account_holder_name" severity="error" variant="simple" size="small">{{ bankForm.errors.account_holder_name }}</Message>
                    </div>
                    <Button type="submit" label="Guardar cuenta bancaria" icon="pi pi-save" :loading="bankForm.processing" class="!rounded-full" />
                </form>
            </div>

            <!-- Lista de referidos -->
            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1">Historial de referidos</h3>
                <div v-if="pendingRewards > 0" class="bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-2xl p-4 mb-4">
                    <p class="text-sm text-amber-800 dark:text-amber-200 m-0">
                        <i class="pi pi-info-circle mr-1.5"></i>
                        Estamos revisando el pago de tu referido. 
                        Tu premio será transferido a la cuenta bancaria registrada una vez aprobado. Te notificaremos cuando el pago esté realizado.
                    </p>
                </div>

                <div v-if="trialCount > 0" class="bg-sky-50 dark:bg-sky-900/10 border border-sky-100 dark:border-sky-900/30 rounded-2xl p-4 mb-4">
                    <p class="text-sm text-sky-800 dark:text-sky-200 m-0">
                        <i class="pi pi-sync mr-1.5"></i>
                        Tienes <strong>{{ trialCount }}</strong> referido(s) en su periodo de prueba de 30 días.
                        El premio se activa automáticamente cuando hagan su primer pago.
                    </p>
                </div>

                <div v-if="referrals.length === 0" class="text-center py-8">
                    <i class="pi pi-users !text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Aún no has referido a nadie. ¡Comparte tu código!</p>
                </div>

                <DataTable v-else :value="referrals" stripedRows class="!rounded-2xl overflow-hidden"
                    :pt="{
                        root: { class: '!border-gray-100 dark:!border-[#3a3a3a]' },
                        headerCell: { class: '!bg-gray-50 dark:!bg-[#1a1a1a] !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-500 !py-3 !px-4' },
                        bodyCell: { class: '!py-3 !px-4 !text-sm' }
                    }">
                    <Column field="referred_subscription.commercial_name" header="Suscriptor" />
                    <Column header="Fecha de pago">
                        <template #body="{ data }">
                            <span v-if="data.payment?.created_at">{{ new Date(data.payment.created_at).toLocaleDateString('es-MX') }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                    </Column>
                    <Column header="Mensualidad base">
                        <template #body="{ data }">
                            <span v-if="data.monthly_base_amount != null">${{ parseFloat(data.monthly_base_amount).toFixed(2) }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                    </Column>
                    <Column header="Premio">
                        <template #body="{ data }">
                            <span v-if="data.reward_amount != null">${{ parseFloat(data.reward_amount).toFixed(2) }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5 items-start">
                                <Tag :value="rewardLabel(data.reward_status)" :severity="rewardSeverity(data.reward_status)" rounded />
                                <span v-if="data.reward_status === 'trial' && data.trial_ends_at" class="text-[10px] text-gray-400 dark:text-gray-500">
                                    Prueba termina {{ formatTrialEnd(data.trial_ends_at) }}
                                </span>
                                <span v-else-if="data.reward_status === 'expired'" class="text-[10px] text-gray-400 dark:text-gray-500">
                                    No hizo su primer pago
                                </span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Suscripción" style="width: 7rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1.5">
                                <span :class="['w-1.5 h-1.5 rounded-full', data.referred_subscription_active ? 'bg-green-500 animate-pulse' : 'bg-gray-400']"></span>
                                <span class="text-xs" :class="data.referred_subscription_active ? 'text-green-600 dark:text-green-400 font-medium' : 'text-gray-400'">
                                    {{ data.referred_subscription_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
