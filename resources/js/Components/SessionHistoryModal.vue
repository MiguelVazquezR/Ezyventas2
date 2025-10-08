<script setup>
import { computed } from 'vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
});

const emit = defineEmits(['update:visible']);

const closeModal = () => {
    emit('update:visible', false);
};

const formatCurrency = (value) => {
    if (typeof value !== 'number') {
        value = parseFloat(value) || 0;
    }
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDateTime = (dateTimeString) => {
    if (!dateTimeString) return '';
    const options = { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(dateTimeString).toLocaleDateString('es-MX', options);
};

const formatTime = (dateTimeString) => {
    if (!dateTimeString) return '';
    const options = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
    return new Date(dateTimeString).toLocaleTimeString('es-MX', options);
}

// Combina transacciones y movimientos de caja en una única línea de tiempo ordenada
const timelineEvents = computed(() => {
    if (!props.session || !props.session.transactions) return [];

    const salesEvents = props.session.transactions.map(tx => ({
        type: 'sale',
        date: tx.created_at,
        status: tx.status === 'completado' ? 'Venta' : 'Venta (pendiente)',
        color: tx.status === 'completado' ? '#22c55e' : '#f59e0b', // Verde para completado, ambar para pendiente
        icon: 'pi pi-shopping-cart',
        data: tx,
        // --- CORRECCIÓN: Se calculan ambos totales ---
        totalSale: parseFloat(tx.total),
        totalPaid: tx.payments.reduce((sum, p) => sum + parseFloat(p.amount), 0)
    }));

    const movementEvents = (props.session.cash_movements || []).map(mv => ({
        type: 'movement',
        date: mv.created_at,
        status: mv.type === 'ingreso' ? 'Ingreso de efectivo' : 'Retiro de efectivo',
        color: mv.type === 'ingreso' ? '#3b82f6' : '#ef4444',
        icon: mv.type === 'ingreso' ? 'pi pi-arrow-down-left' : 'pi pi-arrow-up-right',
        data: mv,
    }));

    return [...salesEvents, ...movementEvents].sort((a, b) => new Date(b.date) - new Date(a.date));
});

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Historial de la sesión actual" :style="{ width: '50rem' }">
        <div v-if="session" class="p-2">
            <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg mb-6">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="m-0 text-sm text-gray-500">Caja</p>
                        <p class="m-0 font-bold text-base">{{ session.cash_register.name }}</p>
                    </div>
                    <div>
                        <p class="m-0 text-sm text-gray-500">Usuario</p>
                        <p class="m-0 font-bold text-base">{{ session.user.name }}</p>
                    </div>
                    <div class="text-right">
                        <p class="m-0 text-sm text-gray-500">Apertura</p>
                        <p class="m-0 font-bold text-base">{{ formatDateTime(session.opened_at) }}</p>
                    </div>
                </div>
            </div>

            <div class="max-h-[55vh] overflow-y-auto pr-2">
                 <Timeline v-if="timelineEvents.length > 0" :value="timelineEvents" align="alternate" class="customized-timeline">
                    <template #marker="slotProps">
                        <span class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md" :style="{ backgroundColor: slotProps.item.color }">
                            <i :class="slotProps.item.icon"></i>
                        </span>
                    </template>
                    <template #content="slotProps">
                        <Card class="mt-0 mb-4">
                            <template #title>
                                <div class="flex justify-between items-center text-md">
                                    <span>{{ slotProps.item.status }}</span>
                                    <span class="font-normal text-sm">{{ formatTime(slotProps.item.date) }}</span>
                                </div>
                            </template>
                            <template #content>
                                <div v-if="slotProps.item.type === 'sale'" class="text-sm space-y-1">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Folio:</span>
                                        <span class="font-mono">{{ slotProps.item.data.folio }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Cliente:</span>
                                        <span class="font-semibold">{{ slotProps.item.data.customer?.name || 'Público en general' }}</span>
                                    </div>
                                    
                                    <!-- --- CORRECCIÓN: Se muestran ambos montos --- -->
                                    <div class="pt-2 border-t mt-2 space-y-1">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Total Venta:</span>
                                            <span class="font-semibold">{{ formatCurrency(slotProps.item.totalSale) }}</span>
                                        </div>
                                        <div class="flex justify-between font-bold">
                                            <span>Total Pagado:</span>
                                            <span>{{ formatCurrency(slotProps.item.totalPaid) }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="slotProps.item.type === 'movement'" class="text-sm space-y-1">
                                    <p class="text-gray-600 italic">"{{ slotProps.item.data.description }}"</p>
                                     <div class="flex justify-between font-bold text-base pt-2 border-t mt-2">
                                        <span>Monto:</span>
                                        <span>{{ formatCurrency(slotProps.item.data.amount) }}</span>
                                    </div>
                                </div>
                            </template>
                        </Card>
                    </template>
                </Timeline>
                 <div v-else class="text-center py-12 text-gray-500">
                    <i class="pi pi-history !text-4xl mb-3"></i>
                    <p>No hay transacciones ni movimientos en esta sesión.</p>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Cerrar" icon="pi pi-times" @click="closeModal" text severity="secondary" />
        </template>
    </Dialog>
</template>