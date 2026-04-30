<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/Composables';

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
</script>

<template>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Información del Cliente -->
            <div v-if="hasPermission('services.orders.see_customer_info')">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información del cliente</h2>
                <ul class="space-y-2 text-sm">
                    <li class="flex items-center">
                        <i class="pi pi-user w-6 text-gray-500"></i>
                        <template v-if="serviceOrder.customer_id">
                            <Link :href="route('customers.show', serviceOrder.customer_id)"
                                class="text-blue-600 hover:underline flex items-center gap-2">
                            {{ serviceOrder.customer_name }}
                            <i class="pi pi-external-link text-xs"></i>
                            </Link>
                        </template>
                        <template v-else>
                            <span class="font-medium">{{ serviceOrder.customer_name }}</span>
                        </template>
                    </li>
                    <li v-if="serviceOrder.customer_phone" class="flex items-center">
                        <i class="pi pi-phone w-6 text-gray-500"></i>
                        <span class="font-medium">{{ serviceOrder.customer_phone }}</span>
                        <a :href="whatsappLink" target="_blank" class="ml-auto">
                            <Button icon="pi pi-whatsapp" severity="success" text rounded v-tooltip.bottom="'Enviar WhatsApp'" />
                        </a>
                    </li>
                    <li class="flex items-center">
                        <i class="pi pi-envelope w-6 text-gray-500"></i>
                        <span class="font-medium">{{ serviceOrder.customer_email || 'N/A' }}</span>
                    </li>
                </ul>
            </div>

            <!-- Información de la Orden -->
            <div>
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información de la orden</h2>
                <ul class="space-y-3 text-sm">
                    <li class="flex justify-between items-center">
                        <span>Estatus actual</span>
                        <Tag :value="serviceOrder.status.replace('_', ' ')" :severity="getStatusSeverity(serviceOrder.status)" class="capitalize" />
                    </li>
                    <li class="flex justify-between">
                        <span>Fecha de recepción</span>
                        <span>{{ formatDate(serviceOrder.received_at) }}</span>
                    </li>
                    <li class="flex justify-between">
                        <span>Fecha promesa</span>
                        <span>{{ formatDate(serviceOrder.promised_at) }}</span>
                    </li>
                    <li v-if="deliveryDate" class="flex justify-between">
                        <span>Fecha de entrega</span>
                        <span class="font-semibold">{{ formatDate(deliveryDate) }}</span>
                    </li>
                    <li class="flex justify-between">
                        <span>Técnico asignado</span>
                        <span>{{ serviceOrder.technician_name || 'Sin asignar' }}</span>
                    </li>
                    <li v-if="serviceOrder.technician_name" class="flex justify-between">
                        <span>Comisión del técnico:</span>
                        <span class="font-semibold">{{ technicianCommission }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>