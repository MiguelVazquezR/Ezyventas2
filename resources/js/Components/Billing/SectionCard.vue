<script setup>
/**
 * SectionCard — Tesla UI section wrapper.
 *
 * One uniform card used by every section of the invoice form:
 *   bg-white dark:bg-[#121212] border border-slate-100 dark:border-neutral-800
 *   rounded-3xl p-6 md:p-8 shadow-sm space-y-4
 *
 * The header follows the minimal "micro-copy" pattern:
 *   text-sm font-semibold tracking-wider text-slate-400 uppercase
 *
 * Slots:
 *   - default: section body
 *   - actions: optional trailing controls (right-aligned in the header)
 */
defineProps({
    id: { type: String, default: '' },
    icon: { type: String, default: '' }, // primeicon class, e.g. 'pi pi-building'
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <section
        :id="id || undefined"
        class="bg-white dark:bg-[#121212] border border-slate-100 dark:border-neutral-800 rounded-3xl p-6 md:p-8 shadow-sm space-y-4"
    >
        <header class="flex items-center gap-2.5">
            <i v-if="icon" :class="['pi', icon, '!text-sm text-slate-400']"></i>
            <div class="flex-1 min-w-0">
                <h2 class="text-sm font-semibold tracking-wider text-slate-400 uppercase m-0">{{ title }}</h2>
                <p v-if="subtitle" class="text-[10px] text-slate-400/70 dark:text-neutral-500 mt-0.5 m-0">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
                <slot name="actions" />
            </div>
        </header>

        <slot />
    </section>
</template>
