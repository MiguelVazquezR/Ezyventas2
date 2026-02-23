<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
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
    // RECIBIMOS LA URL LIMPIA DESDE EL CONTROLADOR
    fiscalDocumentUrl: String,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([{ label: 'Mi suscripción' }]);

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
    <AppLayout title="Mi suscripción">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 mb-6" />

        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Mi suscripción</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Aquí puedes ver los detalles de tu plan, historial de
                    pagos, gestión de sucursales, cuentas bancarias e información fiscal.
                </p>
            </header>

            <Message v-if="pendingPayment" severity="info" :closable="false" class="mb-6">
                Tu pago de {{ formatCurrency(pendingPayment.amount) }} por transferencia está en revisión.
                Tu plan se activará automáticamente una vez aprobado.
            </Message>
            <Message v-if="lastRejectedPayment" severity="error" :closable="false" class="mb-6">
                <div class="flex flex-col">
                    <span class="font-bold">Tu último pago fue rechazado.</span>
                    <p class="m-0">Motivo: {{ lastRejectedPayment.payment_details.rejection_reason }}</p>
                    <p class="m-0 mt-2">
                        Por favor, ve a
                        <Link :href="route('subscription.manage')" class="font-bold underline">
                            Gestionar suscripción
                        </Link>
                        para intentarlo de nuevo.
                    </p>
                </div>
            </Message>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna Izquierda -->
                <div class="lg:col-span-1 space-y-6">
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
                <div class="lg:col-span-2 space-y-6">
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