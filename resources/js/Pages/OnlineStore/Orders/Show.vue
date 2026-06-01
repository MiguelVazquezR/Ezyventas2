<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import SelectButton from 'primevue/selectbutton';
import Menu from 'primevue/menu';

const props = defineProps({
    order: Object,
    allowedTransitions: Array,
});

const selectedStatus = ref(null);
const note = ref('');
const loading = ref(false);
const menu = ref();

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const formatDate = (dateString) => {
    if (!dateString) return '\u2014';
    return new Date(dateString).toLocaleDateString('es-MX', {
        day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
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

const actionItems = ref([
    {
        label: 'Ver tienda',
        icon: 'pi pi-external-link',
        command: () => {
            if (props.order.store_config?.slug) {
                window.open(route('store.home', { slug: props.order.store_config.slug }), '_blank');
            }
        },
    },
]);

const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <Head :title="`Pedido ${order.formatted_order_number}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto space-y-6">
            <!-- Breadcrumb -->
            <div class="flex items-center">
                <Link :href="route('online-store.orders.index')"
                    class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]" />
                    Volver a pedidos
                </Link>
            </div>

            <!-- Header card -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-4 font-mono">
                        {{ order.formatted_order_number }}
                        <Tag :value="statusLabelMap[order.status]" :severity="statusSeverityMap[order.status]" :pt="tagPt" />
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full"
                                :class="order.status === 'pending' ? 'bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.8)] animate-pulse' : order.status === 'delivered' ? 'bg-green-500' : order.status === 'cancelled' ? 'bg-red-500' : 'bg-blue-500'" />
                            {{ formatDate(order.created_at) }}
                        </p>
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Cliente:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100">
                                {{ order.customer_name }}
                            </span>
                        </div>
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Total:</span>
                            <span class="text-sm font-bold text-gray-900 dark:text-white font-mono">{{ formatCurrency(order.total) }}</span>
                        </div>
                    </div>
                </div>

                <div class="w-full md:w-auto shrink-0 flex gap-2">
                    <a :href="order.whats_app_link" target="_blank">
                        <Button icon="pi pi-whatsapp" label="WhatsApp" size="small" severity="success"
                            class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                    </a>
                    <a :href="`tel:${order.customer_phone}`">
                        <Button icon="pi pi-phone" label="Llamar" size="small" severity="secondary" outlined
                            class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                    </a>
                    <Button @click="toggleMenu" icon="pi pi-ellipsis-h" severity="secondary" outlined
                        class="!rounded-xl !w-10" />
                    <Menu ref="menu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <!-- Main grid -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <!-- Left: Customer + Items -->
                <div class="xl:col-span-2 space-y-6">
                    <!-- Customer info -->
                    <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4">
                            Información del cliente
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Nombre</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ order.customer_name }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Teléfono</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">{{ order.customer_phone }}</p>
                            </div>
                            <div v-if="order.customer_email" class="space-y-1">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Correo</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">{{ order.customer_email }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Tipo de entrega</p>
                                <div class="flex items-center gap-2">
                                    <i class="pi text-xs"
                                        :class="order.delivery_type === 'pickup' ? 'pi-building !text-blue-500' : 'pi-truck !text-orange-500'" />
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ order.delivery_type === 'pickup' ? 'Recoger en tienda' : 'Envío a domicilio' }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="order.delivery_address" class="sm:col-span-2 space-y-1">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Dirección de entrega</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">{{ order.delivery_address }}</p>
                            </div>
                        </div>
                        <div v-if="order.customer_notes"
                            class="mt-4 p-4 bg-yellow-50 dark:bg-yellow-900/10 rounded-2xl border border-yellow-100 dark:border-yellow-900/30">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-yellow-600 dark:text-yellow-500 m-0 mb-1">
                                Notas del cliente
                            </p>
                            <p class="text-sm text-yellow-800 dark:text-yellow-300 m-0">{{ order.customer_notes }}</p>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4">
                            Productos del pedido ({{ order.items?.length || 0 }})
                        </h2>
                        <div class="overflow-hidden rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a]">
                                        <th class="text-left p-4 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Producto</th>
                                        <th class="text-center p-4 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Cant.</th>
                                        <th class="text-right p-4 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Precio</th>
                                        <th class="text-right p-4 text-[10px] uppercase tracking-widest text-gray-500 font-bold">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in order.items" :key="item.id"
                                        class="border-b border-gray-50 dark:border-[#2a2a2a] last:border-b-0 hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors">
                                        <td class="p-4 font-medium text-gray-900 dark:text-white">{{ item.product_name }}</td>
                                        <td class="p-4 text-center text-gray-500">{{ item.quantity }}</td>
                                        <td class="p-4 text-right font-mono text-gray-600 dark:text-gray-400">{{ formatCurrency(item.unit_price) }}</td>
                                        <td class="p-4 text-right font-mono font-semibold text-gray-900 dark:text-white">{{ formatCurrency(item.subtotal) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-gray-200 dark:border-[#4a4a4a] bg-gray-50/50 dark:bg-[#1a1a1a]/50">
                                        <td colspan="3" class="p-4 text-right text-[10px] uppercase tracking-widest text-gray-500 font-bold">Subtotal</td>
                                        <td class="p-4 text-right font-mono text-gray-900 dark:text-white">{{ formatCurrency(order.subtotal) }}</td>
                                    </tr>
                                    <tr v-if="order.delivery_fee > 0">
                                        <td colspan="3" class="p-4 text-right text-[10px] uppercase tracking-widest text-gray-500 font-bold">Costo de envío</td>
                                        <td class="p-4 text-right font-mono text-gray-900 dark:text-white">{{ formatCurrency(order.delivery_fee) }}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="p-4 text-right text-xs font-bold text-gray-900 dark:text-white">Total</td>
                                        <td class="p-4 text-right font-mono font-bold text-primary-600 dark:text-primary-400 text-lg">
                                            {{ formatCurrency(order.total) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right: Status Management -->
                <div class="space-y-6">
                    <div v-if="hasPendingTransitions"
                        class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4">
                            Cambiar estado
                        </h2>
                        <SelectButton v-model="selectedStatus" :options="transitionOptions"
                            optionLabel="label" optionValue="value" class="w-full mb-4"
                            :pt="{ button: { class: '!text-[10px] !uppercase !tracking-widest !font-bold !rounded-xl flex-1' } }" />
                        <Textarea v-model="note" placeholder="Nota opcional..." rows="2"
                            class="w-full mb-4 !rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-xs"
                            autoResize />
                        <Button
                            :label="selectedStatus ? `Marcar como ${statusLabelMap[selectedStatus]}` : 'Selecciona un estado'"
                            icon="pi pi-check" :loading="loading" :disabled="!selectedStatus"
                            @click="updateStatus"
                            class="w-full !rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold"
                            :pt="{ root: { class: '!py-3' } }" />
                    </div>

                    <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4">
                            Historial de estados
                        </h2>
                        <div class="space-y-3">
                            <div v-if="!order.status_logs || order.status_logs.length === 0"
                                class="flex flex-col items-center justify-center py-8 opacity-50">
                                <i class="pi pi-clock !text-2xl text-gray-400 mb-2" />
                                <p class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin historial</p>
                            </div>
                            <div v-for="log in order.status_logs" :key="log.id"
                                class="flex gap-3 p-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-xl border border-gray-100 dark:border-[#3a3a3a]">
                                <div class="w-2.5 h-2.5 rounded-full mt-1.5 shrink-0"
                                    :class="{
                                        'bg-green-500': statusSeverityMap[log.to_status] === 'success',
                                        'bg-red-500': statusSeverityMap[log.to_status] === 'danger',
                                        'bg-orange-500': statusSeverityMap[log.to_status] === 'warn',
                                        'bg-blue-500': statusSeverityMap[log.to_status] === 'info',
                                    }" />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-semibold text-gray-900 dark:text-white">
                                            {{ statusLabelMap[log.to_status] }}
                                        </span>
                                        <span v-if="log.from_status && log.from_status !== log.to_status"
                                            class="text-[10px] text-gray-400">
                                            de {{ statusLabelMap[log.from_status] }}
                                        </span>
                                    </div>
                                    <p v-if="log.note" class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1">{{ log.note }}</p>
                                    <p class="text-[10px] text-gray-400 m-0 mt-1">{{ formatDate(log.created_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
