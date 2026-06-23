<script setup>
import { ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    versions: Array,
});

const emit = defineEmits(['editVersion', 'registerPayment', 'deleteVersion']);

const confirm = useConfirm();

const expandedVersions = ref([]);

// --- CONFIRM DELETE VERSION ---
const confirmDeleteVersion = (version) => {
    confirm.require({
        message: `¿Estás seguro de que deseas eliminar esta versión (${formatDate(version.start_date)} → ${formatDate(version.end_date)}) y todos sus pagos asociados? Esta acción no se puede deshacer.`,
        header: 'Eliminar versión',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: () => {
            emit('deleteVersion', version);
        }
    });
};

// --- HELPER FUNCTIONS ---
const formatDate = (dateString) => {
    if (!dateString) return '--';
    return new Intl.DateTimeFormat('es-MX', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(dateString));
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const getPaymentStatusIcon = (status) => {
    switch (status) {
        case 'approved': return { icon: 'pi pi-check-circle', class: 'text-green-500' };
        case 'pending': return { icon: 'pi pi-clock', class: 'text-orange-500' };
        case 'rejected': return { icon: 'pi pi-times-circle', class: 'text-red-500' };
        default: return { icon: 'pi pi-info-circle', class: 'text-gray-500' };
    }
};

const getItemTypeBadge = (type) => {
    switch (type) {
        case 'module': return { label: 'Módulo', class: 'bg-blue-500/20 text-blue-400 border-blue-500/30' };
        case 'limit': return { label: 'Límite', class: 'bg-purple-500/20 text-purple-400 border-purple-500/30' };
        case 'feature': return { label: 'Función', class: 'bg-green-500/20 text-green-400 border-green-500/30' };
        default: return { label: type, class: 'bg-gray-500/20 text-gray-400 border-gray-500/30' };
    }
};

// --- TESLA UI PT ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-3 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-3 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = { root: { class: '!rounded-full !px-2 !py-0.5 !text-[9px] !font-bold border' } };
</script>

<template>
    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                <i class="pi pi-money-bill"></i> Historial de pagos
            </h2>
            <Button
                label="Registrar pago + versión"
                icon="pi pi-plus"
                severity="primary"
                size="small"
                class="!rounded-xl !text-xs !uppercase !tracking-wider"
                @click="emit('registerPayment')"
            />
        </div>

        <DataTable :value="versions" v-model:expandedRows="expandedVersions" :paginator="true" :rows="5" removableSort :pt="dataTablePt">
            <!-- Columna: Versión (expandible) -->
            <Column expander style="width: 3rem" />
            <Column header="Versión" sortable sortField="start_date" style="min-width: 12rem">
                <template #body="{ data }">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white m-0">
                            {{ formatDate(data.start_date) }} → {{ formatDate(data.end_date) }}
                        </p>
                        <p class="text-[9px] uppercase tracking-widest text-gray-500 m-0">
                            {{ data.items?.length || 0 }} items
                        </p>
                    </div>
                </template>
            </Column>

            <!-- Columna: Pagos asociados -->
            <Column header="Pagos" style="min-width: 14rem">
                <template #body="{ data }">
                    <div v-if="data.payments && data.payments.length > 0" class="space-y-2">
                        <div v-for="payment in data.payments" :key="payment.id" class="flex items-center gap-2 flex-wrap">
                            <i
                                :class="[getPaymentStatusIcon(payment.status).icon, getPaymentStatusIcon(payment.status).class, '!text-[10px]']"
                            ></i>
                            <div v-if="payment.referral_discount_pct" class="flex items-baseline gap-1">
                                <span class="text-[10px] text-gray-400 line-through font-mono">{{ formatCurrency(parseFloat(payment.amount) + parseFloat(payment.referral_discount_amount || 0)) }}</span>
                                <i class="pi pi-arrow-right !text-[8px] text-gray-500"></i>
                                <span class="font-mono text-sm dark:text-white">{{ formatCurrency(payment.amount) }}</span>
                                <span class="text-[9px] text-green-600 dark:text-green-400 font-bold">-{{ payment.referral_discount_pct }}% ref.</span>
                            </div>
                            <span v-else class="font-mono text-sm dark:text-white">{{ formatCurrency(payment.amount) }}</span>
                            <span class="text-[9px] uppercase tracking-widest text-gray-500">{{ payment.payment_method }}</span>
                            <Tag
                                :value="payment.status"
                                :pt="tagPt"
                                :class="payment.status === 'approved'
                                    ? '!bg-green-500/20 !text-green-400 !border-green-500/30'
                                    : payment.status === 'pending'
                                        ? '!bg-orange-500/20 !text-orange-400 !border-orange-500/30'
                                        : '!bg-red-500/20 !text-red-400 !border-red-500/30'"
                            />
                        </div>
                    </div>
                    <span v-else class="text-[10px] text-gray-400 uppercase tracking-widest">Sin pagos</span>
                </template>
            </Column>

            <!-- Columna: Acciones -->
            <Column headerStyle="width: 10rem; text-align: center">
                <template #body="{ data }">
                    <div class="flex items-center gap-1 justify-center">
                        <Button
                            icon="pi pi-pencil"
                            text
                            rounded
                            size="small"
                            v-tooltip.top="'Editar items de esta versión'"
                            class="!w-8 !h-8 !text-gray-500 hover:!text-primary-500 hover:!bg-primary-500/10 !transition-colors"
                            @click="emit('editVersion', data)"
                        />
                        <Button
                            icon="pi pi-trash"
                            text
                            rounded
                            size="small"
                            severity="danger"
                            v-tooltip.top="'Eliminar esta versión y sus pagos'"
                            class="!w-8 !h-8 !text-gray-500 hover:!text-red-500 hover:!bg-red-500/10 !transition-colors"
                            @click="confirmDeleteVersion(data)"
                        />
                    </div>
                </template>
            </Column>

            <!-- Row expansion: Items de la versión -->
            <template #expansion="{ data }">
                <div class="p-4 bg-gray-50/50 dark:bg-[#1a1a1a]/50">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Items del plan en esta versión</p>
                    <div v-if="data.processed_items && data.processed_items.length > 0" class="space-y-2">
                        <div
                            v-for="item in data.processed_items"
                            :key="item.item_key"
                            class="flex items-center justify-between py-2 px-3 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a]"
                        >
                            <div class="flex items-center gap-2">
                                <Tag
                                    :value="getItemTypeBadge(item.item_type).label"
                                    :pt="tagPt"
                                    :class="getItemTypeBadge(item.item_type).class"
                                />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.name }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-widest">
                                    {{ item.billing_period || '--' }}
                                </span>
                                <span v-if="item.package_size > 1" class="text-xs text-gray-400 whitespace-nowrap" v-tooltip.top="`${(item.quantity / item.package_size)} paquetes × ${item.package_size} unds. c/u`">
                                    {{ (item.quantity / item.package_size) }} paq. × {{ item.package_size }} und.
                                </span>
                                <span v-else class="text-sm font-mono text-gray-900 dark:text-white">
                                    Cant: {{ item.quantity }}
                                </span>
                                <span class="text-sm font-mono text-gray-500">
                                    ${{ Number(item.unit_price).toFixed(2) }}<template v-if="item.package_size > 1">/paq.</template>
                                </span>
                                <span v-if="item.package_size > 1" class="text-[10px] text-gray-400">
                                    (${{ Number(item.price_per_unit).toFixed(2) }}/und.)
                                </span>
                                <span class="text-xs text-gray-400">=</span>
                                <span class="text-sm font-mono font-bold text-gray-900 dark:text-white">
                                    {{ formatCurrency(((item.quantity || 0) / (item.package_size || 1)) * (item.unit_price || 0)) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-3 text-gray-400 text-xs">
                        Sin items registrados en esta versión.
                    </div>
                </div>
            </template>

            <template #empty>
                <div class="text-center py-6 text-gray-500 text-xs">No hay versiones registradas para este suscriptor.</div>
            </template>
        </DataTable>
    </div>
</template>
