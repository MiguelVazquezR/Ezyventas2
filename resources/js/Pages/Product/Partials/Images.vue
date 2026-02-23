<script setup>
import { computed, ref, watch } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Tag from 'primevue/tag';
import Button from 'primevue/button';

const props = defineProps({
    form: Object,
    attributeDefinitions: Array,
    product: {
        type: Object,
        default: null // Nulo en "Crear", lleno en "Editar"
    }
});

// --- LÓGICA DE MODO EDICIÓN (IMÁGENES EXISTENTES) ---
const existingGeneralImages = computed(() => {
    if (!props.product || !props.product.media) return [];
    return props.product.media.filter(m => m.collection_name === 'product-general-images' && !(props.form.deleted_media_ids || []).includes(m.id));
});

const existingVariantImages = computed(() => {
    if (!props.product || !props.product.media) return [];
    return props.product.media.filter(m => m.collection_name === 'product-variant-images' && !(props.form.deleted_media_ids || []).includes(m.id));
});

const getExistingVariantImage = (key) => {
    return existingVariantImages.value.find(m => m.custom_properties?.variant_key === key);
};

const deleteExistingImage = (id) => {
    if (!props.form.deleted_media_ids) props.form.deleted_media_ids = [];
    props.form.deleted_media_ids.push(id);
};


// 1. Identificar QUÉ atributos requieren imagen
const attributesRequiringImages = computed(() => {
    if (props.form.product_type !== 'variant' || !props.form.category_id) return [];
    if (!props.attributeDefinitions) return [];

    return props.attributeDefinitions.filter(
        attr => attr.category_id == props.form.category_id && attr.requires_image
    );
});

// 2. Extraer opciones ÚNICAS de la matriz
const requiredVariantImagesList = computed(() => {
    const list = [];
    
    attributesRequiringImages.value.forEach(attr => {
        const attrName = attr.name;
        const uniqueValues = new Set();
        
        if (props.form.variants_matrix) {
            props.form.variants_matrix.forEach(variant => {
                if (variant.attributes && variant.attributes[attrName]) {
                    uniqueValues.add(variant.attributes[attrName]);
                }
            });
        }
        
        uniqueValues.forEach(val => {
            list.push({
                attribute: attrName,
                value: val,
                key: `${attrName}_${val}` 
            });
        });
    });
    
    return list;
});

// Manejador para nuevas imágenes generales
const onGeneralImagesSelect = (event) => {
    props.form.general_images = event.files;
};

// --- LÓGICA DE VISTA PREVIA Y SUBIDA DE ATRIBUTOS ---
const previews = ref({});
const fileInputs = ref({});

watch(requiredVariantImagesList, (newList) => {
    const validKeys = newList.map(item => item.key);
    
    if (props.form.variant_images) {
        Object.keys(props.form.variant_images).forEach(key => {
            if (!validKeys.includes(key)) {
                delete props.form.variant_images[key];
                delete previews.value[key];
            }
        });
    }
}, { deep: true });

const triggerFileInput = (key) => {
    if (fileInputs.value[key]) {
        fileInputs.value[key].click();
    }
};

const onVariantImageSelect = (event, key) => {
    const file = event.target.files[0];
    if (file) {
        if (Array.isArray(props.form.variant_images)) {
            props.form.variant_images = {};
        }
        
        props.form.variant_images[key] = file;
        previews.value[key] = URL.createObjectURL(file);
    }
    event.target.value = '';
};

const removeVariantImage = (key) => {
    delete props.form.variant_images[key];
    delete previews.value[key];
};
</script>

<template>
    <div id="images" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md scroll-mt-24">
        <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
            Imágenes del Producto
        </h2>

        <!-- SECCIÓN: IMÁGENES GENERALES -->
        <div>
            <div class="mb-3">
                <InputLabel value="Imágenes Generales (Máx. 5)" class="text-base font-bold" />
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Estas son las fotos principales que verán tus clientes al entrar al producto. Se recomienda usar fondo blanco y buena iluminación.
                </p>
            </div>
            
            <!-- MODO EDICIÓN: Cuadrícula de fotos generales existentes -->
            <div v-if="existingGeneralImages.length > 0" class="flex flex-wrap gap-4 mb-4">
                <div v-for="img in existingGeneralImages" :key="img.id" class="relative group w-24 h-24">
                    <img :src="img.original_url" class="w-full h-full object-cover rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center rounded-lg">
                        <Button icon="pi pi-trash" severity="danger" text rounded @click="deleteExistingImage(img.id)" v-tooltip.top="'Eliminar de la nube'" />
                    </div>
                </div>
            </div>

            <FileUpload 
                name="general_images[]" 
                @select="onGeneralImagesSelect" 
                :multiple="true" 
                accept="image/*" 
                :maxFileSize="5000000" 
                :showUploadButton="false" 
                :showCancelButton="false"
                chooseLabel="Seleccionar nuevas imágenes"
                class="border border-gray-200 dark:border-gray-700 rounded-lg"
            >
                <template #empty>
                    <div class="flex flex-col items-center justify-center p-6 text-gray-500">
                        <i class="pi pi-cloud-upload text-4xl mb-3 text-gray-400"></i>
                        <p class="m-0 text-center">Arrastra y suelta nuevas imágenes aquí, o haz clic para buscarlas en tu equipo.</p>
                    </div>
                </template>
            </FileUpload>
            <InputError :message="form.errors.general_images" class="mt-2" />
        </div>

        <!-- SECCIÓN: IMÁGENES POR ATRIBUTO -->
        <div v-if="attributesRequiringImages.length > 0 && requiredVariantImagesList.length > 0" class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
            
            <div class="mb-5 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800 flex gap-3 items-start">
                <i class="pi pi-camera text-blue-500 text-xl mt-0.5"></i>
                <div>
                    <h3 class="font-bold text-blue-800 dark:text-blue-200 m-0">Fotografías Específicas Requeridas</h3>
                    <p class="text-sm text-blue-600 dark:text-blue-400 m-0 mt-1">
                        El sistema ha detectado que debes subir una imagen por cada opción de 
                        <strong v-for="(attr, idx) in attributesRequiringImages" :key="attr.id">
                            "{{ attr.name }}"<span v-if="idx < attributesRequiringImages.length - 1">, </span>
                        </strong>.
                    </p>
                </div>
            </div>

            <!-- CUADRÍCULA DE FOTOS -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
                <div v-for="item in requiredVariantImagesList" :key="item.key" class="flex flex-col gap-2">
                    
                    <div class="bg-gray-100 dark:bg-gray-700 text-center py-1.5 px-2 rounded font-semibold text-xs text-gray-700 dark:text-gray-300 truncate" :title="item.value">
                        {{ item.attribute }}: <span class="text-blue-600 dark:text-blue-400">{{ item.value }}</span>
                    </div>

                    <div class="relative w-full aspect-square border-2 rounded-lg overflow-hidden transition-all group"
                        :class="previews[item.key] || getExistingVariantImage(item.key) ? 'border-gray-200 dark:border-gray-600' : 'border-dashed border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer'">
                        
                        <!-- Si YA hay imagen (Local o en Base de Datos): Vista previa -->
                        <template v-if="previews[item.key] || getExistingVariantImage(item.key)">
                            <img :src="previews[item.key] || getExistingVariantImage(item.key).original_url" class="w-full h-full object-cover" />
                            <!-- Overlay oscuro -->
                            <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                <Button 
                                    icon="pi pi-trash" 
                                    severity="danger" 
                                    rounded 
                                    @click="previews[item.key] ? removeVariantImage(item.key) : deleteExistingImage(getExistingVariantImage(item.key).id)" 
                                    v-tooltip.top="'Quitar imagen'" 
                                />
                            </div>
                        </template>

                        <!-- Si NO hay imagen: Botón de Subida -->
                        <div v-else @click="triggerFileInput(item.key)" class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                            <i class="pi pi-image text-3xl mb-2 opacity-50"></i>
                            <span class="text-xs font-medium">Subir foto</span>
                        </div>

                        <input type="file" :ref="el => fileInputs[item.key] = el" @change="(e) => onVariantImageSelect(e, item.key)" accept="image/jpeg, image/png, image/jpg, image/webp" class="hidden" />
                    </div>
                </div>
            </div>
            
            <InputError :message="form.errors.variant_images" class="mt-2" />
        </div>
    </div>
</template>