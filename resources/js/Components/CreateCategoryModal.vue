<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import InputLabel from './InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    type: {
        type: String,
        required: true, // 'product', 'service'
    },
    title: {
        type: String,
        default: 'Crear Nueva Categoría'
    }
});

const emit = defineEmits(['update:visible', 'created']);

const form = ref({
    name: '',
    type: props.type,
});
const processing = ref(false);

watch(() => props.type, (newType) => {
    form.value.type = newType;
});

const closeModal = () => {
    emit('update:visible', false);
    form.value.name = '';
};

const submit = async () => {
    if (!form.value.name) return;
    processing.value = true;
    try {
        const response = await axios.post(route('quick-create.categories.store'), form.value);
        emit('created', response.data);
        closeModal();
    } catch (error) {
        console.error("Error al crear categoría:", error);
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="title" :style="{ width: '25rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div class="flex flex-col gap-2">
                <InputLabel for="category-name" value="Nombre de la Categoría" />
                <InputText id="category-name" v-model="form.name" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Guardar" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>
</template>