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
    <footer class="relative bg-[#0a0a0a] text-white pt-20 pb-10 overflow-hidden font-krub">
        
        <!-- ELEMENTOS DE FONDO (Glow effects) -->
        <div class="absolute top-0 left-0 w-full h-px bg-gradient-to-r from-transparent via-[#F68C0F]/50 to-transparent opacity-50"></div>
        <div class="absolute -top-[200px] left-1/2 -translate-x-1/2 w-[600px] h-[400px] bg-[#F68C0F] opacity-[0.03] blur-[100px] rounded-full pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-6 md:px-12 relative z-10">
            
            <!-- TOP SECTION: LOGO & CTA -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-8 mb-10">
                <div>
                    <!-- Reemplazo de imagen por Texto Estilizado -->
                    <div class="flex items-center gap-3 mb-4">
                        <img 
                            src="/imagesLanding/white_logo.png" 
                            alt="Logo Ezy Ventas" 
                            class="h-16 w-auto object-contain" 
                        />
                    </div>
                    <p class="text-gray-400 max-w-sm leading-relaxed text-sm">
                        La plataforma todo en uno para gestionar, controlar y escalar tu negocio sin complicaciones.
                    </p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-4">
                     <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="px-6 py-3 rounded-full border border-gray-800 bg-gray-900/50 text-white hover:bg-white hover:text-black transition-all duration-300 font-medium text-sm flex items-center gap-2 group">
                        <span>Hablar con Ventas</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 group-hover:translate-x-1 transition-transform"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </a>
                </div>
            </div>

            <!-- MIDDLE SECTION: LINKS GRID -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-10 md:gap-8 border-t border-gray-800/50 pt-10 mb-10">
                
                <!-- Columna 1 -->
                <div class="space-y-6">
                    <h4 class="text-md font-semibold text-white uppercase tracking-wider">Producto</h4>
                    <ul class="space-y-4 text-md text-gray-400">
                        <li><button @click="scrollToElement('features')" class="hover:text-[#F68C0F] transition-colors">Funcionalidades</button></li>
                        <li><button @click="scrollToElement('prices')" class="hover:text-[#F68C0F] transition-colors">Planes y Precios</button></li>
                        <li><span class="text-gray-600 cursor-not-allowed">Ezy Restaurant <span class="text-[12px] bg-gray-800 text-gray-300 px-1.5 py-0.5 rounded ml-1">Pronto</span></span></li>
                    </ul>
                </div>

                <!-- Columna 2 -->
                <div class="space-y-6">
                    <h4 class="text-md font-semibold text-white uppercase tracking-wider">Recursos</h4>
                    <ul class="space-y-4 text-md text-gray-400">
                        <li><button @click="scrollToElement('faq')" class="hover:text-[#F68C0F] transition-colors">Preguntas Frecuentes</button></li>
                        <!-- <li><a href="#" class="hover:text-[#F68C0F] transition-colors">Centro de Ayuda</a></li> -->
                        <!-- <li><a href="#" class="hover:text-[#F68C0F] transition-colors">Blog de Negocios</a></li> -->
                        <!-- <li><a href="#" class="hover:text-[#F68C0F] transition-colors">Guías de uso</a></li> -->
                    </ul>
                </div>

                <!-- Columna 3 -->
                <div class="space-y-6">
                    <h4 class="text-md font-semibold text-white uppercase tracking-wider">Legal</h4>
                    <ul class="space-y-4 text-md text-gray-400">
                        <li><a :href="route('policy.show')" target="_blank" class="hover:text-[#F68C0F] transition-colors">Política de Privacidad</a></li>
                        <li><a :href="route('terms.show')" target="_blank" class="hover:text-[#F68C0F] transition-colors">Términos y Condiciones</a></li>
                    </ul>
                </div>

                <!-- Columna 4 (Social / Info) -->
                <div class="space-y-6">
                    <h4 class="text-md font-semibold text-white uppercase tracking-wider">Contacto</h4>
                    <ul class="space-y-4 text-md text-gray-400">
                        <li class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                            Equipo EzyVentas
                        </li>
                        <li><a href="mailto:notificaciones@ezyventas.com" class="hover:text-[#F68C0F] transition-colors">notificaciones@ezyventas.com</a></li>
                        <li>Zapopan, Jalisco, MX</li>
                    </ul>
                </div>

            </div>

            <!-- BOTTOM SECTION: COPYRIGHT -->
            <div class="flex flex-col md:flex-row justify-between items-center pt-8 border-t border-gray-800/50 gap-4">
                <div class="text-md text-gray-500">
                    &copy; 2026 Ezy Ventas. Todos los derechos reservados.
                </div>
                
                <div class="flex items-center gap-6">
                    <!-- Social Placeholders (Icons) -->
                    <!-- <a href="#" class="text-gray-500 hover:text-white transition-colors">
                        <span class="sr-only">Facebook</span>
                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" clip-rule="evenodd" /></svg>
                    </a>
                    <a href="#" class="text-gray-500 hover:text-white transition-colors">
                        <span class="sr-only">Instagram</span>
                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12.315 2c2.43 0 2.784.013 3.808.06 1.064.049 1.791.218 2.427.465a4.902 4.902 0 011.772 1.153 4.902 4.902 0 011.153 1.772c.247.636.416 1.363.465 2.427.048 1.067.06 1.407.06 4.123v.08c0 2.643-.012 2.987-.06 4.043-.049 1.064-.218 1.791-.465 2.427a4.902 4.902 0 01-1.153 1.772 4.902 4.902 0 01-1.772 1.153c-.636.247-1.363.416-2.427.465-1.067.048-1.407.06-4.123.06h-.08c-2.643 0-2.987-.012-4.043-.06-1.064-.049-1.791-.218-2.427-.465a4.902 4.902 0 01-1.772-1.153 4.902 4.902 0 01-1.153-1.772c-.247-.636-.416-1.363-.465-2.427-.047-1.024-.06-1.379-.06-3.808v-.63c0-2.43.013-2.784.06-3.808.049-1.064.218-1.791.465-2.427a4.902 4.902 0 011.153-1.772A4.902 4.902 0 015.468.99c.636-.247 1.363-.416 2.427-.465C8.901 2.013 9.256 2 11.685 2h.63zm-.081 1.802h-.468c-2.456 0-2.784.011-3.807.058-.975.045-1.504.207-1.857.344-.467.182-.8.398-1.15.748-.35.35-.566.683-.748 1.15-.137.353-.3.882-.344 1.857-.047 1.023-.058 1.351-.058 3.807v.468c0 2.456.011 2.784.058 3.807.045.975.207 1.504.344 1.857.182.466.399.8.748 1.15.35.35.683.566 1.15.748.353.137.882.3 1.857.344 1.054.048 1.37.058 4.041.058h.08c2.597 0 2.917-.01 3.96-.058.976-.045 1.505-.207 1.858-.344.466-.182.8-.398 1.15-.748.35-.35.566-.683.748-1.15.137-.353.3-.882.344-1.857.048-1.055.058-1.37.058-4.041v-.08c0-2.597-.01-2.917-.058-3.96-.045-.976-.207-1.505-.344-1.858a3.097 3.097 0 00-.748-1.15 3.098 3.098 0 00-1.15-.748c-.353-.137-.882-.3-1.857-.344-1.023-.047-1.351-.058-3.807-.058zM12 6.865a5.135 5.135 0 110 10.27 5.135 5.135 0 010-10.27zm0 1.802a3.333 3.333 0 100 6.666 3.333 3.333 0 000-6.666zm5.338-3.205a1.2 1.2 0 110 2.4 1.2 1.2 0 010-2.4z" clip-rule="evenodd" /></svg>
                    </a> -->
                </div>
            </div>
            
            <div class="mt-8 flex justify-center items-center gap-2 opacity-60 grayscale hover:grayscale-0 transition-all duration-500">
                <span class="text-md text-gray-300">Desarrollado con</span>
                 <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 text-red-500 animate-pulse"><path d="m11.645 20.91-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z" /></svg>
                <span class="text-md text-gray-300">por DTW en México</span>
            </div>
        </div>
    </footer>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Krub:wght@200;300;400;500;600;700&display=swap');
.font-krub { font-family: 'Krub', sans-serif; }
</style>