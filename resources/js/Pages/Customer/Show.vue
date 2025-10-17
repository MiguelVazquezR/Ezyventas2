<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';

const props = defineProps({
    customer: Object,
    historicalMovements: Array,
    userBankAccounts: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();
const page = usePage();

// --- LÓGICA DE SESIÓN CORREGIDA ---
const activeSession = computed(() => page.props.activeSession);
const joinableSessions = computed(() => page.props.joinableSessions);
const availableCashRegisters = computed(() => page.props.availableCashRegisters);

const isPaymentModalVisible = ref(false);
const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingPaymentModal = ref(false);

const handleOpenAddBalanceFlow = () => {
    if (activeSession.value) {
        isPaymentModalVisible.value = true;
    } else if (joinableSessions.value && joinableSessions.value.length > 0) {
        sessionModalAwaitingPaymentModal.value = true;
        isJoinSessionModalVisible.value = true;
    } else {
        sessionModalAwaitingPaymentModal.value = true;
        isStartSessionModalVisible.value = true;
    }
};

// Este watcher ahora funciona correctamente para ambos flujos (iniciar y unirse)
watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingPaymentModal.value) {
        sessionModalAwaitingPaymentModal.value = false;
        isPaymentModalVisible.value = true;
    }
});


const handleBalancePaymentSubmit = (paymentData) => {
    router.post(route('customers.payments.store', props.customer.id), paymentData, {
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
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteCustomer, visible: hasPermission('customers.delete') },
]);


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
        pendiente: 'warn',
        cancelado: 'danger',
        reembolsado: 'info',
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
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Ventas</h2>
                    <DataTable :value="customer.transactions" class="p-datatable-sm" responsiveLayout="scroll" :paginator="customer.transactions?.length > 5" :rows="5">
                        <Column field="folio" header="Folio">
                            <template #body="{ data }">
                                <Link :href="route('transactions.show', data.id)" class="text-blue-500 hover:underline">
                                    #{{ data.folio }}
                                </Link>
                            </template>
                        </Column>
                        <Column field="created_at" header="Fecha" sortable>
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
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No hay ventas registradas.
                            </div>
                        </template>
                    </DataTable>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Historial de movimientos</h2>
                    <DataTable :value="historicalMovements" class="p-datatable-sm" responsiveLayout="scroll" :paginator="historicalMovements?.length > 5" :rows="5" sortField="date" :sortOrder="-1">
                        <Column field="date" header="Fecha" sortable>
                             <template #body="{ data }"> {{ formatDate(data.date) }}</template>
                        </Column>
                        <Column field="type" header="Tipo">
                            <template #body="{data}">
                                <span class="capitalize">{{ data.type }}</span>
                            </template>
                        </Column>
                        <Column field="description" header="Descripción">
                             <template #body="{data}">
                                 {{ data.description }}
                            </template>
                        </Column>
                        <Column field="amount" header="Monto">
                            <template #body="{ data }">
                                <span :class="{ 'text-green-600': data.type.toLowerCase().includes('abono'), 'dark:text-green-400': data.type.toLowerCase().includes('abono') }">
                                    {{ formatCurrency(data.amount) }}
                                </span>
                            </template>
                        </Column>
                         <Column field="resulting_balance" header="Saldo Resultante">
                               <template #body="{ data }">
                                   <span :class="getBalanceClass(data.resulting_balance)" class="font-mono font-semibold">
                                       {{ formatCurrency(data.resulting_balance) }}
                                   </span>
                               </template>
                         </Column>
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No hay movimientos registrados.
                            </div>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <!-- Modales -->
        <StartSessionModal 
            v-model:visible="isStartSessionModalVisible"
            :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts"
        />
        <JoinSessionModal
            v-model:visible="isJoinSessionModalVisible"
            :sessions="joinableSessions"
        />
        <PaymentModal
            v-if="isPaymentModalVisible"
            v-model:visible="isPaymentModalVisible"
            :total-amount="0"
            :client="customer"
            :active-session="activeSession"
            payment-mode="balance"
            @submit="handleBalancePaymentSubmit"
        />
    </AppLayout>
</template>