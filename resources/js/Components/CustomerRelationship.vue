<script setup>
import { ref } from 'vue';

const cards = ref([
    {
        id: 1,
        name: 'Ricardo M.',
        image: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80',
        badge: 'Pago pendiente',
        badgeColor: 'bg-red-100 text-red-600',
        mainInfo: '-$1,250.00',
        subInfo: 'Vence hoy',
        action: 'Recordar',
        iconColor: 'text-red-500'
    },
    {
        id: 2,
        name: 'Ana Sofia',
        image: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80',
        badge: 'Apartado listo',
        badgeColor: 'bg-amber-100 text-amber-600',
        mainInfo: '3 Prendas',
        subInfo: 'Listas para entrega',
        action: 'Notificar',
        iconColor: 'text-amber-500'
    },
    {
        id: 3,
        name: 'Elena G.',
        image: 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80',
        badge: 'Servicio',
        badgeColor: 'bg-blue-100 text-blue-600',
        mainInfo: 'Ajuste Bastilla',
        subInfo: 'Agendado: 4:00 PM',
        action: 'Ver detalles',
        iconColor: 'text-blue-500'
    }
]);
</script>

<template>
    <div class="relative w-full py-10 flex items-center justify-center select-none overflow-hidden">
        
        <!-- Fondo Ambiental Sutil (Glows) -->
        <div class="absolute inset-0 flex items-center justify-center z-0 pointer-events-none">
            <div class="absolute w-[600px] h-[300px] bg-white blur-[100px] rounded-full -translate-y-10"></div>
            <div class="absolute w-[500px] h-[300px] bg-white blur-[90px] rounded-full translate-y-10"></div>
        </div>

        <!-- Contenedor Horizontal -->
        <div class="relative z-10 flex flex-col md:flex-row gap-5 items-center justify-center max-w-5xl mx-auto px-4">
            
            <div v-for="(card, index) in cards" :key="card.id" 
                 class="group relative flex flex-col bg-white/70 backdrop-blur-xl border border-white/60 shadow-[0_8px_30px_rgb(0,0,0,0.04)] rounded-[24px] p-4 w-56 transition-all duration-300 hover:-translate-y-2 hover:shadow-[0_20px_40px_rgb(0,0,0,0.08)] ring-1 ring-black/5"
                 :style="{ animationDelay: `${index * 0.15}s` }">
                
                <!-- Header: Avatar + Badge -->
                <div class="flex justify-between items-start mb-3">
                    <img :src="card.image" class="w-12 h-12 rounded-full object-cover shadow-md ring-2 ring-white" alt="Avatar" />
                    <span class="text-[10px] font-semibold px-2 py-1 rounded-full uppercase tracking-wide" :class="card.badgeColor">
                        {{ card.badge }}
                    </span>
                </div>

                <!-- Body: Info Principal -->
                <div class="flex flex-col gap-0.5 mb-4">
                    <h3 class="text-sm font-semibold text-gray-900">{{ card.name }}</h3>
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-lg font-bold text-gray-800 tracking-tight">{{ card.mainInfo }}</span>
                    </div>
                    <p class="text-sm text-gray-400 font-medium">{{ card.subInfo }}</p>
                </div>

                <!-- Footer: Acción -->
                <div class="mt-auto pt-3 border-t border-gray-100 flex items-center justify-between group-hover:opacity-100 transition-opacity">
                    <span class="text-sm font-medium text-gray-500 group-hover:text-gray-800 transition-colors">{{ card.action }}</span>
                    <button class="w-6 h-6 rounded-full bg-white shadow-sm border border-gray-100 flex items-center justify-center transition-transform group-hover:scale-110">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3" :class="card.iconColor">
                            <path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </div>
</template>

<style scoped>
/* Animación suave de entrada al cargar */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.group {
    animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) backwards;
}
</style>