<script setup>

const props = defineProps({
    order: {
        type: Object,
        required: true
    },
    canSeeDetails: {
        type: Boolean,
        default: false
    },
    canEdit: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['go-to-details', 'go-to-edit']);

// --- Helpers ---
const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

const formatFriendlyDate = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const d = new Date(dateString);
        const day = d.getDate();
        const month = new Intl.DateTimeFormat('es-MX', { month: 'short' }).format(d);
        let hour = d.getHours();
        const minute = d.getMinutes().toString().padStart(2, '0');
        const ampm = hour >= 12 ? 'pm' : 'am';
        hour = hour % 12;
        hour = hour ? hour : 12;
        return `${day} ${month}, ${hour}:${minute} ${ampm}`;
    } catch (e) {
        return dateString;
    }
};

const getStatusSeverity = (status) => {
    if (!status) return 'secondary';
    const map = {
        cancelado: 'danger',
        pendiente: 'warn',
        en_progreso: 'info',
        esperando_refaccion: 'secondary',
        terminado: 'success',
        entregado: 'success',
    };
    return map[status] || 'secondary';
};

const getOrderTotalPaid = (order) => {
    return (Array.isArray(order?.transaction?.payments) ? order.transaction.payments : [])
        .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
};

const getOrderPending = (order) => {
    if (!order) return 0;
    const total = parseFloat(order.final_total || 0);
    const paid = getOrderTotalPaid(order);
    return Math.max(0, total - paid);
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Scrollable Content -->
        <div class="flex-grow space-y-6 overflow-y-auto pb-6 px-6 pt-6 custom-scrollbar">
            
            <!-- Info Header -->
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50">
                    <i class="pi pi-clipboard !text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Orden {{ order.folio }}</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1.5 flex items-center gap-1">
                        <i class="pi pi-user !text-[9px]"></i> {{ order.customer_name || 'Público general' }}
                    </p>
                </div>
            </div>

            <!-- Tiempos y Estado -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0">Parámetros de servicio</h3>
                    <Tag :value="(order.status || '').replace('_', ' ')" :severity="getStatusSeverity(order.status)" :pt="tagPt" />
                </div>
                
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3 pt-2">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Ingreso</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-white flex items-center gap-1">
                        <i class="pi pi-calendar-plus !text-xs text-gray-400"></i>
                        {{ formatFriendlyDate(order.received_at) }}
                    </span>
                </div>

                <div class="flex justify-between items-center pt-1 border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Entrega promesa</span>
                    <span class="font-medium text-sm text-primary-600 dark:text-primary-400 flex items-center gap-1">
                        <i class="pi pi-flag !text-xs text-primary-400"></i>
                        {{ formatFriendlyDate(order.promised_at) }}
                    </span>
                </div>
                
                <div class="flex justify-between items-center pt-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Técnico Asignado</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-white truncate max-w-[150px]" :title="order.technician_name">
                        {{ order.technician_name || 'Sin asignar' }}
                    </span>
                </div>
            </div>

            <!-- Datos del Equipo -->
            <div class="space-y-3 bg-blue-50 dark:bg-blue-900/10 p-5 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                <h3 class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0">Equipo / Dispositivo</h3>
                <p class="font-semibold text-gray-900 dark:text-white text-sm m-0">{{ order.item_description }}</p>
                
                <div v-if="order.reported_problems" class="mt-3 pt-3 border-t border-blue-200 dark:border-blue-900/40">
                    <span class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 block mb-1">Falla reportada:</span>
                    <p class="text-sm text-gray-700 dark:text-gray-300 m-0 leading-relaxed">{{ order.reported_problems }}</p>
                </div>
            </div>

            <!-- Lista de Conceptos -->
            <div class="space-y-3 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-3">Conceptos registrados</h3>
                <ul v-if="order.items && order.items.length" class="flex flex-col gap-3 m-0 p-0 list-none">
                    <li v-for="item in order.items" :key="item.id" class="flex justify-between items-center text-sm border-b border-gray-200 dark:border-[#2a2a2a] pb-2 last:border-0 last:pb-0">
                        <div class="flex flex-col flex-1 pr-2">
                            <span class="font-medium text-gray-800 dark:text-gray-200 leading-tight">
                                <span class="text-gray-400 mr-1">{{ Math.round(item.quantity) }}x</span>
                                {{ item.description }}
                            </span>
                        </div>
                        <span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</span>
                    </li>
                </ul>
                <div v-else class="text-xs text-gray-400 italic text-center py-2">
                    No hay conceptos agregados a esta orden.
                </div>
            </div>

            <!-- Historial de Pagos -->
            <div class="space-y-3 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-3">Historial de pagos</h3>
                <ul v-if="order.transaction && order.transaction.payments && order.transaction.payments.length" class="flex flex-col gap-4 m-0 p-0 list-none relative border-l-2 border-gray-200 dark:border-[#3a3a3a] ml-2 pl-4 py-1">
                    <li v-for="payment in order.transaction.payments" :key="payment.id" class="flex flex-col text-sm relative">
                        <div class="absolute w-2 h-2 bg-green-500 rounded-full -left-[18px] top-1.5 shadow-[0_0_5px_rgba(34,197,94,0.5)]"></div>
                        <div class="flex justify-between items-center">
                            <span class="font-medium capitalize text-gray-800 dark:text-gray-200">{{ (payment.payment_method || 'Desconocido').replace(/_/g, ' ') }}</span>
                            <span class="font-mono font-bold text-green-600 dark:text-green-400">{{ formatCurrency(payment.amount) }}</span>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 mt-1">{{ formatFriendlyDate(payment.created_at) }}</span>
                    </li>
                </ul>
                <div v-else class="text-xs text-gray-400 italic text-center py-2">No se han registrado pagos (anticipos).</div>
            </div>

            <!-- Resumen Financiero Total -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-2">
                <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Total de la orden</span>
                    <span class="font-mono">{{ formatCurrency(order.final_total) }}</span>
                </div>
                <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Abonado</span>
                    <span class="font-mono">{{ formatCurrency(getOrderTotalPaid(order)) }}</span>
                </div>
                <div class="flex justify-between items-center pt-2">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Resta por pagar</span>
                    <span class="font-light tracking-tight text-3xl leading-none m-0" :class="getOrderPending(order) > 0 ? 'text-red-500' : 'text-green-500'">
                        {{ formatCurrency(getOrderPending(order)) }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Actions Footer -->
        <div class="p-6 border-t border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3 bg-white dark:bg-[#232323]">
            <Button 
                v-if="canSeeDetails" 
                label="Ver detalles completos" 
                icon="pi pi-eye" 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-details')" 
            />
            <Button 
                v-if="canEdit" 
                label="Editar orden" 
                icon="pi pi-pencil" 
                severity="secondary" 
                outlined 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-edit')" 
            />
        </div>
    </div>
</template>