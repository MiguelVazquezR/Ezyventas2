<script setup>
import { computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    visible: Boolean,
    transactionId: {
        type: Number,
        required: true
    },
    payment: {
        type: Object,
        default: null
    },
    bankAccounts: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:visible', 'success']);
const confirm = useConfirm();

const isVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value)
});

const editForm = useForm({
    amount: 0,
    payment_method: '',
    bank_account_id: null,
    notes: ''
});

const paymentMethods = [
    { label: 'Efectivo', value: 'efectivo' },
    { label: 'Tarjeta', value: 'tarjeta' },
    { label: 'Transferencia', value: 'transferencia' },
    { label: 'Saldo de Cliente', value: 'saldo' },
];

watch(() => props.visible, (newVal) => {
    if (newVal && props.payment) {
        editForm.clearErrors();
        editForm.amount = Math.abs(parseFloat(props.payment.amount));
        editForm.payment_method = typeof props.payment.payment_method === 'object' && props.payment.payment_method !== null 
            ? props.payment.payment_method.value 
            : props.payment.payment_method;
        editForm.bank_account_id = props.payment.bank_account_id;
        editForm.notes = props.payment.notes || '';
    }
});

const submit = () => {
    if (!props.payment) return;
    
    editForm.put(route('transactions.updatePayment', { 
        transaction: props.transactionId, 
        payment: props.payment.id 
    }), {
        preserveScroll: true,
        onSuccess: () => {
            isVisible.value = false;
            emit('success');
        }
    });
};

const confirmDelete = () => {
    confirm.require({
        message: '¿Estás seguro de que quieres eliminar este pago permanentemente?',
        header: 'Eliminar Pago',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('transactions.destroyPayment', { transaction: props.transactionId, payment: props.payment.id }), {
                preserveScroll: true,
                onSuccess: () => {
                    isVisible.value = false;
                    emit('success');
                }
            });
        }
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
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' } // Fondo sólido oscuro
};

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors w-full' },
    label: { class: '!text-sm !py-2.5' },
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm w-full font-mono' }
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm w-full' }
};
</script>

<template>
    <Dialog v-model:visible="isVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
        
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-pencil !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Editar pago</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Ajuste de transacción
                    </p>
                </div>
            </div>
        </template>

        <div class="flex flex-col gap-5 pt-2">
            
            <div class="flex flex-col">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Monto del pago *</label>
                <InputNumber v-model="editForm.amount" mode="currency" currency="MXN" locale="es-MX" :min="0.01" autofocus :pt="{ input: inputPt }" />
                <span v-if="editForm.errors.amount" class="text-xs text-red-500 font-medium mt-1.5">{{ editForm.errors.amount }}</span>
            </div>
            
            <div class="flex flex-col">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Método de pago *</label>
                <Select v-model="editForm.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" placeholder="Seleccione método" :pt="selectPt" />
                <span v-if="editForm.errors.payment_method" class="text-xs text-red-500 font-medium mt-1.5">{{ editForm.errors.payment_method }}</span>
            </div>
            
            <div v-if="editForm.payment_method !== 'efectivo' && editForm.payment_method !== 'saldo'" class="flex flex-col">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Cuenta bancaria de destino</label>
                <Select 
                    v-model="editForm.bank_account_id" 
                    :options="bankAccounts" 
                    optionLabel="bank_name" 
                    optionValue="id" 
                    placeholder="Seleccione cuenta"  
                    :pt="selectPt"
                >
                    <template #option="slotProps">
                        <div class="flex flex-col py-0.5">
                            <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0 leading-tight">{{ slotProps.option.bank_name }}</span>
                            <span class="text-[10px] uppercase tracking-widest text-gray-500 mt-0.5">{{ slotProps.option.owner_name }} ({{ slotProps.option.account_name }})</span>
                        </div>
                    </template>
                </Select>
                <span v-if="editForm.errors.bank_account_id" class="text-xs text-red-500 font-medium mt-1.5">{{ editForm.errors.bank_account_id }}</span>
            </div>
            
            <div class="flex flex-col">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">Notas internas / Referencia</label>
                <Textarea v-model="editForm.notes" rows="3" placeholder="Ej. Folio de transferencia, terminal usada, etc." :pt="textareaPt" />
                <span v-if="editForm.errors.notes" class="text-xs text-red-500 font-medium mt-1.5">{{ editForm.errors.notes }}</span>
            </div>

        </div>

        <template #footer>
            <div class="flex justify-between items-center w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <!-- Botón de eliminar en el extremo izquierdo -->
                <Button v-if="payment" icon="pi pi-trash" severity="danger" text @click="confirmDelete" v-tooltip.top="'Eliminar pago'" class="!rounded-full !w-10 !h-10 !p-0" />
                <div v-else></div> <!-- Placeholder para alinear a la derecha -->
                
                <div class="flex justify-end items-center gap-3">
                    <Button label="Cancelar" text @click="isVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                    <Button label="Guardar cambios" @click="submit" :loading="editForm.processing" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" />
                </div>
            </div>
        </template>
    </Dialog>
</template>