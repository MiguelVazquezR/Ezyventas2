<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// Importación de Componentes Modulares
import FinancialSummaryCard from './Partials/FinancialSummaryCard.vue';
import BankAccountsSummaryCard from './Partials/BankAccountsSummaryCard.vue';
import OperationalDetailsCard from './Partials/OperationalDetailsCard.vue';
import IncomeBreakdownCard from './Partials/IncomeBreakdownCard.vue';
import CashMovementsCard from './Partials/CashMovementsCard.vue';

const props = defineProps({
    session: Object,
    sessionTotals: Object,
    bankAccountSummary: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Historial de Cortes', url: route('cash-register-sessions.index') },
    { label: `Detalle de Corte #${props.session.id}` }
]);

const printReport = () => {
    window.open(route('cash-register-sessions.print', props.session.id), '_blank');
};
</script>

<template>
    <Head :title="`Detalle de Corte #${session.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        
        <!-- Header de la página -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Detalle de corte de caja #{{ session.id }}</h1>
                <div class="flex items-center gap-4 mt-1">
                    <p class="text-gray-500 dark:text-gray-400 m-0">
                        Abierto por: <span class="font-semibold">{{ session.opener.name }}</span> en la caja "{{ session.cash_register.name }}"
                    </p>
                    <div class="flex items-center gap-2">
                        <span class="text-gray-500 dark:text-gray-400">Participantes:</span>
                        <AvatarGroup>
                            <Avatar v-for="user in session.users" :key="user.id" :label="user.name.charAt(0).toUpperCase()" v-tooltip.bottom="user.name" shape="circle" />
                        </AvatarGroup>
                    </div>
                </div>
            </div>
            <Button 
                label="Imprimir reporte" 
                icon="pi pi-print" 
                severity="secondary" 
                outlined 
                @click="printReport"
                class="mt-4 sm:mt-0"
            />
        </div>

        <!-- Contenedor Principal (Grid Layout) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Izquierda (Principal) -->
            <div class="lg:col-span-2 space-y-6">
                <FinancialSummaryCard :session="session" :sessionTotals="sessionTotals" />
                <BankAccountsSummaryCard v-if="bankAccountSummary && bankAccountSummary.length > 0" :summary="bankAccountSummary" />
                <OperationalDetailsCard :session="session" />
            </div>
            
            <!-- Columna Derecha (Lateral) -->
            <div class="lg:col-span-1 space-y-6">
                <IncomeBreakdownCard :sessionTotals="sessionTotals" />
                <CashMovementsCard :cashMovements="session.cash_movements" />
            </div>
        </div>
    </AppLayout>
</template>