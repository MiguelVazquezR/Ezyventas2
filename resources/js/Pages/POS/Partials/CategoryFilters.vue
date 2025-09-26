<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    categories: Array,
    activeCategoryId: [Number, String, null],
});

const emit = defineEmits(['filter']);

const selectedCategoryId = ref(props.activeCategoryId);

// Sincroniza el estado si la prop cambia desde el padre
watch(() => props.activeCategoryId, (newVal) => {
    selectedCategoryId.value = newVal;
});

const selectCategory = (categoryId) => {
    selectedCategoryId.value = categoryId;
    emit('filter', categoryId);
}

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
    const walk = (x - startX.value) * 1.5;
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
                :outlined="selectedCategoryId !== category.id"
                :severity="selectedCategoryId === category.id ? 'warning' : 'secondary'"
                class="p-button-sm whitespace-nowrap">
                <span class="mr-2">{{ category.name }}</span>
                <!-- CAMBIO: Se usa 'products_count' -->
                <Badge :value="category.products_count" 
                       :severity="selectedCategoryId === category.id ? 'warning' : 'secondary'"></Badge>
            </Button>
        </div>
    </div>
</template>

<style scoped>
.category-scroll-container {
    scrollbar-width: none;
    -ms-overflow-style: none;
    user-select: none;
}

.category-scroll-container::-webkit-scrollbar {
    display: none;
}

.category-scroll-container.grabbing {
    cursor: grabbing;
    cursor: -webkit-grabbing;
}
</style>