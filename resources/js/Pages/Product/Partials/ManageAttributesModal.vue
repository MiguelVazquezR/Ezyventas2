<script setup>
import { ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    categoryId: Number,
});

const emit = defineEmits(['update:visible', 'updated']);

const toast = useToast();
const attributes = ref([]);
const isLoading = ref(false);

const fetchAttributes = async () => {
    if (!props.categoryId) return;
    isLoading.value = true;
    try {
        const response = await axios.get(route('attribute-definitions.index', { category_id: props.categoryId }));
        attributes.value = response.data.map(attr => ({
            // Se reemplaza useForm por un objeto plano para el estado del formulario
            form: {
                id: attr.id,
                name: attr.name,
                requires_image: !!attr.requires_image,
                options: JSON.parse(JSON.stringify(attr.options)), // Clon profundo
                processing: false,
                errors: {},
            }
        }));
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar los atributos.', life: 3000 });
        console.error(error);
    } finally {
        isLoading.value = false;
    }
};

// Observa cuando el modal se hace visible y carga los atributos
watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchAttributes();
    } else {
        attributes.value = []; // Limpia al cerrar
    }
});

const addNewAttribute = () => {
    attributes.value.push({
        isNew: true,
        // Se reemplaza useForm por un objeto plano
        form: {
            id: null,
            name: '',
            requires_image: false,
            options: [{ value: '' }],
            processing: false,
            errors: {},
        }
    });
};

const addOption = (attribute) => {
    attribute.form.options.push({ value: '' });
};

const removeOption = (attribute, index) => {
    attribute.form.options.splice(index, 1);
};

// --- FUNCIÓN CORREGIDA: Usa axios en lugar de form.submit ---
const saveAttribute = (attribute) => {
    const isUpdating = !!attribute.form.id;
    const url = isUpdating ? route('attribute-definitions.update', attribute.form.id) : route('attribute-definitions.store');
    const method = isUpdating ? 'put' : 'post';

    // Se construye el payload desde el objeto 'form'
    const payload = { ...attribute.form };
    payload.options = payload.options.filter(opt => opt.value.trim() !== '');
    if (!isUpdating) {
        payload.category_id = props.categoryId;
    }

    // Se manejan los estados de 'processing' y 'errors' manualmente
    attribute.form.processing = true;
    attribute.form.errors = {};

    axios[method](url, payload)
        .then(() => {
            toast.add({ severity: 'success', summary: 'Éxito', detail: `Atributo ${isUpdating ? 'actualizado' : 'creado'} correctamente.`, life: 3000 });
            fetchAttributes(); // Recargar la lista
            emit('updated');   // Notificar al componente padre
        })
        .catch(error => {
            if (error.response && error.response.status === 422) {
                // Errores de validación del backend
                attribute.form.errors = error.response.data.errors;
                toast.add({ severity: 'error', summary: 'Error de validación', detail: 'Por favor, revisa los campos.', life: 3000 });
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: 'Ocurrió un error inesperado.', life: 3000 });
                console.error(error);
            }
        })
        .finally(() => {
            attribute.form.processing = false;
        });
};

const deleteAttribute = (attribute, index) => {
    if (attribute.isNew) {
        attributes.value.splice(index, 1);
        return;
    }

    if (confirm(`¿Estás seguro de que quieres eliminar el atributo "${attribute.form.name}"? Esta acción no se puede deshacer.`)) {
        axios.delete(route('attribute-definitions.destroy', attribute.form.id))
            .then(() => {
                toast.add({ severity: 'success', summary: 'Éxito', detail: 'Atributo eliminado.', life: 3000 });
                fetchAttributes(); // Recargar la lista
                emit('updated'); // Notificar al componente padre
            })
            .catch(error => {
                toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo eliminar el atributo.', life: 3000 });
                console.error(error);
            });
    }
};

const closeModal = () => {
    emit('update:visible', false);
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Gestionar Atributos de Variante" class="w-full max-w-2xl">
        <div v-if="isLoading" class="flex justify-center items-center p-8">
            <ProgressSpinner />
        </div>
        <div v-else class="space-y-4 max-h-[60vh] overflow-y-auto p-1">
            <p v-if="attributes.length === 0" class="text-gray-500 dark:text-gray-400 text-center py-4">
                No hay atributos definidos para esta categoría.
            </p>
            <Accordion v-else :multiple="true" :activeIndex="[0]">
                <AccordionTab v-for="(attribute, index) in attributes" :key="attribute.form.id || `new-${index}`">
                    <template #header>
                        <span class="font-semibold">{{ attribute.form.name || 'Nuevo Atributo' }}</span>
                    </template>
                    <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-b-md">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Nombre del Atributo" :for="`attr-name-${index}`" />
                                <InputText :id="`attr-name-${index}`" v-model="attribute.form.name" class="w-full mt-1" />
                                <InputError :message="attribute.form.errors.name" class="mt-1" />
                            </div>
                            <div class="flex items-center pt-5">
                                <ToggleSwitch v-model="attribute.form.requires_image" :inputId="`attr-image-${index}`" />
                                <InputLabel :for="`attr-image-${index}`" value="¿Requiere imagen?" class="ml-2" />
                            </div>
                        </div>

                        <div class="mt-4">
                            <InputLabel value="Opciones del Atributo" />
                            <div class="space-y-2 mt-1">
                                <div v-for="(option, optIndex) in attribute.form.options" :key="optIndex" class="flex items-center gap-2">
                                    <InputText v-model="option.value" placeholder="Ej: Rojo, Grande, Algodón" class="flex-grow" />
                                    <Button @click="removeOption(attribute, optIndex)" icon="pi pi-trash" severity="danger" text rounded />
                                </div>
                                <!-- Mostrar errores de validación para las opciones -->
                                <template v-for="(option, optIndex) in attribute.form.options" :key="`error-${optIndex}`">
                                    <InputError :message="attribute.form.errors[`options.${optIndex}.value`]" class="mt-1" />
                                </template>
                            </div>
                            <Button @click="addOption(attribute)" label="Agregar opción" icon="pi pi-plus" size="small" text class="mt-2" />
                        </div>
                        
                        <div class="flex justify-end gap-2 mt-4 border-t pt-3">
                             <Button @click="deleteAttribute(attribute, index)" label="Eliminar" icon="pi pi-trash" severity="danger" outlined />
                             <Button @click="saveAttribute(attribute)" :label="attribute.isNew ? 'Crear' : 'Guardar'" icon="pi pi-check" severity="warning" :loading="attribute.form.processing"/>
                        </div>
                    </div>
                </AccordionTab>
            </Accordion>
        </div>

        <template #footer>
            <div class="flex justify-between w-full">
                <Button @click="addNewAttribute" label="Nuevo Atributo" icon="pi pi-plus" severity="success" />
                <Button label="Cerrar" icon="pi pi-times" severity="secondary" @click="closeModal" text />
            </div>
        </template>
    </Dialog>
</template>