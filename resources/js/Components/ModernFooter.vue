<script setup>
import { Link } from '@inertiajs/vue3';

// Función suave para scroll
const scrollToElement = (id) => {
    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
    }
};
</script>

<template>
    <footer class="relative bg-[#050505] text-white pt-16 pb-8 overflow-hidden font-krub border-t border-white/5">
        
        <!-- ELEMENTOS DE FONDO (Glow effects) -->
        <div class="absolute top-0 left-1/4 w-1/2 h-px bg-gradient-to-r from-transparent via-[#F68C0F]/40 to-transparent"></div>
        <div class="absolute -top-[300px] left-1/2 -translate-x-1/2 w-[600px] h-[500px] bg-[#F68C0F] opacity-[0.03] blur-[120px] rounded-full pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-6 md:px-12 relative z-10">
            
            <!-- TOP SECTION: LOGO & CTA -->
            <div class="flex flex-col md:flex-row justify-between items-center md:items-start gap-6 mb-8 text-center md:text-left">
                <div class="flex flex-col items-center md:items-start">
                    <!-- Logo -->
                    <div class="mb-4">
                        <img 
                            src="/imagesLanding/white_logo.png" 
                            alt="Logo Ezy Ventas" 
                            class="h-16 md:h-12 w-auto object-contain opacity-100 hover:opacity-100 transition-opacity" 
                        />
                    </div>
                    <p class="text-gray-400 max-w-sm leading-relaxed text-sm md:text-base font-light">
                        La plataforma todo en uno para gestionar, controlar y escalar tu negocio sin complicaciones.
                    </p>
                </div>
                
                <div>
                     <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="px-8 py-3.5 rounded-full bg-white text-black hover:bg-gray-100 transition-all duration-300 font-bold text-sm flex items-center justify-center gap-2 group shadow-lg hover:shadow-xl hover:-translate-y-0.5">
                        <span>Hablar con Ventas</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 group-hover:translate-x-1 transition-transform"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </a>
                </div>
            </div>

            <!-- MIDDLE SECTION: LINKS GRID -->
            <!-- Mobile: 1 columna centrada (o alineada a la izq con padding) | Tablet: 2 cols | Desktop: 4 cols -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-10 md:gap-8 border-t border-white/5 pt-12 mb-12">
                
                <!-- Columna 1 -->
                <div class="flex flex-col items-center md:items-start space-y-0 text-center md:text-left">
                    <h4 class="text-sm font-bold text-white uppercase tracking-widest">Producto</h4>
                    <ul class="space-y-3 text-sm text-gray-400 font-medium">
                        <li><button @click="scrollToElement('features')" class="hover:text-[#F68C0F] transition-colors duration-200">Funcionalidades</button></li>
                        <li><button @click="scrollToElement('prices')" class="hover:text-[#F68C0F] transition-colors duration-200">Planes y Precios</button></li>
                        <li><span class="text-gray-600 cursor-not-allowed flex items-center gap-2 justify-center md:justify-start">Ezy Restaurant <span class="text-[10px] bg-white/10 text-white/70 px-1.5 py-0.5 rounded border border-white/5">Pronto</span></span></li>
                    </ul>
                </div>

                <!-- Columna 2 -->
                <div class="flex flex-col items-center md:items-start space-y-0 text-center md:text-left">
                    <h4 class="text-sm font-bold text-white uppercase tracking-widest">Recursos</h4>
                    <ul class="space-y-3 text-sm text-gray-400 font-medium">
                        <li><button @click="scrollToElement('faq')" class="hover:text-[#F68C0F] transition-colors duration-200">Preguntas Frecuentes</button></li>
                        <!-- <li><a href="#" class="hover:text-[#F68C0F] transition-colors">Centro de Ayuda</a></li> -->
                    </ul>
                </div>

                <!-- Columna 3 -->
                <div class="flex flex-col items-center md:items-start space-y-0 text-center md:text-left">
                    <h4 class="text-sm font-bold text-white uppercase tracking-widest">Legal</h4>
                    <ul class="space-y-3 text-sm text-gray-400 font-medium">
                        <li><a :href="route('policy.show')" target="_blank" class="hover:text-[#F68C0F] transition-colors duration-200">Política de Privacidad</a></li>
                        <li><a :href="route('terms.show')" target="_blank" class="hover:text-[#F68C0F] transition-colors duration-200">Términos y Condiciones</a></li>
                    </ul>
                </div>

                <!-- Columna 4 (Contacto) -->
                <div class="flex flex-col items-center md:items-start space-y-0 text-center md:text-left">
                    <h4 class="text-sm font-bold text-white uppercase tracking-widest">Contacto</h4>
                    <ul class="space-y-3 text-sm text-gray-400 font-medium">
                        <li class="flex items-center gap-2 justify-center md:justify-start">
                            <span class="relative flex h-2 w-2">
                              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                              <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                            </span>
                            <span>Soporte Activo</span>
                        </li>
                        <li><a href="mailto:notificaciones@ezyventas.com" class="hover:text-[#F68C0F] transition-colors duration-200 text-white">notificaciones@ezyventas.com</a></li>
                        <li class="text-gray-500">Zapopan, Jalisco, MX</li>
                    </ul>
                </div>

            </div>

            <!-- BOTTOM SECTION: COPYRIGHT -->
            <div class="flex flex-col md:flex-row justify-between items-center pt-4 border-t border-white/5 gap-6">
                <div class="text-md text-gray-500 font-medium text-center md:text-left">
                    &copy; {{ new Date().getFullYear() }} Ezy Ventas. Todos los derechos reservados.
                </div>
                
                <div class="flex justify-center items-center gap-2 opacity-50 grayscale hover:grayscale-0 transition-all duration-500 hover:opacity-100">
                    <span class="text-md text-gray-400 font-medium">Hecho con</span>
                     <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-red-500 animate-pulse"><path d="m11.645 20.91-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z" /></svg>
                    <span class="text-md text-gray-400 font-medium">por</span>
                    <a href="https://dtw.com.mx" target="_blank" rel="noopener noreferrer" class="hover:opacity-80 transition-opacity flex items-center">
                        <img src="/imagesLanding/DTW_logo_blanco.png" alt="DTW" class="h-6 w-auto object-contain" />
                    </a>
                </div>
            </div>
        </div>
    </footer>
</template>

<style scoped>
/* Asegúrate de que la fuente Krub esté cargada en el layout principal o aquí */
@import url('https://fonts.googleapis.com/css2?family=Krub:wght@200;300;400;500;600;700&display=swap');
.font-krub { font-family: 'Krub', sans-serif; }
</style>