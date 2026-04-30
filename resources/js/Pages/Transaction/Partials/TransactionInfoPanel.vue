<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    transaction: Object,
    localTransaction: Object,
    canExtendExpiration: Boolean,
    pendingAmount: Number,
});

defineEmits(['open-reschedule-order-modal', 'toggle-phone-menu', 'open-extend-layaway-modal']);

const getStatusSeverity = (status) => ({ completado: 'success', pendiente: 'warn', cancelado: 'danger', reembolsado: 'info', on_layaway: 'warn', apartado: 'warn', por_entregar: 'info', en_ruta: 'primary', entregado_por_pagar: 'warn' }[status] || 'secondary');
const formatStatusLabel = (status) => status ? (status.replace(/_/g, ' ').charAt(0).toUpperCase() + status.replace(/_/g, ' ').slice(1).toLowerCase()) : '';
const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatDateOnly = (date) => date ? new Date(date).toLocaleDateString('es-MX', { dateStyle: 'long' }) : '';
</script>

<template>
    <Card>
        <template #title>Información de la venta</template>
        <template #content>
            <ul class="space-y-3 text-sm">
                <li class="flex justify-between items-center">
                    <span>Estatus:</span>
                    <Tag :value="formatStatusLabel(localTransaction.status)" :severity="getStatusSeverity(localTransaction.status)" />
                </li>
                
                <!-- DETALLES DE PEDIDO -->
                <li v-if="transaction.delivery_date" class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg -mx-2 border border-blue-100 dark:border-blue-800">
                    <div class="flex flex-col gap-1">
                        <span class="text-blue-800 dark:text-blue-300 font-bold text-xs uppercase mb-1">
                            <i class="pi pi-truck mr-1"></i>Entrega Programada
                        </span>
                        <span class="font-bold text-blue-700 dark:text-blue-200 text-base">{{ formatDate(transaction.delivery_date) }}</span>
                        
                        <div v-if="transaction.shipping_address" class="mt-2 text-xs text-blue-700 dark:text-blue-300 flex gap-2">
                            <i class="pi pi-map-marker mt-0.5"></i>
                            <span>{{ transaction.shipping_address }}</span>
                        </div>

                        <div v-if="localTransaction.status === 'por_entregar'" class="mt-2">
                            <Button label="Reprogramar" icon="pi pi-calendar-plus" size="small" severity="info" outlined class="w-full h-8 text-xs" @click="$emit('open-reschedule-order-modal')" />
                        </div>
                    </div>
                </li>

                <!-- CONTACTO TEMPORAL -->
                <li v-if="!transaction.customer && transaction.contact_info" class="flex flex-col border-b pb-2">
                    <span class="text-gray-500 dark:text-gray-400 mb-1 text-xs font-bold">Datos de Contacto (Invitado):</span>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-user text-gray-400"></i>
                        <span class="font-medium">{{ transaction.contact_info.name }}</span>
                    </div>
                    <div v-if="transaction.contact_info.phone" class="flex items-center gap-2 mt-1">
                        <i class="pi pi-phone text-gray-400"></i>
                        <span class="text-blue-600 hover:text-blue-800 cursor-pointer font-medium" @click="$emit('toggle-phone-menu', $event, transaction.contact_info.phone)">
                            {{ transaction.contact_info.phone }} <i class="pi pi-angle-down text-xs ml-1"></i>
                        </span>
                    </div>
                </li>

                <!-- Sección de Vencimiento -->
                <li v-if="transaction.layaway_expiration_date" class="bg-purple-50 dark:bg-purple-900/20 p-2 rounded -mx-2">
                    <div class="flex justify-between items-center">
                        <span class="text-purple-800 dark:text-purple-300 font-medium">Vencimiento:</span>
                        <span class="font-bold text-purple-700 dark:text-purple-200">{{ formatDateOnly(transaction.layaway_expiration_date) }}</span>
                    </div>
                    
                    <!-- LEYENDA SI YA ESTÁ PAGADO -->
                    <div v-if="pendingAmount <= 0" class="mt-2 text-[11px] uppercase font-bold text-purple-700 dark:text-purple-300 bg-purple-200 dark:bg-purple-800/40 p-1.5 rounded text-center">
                        <i class="pi pi-check-circle mr-1"></i> Apartado liquidado (Fecha informativa)
                    </div>

                    <div v-if="canExtendExpiration && pendingAmount > 0" class="mt-2">
                        <Button label="Extender fecha" icon="pi pi-calendar-plus" size="small" severity="help" outlined class="w-full h-8 text-xs" @click="$emit('open-extend-layaway-modal')" />
                    </div>
                </li>

                <li v-if="transaction.customer" class="flex justify-between items-center">
                    <span>Cliente:</span>
                    <span class="font-medium">
                        <Link :href="route('customers.show', transaction.customer.id)" class="text-blue-600 hover:underline flex items-center gap-2">
                            {{ transaction.customer.name }} <i class="pi pi-external-link text-xs"></i>
                        </Link>
                    </span>
                </li>
                <li v-else-if="!transaction.contact_info" class="flex justify-between items-center">
                    <span>Cliente:</span>
                    <span class="font-medium text-gray-500 italic">Público en general</span>
                </li>

                <li class="flex justify-between"><span>Cajero:</span><span class="font-medium">{{ transaction.user?.name || 'N/A' }}</span></li>
                <li v-if="transaction.notes" class="flex flex-col border-t pt-2 mt-2">
                    <span class="text-gray-500 dark:text-gray-400 mb-1 text-xs uppercase font-bold">Notas / Referencia:</span>
                    <p class="text-sm bg-gray-50 dark:bg-gray-700/50 p-2 rounded italic text-gray-700 dark:text-gray-300">{{ transaction.notes }}</p>
                </li>
            </ul>
        </template>
    </Card>
</template>