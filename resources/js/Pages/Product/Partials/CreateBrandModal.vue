<script setup>
import { ref } from 'vue';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import axios from 'axios';

const props = defineProps({
    visible: Boolean,
});
const emit = defineEmits(['update:visible', 'created']);
const name = ref('');
const processing = ref(false);

const closeModal = () => {
    emit('update:visible', false);
    name.value = '';
};
const submit = async () => {
    if (!name.value) return;
    processing.value = true;
    try {
        const response = await axios.post(route('quick-create.brands.store'), { name: name.value });
        emit('created', response.data);
        closeModal();
    } catch (error) {
        console.error("Error creating brand:", error);
    } finally {
        processing.value = false;
    }
};
</script>
<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Crear Nueva Marca" :style="{ width: '25rem' }">
        <form @submit.prevent="submit">
            <div class="flex flex-col gap-2">
                <label for="brand-name" class="font-semibold">Nombre de la Marca</label>
                <InputText id="brand-name" v-model="name" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Guardar" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>
</template>