<script setup>
const props = defineProps({
    kpis: Object
});

const emit = defineEmits(['open-sales', 'open-payments', 'open-expenses']);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Ventas -->
        <div @click="$emit('open-sales')" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-all flex flex-col justify-between group">
            <div class="flex items-start justify-between mb-6"> 
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Ventas totales</h2> 
                    <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0 opacity-0 group-hover:opacity-100 transition-opacity">Clic para detalles</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                    <i class="pi pi-shopping-cart !text-sm text-purple-500"></i> 
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(kpis.sales.current) }}</p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]"> 
                    <div class="flex items-center gap-1" :class="kpis.sales.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.sales.percentage_change >= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.sales.percentage_change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">({{ formatCurrency(kpis.sales.monetary_change) }})</span> 
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ formatCurrency(kpis.sales.previous) }} periodo ant.</p>
            </div>
        </div>

        <!-- Pagos -->
        <div @click="$emit('open-payments')" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-all flex flex-col justify-between group">
            <div class="flex items-start justify-between mb-6"> 
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Total de pagos</h2> 
                    <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0 opacity-0 group-hover:opacity-100 transition-opacity">Clic para detalles</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0 border border-cyan-100 dark:border-cyan-900/30">
                    <i class="pi pi-dollar !text-sm text-cyan-500"></i>
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(kpis.payments.current) }}</p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]"> 
                    <div class="flex items-center gap-1" :class="kpis.payments.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.payments.percentage_change >= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.payments.percentage_change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">({{ formatCurrency(kpis.payments.monetary_change) }})</span> 
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ formatCurrency(kpis.payments.previous) }} periodo ant.</p>
            </div>
        </div>

        <!-- Gastos -->
        <div @click="$emit('open-expenses')" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-all flex flex-col justify-between group">
            <div class="flex items-start justify-between mb-6"> 
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Total de gastos</h2> 
                    <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0 opacity-0 group-hover:opacity-100 transition-opacity">Clic para detalles</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-yellow-50 dark:bg-yellow-900/20 flex items-center justify-center flex-shrink-0 border border-yellow-100 dark:border-yellow-900/30">
                    <i class="pi pi-arrow-up-right !text-sm text-yellow-500"></i> 
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(kpis.expenses.current) }}</p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]"> 
                    <div class="flex items-center gap-1" :class="kpis.expenses.percentage_change <= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.expenses.percentage_change <= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.expenses.percentage_change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">({{ formatCurrency(kpis.expenses.monetary_change) }})</span> 
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ formatCurrency(kpis.expenses.previous) }} periodo ant.</p>
            </div>
        </div>

        <!-- Ganancia Neta -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-between"> 
            <div class="flex items-start justify-between mb-6">
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Ganancia neta</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Ventas totales - gastos</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-teal-50 dark:bg-teal-900/20 flex items-center justify-center flex-shrink-0 border border-teal-100 dark:border-teal-900/30">
                    <i class="pi pi-chart-line !text-sm text-teal-500"></i>
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight m-0" :class="kpis.netProfit.current >= 0 ? 'text-gray-900 dark:text-white' : 'text-red-500'">
                    {{ formatCurrency(kpis.netProfit.current) }} 
                </p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]"> 
                    <div class="flex items-center gap-1" :class="kpis.netProfit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.netProfit.percentage_change >= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.netProfit.percentage_change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">({{ formatCurrency(kpis.netProfit.monetary_change) }})</span> 
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ formatCurrency(kpis.netProfit.previous) }} periodo ant.</p>
            </div>
        </div>

        <!-- Flujo de Dinero Neto -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-between"> 
            <div class="flex items-start justify-between mb-6">
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Flujo de dinero neto</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Total de pagos - gastos</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                    <i class="pi pi-wallet !text-sm text-green-500"></i>
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight m-0" :class="kpis.profit.current >= 0 ? 'text-gray-900 dark:text-white' : 'text-red-500'">
                    {{ formatCurrency(kpis.profit.current) }}
                </p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]"> 
                    <div class="flex items-center gap-1" :class="kpis.profit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.profit.percentage_change >= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.profit.percentage_change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">({{ formatCurrency(kpis.profit.monetary_change) }})</span> 
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ formatCurrency(kpis.profit.previous) }} periodo ant.</p>
            </div>
        </div>

        <!-- KPI: Ticket Promedio -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-between"> 
            <div class="flex items-start justify-between mb-6">
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Ticket promedio</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Monto promedio por venta</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-receipt !text-sm text-blue-500"></i>
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(kpis.averageTicket.current) }}</p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center gap-1" :class="kpis.averageTicket.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.averageTicket.percentage_change >= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.averageTicket.percentage_change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">({{ formatCurrency(kpis.averageTicket.monetary_change) }})</span>
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ formatCurrency(kpis.averageTicket.previous) }} periodo ant.</p>
            </div>
        </div>
        
        <!-- Margen de Utilidad -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-between">
            <div class="flex items-start justify-between mb-6">
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Margen de utilidad</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Porcentaje de ganancia</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                    <i class="pi pi-percentage !text-sm text-orange-500"></i>
                </div>
            </div>
            <div>
                <p class="text-4xl lg:text-5xl font-light tracking-tight m-0" :class="kpis.utilityMargin.current >= 0 ? 'text-gray-900 dark:text-white' : 'text-red-500'">
                    {{ kpis.utilityMargin.current }}%
                </p>
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center gap-1" :class="kpis.utilityMargin.change >= 0 ? 'text-green-500' : 'text-red-500'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="kpis.utilityMargin.change >= 0 ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></span>
                        <span class="text-xs font-bold uppercase tracking-widest">{{ Math.abs(kpis.utilityMargin.change) }}%</span>
                    </div>
                    <span class="text-[10px] text-gray-500 uppercase tracking-widest">(puntos)</span>
                </div>
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 m-0">vs {{ kpis.utilityMargin.previous }}% periodo ant.</p>
            </div>
        </div>
    </div>
</template>