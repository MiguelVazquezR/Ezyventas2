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
    <div class="flex items-center pb-2 overflow-x-auto category-scroll-container cursor-grab" ref="scrollContainer"
        @mousedown.prevent="onMouseDown" @mouseleave="onMouseLeaveOrUp" @mouseup="onMouseLeaveOrUp"
        @mousemove="onMouseMove">
        <div class="flex gap-3 px-1 py-1">
            <button v-for="category in categories" :key="category.id" @click="selectCategory(category.id)"
                :class="selectedCategoryId === category.id 
                    ? 'bg-gray-900 dark:bg-white border-gray-900 dark:border-white text-white dark:text-gray-900 shadow-md scale-100' 
                    : 'bg-white dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a] text-gray-600 dark:text-gray-400 hover:border-gray-400 dark:hover:border-gray-500 hover:text-gray-900 dark:hover:text-gray-200 scale-95 hover:scale-100'"
                class="whitespace-nowrap rounded-full px-5 py-2 flex items-center gap-3 transition-all duration-300 cursor-grab active:cursor-grabbing text-sm font-medium border select-none group">
                
                <span class="tracking-wide">{{ category.name }}</span>
                
                <span 
                    :class="selectedCategoryId === category.id 
                        ? 'bg-gray-700 dark:bg-gray-200 text-gray-200 dark:text-gray-700' 
                        : 'bg-gray-100 dark:bg-[#2a2a2a] text-gray-500 dark:text-gray-400 group-hover:bg-gray-200 dark:group-hover:bg-[#3a3a3a]'"
                    class="px-2 py-0.5 rounded-full text-[10px] font-bold transition-colors tracking-widest">
                    {{ category.products_count }}
                </span>
            </button>
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