<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue';

const props = defineProps({
    modelValue: {
        // El valor ahora es un objeto para soportar múltiples tipos
        type: Object,
        default: () => ({ type: 'pattern', value: [] })
    },
    edit: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:modelValue']);

// --- Estado Principal del Componente ---
const isModalVisible = ref(false);
const selectedType = ref(props.modelValue?.type || 'pattern');
const localPassword = ref('');

const lockOptions = ref([
    { label: 'Patrón', value: 'pattern' },
    { label: 'Contraseña', value: 'password' },
]);

// --- Lógica de Retroalimentación (Feedback) ---
const hasValue = computed(() => {
    return props.modelValue && props.modelValue.value && props.modelValue.value.length > 0;
});

const feedbackText = computed(() => {
    const action = hasValue.value ? 'Ver' : 'Agregar';
    const type = selectedType.value === 'pattern' ? 'patrón' : 'contraseña';
    return `${action} ${type}`;
});

// --- Lógica del Modal ---
const openModal = () => {
    if (!props.edit && !hasValue.value) return; // No abrir si no es editable y no hay valor

    if (selectedType.value === 'password' && props.modelValue?.type === 'password') {
        localPassword.value = props.modelValue.value;
    } else {
        localPassword.value = '';
    }

    isModalVisible.value = true;

    // CORRECCIÓN 1: Usar setTimeout para esperar la animación del modal.
    // Esto previene el desfase al dibujar, asegurando que la posición del modal
    // es final antes de calcular las coordenadas de los puntos.
    if (selectedType.value === 'pattern') {
        setTimeout(() => {
            calculateDotPositions();
        }, 150);
    }
};

const saveAndClose = () => {
    if (selectedType.value === 'password') {
        emit('update:modelValue', { type: 'password', value: localPassword.value });
    }
    isModalVisible.value = false;
};

const clearValue = () => {
    if (selectedType.value === 'pattern') {
        clearPattern();
        emit('update:modelValue', { type: 'pattern', value: [] });
    } else {
        localPassword.value = '';
        emit('update:modelValue', { type: 'password', value: '' });
    }
    isModalVisible.value = false;
};


// --- Lógica del Patrón (Adaptada para el Modal) ---
const svgRef = ref(null);
const gridRef = ref(null);
const isDrawing = ref(false);
const path = ref('');
const currentLine = ref('');
const points = ref([]);
const dots = ref(Array(9).fill(null).map((_, i) => ({ id: i + 1, x: 0, y: 0, active: false })));

// CORRECCIÓN 2: Función para identificar el punto de inicio del patrón.
const isStartDot = (dot) => {
    return points.value.length > 0 && points.value[0].id === dot.id;
};

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
        if (distance < 15) return dot;
    }
    return null;
};

const handleStart = (e) => {
    if (!props.edit) return;
    e.preventDefault();
    clearPattern();
    isDrawing.value = true;
    const dot = getDotFromEvent(e);
    if (dot) addPoint(dot);
};

const handleMove = (e) => {
    if (!isDrawing.value || !props.edit) return;
    e.preventDefault();
    const dot = getDotFromEvent(e);
    if (dot && !dot.active) addPoint(dot);

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
    emit('update:modelValue', { type: 'pattern', value: points.value.map(p => p.id) });
};

const addPoint = (dot) => {
    dot.active = true;
    points.value.push(dot);

    if (points.value.length > 1) {
        const prev = points.value[points.value.length - 2];
        path.value += ` M ${prev.x} ${prev.y} L ${dot.x} ${dot.y}`;
    }
};

const clearPattern = () => {
    points.value = [];
    path.value = '';
    dots.value.forEach(d => d.active = false);
};

const drawStaticPath = () => {
    clearPattern();
    const sequence = props.modelValue?.type === 'pattern' ? props.modelValue.value : [];
    if (!sequence || sequence.length === 0) return;

    let staticPath = '';
    for (let i = 0; i < sequence.length; i++) {
        const dot = dots.value.find(d => d.id === sequence[i]);
        if (dot) {
            dot.active = true;
            points.value.push(dot);
            if (i > 0) {
                const prevDot = points.value[i - 1];
                staticPath += ` M ${prevDot.x} ${prevDot.y} L ${dot.x} ${dot.y}`;
            }
        }
    }
    path.value = staticPath;
};

watch(selectedType, (newType) => {
    if (props.modelValue?.type !== newType) {
        emit('update:modelValue', { type: newType, value: newType === 'pattern' ? [] : '' });
    }
});

onMounted(() => {
    window.addEventListener('resize', calculateDotPositions);
});
onUnmounted(() => {
    window.removeEventListener('resize', calculateDotPositions);
});
</script>

<template>
    <div class="flex flex-col items-start w-full">
        <SelectButton v-if="edit" v-model="selectedType" :options="lockOptions" optionLabel="label" optionValue="value"
            aria-labelledby="basic" />

        <Button :label="feedbackText" @click="openModal" text
            :class="[hasValue ? 'text-primary' : 'text-gray-500', 'p-1 mt-1']" />

        <Dialog v-model:visible="isModalVisible" modal
            :header="selectedType === 'pattern' ? 'Establecer Patrón' : 'Establecer Contraseña'" class="w-full max-w-xs">

            <!-- Vista para Patrón -->
            <div v-if="selectedType === 'pattern'">
                <div class="relative w-48 h-48 mx-auto" ref="gridRef">
                    <svg class="absolute top-0 left-0 w-full h-full" ref="svgRef"
                        @mousedown="handleStart" @mousemove="handleMove" @mouseup="handleEnd" @mouseleave="handleEnd"
                        @touchstart.passive="handleStart" @touchmove.passive="handleMove" @touchend.passive="handleEnd">
                        <path :d="path" stroke="var(--p-primary-color)" stroke-width="3" fill="none" />
                        <path :d="currentLine" stroke="var(--p-primary-color)" stroke-width="3" fill="none"
                            stroke-linecap="round" />
                    </svg>
                    <div class="grid grid-cols-3 w-full h-full">
                        <div v-for="dot in dots" :key="dot.id" class="flex items-center justify-center">
                            <!-- CORRECCIÓN 2: Se añaden clases dinámicas para el punto de inicio -->
                            <div class="w-8 h-8 rounded-full border-2 flex items-center justify-center transition-all"
                                :class="{
                                    'bg-primary border-primary': dot.active,
                                    'border-gray-300 dark:border-gray-600': !dot.active,
                                    'border-green-600 bg-green-600 ring-offset-2 ring-offset-white dark:ring-offset-gray-800': isStartDot(dot) && dot.active
                                }">
                                <div v-if="dot.active" class="w-3 h-3 rounded-full bg-primary-contrast"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vista para Contraseña -->
            <div v-if="selectedType === 'password'" class="p-fluid">
                <div class="field">
                    <label for="password">Contraseña</label>
                    <Password id="password" v-model="localPassword" :feedback="false" toggleMask class="w-full mt-1" :disabled="!edit"/>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-between w-full">
                    <Button v-if="edit" label="Limpiar" @click="clearValue" severity="danger" text />
                    <Button v-if="selectedType === 'password' && edit" label="Guardar" @click="saveAndClose" severity="warning" />
                    <Button v-else label="Cerrar" @click="isModalVisible = false" severity="secondary" text />
                </div>
            </template>
        </Dialog>
    </div>
</template>