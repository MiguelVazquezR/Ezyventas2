<script setup>
import { computed } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true
    },
    sessionTotals: {
        type: Object,
        required: true
    }
});

const totalInflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const totalOutflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
</script>

<template>
    <Card>
        <template #title>Resumen financiero del corte</template>
        <template #content>
            <ul class="space-y-3 text-sm">
                <li class="flex justify-between"><span>Fondo inicial:</span> <span class="font-mono">{{ formatCurrency(session.opening_cash_balance) }}</span></li>
                <li class="flex justify-between"><span>(+) Ventas en efectivo:</span> <span class="font-mono text-green-500">{{ formatCurrency(sessionTotals.efectivo || 0) }}</span></li>
                <li class="flex justify-between"><span>(+) Otros ingresos:</span> <span class="font-mono text-green-500">{{ formatCurrency(totalInflows) }}</span></li>
                <li class="flex justify-between"><span>(-) Egresos / retiros:</span> <span class="font-mono text-red-500">{{ formatCurrency(totalOutflows) }}</span></li>
                <li class="flex justify-between border-t pt-2 mt-2 font-semibold"><span>Total esperado en caja:</span> <span class="font-mono">{{ formatCurrency(session.calculated_cash_total) }}</span></li>
                <li class="flex justify-between"><span>Total contado por cajero:</span> <span class="font-mono">{{ formatCurrency(session.closing_cash_balance) }}</span></li>
                <li class="flex justify-between font-bold text-base border-t pt-2 mt-2" :class="session.cash_difference != 0 ? (session.cash_difference > 0 ? 'text-green-600' : 'text-red-600') : ''">
                    <span>Diferencia (sobrante / faltante):</span> <span class="font-mono">{{ formatCurrency(session.cash_difference) }}</span>
                </li>
            </ul>
            <div v-if="session.notes" class="mt-4 pt-4 border-t">
                <h4 class="font-semibold text-base">Notas del cajero:</h4>
                <p class="text-sm text-gray-600 dark:text-gray-400 italic mt-1">"{{ session.notes }}"</p>
            </div>
        </template>
    </Card>
</template>