<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/Composables';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    },
    technicianCommissionCostNumeric: {
        type: Number,
        required: false
    }
});

const { hasPermission } = usePermissions();

// --- Lógica extraída de la vista principal ---

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });
};

const getStatusSeverity = (status) => {
    const map = { pendiente: 'warn', en_progreso: 'info', esperando_refaccion: 'secondary', terminado: 'success', entregado: 'success', cancelado: 'danger' };
    return map[status] || 'secondary';
};

const whatsappLink = computed(() => {
    if (!props.serviceOrder.customer_phone) return '#';
    const sanitizedPhone = props.serviceOrder.customer_phone.replace(/\D/g, '');
    return `https://wa.me/${sanitizedPhone.length === 10 ? `52${sanitizedPhone}` : sanitizedPhone}`;
});

const deliveryDate = computed(() => {
    if (props.serviceOrder.status === 'entregado' && props.serviceOrder.transaction?.payments?.length > 0) {
        const latestPayment = props.serviceOrder.transaction.payments.reduce((latest, current) => {
            return new Date(current.payment_date) > new Date(latest.payment_date) ? current : latest;
        });
        return latestPayment.payment_date;
    }
    return null;
});

// Traemos la lógica del costo de refacciones para poder calcular la utilidad
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

const technicianCommission = computed(() => {
    if (!props.serviceOrder.technician_name || !props.serviceOrder.technician_commission_value) {
        return 'N/A';
    }
    const value = parseFloat(props.serviceOrder.technician_commission_value);
    const formattedAmount = formatCurrency(calculatedCommission.value);

    if (props.serviceOrder.technician_commission_type === 'percentage') {
        return `${formattedAmount} (${value}% sobre utilidad)`;
    }
    return formattedAmount;
});

// --- TESLA UI PASS-THROUGH (PT) ---
const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
            
            <!-- Información del Cliente (Columna 1) -->
            <div v-if="hasPermission('services.orders.see_customer_info')" class="flex flex-col">
                <div class="mb-5 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-user !text-sm text-blue-500"></i>
                    </div>
                    <div>
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Información del cliente</h2>
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Propietario del equipo</p>
                    </div>
                </div>

                <div class="bg-blue-50/50 dark:bg-blue-900/10 p-5 rounded-2xl border border-blue-100 dark:border-blue-900/30 flex-grow flex flex-col gap-4">
                    <!-- Cliente Nombre -->
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center border border-blue-200 dark:border-blue-800 shrink-0">
                            <i class="pi pi-id-card !text-[10px] text-blue-500"></i>
                        </div>
                        <div class="flex flex-col">
                            <template v-if="serviceOrder.customer_id">
                                <Link :href="route('customers.show', serviceOrder.customer_id)"
                                    class="text-sm font-medium text-blue-700 dark:text-blue-400 hover:text-blue-500 hover:underline m-0 flex items-center gap-1.5 transition-colors">
                                    {{ serviceOrder.customer_name }}
                                    <i class="pi pi-external-link !text-[10px]"></i>
                                </Link>
                            </template>
                            <template v-else>
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0">{{ serviceOrder.customer_name }}</span>
                            </template>
                        </div>
                    </div>

                    <!-- Teléfono -->
                    <div v-if="serviceOrder.customer_phone" class="flex justify-between items-center gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center border border-blue-200 dark:border-blue-800 shrink-0">
                                <i class="pi pi-phone !text-[10px] text-blue-500"></i>
                            </div>
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 tracking-tight">{{ serviceOrder.customer_phone }}</span>
                        </div>
                        <a :href="whatsappLink" target="_blank" rel="noopener noreferrer">
                            <Button icon="pi pi-whatsapp" rounded text class="!bg-green-50 dark:!bg-green-900/20 !text-green-600 dark:!text-green-400 hover:!bg-green-100 dark:hover:!bg-green-900/40 !w-8 !h-8 !p-0" v-tooltip.top="'Enviar WhatsApp'" />
                        </a>
                    </div>

                    <!-- Email -->
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center border border-blue-200 dark:border-blue-800 shrink-0">
                            <i class="pi pi-envelope !text-[10px] text-blue-500"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 tracking-tight break-all">{{ serviceOrder.customer_email || 'No registrado' }}</span>
                    </div>
                </div>
            </div>

            <!-- Información de la Orden (Columna 2) -->
            <div class="flex flex-col">
                <div class="mb-5 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center flex-shrink-0 border border-gray-200 dark:border-[#3a3a3a]">
                        <i class="pi pi-info-circle !text-sm text-gray-500"></i>
                    </div>
                    <div>
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Información operativa</h2>
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Tiempos y asignaciones</p>
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex-grow">
                    <ul class="m-0 p-0 list-none space-y-4">
                        <li class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Estatus actual</span>
                            <Tag :value="serviceOrder.status.replace('_', ' ')" :severity="getStatusSeverity(serviceOrder.status)" class="capitalize" :pt="tagPt" />
                        </li>
                        <li class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Recepción</span>
                            <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ formatDate(serviceOrder.received_at) }}</span>
                        </li>
                        <li class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Promesa de entrega</span>
                            <span class="font-medium text-sm text-primary-600 dark:text-primary-400 m-0">{{ formatDate(serviceOrder.promised_at) }}</span>
                        </li>
                        <li v-if="deliveryDate" class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Entrega real</span>
                            <span class="font-bold text-sm text-green-600 dark:text-green-500 m-0">{{ formatDate(deliveryDate) }}</span>
                        </li>
                        <li class="flex justify-between items-center" :class="{'border-b border-gray-200 dark:border-[#2a2a2a] pb-3': serviceOrder.technician_name}">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Técnico asignado</span>
                            <span class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ serviceOrder.technician_name || 'Sin asignar' }}</span>
                        </li>
                        <li v-if="serviceOrder.technician_name" class="flex justify-between items-center">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Comisión del técnico</span>
                            <span class="font-mono text-sm font-bold text-gray-900 dark:text-white m-0">{{ technicianCommission }}</span>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</template>