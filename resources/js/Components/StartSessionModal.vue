<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    cashRegisters: Array,
    userBankAccounts: Array,
});

const emit = defineEmits(['update:visible']);

const user = usePage().props.auth.user;

const form = useForm({
    cash_register_id: null,
    user_id: user.id,
    opening_cash_balance: 0.00,
    bank_accounts: [],
});

watch(() => props.visible, (isVisible) => {
    if (isVisible) {
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


const submit = () => {
    form.post(route('cash-register-sessions.store'), {
        onSuccess: () => {
            closeModal();
        },
        preserveScroll: true,
    });
};

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" :modal="true" header="Inicializar caja" 
        class="w-full max-w-xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white' },
            content: { class: 'dark:bg-[#232323] px-8 py-6' }
        }">
        
        <div v-if="cashRegisters && cashRegisters.length > 0">
            
            <!-- Encabezado de la acción -->
            <div class="flex items-center gap-4 mb-8 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="w-12 h-12 rounded-full bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center text-primary-500 shadow-sm border border-primary-100 dark:border-primary-900/50">
                    <i class="pi pi-desktop text-xl"></i>
                </div>
                <div>
                    <h3 class="font-medium m-0 text-lg text-gray-900 dark:text-gray-100 tracking-tight">Apertura de terminal</h3>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1">Confirmar saldos y habilitar ventas</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Selector de Caja -->
                <div>
                    <InputLabel for="cash-register" value="Equipo asignado *" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                    <Select v-model="form.cash_register_id" :options="cashRegisters" optionLabel="name" optionValue="id" placeholder="Seleccionar terminal libre..." 
                        class="w-full"
                        :pt="{ root: { class: '!rounded-2xl dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] hover:dark:!border-primary-500 transition-colors' } }" />
                    <InputError :message="form.errors.cash_register_id" class="mt-1" />
                </div>
                
                <!-- Saldos Iniciales -->
                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <h5 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-4">Declaración de fondos iniciales</h5>
                    
                    <div class="space-y-5 max-h-[40vh] overflow-y-auto pr-2 custom-scrollbar">
                        
                        <!-- Efectivo -->
                        <div>
                            <InputLabel for="opening-balance" value="Efectivo físico en caja *" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                            <InputNumber id="opening-balance" v-model="form.opening_cash_balance" mode="currency" currency="MXN" locale="es-MX" 
                                class="w-full"
                                :pt="{ input: { root: { class: '!rounded-2xl w-full dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !font-light !text-2xl !text-gray-900 dark:!text-white' } } }" />
                            <InputError :message="form.errors.opening_cash_balance" class="mt-1" />
                        </div>

                        <!-- Cuentas Bancarias -->
                        <div v-if="form.bank_accounts && form.bank_accounts.length > 0" class="space-y-5">
                             <div v-for="(account, index) in form.bank_accounts" :key="account.id">
                                <InputLabel :for="'bank-balance-' + account.id" :value="`Saldo en banco: ${account.bank_name} (${account.account_name})`" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                                <InputNumber :id="'bank-balance-' + account.id" v-model="account.balance" mode="currency" currency="MXN" locale="es-MX" 
                                    class="w-full"
                                    :pt="{ input: { root: { class: '!rounded-2xl w-full dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !font-light !text-2xl !text-gray-900 dark:!text-white' } } }" />
                                <InputError :message="form.errors[`bank_accounts.${index}.balance`]" class="mt-1" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones -->
                 <div class="flex items-center justify-end gap-3 pt-6 mt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button type="submit" label="Iniciar turno" icon="pi pi-power-off" :loading="form.processing" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-8" />
                </div>
            </form>
        </div>
        
        <!-- Estado Vacío (Sin cajas) -->
        <div v-else class="py-12 flex flex-col items-center justify-center text-center">
            <div class="w-16 h-16 bg-gray-50 dark:bg-[#1a1a1a] rounded-full flex items-center justify-center mb-6 border border-gray-100 dark:border-[#3a3a3a]">
                <i class="pi pi-exclamation-triangle text-2xl text-orange-500"></i>
            </div>
            <h3 class="text-xl font-light text-gray-900 dark:text-white mb-2 tracking-tight">Sin equipos disponibles</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs leading-relaxed">
                No hay terminales configuradas o libres en esta sucursal para aperturar una sesión.
            </p>
            <Button label="Cerrar ventana" severity="secondary" @click="closeModal" text class="mt-8 !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
        </div>
    </Dialog>
</template>