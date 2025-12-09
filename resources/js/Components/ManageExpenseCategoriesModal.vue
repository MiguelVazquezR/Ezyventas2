<script setup>
import { ref, watch, nextTick, computed } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel.vue'; // Ajusta la ruta si es necesario

const props = defineProps({
    visible: Boolean,
});

const emit = defineEmits(['update:visible', 'created', 'updated', 'deleted']);

// --- ESTADO ---
const toast = useToast();
const confirm = useConfirm();
const categories = ref([]);
const loading = ref(false);
const editingRows = ref([]);
const newCategoryName = ref('');
const newCategoryDescription = ref('');
const newCategoryProcessing = ref(false);
const firstInputRef = ref(null);

// --- ESTADO PARA MIGRACIÓN ---
const migrationDialogVisible = ref(false);
const categoryToDelete = ref(null); // La categoría que intentamos borrar
const targetCategoryId = ref(null); // La categoría destino seleccionada
const isMigrating = ref(false);

// Lista de categorías disponibles para migrar (todas menos la que se borra)
const migrationOptions = computed(() => {
    if (!categoryToDelete.value) return [];
    return categories.value.filter(c => c.id !== categoryToDelete.value.id);
});

// --- CARGA DE DATOS ---
const fetchCategories = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('expense-categories.index'));
        categories.value = response.data;
    } catch (error) {
        console.error("Error al cargar:", error);
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar las categorías.', life: 3000 });
    } finally {
        loading.value = false;
    }
};

const closeModal = () => {
    emit('update:visible', false);
};

// --- EDICIÓN ---
const onRowEditSave = async (event) => {
    let { newData, index, data: oldData } = event;

    if (!newData.name || newData.name.trim() === '') {
        toast.add({ severity: 'warn', summary: 'Requerido', detail: 'El nombre es obligatorio.', life: 3000 });
        categories.value[index] = oldData;
        return;
    }

    try {
        await axios.put(route('expense-categories.update', newData.id), {
            name: newData.name,
            description: newData.description
        });
        categories.value[index] = newData;
        emit('updated', newData);
        toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Categoría actualizada.', life: 3000 });
    } catch (error) {
        categories.value[index] = oldData;
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo actualizar.', life: 3000 });
    }
};

// --- ELIMINACIÓN ---
const confirmDelete = (category) => {
    confirm.require({
        message: `¿Eliminar "${category.name}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => deleteCategory(category)
    });
};

/**
 * Función principal de borrado.
 * Si 'migrateToId' tiene valor, se envía al backend para mover gastos.
 */
const deleteCategory = async (category, migrateToId = null) => {
    try {
        if (migrateToId) isMigrating.value = true;

        // Axios delete acepta data en la config 'data'
        await axios.delete(route('expense-categories.destroy', category.id), {
            data: { migrate_to_id: migrateToId }
        });
        
        // Éxito
        categories.value = categories.value.filter(c => c.id !== category.id);
        emit('deleted', category.id);
        toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Categoría eliminada.', life: 3000 });

        // Resetear estado de migración si estaba activo
        migrationDialogVisible.value = false;
        categoryToDelete.value = null;
        targetCategoryId.value = null;

    } catch (error) {
        // MANEJO DE GASTOS HUÉRFANOS
        if (error.response && error.response.status === 422 && error.response.data.code === 'expenses_exist') {
            // Guardamos la categoría y abrimos el modal de migración
            categoryToDelete.value = category;
            targetCategoryId.value = null; // Resetear selección anterior
            migrationDialogVisible.value = true;
            
            // Opcional: Mostrar aviso
            // toast.add({ severity: 'info', summary: 'Acción requerida', detail: 'Esta categoría tiene gastos. Selecciona dónde moverlos.', life: 4000 });
        } else {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo eliminar la categoría.', life: 3000 });
        }
    } finally {
        isMigrating.value = false;
    }
};

// Acción del botón "Mover y Eliminar" en el modal secundario
const handleMigration = () => {
    if (!targetCategoryId.value) {
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'Selecciona una categoría destino.', life: 3000 });
        return;
    }
    deleteCategory(categoryToDelete.value, targetCategoryId.value);
};

// --- CREACIÓN ---
const submitNewCategory = async () => {
    if (!newCategoryName.value.trim()) return;
    newCategoryProcessing.value = true;
    try {
        const response = await axios.post(route('quick-create.expense_categories.store'), {
            name: newCategoryName.value,
            description: newCategoryDescription.value
        });
        categories.value.push(response.data);
        emit('created', response.data);
        newCategoryName.value = ''; 
        newCategoryDescription.value = '';
        toast.add({ severity: 'success', summary: 'Creada', detail: 'Categoría creada.', life: 3000 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo crear.', life: 3000 });
    } finally {
        newCategoryProcessing.value = false;
    }
};

watch(() => props.visible, (val) => {
    if (val) {
        fetchCategories();
        nextTick(() => firstInputRef.value?.$el?.focus());
    }
}, { immediate: true });
</script>

<template>
    <!-- Modal Principal -->
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Categorías de Gastos" :style="{ width: '35rem' }">
        
        <!-- Formulario Crear -->
        <form @submit.prevent="submitNewCategory" class="mb-4">
            <div class="flex flex-col gap-2">
                <InputLabel for="new-cat" value="Nueva categoría" />
                <div class="flex gap-2">
                    <InputText id="new-cat" ref="firstInputRef" v-model="newCategoryName" placeholder="Nombre" class="flex-1" />
                    <InputText v-model="newCategoryDescription" placeholder="Descripción (opcional)" class="flex-1" />
                    <Button type="submit" icon="pi pi-plus" :loading="newCategoryProcessing" :disabled="!newCategoryName" />
                </div>
            </div>
        </form>

        <Divider />

        <!-- Tabla -->
        <div style="height: 300px; overflow-y: auto;">
            <DataTable :value="categories" v-model:editingRows="editingRows" editMode="row" dataKey="id"
                @row-edit-save="onRowEditSave" :loading="loading" size="small" stripedRows>
                
                <template #empty><div class="text-center p-4 text-gray-500">Sin categorías.</div></template>
                <template #loading>
                     <div class="flex justify-center p-4"><ProgressSpinner style="width: 40px; height: 40px" /></div>
                </template>

                <Column field="name" header="Nombre" style="width: 40%">
                    <template #editor="{ data, field }"><InputText v-model="data[field]" autofocus class="w-full" /></template>
                </Column>
                <Column field="description" header="Descripción" style="width: 40%">
                    <template #editor="{ data, field }"><InputText v-model="data[field]" class="w-full" /></template>
                </Column>
                <Column :rowEditor="true" style="width: 10%; min-width: 4rem" bodyStyle="text-align: center"></Column>
                <Column style="width: 10%; min-width: 4rem" bodyStyle="text-align: center">
                    <template #body="{ data }">
                        <Button @click="confirmDelete(data)" icon="pi pi-trash" severity="danger" text rounded size="small" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <template #footer>
            <Button label="Cerrar" severity="secondary" @click="closeModal" />
        </template>
    </Dialog>

    <!-- Modal de Migración (Secundario) -->
    <Dialog v-model:visible="migrationDialogVisible" modal header="⚠ Gastos asociados detectados" :style="{ width: '30rem' }">
        <div class="p-2">
            <div class="flex items-start gap-3 mb-4 text-gray-700 dark:text-gray-300">
                <i class="pi pi-exclamation-circle text-orange-500 text-2xl mt-1"></i>
                <div>
                    <p class="mb-2">
                        La categoría <strong>"{{ categoryToDelete?.name }}"</strong> tiene gastos registrados.
                    </p>
                    <p class="text-sm">
                        Para eliminarla, debes mover estos gastos a otra categoría.
                    </p>
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Mover gastos a:
                </label>
                <Select 
                    v-model="targetCategoryId" 
                    :options="migrationOptions" 
                    optionLabel="name" 
                    optionValue="id" 
                    placeholder="Selecciona una categoría" 
                    class="w-full"
                    filter
                />
            </div>
        </div>

        <template #footer>
            <Button label="Cancelar" severity="secondary" text @click="migrationDialogVisible = false" />
            <Button label="Mover y Eliminar" icon="pi pi-arrow-right-arrow-left" severity="danger" 
                @click="handleMigration" 
                :loading="isMigrating"
                :disabled="!targetCategoryId" />
        </template>
    </Dialog>
</template>