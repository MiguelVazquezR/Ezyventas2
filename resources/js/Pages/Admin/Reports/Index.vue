<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    metrics: Object
});

// --- HELPER FUNCTIONS ---
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const currentMonthName = computed(() => {
    return new Intl.DateTimeFormat('es-MX', { month: 'long' }).format(new Date());
});
</script>

<template>
    <AppLayout title="Reportes y Métricas">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header (Tesla UI) -->
                <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-gray-100 dark:border-[#3a3a3a] pb-6">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Métricas del sistema</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                            Rendimiento general y telemetría financiera
                        </p>
                    </div>
                    
                    <!-- Indicador de Fecha/Corte -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] px-4 py-2.5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] inline-flex items-center gap-3 shrink-0">
                        <i class="pi pi-calendar text-gray-400 !text-sm"></i>
                        <div class="flex flex-col">
                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0 leading-none">Periodo actual</span>
                            <span class="text-sm font-medium text-gray-900 dark:text-white capitalize">{{ currentMonthName }} {{ new Date().getFullYear() }}</span>
                        </div>
                    </div>
                </div>

                <!-- Cuadrícula de KPIs -->
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                    
                    <!-- KPI 1: Ingresos Totales -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-wallet text-6xl text-primary-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Ingreso histórico (Acumulado)
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1">
                            {{ formatCurrency(metrics.totalRevenue) }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">Monto total de pagos aprobados</p>
                    </div>

                    <!-- KPI 2: Ingresos del Mes -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-chart-line text-6xl text-green-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Ingresos de {{ currentMonthName }}
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1 text-green-600 dark:text-green-400">
                            {{ formatCurrency(metrics.monthlyRevenue) }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">Pagos liquidados en este mes</p>
                    </div>

                    <!-- KPI 3: Suscripciones Activas -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-check-circle text-6xl text-blue-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Clientes activos
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1">
                            {{ metrics.activeSubscriptions }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">Con plan vigente al día de hoy</p>
                    </div>

                    <!-- KPI 4: Nuevas Altas -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-6 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class="pi pi-users text-6xl text-orange-500"></i>
                        </div>
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            Nuevas suscripciones
                        </h2>
                        <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white mt-4 mb-1 text-orange-600 dark:text-orange-400">
                            +{{ metrics.newSubscriptions }}
                        </span>
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest m-0">Suscripciones creadas este mes</p>
                    </div>

                </div>

                <!-- Sección para Gráficos/Análisis Futuro -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col items-center justify-center text-center py-16">
                    <i class="pi pi-chart-bar !text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Módulo gráfico en preparación</p>
                    <p class="text-sm text-gray-400 mt-2 max-w-md">Pronto agregaremos gráficas detalladas de evolución de MRR (Ingresos Mensuales Recurrentes) y tasas de retención (Churn).</p>
                </div>

            </div>
        </div>
    </AppLayout>
</template>