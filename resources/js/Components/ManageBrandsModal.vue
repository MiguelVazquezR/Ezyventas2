<script setup>
import { ref, watch, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue'; // Asegúrate de que la ruta sea correcta

// --- Definición de Props y Emits ---
const props = defineProps({
    visible: Boolean,
});

// 'update:visible' es para el v-model:visible
// 'created', 'updated', 'deleted' son para notificar al padre de los cambios en los datos
const emit = defineEmits(['update:visible', 'created', 'updated', 'deleted']);

// --- Inicialización de servicios y estado ---
const toast = useToast();
const confirm = useConfirm();
const brands = ref([]); // Lista de marcas cargadas
const loading = ref(false); // Estado de carga para la tabla
const editingRows = ref([]); // Para la edición en tabla de PrimeVue
const newBrandName = ref(''); // Para el input de nueva marca
const newBrandProcessing = ref(false); // Estado de carga del botón de crear
const firstInputRef = ref(null); // Referencia para el input de nueva marca

/**
 * Carga las marcas desde el backend.
 * Se llama cuando el modal se hace visible.
 */
const fetchBrands = async () => {
    loading.value = true;
    try {
        // Usa la nueva ruta 'brands.index'
        const response = await axios.get(route('brands.index'));
        brands.value = response.data;
    } catch (error) {
        console.error("Error al cargar marcas:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar las marcas.', life: 6000 });
    } finally {
        loading.value = false;
    }
};

/**
 * Cierra el modal principal.
 * Emite el evento 'update:visible' para que el v-model en el padre funcione.
 */
const closeModal = () => {
    emit('update:visible', false);
};

/**
 * Maneja el evento de guardar en la edición de la tabla.
 * Se llama cuando el usuario guarda un cambio en una fila.
 */
const onRowEditSave = async (event) => {
    let { newData, index, data: oldData } = event;

    // Validación simple para que no esté vacío
    if (!newData.name || newData.name.trim() === '') {
        toast.add({ severity: 'warn', summary: 'Campo requerido', detail: 'El nombre no puede estar vacío.', life: 6000 });
        brands.value[index] = oldData; // Revierte el cambio visualmente
        return;
    }

    try {
        // Usa la ruta 'brands.update'
        const response = await axios.put(route('brands.update', newData.id), {
            name: newData.name,
        });
        
        // Actualiza la lista local con los datos del servidor (por si hay alguna transformación)
        brands.value[index] = response.data; 
        
        // Emite el evento 'updated' para notificar al padre
        emit('updated', response.data); 
        
        toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Marca actualizada con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al actualizar:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar la marca.', life: 6000 });
        brands.value[index] = oldData; // Revierte el cambio en caso de error
    }
};

/**
 * Pide confirmación antes de eliminar una marca.
 */
const confirmDelete = (brand) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la marca "${brand.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Eliminar',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-danger',
        accept: () => {
            deleteBrand(brand); // Llama a la función de borrado si acepta
        }
    });
};

/**
 * Elimina la marca (se llama después de la confirmación).
 */
const deleteBrand = async (brand) => {
    try {
        // Usa la ruta 'brands.destroy'
        await axios.delete(route('brands.destroy', brand.id));
        
        // Filtra la marca de la lista local
        brands.value = brands.value.filter(c => c.id !== brand.id);
        
        // Emite el evento 'deleted' para notificar al padre
        emit('deleted', brand.id); 
        
        toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Marca eliminada con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al eliminar:", error);
        let detail = 'No se pudo eliminar la marca.';
        // Lee el mensaje de error específico del controlador (si la marca está en uso)
        if (error.response && error.response.status === 422) {
            detail = error.response.data.message;
        }
        toast.add({ severity: 'error', summary: 'Error', detail: detail, life: 6000 });
    }
};

/**
 * Crea una nueva marca.
 * Se llama desde el formulario en la parte superior del modal.
 */
const submitNewBrand = async () => {
    if (!newBrandName.value || newBrandName.value.trim() === '') {
        return;
    }
    newBrandProcessing.value = true;
    try {
        // Usa la ruta de 'quick-create' que ya tenías
        const response = await axios.post(route('quick-create.brands.store'), {
            name: newBrandName.value,
        });
        
        const newBrand = response.data;
        brands.value.push(newBrand); // Añadir a la lista local
        
        // Emite el evento 'created' para notificar al padre
        emit('created', newBrand); 
        
        newBrandName.value = ''; // Limpiar input
        toast.add({ severity: 'success', summary: 'Creada', detail: 'Marca creada con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al crear:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo crear la marca.', life: 6000 });
    } finally {
        newBrandProcessing.value = false;
    }
};

// --- Observador (Watcher) ---
/**
 * Observa la prop 'visible'.
 * Cuando 'visible' cambia a 'true', carga las marcas y enfoca el input.
 */
watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchBrands(); // Carga los datos
        // Usa nextTick para asegurar que el DOM esté listo
        nextTick(() => {
            // Se usa .$el.focus() porque 'firstInputRef' es el componente de PrimeVue,
            // no el elemento <input> HTML directamente.
            if (firstInputRef.value && firstInputRef.value.$el) {
                firstInputRef.value.$el.focus();
            }
        });
    }
}, { immediate: true }); // 'immediate: true' es útil si el modal es visible al cargar la página

</script>

<template>
    <!-- El `v-model:visible` funciona gracias a la prop `visible` y al emit `update:visible` -->
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Gestionar marcas"
        :style="{ width: '30rem' }">

        <!-- Formulario para crear nueva marca -->
        <form @submit.prevent="submitNewBrand" class="p-2">
            <div class="flex flex-col gap-2">
                <InputLabel for="new-brand-name" value="Crear nueva marca" />
                <div class="flex items-center space-x-2">
                    <InputText id="new-brand-name" ref="firstInputRef" v-model="newBrandName"
                        placeholder="Nombre de la marca" class="w-2/3" />
                    <Button type="submit" icon="pi pi-plus" :loading="newBrandProcessing"
                        :disabled="!newBrandName || newBrandProcessing" />
                </div>
            </div>
        </form>

        <Divider />

        <!-- Lista de marcas existentes -->
        <div class_="" :style="{ 'max-height': '300px', 'overflow-y': 'auto' }">
            <DataTable :value="brands" v-model:editingRows="editingRows" editMode="row" dataKey="id"
                @row-edit-save="onRowEditSave" :loading="loading" size="small" stripedRows responsiveLayout="scroll">
                
                <!-- Plantillas para Carga y Vacío -->
                <template #loading>
                    <div class="flex justify-center p-4">
                        <ProgressSpinner style="width: 50px; height: 50px" strokeWidth="8" />
                    </div>
                </template>
                <template #empty>
                    <div class="text-center p-4 text-gray-500">
                        No se encontraron marcas.
                    </div>
                </template>

                <!-- Columna de Nombre (Editable) -->
                <Column field="name" header="Nombre">
                    <template #body="{ data }">
                        {{ data.name }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" autofocus />
                    </template>
                </Column>

                <!-- Columna de Edición (Lápiz/Check) -->
                <Column :rowEditor="true" style="width: 10%; min-width: 6rem" bodyStyle="text-align:center"></Column>
                
                <!-- Columna de Borrado (Basurero) -->
                <Column style="width: 10%; min-width: 4rem" bodyStyle="text-align:center">
                    <template #body="{ data }">
                        <Button @click="confirmDelete(data)" icon="pi pi-trash" severity="danger" text rounded
                            size="small" />
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