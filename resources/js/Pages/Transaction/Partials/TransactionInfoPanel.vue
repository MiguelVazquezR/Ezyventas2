<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    transaction: Object,
    localTransaction: Object,
    canExtendExpiration: Boolean,
    pendingAmount: Number,
});

defineEmits(['open-reschedule-order-modal', 'toggle-phone-menu', 'open-extend-layaway-modal']);

const getStatusSeverity = (status) => ({ completado: 'success', pendiente: 'warn', cancelado: 'danger', reembolsado: 'info', on_layaway: 'warn', apartado: 'warn', por_entregar: 'info', en_ruta: 'info', entregado_por_pagar: 'warn' }[status] || 'secondary');
const formatStatusLabel = (status) => status ? (status.replace(/_/g, ' ').charAt(0).toUpperCase() + status.replace(/_/g, ' ').slice(1).toLowerCase()) : '';
const formatDate = (date) => date ? new Date(date).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
const formatDateOnly = (date) => date ? new Date(date).toLocaleDateString('es-MX', { dateStyle: 'long' }) : '';

// --- SECCIÓN DE VENCIMIENTO (APARTADO O CRÉDITO) ---
// Mismo criterio visual que el procesador de pagos del POS:
//  - Venta a crédito (status 'pendiente') → naranja "Vencimiento del crédito".
//  - Apartado (status 'apartado'/'on_layaway') → morado "Vencimiento del apartado".
const expirationInfo = computed(() => {
    const isCreditSale = props.localTransaction?.status === 'pendiente';

    if (isCreditSale) {
        return {
            title: 'Vencimiento del crédito',
            legend: 'Crédito liquidado',
            containerClass: 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-800',
            titleClass: 'text-orange-800 dark:text-orange-300',
            dateClass: 'text-orange-900 dark:text-orange-100',
            legendDividerClass: 'border-orange-200 dark:border-orange-800/50',
            legendClass: 'text-orange-600 dark:text-orange-400',
            buttonSeverity: 'warning',
        };
    }

    return {
        title: 'Vencimiento del apartado',
        legend: 'Apartado liquidado',
        containerClass: 'bg-purple-50 dark:bg-purple-900/10 border-purple-100 dark:border-purple-900/30',
        titleClass: 'text-purple-800 dark:text-purple-400',
        dateClass: 'text-purple-900 dark:text-purple-100',
        legendDividerClass: 'border-purple-200 dark:border-purple-800/50',
        legendClass: 'text-purple-600 dark:text-purple-400',
        buttonSeverity: 'help',
    };
});

// --- TESLA UI PASS-THROUGH (PT) ---
const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Información de la venta</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Detalles operativos y cliente</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                <i class="pi pi-info-circle !text-sm text-purple-500"></i>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-grow flex flex-col">
            <ul class="m-0 p-0 list-none space-y-4">
                
                <!-- Estatus -->
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Estatus operativo</span>
                    <Tag :value="formatStatusLabel(localTransaction.status)" :severity="getStatusSeverity(localTransaction.status)" :pt="tagPt" />
                </li>
                
                <!-- DETALLES DE PEDIDO -->
                <li v-if="transaction.delivery_date" class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                    <div class="flex flex-col gap-1">
                        <span class="text-blue-800 dark:text-blue-400 font-bold text-[10px] uppercase tracking-widest mb-1 flex items-center gap-1.5">
                            <i class="pi pi-truck !text-[10px]"></i> Entrega Programada
                        </span>
                        <span class="font-medium text-blue-900 dark:text-blue-100 text-sm m-0">{{ formatDate(transaction.delivery_date) }}</span>
                        
                        <div v-if="transaction.shipping_address" class="mt-2 text-xs text-blue-700 dark:text-blue-300 flex items-start gap-2 leading-relaxed">
                            <i class="pi pi-map-marker mt-0.5 !text-[10px]"></i>
                            <span>{{ transaction.shipping_address }}</span>
                        </div>

                        <div v-if="localTransaction.status === 'por_entregar'" class="mt-4">
                            <Button label="Reprogramar" icon="pi pi-calendar-plus" outlined class="!w-full !rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold" @click="$emit('open-reschedule-order-modal')" />
                        </div>
                    </div>
                </li>

                <!-- CONTACTO TEMPORAL -->
                <li v-if="!transaction.customer && transaction.contact_info" class="flex flex-col border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Datos de Contacto (Invitado)</span>
                    
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-200 dark:border-[#3a3a3a] shrink-0">
                                <i class="pi pi-user !text-[10px] text-gray-400"></i>
                            </div>
                            <span class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ transaction.contact_info.name }}</span>
                        </div>
                        
                        <div v-if="transaction.contact_info.phone" class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-200 dark:border-[#3a3a3a] shrink-0">
                                <i class="pi pi-phone !text-[10px] text-gray-400"></i>
                            </div>
                            <span class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-500 cursor-pointer transition-colors" @click="$emit('toggle-phone-menu', $event, transaction.contact_info.phone)">
                                {{ transaction.contact_info.phone }} <i class="pi pi-angle-down !text-[10px] ml-1"></i>
                            </span>
                        </div>
                    </div>
                </li>

                <!-- Sección de Vencimiento (Apartado o Crédito) -->
                <li v-if="transaction.layaway_expiration_date" class="p-4 rounded-2xl border" :class="expirationInfo.containerClass">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-[10px] uppercase tracking-widest font-bold m-0" :class="expirationInfo.titleClass">{{ expirationInfo.title }}</span>
                    </div>
                    <span class="font-medium text-sm m-0" :class="expirationInfo.dateClass">{{ formatDateOnly(transaction.layaway_expiration_date) }}</span>
                    
                    <!-- LEYENDA SI YA ESTÁ LIQUIDADO -->
                    <div v-if="pendingAmount <= 0" class="mt-3 pt-3 border-t flex items-center gap-2" :class="[expirationInfo.legendDividerClass, expirationInfo.legendClass]">
                        <i class="pi pi-check-circle !text-sm"></i> 
                        <span class="text-[10px] uppercase font-bold tracking-widest m-0">{{ expirationInfo.legend }}</span>
                    </div>

                    <div v-if="canExtendExpiration && pendingAmount > 0" class="mt-4">
                        <Button label="Extender fecha" icon="pi pi-calendar-plus" :severity="expirationInfo.buttonSeverity" outlined class="!w-full !rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold" @click="$emit('open-extend-layaway-modal')" />
                    </div>
                </li>

                <!-- Cliente Registrado -->
                <li v-if="transaction.customer" class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cliente</span>
                    <span class="font-medium text-sm">
                        <Link :href="route('customers.show', transaction.customer.id)" class="text-primary-600 dark:text-primary-400 hover:text-primary-500 hover:underline flex items-center gap-2 m-0 transition-colors">
                            {{ transaction.customer.name }} <i class="pi pi-external-link !text-[10px]"></i>
                        </Link>
                    </span>
                </li>
                <li v-else-if="!transaction.contact_info" class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cliente</span>
                    <span class="font-medium text-sm text-gray-500 italic m-0">Público en general</span>
                </li>

                <!-- Cajero -->
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Registrado por</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ transaction.user?.name || 'Sistema' }}</span>
                </li>

                <!-- Notas -->
                <li v-if="transaction.notes" class="flex flex-col mt-2">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Notas / Referencia</span>
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <p class="text-sm m-0 italic text-gray-700 dark:text-gray-300 leading-relaxed">{{ transaction.notes }}</p>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>