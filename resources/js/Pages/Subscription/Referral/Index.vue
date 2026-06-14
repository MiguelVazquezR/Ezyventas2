<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

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
const loadingCode = ref(false);

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

function generateCode() {
    if (loadingCode.value) return;
    loadingCode.value = true;
    fetch(route('referrals.code'))
        .then(r => r.json())
        .then(data => {
            window.location.reload();
        })
        .catch(() => {
            loadingCode.value = false;
        });
}

function copyCode() {
    if (!props.referralCode?.code) return;
    navigator.clipboard.writeText(props.referralCode.code);
}

const shareMessage = computed(() =>
    `Regístrate en EzyVentas con mi código ${props.referralCode?.code || ''} y obtén ${props.settings?.referred_discount_pct || 15}% de descuento en tu primer pago.`
);

const rewardLabel = (status) => {
    const map = { pending: 'Pendiente', paid: 'Pagado', cancelled: 'Cancelado' };
    return map[status] || status;
};

const rewardSeverity = (status) => {
    const map = { pending: 'warn', paid: 'success', cancelled: 'danger' };
    return map[status] || 'info';
};

// Marcar referidos como vistos al cargar la página
const hasUnseen = computed(() => props.referrals.some(r => !r.seen_at));
if (hasUnseen.value) {
    fetch(route('referrals.mark-seen'), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') } });
}
</script>

<template>
    <AppLayout title="Mis referidos">
        <div class="max-w-4xl mx-auto py-8 px-4 space-y-8">
            <!-- Header -->
            <div>
                <h2 class="text-2xl font-light text-gray-900 dark:text-white tracking-tight m-0">Mis referidos</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 m-0">Comparte EzyVentas y gana premios por cada amigo que se suscriba.</p>
            </div>

             <!-- Instrucciones -->
            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-4">¿Cómo funciona?</h3>
                <div class="space-y-4">
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                            <span class="text-xs font-bold text-blue-500">1</span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white m-0">Comparte tu código</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1">Envía tu código único a otros negocios que quieran usar EzyVentas.</p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                            <span class="text-xs font-bold text-green-500">2</span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white m-0">Beneficio para tu amigo</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1">Obtiene {{ settings?.referred_discount_pct || 15 }}% de descuento en su primer pago.</p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                            <span class="text-xs font-bold text-amber-500">3</span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white m-0">Tu recompensa</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1">Recibes {{ settings?.referrer_reward_pct || 50 }}% de una mensualidad como premio único + {{ settings?.referrer_ongoing_discount_pct || 10 }}% de descuento en tu plan mientras tu referido esté activo.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Métricas -->
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
                        <span class="text-gray-300 mx-1">•</span>
                        <span class="text-gray-400">{{ referrals.length - activeReferralsCount }} inactivos</span>
                    </p>
                </div>
            </div>

            <!-- Costo de suscripción y descuento activo -->
            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                        <i class="pi pi-wallet !text-sm text-purple-500"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Costo actual de tu suscripción</h3>
                        <div v-if="referrerActiveDiscountPct > 0" class="flex items-baseline gap-2 mt-2">
                            <span class="text-lg text-gray-400 line-through font-mono">${{ (subscriptionCost || 0).toFixed(2) }}</span>
                            <span class="text-3xl font-light tracking-tight text-purple-600 dark:text-purple-400">${{ ((subscriptionCost || 0) * (1 - referrerActiveDiscountPct / 100)).toFixed(2) }}</span>
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400">/mes</span>
                        </div>
                        <div v-else class="flex items-baseline gap-2 mt-2">
                            <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">${{ (subscriptionCost || 0).toFixed(2) }}</span>
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400">/mes</span>
                        </div>
                        <div v-if="referrerActiveDiscountPct > 0" class="mt-3 bg-purple-50 dark:bg-purple-900/10 border border-purple-100 dark:border-purple-900/30 rounded-2xl p-3">
                            <p class="text-sm text-purple-800 dark:text-purple-200 m-0 flex items-center gap-2">
                                <i class="pi pi-check-circle !text-xs"></i>
                                Tienes un <strong>{{ referrerActiveDiscountPct }}% de descuento</strong> por tus referidos activos
                            </p>
                            <p class="text-xs text-purple-600 dark:text-purple-400 m-0 mt-1">
                                Aplicas descuento al pagar tu suscripción de forma mensual. Descuento estimado: <strong class="font-mono">${{ ((subscriptionCost || 0) * (referrerActiveDiscountPct / 100)).toFixed(2) }}</strong>
                            </p>
                        </div>
                        <p v-else class="text-xs text-gray-500 m-0 mt-2">Refiere a otros negocios y obtén descuento en tu plan.</p>
                    </div>
                </div>
            </div>

            <!-- Mi código -->
            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-4">Mi código de referido</h3>

                <div v-if="referralCode" class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl px-4 py-3">
                            <p class="text-2xl font-mono font-bold text-gray-900 dark:text-white tracking-widest m-0">{{ referralCode.code }}</p>
                        </div>
                        <Button icon="pi pi-copy" severity="secondary" text rounded class="!w-10 !h-10" @click="copyCode" v-tooltip.top="'Copiar código'" />
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-2xl p-4">
                        <p class="text-sm text-blue-700 dark:text-blue-300 m-0">{{ shareMessage }}</p>
                    </div>
                </div>

                <div v-else class="text-center py-6">
                    <p class="text-sm text-gray-500 dark:text-gray-400 m-0 mb-4">Aún no tienes un código de referido.</p>
                    <Button label="Generar mi código" icon="pi pi-ticket" severity="primary" @click="generateCode" class="!rounded-full" :loading="loadingCode" :disabled="loadingCode" />
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
                            {{ new Date(data.payment?.created_at).toLocaleDateString('es-MX') }}
                        </template>
                    </Column>
                    <Column header="Mensualidad base">
                        <template #body="{ data }">
                            ${{ parseFloat(data.monthly_base_amount).toFixed(2) }}
                        </template>
                    </Column>
                    <Column header="Premio">
                        <template #body="{ data }">
                            ${{ parseFloat(data.reward_amount).toFixed(2) }}
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag :value="rewardLabel(data.reward_status)" :severity="rewardSeverity(data.reward_status)" rounded />
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
