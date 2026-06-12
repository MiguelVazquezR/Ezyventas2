<script setup>
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: Object,
    branchOptions: Array,
    saving: Boolean,
});

const emit = defineEmits(['add-account', 'remove-account', 'finish', 'go-back']);

// --- Tesla UI PT ---
const inputPt = {
    root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white' },
};

const inputNumberPt = {
    input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white' } },
};

const multiSelectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' },
};
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">
        
        <!-- Warning message -->
        <Message severity="warn" :closable="false" class="!rounded-xl !text-xs" :pt="{ content: { class: '!text-xs' } }">
            <span class="font-bold">Importante:</span> Este paso es opcional y
            <strong>únicamente para control financiero interno</strong>.
            No solicitamos CVV ni información sensible. Esto NO es para pagar tu suscripción.
        </Message>

        <!-- Tarjetas de cuentas -->
        <div v-if="form.bank_accounts.length === 0" class="text-center py-10 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <i class="pi pi-wallet !text-3xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
            <p class="text-sm text-gray-500 m-0">No hay cuentas bancarias registradas</p>
            <p class="text-[10px] text-gray-400 m-0 mt-1">Puedes añadirlas más tarde desde el panel de administración</p>
        </div>

        <div v-for="(account, index) in form.bank_accounts" :key="index"
            class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] space-y-4 relative"
        >
            <!-- Header de la cuenta -->
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-primary-500/10 flex items-center justify-center">
                        <i class="pi pi-credit-card text-primary-500 !text-sm"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300 m-0">
                        Cuenta {{ index + 1 }} {{ account.account_name ? '— ' + account.account_name : '' }}
                    </span>
                </div>
                <Button icon="pi pi-trash" severity="danger" text rounded
                    @click="emit('remove-account', index)" class="!w-7 !h-7 !p-0" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre del banco *</label>
                    <InputText v-model="account.bank_name"
                        :invalid="!!form.errors[`bank_accounts.${index}.bank_name`]" :pt="inputPt" />
                    <InputError :message="form.errors[`bank_accounts.${index}.bank_name`]" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Alias de la cuenta *</label>
                    <InputText v-model="account.account_name" placeholder="Ej. Cheques"
                        :invalid="!!form.errors[`bank_accounts.${index}.account_name`]" :pt="inputPt" />
                    <InputError :message="form.errors[`bank_accounts.${index}.account_name`]" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre del titular *</label>
                    <InputText v-model="account.owner_name"
                        :invalid="!!form.errors[`bank_accounts.${index}.owner_name`]" :pt="inputPt" />
                    <InputError :message="form.errors[`bank_accounts.${index}.owner_name`]" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Saldo inicial (opcional)</label>
                    <InputNumber v-model="account.balance" mode="currency" currency="MXN" locale="es-MX" fluid :pt="inputNumberPt" />
                    <InputError :message="form.errors[`bank_accounts.${index}.balance`]" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">No. de cuenta (opcional)</label>
                    <InputText v-model="account.account_number" :pt="inputPt" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clabe (opcional)</label>
                    <InputText v-model="account.clabe" :pt="inputPt" />
                </div>
                <div class="md:col-span-2 flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Disponible en sucursales</label>
                    <MultiSelect v-model="account.branch_ids" :options="branchOptions"
                        optionLabel="label" optionValue="value"
                        placeholder="Seleccionar sucursales" class="w-full" :pt="multiSelectPt" />
                    <InputError :message="form.errors[`bank_accounts.${index}.branch_ids`]" />
                </div>
            </div>
        </div>

        <Button label="Añadir cuenta bancaria" icon="pi pi-plus" severity="secondary" outlined
            @click="emit('add-account')" class="!rounded-xl !text-xs !uppercase !tracking-wider" />

        <!-- Navegación -->
        <div class="flex justify-between pt-2">
            <Button label="Anterior" icon="pi pi-arrow-left" severity="secondary" outlined
                @click="emit('go-back')" class="!rounded-full !text-xs !uppercase !tracking-wider" />
            <Button label="Finalizar configuración" icon="pi pi-check"
                @click="emit('finish')" :loading="saving || form.processing"
                class="!rounded-full !text-xs !uppercase !tracking-wider" />
        </div>
    </div>
</template>
