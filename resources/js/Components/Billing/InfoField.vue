<script setup>
/**
 * InfoField — Tesla UI informational display (read-only).
 *
 * A soft-purple chip that reads as data, not as an input. The micro-copy
 * label lives INSIDE the chip (top row) and the value wraps below it, so
 * long values (e.g. razón social) are always fully visible and the chip
 * grows vertically instead of truncating.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '' },
    icon: { type: String, default: '' }, // primeicon class, e.g. 'pi pi-id-card'
    hint: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col gap-1.5 min-w-0 h-full">
        <div class="flex flex-col gap-1 w-full flex-1 min-h-[52px] px-3.5 py-2.5 rounded-xl bg-violet-50 dark:bg-violet-500/10 border border-violet-100 dark:border-violet-500/20 select-none">
            <div class="flex items-center gap-1.5 min-w-0">
                <i v-if="icon" :class="['pi', icon, '!text-[10px] text-violet-500 dark:text-violet-400 shrink-0']"></i>
                <span class="text-[9px] uppercase tracking-widest font-bold text-violet-500/80 dark:text-violet-400/80 m-0 truncate">{{ label }}</span>
            </div>
            <span class="text-sm font-medium leading-snug text-violet-700 dark:text-violet-300 break-words">{{ value || '—' }}</span>
        </div>
        <p v-if="hint" class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">{{ hint }}</p>
    </div>
</template>
