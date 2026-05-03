<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import BankAccountHistoryModal from '@/Components/BankAccountHistoryModal.vue';
import BankAccountTransferModal from '@/Components/BankAccountTransferModal.vue';

const props = defineProps({
    bankAccounts: Array,
    allBankAccounts: Array
});

const menu = ref();
const selectedAccount = ref(null);
const isHistoryModalVisible = ref(false);
const isTransferModalVisible = ref(false);

const menuItems = ref([
    { label: 'Ver historial', icon: 'pi pi-history', command: () => { isHistoryModalVisible.value = true; } },
    { label: 'Realizar transferencia', icon: 'pi pi-arrows-h', command: () => { isTransferModalVisible.value = true; } }
]);

const toggleMenu = (event, account) => {
    selectedAccount.value = account;
    menu.value.toggle(event);
};

const onTransferSuccess = () => {
    isTransferModalVisible.value = false;
    router.reload({ preserveState: false });
};

const totalBalance = computed(() => {
    if (!props.bankAccounts || props.bankAccounts.length === 0) return 0;
    return props.bankAccounts.reduce((sum, account) => sum + parseFloat(account.balance || 0), 0);
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        <!-- Encabezado -->
        <div class="mb-6 flex justify-between items-start">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Cuentas bancarias</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Acciones rápidas por sucursal</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                <i class="pi pi-building !text-sm text-blue-500"></i>
            </div>
        </div>

        <div v-if="bankAccounts && bankAccounts.length > 0" class="flex-grow flex flex-col">
            <!-- Balance Total -->
            <div class="flex items-end justify-between mb-6 pb-6 border-b border-gray-100 dark:border-[#3a3a3a]">
                <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400 m-0">Balance total</span> 
                <span class="text-4xl lg:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-none">{{ formatCurrency(totalBalance) }}</span> 
            </div>
            
            <!-- Lista de Cuentas -->
            <ul class="space-y-3 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                <li v-for="account in bankAccounts" :key="account.id" 
                    class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all flex justify-between items-center group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-[#2a2a2a] flex items-center justify-center flex-shrink-0">
                            <i class="pi pi-wallet !text-xs text-gray-600 dark:text-gray-400"></i>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ account.account_name }}</p>
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest m-0 mt-0.5">{{ account.bank_name }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3"> 
                        <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatCurrency(account.balance) }}</span> 
                        <Button icon="pi pi-ellipsis-v" text rounded @click="toggleMenu($event, account)" 
                            class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" /> 
                    </div>
                </li>
            </ul>
            
            <!-- Menú de Acciones -->
            <Menu ref="menu" :model="menuItems" :popup="true" 
                :pt="{
                    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2' },
                    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
                    label: { class: 'text-sm font-medium dark:!text-gray-300' },
                    icon: { class: 'dark:!text-gray-400 !text-sm' }
                }" 
            />
        </div>
        
        <!-- Empty State -->
        <div v-else class="flex flex-col items-center justify-center flex-grow text-center py-10 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <i class="pi pi-building !text-2xl text-gray-400 mb-2"></i>
            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin cuentas asignadas</p>
            <p class="text-xs text-gray-400 mt-1 max-w-[200px]">Añade una cuenta para esta sucursal.</p>
        </div>

        <BankAccountHistoryModal v-if="selectedAccount" v-model:visible="isHistoryModalVisible" :account="selectedAccount" />
        <BankAccountTransferModal v-if="selectedAccount" v-model:visible="isTransferModalVisible" :account="selectedAccount" :all-accounts="allBankAccounts" @transfer-success="onTransferSuccess" />
    </div>
</template>