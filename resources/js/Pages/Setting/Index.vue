<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    settings: Object, // { 'Punto de Venta': [setting1, setting2], 'Notificaciones': [...] }
});

// Crear un objeto plano para el formulario a partir de las props anidadas
const initialFormValues = {};
Object.values(props.settings).flat().forEach(setting => {
    initialFormValues[setting.key] = setting.value;
});

const form = useForm({
    settings: initialFormValues,
});

const submit = () => {
    form.put(route('settings.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Configuraciones" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-4xl mx-auto">
                <header class="mb-6">
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Configuraciones</h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Personaliza el comportamiento del sistema para que se adapte a tu negocio.</p>
                </header>
                
                <form @submit.prevent="submit">
                    <TabView>
                        <TabPanel v-for="(moduleSettings, moduleName) in settings" :key="moduleName" :header="moduleName">
                            <div class="p-4 space-y-6">
                                <div v-for="setting in moduleSettings" :key="setting.id" class="flex items-start justify-between">
                                    <div class="max-w-lg">
                                        <InputLabel :for="setting.key" :value="setting.name" class="font-semibold" />
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ setting.description }}</p>
                                    </div>
                                    <div class="flex-shrink-0 ml-4">
                                        <ToggleSwitch v-if="setting.type === 'boolean'" :inputId="setting.key" v-model="form.settings[setting.key]" />
                                        <InputText v-if="setting.type === 'text'" :id="setting.key" v-model="form.settings[setting.key]" />
                                        <InputNumber v-if="setting.type === 'number'" :inputId="setting.key" v-model="form.settings[setting.key]" />
                                        <!-- Aquí se añadirían otros tipos de input como Select, etc. -->
                                         <InputError :message="form.errors[`settings.${setting.key}`]" class="mt-2" />
                                    </div>
                                </div>
                            </div>
                        </TabPanel>
                    </TabView>
                    
                    <div class="mt-6 flex justify-end bg-white dark:bg-gray-800 p-4 rounded-b-lg shadow-md sticky bottom-0">
                        <Button type="submit" label="Guardar Cambios" icon="pi pi-check" :loading="form.processing" severity="warning" />
                    </div>
                </form>

            </div>
        </div>
    </AppLayout>
</template>