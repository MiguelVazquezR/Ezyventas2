<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import BankAccountModal from '@/Components/BankAccountModal.vue';
import BankAccountHistoryModal from '@/Components/BankAccountHistoryModal.vue';
import BankAccountTransferModal from '@/Components/BankAccountTransferModal.vue';

const props = defineProps({
    subscription: Object
});

const confirm = useConfirm();
const menu = ref();

const isBankAccountModalVisible = ref(false);
const selectedBankAccount = ref(null);
const accountMenuItems = ref([]);

const isHistoryModalVisible = ref(false);
const selectedAccountForHistory = ref(null);

const isTransferModalVisible = ref(false);
const selectedAccountForTransfer = ref(null);

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

const openHistoryModal = (account) => {
    selectedAccountForHistory.value = account;
    isHistoryModalVisible.value = true;
};

const openTransferModal = (account) => {
    selectedAccountForTransfer.value = account;
    isTransferModalVisible.value = true;
};

const getAccountMenuItems = (account) => [
    { label: 'Historial de movimientos', icon: 'pi pi-history', command: () => openHistoryModal(account) },
    { label: 'Realizar transferencia', icon: 'pi pi-arrows-h', command: () => openTransferModal(account) },
    { separator: true },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => openEditBankAccountModal(account) },
    { label: 'Eliminar', icon: 'pi pi-trash', command: () => confirmDeleteAccount(account) }
];

const toggleAccountMenu = (event, account) => {
    accountMenuItems.value = getAccountMenuItems(account);
    menu.value.toggle(event);
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>
    <Card>
        <template #title>
            <div class="flex justify-between items-center">
                <span>Cuentas bancarias</span>
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
                            <Button @click="toggleAccountMenu($event, slotProps.data)"
                                icon="pi pi-ellipsis-v" text rounded size="small" />
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
            
            <BankAccountModal 
                :visible="isBankAccountModalVisible" 
                :account="selectedBankAccount"
                :branches="subscription.branches" 
                @update:visible="isBankAccountModalVisible = $event" 
            />

            <BankAccountHistoryModal 
                v-model:visible="isHistoryModalVisible" 
                :account="selectedAccountForHistory" 
            />

            <BankAccountTransferModal 
                v-model:visible="isTransferModalVisible" 
                :account="selectedAccountForTransfer"
                :all-accounts="subscription.bank_accounts" 
            />
        </template>
    </Card>
</template>