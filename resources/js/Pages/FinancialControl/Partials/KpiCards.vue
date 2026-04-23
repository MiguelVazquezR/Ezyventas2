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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <!-- Ventas -->
        <Card @click="$emit('open-sales')" class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
            <template #content>
                <div class="flex items-center justify-between mb-2"> 
                    <span class="text-gray-500">Ventas totales (clic para detalles)</span> 
                    <i class="pi pi-shopping-cart p-2 bg-purple-100 text-purple-600 rounded-full"></i> 
                </div>
                <p class="text-2xl font-bold">{{ formatCurrency(kpis.sales.current) }}</p>
                <div class="flex items-center text-sm mt-1" :class="kpis.sales.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> 
                    <i class="pi" :class="kpis.sales.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> 
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.sales.percentage_change) }}%</span>
                    <span>({{ formatCurrency(kpis.sales.monetary_change) }})</span> 
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.sales.previous) }} periodo ant.</p>
            </template> 
        </Card>

        <!-- Pagos -->
        <Card @click="$emit('open-payments')" class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
            <template #content>
                <div class="flex items-center justify-between mb-2"> 
                    <span class="text-gray-500">Total de pagos (clic para detalles)</span> 
                    <i class="pi pi-dollar p-2 bg-cyan-100 text-cyan-600 rounded-full"></i>
                </div>
                <p class="text-2xl font-bold">{{ formatCurrency(kpis.payments.current) }}</p>
                <div class="flex items-center text-sm mt-1" :class="kpis.payments.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> 
                    <i class="pi" :class="kpis.payments.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.payments.percentage_change) }}%</span>
                    <span>({{ formatCurrency(kpis.payments.monetary_change) }})</span> 
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.payments.previous) }} periodo ant.</p>
            </template> 
        </Card>

        <!-- Gastos -->
        <Card @click="$emit('open-expenses')" class="cursor-pointer hover:shadow-lg transition-shadow duration-150">
            <template #content>
                <div class="flex items-center justify-between mb-2"> 
                    <span class="text-gray-500">Total de gastos (clic para detalles)</span> 
                    <i class="pi pi-arrow-up-right p-2 bg-yellow-100 text-yellow-600 rounded-full"></i> 
                </div>
                <p class="text-2xl font-bold">{{ formatCurrency(kpis.expenses.current) }}</p>
                <div class="flex items-center text-sm mt-1" :class="kpis.expenses.percentage_change <= 0 ? 'text-green-500' : 'text-red-500'"> 
                    <i class="pi" :class="kpis.expenses.percentage_change <= 0 ? 'pi-arrow-down' : 'pi-arrow-up'"></i>
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.expenses.percentage_change) }}%</span>
                    <span>({{ formatCurrency(kpis.expenses.monetary_change) }})</span> 
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.expenses.previous) }} periodo ant.</p>
            </template>
        </Card>

        <!-- Ganancia Neta -->
        <Card> 
            <template #content>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-500">Ganancia neta (ventas totales - gastos)</span>
                    <i class="pi pi-chart-line p-2 bg-teal-100 text-teal-600 rounded-full"></i>
                </div>
                <p class="text-2xl font-bold" :class="kpis.netProfit.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">
                    {{ formatCurrency(kpis.netProfit.current) }} 
                </p>
                <div class="flex items-center text-sm mt-1" :class="kpis.netProfit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> 
                    <i class="pi" :class="kpis.netProfit.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.netProfit.percentage_change) }}%</span>
                    <span>({{ formatCurrency(kpis.netProfit.monetary_change) }})</span> 
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.netProfit.previous) }} periodo ant.</p>
            </template> 
        </Card>

        <!-- Flujo de Dinero Neto -->
        <Card> 
            <template #content>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-500">Flujo de dinero neto (total de pagos - gastos)</span>
                    <i class="pi pi-wallet p-2 bg-green-100 text-green-600 rounded-full"></i>
                </div>
                <p class="text-2xl font-bold" :class="kpis.profit.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">
                    {{ formatCurrency(kpis.profit.current) }}
                </p>
                <div class="flex items-center text-sm mt-1" :class="kpis.profit.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'"> 
                    <i class="pi" :class="kpis.profit.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i> 
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.profit.percentage_change) }}%</span>
                    <span>({{ formatCurrency(kpis.profit.monetary_change) }})</span> 
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.profit.previous) }} periodo ant.</p>
            </template> 
        </Card>

        <!-- KPI: Ticket Promedio -->
        <Card> 
            <template #content>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-500">Monto promedio por venta</span>
                    <i class="pi pi-receipt p-2 bg-blue-100 text-blue-600 rounded-full"></i>
                </div>
                <p class="text-2xl font-bold">{{ formatCurrency(kpis.averageTicket.current) }}</p>
                <div class="flex items-center text-sm mt-1" :class="kpis.averageTicket.percentage_change >= 0 ? 'text-green-500' : 'text-red-500'">
                    <i class="pi" :class="kpis.averageTicket.percentage_change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.averageTicket.percentage_change) }}%</span>
                    <span>({{ formatCurrency(kpis.averageTicket.monetary_change) }})</span>
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ formatCurrency(kpis.averageTicket.previous) }} periodo ant.</p>
            </template>
        </Card>
        
        <!-- Margen de Utilidad -->
        <Card>
            <template #content>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-500">% Margen de utilidad</span>
                    <i class="pi pi-percentage p-2 bg-orange-100 text-orange-600 rounded-full"></i>
                </div>
                <p class="text-2xl font-bold" :class="kpis.utilityMargin.current >= 0 ? 'text-gray-800 dark:text-gray-200' : 'text-red-600'">
                    {{ kpis.utilityMargin.current }}%
                </p>
                <div class="flex items-center text-sm mt-1" :class="kpis.utilityMargin.change >= 0 ? 'text-green-500' : 'text-red-500'">
                    <i class="pi" :class="kpis.utilityMargin.change >= 0 ? 'pi-arrow-up' : 'pi-arrow-down'"></i>
                    <span class="font-semibold mx-1">{{ Math.abs(kpis.utilityMargin.change) }}%</span>
                    <span>(puntos)</span>
                </div>
                <p class="text-xs text-gray-400 mt-2">vs {{ kpis.utilityMargin.previous }}% periodo ant.</p>
            </template>
        </Card>
    </div>
</template>