<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DiffViewer from '@/Components/DiffViewer.vue';
import { useConfirm } from 'primevue/useconfirm';
import { usePermissions } from '@/Composables';
import Breadcrumb from 'primevue/breadcrumb';
import SplitButton from 'primevue/splitbutton';
import Tag from 'primevue/tag';

const props = defineProps({
    expense: Object,
    activities: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Gastos', url: route('expenses.index') },
    { label: props.expense.folio || 'Detalle del Gasto' }
]);

const toggleStatus = () => {
    router.patch(route('expenses.updateStatus', props.expense.id), {}, {
        preserveScroll: true,
    });
};

const deleteExpense = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el gasto con folio "${props.expense.folio || props.expense.id}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('expenses.destroy', props.expense.id));
        }
    });
};

const actionItems = computed(() => [
    { label: 'Crear Nuevo', icon: 'pi pi-plus', command: () => router.get(route('expenses.create')), visible: hasPermission('expenses.create') },
    { label: 'Editar Gasto', icon: 'pi pi-pencil', command: () => router.get(route('expenses.edit', props.expense.id)), visible: hasPermission('expenses.edit') },
    {
        label: props.expense.status === 'pagado' ? 'Marcar como Pendiente' : 'Marcar como Pagado',
        icon: 'pi pi-check-circle',
        command: toggleStatus, visible: hasPermission('expenses.edit') // Assuming edit permission allows status change
    },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteExpense, visible: hasPermission('expenses.delete') },
]);

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
};

const getStatusSeverity = (status) => {
    return status === 'pagado' ? 'success' : 'warning';
};

const getPaymentMethodIcon = (method) => {
    const icons = {
        efectivo: 'pi pi-money-bill',
        tarjeta: 'pi pi-credit-card',
        transferencia: 'pi pi-arrows-h',
    };
    return icons[method] || 'pi pi-question-circle';
};
</script>

<template>

    <Head :title="`Gasto: ${expense.folio || expense.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Detalle del Gasto</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Concepto / Folio / Referencia: {{ expense.folio || 'N/A' }}</p>
            </div>
            <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0">
            </SplitButton>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal: Detalles -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-6">
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información Principal</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span class="text-gray-500">Monto</span> <span
                                        class="font-medium text-lg">{{ new Intl.NumberFormat('es-MX', {
                                            style:
                                                'currency', currency: 'MXN' }).format(expense.amount) }}</span></li>
                                <li class="flex justify-between"><span class="text-gray-500">Fecha del Gasto</span>
                                    <span class="font-medium">{{ formatDate(expense.expense_date) }}</span></li>
                                <li class="flex justify-between"><span class="text-gray-500">Categoría</span> <span
                                        class="font-medium">{{ expense.category.name }}</span></li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles Adicionales</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span class="text-gray-500">Estatus</span>
                                    <Tag :value="expense.status" :severity="getStatusSeverity(expense.status)" class="capitalize" />
                                </li>
                                <li class="flex justify-between"><span class="text-gray-500">Registrado por</span> <span
                                        class="font-medium">{{ expense.user.name }}</span></li>
                                <li class="flex justify-between"><span class="text-gray-500">Sucursal</span> <span
                                        class="font-medium">{{ expense.branch.name }}</span></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- INICIA SECCIÓN DE PAGO -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información de Pago</h2>
                    <ul class="space-y-3 text-sm">
                        <li class="flex justify-between items-center">
                            <span class="text-gray-500">Método de Pago</span>
                             <Tag class="capitalize">
                                <i :class="getPaymentMethodIcon(expense.payment_method)" class="mr-2"></i>
                                {{ expense.payment_method }}
                            </Tag>
                        </li>
                        <li v-if="expense.bank_account" class="flex justify-between">
                            <span class="text-gray-500">Cuenta de Origen</span>
                            <div class="text-right font-medium">
                                <div>{{ expense.bank_account.account_name }} ({{ expense.bank_account.bank_name }})</div>
                                <div class="text-xs text-gray-400">{{ expense.bank_account.account_number }}</div>
                            </div>
                        </li>
                    </ul>
                </div>
                <!-- TERMINA SECCIÓN DE PAGO -->

                <div v-if="expense.description" class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h3 class="font-semibold mb-2 text-lg border-b pb-3">Descripción</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">{{ expense.description }}</p>
                </div>
            </div>

            <!-- Columna Derecha: Historial -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-6">Historial de Actividad</h2>
                    <div v-if="activities && activities.length > 0" class="relative max-h-[600px] overflow-y-auto pr-2">
                        <div class="relative pl-6">
                            <div class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                            </div>
                            <div class="space-y-8">
                                <div v-for="activity in activities" :key="activity.id" class="relative">
                                    <div class="absolute left-0 top-1.5 -translate-x-1/2">
                                        <span
                                            class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                                            :class="{ 'bg-blue-500': activity.event === 'created', 'bg-orange-500': activity.event === 'updated', 'bg-red-500': activity.event === 'deleted' }">
                                            <i
                                                :class="{ 'pi pi-plus': activity.event === 'created', 'pi pi-pencil': activity.event === 'updated', 'pi pi-trash': activity.event === 'deleted' }"></i>
                                        </span>
                                    </div>
                                    <div class="ml-10">
                                        <h3 class="font-semibold">{{ activity.description }}</h3>
                                        <p class="text-xs text-gray-500">Por {{ activity.causer }} - {{
                                            activity.timestamp }}</p>
                                        <div v-if="activity.event === 'updated' && Object.keys(activity.changes.after).length > 0"
                                            class="mt-3 text-sm space-y-2">
                                            <div v-for="(value, key) in activity.changes.after" :key="key">
                                                <p class="font-medium">{{ key }}</p>
                                                <!-- CORRECCIÓN: Convertir valores a String antes de pasarlos a DiffViewer -->
                                                <DiffViewer :oldValue="String(activity.changes.before[key] || '')"
                                                    :newValue="String(value || '')" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-500 py-8"> No hay actividades registradas. </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>