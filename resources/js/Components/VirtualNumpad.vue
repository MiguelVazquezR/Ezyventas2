<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { useVirtualNumpad } from '@/Composables/useVirtualNumpad';

const { isVisible, activeLabel, activeMode, anchorRect, close, handleKey } = useVirtualNumpad();

const panelRef = ref(null);
const positionStyle = ref({ top: '50%', left: '50%' });
const arrowClass = ref('');

const PANEL_W = 220; // approximate panel width
const PANEL_H = 260; // approximate panel height
const GAP = 8; // gap from anchor element

function recalcPosition() {
    if (!anchorRect.value) return;

    const rect = anchorRect.value;
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    // Horizontal: center on anchor, clamp to viewport
    let left = rect.left + rect.width / 2 - PANEL_W / 2;
    left = Math.max(GAP, Math.min(left, vw - PANEL_W - GAP));

    // Vertical: prefer below, fallback above
    const spaceBelow = vh - rect.bottom - GAP;
    const spaceAbove = rect.top - GAP;
    let top;
    let arrow;

    if (spaceBelow >= PANEL_H || spaceBelow >= spaceAbove) {
        // Show below
        top = rect.bottom + GAP;
        arrow = 'above';
    } else {
        // Show above
        top = rect.top - PANEL_H - GAP;
        arrow = 'below';
    }

    positionStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
    };
    arrowClass.value = arrow;
}

watch(isVisible, (val) => {
    if (val) {
        // Delay to let DOM settle
        requestAnimationFrame(() => recalcPosition());
    }
});

// Recalculate on scroll/resize
onMounted(() => {
    window.addEventListener('scroll', recalcPosition, true);
    window.addEventListener('resize', recalcPosition);
});
onUnmounted(() => {
    window.removeEventListener('scroll', recalcPosition, true);
    window.removeEventListener('resize', recalcPosition);
});

const keys = [
    ['7', '8', '9'],
    ['4', '5', '6'],
    ['1', '2', '3'],
    ['0', '.', '00'],
];

// pointerdown.prevent stops the blur from firing on the input before we process the key
const onKeyDown = (key) => {
    handleKey(key);
};
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop to capture outside clicks -->
        <div
            v-if="isVisible"
            class="fixed inset-0 z-[9998]"
            @click="close"
        />

        <Transition name="numpad-pop">
            <div
                v-if="isVisible"
                ref="panelRef"
                class="fixed z-[9999] select-none"
                :style="positionStyle"
            >
                <!-- Arrow pointer -->
                <div
                    v-if="arrowClass"
                    class="absolute left-1/2 -translate-x-1/2 w-0 h-0 border-l-[6px] border-r-[6px] border-l-transparent border-r-transparent"
                    :class="arrowClass === 'above'
                        ? 'border-b-[6px] border-b-white dark:border-b-[#1a1a1a] -top-[6px]'
                        : 'border-t-[6px] border-t-white dark:border-t-[#1a1a1a] -bottom-[6px]'"
                />

                <!-- mousedown.prevent on the card stops blur from firing on the input -->
                <div
                    @mousedown.prevent
                    class="bg-white dark:bg-[#1a1a1a] border border-gray-200 dark:border-[#3a3a3a] rounded-2xl shadow-2xl overflow-hidden w-[220px]"
                >
                    <!-- Compact header -->
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100 dark:border-[#2a2a2a]">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <span class="text-[9px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest truncate m-0">
                                {{ activeLabel || 'Valor' }}
                            </span>
                        </div>
                        <button
                            @click="close"
                            class="w-6 h-6 rounded-full flex items-center justify-center bg-transparent border-none cursor-pointer text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-[#232323] transition-colors"
                        >
                            <i class="pi pi-times !text-[10px]" />
                        </button>
                    </div>

                    <!-- Compact numpad grid -->
                    <div class="p-2">
                        <div class="grid grid-cols-3 gap-1.5">
                            <button
                                v-for="key in keys.flat()"
                                :key="key"
                                @click="onKeyDown(key)"
                                class="flex items-center justify-center h-9 rounded-xl text-sm font-medium tracking-tight transition-all duration-100 active:scale-95 cursor-pointer border-none outline-none select-none
                                    bg-gray-100 dark:bg-[#232323] text-gray-900 dark:text-white
                                    hover:bg-gray-200 dark:hover:bg-[#2a2a2a]
                                    focus-visible:ring-2 focus-visible:ring-primary-500/50"
                            >
                                {{ key }}
                            </button>
                        </div>

                        <!-- Action row -->
                        <div class="grid grid-cols-2 gap-1.5 mt-1.5">
                            <button
                                @click="onKeyDown('clear')"
                                class="flex items-center justify-center h-8 rounded-xl text-[10px] uppercase tracking-widest font-bold transition-all duration-100 active:scale-95 cursor-pointer border-none outline-none select-none
                                    bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400
                                    hover:bg-red-100 dark:hover:bg-red-900/40"
                            >
                                C
                            </button>
                            <button
                                @click="onKeyDown('backspace')"
                                class="flex items-center justify-center h-8 rounded-xl text-sm transition-all duration-100 active:scale-95 cursor-pointer border-none outline-none select-none
                                    bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400
                                    hover:bg-amber-100 dark:hover:bg-amber-900/40"
                            >
                                <i class="pi pi-delete-left !text-sm" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.numpad-pop-enter-active {
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
}
.numpad-pop-leave-active {
    transition: all 0.12s cubic-bezier(0.4, 0, 1, 1);
}
.numpad-pop-enter-from {
    transform: scale(0.9);
    opacity: 0;
}
.numpad-pop-leave-to {
    transform: scale(0.9);
    opacity: 0;
}
</style>
