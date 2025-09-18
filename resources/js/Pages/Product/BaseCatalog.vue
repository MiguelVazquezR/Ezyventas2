<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';

const props = defineProps({
    availableProducts: Array,
    localProducts: Array,
});

const sourceProducts = ref([...props.availableProducts]);
const targetProducts = ref([...props.localProducts]);

const onMoveToTarget = (event) => {
    const idsToImport = event.items.map(p => p.id);
    router.post(route('products.base-catalog.import'), { product_ids: idsToImport }, {
        preserveScroll: true,
    });
};

const onMoveToSource = (event) => {
    const idsToUnlink = event.items.map(p => p.id);
    router.post(route('products.base-catalog.unlink'), { product_ids: idsToUnlink }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Catálogo Base de Productos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Navegación -->
                <ProductNavigation />

                <!-- Explicación -->
                <div class="bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 p-4 rounded-lg text-sm mb-6">
                    <p class="font-semibold">¿Cómo funciona el catálogo base?</p>
                    <p class="mt-1">
                        Aquí puedes explorar una lista de productos pre-cargados para tu tipo de negocio. Usa los botones <i class="pi pi-angle-right"></i> para importar productos a tu tienda y <i class="pi pi-angle-left"></i> para desvincularlos. Los productos importados aparecerán en "Mis Productos" y podrás gestionar su stock y precios.
                    </p>
                </div>

                <!-- PickList para transferir -->
                <PickList v-model:list1="sourceProducts" v-model:list2="targetProducts" listStyle="height:342px" dataKey="id"
                    @move-to-target="onMoveToTarget"
                    @move-to-source="onMoveToSource">
                    <template #sourceheader> Disponibles en el Catálogo </template>
                    <template #targetheader> En Mi Tienda </template>
                    <template #item="slotProps">
                        <div class="flex flex-wrap p-2 align-items-center gap-3">
                            <img v-if="slotProps.item.media && slotProps.item.media.length > 0" class="w-16 h-16 shrink-0 rounded-md object-cover" :src="slotProps.item.media[0].original_url" :alt="slotProps.item.name" />
                             <div v-else class="w-16 h-16 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <i class="pi pi-image text-3xl text-gray-400 dark:text-gray-500"></i>
                            </div>
                            <div class="flex-1 flex flex-col gap-2">
                                <span class="font-bold">{{ slotProps.item.name }}</span>
                                <div class="flex align-items-center gap-2">
                                    <i class="pi pi-tag text-sm"></i>
                                    <span>{{ slotProps.item.sku }}</span>
                                </div>
                            </div>
                            <span class="font-bold text-lg">${{ slotProps.item.selling_price }}</span>
                        </div>
                    </template>
                </PickList>
            </div>
        </div>
    </AppLayout>
</template>