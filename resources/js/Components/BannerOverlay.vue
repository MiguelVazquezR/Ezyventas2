<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    banner: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['dismissed']);

const visible = ref(true);
const isZoomed = ref(false);

const closeBanner = () => {
    visible.value = false;
    emit('dismissed');
};

const toggleZoom = () => {
    if (!props.banner.banner_image_url) return;
    isZoomed.value = !isZoomed.value;
};

const goToDetails = () => {
    router.visit(route('release-notes.show', props.banner.id));
};
</script>

<template>
    <Teleport to="body">
        <Transition name="banner">
            <div v-if="visible" class="fixed inset-0 z-[9999] flex items-center justify-center">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/70 backdrop-blur-md" @click="isZoomed ? toggleZoom() : null"></div>

                <!-- Contenido del banner -->
                <div class="relative z-10 w-full h-full flex flex-col items-center justify-center p-4 md:p-6">

                    <!-- Botón de cierre -->
                    <button
                        @click="closeBanner"
                        class="absolute top-4 right-4 md:top-6 md:right-6 z-20 w-10 h-10 md:w-12 md:h-12 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 backdrop-blur-sm border border-white/20 text-white transition-all hover:scale-105"
                        v-tooltip.left="'Cerrar banner'"
                    >
                        <i class="pi pi-times !text-lg md:!text-xl"></i>
                    </button>

                    <!-- Imagen del banner -->
                    <div
                        class="flex-1 flex items-center justify-center w-full mx-auto overflow-hidden transition-all duration-300"
                        :class="isZoomed ? 'max-w-full cursor-zoom-out' : 'max-w-6xl cursor-zoom-in'"
                        @click="toggleZoom"
                    >
                        <img
                            v-if="banner.banner_image_url"
                            :src="banner.banner_image_url"
                            :alt="banner.title"
                            class="rounded-3xl shadow-2xl transition-all duration-300"
                            :class="isZoomed
                                ? 'max-w-[95vw] max-h-[90vh] object-contain'
                                : 'max-w-full max-h-[78vh] object-contain'"
                        />
                        <!-- Fallback si no hay imagen: mostrar solo el texto -->
                        <div v-else class="text-center px-6">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-purple-300 mb-4 block">
                                {{ banner.version ? 'v' + banner.version : 'Novedad' }}
                            </span>
                            <h2 class="text-3xl md:text-5xl font-light tracking-tight text-white m-0 max-w-2xl">
                                {{ banner.title }}
                            </h2>
                            <p v-if="banner.excerpt" class="text-lg text-white/60 mt-4 max-w-xl mx-auto">
                                {{ banner.excerpt }}
                            </p>
                        </div>
                    </div>

                    <!-- Indicador de zoom -->
                    <span
                        v-if="banner.banner_image_url"
                        class="text-[10px] uppercase tracking-widest font-bold text-white/40 mt-3"
                    >
                        <i class="pi pi-search-plus !text-[10px] mr-1"></i>
                        {{ isZoomed ? 'clic para reducir' : 'clic en la imagen para ampliar' }}
                    </span>

                    <!-- Footer con acciones -->
                    <div class="flex flex-col sm:flex-row items-center gap-3 mt-4 mb-2">
                        <Button
                            label="Cerrar"
                            icon="pi pi-times"
                            severity="secondary"
                            @click="closeBanner"
                            class="!rounded-full !px-8 !text-sm !font-medium !bg-white/10 !border-white/20 !text-white hover:!bg-white/20 !backdrop-blur-sm"
                            :pt="{
                                root: { class: '!rounded-full !px-8 !text-sm !font-medium !bg-white/10 !border-white/20 !text-white hover:!bg-white/20 !backdrop-blur-sm' }
                            }"
                        />
                        <Button
                            label="Ver más detalles"
                            icon="pi pi-arrow-right"
                            @click="goToDetails"
                            class="!rounded-full !px-8 !text-sm !font-medium"
                            :pt="{
                                root: { class: '!rounded-full !px-8 !text-sm !font-medium' }
                            }"
                        />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.banner-enter-active {
    transition: opacity 0.3s ease;
}
.banner-leave-active {
    transition: opacity 0.2s ease;
}
.banner-enter-from,
.banner-leave-to {
    opacity: 0;
}
</style>
