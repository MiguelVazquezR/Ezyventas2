<script setup>
import { ref } from 'vue';
import axios from 'axios';
import InputLabel from './InputLabel.vue';

defineProps({
    visible: Boolean,
});
const emit = defineEmits(['update:visible', 'created']);

const form = ref({
    name: '',
    phone: ''
});
const processing = ref(false);

const closeModal = () => {
    emit('update:visible', false);
    form.value.name = '';
    form.value.phone = '';
};

const submit = async () => {
    if (!form.value.name) return;
    processing.value = true;
    try {
        const response = await axios.post(route('quick-create.customers.store'), form.value);
        emit('created', response.data);
        closeModal();
    } catch (error) {
        console.error("Error al crear cliente:", error);
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Crear Nuevo Cliente" :style="{ width: '25rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="customer-name" value="Nombre del Cliente *" />
                <InputText id="customer-name" v-model="form.name" class="w-full mt-1" />
            </div>
            <div>
                <InputLabel for="customer-phone" value="Teléfono" />
                <InputText id="customer-phone" v-model="form.phone" class="w-full mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Guardar" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>
</template>