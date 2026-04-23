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
    { label: 'Ver Historial', icon: 'pi pi-history', command: () => { isHistoryModalVisible.value = true; } },
    { label: 'Realizar Transferencia', icon: 'pi pi-arrows-h', command: () => { isTransferModalVisible.value = true; } }
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
    <Card>
        <template #title>Cuentas bancarias</template>
        <template #subtitle>Balance actual y acciones rápidas (Solo cuentas de esta sucursal).</template>
        <template #content>
            <div v-if="bankAccounts && bankAccounts.length > 0">
                <div class="flex justify-between items-center mb-4 pb-2 border-b border-dashed dark:border-gray-700">
                    <span class="font-bold">Balance total (Sucursal)</span> 
                    <span class="font-bold text-lg">{{ formatCurrency(totalBalance) }}</span> 
                </div>
                <ul class="space-y-3 max-h-60 overflow-y-auto pr-2">
                    <li v-for="account in bankAccounts" :key="account.id" class="flex justify-between items-center">
                        <div>
                            <p class="font-semibold">{{ account.account_name }}</p>
                            <p class="text-sm text-gray-500">{{ account.bank_name }}</p>
                        </div>
                        <div class="flex items-center gap-2"> 
                            <span class="font-mono font-bold">{{ formatCurrency(account.balance) }}</span> 
                            <Button icon="pi pi-ellipsis-v" text rounded @click="toggleMenu($event, account)" /> 
                        </div>
                    </li>
                </ul>
                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
            <p v-else class="text-center text-gray-500 py-4">No hay cuentas bancarias asignadas a esta sucursal.</p>
        </template>
    </Card>

    <BankAccountHistoryModal v-if="selectedAccount" v-model:visible="isHistoryModalVisible" :account="selectedAccount" />
    <BankAccountTransferModal v-if="selectedAccount" v-model:visible="isTransferModalVisible" :account="selectedAccount" :all-accounts="allBankAccounts" @transfer-success="onTransferSuccess" />
</template>