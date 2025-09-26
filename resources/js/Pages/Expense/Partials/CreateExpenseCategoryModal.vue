<script setup>
import { ref } from 'vue';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue';

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
        const response = await axios.post(route('quick-create.expense_categories.store'), { name: name.value });
        emit('created', response.data);
        closeModal();
    } catch (error) {
        console.error("Error al crear categoría de gasto:", error);
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Crear Categoría de Gasto" :style="{ width: '25rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div class="flex flex-col gap-2">
                <InputLabel for="category-name" value="Nombre de la Categoría" />
                <InputText id="category-name" v-model="name" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Guardar" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>
</template>

