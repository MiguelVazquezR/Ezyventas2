<script setup>
import { computed } from 'vue';
import { diffWords } from 'diff';

const props = defineProps({
    oldValue: [String, Number, Object, Array],
    newValue: [String, Number, Object, Array],
});

// Función para convertir cualquier dato a texto legible antes de comparar
const processValue = (val) => {
    if (val === null || val === undefined) return '';
    if (typeof val === 'object') {
        try {
            // Si es objeto/array, lo convertimos a JSON bonito
            return JSON.stringify(val, null, 2); 
        } catch (e) {
            return String(val);
        }
    }
    return String(val);
};

const differences = computed(() => {
    const oldText = processValue(props.oldValue);
    const newText = processValue(props.newValue);
    
    return diffWords(oldText, newText);
});
</script>

<template>
    <div class="diff-viewer bg-gray-50 dark:bg-gray-900 rounded-md border border-gray-200 dark:border-gray-700 font-mono text-sm shadow-inner overflow-hidden">
        <div class="p-3 whitespace-pre-wrap break-words leading-relaxed text-gray-700 dark:text-gray-300">
            <template v-for="(part, index) in differences" :key="index">
                <!-- Texto Eliminado -->
                <span 
                    v-if="part.removed" 
                    class="bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 px-0.5 rounded-sm line-through decoration-red-400/50 opacity-80 mx-0.5 select-none"
                    title="Valor anterior"
                >{{ part.value }}</span>
                
                <!-- Texto Añadido -->
                <span 
                    v-else-if="part.added" 
                    class="bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300 px-1 rounded-sm border-b-2 border-green-200 dark:border-green-800 font-medium"
                    title="Nuevo valor"
                >{{ part.value }}</span>
                
                <!-- Texto sin cambios -->
                <span 
                    v-else 
                    class="opacity-60"
                >{{ part.value }}</span>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Ajuste para que las palabras largas no rompan el layout */
.diff-viewer {
    max-width: 100%;
    overflow-x: auto;
}
</style>