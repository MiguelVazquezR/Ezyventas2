<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    customer: Object,
    movements: Array,
});

const print = () => {
    window.print();
};

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};

const getBalanceClass = (balance) => {
    if (balance > 0) return 'text-green-600';
    if (balance < 0) return 'text-red-600';
    return 'text-gray-700';
};

</script>

<template>
    <Head :title="`Estado de Cuenta - ${customer.name}`" />
    <div class="bg-gray-100 min-h-screen p-3 print:p-0 print:bg-white">
        
        <!-- Botón de Imprimir (se oculta al imprimir) -->
        <div class="max-w-4xl mx-auto mb-4 print:hidden">
             <Button @click="print" label="Imprimir / Guardar PDF" icon="pi pi-print" severity="warning" />
        </div>

        <!-- Contenido Imprimible -->
        <main class="max-w-4xl mx-auto bg-white p-8 sm:p-12 shadow-lg print:shadow-none">
            
            <!-- Encabezado -->
            <header class="grid grid-cols-2 items-start mb-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 m-0">{{ customer.branch?.subscription?.commercial_name || 'Mi negocio' }}</h1>
                    <p class="text-sm text-gray-500 m-0">{{ customer.branch?.name || 'Sucursal principal' }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-semibold text-gray-700">Estado de cuenta</h2>
                    <p class="text-gray-500 m-0">Cliente: <span class="font-bold text-gray-800">{{ customer.name }}</span></p>
                    <p class="text-sm text-gray-500 m-0">Generado: {{ formatDate(new Date().toISOString()) }}</p>
                </div>
            </header>

            <!-- Resumen del Cliente -->
            <section class="mb-5 bg-gray-50 p-4 rounded-lg">
                <h4 class="text-lg font-semibold text-gray-800 mb-1">Resumen del cliente</h4>
                <div class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <h6 class="font-medium text-gray-500 m-0">Cliente</h6>
                        <p class="font-semibold text-gray-800 m-0">{{ customer.name }}</p>
                        <p v-if="customer.company_name" class="text-gray-600 m-0">{{ customer.company_name }}</p>
                    </div>
                    <div>
                        <h6 class="font-medium text-gray-500 m-0">Contacto</h6>
                        <p v-if="customer.phone" class="font-semibold text-gray-800 m-0">{{ customer.phone }}</p>
                        <p v-if="customer.email" class="text-gray-600 m-0">{{ customer.email }}</p>
                    </div>
                    <div>
                        <h6 class="font-medium text-gray-500 m-0">Saldo actual</h6>
                        <p class="font-bold text-base m-0" :class="getBalanceClass(customer.balance)">
                            {{ formatCurrency(customer.balance) }}
                        </p>
                        <p class="text-gray-600 m-0">Límite de crédito: {{ formatCurrency(customer.credit_limit) }}</p>
                    </div>
                </div>
            </section>

            <!-- Historial de Movimientos -->
            <section>
                <h4 class="text-lg font-semibold border-b pb-2 mb-4">Historial de movimientos</h4>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left font-semibold py-2">Fecha</th>
                            <th class="text-left font-semibold py-2">Tipo</th>
                            <th class="text-left font-semibold py-2">Descripción</th>
                            <th class="text-right font-semibold py-2">Monto</th>
                            <th class="text-right font-semibold py-2">Saldo resultante</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="movement in movements" :key="movement.date + movement.description" class="border-b last:border-b-0 hover:bg-gray-50">
                            <td class="py-2 pr-2">{{ formatDate(movement.date) }}</td>
                            <td class="py-2 pr-2 capitalize">{{ movement.type }}</td>
                            <td class="py-2 pr-2 text-gray-600">{{ movement.description }}</td>
                            <td class="py-2 pr-2 text-right font-mono" :class="{ 'text-green-600': movement.type.toLowerCase().includes('abono'), 'dark:text-green-400': movement.type.toLowerCase().includes('abono') }">
                                {{ formatCurrency(movement.amount) }}
                            </td>
                            <td class_cs="py-2 pl-2 font-mono font-semibold" :class="getBalanceClass(movement.resulting_balance)">
                                <p class="text-right">{{ formatCurrency(movement.resulting_balance) }}</p>
                            </td>
                        </tr>
                        <tr v-if="!movements || movements.length === 0">
                            <td colspan="5" class="py-8 text-center text-gray-400">Sin movimientos registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </section>

        </main>
    </div>
</template>

<style>
@media print {
    .print\:hidden { display: none; }
    .print\:p-0 { padding: 0 !important; }
    .print\:bg-white { background-color: white !important; }
    .print\:shadow-none { box-shadow: none !important; }
    body { -webkit-print-color-adjust: exact; }
    
    /* Asegurarnos que los colores de texto se impriman */
    .text-green-600 { color: #16a34a !important; }
    .text-red-600 { color: #dc2626 !important; }
    .text-gray-700 { color: #374151 !important; }
}
</style>