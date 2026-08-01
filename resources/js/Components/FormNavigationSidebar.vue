<script setup>
defineProps({
    sections: {
        type: Array,
        required: true,
        // Expected shape: [{ id: 'general', label: 'Información principal' }]
    },
    activeSection: {
        type: String,
        required: true,
    },
});

defineEmits(['scrollTo']);
</script>

<template>
    <div class="w-full md:w-1/4 sticky top-24 z-10 hidden md:block">
        <div class="rounded-3xl border border-zinc-200 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] p-5">
            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-4">
                Secciones
            </p>
            <ul class="space-y-1">
                <li v-for="section in sections" :key="section.id">
                    <button
                        type="button"
                        @click="$emit('scrollTo', section.id)"
                        class="text-left w-full px-3 py-2 rounded-xl transition-colors text-sm"
                        :class="activeSection === section.id
                            ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium'
                            : 'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-zinc-800'"
                    >
                        {{ section.label }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>