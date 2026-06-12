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
        acceptClass: 'p-button-danger',
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
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: () => confirmDeleteAccount(account) }
];

const toggleAccountMenu = (event, account) => {
    accountMenuItems.value = getAccountMenuItems(account);
    menu.value.toggle(event);
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                    <i class="pi pi-wallet !text-sm text-emerald-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Cuentas bancarias</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Gestión de cuentas y saldos</p>
                </div>
            </div>
            
            <Button @click="openCreateBankAccountModal" label="Nueva cuenta" icon="pi pi-plus"
                severity="primary"
                class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold w-full sm:w-auto shadow-sm"
                v-tooltip.bottom="'Añadir nueva cuenta'" />
        </div>

        <!-- Tabla -->
        <div class="flex-grow overflow-x-auto">
            <DataTable :value="subscription.bank_accounts" :pt="dataTablePt" responsiveLayout="scroll">
                
                <Column field="account_name" header="Cuenta">
                    <template #body="{ data }">
                        <div class="flex flex-col">
                            <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.account_name }}</span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest m-0 mt-0.5">{{ data.bank_name }}</span>
                        </div>
                    </template>
                </Column>
                
                <Column header="Sucursales asignadas">
                    <template #body="{ data }">
                        <div class="flex flex-wrap gap-1.5">
                            <Tag v-for="branch in data.branches" :key="branch.id" severity="secondary" :pt="tagPt">
                                <div class="flex items-center gap-1">
                                    <span>{{ branch.name }}</span>
                                    <i v-if="branch.pivot && branch.pivot.is_favorite"
                                        class="pi pi-star-fill text-yellow-500 !text-[8px]"
                                        v-tooltip.bottom="'Favorita para esta sucursal'"></i>
                                </div>
                            </Tag>
                            <span v-if="!data.branches || data.branches.length === 0" class="text-xs text-gray-400 italic m-0">Ninguna</span>
                        </div>
                    </template>
                </Column>
                
                <Column header="Saldo actual" headerStyle="text-align: right">
                    <template #body="{ data }">
                        <div class="text-right">
                            <span class="font-mono text-lg tracking-tight text-gray-900 dark:text-white m-0">
                                {{ formatCurrency(data.balance) }}
                            </span>
                        </div>
                    </template>
                </Column>
                
                <Column headerStyle="width: 4rem; text-align: center;">
                    <template #body="slotProps">
                        <div class="flex justify-center">
                            <Button @click.stop="toggleAccountMenu($event, slotProps.data)"
                                icon="pi pi-ellipsis-v" text rounded 
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" />
                        </div>
                    </template>
                </Column>
                
                <template #empty>
                    <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                        <i class="pi pi-wallet !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin cuentas asignadas</p>
                        <p class="text-xs text-gray-400 mt-1">No has registrado ninguna cuenta bancaria.</p>
                    </div>
                </template>
            </DataTable>
            
            <Menu ref="menu" :model="accountMenuItems" :popup="true" :pt="menuPt" />
            
            <!-- Modales -->
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
        </div>
    </div>
</template>