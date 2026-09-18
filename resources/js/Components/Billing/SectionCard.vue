<script setup>
/**
 * SectionCard — Tesla UI section wrapper.
 *
 * One uniform card used by every section of the invoice form:
 *   bg-white dark:bg-[#121212] border border-slate-100 dark:border-neutral-800
 *   rounded-3xl p-6 md:p-8 shadow-sm space-y-4
 *
 * The header follows the minimal "micro-copy" pattern, now high-contrast:
 *   title    → text-sm font-semibold tracking-wider text-slate-900 dark:text-white uppercase
 *   subtitle → text-xs text-slate-600 dark:text-neutral-400 (lighter than the title)
 *
 * The icon is vertically centered with the title line only (never with the
 * title + subtitle block). The subtitle is indented so it stays aligned with
 * the title text (icon width + gap).
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
        <header class="flex items-start gap-2.5">
            <div class="flex-1 min-w-0">
                <!-- Icon lives with the title row so it centers on the title line only -->
                <div class="flex items-center gap-2.5">
                    <i v-if="icon" :class="['pi', icon, '!text-base text-slate-700 dark:text-neutral-400']"></i>
                    <h2 class="text-sm font-semibold tracking-wider text-slate-800 dark:text-white uppercase m-0">{{ title }}</h2>
                </div>
                <!-- Indent = icon width (16px) + row gap (10px) to align with the title -->
                <p v-if="subtitle" :class="['text-xs text-slate-600 dark:text-neutral-400 mt-1 m-0', icon ? 'pl-[26px]' : '']">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="shrink-0 self-center">
                <slot name="actions" />
            </div>
        </header>

        <slot />
    </section>
</template>
