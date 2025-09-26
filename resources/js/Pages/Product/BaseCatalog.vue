<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProductNavigation from './Partials/ProductNavigation.vue';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    availableProducts: Array,
    localProducts: Array,
});

const confirm = useConfirm();

// El v-model del PickList debe ser un array con dos elementos: [source, target].
const productLists = ref([
    [...props.availableProducts], // Lista de origen (Catálogo Base)
    [...props.localProducts]      // Lista de destino (Mi Tienda)
]);

const onMoveToTarget = (event) => {
    const idsToImport = event.items.map(p => p.id);
    router.post(route('products.base-catalog.import'), { product_ids: idsToImport }, {
        preserveScroll: true,
    });
};

const onMoveToSource = (event) => {
    const items = event.items;
    const itemCount = items.length;
    const productName = itemCount === 1 ? `"${items[0].name}"` : `${itemCount} productos`;

    confirm.require({
        message: `Al desvincular ${productName}, se eliminarán de tu tienda permanentemente, incluyendo su historial, variantes y promociones. El producto seguirá disponible en el catálogo base para futuras importaciones. ¿Estás seguro de que quieres continuar?`,
        header: 'Confirmación de Desvinculación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, desvincular',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToUnlink = items.map(p => p.id);
            router.post(route('products.base-catalog.unlink'), { product_ids: idsToUnlink }, {
                preserveScroll: true,
            });
        },
        // Si el usuario rechaza, revertimos el movimiento en la interfaz.
        reject: () => {
             productLists.value = [
                [...props.availableProducts],
                [...props.localProducts]
            ];
        }
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
                        Aquí puedes explorar una lista de productos pre-cargados. Usa los botones <i class="pi pi-angle-right"></i> para importar productos a tu tienda y <i class="pi pi-angle-left"></i> para desvincularlos. Los productos importados aparecerán en "Mis Productos".
                    </p>
                </div>

                <!-- PickList con confirmación -->
                <PickList v-model="productLists" listStyle="height:342px" dataKey="id"
                    @move-to-target="onMoveToTarget"
                    @move-to-source="onMoveToSource">
                    <template #sourceheader> Disponibles en el Catálogo </template>
                    <template #targetheader> En Mi Tienda </template>
                    <template #item="slotProps">
                        <div class="flex flex-wrap p-2 items-center gap-3 w-full">
                            <img v-if="slotProps.item.media && slotProps.item.media.length > 0" class="w-16 h-16 shrink-0 rounded-md object-cover" :src="slotProps.item.media[0].original_url" :alt="slotProps.item.name" />
                             <div v-else class="w-16 h-16 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <i class="pi pi-image text-3xl text-gray-400 dark:text-gray-500"></i>
                            </div>
                            <div class="flex-1 flex flex-col gap-1">
                                <span class="font-bold">{{ slotProps.item.name }}</span>
                                <div class="flex items-center gap-2">
                                    <i class="pi pi-tag text-sm text-gray-500"></i>
                                    <span class="text-sm text-gray-500">{{ slotProps.item.sku }}</span>
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

