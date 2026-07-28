<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import { usePermissions } from '@/Composables';

const props = defineProps({
    expense: Object,
    activities: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

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
        message: `¿Estás seguro de que quieres eliminar el gasto con concepto "${props.expense.folio || props.expense.id}"?`,
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

const getOriginLabel = (expense) => {
    if (expense.is_external) {
        return { label: 'Dinero propio / Externo', icon: 'pi pi-wallet', severity: 'info' };
    }
    if (expense.payment_method === 'efectivo') {
        return { label: 'Caja del negocio', icon: 'pi pi-inbox', severity: 'success' };
    }
    if (expense.bank_account) {
        return { label: 'Cuenta del negocio', icon: 'pi pi-building', severity: 'success' };
    }
    return { label: 'Cuenta del negocio', icon: 'pi pi-building', severity: 'success' };
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};

const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl mt-1' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};
</script>

<template>
    <Head :title="`Gasto: ${expense.folio || expense.id}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- Breadcrumb -->
            <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

            <!-- Header Tesla UI -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Detalle del gasto</h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)] animate-pulse"></span>
                            Egreso operativo
                        </p>
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Concepto: {{ expense.folio || 'N/A' }}</span>
                    </div>
                </div>
                <div class="w-full md:w-auto shrink-0 flex gap-2">
                    <Button type="button" label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" />
                    <Menu ref="menu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <!-- Grid Principal -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Columna Principal -->
                <div class="lg:col-span-3 space-y-6">

                    <!-- Card: Información Principal -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Información principal</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                                <div class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Monto</span>
                                    <span class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(expense.amount) }}</span>
                                </div>
                                <div class="pt-2 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha del gasto</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(expense.expense_date) }}</span>
                                </div>
                                <div class="pt-2 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Categoría</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ expense.category.name }}</span>
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                                <div class="flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Estatus</span>
                                    <Tag :value="expense.status" :severity="getStatusSeverity(expense.status)" :pt="tagPt" class="capitalize" />
                                </div>
                                <div class="pt-2 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Registrado por</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ expense.user.name }}</span>
                                </div>
                                <div class="pt-2 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sucursal</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ expense.branch.name }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Información de Pago -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Información de pago</h2>
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Método de pago</span>
                                <Tag class="capitalize" :pt="tagPt">
                                    <i :class="getPaymentMethodIcon(expense.payment_method)" class="mr-1.5"></i>
                                    {{ expense.payment_method }}
                                </Tag>
                            </div>
                            <div class="pt-2 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Origen del dinero</span>
                                <Tag :severity="getOriginLabel(expense).severity" :pt="tagPt" class="capitalize" v-tooltip.top="getOriginLabel(expense).tooltip">
                                    <i :class="getOriginLabel(expense).icon" class="mr-1.5"></i>
                                    {{ getOriginLabel(expense).label }}
                                </Tag>
                            </div>
                            <div v-if="expense.bank_account" class="pt-2 border-t border-gray-200 dark:border-[#2a2a2a] flex justify-between items-center">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cuenta de origen</span>
                                <div class="text-right">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 block">{{ expense.bank_account.account_name }} ({{ expense.bank_account.bank_name }})</span>
                                    <span class="text-[10px] uppercase tracking-widest text-gray-400 block mt-0.5">{{ expense.bank_account.account_number }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Descripción -->
                    <div v-if="expense.description" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Descripción</h2>
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <p class="text-sm text-gray-600 dark:text-gray-400 m-0 leading-relaxed">{{ expense.description }}</p>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha -->
                <!-- <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Historial de actividad</h2> -->
                        <!-- El historial está comentado en la versión actual -->
                        <!-- <div class="flex flex-col items-center justify-center text-center py-10 opacity-60">
                            <i class="pi pi-history !text-2xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin actividad</p>
                            <p class="text-xs text-gray-400 mt-1">No hay cambios registrados en este gasto.</p>
                        </div> -->
                    <!-- </div>
                </div> -->
            </div>
        </div>
    </AppLayout>
</template>