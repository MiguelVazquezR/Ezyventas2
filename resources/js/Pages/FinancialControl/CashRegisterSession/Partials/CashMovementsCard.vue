<script setup>
import { computed } from 'vue';

const props = defineProps({
    cashMovements: {
        type: Array,
        default: () => []
    }
});

const safeCashMovements = computed(() => props.cashMovements || []);
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
</script>

<template>
    <Card>
        <template #title>Movimientos de Efectivo</template>
        <template #content>
             <DataTable :value="safeCashMovements" class="p-datatable-sm">
                 <template #empty><div class="text-center py-4">No hubo movimientos.</div></template>
                 <Column field="description" header="Descripción"></Column>
                 <Column field="user.name" header="Realizado por"></Column>
                 <Column field="amount" header="Monto">
                   <template #body="{data}">
                       <span :class="data.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">{{ formatCurrency(data.amount) }}</span>
                   </template>
                 </Column>
             </DataTable>
        </template>
    </Card>
</template>