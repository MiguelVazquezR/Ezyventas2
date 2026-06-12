<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    transactionId: {
        type: Number,
        required: true
    },
    currentDate: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['update:visible', 'success']);

const isVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value)
});

const newDate = ref(null);
const isProcessing = ref(false);

watch(() => props.visible, (newVal) => {
    if (newVal) {
        newDate.value = props.currentDate ? new Date(props.currentDate) : null;
    }
});

const toLocalISOString = (date) => {
    if (!date) return null;
    const tzOffset = date.getTimezoneOffset() * 60000;
    return (new Date(date - tzOffset)).toISOString().slice(0, 19).replace('T', ' ');
};

const submit = () => {
    if (!newDate.value) return;
    
    isProcessing.value = true;
    router.put(route('transactions.extend-layaway', props.transactionId), {
        new_expiration_date: toLocalISOString(newDate.value)
    }, {
        onSuccess: () => {
            isVisible.value = false;
            emit('success');
        },
        onFinish: () => isProcessing.value = false
    });
};
</script>

<template>
    <Dialog v-model:visible="isVisible" modal header="Extender fecha de vencimiento" :style="{ width: '25rem' }">
        <div class="flex flex-col gap-4 py-2">
            <p class="text-sm text-gray-500">Selecciona la nueva fecha límite para liquidar este saldo.</p>
            <div class="flex flex-col gap-2">
                <label class="font-bold text-gray-700 dark:text-gray-300">Nueva fecha</label>
                <DatePicker v-model="newDate" dateFormat="dd/mm/yy" :minDate="new Date()" showIcon class="w-full" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button label="Cancelar" severity="secondary" text @click="isVisible = false" />
                <Button label="Guardar fecha" icon="pi pi-check" @click="submit" :loading="isProcessing" />
            </div>
        </template>
    </Dialog>
</template>