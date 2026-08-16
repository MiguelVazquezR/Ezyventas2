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
        <div class="rounded-xl p-2 bg-slate-100/50 dark:bg-neutral-900/50 backdrop-blur-md border border-slate-100 dark:border-neutral-800">
            <p class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 m-0 px-3.5 pt-2 pb-3">
                Secciones
            </p>
            <ul class="space-y-1">
                <li v-for="section in sections" :key="section.id">
                    <button
                        type="button"
                        @click="$emit('scrollTo', section.id)"
                        class="text-left w-full px-3.5 py-2.5 rounded-lg transition-all duration-200 text-sm flex items-center justify-between gap-2"
                        :class="activeSection === section.id
                            ? 'bg-white dark:bg-[#121212] text-slate-900 dark:text-white font-semibold shadow-sm border border-slate-100 dark:border-neutral-800'
                            : 'text-slate-500 hover:text-slate-900 dark:text-neutral-400 dark:hover:text-white'"
                    >
                        <span>{{ section.label }}</span>
                        <span v-if="activeSection === section.id" class="w-1.5 h-1.5 rounded-full bg-black dark:bg-white animate-pulse"></span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>