<script setup>
import { computed } from 'vue';

const props = defineProps({
    session: { type: Object, required: true },
    scaleEnabled: { type: Boolean, default: false },
});

const cameraState = computed(() => {
    if (props.session.status === 'error') return { label: 'Error', tone: 'text-red-500', icon: 'pi pi-exclamation-triangle' };
    if (props.session.status === 'paused') return { label: 'En pausa', tone: 'text-gray-400', icon: 'pi pi-pause' };
    if (props.session.status === 'loading') return { label: 'Cargando', tone: 'text-amber-400', icon: 'pi pi-spin pi-spinner' };
    return { label: 'Activa', tone: 'text-emerald-500', icon: 'pi pi-video' };
});

const modelState = computed(() => {
    if (props.session.status === 'error') return { label: 'Error', tone: 'text-red-500' };
    if (props.session.status === 'loading') return { label: 'Cargando', tone: 'text-amber-400' };
    return { label: 'Listo', tone: 'text-emerald-500' };
});

const elapsed = computed(() => {
    const total = Number(props.session.elapsedSeconds) || 0;
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});

const accuracy = computed(() => {
    const value = props.session.metrics?.accuracy;
    return Number.isFinite(Number(value)) ? `${Number(value).toFixed(1)}%` : '—';
});

const avgLatency = computed(() => {
    const value = props.session.latency ? Number(props.session.latency) : Number(props.session.metrics?.avg_latency_ms);
    return Number.isFinite(value) && value > 0 ? `${Math.round(value)} ms` : '—';
});

const avgSeconds = computed(() => {
    const value = Number(props.session.metrics?.avg_seconds_per_sale);
    return Number.isFinite(value) && value > 0 ? `${value.toFixed(1)} s` : '—';
});

const metrics = computed(() => props.session.metrics || {});
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-2xl border border-gray-200 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] px-3 py-2 text-[11px] shadow-sm mb-3">
        <!-- Estado técnico -->
        <span class="inline-flex items-center gap-1.5 font-bold text-gray-500 dark:text-gray-400">
            <i :class="cameraState.icon" :aria-hidden="true" class="!text-[11px]"></i>
            <span :class="cameraState.tone">Cámara {{ cameraState.label }}</span>
        </span>
        <span class="inline-flex items-center gap-1.5 font-bold text-gray-500 dark:text-gray-400">
            <i class="pi pi-microchip !text-[11px]" aria-hidden="true"></i>
            Modelo <span :class="modelState.tone">{{ modelState.label }}</span>
        </span>
        <span v-if="props.scaleEnabled" class="inline-flex items-center gap-1.5 font-bold text-gray-500 dark:text-gray-400">
            <i class="pi pi-box !text-[11px]" aria-hidden="true"></i>
            Báscula <span class="text-emerald-500">Habilitada</span>
        </span>

        <span class="hidden sm:block h-4 w-px bg-gray-200 dark:bg-[#3a3a3a]" aria-hidden="true"></span>

        <!-- Venta en curso -->
        <span class="text-gray-500 dark:text-gray-400">Detectados <strong class="text-gray-800 dark:text-white">{{ props.session.counters.detected }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Auto <strong class="text-emerald-600 dark:text-emerald-400">{{ props.session.counters.auto_accepted }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Confirmados <strong class="text-amber-600 dark:text-amber-400">{{ props.session.counters.confirmed }}</strong></span>
        <!-- Fase 4: la corrección del cajero es la señal de aprendizaje más valiosa. -->
        <span class="text-gray-500 dark:text-gray-400">Corregidos <strong class="text-sky-600 dark:text-sky-400">{{ props.session.counters.corrected }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Desconocidos <strong class="text-red-600 dark:text-red-400">{{ props.session.counters.unknown }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Tiempo <strong class="text-gray-800 dark:text-white tabular-nums">{{ elapsed }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Latencia <strong class="text-gray-800 dark:text-white">{{ avgLatency }}</strong></span>

        <span class="hidden sm:block h-4 w-px bg-gray-200 dark:bg-[#3a3a3a]" aria-hidden="true"></span>

        <!-- Del día -->
        <span class="text-gray-500 dark:text-gray-400">Precisión <strong class="text-gray-800 dark:text-white">{{ accuracy }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Corregidos hoy <strong class="text-gray-800 dark:text-white">{{ metrics.corrected ?? 0 }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Desconocidos hoy <strong class="text-gray-800 dark:text-white">{{ metrics.unknowns_registered ?? 0 }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Ventas con modo <strong class="text-gray-800 dark:text-white">{{ metrics.sales_with_mode ?? 0 }}</strong></span>
        <span class="text-gray-500 dark:text-gray-400">Promedio <strong class="text-gray-800 dark:text-white">{{ avgSeconds }}</strong></span>
    </div>
</template>
