<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, Link, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';
import { useToast } from 'primevue/usetoast';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import PaymentModal from '@/Components/PaymentModal.vue';
import PrintModal from '@/Components/PrintModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

// --- PROPS CORREGIDAS (Sintaxis Simplificada) ---
const props = defineProps({
    customer: Object,
    historicalMovements: Array,
    userBankAccounts: Array,
    activeLayaways: Array,
    availableTemplates: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();
const page = usePage();
const toast = useToast();

// --- LÓGICA DE SESIÓN ---
const activeSession = computed(() => page.props.activeSession);
const joinableSessions = computed(() => page.props.joinableSessions);
const availableCashRegisters = computed(() => page.props.availableCashRegisters);

const isPaymentModalVisible = ref(false);
const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingPaymentModal = ref(false);
const isPaymentProcessing = ref(false);

// --- Lógica para modal de impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

const openPrintModal = () => {
    printDataSource.value = { type: 'customer', id: props.customer.id };
    isPrintModalVisible.value = true;
};

// --- Lógica para modal de ajuste (MEJORADA) ---
const isAdjustModalVisible = ref(false);

const adjustForm = useForm({
    adjustment_type: 'add', // 'add' o 'set_total'
    amount: null,
    notes: '',
    direction: 'credit', // 'credit' (positivo/a favor) o 'debit' (negativo/deuda)
});

const adjustmentTypeOptions = ref([
    { label: 'Aplicar movimiento', value: 'add' },
    { label: 'Definir saldo final', value: 'set_total' },
]);

// Opciones dinámicas para la dirección (Acción)
const adjustmentDirectionOptions = computed(() => {
    if (adjustForm.adjustment_type === 'add') {
        return [
            { label: 'Sumar saldo (+)', value: 'credit', icon: 'pi pi-plus' },
            { label: 'Restar saldo (-)', value: 'debit', icon: 'pi pi-minus' }
        ];
    } else {
        return [
            { label: 'Saldo a favor (Positivo)', value: 'credit', icon: 'pi pi-arrow-up' },
            { label: 'Saldo deudor (Negativo)', value: 'debit', icon: 'pi pi-arrow-down' }
        ];
    }
});

const openAdjustModal = () => {
    adjustForm.reset();
    adjustForm.direction = 'credit'; // Default a positivo
    isAdjustModalVisible.value = true;
};

const submitAdjustment = () => {
    adjustForm.transform((data) => {
        // Aseguramos que el monto base sea positivo (magnitud)
        let finalAmount = Math.abs(Number(data.amount));

        // Aplicamos el signo según la dirección seleccionada
        if (data.direction === 'debit') {
            finalAmount = -finalAmount;
        }

        return {
            ...data,
            amount: finalAmount,
        }
    }).post(route('customers.adjustBalance', props.customer.id), {
        onSuccess: () => {
            isAdjustModalVisible.value = false;
            adjustForm.reset();
        },
        preserveScroll: true,
    });
};

// --- Resto de lógica (Add Balance, etc.) ---
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

watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingPaymentModal.value) {
        sessionModalAwaitingPaymentModal.value = false;
        isPaymentModalVisible.value = true;
    }
});

const handleBalancePaymentSubmit = (paymentData) => {
    if (!activeSession.value) {
        usePage().props.flash.error = 'No hay una sesión de caja activa para registrar el pago.';
        return;
    }

    const payload = {
        ...paymentData,
        cash_register_session_id: activeSession.value.id
    };

    isPaymentProcessing.value = true;

    router.post(route('customers.payments.store', props.customer.id), payload, {
        onSuccess: () => {
            isPaymentModalVisible.value = false;
            //toast.add({ severity: 'success', summary: 'Abono registrado', detail: 'El saldo ha sido actualizado.', life: 3000 });
            openPrintModal();
        },
        onFinish: () => { 
            isPaymentProcessing.value = false;
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
        header: 'Confirmar eliminación',
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
    { label: 'Abonar / agregar saldo', icon: 'pi pi-dollar', command: handleOpenAddBalanceFlow, visible: hasPermission('customers.edit') },
    { label: 'Ajuste de saldo manual', icon: 'pi pi-sliders-h', command: openAdjustModal, visible: hasPermission('customers.edit') },
    { separator: true },
    { label: 'Imprimir Ficha / Ticket', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('customers.see_details') },
    { label: 'Crear nuevo cliente', icon: 'pi pi-plus', command: () => router.get(route('customers.create')), visible: hasPermission('customers.create') },
    { label: 'Editar cliente', icon: 'pi pi-pencil', command: () => router.get(route('customers.edit', props.customer.id)), visible: hasPermission('customers.edit') },
    {
        label: 'Estado de cuenta (PDF)',
        icon: 'pi pi-file-pdf',
        command: () => window.open(route('customers.printStatement', props.customer.id), '_blank'),
        visible: hasPermission('customers.see_details')
    },
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

const formatDateOnly = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        return new Date(dateString).toLocaleDateString('es-MX', { dateStyle: 'medium' });
    } catch (e) {
        return dateString;
    }
};

const isExpired = (dateString) => {
    if (!dateString) return false;
    const expiration = new Date(dateString + 'T00:00:00');
    const today = new Date();
    today.setHours(0,0,0,0);
    return expiration < today;
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
        apartado: 'info',
    };
    return map[status] || 'secondary';
};

const sanitizePhone = (phone) => {
    if (!phone) return '';
    return phone.replace(/\D/g, ''); 
};

// --- NUEVO: Computed para Google Maps ---
const hasAddress = computed(() => {
    return props.customer.address && (props.customer.address.street || props.customer.address.city);
});

const formattedAddress = computed(() => {
    if (!hasAddress.value) return 'Sin dirección registrada';
    const a = props.customer.address;
    
    // Construir string legible
    let parts = [];
    if (a.street) parts.push(a.street);
    if (a.exterior_number) parts.push(`#${a.exterior_number}`);
    if (a.interior_number) parts.push(`Int. ${a.interior_number}`);
    if (a.neighborhood) parts.push(`Col. ${a.neighborhood}`);
    if (a.city) parts.push(a.city);
    if (a.state) parts.push(a.state);
    if (a.zip_code) parts.push(`CP ${a.zip_code}`);
    
    return parts.join(', ');
});

const googleMapsUrl = computed(() => {
    if (!hasAddress.value) return '#';
    const a = props.customer.address;
    
    // Construir query para Google Maps
    const queryParts = [
        a.street,
        a.exterior_number,
        a.neighborhood,
        a.city,
        a.state,
        a.zip_code
    ].filter(part => part).join(' '); // Unir con espacios para la búsqueda
    
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(queryParts)}`;
});

</script>

<template>

    <Head :title="`Cliente: ${customer.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ customer.name }}</h1>
                <p v-if="customer.company_name" class="text-gray-500 dark:text-gray-400 mt-1">{{ customer.company_name
                }}</p>
            </div>
            <div>
                <Button @click="toggleMenu" label="Acciones" icon="pi pi-chevron-down" iconPos="right"
                    severity="secondary" outlined class="mt-4 sm:mt-0" />
                <Menu ref="menu" :model="actionItems" :popup="true" />
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Izquierda: Información -->
            <div class="lg:col-span-1 space-y-6">
                
                <!-- Tarjeta: Información de Contacto -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                        Información de contacto</h2>
                    <ul class="space-y-4 text-sm">
                        <!-- Teléfono con acciones -->
                        <li v-if="customer.phone" class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i class="pi pi-phone text-gray-500"></i>
                                <span class="font-medium">{{ customer.phone }}</span>
                            </div>
                            <div class="flex gap-2">
                                <a :href="`https://wa.me/${sanitizePhone(customer.phone)}`" target="_blank" rel="noopener noreferrer">
                                    <Button icon="pi pi-whatsapp" rounded severity="success" size="small" class="!w-8 !h-8" v-tooltip.top="'Enviar WhatsApp'" />
                                </a>
                                <a :href="`tel:${sanitizePhone(customer.phone)}`">
                                    <Button icon="pi pi-phone" rounded severity="info" size="small" class="!w-8 !h-8" v-tooltip.top="'Llamar'" />
                                </a>
                            </div>
                        </li>
                        
                        <li v-if="customer.email" class="flex items-center"><i
                                class="pi pi-envelope w-6 text-gray-500"></i> <span class="font-medium">{{
                                    customer.email }}</span></li>
                        <li v-if="customer.tax_id" class="flex items-center"><i
                                class="pi pi-id-card w-6 text-gray-500"></i> <span class="font-medium">{{
                                    customer.tax_id }}</span></li>
                    </ul>
                </div>

                <!-- Tarjeta: Domicilio (NUEVA) -->
                <div v-if="hasAddress" class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                        <h2 class="text-lg font-semibold">Domicilio</h2>
                        <a :href="googleMapsUrl" target="_blank" rel="noopener noreferrer">
                            <Button icon="pi pi-map-marker" label="Mapa" severity="help" size="small" text outlined />
                        </a>
                    </div>
                    
                    <div class="text-sm space-y-3">
                        <p class="text-gray-800 dark:text-gray-200 font-medium">
                            {{ formattedAddress }}
                        </p>
                        
                        <div v-if="customer.address.cross_streets" class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-md text-xs">
                            <span class="font-semibold block mb-1 text-gray-500">Referencias:</span>
                            {{ customer.address.cross_streets }}
                        </div>
                    </div>
                </div>
                
                <!-- Tarjeta: Información Financiera -->
                <div v-if="hasPermission('customers.see_financial_info')"
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                        Información financiera</h2>
                    <ul class="space-y-3 text-sm">
                        <li class="flex justify-between items-center">
                            <span class="text-gray-500">Saldo actual</span>
                            <span :class="getBalanceClass(customer.balance)" class="font-mono font-semibold text-lg">
                                {{ formatCurrency(customer.balance) }}
                            </span>
                        </li>
                         <!-- Crédito Disponible -->
                         <li class="flex justify-between items-center">
                            <span class="text-gray-500">Crédito disponible</span>
                            <span class="font-mono font-medium text-blue-600 dark:text-blue-400">
                                {{ formatCurrency(customer.available_credit) }}
                            </span>
                        </li>
                        <li class="flex justify-between items-center">
                            <span class="text-gray-500">Límite de crédito</span>
                            <span class="font-mono font-medium">
                                {{ formatCurrency(customer.credit_limit) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Columna Derecha: Historial -->
            <div class="lg:col-span-2 space-y-6">
                <div v-if="activeLayaways && activeLayaways.length > 0" class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                        Apartados activos
                    </h2>
                    <DataTable :value="activeLayaways" class="p-datatable-sm" responsiveLayout="scroll"
                        :paginator="activeLayaways.length > 3" :rows="3" sortField="created_at" :sortOrder="-1">
                        <Column field="folio" header="Folio">
                            <template #body="{ data }">
                                <Link :href="route('transactions.show', data.id)" class="text-blue-500 hover:underline">
                                #{{ data.folio }}
                                </Link>
                            </template>
                        </Column>
                        <Column field="created_at" header="Fecha apartado" sortable>
                            <template #body="{ data }"> {{ formatDate(data.created_at) }}</template>
                        </Column>
                        
                        <Column field="layaway_expiration_date" header="Vencimiento" sortable>
                            <template #body="{ data }">
                                <span :class="{'text-red-500 font-bold': isExpired(data.layaway_expiration_date), 'text-gray-700 dark:text-gray-300': !isExpired(data.layaway_expiration_date)}">
                                    {{ formatDateOnly(data.layaway_expiration_date) }}
                                </span>
                            </template>
                        </Column>

                        <Column field="total_items_quantity" header="Unidades" headerClass="text-center" bodyClass="text-center"></Column>
                        <Column field="total_amount" header="Total">
                            <template #body="{ data }">
                                {{ formatCurrency(data.total_amount) }}
                            </template>
                        </Column>
                         <Column field="pending_amount" header="Pendiente">
                            <template #body="{ data }">
                                <span class="font-semibold text-red-500">
                                    {{ formatCurrency(data.pending_amount) }}
                                </span>
                            </template>
                        </Column>
                        <template #empty>
                            <div class="text-center text-gray-500 py-4">
                                No hay apartados activos.
                            </div>
                        </template>
                    </DataTable>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                        Ventas
                    </h2>
                    <DataTable :value="customer.transactions" class="p-datatable-sm" responsiveLayout="scroll"
                        :paginator="customer.transactions?.length > 5" :rows="5">
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
                                <Tag :value="data.status" :severity="getTransactionStatusSeverity(data.status)"
                                    class="capitalize" />
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
                    <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                        Historial de movimientos
                    </h2>
                    <DataTable :value="historicalMovements" class="p-datatable-sm" responsiveLayout="scroll"
                        :paginator="historicalMovements?.length > 5" :rows="5" sortField="date" :sortOrder="-1">
                        <Column field="date" header="Fecha" sortable>
                            <template #body="{ data }"> {{ formatDate(data.date) }}</template>
                        </Column>
                        <Column field="type" header="Tipo">
                            <template #body="{ data }">
                                <span class="capitalize">{{ data.type }}</span>
                            </template>
                        </Column>
                        <Column field="description" header="Descripción">
                            <template #body="{ data }">
                                {{ data.description }}
                            </template>
                        </Column>
                        <Column field="amount" header="Monto">
                            <template #body="{ data }">
                                <span
                                    :class="{ 'text-green-600': data.type.toLowerCase().includes('abono'), 'dark:text-green-400': data.type.toLowerCase().includes('abono') }">
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
        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />
        <PaymentModal v-if="isPaymentModalVisible" v-model:visible="isPaymentModalVisible" :total-amount="0"
            :client="customer" :loading="isPaymentProcessing" payment-mode="balance" @submit="handleBalancePaymentSubmit" />
        
        <!-- Modal de Impresión -->
        <PrintModal v-if="printDataSource" v-model:visible="isPrintModalVisible" :data-source="printDataSource"
            :available-templates="availableTemplates" />
        
        <!-- MODAL DE AJUSTE MANUAL MEJORADO -->
        <Dialog v-model:visible="isAdjustModalVisible" header="Ajuste Manual de Saldo" modal
            class="w-full max-w-lg mx-4">
            <form @submit.prevent="submitAdjustment" class="space-y-6">
                <!-- 1. Tipo de Acción -->
                <div>
                    <InputLabel value="¿Qué deseas hacer?" class="mb-2" />
                    <SelectButton v-model="adjustForm.adjustment_type" :options="adjustmentTypeOptions" optionLabel="label"
                        optionValue="value" class="w-full" />
                </div>

                <!-- 2. Dirección / Signo -->
                <div>
                    <InputLabel value="Tipo de movimiento" class="mb-2" />
                    <SelectButton v-model="adjustForm.direction" :options="adjustmentDirectionOptions" optionLabel="label"
                        optionValue="value" class="w-full" :allowEmpty="false">
                        <template #option="slotProps">
                            <div class="flex items-center gap-2" :class="{
                                'text-green-600': slotProps.option.value === 'credit',
                                'text-red-600': slotProps.option.value === 'debit'
                            }">
                                <i :class="slotProps.option.icon"></i>
                                <span>{{ slotProps.option.label }}</span>
                            </div>
                        </template>
                    </SelectButton>
                </div>

                <!-- 3. Monto (Siempre positivo en UI) -->
                <div>
                    <InputLabel for="adjust-amount" :value="adjustForm.adjustment_type === 'add' ? 'Monto del movimiento' : 'Nuevo saldo total'" />
                    <InputNumber id="adjust-amount" v-model="adjustForm.amount" mode="currency" currency="MXN"
                        locale="es-MX" class="w-full mt-1" :min="0" :minFractionDigits="2" :maxFractionDigits="2" 
                        placeholder="$0.00" />
                    <small class="text-gray-500">Ingresa el monto en positivo.</small>
                    <InputError :message="adjustForm.errors.amount" />
                </div>

                <div>
                    <InputLabel for="adjust-notes" value="Razón del ajuste (Obligatorio)" />
                    <Textarea id="adjust-notes" v-model="adjustForm.notes" class="w-full mt-1" rows="3" placeholder="Ej: Error en cobro anterior, bonificación, etc." />
                    <InputError :message="adjustForm.errors.notes" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" label="Cancelar" @click="isAdjustModalVisible = false" text
                        severity="secondary" />
                    <Button type="submit" label="Aplicar ajuste" :loading="adjustForm.processing" severity="warning" />
                </div>
            </form>
        </Dialog>
    </AppLayout>
</template>