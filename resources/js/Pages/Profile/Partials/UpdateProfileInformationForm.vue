<script setup>
import { ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    user: Object,
});

const form = useForm({
    _method: 'PUT',
    name: props.user.name,
    email: props.user.email,
    photo: null,
});

const photoPreview = ref(null);
const photoInput = ref(null);

const updateProfileInformation = () => {
    if (photoInput.value) {
        form.photo = photoInput.value.files[0];
    }

    form.post(route('user-profile-information.update'), {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
        onSuccess: () => clearPhotoFileInput(),
    });
};

const selectNewPhoto = () => {
    photoInput.value.click();
};

const updatePhotoPreview = () => {
    const photo = photoInput.value.files[0];
    if (!photo) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        photoPreview.value = e.target.result;
    };
    reader.readAsDataURL(photo);
};

const deletePhoto = () => {
    router.delete(route('current-user-photo.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            photoPreview.value = null;
            clearPhotoFileInput();
        },
    });
};

const clearPhotoFileInput = () => {
    if (photoInput.value?.value) {
        photoInput.value.value = null;
    }
};
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-1">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Información del Perfil</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Actualiza la información de tu perfil y tu dirección de correo electrónico.
            </p>
        </div>

        <div class="md:col-span-2">
            <form @submit.prevent="updateProfileInformation">
                <div class="p-6 bg-white dark:bg-gray-800 sm:rounded-lg">
                    <div class="space-y-6">
                        <!-- Profile Photo -->
                        <div v-if="$page.props.jetstream.managesProfilePhotos">
                            <input id="photo" ref="photoInput" type="file" class="hidden" @change="updatePhotoPreview">
                            <InputLabel for="photo" value="Foto de Perfil" />

                            <div class="mt-2 flex items-center gap-4">
                               <img v-if="!photoPreview" :src="user.profile_photo_url" :alt="user.name" class="rounded-full h-20 w-20 object-cover">
                               <div v-if="photoPreview" class="w-20 h-20 rounded-full bg-cover bg-no-repeat bg-center" :style="'background-image: url(\'' + photoPreview + '\');'"></div>
                               
                               <div>
                                    <Button type="button" severity="secondary" @click.prevent="selectNewPhoto">Seleccionar Nueva Foto</Button>
                                    <Button v-if="user.profile_photo_path" type="button" severity="danger" text class="ml-2" @click.prevent="deletePhoto">Eliminar Foto</Button>
                               </div>
                            </div>
                             <InputError :message="form.errors.photo" class="mt-2" />
                        </div>

                        <!-- Name -->
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <InputText id="name" v-model="form.name" type="text" class="mt-1 block w-full" required autocomplete="name" />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>

                        <!-- Email -->
                        <div>
                            <InputLabel for="email" value="Correo Electrónico" />
                            <InputText id="email" v-model="form.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                            <InputError :message="form.errors.email" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-6">
                         <transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                            <p v-if="form.recentlySuccessful" class="text-sm text-gray-600 dark:text-gray-400 mr-2">Guardado.</p>
                        </transition>
                        <Button :class="{ 'opacity-25': form.processing }" :disabled="form.processing" type="submit" label="Guardar" />
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>