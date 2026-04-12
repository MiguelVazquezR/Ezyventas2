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
        newDate.value = props.currentDate ? new Date(props.currentDate) : new Date();
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
    router.put(route('transactions.update-date', props.transactionId), {
        created_at: toLocalISOString(newDate.value)
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
    <Dialog v-model:visible="isVisible" modal header="Editar fecha de venta" :style="{ width: '25rem' }">
        <div class="flex flex-col gap-4 py-2">
            <Message severity="warn" :closable="false">
                Cambiar la fecha afectará los reportes y cortes de caja de ese día.
            </Message>
            <div class="flex flex-col gap-2">
                <label class="font-bold text-gray-700 dark:text-gray-300">Nueva fecha y hora</label>
                <DatePicker v-model="newDate" showTime hourFormat="12" dateFormat="dd/mm/yy" showIcon class="w-full" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button label="Cancelar" severity="secondary" text @click="isVisible = false" />
                <Button label="Actualizar" icon="pi pi-save" @click="submit" :loading="isProcessing" />
            </div>
        </template>
    </Dialog>
</template>