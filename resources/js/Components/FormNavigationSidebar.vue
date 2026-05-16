<script setup>
defineProps({
    sections: {
        type: Array,
        required: true,
        // Ejemplo de formato esperado: [{ id: 'general', label: 'Información principal' }]
    },
    activeSection: {
        type: String,
        required: true
    }
});

defineEmits(['scrollTo']);
</script>

<template>
    <div class="w-full md:w-1/4 sticky top-24 z-10 hidden md:block">
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border dark:border-gray-700">
            <h5 class="font-bold mb-4 text-gray-700 dark:text-gray-300">Secciones</h5>
            <ul class="space-y-2">
                <li v-for="section in sections" :key="section.id">
                    <button 
                        type="button" 
                        @click="$emit('scrollTo', section.id)" 
                        class="text-left w-full px-3 py-2 rounded-md transition-colors"
                        :class="activeSection === section.id 
                            ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' 
                            : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'"
                    >
                        {{ section.label }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>