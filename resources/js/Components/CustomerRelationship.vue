<script setup>
import { ref } from 'vue';

const cards = ref([
    {
        id: 1,
        name: 'Ricardo M.',
        image: '/imagesLanding/C3.webp',
        badge: 'Pendiente',
        badgeColor: 'bg-red-50 text-red-600 ring-red-100',
        mainInfo: '-$1,250',
        subInfo: 'Vence hoy',
        actionIcon: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z', // Icono Dinero/Cobrar
        btnClass: 'hover:bg-red-50 text-red-500'
    },
    {
        id: 2,
        name: 'Ana Sofia',
        image: '/imagesLanding/C1.webp',
        badge: 'Apartado',
        badgeColor: 'bg-amber-50 text-amber-600 ring-amber-100',
        mainInfo: '3 Prendas',
        subInfo: '30 mayo',
        actionIcon: 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z', // Icono Ojo/Ver
        btnClass: 'hover:bg-amber-50 text-amber-500'
    },
    {
        id: 3,
        name: 'Elena G.',
        image: '/imagesLanding/C2.webp',
        badge: 'Servicio',
        badgeColor: 'bg-blue-50 text-blue-600 ring-blue-100',
        mainInfo: 'Reparación',
        subInfo: 'Listo',
        actionIcon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', // Icono Check
        btnClass: 'hover:bg-blue-50 text-blue-500'
    }
]);
</script>

<template>
    <div class="w-full flex justify-center py-2 select-none">
        <!-- Lista Vertical de Tarjetas Horizontales -->
        <div class="flex flex-col gap-3 w-full max-w-md px-2 md:px-0">
            
            <div v-for="(card, index) in cards" :key="card.id" 
                 class="group relative flex items-center bg-white border border-gray-100 shadow-sm rounded-2xl p-3 gap-4 transition-all duration-300 hover:shadow-md hover:border-gray-200 hover:-translate-y-1"
                 :style="{ animationDelay: `${index * 0.15}s` }">
                
                <!-- 1. Imagen (Izquierda) -->
                <div class="relative shrink-0">
                    <img :src="card.image" class="w-12 h-12 rounded-full object-cover shadow-sm ring-2 ring-white" alt="Avatar" />
                    <!-- Indicador de estado (punto de color en la imagen) -->
                     <div class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-white rounded-full flex items-center justify-center">
                        <div class="w-2 h-2 rounded-full" :class="card.badgeColor.replace('bg-', 'bg-').replace('text-', '').split(' ')[0].replace('-50', '-500')"></div>
                     </div>
                </div>

                <!-- 2. Información (Centro - Horizontal layout interno) -->
                <div class="flex-1 min-w-0 flex flex-col justify-center">
                    <div class="flex items-center justify-between mb-0.5">
                        <h3 class="text-sm font-bold text-gray-900 truncate mr-2">{{ card.name }}</h3>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 ring-inset uppercase tracking-wider" :class="card.badgeColor">
                            {{ card.badge }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-bold text-gray-700">{{ card.mainInfo }}</span>
                        <span class="text-gray-300">•</span>
                        <span class="text-gray-500 truncate">{{ card.subInfo }}</span>
                    </div>
                </div>

                <!-- 3. Acción (Derecha - Botón Circular) -->
                <button class="w-9 h-9 rounded-full bg-gray-50 flex items-center justify-center transition-colors shrink-0" :class="card.btnClass">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                        <path fill-rule="evenodd" :d="card.actionIcon" clip-rule="evenodd" />
                    </svg>
                </button>

            </div>

        </div>
    </div>
</template>

<style scoped>
/* Animación de entrada suave tipo cascada */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.group {
    animation: slideIn 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
}
</style>