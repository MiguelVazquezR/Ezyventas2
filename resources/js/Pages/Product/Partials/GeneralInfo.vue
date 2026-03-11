<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: Object,
    categories: Array,
    brands: Array,
    providers: Array,
    branches: Array,
});

defineEmits(['open-category', 'open-brand', 'open-provider']);
</script>

<template>
    <div id="general" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md scroll-mt-24">
        <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
            Información general
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
            <div class="col-span-full md:col-span-6">
                <InputLabel for="name" value="Nombre del producto *" />
                <InputText v-model="form.name" id="name" class="w-full mt-1" placeholder="Ej: Funda de Silicón" autofocus />
                <InputError :message="form.errors.name" class="mt-2" />
            </div>

            <div class="col-span-full md:col-span-3">
                <InputLabel for="sku" value="SKU / Código de barras" />
                <InputText v-model="form.sku" id="sku" class="w-full mt-1" />
                <InputError :message="form.errors.sku" class="mt-2" />
            </div>

            <div class="col-span-full md:col-span-3">
                <InputLabel for="location" value="Ubicación (Estante/Pasillo)" />
                <InputText v-model="form.location" id="location" class="w-full mt-1" placeholder="Ej: Pasillo 3, Nivel 2" />
                <InputError :message="form.errors.location" class="mt-2" />
            </div>

            <div class="col-span-full">
                <InputLabel for="branch_ids" value="Disponible en sucursales: *" />
                <MultiSelect id="branch_ids" v-model="form.branch_ids" :options="branches" optionLabel="name"
                    optionValue="id" placeholder="Selecciona las sucursales" class="w-full mt-1" display="chip" />
                <InputError :message="form.errors.branch_ids" class="mt-2" />
            </div>

            <!-- Categoría -->
            <div class="col-span-full md:col-span-2 flex flex-col justify-end">
                <div class="flex justify-between items-center mb-1">
                    <InputLabel for="category" value="Categoría *" />
                    <Button @click="$emit('open-category')" label="Gestionar" icon="pi pi-cog" text size="small" class="!p-0" />
                </div>
                <Select id="category" v-model="form.category_id" :options="categories" optionLabel="name"
                    optionValue="id" placeholder="Selecciona una categoría" filter class="w-full" />
                <InputError :message="form.errors.category_id" class="mt-2" />
            </div>

            <!-- Marca -->
            <div class="col-span-full md:col-span-2 flex flex-col justify-end">
                <div class="flex justify-between items-center mb-1">
                    <InputLabel for="brand" value="Marca" />
                    <Button @click="$emit('open-brand')" label="Gestionar" icon="pi pi-cog" text size="small" class="!p-0" />
                </div>
                <Select id="brand" v-model="form.brand_id" :options="brands" optionLabel="name"
                    optionValue="id" placeholder="Selecciona una marca" filter class="w-full" showClear />
                <InputError :message="form.errors.brand_id" class="mt-2" />
            </div>

            <!-- Proveedor -->
            <div class="col-span-full md:col-span-2 flex flex-col justify-end">
                <div class="flex justify-between items-center mb-1">
                    <InputLabel for="provider" value="Proveedor" />
                    <Button @click="$emit('open-provider')" label="Gestionar" icon="pi pi-cog" text size="small" class="!p-0" />
                </div>
                <Select id="provider" v-model="form.provider_id" :options="providers" optionLabel="name"
                    optionValue="id" placeholder="Selecciona un proveedor" filter class="w-full" showClear />
                <InputError :message="form.errors.provider_id" class="mt-2" />
            </div>

            <div class="col-span-full">
                <InputLabel for="description" value="Descripción" />
                <Editor v-model="form.description" editorStyle="height: 150px" class="mt-1" />
                <InputError :message="form.errors.description" class="mt-2" />
            </div>
        </div>
    </div>
</template>