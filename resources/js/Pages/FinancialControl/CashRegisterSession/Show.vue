<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Avatar from 'primevue/avatar';
import AvatarGroup from 'primevue/avatargroup';

// Importación de Componentes Modulares
import FinancialSummaryCard from './Partials/FinancialSummaryCard.vue';
import BankAccountsSummaryCard from './Partials/BankAccountsSummaryCard.vue';
import OperationalDetailsCard from './Partials/OperationalDetailsCard.vue';
import IncomeBreakdownCard from './Partials/IncomeBreakdownCard.vue';

const props = defineProps({
    session: Object,
    sessionTotals: Object,
    bankAccountSummary: Array,
});

const printReport = () => {
    window.open(route('cash-register-sessions.print', props.session.id), '_blank');
};

const formatDateTime = (dateString) => {
    if (!dateString) return 'En progreso';
    const date = new Date(dateString);
    return date.toLocaleString('es-MX', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <Head :title="`Detalle de Corte #${session.id}`" />
    <AppLayout>
        
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('cash-register-sessions.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al historial de cortes
                </Link>
            </div>

            <!-- Header de la página al estilo Tesla UI -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col xl:flex-row justify-between items-start xl:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Corte de caja #{{ session.id }}
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full" :class="session.closed_at ? 'bg-gray-400 dark:bg-gray-600' : 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse'"></span>
                            {{ session.cash_register.name }}
                        </p>
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Cierre:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                <i class="pi pi-calendar-times !text-[10px] text-gray-400"></i>
                                {{ formatDateTime(session.closed_at) }}
                            </span>
                        </div>
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Operador:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ session.opener.name }}</span>
                        </div>
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Participantes:</span>
                            <AvatarGroup>
                                <Avatar v-for="user in session.users" :key="user.id" :label="user.name.charAt(0).toUpperCase()" v-tooltip.bottom="user.name" shape="circle" class="!w-6 !h-6 !text-[10px]" />
                            </AvatarGroup>
                        </div>
                    </div>
                </div>
                
                <Button 
                    label="Imprimir reporte" 
                    icon="pi pi-print" 
                    severity="secondary" 
                    @click="printReport"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full xl:w-auto shrink-0"
                />
            </div>

            <!-- Contenedor Principal (Grid Layout reestructurado) -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                
                <!-- Fila 1: Resumen y Movimientos FUSIONADOS (Ocupa todo el ancho) -->
                <div class="xl:col-span-3">
                    <FinancialSummaryCard :session="session" :sessionTotals="sessionTotals" />
                </div>
                
                <!-- Fila 2: Detalles Operativos (Columna Izquierda - Span 2) -->
                <div class="xl:col-span-2 space-y-6 flex flex-col">
                    <OperationalDetailsCard :session="session" />
                </div>
                
                <!-- Fila 2: Cuentas y Desgloses (Columna Derecha - Span 1) -->
                <div class="xl:col-span-1 space-y-6 flex flex-col">
                    <IncomeBreakdownCard :sessionTotals="sessionTotals" />
                    
                    <BankAccountsSummaryCard v-if="bankAccountSummary && bankAccountSummary.length > 0" :summary="bankAccountSummary" />
                </div>
                
            </div>
        </div>
    </AppLayout>
</template>