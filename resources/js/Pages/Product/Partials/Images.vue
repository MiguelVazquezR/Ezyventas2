<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: Object,
});

const onGeneralImagesSelect = (event) => {
    props.form.general_images = event.files;
};
</script>

<template>
    <div id="images" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md scroll-mt-24">
        <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
            Imágenes
        </h2>
        <InputLabel value="Imágenes generales (Máx. 5)" />
        <FileUpload name="general_images[]" @select="onGeneralImagesSelect" :multiple="true" accept="image/*" :maxFileSize="2000000" :showUploadButton="false" :showCancelButton="false">
            <template #empty>
                <p class="m-0 text-gray-500 text-center py-4">Arrastra y suelta las imágenes aquí, o haz clic para seleccionar.</p>
            </template>
        </FileUpload>
        <InputError :message="form.errors.general_images" class="mt-2" />
    </div>
</template>