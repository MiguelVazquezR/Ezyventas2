<script setup>
import Button from 'primevue/button';
import Badge from 'primevue/badge';
import { ref } from 'vue';

const props = defineProps({
    categories: Array,
});

const selectedCategoryId = ref(props.categories.length > 0 ? props.categories[0].id : null);

const selectCategory = (categoryId) => {
    selectedCategoryId.value = categoryId;
    // Aquí puedes emitir un evento para filtrar los productos en el componente padre
    // emit('filterByCategory', categoryId);
}

// --- Lógica para el scroll con el mouse ---
const scrollContainer = ref(null);
const isDown = ref(false);
const startX = ref(0);
const scrollLeft = ref(0);

const onMouseDown = (e) => {
    if (!scrollContainer.value) return;
    isDown.value = true;
    scrollContainer.value.classList.add('grabbing');
    startX.value = e.pageX - scrollContainer.value.offsetLeft;
    scrollLeft.value = scrollContainer.value.scrollLeft;
};

const onMouseLeaveOrUp = () => {
     if (!scrollContainer.value) return;
    isDown.value = false;
    scrollContainer.value.classList.remove('grabbing');
};

const onMouseMove = (e) => {
    if (!isDown.value || !scrollContainer.value) return;
    e.preventDefault();
    const x = e.pageX - scrollContainer.value.offsetLeft;
    const walk = (x - startX.value) * 1.5; // El multiplicador * 1.5 hace el scroll un poco más rápido
    scrollContainer.value.scrollLeft = scrollLeft.value - walk;
};

</script>

<template>
    <div 
        class="flex items-center pb-2 overflow-x-auto category-scroll-container cursor-grab"
        ref="scrollContainer"
        @mousedown.prevent="onMouseDown"
        @mouseleave="onMouseLeaveOrUp"
        @mouseup="onMouseLeaveOrUp"
        @mousemove="onMouseMove"
    >
        <div class="flex gap-2">
            <Button v-for="category in categories" :key="category.id"
                @click="selectCategory(category.id)"
                :class="{ '!bg-orange-100 !text-orange-600 !border-orange-200': selectedCategoryId === category.id }"
                class="p-button-secondary p-button-outlined p-button-sm whitespace-nowrap">
                <span class="mr-2">{{ category.name }}</span>
                <Badge :value="category.count" 
                       :severity="selectedCategoryId === category.id ? 'warning' : 'secondary'"></Badge>
            </Button>
        </div>
    </div>
</template>

<style scoped>
.category-scroll-container {
    scrollbar-width: none;
    -ms-overflow-style: none;
    user-select: none; /* Evita que el texto se seleccione al arrastrar */
}

.category-scroll-container::-webkit-scrollbar {
    display: none;
}

.category-scroll-container.grabbing {
    cursor: grabbing;
    cursor: -webkit-grabbing;
}
</style>