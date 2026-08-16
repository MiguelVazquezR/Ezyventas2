<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    form: Object,
    categories: Array,
    brands: Array,
    providers: Array,
    branches: Array,
    showCreatedAt: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['open-category', 'open-brand', 'open-provider']);

const hasBilling = computed(() => usePage().props.auth.active_modules?.includes('module_billing'));
</script>

<template>
    <div id="general" class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] scroll-mt-24">
        <h2 class="text-lg font-semibold mb-6 text-gray-900 dark:text-white m-0">
            Información general
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
            <div class="col-span-full md:col-span-6 flex flex-col gap-1.5">
                <label for="name" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre del producto *</label>
                <InputText v-model="form.name" id="name" class="w-full" placeholder="Ej: Funda de Silicón" autofocus :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.name" severity="error" variant="simple" size="small">{{ form.errors.name }}</Message>
            </div>

            <div class="col-span-full md:col-span-3 flex flex-col gap-1.5">
                <label for="sku" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">SKU / Código de barras</label>
                <InputText v-model="form.sku" id="sku" class="w-full" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.sku" severity="error" variant="simple" size="small">{{ form.errors.sku }}</Message>
            </div>

            <div class="col-span-full md:col-span-3 flex flex-col gap-1.5">
                <label for="location" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Ubicación (Estante/Pasillo)</label>
                <InputText v-model="form.location" id="location" class="w-full" placeholder="Ej: Pasillo 3, Nivel 2" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.location" severity="error" variant="simple" size="small">{{ form.errors.location }}</Message>
            </div>

            <!-- SAT fiscal fields (only when billing module is active) -->
            <template v-if="hasBilling">
                <div class="col-span-full md:col-span-3 flex flex-col gap-1.5">
                    <label for="sat_product_code" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clave de Producto (En caso de emitir facturas)</label>
                    <InputText v-model="form.sat_product_code" id="sat_product_code" class="w-full" placeholder="01010101" maxlength="8" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                    <Message v-if="form.errors.sat_product_code" severity="error" variant="simple" size="small">{{ form.errors.sat_product_code }}</Message>
                </div>
                <div class="col-span-full md:col-span-3 flex flex-col gap-1.5">
                    <label for="sat_unit_code" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clave Unidad (En caso de emitir facturas)</label>
                    <Select v-model="form.sat_unit_code" :options="[
                        { value: 'H87', label: 'H87 - Pieza', description: 'Artículos individuales / Productos físicos' },
                        { value: 'E48', label: 'E48 - Unidad de servicio', description: 'Servicios (consultoría, desarrollo, honorarios, comisiones)' },
                        { value: 'KGM', label: 'KGM - Kilogramo', description: 'Materiales, alimentos a granel, peso' },
                        { value: 'LTR', label: 'LTR - Litro', description: 'Líquidos, insumos' },
                        { value: 'MTR', label: 'MTR - Metro', description: 'Telas, cables, construcción' },
                        { value: 'XBX', label: 'XBX - Caja', description: 'Empaques o ventas agrupadas' },
                        { value: 'XPK', label: 'XPK - Paquete', description: 'Kits o venta agrupada' },
                        { value: 'DAY', label: 'DAY - Día', description: 'Arrendamiento de equipo, hospedaje' },
                        { value: 'HUR', label: 'HUR - Hora', description: 'Soporte por tiempo, asesorías' },
                        { value: 'MON', label: 'MON - Mes', description: 'Suscripciones, rentas' },
                        { value: 'ACT', label: 'ACT - Actividad', description: 'Tareas de mantenimiento o servicios por avance' },
                    ]" optionLabel="label" optionValue="value" placeholder="Selecciona" filter showClear class="w-full" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }"><template #option="s"><div class="flex flex-col gap-0.5"><span class="text-sm font-medium">{{ s.option.label }}</span><span class="text-xs text-zinc-500">{{ s.option.description }}</span></div></template></Select>
                    <Message v-if="form.errors.sat_unit_code" severity="error" variant="simple" size="small">{{ form.errors.sat_unit_code }}</Message>
                </div>
            </template>

            <div class="col-span-full flex flex-col gap-1.5">
                <label for="branch_ids" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Disponible en sucursales: *</label>
                <MultiSelect id="branch_ids" v-model="form.branch_ids" :options="branches" optionLabel="name"
                    optionValue="id" placeholder="Selecciona las sucursales" class="w-full" display="chip" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.branch_ids" severity="error" variant="simple" size="small">{{ form.errors.branch_ids }}</Message>
            </div>

            <!-- Categoría -->
            <div class="col-span-full md:col-span-2 flex flex-col gap-1.5 justify-end">
                <div class="flex justify-between items-center">
                    <label for="category" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Categoría *</label>
                    <Button @click="$emit('open-category')" label="Gestionar" icon="pi pi-cog" text size="small" class="!p-0 !rounded-full" />
                </div>
                <Select id="category" v-model="form.category_id" :options="categories" optionLabel="name"
                    optionValue="id" placeholder="Selecciona una categoría" filter class="w-full" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.category_id" severity="error" variant="simple" size="small">{{ form.errors.category_id }}</Message>
            </div>

            <!-- Marca -->
            <div class="col-span-full md:col-span-2 flex flex-col gap-1.5 justify-end">
                <div class="flex justify-between items-center">
                    <label for="brand" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Marca</label>
                    <Button @click="$emit('open-brand')" label="Gestionar" icon="pi pi-cog" text size="small" class="!p-0 !rounded-full" />
                </div>
                <Select id="brand" v-model="form.brand_id" :options="brands" optionLabel="name"
                    optionValue="id" placeholder="Selecciona una marca" filter class="w-full" showClear :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.brand_id" severity="error" variant="simple" size="small">{{ form.errors.brand_id }}</Message>
            </div>

            <!-- Proveedor -->
            <div class="col-span-full md:col-span-2 flex flex-col gap-1.5 justify-end">
                <div class="flex justify-between items-center">
                    <label for="provider" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Proveedor</label>
                    <Button @click="$emit('open-provider')" label="Gestionar" icon="pi pi-cog" text size="small" class="!p-0 !rounded-full" />
                </div>
                <Select id="provider" v-model="form.provider_id" :options="providers" optionLabel="name"
                    optionValue="id" placeholder="Selecciona un proveedor" filter class="w-full" showClear :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.provider_id" severity="error" variant="simple" size="small">{{ form.errors.provider_id }}</Message>
            </div>

            <div v-if="showCreatedAt" class="col-span-full md:col-span-3 flex flex-col gap-1.5">
                <label for="created_at" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha y hora de creación</label>
                <DatePicker v-model="form.created_at" id="created_at" showTime hourFormat="24" dateFormat="dd/mm/yy"
                    class="w-full" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                <Message v-if="form.errors.created_at" severity="error" variant="simple" size="small">{{ form.errors.created_at }}</Message>
            </div>

            <div class="col-span-full flex flex-col gap-1.5">
                <label for="description" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descripción</label>
                <Editor v-model="form.description" editorStyle="height: 150px" :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] overflow-hidden' } }" />
                <Message v-if="form.errors.description" severity="error" variant="simple" size="small">{{ form.errors.description }}</Message>
            </div>
        </div>
    </div>
</template>