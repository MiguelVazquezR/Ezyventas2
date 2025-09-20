<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => []
    },
    edit: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:modelValue']);

const svgRef = ref(null);
const gridRef = ref(null);
const isDrawing = ref(false);
const path = ref('');
const currentLine = ref('');
const points = ref([]);
const dots = ref(Array(9).fill(null).map((_, i) => ({ id: i + 1, x: 0, y: 0, active: false })));

const calculateDotPositions = () => {
    if (!gridRef.value) return;
    const rect = gridRef.value.getBoundingClientRect();
    const size = rect.width / 3;
    dots.value.forEach((dot, i) => {
        dot.x = (i % 3) * size + size / 2;
        dot.y = Math.floor(i / 3) * size + size / 2;
    });
    drawStaticPath();
};

const getDotFromEvent = (e) => {
    const touch = e.touches ? e.touches[0] : e;
    const rect = svgRef.value.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;
    
    for (const dot of dots.value) {
        const distance = Math.sqrt(Math.pow(dot.x - x, 2) + Math.pow(dot.y - y, 2));
        if (distance < 15) { // 15px radius for touch target
            return dot;
        }
    }
    return null;
};

const handleStart = (e) => {
    if (!props.edit) return;
    e.preventDefault();
    clearPattern();
    isDrawing.value = true;
    const dot = getDotFromEvent(e);
    if (dot) {
        addPoint(dot);
    }
};

const handleMove = (e) => {
    if (!isDrawing.value || !props.edit) return;
    e.preventDefault();
    const dot = getDotFromEvent(e);
    if (dot && !dot.active) {
        addPoint(dot);
    }
    
    const touch = e.touches ? e.touches[0] : e;
    const rect = svgRef.value.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;
    const lastPoint = points.value[points.value.length - 1];
    if (lastPoint) {
        currentLine.value = `M ${lastPoint.x} ${lastPoint.y} L ${x} ${y}`;
    }
};

const handleEnd = () => {
    if (!props.edit) return;
    isDrawing.value = false;
    currentLine.value = '';
};

const addPoint = (dot) => {
    dot.active = true;
    points.value.push(dot);
    
    if (points.value.length > 1) {
        const prev = points.value[points.value.length - 2];
        path.value += ` M ${prev.x} ${prev.y} L ${dot.x} ${dot.y}`;
    }
    emit('update:modelValue', points.value.map(p => p.id));
};

const clearPattern = () => {
    if (!props.edit) return;
    points.value = [];
    path.value = '';
    dots.value.forEach(d => d.active = false);
    emit('update:modelValue', []);
};

const drawStaticPath = () => {
    if (props.edit || !props.modelValue || props.modelValue.length === 0) return;
    const sequence = props.modelValue;
    let staticPath = '';
    for (let i = 0; i < sequence.length; i++) {
        const dot = dots.value.find(d => d.id === sequence[i]);
        if (dot) {
            dot.active = true;
            points.value.push(dot);
            if (i > 0) {
                const prevDot = points.value[i-1];
                staticPath += ` M ${prevDot.x} ${prevDot.y} L ${dot.x} ${dot.y}`;
            }
        }
    }
    path.value = staticPath;
};


onMounted(() => {
    calculateDotPositions();
    window.addEventListener('resize', calculateDotPositions);
});

onUnmounted(() => {
    window.removeEventListener('resize', calculateDotPositions);
});

</script>

<template>
    <div>
        <div class="relative w-48 h-48 mx-auto" ref="gridRef">
            <svg class="absolute top-0 left-0 w-full h-full" ref="svgRef"
                 @mousedown="handleStart" @mousemove="handleMove" @mouseup="handleEnd" @mouseleave="handleEnd"
                 @touchstart.passive="handleStart" @touchmove.passive="handleMove" @touchend.passive="handleEnd">
                <path :d="path" stroke="var(--p-primary-color)" stroke-width="3" fill="none" />
                <path :d="currentLine" stroke="var(--p-primary-color)" stroke-width="3" fill="none" stroke-linecap="round" />
            </svg>
            <div class="grid grid-cols-3 w-full h-full">
                <div v-for="dot in dots" :key="dot.id" class="flex items-center justify-center">
                     <div class="w-8 h-8 rounded-full border-2 flex items-center justify-center transition-colors"
                          :class="dot.active ? 'bg-primary border-primary' : 'border-gray-300 dark:border-gray-600'">
                        <div v-if="dot.active" class="w-3 h-3 rounded-full bg-primary-contrast"></div>
                    </div>
                </div>
            </div>
        </div>
        <Button v-if="edit" @click="clearPattern" label="Limpiar patrón" severity="danger" text class="w-full mt-2" />
    </div>
</template>