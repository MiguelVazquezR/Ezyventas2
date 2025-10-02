<script setup>
import { useForm } from '@inertiajs/vue3';

defineProps({
    visible: Boolean,
});
const emit = defineEmits(['update:visible']);

const form = useForm({
    file: null,
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    form.post(route('import-export.customers.import'), {
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
     <Dialog :visible="visible" @update:visible="closeModal" modal header="Importar Clientes desde Excel" :style="{ width: '35rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-2 mb-4">
                <p>Sube un archivo .xlsx o .xls con las siguientes columnas para importar tus clientes:</p>
                <code class="bg-gray-100 dark:bg-gray-700 p-2 rounded-md block text-xs">
                    nombre, empresa, email, telefono, rfc, limite_de_credito
                </code>
                <p>Las columnas deben tener exactamente esos nombres. Los campos vacíos serán ignorados.</p>
            </div>
            
            <FileUpload @select="form.file = $event.files[0]" :auto="true" :customUpload="true" :show-upload-button="false" accept=".xlsx, .xls">
                 <template #empty>
                    <p>Arrastra y suelta el archivo aquí.</p>
                </template>
            </FileUpload>
            <InputError :message="form.errors.file" class="mt-2" />

            <div class="flex justify-end gap-2 mt-6">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Importar" :loading="form.processing" :disabled="!form.file"></Button>
            </div>
        </form>
    </Dialog>
</template>