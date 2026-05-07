<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    serviceOrder: Object,
    totalPaid: Number,
    amountDue: Number,
    technicianCommissionCostNumeric: Number,
});

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};

const partsCost = computed(() => {
    if (!props.serviceOrder.items || props.serviceOrder.items.length === 0) return 0;
    
    return props.serviceOrder.items.reduce((total, item) => {
        if (item.itemable_type === 'App\\Models\\Product' || item.itemable_type === 'App\\Models\\ProductAttribute') {
            const cost = parseFloat(item.unit_price) || 0;
            const quantity = parseFloat(item.quantity) || 0;
            return total + (cost * quantity);
        }
        return total;
    }, 0);
});

// Nuevo cálculo de comisión basado en utilidad
const calculatedCommission = computed(() => {
    if (!props.serviceOrder.technician_name || !props.serviceOrder.technician_commission_value) return 0;

    if (props.serviceOrder.technician_commission_type === 'percentage') {
        const percentage = parseFloat(props.serviceOrder.technician_commission_value) || 0;
        const netRevenue = parseFloat(props.serviceOrder.final_total) || 0;
        const baseUtility = Math.max(0, netRevenue - partsCost.value); 
        return baseUtility * (percentage / 100);
    }

    return parseFloat(props.serviceOrder.technician_commission_value) || 0;
});

const profitAnalysis = computed(() => {
    const subtotal = parseFloat(props.serviceOrder.subtotal) || 0;
    const discount = parseFloat(props.serviceOrder.discount_amount) || 0;
    const netRevenue = parseFloat(props.serviceOrder.final_total) || 0;
    
    const commission = calculatedCommission.value;
    const parts = partsCost.value;

    const totalCosts = commission + parts;
    const netProfit = netRevenue - totalCosts;
    const margin = netRevenue > 0 ? (netProfit / netRevenue) * 100 : 0;

    return {
        subtotal,
        discount,
        netRevenue,
        commission,
        parts,
        totalCosts,
        netProfit,
        margin,
    };
});

const getPaymentMethodIcon = (method) => {
    const icons = {
        efectivo: { icon: 'pi pi-money-bill', color: 'text-green-600 dark:text-green-400' },
        tarjeta: { icon: 'pi pi-credit-card', color: 'text-blue-600 dark:text-blue-400' },
        transferencia: { icon: 'pi pi-arrows-h', color: 'text-orange-500 dark:text-orange-400' },
        saldo: { icon: 'pi pi-wallet', color: 'text-purple-500 dark:text-purple-400' },
    };
    return icons[method] || { icon: 'pi pi-circle', color: 'text-gray-500' };
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-3 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-3 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};
</script>

<template>
    <div class="space-y-6 lg:space-y-8 flex flex-col">
        
        <!-- Tarjeta 1: Estado de Cuenta -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
            <div class="mb-6 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                    <i class="pi pi-wallet !text-sm text-emerald-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Estado de cuenta</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Saldos de la reparación</p>
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Subtotal</span>
                    <span class="font-mono text-sm text-gray-900 dark:text-white m-0">{{ formatCurrency(serviceOrder.subtotal) }}</span>
                </div>
                
                <div v-if="serviceOrder.discount_amount > 0" class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1"><i class="pi pi-tag !text-[9px]"></i> Descuento</span>
                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(serviceOrder.discount_amount) }}</span>
                </div>
                
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3 pt-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-900 dark:text-gray-100 m-0">Total neto</span>
                    <span class="font-light tracking-tight text-xl text-gray-900 dark:text-white m-0">{{ formatCurrency(serviceOrder.final_total) }}</span>
                </div>
                
                <div class="flex justify-between items-center pt-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Monto abonado</span>
                    <span class="font-mono text-base font-bold text-green-600 dark:text-green-500 m-0">{{ formatCurrency(totalPaid) }}</span>
                </div>
            </div>

            <div v-if="amountDue > 0.01" class="mt-4 bg-red-50 dark:bg-red-900/10 p-5 rounded-2xl border border-red-100 dark:border-red-900/30 flex justify-between items-center">
                <span class="text-[10px] uppercase tracking-widest font-bold text-red-800 dark:text-red-400 m-0">Resta por pagar</span>
                <span class="font-light tracking-tight text-3xl leading-none text-red-600 dark:text-red-500 m-0">{{ formatCurrency(amountDue) }}</span>
            </div>
            <div v-else class="mt-4 bg-green-50 dark:bg-green-900/10 p-4 rounded-2xl border border-green-100 dark:border-green-900/30 flex justify-between items-center">
                <span class="text-[10px] uppercase tracking-widest font-bold text-green-800 dark:text-green-400 m-0 flex items-center gap-1.5"><i class="pi pi-check-circle !text-[10px]"></i> Servicio liquidado</span>
                <span class="font-mono text-lg font-bold text-green-600 dark:text-green-500 m-0">{{ formatCurrency(0) }}</span>
            </div>
        </div>

        <!-- Tarjeta 2: Análisis de Ganancia -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
            <div class="mb-6 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0 border border-cyan-100 dark:border-cyan-900/30">
                    <i class="pi pi-chart-pie !text-sm text-cyan-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Análisis de rentabilidad</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Costos y utilidades proyectadas</p>
                </div>
            </div>

            <ul class="m-0 p-0 list-none space-y-4">
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Ingresos brutos</span>
                    <span class="font-mono text-sm text-gray-900 dark:text-white m-0">{{ formatCurrency(profitAnalysis.subtotal) }}</span>
                </li>
                
                <li v-if="profitAnalysis.discount > 0" class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descuentos</span>
                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(profitAnalysis.discount) }}</span>
                </li>
                
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-900 dark:text-gray-100 m-0">Ingresos netos</span>
                    <span class="font-mono text-sm text-gray-900 dark:text-white font-bold m-0">{{ formatCurrency(profitAnalysis.netRevenue) }}</span>
                </li>
                
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1"><i class="pi pi-cog !text-[9px]"></i> Refacciones</span>
                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(profitAnalysis.parts) }}</span>
                </li>
                
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1"><i class="pi pi-user !text-[9px]"></i> Comisiones</span>
                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(profitAnalysis.commission) }}</span>
                </li>
                
                <li class="flex justify-between items-center pt-2">
                    <div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-cyan-600 dark:text-cyan-500 m-0 block">Utilidad libre</span>
                        <span class="text-[10px] text-gray-500 m-0 mt-0.5">Margen: {{ profitAnalysis.margin.toFixed(2) }}%</span>
                    </div>
                    <span class="font-light tracking-tight text-3xl leading-none text-green-600 dark:text-green-500 m-0">{{ formatCurrency(profitAnalysis.netProfit) }}</span>
                </li>
            </ul>
        </div>

        <!-- Tarjeta 3: Pagos Registrados -->
        <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
            <div class="mb-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-receipt !text-sm text-blue-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Anticipos y pagos</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Registro histórico</p>
                </div>
            </div>

            <div v-if="serviceOrder.transaction && serviceOrder.transaction.payments?.length > 0" class="bg-blue-50 dark:bg-blue-900/10 p-3 rounded-2xl flex items-start gap-2 border border-blue-100 dark:border-blue-900/30 mb-4">
                <i class="pi pi-info-circle mt-0.5 !text-sm text-blue-500"></i>
                <p class="text-[10px] uppercase tracking-widest text-blue-800 dark:text-blue-300 m-0 leading-relaxed font-bold">
                    Para editar un pago ve a la venta <br> <Link :href="route('transactions.show', serviceOrder.transaction.id)" class="text-blue-600 dark:text-blue-400 hover:underline">#{{ serviceOrder.transaction.folio }}</Link>
                </p>
            </div>

            <DataTable :value="serviceOrder.transaction?.payments" responsiveLayout="scroll" :pt="dataTablePt">
                
                <Column field="payment_date" header="Fecha">
                    <template #body="{ data }">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">{{ formatDate(data.payment_date) }}</span>
                    </template>
                </Column>
                
                <Column field="payment_method" header="Método">
                    <template #body="{ data }">
                        <div class="flex flex-col gap-0.5">
                            <div class="flex items-center gap-2">
                                <i :class="[getPaymentMethodIcon(data.payment_method).icon, getPaymentMethodIcon(data.payment_method).color]" class="!text-[10px]"></i>
                                <span class="capitalize text-sm font-medium text-gray-900 dark:text-white m-0">{{ data.payment_method.replace('_', ' ') }}</span>
                            </div>
                            <span v-if="data.bank_account" class="text-[9px] uppercase tracking-widest text-gray-500 ml-4 pl-0.5 truncate max-w-[120px]" v-tooltip.bottom="data.bank_account.account_name">
                                {{ data.bank_account.account_name }}
                            </span>
                        </div>
                    </template>
                </Column>
                
                <Column field="amount" header="Monto" class="text-right" headerClass="text-right">
                    <template #body="{ data }">
                        <span class="font-mono text-sm font-bold text-green-600 dark:text-green-500 m-0">{{ formatCurrency(data.amount) }}</span>
                    </template>
                </Column>
                
                <template #empty>
                    <div class="text-center py-6">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 block mb-1">Sin ingresos</span>
                        <span class="text-xs text-gray-400 italic m-0 block">No se han registrado pagos para esta orden.</span>
                    </div>
                </template>
            </DataTable>
        </div>
        
    </div>
</template>