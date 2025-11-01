<script setup>
import { ref, watch, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import axios from 'axios';
import InputLabel from './InputLabel.vue';

// --- PROPS Y EMITS ---

// visible: Controla si el modal se muestra o no (conectado con v-model:visible)
// categoryType: Lo dejamos por compatibilidad, aunque para gastos no se usa,
// pero el controlador de creación rápida (QuickCreate) podría necesitarlo.
const props = defineProps({
    visible: Boolean,
});

// Eventos que el modal emite al componente padre:
// update:visible: Para que el v-model funcione.
// created: Cuando se crea una nueva categoría.
// updated: Cuando se edita una categoría.
// deleted: Cuando se elimina una categoría.
const emit = defineEmits(['update:visible', 'created', 'updated', 'deleted']);

// --- REFERENCIAS Y SERVICIOS ---
const toast = useToast(); // Para mostrar notificaciones
const confirm = useConfirm(); // Para el diálogo de confirmación de borrado
const categories = ref([]); // La lista de categorías que se muestra en la tabla
const loading = ref(false); // Estado de carga para la tabla
const editingRows = ref([]); // Controla qué filas están en modo de edición
const newCategoryName = ref(''); // v-model para el input de "Nombre" de la nueva categoría
const newCategoryDescription = ref(''); // v-model para el input de "Descripción"
const newCategoryProcessing = ref(false); // Estado de carga para el botón de crear
const firstInputRef = ref(null); // Referencia al input "Nombre" para hacer focus

// --- FUNCIONES ---

/**
 * Carga la lista de categorías de gastos desde el backend.
 * Se llama cuando el modal se hace visible.
 */
const fetchCategories = async () => {
    loading.value = true;
    try {
        // Llama a la ruta 'expense-categories.index' que definimos en PHP
        const response = await axios.get(route('expense-categories.index'));
        categories.value = response.data;
    } catch (error) {
        console.error("Error al cargar categorías de gastos:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar las categorías.', life: 6000 });
    } finally {
        loading.value = false;
    }
};

/**
 * Cierra el modal.
 * Emite el evento 'update:visible' para que el v-model en el padre se actualice.
 */
const closeModal = () => {
    emit('update:visible', false);
};

/**
 * Se activa al guardar una fila editada en la tabla.
 */
const onRowEditSave = async (event) => {
    let { newData, index, data: oldData } = event; // newData tiene los cambios

    // Validación simple: el nombre no puede estar vacío
    if (!newData.name || newData.name.trim() === '') {
        toast.add({ severity: 'warn', summary: 'Campo requerido', detail: 'El nombre no puede estar vacío.', life: 6000 });
        categories.value[index] = oldData; // Revertir los cambios visualmente
        return;
    }

    try {
        // Llama a la ruta 'expense-categories.update'
        await axios.put(route('expense-categories.update', newData.id), {
            name: newData.name,
            description: newData.description // Enviamos también la descripción
        });
        
        // Actualiza el dato en la lista local
        categories.value[index] = newData;
        
        // Emite el evento 'updated' al componente padre con la categoría actualizada
        emit('updated', newData);
        
        toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Categoría actualizada.', life: 6000 });
    } catch (error) {
        console.error("Error al actualizar:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar la categoría.', life: 6000 });
        categories.value[index] = oldData; // Revertir si hay error
    }
};

/**
 * Muestra el diálogo de confirmación antes de eliminar.
 */
const confirmDelete = (category) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la categoría "${category.name}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Eliminar',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-danger',
        accept: () => {
            // Si el usuario acepta, llama a la función deleteCategory
            deleteCategory(category);
        }
    });
};

/**
 * Llama a la API para eliminar la categoría.
 */
const deleteCategory = async (category) => {
    try {
        // Llama a la ruta 'expense-categories.destroy'
        await axios.delete(route('expense-categories.destroy', category.id));
        
        // Elimina la categoría de la lista local
        categories.value = categories.value.filter(c => c.id !== category.id);
        
        // Emite el evento 'deleted' al padre con el ID de la categoría eliminada
        emit('deleted', category.id);
        
        toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Categoría eliminada.', life: 6000 });
    } catch (error) {
        console.error("Error al eliminar:", error);
        let detail = 'No se pudo eliminar la categoría.';
        // Si el backend devuelve un error 422 (porque está en uso), muestra ese mensaje
        if (error.response && error.response.status === 422) {
            detail = error.response.data.message; 
        }
        toast.add({ severity: 'error', summary: 'Error', detail: detail, life: 6000 });
    }
};

/**
 * Envía el formulario para crear una nueva categoría.
 */
const submitNewCategory = async () => {
    if (!newCategoryName.value || newCategoryName.value.trim() === '') {
        return;
    }
    newCategoryProcessing.value = true;
    try {
        // Llama a la ruta 'quick-create.expense_categories.store'
        // NOTA: El QuickCreateController solo acepta 'name'. 
        // Si queremos enviar 'description' también, hay que actualizar ese controlador.
        const response = await axios.post(route('quick-create.expense_categories.store'), {
            name: newCategoryName.value,
            description: newCategoryDescription.value // Enviamos la descripción
        });
        
        const newCategory = response.data;
        categories.value.push(newCategory); // Añadir a la lista local
        
        // Emite el evento 'created' al padre con la nueva categoría
        emit('created', newCategory); 
        
        // Limpiar inputs
        newCategoryName.value = ''; 
        newCategoryDescription.value = '';
        
        toast.add({ severity: 'success', summary: 'Creada', detail: 'Categoría creada con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al crear:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo crear la categoría.', life: 6000 });
    } finally {
        newCategoryProcessing.value = false;
    }
};

// --- WATCHER ---

/**
 * Observa la prop 'visible'. Cuando cambia a 'true' (o sea, se abre el modal),
 * carga las categorías y hace focus en el primer input.
 */
watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchCategories(); // Carga los datos frescos
        
        // Limpia los campos del formulario de creación
        newCategoryName.value = '';
        newCategoryDescription.value = '';
        
        // Espera a que el DOM se actualice (nextTick) y luego hace focus
        nextTick(() => {
            if (firstInputRef.value) {
                // Usamos .$el.focus() porque firstInputRef es un componente de PrimeVue,
                // no un <input> HTML directo.
                firstInputRef.value?.$el?.focus();
            }
        });
    }
}, { immediate: true }); // immediate: true hace que se ejecute una vez al cargar

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Gestionar categorías de gastos"
        :style="{ width: '35rem' }"> <!-- Un poco más ancho para la descripción -->

        <!-- Formulario para crear nueva categoría -->
        <form @submit.prevent="submitNewCategory" class="p-2">
            <div class="flex flex-col gap-3">
                <InputLabel for="new-category-name" value="Crear nueva categoría" />
                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-2">
                    <!-- Input de Nombre -->
                    <div class="flex-1 w-full sm:w-auto">
                        <InputText id="new-category-name" ref="firstInputRef" v-model="newCategoryName"
                            placeholder="Nombre de la categoría" class="w-full" />
                    </div>
                    <!-- Input de Descripción -->
                    <div class="flex-1 w-full sm:w-auto">
                        <InputText id="new-category-desc" v-model="newCategoryDescription"
                            placeholder="Descripción (opcional)" class="w-full" />
                    </div>
                    <!-- Botón de Crear -->
                    <Button type="submit" icon="pi pi-plus" :loading="newCategoryProcessing"
                        :disabled="!newCategoryName || newCategoryProcessing" 
                        class="w-full sm:w-auto"
                        />
                </div>
            </div>
        </form>

        <Divider />

        <!-- Lista de categorías existentes -->
        <div class_="" :style="{ 'max-height': '300px', 'overflow-y': 'auto' }">
            <DataTable :value="categories" v-model:editingRows="editingRows" editMode="row" dataKey="id"
                @row-edit-save="onRowEditSave" :loading="loading" size="small" stripedRows responsiveLayout="scroll">
                
                <!-- Estado de Carga -->
                <template #loading>
                    <div class="flex justify-center p-4">
                        <ProgressSpinner style="width: 50px; height: 50px" strokeWidth="8" />
                    </div>
                </template>
                <!-- Mensaje cuando no hay datos -->
                <template #empty>
                    <div class="text-center p-4 text-gray-500">
                        No se encontraron categorías de gastos.
                    </div>
                </template>

                <!-- Columna de Nombre (editable) -->
                <Column field="name" header="Nombre" style="width: 35%">
                    <template #body="{ data }">
                        {{ data.name }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" autofocus class="w-full" />
                    </template>
                </Column>

                <!-- Columna de Descripción (editable) -->
                <Column field="description" header="Descripción" style="width: 45%">
                    <template #body="{ data }">
                        {{ data.description }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" class="w-full" />
                    </template>
                </Column>

                <!-- Columna de Botones de Edición -->
                <Column :rowEditor="true" style="width: 10%; min-width: 8rem" bodyStyle="text-align:center"></Column>
                
                <!-- Columna de Botón de Eliminar -->
                <Column style="width: 10%; min-width: 4rem" bodyStyle="text-align:center">
                    <template #body="{ data }">
                        <Button @click="confirmDelete(data)" icon="pi pi-trash" severity="danger" text rounded
                            size="small" vD-tooltip.left="'Eliminar'" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Footer con botón de Cerrar -->
        <template #footer>
            <Button label="Cerrar" severity="secondary" @click="closeModal" />
        </template>
    </Dialog>
</template>