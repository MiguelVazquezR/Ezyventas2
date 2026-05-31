<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import SelectButton from 'primevue/selectbutton';
import Divider from 'primevue/divider';

const props = defineProps({
    order: Object,
    allowedTransitions: Array,
});

const selectedStatus = ref(null);
const note = ref('');
const loading = ref(false);

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const statusLabelMap = {
    pending: 'Pendiente',
    reviewed: 'En revisión',
    in_preparation: 'En preparación',
    ready: 'Listo',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
};

const statusSeverityMap = {
    pending: 'warn',
    reviewed: 'info',
    in_preparation: 'info',
    ready: 'success',
    delivered: 'success',
    cancelled: 'danger',
};

const transitionOptions = computed(() => {
    return props.allowedTransitions.map(t => ({
        label: statusLabelMap[t] || t,
        value: t,
    }));
});

const updateStatus = () => {
    if (!selectedStatus.value) return;
    loading.value = true;
    router.put(route('online-store.orders.update-status', props.order.id), {
        status: selectedStatus.value,
        note: note.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedStatus.value = null;
            note.value = '';
        },
        onFinish: () => loading.value = false,
    });
};

const hasPendingTransitions = computed(() => props.allowedTransitions.length > 0);
</script>

<template>
    <Head :title="`Pedido ${order.formatted_order_number}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-5xl mx-auto space-y-6">
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <!-- Header -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0 font-mono">{{ order.formatted_order_number }}</h1>
                            <Tag :value="statusLabelMap[order.status]" :severity="statusSeverityMap[order.status]" class="!text-[10px] !uppercase !tracking-widest !font-bold" />
                        </div>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ new Date(order.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="`tel:${order.customer_phone}`" class="!rounded-full">
                            <Button icon="pi pi-phone" label="Llamar" size="small" severity="secondary" outlined class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold" />
                        </a>
                        <a :href="order.whats_app_link" target="_blank">
                            <Button icon="pi pi-whatsapp" label="WhatsApp" size="small" severity="success" class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold" />
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left: Customer & Items -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Customer Info -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Información del cliente</h2>
                            <div class="space-y-2">
                                <p class="text-sm font-medium dark:text-white m-0">{{ order.customer_name }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">
                                    <i class="pi pi-phone !text-xs mr-1" /> {{ order.customer_phone }}
                                </p>
                                <p v-if="order.customer_email" class="text-sm text-gray-600 dark:text-gray-400 m-0">
                                    <i class="pi pi-envelope !text-xs mr-1" /> {{ order.customer_email }}
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400 m-0">
                                    <i class="pi pi-truck !text-xs mr-1" />
                                    <strong>{{ order.delivery_type === 'pickup' ? 'Recoger en tienda' : 'Envío a domicilio' }}</strong>
                                </p>
                                <p v-if="order.delivery_address" class="text-sm text-gray-600 dark:text-gray-400 m-0 pl-4">{{ order.delivery_address }}</p>
                            </div>
                            <div v-if="order.customer_notes" class="mt-3 p-3 bg-yellow-50 dark:bg-yellow-900/10 rounded-xl border border-yellow-100 dark:border-yellow-900/30">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-yellow-600 dark:text-yellow-500 m-0 mb-1">Notas del cliente</p>
                                <p class="text-sm text-yellow-800 dark:text-yellow-300 m-0">{{ order.customer_notes }}</p>
                            </div>
                        </div>

                        <!-- Order Items -->
                        <div>
                            <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Productos del pedido</h2>
                            <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-100 dark:border-[#3a3a3a]">
                                            <th class="text-left p-3 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Producto</th>
                                            <th class="text-center p-3 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Cant.</th>
                                            <th class="text-right p-3 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Precio</th>
                                            <th class="text-right p-3 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in order.items" :key="item.id" class="border-b border-gray-100 dark:border-[#3a3a3a] last:border-b-0">
                                            <td class="p-3 font-medium dark:text-white">{{ item.product_name }}</td>
                                            <td class="p-3 text-center text-gray-500">{{ item.quantity }}</td>
                                            <td class="p-3 text-right font-mono text-gray-600 dark:text-gray-400">{{ formatCurrency(item.unit_price) }}</td>
                                            <td class="p-3 text-right font-mono font-semibold dark:text-white">{{ formatCurrency(item.subtotal) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t border-gray-200 dark:border-[#4a4a4a]">
                                            <td colspan="3" class="p-3 text-right text-[10px] uppercase tracking-widest text-gray-500 font-bold">Subtotal</td>
                                            <td class="p-3 text-right font-mono dark:text-white">{{ formatCurrency(order.subtotal) }}</td>
                                        </tr>
                                        <tr v-if="order.delivery_fee > 0">
                                            <td colspan="3" class="p-3 text-right text-[10px] uppercase tracking-widest text-gray-500 font-bold">Costo de envío</td>
                                            <td class="p-3 text-right font-mono dark:text-white">{{ formatCurrency(order.delivery_fee) }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="p-3 text-right text-sm font-bold dark:text-white">Total</td>
                                            <td class="p-3 text-right font-mono font-bold text-primary-600 dark:text-primary-400 text-lg">{{ formatCurrency(order.total) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Status Management & History -->
                    <div class="space-y-6">
                        <!-- Status Change -->
                        <div v-if="hasPendingTransitions" class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Cambiar estado</h2>
                            <SelectButton v-model="selectedStatus" :options="transitionOptions" optionLabel="label" optionValue="value" class="w-full mb-3" :pt="{ button: { class: '!text-[10px] !uppercase !tracking-widest !font-bold !rounded-xl flex-1' } }" />
                            <Textarea v-model="note" placeholder="Nota opcional..." rows="2" class="w-full mb-3 !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-xs" autoResize />
                            <Button :label="selectedStatus ? `Marcar como ${statusLabelMap[selectedStatus]}` : 'Selecciona un estado'" icon="pi pi-check" :loading="loading" :disabled="!selectedStatus" @click="updateStatus" class="w-full !rounded-xl !text-xs" />
                        </div>

                        <!-- Status History -->
                        <div>
                            <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Historial de estados</h2>
                            <div class="space-y-2">
                                <div v-for="log in order.status_logs" :key="log.id" class="flex gap-3 p-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-xl border border-gray-100 dark:border-[#3a3a3a]">
                                    <div class="w-2 h-2 rounded-full mt-1.5 shrink-0" :class="`bg-${statusSeverityMap[log.to_status] === 'success' ? 'green' : statusSeverityMap[log.to_status] === 'danger' ? 'red' : statusSeverityMap[log.to_status] === 'warn' ? 'orange' : 'blue'}-500`" />
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-semibold dark:text-white">{{ statusLabelMap[log.to_status] }}</span>
                                            <span v-if="log.from_status && log.from_status !== log.to_status" class="text-[10px] text-gray-400">de {{ statusLabelMap[log.from_status] }}</span>
                                        </div>
                                        <p v-if="log.note" class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1">{{ log.note }}</p>
                                        <p class="text-[10px] text-gray-400 m-0 mt-1">{{ new Date(log.created_at).toLocaleString('es-MX') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
