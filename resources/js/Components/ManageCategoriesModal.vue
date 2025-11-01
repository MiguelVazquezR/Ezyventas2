<script setup>
import { ref, watch, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import axios from 'axios';
import InputLabel from './InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    categoryType: {
        type: String,
        required: true,
    },
});

// AÑADIDO: 'deleted' y 'updated'
const emit = defineEmits(['update:visible', 'created', 'deleted', 'updated']);

const toast = useToast();
const confirm = useConfirm();
const categories = ref([]);
const loading = ref(false);
const editingRows = ref([]); // Para la edición en tabla de PrimeVue
const newCategoryName = ref('');
const newCategoryProcessing = ref(false);
const firstInputRef = ref(null); // Referencia para el input de nueva categoría

/**
 * Carga las categorías desde el backend.
 */
const fetchCategories = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('categories.index'), {
            params: { type: props.categoryType }
        });
        categories.value = response.data;
    } catch (error) {
        console.error("Error al cargar categorías:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar las categorías.', life: 6000 });
    } finally {
        loading.value = false;
    }
};

/**
 * Cierra el modal principal.
 */
const closeModal = () => {
    emit('update:visible', false);
};

/**
 * Maneja el evento de guardar en la edición de la tabla.
 */
const onRowEditSave = async (event) => {
    let { newData, index } = event;

    if (!newData.name || newData.name.trim() === '') {
        toast.add({ severity: 'warn', summary: 'Campo requerido', detail: 'El nombre no puede estar vacío.', life: 6000 });
        categories.value[index] = event.data;
        return;
    }

    try {
        await axios.put(route('categories.update', newData.id), {
            name: newData.name,
            type: props.categoryType,
        });
        categories.value[index] = newData;
        toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Categoría actualizada con éxito.', life: 6000 });
        
        // --- AÑADIDO ---
        emit('updated', newData); // Avisar al padre que se actualizó
        // --- FIN AÑADIDO ---

    } catch (error) {
        console.error("Error al actualizar:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar la categoría.', life: 6000 });
        categories.value[index] = event.data;
    }
};

/**
 * Pide confirmación antes de eliminar una categoría.
 */
const confirmDelete = (category) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la categoría "${category.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Eliminar',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-danger',
        accept: () => {
            deleteCategory(category);
        }
    });
};

/**
 * Elimina la categoría.
 */
const deleteCategory = async (category) => {
    try {
        await axios.delete(route('categories.destroy', category.id));
        categories.value = categories.value.filter(c => c.id !== category.id);
        toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Categoría eliminada con éxito.', life: 6000 });

        // --- AÑADIDO ---
        emit('deleted', category.id); // Avisar al padre que se eliminó (enviar solo el ID)
        // --- FIN AÑADIDO ---

    } catch (error) {
        console.error("Error al eliminar:", error);
        let detail = 'No se pudo eliminar la categoría.';
        if (error.response && error.response.status === 422) {
            console.log(error)
            detail = error.response.data.message; // Mensaje de error del controlador
        }
        toast.add({ severity: 'error', summary: 'Error', detail: detail, life: 6000 });
    }
};

/**
 * Crea una nueva categoría.
 */
const submitNewCategory = async () => {
    if (!newCategoryName.value || newCategoryName.value.trim() === '') {
        return;
    }
    newCategoryProcessing.value = true;
    try {
        const response = await axios.post(route('quick-create.categories.store'), {
            name: newCategoryName.value,
            type: props.categoryType
        });
        const newCategory = response.data;
        categories.value.push(newCategory); // Añadir a la lista actual
        emit('created', newCategory); // Emitir al componente padre
        newCategoryName.value = ''; // Limpiar input
        toast.add({ severity: 'success', summary: 'Creada', detail: 'Categoría creada con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al crear:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo crear la categoría.', life: 6000 });
    } finally {
        newCategoryProcessing.value = false;
    }
};

// Cargar categorías cuando el modal se hace visible
watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchCategories();
        // Enfocar el input de "nueva categoría"
        nextTick(() => {
            if (firstInputRef.value && firstInputRef.value.$el) {
                firstInputRef.value.$el.focus();
            }
        });
    }
}, { immediate: true });

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Gestionar categorías"
        :style="{ width: '30rem' }">

        <!-- Formulario para crear nueva categoría -->
        <form @submit.prevent="submitNewCategory" class="p-2">
            <div class="flex flex-col gap-2">
                <InputLabel for="new-category-name" value="Crear nueva categoría" />
                <div class="flex items-center space-x-2">
                    <InputText id="new-category-name" ref="firstInputRef" v-model="newCategoryName"
                        placeholder="Nombre de la categoría" class="w-3/4" />
                    <Button type="submit" icon="pi pi-plus" :loading="newCategoryProcessing"
                        :disabled="!newCategoryName || newCategoryProcessing" />
                </div>
            </div>
        </form>

        <Divider />

        <!-- Lista de categorías existentes -->
        <div :style="{ 'max-height': '300px', 'overflow-y': 'auto' }">
            <DataTable :value="categories" v-model:editingRows="editingRows" editMode="row" dataKey="id"
                @row-edit-save="onRowEditSave" :loading="loading" size="small" stripedRows responsiveLayout="scroll">
                <template #loading>
                    <div class="flex justify-center p-4">
                        <ProgressSpinner style="width: 50px; height: 50px" strokeWidth="8" />
                    </div>
                </template>
                <template #empty>
                    <div class="text-center p-4 text-gray-500">
                        No se encontraron categorías para "{{ categoryType }}".
                    </div>
                </template>

                <Column field="name" header="Nombre">
                    <template #body="{ data }">
                        {{ data.name }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" autofocus />
                    </template>
                </Column>
                <Column :rowEditor="true" style="width: 10%; min-width: 6rem" bodyStyle="text-align:center"></Column>
                <Column style="width: 10%; min-width: 4rem" bodyStyle="text-align:center">
                    <template #body="{ data }">
                        <Button @click="confirmDelete(data)" icon="pi pi-trash" severity="danger" text rounded
                            size="small" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <template #footer>
            <Button label="Cerrar" severity="secondary" @click="closeModal" />
        </template>
    </Dialog>
</template>