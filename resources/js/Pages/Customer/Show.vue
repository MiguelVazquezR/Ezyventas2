<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';


const props = defineProps({
    customer: Object,
    availableCashRegisters: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();
const page = usePage();
const activeSession = computed(() => page.props.activeSession);

const isPaymentModalVisible = ref(false);
const isStartSessionModalVisible = ref(false);
const sessionModalAwaitingPaymentModal = ref(false);

const handleOpenAddBalanceFlow = () => {
    if (!activeSession.value) {
        sessionModalAwaitingPaymentModal.value = true;
        isStartSessionModalVisible.value = true;
    } else {
        isPaymentModalVisible.value = true;
    }
};

watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingPaymentModal.value) {
        sessionModalAwaitingPaymentModal.value = false;
        isPaymentModalVisible.value = true;
    }
});

const handleBalancePaymentSubmit = (paymentData) => {
    const form = router.form({
        payments: paymentData.payments,
        cash_register_session_id: paymentData.cash_register_session_id,
    });

    form.post(route('customers.payments.store', props.customer.id), {
        onSuccess: () => {
            isPaymentModalVisible.value = false;
        },
        preserveScroll: true,
    });
};

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Clientes', url: route('customers.index') },
    { label: props.customer.name }
]);

const deleteCustomer = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar a ${props.customer.name}? Esta acción no se puede deshacer.`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('customers.destroy', props.customer.id));
        }
    });
};

const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const actionItems = computed(() => [
    { label: 'Abonar / Agregar Saldo', icon: 'pi pi-dollar', command: handleOpenAddBalanceFlow, visible: hasPermission('customers.edit') },
    { separator: true },
    { label: 'Crear nuevo cliente', icon: 'pi pi-plus', command: () => router.get(route('customers.create')), visible: hasPermission('customers.create') },
    { label: 'Editar cliente', icon: 'pi pi-pencil', command: () => router.get(route('customers.edit', props.customer.id)), visible: hasPermission('customers.edit') },
    { label: 'Registrar venta', icon: 'pi pi-shopping-cart', command: () => router.get(route('pos.index', { customer_id: props.customer.id })), visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteCustomer, visible: hasPermission('customers.delete') },
]);


// --- Helpers de Formato (sin cambios) ---
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
    if (balance > 0) return 'text-green-600 dark:text-green-400';
    if (balance < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-400';
};

const getTransactionStatusSeverity = (status) => {
    const map = {
        completado: 'success',
        pendiente: 'info',
        cancelado: 'danger',
        reembolsado: 'warning',
    };
    return map[status] || 'secondary';
};

</script>

<template>
    <Head :title="`Cliente: ${customer.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ customer.name }}</h1>
                <p v-if="customer.company_name" class="text-gray-500 dark:text-gray-400 mt-1">{{ customer.company_name }}</p>
            </div>
            <div>
                <Button @click="toggleMenu" label="Acciones" icon="pi pi-chevron-down" iconPos="right" severity="secondary" outlined class="mt-4 sm:mt-0" />
                <Menu ref="menu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Izquierda: Información -->
            <div class="lg:col-span-1 space-y-6">
                 <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                     <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Información de Contacto</h2>
                     <ul class="space-y-3 text-sm">
                         <li v-if="customer.phone" class="flex items-center"><i class="pi pi-phone w-6 text-gray-500"></i> <span class="font-medium">{{ customer.phone }}</span></li>
                         <li v-if="customer.email" class="flex items-center"><i class="pi pi-envelope w-6 text-gray-500"></i> <span class="font-medium">{{ customer.email }}</span></li>
                         <li v-if="customer.tax_id" class="flex items-center"><i class="pi pi-id-card w-6 text-gray-500"></i> <span class="font-medium">{{ customer.tax_id }}</span></li>
                     </ul>
                 </div>
                 <div v-if="hasPermission('customers.see_financial_info')" class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                     <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Información Financiera</h2>
                     <ul class="space-y-3 text-sm">
                         <li class="flex justify-between items-center">
                             <span class="text-gray-500">Saldo Actual</span> 
                             <span :class="getBalanceClass(customer.balance)" class="font-mono font-semibold text-lg">
                                 {{ formatCurrency(customer.balance) }}
                             </span>
                         </li>
                          <li class="flex justify-between items-center">
                             <span class="text-gray-500">Límite de Crédito</span> 
                             <span class="font-mono font-medium">
                                 {{ formatCurrency(customer.credit_limit) }}
                             </span>
                         </li>
                     </ul>
                 </div>
            </div>

            <!-- Columna Derecha: Historial -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Historial de Transacciones</h2>
                    <DataTable :value="customer.transactions" class="p-datatable-sm" responsiveLayout="scroll" :paginator="customer.transactions?.length > 5" :rows="5">
                        <Column field="folio" header="Folio">
                            <template #body="{ data }">
                                <Link :href="route('transactions.show', data.id)" class="text-blue-500 hover:underline">
                                    #{{ data.folio }}
                                </Link>
                            </template>
                        </Column>
                        <Column field="created_at" header="Fecha">
                            <template #body="{ data }"> {{ formatDate(data.created_at) }}</template>
                        </Column>
                        <Column field="total" header="Total">
                             <template #body="{ data }">
                                 {{ formatCurrency(data.total) }}
                            </template>
                        </Column>
                        <Column field="status" header="Estatus">
                            <template #body="{ data }">
                                <Tag :value="data.status" :severity="getTransactionStatusSeverity(data.status)" class="capitalize"/>
                            </template>
                        </Column>
                        <!-- Se elimina la columna de acción con el icono de enlace externo -->
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No hay transacciones registradas.
                            </div>
                        </template>
                    </DataTable>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Movimientos de Saldo</h2>
                    <DataTable :value="customer.balance_movements" class="p-datatable-sm" responsiveLayout="scroll" :paginator="customer.balance_movements?.length > 5" :rows="5">
                        <Column field="transaction.folio" header="Folio Venta">
                           <template #body="{data}">
                               <Link v-if="data.transaction" :href="route('transactions.show', data.transaction.id)" class="text-blue-500 hover:underline">
                                   #{{ data.transaction.folio }}
                               </Link>
                               <span v-else>N/A</span>
                           </template>
                       </Column>
                        <Column field="created_at" header="Fecha">
                             <template #body="{ data }"> {{ formatDate(data.created_at) }}</template>
                        </Column>
                        <Column field="type" header="Tipo">
                            <template #body="{data}">
                                <span class="capitalize">{{ data.type.replace(/_/g, ' ') }}</span>
                            </template>
                        </Column>
                        <Column field="amount" header="Monto">
                            <template #body="{ data }">
                                <span :class="data.amount > 0 ? 'text-green-600' : 'text-red-600'">
                                    {{ formatCurrency(data.amount) }}
                                </span>
                            </template>
                        </Column>
                         <Column field="balance_after" header="Saldo Resultante">
                                <template #body="{ data }"> {{ formatCurrency(data.balance_after) }}</template>
                        </Column>
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No hay movimientos de saldo registrados.
                            </div>
                        </template>
                    </DataTable>
                    <!-- Se elimina el div que mostraba el mensaje de no hay registros -->
                </div>
            </div>
        </div>

        <StartSessionModal 
            :visible="isStartSessionModalVisible"
            :cash-registers="availableCashRegisters"
            @update:visible="isStartSessionModalVisible = $event"
        />

        <PaymentModal
            v-if="isPaymentModalVisible"
            v-model:visible="isPaymentModalVisible"
            :total-amount="0"
            :client="customer"
            payment-mode="balance"
            @submit="handleBalancePaymentSubmit"
        />
    </AppLayout>
</template>