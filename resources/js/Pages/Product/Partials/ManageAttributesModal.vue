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
            isNew: false,
            form: {
                id: attr.id,
                name: attr.name,
                requires_image: !!attr.requires_image,
                options: JSON.parse(JSON.stringify(attr.options)), 
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

watch(() => props.visible, (newValue) => {
    if (newValue) {
        fetchAttributes();
    } else {
        attributes.value = [];
    }
});

const closeModal = () => {
    emit('update:visible', false);
};

const addNewAttribute = () => {
    // Si ya hay uno nuevo sin guardar, no agregar otro
    if (attributes.value.some(attr => attr.isNew)) {
        toast.add({ severity: 'info', summary: 'Atención', detail: 'Guarda el atributo actual antes de crear otro.', life: 3000 });
        return;
    }

    attributes.value.unshift({
        isNew: true,
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

const saveAttribute = async (attribute) => {
    attribute.form.processing = true;
    attribute.form.errors = {};

    try {
        let response;
        const payload = {
            category_id: props.categoryId,
            name: attribute.form.name,
            requires_image: attribute.form.requires_image,
            options: attribute.form.options.filter(opt => opt.value.trim() !== '') 
        };

        if (attribute.isNew) {
            response = await axios.post(route('attribute-definitions.store'), payload);
            attribute.isNew = false;
            attribute.form.id = response.data.id;
            toast.add({ severity: 'success', summary: 'Éxito', detail: 'Atributo creado correctamente.', life: 3000 });
        } else {
            response = await axios.put(route('attribute-definitions.update', attribute.form.id), payload);
            toast.add({ severity: 'success', summary: 'Éxito', detail: 'Atributo actualizado.', life: 3000 });
        }
        
        // Actualizar opciones con los IDs generados por el backend
        attribute.form.options = response.data.options || [];
        
        emit('updated');
        
    } catch (error) {
        if (error.response && error.response.status === 422) {
            attribute.form.errors = error.response.data.errors;
        } else {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Hubo un problema al guardar.', life: 3000 });
        }
    } finally {
        attribute.form.processing = false;
    }
};

const deleteAttribute = async (attribute, index) => {
    if (attribute.isNew) {
        attributes.value.splice(index, 1);
        return;
    }

    try {
        await axios.delete(route('attribute-definitions.destroy', attribute.form.id));
        attributes.value.splice(index, 1);
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Atributo eliminado.', life: 3000 });
        emit('updated');
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo eliminar el atributo.', life: 3000 });
    }
};
</script>

<template>
    <Dialog 
        :visible="visible" 
        @update:visible="$emit('update:visible', $event)" 
        modal 
        header="Gestión de atributos" 
        :style="{ width: '40rem' }"
    >
        <div class="p-2">
            <!-- Texto explicativo para el usuario -->
            <div class="mb-5 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <p class="m-0">
                    <i class="pi pi-info-circle text-blue-500 mr-1"></i>
                    Los atributos definen las características que varían en tus productos (ej. <strong>Color</strong>, <strong>Talla</strong> o <strong>Material</strong>). Configúralos aquí para generar combinaciones automáticas.
                </p>
            </div>

            <div v-if="isLoading" class="text-center py-8">
                <i class="pi pi-spinner pi-spin text-3xl text-gray-400"></i>
                <p class="text-gray-500 mt-2">Cargando atributos...</p>
            </div>

            <div v-else-if="attributes.length === 0" class="text-center py-8 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-lg">
                <i class="pi pi-tags text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-500 mb-4">No hay atributos configurados para esta categoría.</p>
                <Button @click="addNewAttribute" label="Crear mi primer atributo" icon="pi pi-plus" size="small" outlined severity="secondary" />
            </div>

            <Accordion v-else :activeIndex="attributes.findIndex(a => a.isNew) !== -1 ? attributes.findIndex(a => a.isNew) : null">
                <AccordionPanel v-for="(attribute, index) in attributes" :key="index" :value="index">
                    <AccordionHeader>
                        <span class="font-semibold text-gray-800 dark:text-gray-200">
                            {{ attribute.form.name || 'Nuevo Atributo (Sin nombrar)' }}
                        </span>
                        <Tag v-if="attribute.isNew" value="Nuevo" severity="info" class="ml-2 !text-[10px]" rounded />
                    </AccordionHeader>
                    
                    <AccordionContent>
                        <div class="flex flex-col gap-5 pt-2">
                            
                            <!-- Nombre del Atributo -->
                            <div>
                                <InputLabel for="name" value="Nombre del atributo (Ej: Color, Talla, Capacidad)" />
                                <InputText v-model="attribute.form.name" id="name" class="w-full mt-1" :class="{'p-invalid': attribute.form.errors.name}" />
                                <InputError :message="attribute.form.errors.name" class="mt-1" />
                            </div>

                            <!-- Requiere Imagen (Con Explicación) -->
                            <div class="flex items-start gap-3 bg-gray-50 dark:bg-gray-800/80 p-3 rounded-md border border-gray-200 dark:border-gray-700">
                                <Checkbox v-model="attribute.form.requires_image" :binary="true" :inputId="`req_img_${index}`" class="mt-0.5" />
                                <div>
                                    <label :for="`req_img_${index}`" class="font-medium cursor-pointer text-sm text-gray-800 dark:text-gray-200">
                                        Requiere fotografía específica por opción
                                    </label>
                                    <p class="text-xs text-gray-500 mt-0.5 m-0 leading-tight">
                                        Actívalo si el aspecto del producto cambia visualmente. 
                                        <strong>Útil para:</strong> Colores o Diseños. <strong>Innecesario para:</strong> Tallas o Capacidades de memoria.
                                    </p>
                                </div>
                            </div>

                            <!-- Opciones (Dinámicas) -->
                            <div>
                                <InputLabel value="Opciones / Valores posibles" class="font-medium mb-2" />
                                
                                <div class="flex flex-col gap-2">
                                    <div v-for="(option, optIndex) in attribute.form.options" :key="optIndex" class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <InputText 
                                                v-model="option.value" 
                                                :placeholder="optIndex === 0 ? 'Ej: Rojo' : (optIndex === 1 ? 'Ej: Azul' : 'Nueva opción')" 
                                                class="w-full text-sm" 
                                                :class="{'p-invalid': attribute.form.errors[`options.${optIndex}.value`]}"
                                            />
                                        </div>
                                        <Button icon="pi pi-times" severity="secondary" text rounded @click="removeOption(attribute, optIndex)" v-tooltip.top="'Quitar opción'" />
                                    </div>
                                    
                                    <!-- Errores de las opciones -->
                                    <template v-for="(option, optIndex) in attribute.form.options" :key="`error-${optIndex}`">
                                        <InputError :message="attribute.form.errors[`options.${optIndex}.value`]" class="mt-1" />
                                    </template>
                                </div>
                                
                                <Button @click="addOption(attribute)" label="Añadir otra opción" icon="pi pi-plus" size="small" text class="mt-2 text-sm !p-1" />
                            </div>
                            
                            <!-- Acciones de Guardado -->
                            <div class="flex justify-end gap-3 mt-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <Button @click="deleteAttribute(attribute, index)" label="Eliminar atributo" icon="pi pi-trash" severity="danger" text size="small" />
                                <Button @click="saveAttribute(attribute)" :label="attribute.isNew ? 'Guardar nuevo atributo' : 'Actualizar atributo'" icon="pi pi-save" severity="primary" size="small" :loading="attribute.form.processing"/>
                            </div>
                        </div>
                    </AccordionContent>
                </AccordionPanel>
            </Accordion>
        </div>

        <template #footer>
            <div class="flex justify-between w-full border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
                <Button @click="addNewAttribute" label="Nuevo Atributo" icon="pi pi-plus" severity="secondary" outlined :disabled="attributes.some(a => a.isNew)" />
                <Button label="Cerrar modal" icon="pi pi-times" severity="secondary" text @click="closeModal" />
            </div>
        </template>
    </Dialog>
</template>