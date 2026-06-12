<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/Components/InputError.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    visible: Boolean,
    cashRegister: Object,
    branchUsers: Array,
    userBankAccounts: Array,
});

const emit = defineEmits(['update:visible']);

// composables
const { hasPermission } = usePermissions();

const form = useForm({
    cash_register_id: props.cashRegister?.id,
    opening_cash_balance: 0.0,
    user_id: null,
    bank_accounts: [],
});

watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        form.cash_register_id = props.cashRegister?.id;

        if (props.userBankAccounts) {
            form.bank_accounts = props.userBankAccounts.map(account => ({
                id: account.id,
                bank_name: account.bank_name,
                account_name: account.account_name,
                balance: parseFloat(account.balance)
            }));
        } else {
            form.bank_accounts = [];
        }
    }
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    form.post(route('cash-register-sessions.store'), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
};

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors w-full' },
    label: { class: '!text-sm !py-2.5' },
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm w-full font-mono' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal class="w-full max-w-md mx-4" :pt="dialogPt">
        
        <!-- Custom Header -->
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                    <i class="pi pi-key !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Inicio de turno</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Apertura de caja registradora
                    </p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-5">
            <!-- Mensaje Informativo -->
            <div class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl flex items-start gap-3 border border-blue-100 dark:border-blue-900/30 mb-2">
                <i class="pi pi-info-circle mt-0.5 !text-lg text-blue-500"></i>
                <div>
                    <p class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 mb-1">Nueva sesión</p>
                    <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                        Estás a punto de iniciar una nueva sesión para la caja <strong class="font-bold">{{ cashRegister?.name }}</strong>.
                    </p>
                </div>
            </div>

            <div class="max-h-[50vh] overflow-y-auto custom-scrollbar pr-2 space-y-5">
                
                <!-- Operador -->
                <div>
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">
                        Operador de la sesión *
                    </span>
                    <Select id="user_id" v-model="form.user_id" :options="branchUsers" optionLabel="name"
                        optionValue="id" placeholder="Selecciona un usuario..." :pt="selectPt"
                        :optionDisabled="(option) => option.is_busy">
                        <template #option="slotProps">
                            <div class="flex justify-between items-center w-full">
                                <span>{{ slotProps.option.name }}</span>
                                <Tag v-if="slotProps.option.is_busy" value="Ocupado" severity="danger" :pt="tagPt" />
                                <Tag v-else value="Libre" severity="success" :pt="tagPt" />
                            </div>
                        </template>
                    </Select>
                    <InputError :message="form.errors.user_id" class="mt-2" />
                </div>

                <div class="border-t border-gray-100 dark:border-[#3a3a3a] my-2"></div>

                <!-- Saldos Iniciales -->
                <div>
                    <h5 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-4">Saldos iniciales</h5>

                    <div class="space-y-5">
                        <!-- Efectivo -->
                        <div>
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">
                                Fondo de caja inicial (Efectivo) *
                            </span>
                            <InputNumber id="opening_cash_balance" v-model="form.opening_cash_balance" mode="currency"
                                currency="MXN" locale="es-MX" :min="0" :pt="{ input: inputPt }" />
                            <InputError :message="form.errors.opening_cash_balance" class="mt-2" />
                        </div>

                        <!-- Cuentas Bancarias -->
                        <template v-if="form.bank_accounts && form.bank_accounts.length > 0">
                            <div v-for="(account, index) in form.bank_accounts" :key="account.id">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2 flex items-center gap-1">
                                    <i class="pi pi-building !text-[9px]"></i> {{ account.bank_name }} ({{ account.account_name }})
                                </span>
                                <InputNumber :id="'bank-balance-' + account.id" v-model="account.balance" mode="currency"
                                    currency="MXN" locale="es-MX" :min="0" :pt="{ input: inputPt }" />
                                <InputError :message="form.errors[`bank_accounts.${index}.balance`]" class="mt-2" />
                            </div>
                        </template>
                    </div>
                </div>

            </div>
            
            <!-- Acciones -->
            <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button type="button" label="Cancelar" text @click="closeModal" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                <Button type="submit" label="Abrir caja" :loading="form.processing" severity="success" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6" />
            </div>
        </form>
    </Dialog>
</template>