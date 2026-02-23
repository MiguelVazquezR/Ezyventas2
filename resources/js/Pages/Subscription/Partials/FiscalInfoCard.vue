<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    fiscalDocumentUrl: String
});

const toast = useToast();
const fileUploadRef = ref(null);

const docForm = useForm({ fiscal_document: null });

const onFileSelect = (event) => { docForm.fiscal_document = event.files[0]; };

const uploadDocument = () => {
    docForm.post(route('subscription.document.store'), {
        onSuccess: () => {
            docForm.reset();
            if (fileUploadRef.value) fileUploadRef.value.clear();
        }
    });
};

const getFileIcon = (type) => {
    if (type.includes('pdf')) return 'pi pi-file-pdf text-red-500 text-4xl';
    if (type.includes('image')) return 'pi pi-image text-blue-500 text-4xl';
    return 'pi pi-file text-gray-500 text-4xl';
};

// Computed property para mejorar el ícono basado en la URL
const isPdf = computed(() => {
    return props.fiscalDocumentUrl && props.fiscalDocumentUrl.toLowerCase().includes('.pdf');
});
</script>

<template>
    <Card>
        <template #title>Información fiscal</template>
        <template #content>
            <div v-if="fiscalDocumentUrl" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">Tu constancia de situación fiscal
                    está registrada.</p>
                <a :href="fiscalDocumentUrl" target="_blank" rel="noopener noreferrer">
                    <Button 
                        label="Ver documento actual" 
                        :icon="isPdf ? 'pi pi-file-pdf' : 'pi pi-image'" 
                        outlined
                        severity="secondary" 
                    />
                </a>
                <p class="text-xs text-gray-500 pt-4 border-t dark:border-gray-700">Para actualizar,
                    simplemente sube un nuevo archivo.</p>
            </div>
            <p v-else class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                Sube tu Constancia de Situación Fiscal para solicitar facturas.
            </p>
            <FileUpload ref="fileUploadRef" name="fiscal_document" @select="onFileSelect"
                :showUploadButton="false" :showCancelButton="false" customUpload
                accept=".pdf,.jpg,.jpeg,.png" :maxFileSize="2048000">
                <template #thumbnail="{ file }">
                    <div
                        class="w-full h-full flex items-center justify-center border-2 border-dashed rounded-md p-4">
                        <i :class="getFileIcon(file.type)"></i>
                    </div>
                </template>
                <template #empty>
                    <p class="text-sm text-center text-gray-500">Arrastra y suelta tu archivo aquí o haz
                        clic para seleccionar.</p>
                </template>
            </FileUpload>
            <Button v-if="docForm.fiscal_document" @click="uploadDocument" label="Subir nuevo documento"
                class="w-full mt-4" :loading="docForm.processing" />
        </template>
    </Card>
</template>