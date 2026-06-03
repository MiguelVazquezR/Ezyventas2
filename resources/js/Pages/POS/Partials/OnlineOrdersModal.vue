<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    visible: Boolean,
});

const emit = defineEmits(['update:visible']);

const toast = useToast();
const confirm = useConfirm();

// ─── State ────────────────────────────────────────────────
const orders = ref([]);
const counts = ref({});
const statuses = ref([]);
const activeStatus = ref('');
const isLoading = ref(false);
const isUpdating = ref(false);
const statusNote = ref('');

// ─── Fetch orders ─────────────────────────────────────────
const fetchOrders = async () => {
    isLoading.value = true;
    try {
        const params = activeStatus.value ? { status: activeStatus.value } : {};
        const { data } = await axios.get(route('pos.online-orders'), { params });
        orders.value = data.orders?.data || [];
        counts.value = data.counts || {};
        statuses.value = data.statuses || [];
    } catch (e) {
        console.error('Error fetching online orders:', e);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar los pedidos.', life: 4000 });
    } finally {
        isLoading.value = false;
    }
};

watch(() => props.visible, (val) => {
    if (val) {
        activeStatus.value = '';
        fetchOrders();
    }
});

const filterByStatus = (status) => {
    activeStatus.value = status;
    fetchOrders();
};

// ─── Status update ────────────────────────────────────────
const handleStatusChange = (order, newStatusValue) => {
    if (newStatusValue === 'cancelled') {
        confirm.require({
            message: `El stock del pedido #${order.order_number} será repuesto en el inventario. Los pagos realizados por el cliente deberán gestionarse manualmente (reembolso en efectivo desde caja o registro de gasto).`,
            header: '¿Cancelar este pedido?',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Sí, cancelar pedido',
            rejectLabel: 'No',
            acceptClass: 'p-button-danger',
            accept: () => updateOrderStatus(order, newStatusValue),
        });
        return;
    }
    updateOrderStatus(order, newStatusValue);
};

const updateOrderStatus = async (order, newStatusValue) => {
    isUpdating.value = true;
    try {
        const { data } = await axios.put(route('pos.online-orders.update-status', order.id), {
            status: newStatusValue,
            note: statusNote.value || null,
        });

        // Update order in place
        const idx = orders.value.findIndex(o => o.id === order.id);
        if (idx !== -1) {
            orders.value[idx].status = data.status;
            orders.value[idx].all_statuses = data.all_statuses;
        }

        statusNote.value = '';
        toast.add({ severity: 'success', summary: 'Estado actualizado', detail: data.message, life: 3000 });

        // Refresh counts
        fetchOrders();
    } catch (e) {
        const msg = e.response?.data?.message || 'No se pudo actualizar el estado.';
        toast.add({ severity: 'error', summary: 'Error', detail: msg, life: 4000 });
    } finally {
        isUpdating.value = false;
    }
};

// ─── Helpers ──────────────────────────────────────────────
const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const formatDate = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
};

const statusSeverityMap = {
    pending: 'warn',
    reviewed: 'info',
    in_preparation: 'info',
    ready: 'success',
    delivered: 'success',
    cancelled: 'danger',
};

const deliveryLabel = (type) => type === 'pickup' ? 'Recoger en tienda' : 'Envío a domicilio';

const getStatusCount = (statusValue) => counts.value?.[statusValue] || 0;

const totalPending = computed(() => getStatusCount('pending'));
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal
        header="Pedidos de tienda en línea"
        class="w-full max-w-5xl"
        :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
            title: { class: 'text-xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-6 py-5' },
            footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-6 py-4' }
        }"
    >
        <!-- Header with pending badge -->
        <template #header>
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">Pedidos de tienda en línea</h2>
                <span v-if="totalPending > 0" class="flex items-center gap-1.5 bg-orange-50 dark:bg-orange-900/20 text-orange-700 dark:text-orange-400 text-[10px] font-bold px-2.5 py-1 rounded-full border border-orange-200 dark:border-orange-900/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-orange-500 shadow-[0_0_6px_rgba(249,115,22,0.8)] animate-pulse" />
                    {{ totalPending }} pendiente{{ totalPending !== 1 ? 's' : '' }}
                </span>
            </div>
        </template>

        <!-- Status tabs -->
        <div class="flex flex-wrap gap-2 mb-5">
            <Button
                :label="'Todos'"
                :severity="!activeStatus ? 'primary' : 'secondary'"
                :outlined="!!activeStatus"
                size="small"
                class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold"
                @click="filterByStatus('')"
            />
            <Button
                v-for="s in statuses"
                :key="s.value"
                :label="`${s.label} (${getStatusCount(s.value)})`"
                :severity="activeStatus === s.value ? 'primary' : 'secondary'"
                :outlined="activeStatus !== s.value"
                size="small"
                class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold"
                @click="filterByStatus(s.value)"
            />
        </div>

        <!-- Loading -->
        <div v-if="isLoading" class="flex justify-center items-center py-12">
            <i class="pi pi-spin pi-spinner-dotted !text-3xl text-primary-500" />
        </div>

        <!-- Empty state -->
        <div v-else-if="orders.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
            <div class="w-14 h-14 bg-gray-50 dark:bg-[#1a1a1a] rounded-full flex items-center justify-center mb-4 border border-gray-100 dark:border-[#3a3a3a]">
                <i class="pi pi-inbox !text-xl text-gray-400" />
            </div>
            <h3 class="text-lg font-light text-gray-900 dark:text-white tracking-tight m-0 mb-1">Sin pedidos</h3>
            <p class="text-xs text-gray-500 m-0">No hay pedidos con este estado.</p>
        </div>

        <!-- Orders list -->
        <div v-else class="space-y-3 max-h-[55vh] overflow-y-auto custom-scrollbar pr-1">
            <div
                v-for="order in orders"
                :key="order.id"
                class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 hover:border-primary-500/40 transition-colors"
            >
                <div class="flex items-start justify-between gap-4 mb-3">
                    <!-- Left: order info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-1.5">
                            <span class="font-mono font-bold text-primary-600 dark:text-primary-400 text-sm">#{{ order.order_number }}</span>
                            <Tag :value="order.status.label" :severity="statusSeverityMap[order.status.value]" class="!text-[10px] !uppercase !tracking-widest !font-bold" />
                        </div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white m-0 truncate">{{ order.customer_name }}</p>
                        <p class="text-[11px] text-gray-500 m-0 mt-0.5">{{ order.customer_phone }}</p>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="text-[10px] text-gray-400 uppercase tracking-wider">{{ deliveryLabel(order.delivery_type) }}</span>
                            <span class="text-[10px] text-gray-400">{{ formatDate(order.created_at) }}</span>
                        </div>
                    </div>

                    <!-- Right: total + status change -->
                    <div class="flex flex-col items-end gap-2 flex-shrink-0">
                        <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">{{ formatCurrency(order.total) }}</span>

                        <div v-if="order.all_statuses?.length > 0" class="flex items-center gap-1.5" @click.stop>
                            <Select
                                :modelValue="null"
                                @update:modelValue="(val) => handleStatusChange(order, val)"
                                :options="order.all_statuses"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Cambiar estado"
                                class="w-[140px]"
                                :disabled="isUpdating"
                                :pt="{
                                    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]' },
                                }"
                            />
                        </div>
                    </div>
                </div>

                <!-- Product items list -->
                <div class="mb-3 space-y-1">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1.5">Productos ({{ order.items_count }})</p>
                    <div v-for="(item, i) in order.items" :key="i" class="flex items-center justify-between text-[11px]">
                        <span class="text-gray-700 dark:text-gray-300 truncate flex-1 min-w-0">{{ item.product_name }}</span>
                        <span class="text-gray-400 ml-2 flex-shrink-0">{{ item.quantity }} x {{ formatCurrency(item.unit_price) }}</span>
                    </div>
                </div>

                <!-- Actions: WhatsApp + Detail link -->
                <div class="pt-3 border-t border-gray-200 dark:border-[#3a3a3a] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <a v-if="order.whats_app_link" :href="order.whats_app_link" target="_blank"
                            class="inline-flex items-center gap-1.5 text-[10px] font-medium text-green-600 dark:text-green-400 hover:text-green-700 dark:hover:text-green-300 transition-colors"
                            @click.stop>
                            <i class="pi pi-whatsapp !text-xs" />
                            WhatsApp
                        </a>
                        <a v-if="order.order_detail_url" :href="order.order_detail_url" target="_blank"
                            class="inline-flex items-center gap-1.5 text-[10px] font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 transition-colors"
                            @click.stop>
                            <i class="pi pi-external-link !text-xs" />
                            Ver detalle
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end">
                <Button
                    label="Cerrar"
                    icon="pi pi-times"
                    text
                    severity="secondary"
                    class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold"
                    @click="emit('update:visible', false)"
                />
            </div>
        </template>
    </Dialog>
</template>
