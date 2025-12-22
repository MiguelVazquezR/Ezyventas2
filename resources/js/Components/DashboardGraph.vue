<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

// --- ESTADO Y DATOS ---
const chartContainer = ref(null);
const hoverX = ref(0);
const hoverY = ref(0);
const chartData = ref([20, 45, 30, 60, 45, 80, 65]); // Datos iniciales
const maxVal = 100;
const pointsCount = 7;

// --- ANIMACIÓN DEL GRÁFICO (Live Data Simulation) ---
let intervalId = null;

const randomizeData = () => {
    // Modificamos ligeramente los datos para que el gráfico "respire"
    const newData = chartData.value.map(val => {
        const change = Math.random() * 20 - 10; // Variación entre -10 y +10
        let newVal = val + change;
        if (newVal > 90) newVal = 90;
        if (newVal < 10) newVal = 10;
        return newVal;
    });
    chartData.value = newData;
};

// --- LOGICA SVG (Curvas Bézier suaves) ---
// Convierte los datos en coordenadas SVG y crea una curva suave
const getPath = (data, width, height) => {
    if (!width || !height) return '';
    
    // Espaciado horizontal
    const stepX = width / (data.length - 1);
    
    // Mapeo de puntos (x, y)
    // Invertimos Y porque en SVG 0 está arriba
    const points = data.map((val, i) => {
        const x = i * stepX;
        const y = height - (val / maxVal) * height;
        return { x, y };
    });

    if (points.length === 0) return '';

    // Construcción del path string (Curva Catmull-Rom o Bézier cúbica simplificada)
    let d = `M ${points[0].x} ${points[0].y}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i];
        const p1 = points[i + 1];
        
        // Puntos de control para suavizar (Control Point X es mitad del camino)
        const cp1x = p0.x + (p1.x - p0.x) / 2;
        const cp1y = p0.y;
        const cp2x = p0.x + (p1.x - p0.x) / 2;
        const cp2y = p1.y;

        d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p1.x} ${p1.y}`;
    }

    return d;
};

// Generamos el área (relleno) cerrando el path abajo
const areaPath = computed(() => {
    const d = getPath(chartData.value, 400, 200); // 400x200 es el viewBox
    if (!d) return '';
    return `${d} L 400 200 L 0 200 Z`;
});

// Generamos la línea (borde)
const linePath = computed(() => {
    return getPath(chartData.value, 400, 200);
});

// --- PARALLAX 3D (Glassmorphism Effect) ---
const handleMouseMove = (e) => {
    if (!chartContainer.value) return;
    const rect = chartContainer.value.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    
    // Calculamos el centro
    const centerX = rect.width / 2;
    const centerY = rect.height / 2;

    // Normalizamos entre -1 y 1
    hoverX.value = (x - centerX) / centerX;
    hoverY.value = (y - centerY) / centerY;
};

const handleMouseLeave = () => {
    // Regresar suavemente al centro
    hoverX.value = 0;
    hoverY.value = 0;
};

// Estilos dinámicos para las tarjetas flotantes
const cardStyle = (depth) => computed(() => ({
    transform: `perspective(1000px) translate3d(${hoverX.value * depth * 20}px, ${hoverY.value * depth * 20}px, 0) rotateX(${-hoverY.value * 5}deg) rotateY(${hoverX.value * 5}deg)`
}));

// --- CICLO DE VIDA ---
onMounted(() => {
    // Iniciar "latido" del gráfico cada 3 segundos
    intervalId = setInterval(randomizeData, 3000);
});

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId);
});
</script>

<template>
    <div 
        ref="chartContainer"
        class="relative w-full h-[350px] md:h-[450px] bg-white rounded-[32px] shadow-[0_30px_60px_-15px_rgba(0,0,0,0.1)] border border-gray-100 overflow-hidden select-none group transition-all duration-500 hover:shadow-[0_40px_80px_-12px_rgba(246,140,15,0.15)]"
        @mousemove="handleMouseMove"
        @mouseleave="handleMouseLeave"
    >
        <!-- FONDO DECORATIVO (GRID) -->
        <div class="absolute inset-0 z-0 opacity-30">
            <div class="absolute top-0 bottom-0 left-10 w-px bg-gray-100"></div>
            <div class="absolute top-0 bottom-0 left-1/2 w-px bg-gray-100"></div>
            <div class="absolute top-0 bottom-0 right-10 w-px bg-gray-100"></div>
            <div class="absolute left-0 right-0 top-10 h-px bg-gray-100"></div>
            <div class="absolute left-0 right-0 top-1/2 h-px bg-gray-100"></div>
            <div class="absolute left-0 right-0 bottom-10 h-px bg-gray-100"></div>
        </div>

        <!-- HEADER DEL DASHBOARD FALSO -->
        <div class="absolute top-0 left-0 right-0 p-6 z-10 flex justify-between items-center bg-white/50 backdrop-blur-sm border-b border-gray-50">
            <div class="flex items-center gap-3">
                <img src="/imagesLanding/ezy-logo-color.webp" class="h-9 w-auto"></img>
            </div>
            <div class="text-xs font-bold text-gray-400 tracking-widest uppercase">Resumen Semanal</div>
        </div>

        <!-- CONTENEDOR GRÁFICO SVG -->
        <div class="absolute inset-x-0 bottom-0 top-16 z-0 flex items-end px-0">
            <svg viewBox="0 0 400 200" preserveAspectRatio="none" class="w-full h-3/4 transition-all duration-[2000ms] ease-in-out">
                <defs>
                    <linearGradient id="chartGradient" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="#F68C0F" stop-opacity="0.2"/>
                        <stop offset="100%" stop-color="#F68C0F" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <!-- Area -->
                <path :d="areaPath" fill="url(#chartGradient)" class="transition-all duration-[2000ms] ease-in-out" />
                <!-- Línea -->
                <path :d="linePath" fill="none" stroke="#F68C0F" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="drop-shadow-lg transition-all duration-[2000ms] ease-in-out" />
                
                <!-- Puntos (Dots) -->
                <!-- Solo dibujamos el último punto para efecto 'live' -->
                 <circle v-if="chartData.length" 
                    :cx="400" 
                    :cy="200 - (chartData[chartData.length-1] / maxVal * 200)" 
                    r="4" 
                    fill="#fff" 
                    stroke="#F68C0F" 
                    stroke-width="3"
                    class="transition-all duration-[2000ms] ease-in-out animate-pulse"
                 />
            </svg>
        </div>

        <!-- FLOATING GLASS CARD 1: VENTAS (Main) -->
        <div 
            class="absolute top-1/4 right-10 md:right-16 z-20"
            :style="cardStyle(1.5).value"
        >
            <div class="glass-card p-5 w-48 rounded-2xl border border-white/60 bg-white/70 backdrop-blur-xl shadow-[0_20px_40px_rgba(0,0,0,0.1)] transition-transform duration-200">
                <div class="flex items-center justify-between mb-2">
                    <div class="p-2 bg-green-100 rounded-lg text-green-600">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" /></svg>
                    </div>
                    <span class="text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded-full">+12%</span>
                </div>
                <p class="text-sm text-gray-500 font-medium">Ventas Totales</p>
                <p class="text-2xl font-black text-gray-800 tracking-tight">$42,500</p>
            </div>
        </div>

        <!-- FLOATING GLASS CARD 2: GASTOS (Bottom Left) -->
        <div 
            class="absolute bottom-10 left-6 md:left-12 z-20"
            :style="cardStyle(0.8).value"
        >
            <div class="glass-card p-4 w-40 rounded-2xl border border-white/60 bg-white/60 backdrop-blur-lg shadow-[0_15px_30px_rgba(0,0,0,0.08)]">
                <div class="flex items-center gap-3 mb-1">
                    <div class="w-2 h-2 rounded-full bg-red-500"></div>
                    <p class="text-xs font-bold text-gray-400 uppercase">Gastos</p>
                </div>
                <p class="text-lg font-bold text-gray-800">$8,240</p>
                <div class="w-full bg-gray-200 h-1.5 rounded-full mt-2 overflow-hidden">
                    <div class="bg-red-500 h-full w-[30%]"></div>
                </div>
            </div>
        </div>

        <!-- FLOATING GLASS CARD 3: PAGOS (Top Left - Small) -->
        <div 
            class="absolute top-24 left-10 md:left-20 z-10"
            :style="cardStyle(0.5).value"
        >
            <div class="glass-card px-4 py-2 rounded-full border border-white/60 bg-white/40 backdrop-blur-md shadow-lg flex items-center gap-3">
                <div class="flex -space-x-2">
                    <div class="w-8 h-8 rounded-full bg-blue-100 border border-white flex items-center justify-center text-blue-600 text-[12px] font-bold">R</div>
                    <div class="w-8 h-8 rounded-full bg-purple-100 border border-white flex items-center justify-center text-purple-600 text-[12px] font-bold">C</div>
                </div>
                <div class="text-sm font-bold text-gray-600">
                    <span class="text-[#F68C0F]">3 Pagos</span> pendientes
                </div>
            </div>
        </div>

    </div>
</template>

<style scoped>
/* Clases utilitarias adicionales para suavidad */
.glass-card {
    will-change: transform;
}
</style>