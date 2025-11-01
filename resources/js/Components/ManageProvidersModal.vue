<script setup>
import { ref, watch, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue'; // Asegúrate que la ruta sea correcta

// --- Definición de Props y Emits ---
const props = defineProps({
    visible: Boolean,
});
const emit = defineEmits(['update:visible', 'created', 'updated', 'deleted']);

// --- Inicialización de servicios ---
const toast = useToast();
const confirm = useConfirm();

// --- Estado del componente ---
const providers = ref([]);
const loading = ref(false);
const editingRows = ref([]); // Para la edición en tabla
const firstInputRef = ref(null); // Referencia para el input de nuevo proveedor

// --- Estado para el formulario de nuevo proveedor ---
const newProviderForm = ref({
    name: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
});
const newProviderProcessing = ref(false);

/**
 * Limpia el formulario de nuevo proveedor.
 */
const resetNewProviderForm = () => {
    newProviderForm.value = {
        name: '',
        contact_name: '',
        contact_email: '',
        contact_phone: '',
    };
};

/**
 * Carga los proveedores desde el backend.
 */
const fetchProviders = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('providers.index'));
        providers.value = response.data;
    } catch (error) {
        console.error("Error al cargar proveedores:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar los proveedores.', life: 6000 });
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
 * Maneja el evento de guardar en la edición de la tabla (inline).
 */
const onRowEditSave = async (event) => {
    let { newData, index } = event;

    // Validación simple
    if (!newData.name || newData.name.trim() === '') {
        toast.add({ severity: 'warn', summary: 'Campo requerido', detail: 'El nombre no puede estar vacío.', life: 6000 });
        categories.value[index] = event.data; // Revertir
        return;
    }

    try {
        // Llama a la ruta de actualización
        const response = await axios.put(route('providers.update', newData.id), newData);
        
        // Actualiza el estado local
        providers.value[index] = response.data;
        
        // Emite el evento al padre
        emit('updated', response.data);
        
        toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Proveedor actualizado con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al actualizar:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar el proveedor.', life: 6000 });
        // Revertir en caso de error
        providers.value[index] = event.data;
    }
};

/**
 * Pide confirmación antes de eliminar un proveedor.
 */
const confirmDelete = (provider) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar al proveedor "${provider.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Eliminar',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-danger',
        accept: () => {
            deleteProvider(provider);
        }
    });
};

/**
 * Elimina el proveedor.
 */
const deleteProvider = async (provider) => {
    try {
        // Llama a la ruta de eliminación
        await axios.delete(route('providers.destroy', provider.id));
        
        // Actualiza el estado local
        providers.value = providers.value.filter(c => c.id !== provider.id);
        
        // Emite el evento al padre
        emit('deleted', provider.id);
        
        toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Proveedor eliminado con éxito.', life: 6000 });
    } catch (error) {
        console.error("Error al eliminar:", error);
        let detail = 'No se pudo eliminar el proveedor.';
        // Captura el mensaje de error del backend (si está en uso)
        if (error.response && error.response.status === 422) {
            detail = error.response.data.message;
        }
        toast.add({ severity: 'error', summary: 'Error', detail: detail, life: 6000 });
    }
};

/**
 * Crea un nuevo proveedor.
 */
const submitNewProvider = async () => {
    if (!newProviderForm.value.name || newProviderForm.value.name.trim() === '') {
        toast.add({ severity: 'warn', summary: 'Campo requerido', detail: 'El nombre es obligatorio.', life: 6000 });
        return;
    }
    newProviderProcessing.value = true;
    try {
        // Llama a la ruta de creación rápida
        const response = await axios.post(route('quick-create.providers.store'), newProviderForm.value);
        
        const newProvider = response.data;
        providers.value.push(newProvider); // Añadir a la lista actual
        
        // Emite el evento al padre
        emit('created', newProvider); 
        
        resetNewProviderForm(); // Limpiar inputs
        toast.add({ severity: 'success', summary: 'Creado', detail: 'Proveedor creado con éxito.', life: 6000 });
        
        // Enfocar el primer input de nuevo
        nextTick(() => {
            if (firstInputRef.value?.$el) {
                firstInputRef.value.$el.focus();
            }
        });

    } catch (error) {
        console.error("Error al crear:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo crear el proveedor.', life: 6000 });
    } finally {
        newProviderProcessing.value = false;
    }
};

// Cargar proveedores cuando el modal se hace visible
watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchProviders();
        // Enfocar el input de "nuevo proveedor"
        nextTick(() => {
            if (firstInputRef.value?.$el) {
                firstInputRef.value.$el.focus();
            }
        });
    }
}, { immediate: true });

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Gestionar proveedores"
        :style="{ width: '50rem' }"> <!-- Ancho ajustado para más campos -->

        <!-- Formulario para crear nuevo proveedor -->
        <form @submit.prevent="submitNewProvider" class="p-2 mb-4">
            <h6 class="font-semibold mb-3">Crear nuevo proveedor</h6>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-2">
                    <InputLabel for="new-provider-name" value="Nombre del proveedor *" />
                    <InputText id="new-provider-name" ref="firstInputRef" v-model="newProviderForm.name"
                        placeholder="Nombre..." />
                </div>
                <div class="flex flex-col gap-2">
                    <InputLabel for="new-provider-contact-name" value="Nombre de contacto" />
                    <InputText id="new-provider-contact-name" v-model="newProviderForm.contact_name"
                        placeholder="Juan Pérez..." />
                </div>
                <div class="flex flex-col gap-2">
                    <InputLabel for="new-provider-contact-email" value="Email de contacto" />
                    <InputText id="new-provider-contact-email" v-model="newProviderForm.contact_email"
                        placeholder="correo@ejemplo.com" />
                </div>
                <div class="flex flex-col gap-2">
                    <InputLabel for="new-provider-contact-phone" value="Teléfono de contacto" />
                    <InputText id="new-provider-contact-phone" v-model="newProviderForm.contact_phone"
                        placeholder="33 1234 5678..." />
                </div>
            </div>
            <div class="flex justify-end mt-4">
                <Button type="submit" label="Crear proveedor" icon="pi pi-plus" :loading="newProviderProcessing"
                    :disabled="!newProviderForm.name || newProviderProcessing" />
            </div>
        </form>

        <Divider />

        <!-- Lista de proveedores existentes -->
        <div class_="" :style="{ 'max-height': '300px', 'overflow-y': 'auto' }">
            <DataTable :value="providers" v-model:editingRows="editingRows" editMode="row" dataKey="id"
                @row-edit-save="onRowEditSave" :loading="loading" size="small" stripedRows responsiveLayout="scroll">
                
                <template #loading>
                    <div class="flex justify-center p-4">
                        <ProgressSpinner style="width: 50px; height: 50px" strokeWidth="8" />
                    </div>
                </template>
                <template #empty>
                    <div class="text-center p-4 text-gray-500">
                        No se encontraron proveedores.
                    </div>
                </template>

                <!-- Columna: Nombre -->
                <Column field="name" header="Nombre" style="width: 25%">
                    <template #body="{ data }">
                        {{ data.name }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" autofocus />
                    </template>
                </Column>

                <!-- Columna: Contacto -->
                <Column field="contact_name" header="Contacto" style="width: 25%">
                    <template #body="{ data }">
                        {{ data.contact_name }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" />
                    </template>
                </Column>
                
                <!-- Columna: Email -->
                <Column field="contact_email" header="Email" style="width: 25%">
                    <template #body="{ data }">
                        {{ data.contact_email }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" />
                    </template>
                </Column>

                <!-- Columna: Teléfono -->
                <Column field="contact_phone" header="Teléfono" style="width: 20%">
                    <template #body="{ data }">
                        {{ data.contact_phone }}
                    </template>
                    <template #editor="{ data, field }">
                        <InputText v-model="data[field]" />
                    </template>
                </Column>

                <!-- Columna: Editar/Borrar -->
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