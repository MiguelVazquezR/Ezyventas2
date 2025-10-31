<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import InputLabel from './InputLabel.vue';
import ManageCategoriesModal from './ManageCategoriesModal.vue'; // <-- AÑADIDO

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
const showManageModal = ref(false); // <-- AÑADIDO

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

// <!-- AÑADIDO: Escuchar cuando el modal de gestión se cierra -->
const onManageModalClosed = (newItem) => {
    if (newItem) {
        // Si el modal de gestión emitió un 'created', pasarlo al componente padre
        emit('created', newItem);
    }
};

</script>

<template>
    <!-- Modal Principal: Crear Categoría -->
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="title" :style="{ width: '25rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div class="flex flex-col gap-2">
                <InputLabel for="category-name" value="Nombre de la Categoría" />
                <InputText id="category-name" v-model="form.name" v-focustrap />
            </div>

            <!-- AÑADIDO: Enlace para gestionar -->
            <div class="text-right mt-2">
                <Button @click="showManageModal = true" label="Gestionar categorías" text severity="secondary" size="small"
                    class="p-0 text-sm" />
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Guardar" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>

    <!-- AÑADIDO: Modal Secundario: Gestionar Categorías -->
    <ManageCategoriesModal 
        v-if="showManageModal" 
        :visible="showManageModal"
        @update:visible="showManageModal = $event" 
        :categoryType="type"
        @created="onManageModalClosed" 
    />
</template>