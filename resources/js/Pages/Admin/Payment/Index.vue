<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const props = defineProps({
    pendingPayments: Array,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <Head title="Pagos Pendientes" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('dashboard')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al panel principal
                </Link>
            </div>

            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Pagos pendientes</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Revisión de transferencias bancarias de suscripciones
                    </p>
                </div>

                <!-- Tabla de Pagos Pendientes -->
                <DataTable :value="pendingPayments" responsiveLayout="scroll" rowHover :pt="dataTablePt">
                    
                    <Column field="subscription_version.subscription.commercial_name" header="Negocio / Empresa">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                    <i class="pi pi-building text-blue-500 !text-xs"></i>
                                </div>
                                <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ data.subscription_version.subscription.commercial_name }}</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column header="Monto del pago">
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-xl text-gray-900 dark:text-white m-0">
                                {{ formatCurrency(data.amount) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Fecha de solicitud" sortable field="created_at">
                        <template #body="{ data }">
                            <span class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                                <i class="pi pi-clock !text-[10px]"></i> {{ formatDate(data.created_at) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Método de pago">
                        <template #body="{ data }">
                            <Tag v-if="data.payment_method === 'transferencia'" value="Transferencia" severity="info" :pt="tagPt" />
                            <Tag v-else :value="data.payment_method" severity="secondary" class="capitalize" :pt="tagPt" />
                        </template>
                    </Column>

                    <Column header="Acciones" headerStyle="width: 8rem; text-align: center;">
                        <template #body="{ data }">
                            <Link :href="route('admin.payments.show', data.id)">
                                <Button label="Revisar" icon="pi pi-arrow-right" iconPos="right" 
                                    class="!rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold !py-2" />
                            </Link>
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-12 opacity-80">
                            <div class="w-20 h-20 rounded-full bg-green-50 dark:bg-green-900/10 flex items-center justify-center mb-4 border border-green-100 dark:border-green-900/30">
                                <i class="pi pi-check-circle !text-4xl text-green-500 drop-shadow-[0_0_12px_rgba(34,197,94,0.4)]"></i>
                            </div>
                            <h3 class="text-xl font-medium text-gray-900 dark:text-white m-0 tracking-tight">Todo al día</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">¡Genial! No hay pagos pendientes por revisar en este momento.</p>
                        </div>
                    </template>
                </DataTable>
                
            </div>
        </div>
    </AppLayout>
</template>