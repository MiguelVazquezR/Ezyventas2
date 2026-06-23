<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// --- Imports de los parciales ---
import GeneralInfoCard from './Partials/GeneralInfoCard.vue';
import FiscalInfoCard from './Partials/FiscalInfoCard.vue';
import PlanDetailsCard from './Partials/PlanDetailsCard.vue';
import BranchesCard from './Partials/BranchesCard.vue';
import BankAccountsCard from './Partials/BankAccountsCard.vue';
import HistoryCard from './Partials/HistoryCard.vue';

const props = defineProps({
    subscription: Object,
    planItems: Array,
    usageData: Object,
    subscriptionStatus: Object,
    pendingPayment: Object, 
    lastRejectedPayment: Object,
    fiscalDocumentUrl: String,
});

// --- Helpers Globales para pasar a hijos ---
const mainBranch = computed(() => {
    return props.subscription.branches.find(b => b.is_main) || props.subscription.branches[0];
});

const currentVersion = computed(() => props.subscription?.versions?.[0] || null);

const activeLimits = computed(() => {
    if (!currentVersion.value) return [];
    return currentVersion.value.items.filter(item => item.item_type === 'limit');
});

const branchLimit = computed(() => {
    if (!activeLimits.value) return null;
    return activeLimits.value.find(l => l.item_key === 'limit_branches');
});

const branchUsage = computed(() => props.usageData?.branches ?? 0);

const branchLimitReached = computed(() => {
    if (!branchLimit.value || branchLimit.value.quantity === -1) {
        return false;
    }
    return branchUsage.value >= branchLimit.value.quantity;
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>
    <Head title="Mi suscripción" />
    <AppLayout>
        
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('dashboard')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al panel principal
                </Link>
            </div>

            <!-- Header Principal -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Mi suscripción</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de plan, sucursales y facturación
                    </p>
                </div>
            </div>

            <!-- Alerta: Pago Pendiente (solo transferencia — MercadoPago se auto-aprueba) -->
            <div v-if="pendingPayment" class="bg-blue-50 dark:bg-blue-900/10 p-5 rounded-2xl flex items-start gap-4 border border-blue-100 dark:border-blue-900/30">
                <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                    <i class="pi pi-info-circle !text-lg text-blue-600 dark:text-blue-400"></i>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-widest m-0 mb-1">Pago en revisión</p>
                    <div v-if="pendingPayment.referral_discount_pct" class="space-y-1">
                        <p class="text-sm text-blue-800 dark:text-blue-200 m-0 leading-relaxed tracking-tight">
                            Tu pago de <strong class="font-bold font-mono line-through text-blue-500">{{ formatCurrency(parseFloat(pendingPayment.amount) + parseFloat(pendingPayment.referral_discount_amount || 0)) }}</strong> con un descuento del <strong class="font-bold">{{ pendingPayment.referral_discount_pct }}%</strong> por referido quedó en <strong class="font-bold font-mono">{{ formatCurrency(pendingPayment.amount) }}</strong> por {{ pendingPayment.payment_method === 'mercadopago' ? 'Mercado Pago' : 'transferencia' }}.
                        </p>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-green-600 dark:text-green-400 uppercase tracking-widest bg-green-100 dark:bg-green-900/20 px-2 py-0.5 rounded-full">
                            <i class="pi pi-check-circle !text-[10px]"></i> descuento por referido aplicado
                        </span>
                    </div>
                    <p v-else class="text-sm text-blue-900 dark:text-blue-200 m-0 leading-relaxed tracking-tight">
                        Tu pago de <strong class="font-bold font-mono">{{ formatCurrency(pendingPayment.amount) }}</strong> por transferencia está en revisión. 
                        Tu plan se activará automáticamente una vez aprobado por nuestro equipo.
                    </p>
                </div>
            </div>

            <!-- Alerta: Pago Rechazado -->
            <div v-if="lastRejectedPayment" class="bg-red-50 dark:bg-red-900/10 p-5 rounded-2xl flex items-start gap-4 border border-red-100 dark:border-red-900/30">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <i class="pi pi-exclamation-circle !text-lg text-red-600 dark:text-red-400"></i>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-red-600 dark:text-red-400 uppercase tracking-widest m-0 mb-1">Pago rechazado</p>
                    <p class="text-sm text-red-900 dark:text-red-200 m-0 leading-relaxed tracking-tight mb-2">
                        Tu último pago fue rechazado por el siguiente motivo: <strong class="font-bold">{{ lastRejectedPayment.payment_details.rejection_reason }}</strong>
                    </p>
                    <Link :href="route('subscription.manage')">
                        <Button label="Gestionar pago nuevamente" size="small" severity="danger" outlined class="!rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold" />
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- Columna Izquierda -->
                <div class="lg:col-span-1 space-y-6 lg:space-y-8">
                    <GeneralInfoCard 
                        :subscription="subscription" 
                        :main-branch="mainBranch" 
                        :pending-payment="pendingPayment" 
                    />
                    
                    <FiscalInfoCard 
                        :fiscal-document-url="fiscalDocumentUrl" 
                    />
                </div>

                <!-- Columna Derecha -->
                <div class="lg:col-span-2 space-y-6 lg:space-y-8">
                    <PlanDetailsCard 
                        :current-version="currentVersion"
                        :plan-items="planItems"
                        :usage-data="usageData"
                        :active-limits="activeLimits"
                        :subscription-status="subscriptionStatus"
                        :pending-payment="pendingPayment"
                        :last-rejected-payment="lastRejectedPayment"
                    />

                    <BranchesCard 
                        :subscription="subscription"
                        :branch-limit="branchLimit"
                        :branch-usage="branchUsage"
                        :branch-limit-reached="branchLimitReached"
                    />

                    <BankAccountsCard 
                        :subscription="subscription" 
                    />

                    <HistoryCard 
                        :subscription="subscription" 
                        :fiscal-document-url="fiscalDocumentUrl" 
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>