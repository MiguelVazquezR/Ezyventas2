<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue';

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({ type: 'pattern', value: [] })
    },
    edit: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:modelValue']);

const isModalVisible = ref(false);
const selectedType = ref(props.modelValue?.type || 'pattern');
const localPassword = ref('');

const lockOptions = ref([
    { label: 'Patrón', value: 'pattern' },
    { label: 'Contraseña', value: 'password' },
]);

const hasValue = computed(() => {
    return props.modelValue && props.modelValue.value && props.modelValue.value.length > 0;
});

const feedbackText = computed(() => {
    if (!props.edit) {
        return hasValue.value ? 'Ver ' + (props.modelValue.type === 'pattern' ? 'patrón' : 'contraseña') : 'No establecido';
    }
    const action = hasValue.value ? 'Editar' : 'Agregar';
    const type = selectedType.value === 'pattern' ? 'patrón' : 'contraseña';
    return `${action} ${type}`;
});

const openModal = () => {
    if (!props.edit && !hasValue.value) return;

    if (selectedType.value === 'password' && props.modelValue?.type === 'password') {
        localPassword.value = props.modelValue.value;
    } else {
        localPassword.value = '';
    }

    isModalVisible.value = true;

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
};

const svgRef = ref(null);
const gridRef = ref(null);
const isDrawing = ref(false);
const path = ref('');
const currentLine = ref('');
const points = ref([]);
const dots = ref(Array(9).fill(null).map((_, i) => ({ id: i + 1, x: 0, y: 0, active: false })));

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
        if (distance < 20) return dot;
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
    let localPointsForPath = [];
    for (let i = 0; i < sequence.length; i++) {
        const sequenceId = parseInt(sequence[i], 10);
        const dot = dots.value.find(d => d.id === sequenceId);
        
        if (dot) {
            dot.active = true;
            points.value.push(dot);
            localPointsForPath.push(dot);
            if (i > 0) {
                const prevDot = localPointsForPath[i - 1];
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
            :class="[hasValue ? 'text-primary' : 'text-gray-500', 'p-1 mt-1']" :disabled="!edit && !hasValue"/>

        <Dialog v-model:visible="isModalVisible" modal
            :header="selectedType === 'pattern' ? 'Establecer Patrón' : 'Establecer Contraseña'" class="w-full max-w-xs">

            <div v-if="selectedType === 'pattern'">
                <!-- Texto explicativo añadido -->
                <p v-if="edit" class="text-sm text-center text-gray-500 dark:text-gray-400 mb-4">
                    Dibuja un patrón conectando los puntos sin soltar el clic o despegar el dedo de la pantalla.
                </p>
                <div class="relative w-48 h-48 mx-auto" ref="gridRef">
                    <svg class="absolute top-0 left-0 w-full h-full" ref="svgRef"
                        @mousedown="handleStart" @mousemove="handleMove" @mouseup="handleEnd" @mouseleave="handleEnd"
                        @touchstart="handleStart" @touchmove="handleMove" @touchend="handleEnd">
                        <path :d="path" stroke="var(--p-primary-color)" stroke-width="3" fill="none" />
                        <path :d="currentLine" stroke="var(--p-primary-color)" stroke-width="3" fill="none"
                            stroke-linecap="round" />
                    </svg>
                    <div class="grid grid-cols-3 w-full h-full pointer-events-none">
                        <div v-for="dot in dots" :key="dot.id" class="flex items-center justify-center">
                            <div class="w-8 h-8 rounded-full border-2 flex items-center justify-center transition-all"
                                :class="[
                                    !dot.active ? 'border-gray-300 dark:border-gray-600' :
                                    isStartDot(dot) ? 'border-green-500 bg-green-500' :
                                    'bg-primary border-primary'
                                ]">
                                <div v-if="dot.active" class="w-3 h-3 rounded-full"
                                     :class="isStartDot(dot) ? 'bg-green-100' : 'bg-primary-contrast'">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="selectedType === 'password'" class="p-fluid">
                <div class="field">
                    <label for="password">Contraseña</label>
                    <Password id="password" v-model="localPassword" :feedback="false" toggleMask class="w-full mt-1" :disabled="!edit"/>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-between w-full">
                    <Button v-if="edit" label="Limpiar" @click="clearValue" severity="danger" text />
                    <div v-else class="w-0"></div>
                    
                    <Button v-if="selectedType === 'password' && edit" label="Guardar" @click="saveAndClose" severity="warning" />
                    <Button v-else label="Cerrar" @click="isModalVisible = false" severity="secondary" text />
                </div>
            </template>
        </Dialog>
    </div>
</template>