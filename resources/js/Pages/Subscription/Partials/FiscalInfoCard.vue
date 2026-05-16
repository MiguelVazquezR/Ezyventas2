<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import Button from 'primevue/button';
import FileUpload from 'primevue/fileupload';

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
    if (type.includes('pdf')) return 'pi pi-file-pdf text-red-500';
    if (type.includes('image')) return 'pi pi-image text-blue-500';
    return 'pi pi-file text-gray-500';
};

// Computed property para mejorar el ícono basado en la URL
const isPdf = computed(() => {
    return props.fiscalDocumentUrl && props.fiscalDocumentUrl.toLowerCase().includes('.pdf');
});

// --- TESLA UI PASS-THROUGH (PT) ---
const fileUploadPt = {
    root: { class: 'w-full' },
    buttonbar: { class: 'hidden' }, // Ocultamos la barra superior nativa
    content: { class: '!p-0 !border-none !bg-transparent' } // Quitamos los bordes internos
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                <i class="pi pi-file-pdf !text-sm text-red-500"></i>
            </div>
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Información fiscal</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Documentos y facturación</p>
            </div>
        </div>

        <!-- Contenido Principal -->
        <div class="flex-grow flex flex-col">
            
            <!-- Estado: Documento Registrado -->
            <div v-if="fiscalDocumentUrl" class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] text-center mb-6">
                <i class="pi pi-check-circle !text-3xl text-green-500 mb-3"></i>
                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 mb-1 tracking-tight">Constancia registrada</p>
                <p class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mb-4">Tu documento fiscal está activo.</p>
                
                <a :href="fiscalDocumentUrl" target="_blank" rel="noopener noreferrer" class="block w-full">
                    <Button 
                        label="Ver documento actual" 
                        :icon="isPdf ? 'pi pi-file-pdf' : 'pi pi-image'" 
                        severity="secondary" 
                        outlined
                        class="!w-full !rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold"
                    />
                </a>
            </div>

            <!-- Estado: Falta Documento -->
            <div v-else class="mb-6 bg-orange-50 dark:bg-orange-900/10 p-4 rounded-2xl border border-orange-100 dark:border-orange-900/30 flex items-start gap-3">
                <i class="pi pi-exclamation-triangle mt-0.5 !text-lg text-orange-500"></i>
                <div>
                    <p class="text-[10px] font-bold text-orange-600 dark:text-orange-400 uppercase tracking-widest m-0 mb-1">Falta documento</p>
                    <p class="text-xs text-orange-800 dark:text-orange-300 m-0 leading-relaxed">
                        Sube tu Constancia de Situación Fiscal actualizada para poder solicitar facturas.
                    </p>
                </div>
            </div>

            <!-- Zona de Actualización / Subida -->
            <div class="relative group mt-1">
                <span v-if="fiscalDocumentUrl" class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-3 border-t border-gray-100 dark:border-[#3a3a3a] pt-6">
                    Actualizar documento
                </span>
                
                <FileUpload ref="fileUploadRef" name="fiscal_document" @select="onFileSelect"
                    :showUploadButton="false" :showCancelButton="false" customUpload
                    accept=".pdf,.jpg,.jpeg,.png" :maxFileSize="2048000"
                    :pt="fileUploadPt">
                    
                    <template #thumbnail="{ file }">
                        <div class="w-full flex flex-col items-center justify-center border-2 border-dashed border-primary-300 dark:border-primary-800/50 bg-primary-50 dark:bg-primary-900/10 rounded-2xl p-6 transition-colors">
                            <i :class="getFileIcon(file.type)" class="!text-3xl mb-3"></i>
                            <span class="text-sm font-medium text-primary-700 dark:text-primary-400 m-0">{{ file.name }}</span>
                            <span class="text-[10px] text-primary-500/70 mt-1 uppercase tracking-widest m-0">{{ (file.size / 1024 / 1024).toFixed(2) }} MB</span>
                        </div>
                    </template>
                    
                    <template #empty>
                        <div class="w-full flex flex-col items-center justify-center border-2 border-dashed border-gray-200 dark:border-[#3a3a3a] bg-gray-50/50 dark:bg-[#1a1a1a] rounded-2xl p-8 transition-colors hover:border-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/10 cursor-pointer">
                            <i class="pi pi-cloud-upload !text-3xl text-gray-400 dark:text-gray-600 mb-3 group-hover:text-primary-500 transition-colors"></i>
                            <p class="text-xs font-medium text-gray-600 dark:text-gray-300 m-0 text-center">Arrastra tu archivo aquí</p>
                            <p class="text-[10px] uppercase tracking-widest text-gray-400 mt-1 m-0 text-center">o haz clic para explorar</p>
                        </div>
                    </template>
                </FileUpload>
                
                <Button v-if="docForm.fiscal_document" @click="uploadDocument" label="Subir documento" icon="pi pi-upload"
                    severity="primary" class="w-full mt-4 !rounded-xl !uppercase !tracking-widest !text-xs !font-bold shadow-sm" :loading="docForm.processing" />
            </div>
        </div>

    </div>
</template>