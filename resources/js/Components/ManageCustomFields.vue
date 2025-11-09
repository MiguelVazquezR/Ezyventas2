<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    module: {
        type: String,
        required: true,
    },
    definitions: {
        type: Array,
        default: () => [],
    }
});

const toast = useToast();
const isDialogVisible = ref(false);

const customFieldForm = useForm({
    id: null,
    name: '',
    type: 'text',
    module: props.module,
    options: '', // Las opciones se manejarán como un string separado por comas en el form
});

const fieldTypes = ref([
    { label: 'Texto corto', value: 'text' },
    { label: 'Número', value: 'number' },
    { label: 'Texto largo', value: 'textarea' },
    { label: 'Sí/No (switch)', value: 'boolean' },
    { label: 'Selección única', value: 'select' },
    { label: 'Selección múltiple', value: 'checkbox' },
    { label: 'Desbloqueo celular', value: 'pattern' },
]);

const open = () => {
    customFieldForm.reset();
    customFieldForm.clearErrors();
    isDialogVisible.value = true;
};

// Exponer el método `open` para que el componente padre pueda llamarlo
defineExpose({ open });

const editCustomField = (field) => {
    customFieldForm.id = field.id;
    customFieldForm.name = field.name;
    customFieldForm.type = field.type;
    // Convierte el array de opciones de nuevo a un string para el textarea
    customFieldForm.options = field.options ? field.options.join(', ') : '';
};

const saveCustomField = () => {
    const options = {
        preserveState: true,
        onSuccess: () => {
            customFieldForm.reset();
            customFieldForm.module = props.module; // Re-establecer el módulo
            router.reload({ only: ['customFieldDefinitions'] });
        },
        onError: (errors) => {
            toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo guardar el campo.', life: 3000 });
        }
    };

    if (customFieldForm.id) {
        customFieldForm.put(route('custom-field-definitions.update', customFieldForm.id), options);
    } else {
        customFieldForm.post(route('custom-field-definitions.store'), options);
    }
};

const deleteCustomField = (id) => {
    router.delete(route('custom-field-definitions.destroy', id), {
        preserveState: true,
        onSuccess: () => {
            router.reload({ only: ['customFieldDefinitions'] });
        },
        onError: () => {
            toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo eliminar el campo.', life: 3000 });
        }
    });
};
</script>

<template>
    <Dialog v-model:visible="isDialogVisible" modal header="Gestionar campos personalizados" class="w-full max-w-3xl">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Formulario de creación/edición -->
            <div class="w-full md:w-1/3 border-r-0 md:border-r pr-0 md:pr-6">
                <form @submit.prevent="saveCustomField" class="space-y-4">
                    <h3 class="font-bold text-lg">{{ customFieldForm.id ? 'Editar campo' : 'Nuevo campo' }}</h3>
                    <div>
                        <InputLabel for="cf-name" value="Nombre del campo" />
                        <InputText id="cf-name" v-model="customFieldForm.name" class="w-full mt-1" />
                        <InputError :message="customFieldForm.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="cf-type" value="Tipo de campo" />
                        <Dropdown id="cf-type" v-model="customFieldForm.type" :options="fieldTypes" optionLabel="label"
                            optionValue="value" class="w-full mt-1" />
                        <InputError :message="customFieldForm.errors.type" class="mt-1" />
                    </div>

                    <div v-if="customFieldForm.type === 'select' || customFieldForm.type === 'checkbox'">
                        <InputLabel for="cf-options" value="Opciones (separadas por coma)" />
                        <Textarea id="cf-options" v-model="customFieldForm.options" class="w-full mt-1" rows="3"
                            placeholder="Opción 1, Opción 2, Opción 3" />
                        <InputError :message="customFieldForm.errors.options" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <Button type="submit" :label="customFieldForm.id ? 'Actualizar' : 'Crear'"
                            :loading="customFieldForm.processing" severity="warning" size="small" />
                        <Button v-if="customFieldForm.id" label="Cancelar" @click="customFieldForm.reset(); customFieldForm.module = props.module"
                            severity="secondary" text size="small" />
                    </div>
                </form>
            </div>
            <!-- Lista de campos existentes -->
            <div class="w-full md:w-2/3">
                 <h3 class="font-bold text-lg mb-4">Campos existentes</h3>
                <DataTable :value="definitions" size="small" scrollable scrollHeight="400px">
                    <template #empty>No hay campos definidos.</template>
                    <Column field="name" header="Nombre" style="min-width: 150px;"></Column>
                    <Column field="type" header="Tipo" style="min-width: 100px;"></Column>
                    <Column header="Acciones" style="min-width: 80px;" frozen alignFrozen="right">
                        <template #body="{ data }">
                            <div class="flex gap-1">
                                <Button icon="pi pi-pencil" @click="editCustomField(data)" size="small" text rounded />
                                <Button icon="pi pi-trash" @click="deleteCustomField(data.id)" size="small" text rounded
                                    severity="danger" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </Dialog>
</template>