<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import BankAccountModal from '@/Components/BankAccountModal.vue';
import BranchModal from '@/Components/BranchModal.vue';
import BankAccountHistoryModal from '@/Components/BankAccountHistoryModal.vue';
import BankAccountTransferModal from '@/Components/BankAccountTransferModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    subscription: Object,
    planItems: Array,
    usageData: Object,
});

const toast = useToast();
const confirm = useConfirm();

// --- Lógica de Sucursales ---
const isBranchModalVisible = ref(false);
const selectedBranch = ref(null);
const openCreateBranchModal = () => {
    selectedBranch.value = null;
    isBranchModalVisible.value = true;
};
const openEditBranchModal = (branch) => {
    selectedBranch.value = branch;
    isBranchModalVisible.value = true;
};
const confirmDeleteBranch = (branch) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la sucursal "${branch.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.delete(route('branches.destroy', branch.id), { preserveScroll: true });
        }
    });
};

// --- Lógica de Cuentas Bancarias ---
const isBankAccountModalVisible = ref(false);
const selectedBankAccount = ref(null);
const menu = ref();
const accountMenuItems = ref([]);

const openCreateBankAccountModal = () => {
    selectedBankAccount.value = null;
    isBankAccountModalVisible.value = true;
};
const openEditBankAccountModal = (account) => {
    selectedBankAccount.value = account;
    isBankAccountModalVisible.value = true;
};
const confirmDeleteAccount = (account) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la cuenta "${account.account_name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.delete(route('bank-accounts.destroy', account.id), { preserveScroll: true });
        }
    });
};


// --- Lógica para el modal de historial ---
const isHistoryModalVisible = ref(false);
const selectedAccountForHistory = ref(null);
const openHistoryModal = (account) => {
    selectedAccountForHistory.value = account;
    isHistoryModalVisible.value = true;
};

// --- Lógica para el nuevo modal de transferencia ---
const isTransferModalVisible = ref(false);
const selectedAccountForTransfer = ref(null);
const openTransferModal = (account) => {
    selectedAccountForTransfer.value = account;
    isTransferModalVisible.value = true;
};

// Se añade la nueva opción al menú
const getAccountMenuItems = (account) => [
    { label: 'Historial de movimientos', icon: 'pi pi-history', command: () => openHistoryModal(account) },
    { label: 'Realizar Transferencia', icon: 'pi pi-send', command: () => openTransferModal(account) },
    { separator: true },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => openEditBankAccountModal(account) },
    { label: 'Eliminar', icon: 'pi pi-trash', command: () => confirmDeleteAccount(account) }
];

const toggleAccountMenu = (event, account) => {
    accountMenuItems.value = getAccountMenuItems(account);
    menu.value.toggle(event);
};


const currentVersion = computed(() => props.subscription?.versions?.[0] || null);
const displayPlanItems = computed(() => {
    if (!currentVersion.value) return [];
    const activeItemKeys = new Set(currentVersion.value.items.map(item => item.item_key));
    return props.planItems.map(planItem => ({
        ...planItem,
        is_active: activeItemKeys.has(planItem.key),
    }));
});
const activeModules = computed(() => displayPlanItems.value.filter(item => item.type === 'module'));
const activeLimits = computed(() => {
    if (!currentVersion.value) return [];
    return currentVersion.value.items.filter(item => item.item_type === 'limit');
});
const getUsage = (limit) => {
    if (!props.usageData || !limit.item_key) return 0;
    const resourceKey = limit.item_key.replace('limit_', '');
    return props.usageData[resourceKey] ?? 0;
};
const branchLimit = computed(() => {
    if (!activeLimits.value) return null;
    return activeLimits.value.find(l => l.item_key === 'limit_branches');
});
const branchUsage = computed(() => props.usageData?.branches ?? 0);
const branchLimitReached = computed(() => {
    if (!branchLimit.value || branchLimit.value.quantity === -1) {
        return false;
    }
    return branchUsage.value >= branchLimit.value.quantity;
});
const isEditModalVisible = ref(false);
const infoForm = useForm({
    commercial_name: props.subscription.commercial_name,
    business_name: props.subscription.business_name,
});
const submitInfoForm = () => {
    infoForm.put(route('subscription.update'), {
        onSuccess: () => isEditModalVisible.value = false,
    });
};
const fiscalDocumentUrl = computed(() => props.subscription.media[0]?.original_url || null);
const docForm = useForm({ fiscal_document: null });
const fileUploadRef = ref(null);
const onFileSelect = (event) => { docForm.fiscal_document = event.files[0]; };
const uploadDocument = () => {
    docForm.post(route('subscription.documents.store'), {
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Éxito', detail: 'Documento fiscal actualizado.', life: 3000 });
            docForm.reset();
            if (fileUploadRef.value) fileUploadRef.value.clear();
        }
    });
};
const isInvoiceModalVisible = ref(false);
const paymentToRequest = ref(null);
const confirmRequestInvoice = (paymentId) => {
    paymentToRequest.value = paymentId;
    isInvoiceModalVisible.value = true;
};
const requestInvoice = () => {
    if (paymentToRequest.value) {
        router.post(route('subscription.payments.request-invoice', paymentToRequest.value), {}, {
            preserveScroll: true,
            onSuccess: () => {
                isInvoiceModalVisible.value = false;
                paymentToRequest.value = null;
            }
        });
    }
};
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
const getStatusTagSeverity = (status) => ({ activo: 'success', expirado: 'warning', suspendido: 'danger' })[status] || 'info';
const getFileIcon = (type) => {
    if (type.includes('pdf')) return 'pi pi-file-pdf text-red-500 text-4xl';
    if (type.includes('image')) return 'pi pi-image text-blue-500 text-4xl';
    return 'pi pi-file text-gray-500 text-4xl';
};
const getInvoiceStatusTag = (status) => {
    return {
        'no_solicitada': { text: 'No Solicitada', severity: 'secondary' },
        'solicitada': { text: 'Solicitada', severity: 'info' },
        'generada': { text: 'Generada', severity: 'success' },
    }[status] || { text: status, severity: 'secondary' };
};

</script>

<template>
    <Head title="Mi Suscripción" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Mi suscripción</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Aquí puedes ver los detalles de tu plan, historial de
                    pagos, gestion de sucursales, cuentas bancarias e información fiscal.
                </p>
            </header>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna Izquierda -->
                <div class="lg:col-span-1 space-y-6">
                    <Card>
                        <template #title>
                            <div class="flex justify-between items-center">
                                <span>Información general</span>
                                <Button icon="pi pi-pencil" text rounded @click="isEditModalVisible = true"
                                    v-tooltip.bottom="'Editar información'" />
                            </div>
                        </template>
                        <template #content>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Nombre comercial:</span>
                                    <span class="font-semibold">{{ subscription.commercial_name }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Razón social:</span>
                                    <span class="font-semibold">{{ subscription.business_name }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-500">Estatus:</span>
                                    <Tag :value="subscription.status"
                                        :severity="getStatusTagSeverity(subscription.status)" class="capitalize" />
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Miembro desde:</span>
                                    <span class="font-semibold">{{ formatDate(subscription.created_at) }}</span>
                                </div>
                            </div>
                        </template>
                    </Card>
                    <Card>
                        <template #title>Información fiscal</template>
                        <template #content>
                            <div v-if="fiscalDocumentUrl" class="space-y-4">
                                <p class="text-sm text-gray-600 dark:text-gray-300">Tu constancia de situación fiscal
                                    está registrada.</p>
                                <a :href="fiscalDocumentUrl" target="_blank" rel="noopener noreferrer">
                                    <Button label="Ver Documento Actual" icon="pi pi-file-pdf" outlined
                                        severity="secondary" />
                                </a>
                                <p class="text-xs text-gray-500 pt-4 border-t dark:border-gray-700">Para actualizar,
                                    simplemente sube un nuevo archivo.</p>
                            </div>
                            <p v-else class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                                Sube tu Constancia de Situación Fiscal para solicitar facturas.
                            </p>
                            <FileUpload ref="fileUploadRef" name="fiscal_document" @select="onFileSelect"
                                :showUploadButton="false" :showCancelButton="false" customUpload
                                accept=".pdf,.jpg,.jpeg,.png" :maxFileSize="2048000">
                                <template #thumbnail="{ file }">
                                    <div
                                        class="w-full h-full flex items-center justify-center border-2 border-dashed rounded-md p-4">
                                        <i :class="getFileIcon(file.type)"></i>
                                    </div>
                                </template>
                                <template #empty>
                                    <p class="text-sm text-center text-gray-500">Arrastra y suelta tu archivo aquí o haz
                                        clic para seleccionar.</p>
                                </template>
                            </FileUpload>
                            <Button v-if="docForm.fiscal_document" @click="uploadDocument" label="Subir Nuevo Documento"
                                class="w-full mt-4" :loading="docForm.processing" />
                        </template>
                    </Card>
                </div>

                <!-- Columna Derecha -->
                <div class="lg:col-span-2 space-y-6">
                    <Card v-if="currentVersion">
                        <template #title>
                            <div class="flex justify-between items-center">
                                <span>Plan actual y módulos</span>
                                <!-- <Link :href="route('subscription.upgrade.show')"> -->
                                <Button label="Mejorar Suscripción" icon="pi pi-arrow-up" :disabled="true"
                                    size="small" />
                                <!-- </Link> -->
                            </div>
                        </template>
                        <template #subtitle>
                            Vigencia: {{ formatDate(currentVersion.start_date) }} - {{
                                formatDate(currentVersion.end_date) }}
                        </template>
                        <template #content>
                            <div class="mb-6">
                                <h3 class="font-bold mb-4 text-gray-800 dark:text-gray-200">Módulos</h3>
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <div v-for="module in activeModules" :key="module.key"
                                        class="p-4 rounded-lg text-center flex flex-col items-center justify-center transition-all"
                                        :class="module.is_active ? 'bg-gray-50 dark:bg-gray-800' : 'bg-gray-100 dark:bg-gray-900 opacity-60'">
                                        <div class="relative w-full">
                                            <i
                                                :class="[module.meta.icon, '!text-2xl mb-2', module.is_active ? 'text-primary-500' : 'text-gray-500']"></i>
                                            <i v-if="module.is_active"
                                                class="pi pi-check-circle text-green-500 absolute -top-1 -right-1 bg-white dark:bg-gray-800 rounded-full"></i>
                                        </div>
                                        <span class="font-semibold text-sm">{{ module.name }}</span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h3 class="font-bold mb-4 text-gray-800 dark:text-gray-200">Límites del plan</h3>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div v-for="limit in activeLimits" :key="limit.item_key"
                                        class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg text-center flex flex-col justify-between">
                                        <div>
                                            <p class="text-2xl font-bold">
                                                {{ getUsage(limit) }} / <span class="text-gray-400">{{ limit.quantity
                                                    === -1 ? '∞' : limit.quantity }}</span>
                                            </p>
                                            <p class="text-sm text-gray-500">{{ limit.name }}</p>
                                        </div>
                                        <ProgressBar v-if="limit.quantity > 0"
                                            :value="Math.round((getUsage(limit) / limit.quantity) * 100)"
                                            :showValue="false" class="h-2 mt-2"></ProgressBar>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Card>
                    <!-- Panel de Sucursales -->
                    <Card>
                        <template #title>
                            <div class="flex justify-between items-center">
                                <span>Sucursales</span>
                                <Button @click="openCreateBranchModal" icon="pi pi-plus" size="small"
                                    v-tooltip.bottom="branchLimitReached ? 'Límite de sucursales alcanzado' : 'Nueva Sucursal'" />
                            </div>
                        </template>
                        <template #content>
                            <DataTable :value="subscription.branches" stripedRows size="small">
                                <Column field="name" header="Nombre"></Column>
                                <Column field="contact_email" header="Email"></Column>
                                <Column field="contact_phone" header="Teléfono"></Column>
                                <Column header="Principal">
                                    <template #body="slotProps">
                                        <i v-if="slotProps.data.is_main" class="pi pi-check-circle text-green-500"
                                            v-tooltip.bottom="'Sucursal Principal'"></i>
                                    </template>
                                </Column>
                                <Column>
                                    <template #body="slotProps">
                                        <div class="flex justify-end gap-2">
                                            <Button @click="openEditBranchModal(slotProps.data)" icon="pi pi-pencil"
                                                text rounded size="small" />
                                            <Button @click="confirmDeleteBranch(slotProps.data)" icon="pi pi-trash" text
                                                rounded severity="danger" size="small"
                                                :disabled="slotProps.data.is_main" />
                                        </div>
                                    </template>
                                </Column>
                            </DataTable>
                        </template>
                    </Card>
                    <!-- Panel de Cuentas Bancarias -->
                    <Card>
                        <template #title>
                            <div class="flex justify-between items-center">
                                <span>Cuentas Bancarias</span>
                                <Button @click="openCreateBankAccountModal" icon="pi pi-plus" size="small"
                                    v-tooltip.bottom="'Nueva Cuenta'" />
                            </div>
                        </template>
                        <template #content>
                            <DataTable :value="subscription.bank_accounts" size="small" responsiveLayout="scroll">
                                <Column field="account_name" header="Nombre"></Column>
                                <Column field="bank_name" header="Banco"></Column>
                                <Column header="Sucursales Asignadas">
                                    <template #body="{ data }">
                                        <div class="flex flex-wrap gap-1">
                                            <Tag v-for="branch in data.branches" :key="branch.id">
                                                <div class="flex items-center gap-1.5">
                                                    <span>{{ branch.name }}</span>
                                                    <i v-if="branch.pivot && branch.pivot.is_favorite"
                                                        class="pi pi-star-fill text-yellow-400"
                                                        v-tooltip.bottom="'Favorita para esta sucursal'"></i>
                                                </div>
                                            </Tag>
                                        </div>
                                    </template>
                                </Column>
                                <Column header="Saldo actual">
                                    <template #body="{ data }">
                                        <div class="flex flex-wrap gap-1">
                                            {{ formatCurrency(data.balance) }}
                                        </div>
                                    </template>
                                </Column>
                                <Column headerStyle="width: 5rem; text-align: right">
                                    <template #body="slotProps">
                                        <div class="flex justify-end">
                                            <Button @click="toggleAccountMenu($event, slotProps.data)" icon="pi pi-ellipsis-v" text rounded size="small" />
                                        </div>
                                    </template>
                                </Column>
                                <template #empty>
                                    <div class="text-center text-gray-500 py-4">
                                        No has registrado ninguna cuenta.
                                    </div>
                                </template>
                            </DataTable>
                            <Menu ref="menu" :model="accountMenuItems" :popup="true" />
                        </template>
                    </Card>
                    <Card>
                        <template #title>Historial de Versiones y Pagos</template>
                        <template #content>
                            <Accordion>
                                <AccordionPanel v-for="(version, index) in subscription.versions" :key="version.id"
                                    :value="index">
                                    <AccordionHeader>
                                        Periodo: {{ formatDate(version.start_date) + ' - ' +
                                        formatDate(version.end_date) }}
                                    </AccordionHeader>
                                    <AccordionContent>
                                        <div class="p-4">
                                            <h4 class="font-bold mb-2">Conceptos del Plan</h4>
                                            <DataTable :value="version.items" size="small" class="mb-6">
                                                <Column field="name" header="Concepto"></Column>
                                                <Column field="billing_period" header="Periodo">
                                                    <template #body="slotProps"><span class="capitalize">{{
                                                        slotProps.data.billing_period }}</span></template>
                                                </Column>
                                                <Column field="unit_price" header="Precio">
                                                    <template #body="slotProps">{{
                                                        formatCurrency(slotProps.data.unit_price)
                                                    }}</template>
                                                </Column>
                                            </DataTable>
                                            <h4 class="font-bold mb-2">Pagos Realizados</h4>
                                            <DataTable :value="version.payments" size="small">
                                                <Column field="created_at" header="Fecha de Pago">
                                                    <template #body="slotProps">{{ formatDate(slotProps.data.created_at)
                                                    }}</template>
                                                </Column>
                                                <Column field="payment_method" header="Método" class="capitalize">
                                                </Column>
                                                <Column field="amount" header="Monto">
                                                    <template #body="slotProps">{{ formatCurrency(slotProps.data.amount)
                                                    }}</template>
                                                </Column>
                                                <Column field="invoice_status" header="Factura">
                                                    <template #body="{ data }">
                                                        <div v-if="data.invoice_status === 'no_solicitada'">
                                                            <Button @click="confirmRequestInvoice(data.id)"
                                                                label="Solicitar" size="small" outlined
                                                                :disabled="!fiscalDocumentUrl"
                                                                v-tooltip.bottom="!fiscalDocumentUrl ? 'Debes subir tu constancia fiscal' : 'Solicitar factura'" />
                                                        </div>
                                                        <Tag v-else
                                                            :value="getInvoiceStatusTag(data.invoice_status).text"
                                                            :severity="getInvoiceStatusTag(data.invoice_status).severity"
                                                            class="capitalize" />
                                                    </template>
                                                </Column>
                                                <template #empty>
                                                    <div class="text-center text-gray-500 py-4">
                                                        No hay pagos registrados aún.
                                                    </div>
                                                </template>
                                            </DataTable>
                                        </div>
                                    </AccordionContent>
                                </AccordionPanel>
                            </Accordion>
                        </template>
                    </Card>
                </div>
            </div>
        </div>

        <Dialog v-model:visible="isEditModalVisible" modal header="Editar Información" :style="{ width: '30rem' }">
            <form @submit.prevent="submitInfoForm" class="p-2 space-y-4">
                <div>
                    <InputLabel for="commercial_name" value="Nombre Comercial *" />
                    <InputText id="commercial_name" v-model="infoForm.commercial_name" class="w-full mt-1" />
                    <InputError :message="infoForm.errors.commercial_name" />
                </div>
                <div>
                    <InputLabel for="business_name" value="Razón Social (opcional)" />
                    <InputText id="business_name" v-model="infoForm.business_name" class="w-full mt-1" />
                    <InputError :message="infoForm.errors.business_name" />
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <Button type="button" label="Cancelar" severity="secondary" @click="isEditModalVisible = false"
                        text />
                    <Button type="submit" label="Guardar Cambios" :loading="infoForm.processing" />
                </div>
            </form>
        </Dialog>
        <Dialog v-model:visible="isInvoiceModalVisible" modal header="Confirmar Solicitud de Factura"
            :style="{ width: '35rem' }">
            <div class="p-4 text-center">
                <i class="pi pi-info-circle text-5xl text-blue-500 mb-4"></i>
                <h3 class="text-lg font-bold mb-2">Verifica tu Información Fiscal</h3>
                <p class="text-gray-600 dark:text-gray-300">
                    Antes de continuar, por favor asegúrate de que la Constancia de Situación Fiscal que subiste esté
                    actualizada. La factura se generará con los datos de este documento.
                </p>
            </div>
            <template #footer>
                <Button label="Cancelar" text severity="secondary" @click="isInvoiceModalVisible = false" />
                <Button label="Confirmar y Solicitar" icon="pi pi-check" @click="requestInvoice" />
            </template>
        </Dialog>

        <BranchModal :visible="isBranchModalVisible" :branch="selectedBranch" :limit="branchLimit?.quantity"
            :usage="branchUsage" @update:visible="isBranchModalVisible = $event" />

        <BankAccountModal :visible="isBankAccountModalVisible" :account="selectedBankAccount"
            :branches="subscription.branches" @update:visible="isBankAccountModalVisible = $event" />

        <BankAccountHistoryModal 
            v-model:visible="isHistoryModalVisible"
            :account="selectedAccountForHistory" 
        />

        <BankAccountTransferModal 
            v-model:visible="isTransferModalVisible"
            :account="selectedAccountForTransfer"
            :all-accounts="subscription.bank_accounts"
        />
    </AppLayout>
</template>