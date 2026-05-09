<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    note: {
        type: Object,
        default: () => null
    }
});

const isEditing = !!props.note;

// Estado del formulario
const form = useForm({
    title: props.note?.title || '',
    version: props.note?.version || '',
    excerpt: props.note?.excerpt || '',
    content: props.note?.content || '',
    is_published: props.note ? !!props.note.is_published : false,
    media: [], // Nuevos archivos
    deleted_media: [] // IDs de archivos existentes a eliminar
});

// Medios existentes (cargados por Spatie en el backend)
const existingMedia = ref(props.note?.media || []);

const onFileSelect = (event) => {
    // PrimeVue FileUpload manda los archivos seleccionados en el evento
    form.media = event.files;
};

const onFileRemove = (event) => {
    // Se ejecuta al quitar un archivo nuevo que apenas se iba a subir
    form.media = form.media.filter(f => f.name !== event.file.name);
};

const removeExistingMedia = (mediaId) => {
    // Agregamos el ID al array de eliminados para que el Controller lo borre
    form.deleted_media.push(mediaId);
    // Lo quitamos visualmente de la lista
    existingMedia.value = existingMedia.value.filter(m => m.id !== mediaId);
};

const submit = () => {
    if (isEditing) {
        // Inertia requiere enviar archivos por POST simulando un PUT
        form.transform((data) => ({
            ...data,
            _method: 'PUT',
        })).post(route('admin.release-notes.update', props.note.id), {
            preserveScroll: true,
        });
    } else {
        form.post(route('admin.release-notes.store'), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="flex flex-col gap-6">
        
        <!-- Errores generales -->
        <Message v-if="Object.keys(form.errors).length > 0" severity="error">
            Por favor, corrige los errores en el formulario antes de continuar.
        </Message>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Título -->
            <div class="flex flex-col gap-2">
                <label for="title" class="font-bold text-gray-700 dark:text-gray-200">Título de la novedad <span class="text-red-500">*</span></label>
                <InputText id="title" v-model="form.title" placeholder="Ej. Nuevo Punto de Venta" :class="{'p-invalid': form.errors.title}" />
                <small v-if="form.errors.title" class="text-red-500">{{ form.errors.title }}</small>
            </div>

            <!-- Versión -->
            <div class="flex flex-col gap-2">
                <label for="version" class="font-bold text-gray-700 dark:text-gray-200">Versión (Opcional)</label>
                <InputText id="version" v-model="form.version" placeholder="Ej. v2.1.0" :class="{'p-invalid': form.errors.version}" />
                <small v-if="form.errors.version" class="text-red-500">{{ form.errors.version }}</small>
            </div>

            <!-- Resumen / Excerpt -->
            <div class="flex flex-col gap-2 md:col-span-2">
                <label for="excerpt" class="font-bold text-gray-700 dark:text-gray-200">Resumen corto</label>
                <Textarea id="excerpt" v-model="form.excerpt" rows="2" placeholder="Breve descripción que aparecerá en la lista y notificaciones..." :class="{'p-invalid': form.errors.excerpt}" />
                <small v-if="form.errors.excerpt" class="text-red-500">{{ form.errors.excerpt }}</small>
            </div>

            <!-- Contenido Rico -->
            <div class="flex flex-col gap-2 md:col-span-2">
                <label for="content" class="font-bold text-gray-700 dark:text-gray-200">Contenido Completo <span class="text-red-500">*</span></label>
                <Editor v-model="form.content" editorStyle="height: 300px" placeholder="Describe a detalle las nuevas funciones..." />
                <small v-if="form.errors.content" class="text-red-500">{{ form.errors.content }}</small>
            </div>

            <!-- Multimedia (Spatie Media Library) -->
            <div class="flex flex-col gap-4 md:col-span-2 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <label class="font-bold text-gray-700 dark:text-gray-200">Archivos Multimedia (Imágenes o Videos)</label>
                
                <!-- Galería existente (Al Editar) -->
                <div v-if="existingMedia.length > 0">
                    <p class="text-sm text-gray-500 mb-2">Archivos ya subidos:</p>
                    <div class="flex flex-wrap gap-4">
                        <div v-for="media in existingMedia" :key="media.id" class="relative group">
                            <!-- Nota: Usamos original_url porque es la que entrega Spatie por defecto al cargar el modelo -->
                            <img v-if="media.mime_type.startsWith('image/')" :src="media.original_url" class="w-32 h-32 object-cover rounded-lg shadow-sm border border-gray-300 dark:border-gray-600" />
                            <video v-else-if="media.mime_type.startsWith('video/')" :src="media.original_url" class="w-32 h-32 object-cover rounded-lg shadow-sm border border-gray-300 dark:border-gray-600" />
                            <div v-else class="w-32 h-32 flex items-center justify-center bg-gray-200 dark:bg-gray-700 rounded-lg shadow-sm border border-gray-300 dark:border-gray-600">
                                <i class="pi pi-file text-3xl text-gray-400"></i>
                            </div>
                            
                            <button type="button" @click="removeExistingMedia(media.id)" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 shadow-md opacity-0 group-hover:opacity-100 transition-opacity" v-tooltip.top="'Eliminar archivo'">
                                <i class="pi pi-times text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Subir nuevos -->
                <div class="mt-2">
                    <p class="text-sm text-gray-500 mb-2">Añadir nuevos archivos:</p>
                    <FileUpload name="media[]" mode="advanced" multiple accept="image/*,video/mp4,video/webm" :maxFileSize="20000000" 
                                @select="onFileSelect" @remove="onFileRemove" :showUploadButton="false" :showCancelButton="false" 
                                chooseLabel="Seleccionar archivos" class="w-full" />
                    <small class="text-gray-500 mt-1 block">Puedes seleccionar múltiples archivos. Máx 20MB por archivo.</small>
                    <small v-if="form.errors.media" class="text-red-500 block mt-1">{{ form.errors.media }}</small>
                </div>
            </div>

            <!-- Toggle de Publicación -->
            <div class="flex items-center gap-3 md:col-span-2 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800">
                <ToggleSwitch inputId="is_published" v-model="form.is_published" />
                <div>
                    <label for="is_published" class="font-bold text-gray-800 dark:text-gray-200 block cursor-pointer">Publicar Inmediatamente</label>
                    <small class="text-gray-600 dark:text-gray-400">Si lo activas, los usuarios verán la notificación al guardar. Si lo desactivas, se guardará como borrador.</small>
                </div>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex justify-end gap-3 mt-4">
            <Button label="Cancelar" icon="pi pi-times" severity="secondary" outlined @click="() => $inertia.visit(route('admin.release-notes.index'))" :disabled="form.processing" />
            <Button :label="isEditing ? 'Guardar Cambios' : 'Crear Novedad'" icon="pi pi-save" type="submit" :loading="form.processing" />
        </div>
    </form>
</template>