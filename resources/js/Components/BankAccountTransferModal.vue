<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    account: Object, // La cuenta de ORIGEN
    allAccounts: Array, // Todas las cuentas disponibles para ser DESTINO
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    from_account_id: props.account?.id || null,
    to_account_id: null,
    amount: null,
    notes: '',
});

// Filtra las cuentas de destino para no incluir la de origen
const destinationAccounts = computed(() => {
    return props.allAccounts.filter(acc => acc.id !== props.account?.id);
});

const submit = () => {
    form.from_account_id = props.account.id;
    form.post(route('bank-accounts.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
            form.reset();
        },
    });
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', $event)" modal header="Transferencia de fondos"
        class="w-full max-w-lg"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white' },
            content: { class: 'dark:bg-[#232323] px-8 py-6' }
        }">
        
        <form @submit.prevent="submit" v-if="account" class="space-y-6">
            
            <!-- Origen (Estilo Read-Only Panel) -->
            <div>
                <InputLabel value="Cuenta de origen" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex justify-between items-center group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-[#2a2a2a] flex items-center justify-center">
                            <i class="pi pi-arrow-up-right text-gray-500 !text-xs"></i>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ account.account_name }}</p>
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-0.5">{{ account.bank_name }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-light text-gray-900 dark:text-white">{{ formatCurrency(account.balance) }}</p>
                        <p class="text-[9px] text-gray-500 uppercase tracking-widest">Disponible</p>
                    </div>
                </div>
            </div>

            <!-- Separador visual de flujo -->
            <div class="flex justify-center relative z-10">
                <div class="bg-white dark:bg-[#232323] size-8 flex items-center justify-center rounded-full border border-gray-100 dark:border-[#3a3a3a] shadow-sm">
                    <i class="pi pi-sort-alt text-gray-400 text-sm rotate-90 block"></i>
                </div>
            </div>

            <!-- Destino -->
            <div>
                <InputLabel for="to_account" value="Cuenta destino *" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                <Select v-model="form.to_account_id" :options="destinationAccounts" optionLabel="account_name"
                    optionValue="id" placeholder="Seleccionar cuenta..." class="w-full" 
                    :pt="{
                        root: { class: '!rounded-2xl dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] hover:dark:!border-primary-500 transition-colors' }
                    }" />
                <InputError :message="form.errors.to_account_id" class="mt-1" />
            </div>

            <!-- Monto -->
            <div>
                <InputLabel for="amount" value="Monto a transferir *" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                <div class="relative">
                    <InputNumber v-model="form.amount" inputId="amount" mode="currency" currency="MXN" locale="es-MX" 
                        class="w-full"
                        :pt="{ 
                            root: { class: 'w-full' },
                            input: { root: { class: '!rounded-2xl w-full dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !font-light !text-2xl !text-gray-900 dark:!text-white' } } 
                        }" />
                </div>
                <InputError :message="form.errors.amount" class="mt-1" />
            </div>
            
            <!-- Notas -->
            <div>
                <InputLabel for="notes" value="Concepto / notas (opcional)" class="!text-[10px] !uppercase !tracking-widest !text-gray-500 mb-2" />
                <Textarea v-model="form.notes" id="notes" rows="2" 
                    class="w-full !rounded-2xl dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors resize-none !text-sm" />
                <InputError :message="form.errors.notes" class="mt-1" />
            </div>
            
            <!-- Acciones -->
            <div class="flex items-center justify-end gap-3 pt-6 mt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button type="button" label="Cancelar" severity="secondary" @click="$emit('update:visible', false)" text class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                <Button type="submit" label="Ejecutar" :loading="form.processing" icon="pi pi-check" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-8" />
            </div>
        </form>
    </Dialog>
</template>