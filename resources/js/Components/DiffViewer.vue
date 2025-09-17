<script setup>
import { computed } from 'vue';
import { diffWords } from 'diff';

const props = defineProps({
    oldValue: String,
    newValue: String,
});

const differences = computed(() => {
    // Si no hay valor antiguo, mostramos todo como añadido
    const oldText = props.oldValue || '';
    const newText = props.newValue || '';
    
    // Usamos una librería para encontrar las diferencias palabra por palabra
    return diffWords(oldText, newText);
});
</script>

<template>
    <div class="prose prose-sm max-w-none border rounded-md p-2 bg-gray-50 dark:bg-gray-800">
        <span v-for="(part, index) in differences" :key="index">
            <del v-if="part.removed" class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">{{ part.value }}</del>
            <ins v-else-if="part.added" class="bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 no-underline">{{ part.value }}</ins>
            <span v-else v-html="part.value"></span>
        </span>
    </div>
</template>