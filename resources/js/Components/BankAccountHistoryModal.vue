<script setup>
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    visible: Boolean,
    account: Object,
});

const emit = defineEmits(['update:visible']);

const movements = ref([]);
const loading = ref(false);

const fetchHistory = async () => {
    if (!props.account) return;
    loading.value = true;
    try {
        const response = await axios.get(route('bank-accounts.history', props.account.id));
        movements.value = response.data;
    } catch (error) {
        console.error("Error al cargar el historial:", error);
    } finally {
        loading.value = false;
    }
};

watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchHistory();
    } else {
        movements.value = []; // Limpiar al cerrar
    }
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDateTime = (dateString) => new Date(dateString).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Historial de Movimientos"
        class="w-full max-w-4xl">
        <div class="p-4">
            <div v-if="account" class="mb-4">
                <h3 class="font-bold text-lg">{{ account.account_name }}</h3>
                <p class="text-sm text-gray-500">{{ account.bank_name }} - Saldo actual: {{ formatCurrency(account.balance) }}</p>
            </div>

            <DataTable :value="movements" :loading="loading" stripedRows responsiveLayout="scroll" size="small" paginator :rows="10">
                <template #empty>No se encontraron movimientos.</template>
                <template #loading>Cargando historial...</template>

                <Column field="date" header="Fecha y Hora" style="min-width: 150px;">
                    <template #body="{ data }">
                        {{ formatDateTime(data.date) }}
                    </template>
                </Column>
                <Column field="type" header="Tipo" style="min-width: 150px;"></Column>
                <Column field="folio" header="Folio/Ref." style="min-width: 120px;">
                    <template #body="{ data }">
                        <Link v-if="data.related_url" :href="data.related_url" class="text-blue-500 hover:underline">
                            {{ data.folio }}
                        </Link>
                        <span v-else>{{ data.folio }}</span>
                    </template>
                </Column>
                <Column field="method" header="Método" style="min-width: 120px;" class="capitalize"></Column>
                <Column field="amount" header="Monto" style="min-width: 120px;">
                     <template #body="{ data }">
                        <span :class="data.amount > 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatCurrency(data.amount) }}
                        </span>
                    </template>
                </Column>
                <Column field="balance_after" header="Saldo Resultante" style="min-width: 140px;">
                    <template #body="{ data }">
                        {{ formatCurrency(data.balance_after) }}
                    </template>
                </Column>
            </DataTable>
        </div>
    </Dialog>
</template>