<script setup>
const props = defineProps({
    summary: {
        type: Array,
        required: true
    }
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
</script>

<template>
    <Card>
        <template #title>Resumen de Cuentas Bancarias</template>
        <template #content>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-4 py-3">Cuenta</th>
                            <th scope="col" class="px-4 py-3 text-right">Saldo Inicial</th>
                            <th scope="col" class="px-4 py-3 text-right">Saldo Final</th>
                            <th scope="col" class="px-4 py-3 text-right">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="account in summary" :key="account.id" class="border-b dark:border-gray-700">
                            <td class="px-4 py-3 font-medium">{{ account.account_name }} ({{ account.bank_name }})</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(account.initial_balance) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(account.final_balance) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-semibold" :class="{'text-green-500': (account.final_balance - account.initial_balance) > 0, 'text-red-500': (account.final_balance - account.initial_balance) < 0}">
                                {{ formatCurrency(account.final_balance - account.initial_balance) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </Card>
</template>