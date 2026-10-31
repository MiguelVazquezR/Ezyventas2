<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, computed, onMounted, onUnmounted } from 'vue';
import FrutigerButton from '@/Components/FrutigerButton.vue';

// Below-the-fold components loaded asynchronously
const DashboardGraph = defineAsyncComponent(() => import('@/Components/DashboardGraph.vue'));
const CustomerRelationship = defineAsyncComponent(() => import('@/Components/CustomerRelationship.vue'));
const ModernFooter = defineAsyncComponent(() => import('@/Components/ModernFooter.vue'));

import AOS from 'aos';
import 'aos/dist/aos.css';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    laravelVersion: String,
    phpVersion: String,
});

// --- NAVBAR LOGIC ---
const isNavVisible = ref(true);
const isScrolled = ref(false);

// --- VIDEO SPOT (CLICK-TO-PLAY: el src solo se asigna al primer clic) ---
const videoEl = ref(null);
const hasVideoStarted = ref(false);

const playSpotVideo = () => {
    if (!videoEl.value) return;
    if (!hasVideoStarted.value) {
        videoEl.value.src = '/videos/spot_landing.mp4';
        hasVideoStarted.value = true;
    }
    videoEl.value.play().catch(() => {});
};

// --- HERO PARALLAX LOGIC ---
const heroContainer = ref(null);
const mouseX = ref(0);
const mouseY = ref(0);

const handleMouseMove = (event) => {
    if (!heroContainer.value || window.innerWidth < 768) return;
    const { clientX, clientY, currentTarget } = event;
    const { clientWidth, clientHeight } = currentTarget;
    mouseX.value = (clientX / clientWidth) - 0.5;
    mouseY.value = (clientY / clientHeight) - 0.5;
};

// Movimiento del teléfono 
const phoneStyle = {
    transform: computed(() => `perspective(1000px) rotateY(${mouseX.value * 3}deg) rotateX(${-mouseY.value * 3}deg) translateZ(0)`).value
};

// Movimiento del Texto de Fondo 
const textBackStyle = computed(() => ({
    transform: `translate(${mouseX.value * -15}px, ${mouseY.value * -15}px)`
}));

// Movimiento de los widgets
const floatingWidgetStyle = (factorX, factorY) => {
    return {
        transform: `translate(${mouseX.value * factorX}px, ${mouseY.value * factorY}px)`
    };
};

const handleScroll = () => {
    isScrolled.value = window.scrollY > 50;
};

const setupIntersectionObserver = () => {
    const observer = new IntersectionObserver(
        ([entry]) => { isNavVisible.value = !entry.isIntersecting; },
        { root: null, threshold: 0, rootMargin: "0px 0px -50px 0px" }
    );
};

// --- FAQ LOGIC ---
const faqs = ref([
    { question: '¿Ezy Ventas es gratis?', answer: 'Sí. Gracias a las alianzas estratégicas que hemos construido, hoy tienes acceso ilimitado a todas las herramientas y módulos activos del sistema sin costo de suscripción. No pagas mensualidades ni anualidades.', open: false },
    { question: '¿Necesito comprar equipo especial para usar Ezy Ventas?', answer: 'No. Ezy Ventas funciona directamente en la nube. Puedes usar la computadora, tablet o incluso el celular que ya tienes. Si cuentas con lectores de código de barras o impresoras térmicas estándar, también son compatibles.', open: false },
    { question: 'Tengo varias sucursales, ¿puedo ver todo junto?', answer: 'Sí. Nuestra arquitectura centralizada te permite ver el rendimiento, inventarios y cortes de caja de todas tus sucursales en tiempo real desde una sola cuenta de administrador.', open: false },
    { question: '¿Hay plazos forzosos o costos de instalación?', answer: 'No. Ezy Ventas no tiene costo de instalación, ni plazos forzosos, ni letras chiquitas. No hay suscripción que cancelar y no necesitas tarjeta para comenzar.', open: false }
]);

const toggleFaq = (index) => {
    faqs.value[index].open = !faqs.value[index].open;
};

// --- DATOS MOCK PARA INVENTARIO INTERACTIVO (STOCK REAL AGREGADO) ---
const inventoryMock = [
    { name: 'Tenis', pieces: 124, icon: '👟', hoverClass: 'hover:shadow-green-200 hover:border-green-300', dotClass: 'bg-green-500' },
    { name: 'Camiseta Basic', pieces: 42, icon: '👕', hoverClass: 'hover:shadow-yellow-200 hover:border-yellow-300', dotClass: 'bg-yellow-500' },
    { name: 'Gorra', pieces: 8, icon: '🧢', hoverClass: 'hover:shadow-red-200 hover:border-red-300', dotClass: 'bg-red-500' },
    { name: 'Jeans Slim', pieces: 85, icon: '👖', hoverClass: 'hover:shadow-green-200 hover:border-green-300', dotClass: 'bg-green-500' },
    { name: 'Calcetines', pieces: 200, icon: '🧦', hoverClass: 'hover:shadow-green-200 hover:border-green-300', dotClass: 'bg-green-500' },
    { name: 'Bufanda', pieces: 0, icon: '🧣', hoverClass: 'hover:shadow-red-200 hover:border-red-300', dotClass: 'bg-red-500' },
];

// Scroll suave
const scrollToElement = (id) => {
    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
    }
};

onMounted(() => {
    AOS.init();
    window.addEventListener('scroll', handleScroll);
    setupIntersectionObserver(); 
});

onUnmounted(() => {
    window.removeEventListener('scroll', handleScroll);
});

const businessTypes = [
    { title: 'Ropa y Calzado', image: '/imagesLanding/biz-clothing.webp', alt: 'Artículos de ropa', shortDesc: 'Gestiona tallas, colores y temporadas.', fullDesc: 'La solución perfecta para boutiques. Controla tu inventario con variantes complejas sin perder la cabeza.', features: ['Matriz de Tallas y Colores', 'Impresión de etiquetas de código de barras', 'Gestión de cambios y devoluciones', 'Reportes de prendas más vendidas'] },
    { title: 'Supermercados', image: '/imagesLanding/biz-supermarket.webp', alt: 'Abarrotes', shortDesc: 'Venta rápida y control de caducidad.', fullDesc: 'Agilidad en la caja es clave. Escanea códigos rápidamente y gestiona miles de productos sin demoras.', features: ['Venta ultra rápida con scanner', 'Control de inventario mínimo y máximo', 'Venta a granel (bascula)', 'Múltiples cajeros simultáneos'] },
    { title: 'Papelerías', image: '/imagesLanding/biz-stationery.webp', alt: 'Papelería', shortDesc: 'Miles de artículos pequeños bajo control.', fullDesc: 'Desde un lápiz hasta paquetes escolares. Organiza la inmensa variedad de artículos pequeños fácilmente.', features: ['Venta unitaria y por paquete', 'Kits escolares', 'Búsqueda rápida de productos', 'Control de merma y robo hormiga'] },
    { title: 'Ferreterías', image: '/imagesLanding/biz-hardware.webp', alt: 'Herramientas', shortDesc: 'Inventario pesado y venta a granel.', fullDesc: 'Administra inventarios complejos, ventas por metro, kilo o pieza y mantén el control de tu almacén.', features: ['Venta fraccionada (metros, kilos)', 'Precios de mayoreo y menudeo', 'Kits o ventas por paquetes', 'Tickets claros'] },
    { title: 'Servicios', image: '/imagesLanding/biz-services.webp', alt: 'Servicios', shortDesc: 'Reparaciones, citas y mano de obra.', fullDesc: 'No solo productos, vende tu tiempo y experiencia. Gestiona órdenes de servicio y seguimiento.', features: ['Órdenes de servicio', 'Control de estatus', 'Refacciones', 'Mano de obra','Seguimiento por Whatsapp'] },
];

const selectedBusiness = ref(null);
const isModalOpen = ref(false);
const openBusinessModal = (biz) => { selectedBusiness.value = biz; isModalOpen.value = true; document.body.style.overflow = 'hidden'; };
const closeBusinessModal = () => { isModalOpen.value = false; setTimeout(() => { selectedBusiness.value = null; }, 300); document.body.style.overflow = ''; };
</script>

<style scoped>
.font-krub { font-family: 'Krub', sans-serif; }

/* --- ESTILOS GENERALES (Mantenidos) --- */
.hero-wrapper { position: relative; overflow: hidden; background: radial-gradient(circle at 50% 50%, #ffffff 0%, #fff8f0 40%, #fff0e0 100%); display: flex; flex-direction: column; }
.big-title-bg { font-size: clamp(3.5rem, 11vw, 11rem); font-weight: 900; line-height: 0.9; text-align: center; color: rgba(0, 0, 0, 0.04); pointer-events: none; white-space: nowrap; z-index: 10; letter-spacing: 4px; }
.text-gradient-elegant { background: linear-gradient(to bottom right, #000000 30%, #555555 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.premium-widget { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 16px; box-shadow: 0 20px 40px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -3px rgba(0, 0, 0, 0.03), inset 0 0 0 1px rgba(255, 255, 255, 1); border-left: 4px solid #F68C0F; transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 30; }
.premium-widget:hover { transform: scale(1.05) translateY(-5px) !important; box-shadow: 0 25px 50px -12px rgba(246, 140, 15, 0.2); }
.bottom-glass-bar { width: 100%; background: rgba(255, 255, 255, 0.75); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(80px); border-top: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 -10px 40px rgba(0,0,0,0.05); z-index: 40; }
.btn-apple-primary { background: #1a1a1a; color: white; padding: 14px 32px; border-radius: 99px; font-weight: 600; font-size: 1rem; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); box-shadow: 0 10px 20px rgba(0,0,0,0.1); display: inline-flex; align-items: center; justify-content: center; }
.btn-apple-primary:hover { transform: translateY(-2px); background: #000; box-shadow: 0 15px 30px rgba(0,0,0,0.2); }
.btn-apple-secondary { background: white; color: #333; padding: 14px 32px; border-radius: 99px; font-weight: 600; border: 1px solid #ddd; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; }
.btn-apple-secondary:hover { border-color: #F68C0F; color: #F68C0F; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(246, 140, 15, 0.08); }
.navbar-container { width: 100%; z-index: 50; transition: all 0.3s ease-in-out; position: fixed; top: 0; left: 0; }
.navbar-scrolled { background-color: rgba(255, 255, 255, 0.9); backdrop-filter: blur(20px); border-bottom: 1px solid rgba(0,0,0,0.05); padding-top: 0.8rem; padding-bottom: 0.8rem; }
.navbar-hidden { transform: translateY(-100%); opacity: 0; pointer-events: none; }
.btn-login-nav { background: #F68C0F; color: white; padding: 8px 24px; border-radius: 50px; font-weight: 600; font-size: 0.95rem; border: none; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(246, 140, 15, 0.3); }
.btn-login-nav:hover { background: #e57f00; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(246, 140, 15, 0.5); }
.biz-card-bento { background: #ffffff; border-radius: 24px; padding: 24px; position: relative; overflow: hidden; transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1); box-shadow: 0 4px 6px rgba(0,0,0,0.02), 0 0 0 1px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; cursor: pointer; }
.biz-card-bento:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.08); }
.biz-card-bento:hover .biz-img { transform: scale(1.08) rotate(2deg); }
.biz-card-bento:hover .action-arrow { opacity: 1; transform: translateX(0); }
.biz-img-container { height: 140px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
.biz-img { height: 100%; width: auto; object-fit: contain; transition: transform 0.5s ease; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.1)); }
.action-arrow { opacity: 0; transform: translateX(-10px); transition: all 0.3s ease; color: #F68C0F; }
.feature-card-modern { background: #FAFAFA; border-radius: 32px; padding: 40px; height: 100%; position: relative; overflow: hidden; transition: all 0.5s cubic-bezier(0.25, 0.8, 0.25, 1); border: 1px solid rgba(0,0,0,0.03); }
.feature-card-modern:hover { background: white; box-shadow: 0 20px 50px rgba(0,0,0,0.08); transform: translateY(-5px); }

/* --- STOCK INDICATORS (SIMPLES Y ELEGANTES) --- */
.inventory-item-card {
    background: white;
    border: 1px solid #F3F4F6;
    border-radius: 16px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    position: relative;
    overflow: hidden;
}

.inventory-item-card:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 12px 24px rgba(0,0,0,0.06);
    border-color: transparent; /* El borde lo maneja la clase hover específica */
}

/* Efecto Hover Glow sutil */
.hover\:shadow-green-200:hover { box-shadow: 0 10px 30px rgba(74, 222, 128, 0.15); border-color: rgba(74, 222, 128, 0.4); }
.hover\:shadow-yellow-200:hover { box-shadow: 0 10px 30px rgba(250, 204, 21, 0.15); border-color: rgba(250, 204, 21, 0.4); }
.hover\:shadow-red-200:hover { box-shadow: 0 10px 30px rgba(248, 113, 113, 0.15); border-color: rgba(248, 113, 113, 0.4); }

.coming-soon-wrapper { position: relative; overflow: hidden; background: #050505; border-radius: 40px; color: white; box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); }
.coming-soon-glow { position: absolute; bottom: -50%; left: 50%; transform: translateX(-50%); width: 80%; height: 100%; background: radial-gradient(circle, rgba(246, 140, 15, 0.25) 0%, rgba(0,0,0,0) 70%); filter: blur(80px); pointer-events: none; z-index: 0; }
.glass-input-container { background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 99px; padding: 4px; display: flex; transition: all 0.3s ease; }
.glass-input-container:focus-within { background: rgba(255, 255, 255, 0.15); border-color: rgba(246, 140, 15, 0.5); box-shadow: 0 0 20px rgba(246, 140, 15, 0.15); }
.glass-input { background: transparent; border: none; color: white; padding: 12px 24px; outline: none; width: 100%; font-size: 1rem; }
.glass-input::placeholder { color: rgba(255, 255, 255, 0.4); }
.glass-input:focus, 
.glass-input:active {outline: none !important; box-shadow: none !important; border: none !important;}
.btn-glow { background: #F68C0F; color: white; border-radius: 99px; padding: 12px 32px; font-weight: 600; transition: all 0.3s ease; white-space: nowrap; }
.btn-glow:hover { background: #ff9e3d; box-shadow: 0 0 20px rgba(246, 140, 15, 0.4); transform: scale(1.02); }
.modal-enter-active, .modal-leave-active { transition: opacity 0.3s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-content-enter-active { transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.modal-content-leave-active { transition: all 0.2s ease-in; }
.modal-content-enter-from, .modal-content-leave-to { opacity: 0; transform: scale(0.95) translateY(20px); }
.faq-card { background: #ffffff; border: 1px solid rgba(0,0,0,0.05); border-radius: 16px; padding: 0 24px; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); cursor: pointer; }
.faq-card:hover { border-color: rgba(246, 140, 15, 0.3); background: #fffcf5; }
.faq-card.active { background: #ffffff; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-color: transparent; }
.faq-header { display: flex; justify-content: space-between; align-items: center; padding: 24px 0; }
.faq-grid-wrapper { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.4s ease-out, opacity 0.4s ease-out; opacity: 0; }
.faq-grid-wrapper.grid-open { grid-template-rows: 1fr; opacity: 1; }
.faq-inner-content { overflow: hidden; }
.icon-wrapper { width: 32px; height: 32px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: #6b7280; transition: all 0.3s ease; }
.faq-card:hover .icon-wrapper { background: #F68C0F; color: white; }
.rotate-180 { transform: rotate(180deg); }
.legal-link { color: #6B7280; font-size: 1.1rem; text-decoration: none; }
.legal-link:hover { text-decoration: underline; }

</style>

<template>
    <Head title="Ezy Ventas - Punto de Venta" />

    <div class="min-h-screen bg-white font-krub text-gray-800 overflow-x-hidden flex flex-col">
        
        <!-- NAV BAR -->
        <nav 
            class="navbar-container py-4 px-4 md:px-8 flex justify-center"
            :class="{ 
                'navbar-scrolled': isScrolled || true, 
                'navbar-hidden': !isNavVisible 
            }"
        >
            <div class="max-w-8xl w-full flex justify-between items-center">
                <div class="flex items-center gap-2"> 
                    <img src="/images/black_logo.png" alt="Ezy Ventas Logo" class="h-14 w-auto" />
                </div>
                <div class="hidden lg:flex items-center space-x-10 text-gray-500 font-medium text-[0.95rem] tracking-wide">
                    <button @click="scrollToElement('features')" class="hover:text-[#F68C0F] transition duration-200">Funcionalidades</button>
                    <button @click="scrollToElement('gratis')" class="hover:text-[#F68C0F] transition duration-200">Gratis</button>
                    <button @click="scrollToElement('app-movil')" class="hover:text-[#F68C0F] transition duration-200">App móvil</button>
                    <button @click="scrollToElement('faq')" class="hover:text-[#F68C0F] transition duration-200">Preguntas frecuentes</button>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="hover:text-[#F68C0F] transition duration-200">Contacto</a>
                </div>
                
                <div class="flex items-center gap-4">
                    <template v-if="$page.props.auth?.user">
                        <Link :href="route('dashboard')">
                            <FrutigerButton>Dashboard</FrutigerButton>
                        </Link>
                    </template>
                    <template v-else>
                        <Link 
                            v-if="canRegister" 
                            :href="route('register')" 
                            class="hidden md:block font-bold text-gray-500 hover:text-[#F68C0F] transition duration-200 text-sm"
                        >
                            Registrarse
                        </Link>
                        <Link :href="route('login')">
                            <FrutigerButton>Iniciar sesión</FrutigerButton>
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- HERO SECTION -->
        <header 
            ref="heroContainer"
            @mousemove="handleMouseMove" 
            class="hero-wrapper relative min-h-screen pt-24 pb-0"
        >
            <div class="relative z-30 text-center px-4 my-0" data-aos="fade-down">
                <h1 class="text-[34px] md:text-5xl lg:text-6xl font-bold leading-tight">
                    <span class="text-gradient-elegant">El punto de venta que</span> <br class="hidden md:block"/>
                    <span class="text-[#F68C0F]">se adapta a tu negocio</span>
                </h1>
            </div>

            <div class="relative flex-1 flex flex-col items-center justify-center w-full px-4 pb-20"> 
                <div class="absolute inset-0 flex flex-col justify-center items-center pointer-events-none select-none" :style="textBackStyle">
                    <div class="big-title-bg mb-12 md:mb-12">TU NEGOCIO</div>
                    <div class="big-title-bg mt-0 md:mt-0">INTELIGENTE</div>
                </div>

                <div 
                    class="relative z-20 transition-transform duration-100 ease-out"
                    :style="phoneStyle"
                    data-aos="zoom-out" 
                    data-aos-duration="1000"
                >
                    <div class="absolute -bottom-6 left-1/2 -translate-x-1/2 w-[60%] h-16 bg-black/20 blur-[50px] rounded-full"></div>
                    <img 
                        src="/imagesLanding/hero-phone.webp" 
                        alt="App Ezy Ventas"
                        width="320"
                        height="643"
                        fetchpriority="high"
                        class="w-[200px] md:w-[280px] lg:w-[320px] object-contain drop-shadow-2xl mx-auto"
                    >
                    <div class="absolute top-10 -left-16 md:-left-24 hidden md:block" :style="floatingWidgetStyle(-30, -15)" data-aos="fade-right" data-aos-delay="200">
                        <div class="premium-widget p-3 flex items-center gap-4 w-[180px]">
                            <div class="w-12 h-12 rounded-full bg-green-50 flex items-center justify-center text-green-600 text-2xl font-bold">$</div>
                            <div>
                                <p class="text-[14px] text-gray-400 font-bold uppercase tracking-wider">VENTAS HOY</p>
                                <p class="text-xl font-black text-gray-800">$12,450</p>
                            </div>
                        </div>
                    </div>
                     <div class="absolute top-20 -right-16 md:-right-24 hidden md:block" :style="floatingWidgetStyle(25, -20)" data-aos="fade-left" data-aos-delay="400">
                        <div class="premium-widget p-4 w-[180px]">
                            <div class="flex justify-between items-start mb-1">
                                <span class="text-[14px] font-bold text-gray-400">PERSONAL</span>
                                <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                            </div>
                            <div class="flex -space-x-2 items-center">
                                <div class="w-10 h-10 rounded-full border border-white bg-gray-200 flex items-center justify-center text-[10px] font-bold">JD</div>
                                <div class="w-10 h-10 rounded-full border border-white bg-[#F68C0F] text-white flex items-center justify-center text-[10px] font-bold">MR</div>
                                <div class="w-10 h-10 rounded-full border border-white bg-white text-gray-500 flex items-center justify-center text-[10px] font-bold">+3</div>
                            </div>
                        </div>
                    </div>
                     <div class="absolute bottom-40 -left-12 md:-left-16 hidden md:block" :style="floatingWidgetStyle(-15, 20)" data-aos="fade-up-right" data-aos-delay="500">
                        <div class="premium-widget p-3 flex items-center gap-6 pr-6">
                            <div class="bg-gray-100 p-3 rounded-xl">
                                <img src="/imagesLanding/feature-reader.webp" alt="Lector de código de barras conectado" loading="lazy" decoding="async" class="w-10 h-10 object-contain">
                            </div>
                            <div>
                                <p class="text-lg font-bold text-gray-800">Escáner</p>
                                <p class="text-[14px] font-bold text-green-600">CONECTADO</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bottom-glass-bar absolute bottom-8 py-6 md:py-10 px-6 md:px-12 lg:px-20 flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="text-center md:text-left space-y-1">
                    <p class="text-xl font-bold text-gray-900">Todo lo que necesitas para crecer</p>
                    <p class="text-gray-700 font-medium text-lg">Ahora es 100% gratis, sin costo de suscripción.</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-4 w-full md:w-auto">
                    <Link :href="route('register')" class="btn-apple-primary w-full sm:w-auto gap-2">
                        Crear cuenta
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </Link>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="btn-apple-secondary w-full sm:w-auto gap-2">Contactar con ventas</a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <!-- SECCIÓN: ANUNCIO GRATIS (NUEVO MODELO SIN SUSCRIPCIÓN) -->
            <section id="gratis" class="relative py-20 md:py-28 px-6 md:px-12 overflow-hidden">
                <div class="max-w-[1400px] mx-auto">
                    <div class="relative rounded-[40px] border border-gray-100 bg-gradient-to-br from-[#fff7ec] via-white to-white shadow-[0_40px_90px_-40px_rgba(246,140,15,0.45)] overflow-hidden" data-aos="fade-up">
                        <!-- Glow decorativo -->
                        <div class="absolute -top-32 -left-24 w-96 h-96 bg-[#F68C0F] opacity-[0.09] blur-[110px] rounded-full pointer-events-none"></div>
                        <div class="absolute -bottom-40 -right-20 w-[420px] h-[420px] bg-[#F68C0F] opacity-[0.07] blur-[120px] rounded-full pointer-events-none"></div>

                        <div class="relative z-10 flex flex-col lg:flex-row items-center gap-10 lg:gap-16 px-6 py-12 md:px-16 md:py-16">
                            <div class="flex-1 space-y-5 text-center lg:text-left">
                                <span class="inline-flex items-center gap-2 bg-white border border-[#F68C0F]/30 text-[#F68C0F] px-4 py-2 rounded-full text-[11px] font-bold uppercase tracking-widest shadow-sm">
                                    <span class="w-2 h-2 rounded-full bg-[#F68C0F] animate-pulse"></span>
                                    Ahora es gratis
                                </span>

                                <h2 class="text-3xl md:text-5xl font-bold text-gray-900 tracking-tight leading-tight m-0">¡Ezy Ventas evoluciona!</h2>

                                <p class="text-lg md:text-xl font-medium text-gray-800 m-0">Una gran noticia para toda nuestra comunidad.</p>

                                <div class="space-y-3 text-gray-600 text-base md:text-lg leading-relaxed max-w-2xl">
                                    <p>Gracias a las alianzas estratégicas que hemos construido, Ezy Ventas deja de ser un software de suscripción para convertirse en una plataforma <strong class="text-gray-900">100% gratuita</strong>.</p>
                                    <p>Tendrás acceso ilimitado a todas las herramientas y módulos activos del sistema <strong class="text-gray-900">sin costo de suscripción</strong>.</p>
                                    <p>Agradecemos enormemente la confianza de los negocios que ya operan con nosotros y damos la bienvenida a quienes están por comenzar.</p>
                                </div>

                                <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start pt-2">
                                    <Link :href="route('register')" class="btn-apple-primary w-full sm:w-auto gap-2">
                                        Comenzar gratis
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                                    </Link>
                                    <button type="button" @click="scrollToElement('app-movil')" class="btn-apple-secondary w-full sm:w-auto gap-2">
                                        Ver la app móvil
                                    </button>
                                </div>
                            </div>
                            <!-- Tarjetas de beneficios del modelo gratis -->
                            <div class="w-full lg:w-[380px] space-y-4">
                                <div class="bg-white border border-gray-100 rounded-2xl p-5 flex items-start gap-4 shadow-sm">
                                    <div class="w-10 h-10 rounded-full bg-[#F68C0F]/10 text-[#F68C0F] flex items-center justify-center shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 m-0">Sin mensualidades</p>
                                        <p class="text-sm text-gray-500 mt-1">No pagas suscripción mensual ni anual para operar tu negocio.</p>
                                    </div>
                                </div>
                                <div class="bg-white border border-gray-100 rounded-2xl p-5 flex items-start gap-4 shadow-sm">
                                    <div class="w-10 h-10 rounded-full bg-[#F68C0F]/10 text-[#F68C0F] flex items-center justify-center shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 m-0">Todas las herramientas incluidas</p>
                                        <p class="text-sm text-gray-500 mt-1">Punto de venta, inventario, clientes y reportes activos desde el primer día.</p>
                                    </div>
                                </div>
                                <div class="bg-white border border-gray-100 rounded-2xl p-5 flex items-start gap-4 shadow-sm">
                                    <div class="w-10 h-10 rounded-full bg-[#F68C0F]/10 text-[#F68C0F] flex items-center justify-center shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 m-0">Sin plazos forzosos</p>
                                        <p class="text-sm text-gray-500 mt-1">Usas el sistema porque te sirve, no porque estés amarrado a un contrato.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN: VIDEO SPOT (SE CARGA SOLO AL DAR PLAY) -->
            <section class="py-24 px-6 md:px-12 max-w-[1400px] mx-auto">
                <div class="text-center mb-12 md:mb-16 space-y-4" data-aos="fade-up">
                    <h2 class="text-3xl md:text-5xl font-bold text-gray-900 tracking-tight">Mira Ezy Ventas en acción</h2>
                    <p class="text-lg text-gray-500 max-w-2xl mx-auto">Dale play y descubre en menos de un minuto todo lo que puedes lograr con tu negocio.</p>
                </div>

                <div class="relative w-full max-w-6xl mx-auto aspect-video rounded-3xl overflow-hidden bg-black shadow-2xl group" data-aos="zoom-in" data-aos-delay="100">
                    <!-- Poster estático (se muestra hasta el primer play) -->
                    <div v-if="!hasVideoStarted" class="absolute inset-0 z-10">
                        <img src="/imagesLanding/cover_spot.png" alt="Ezy Ventas en acción" loading="lazy" decoding="async" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-black/10 to-black/10"></div>
                    </div>

                    <!-- Botón de play personalizado -->
                    <button
                        v-if="!hasVideoStarted"
                        type="button"
                        aria-label="Reproducir video"
                        @click="playSpotVideo"
                        class="absolute inset-0 z-20 flex items-center justify-center cursor-pointer transition-colors duration-300 group-hover:bg-black/10"
                    >
                        <span class="relative flex items-center justify-center w-20 h-20 md:w-24 md:h-24 rounded-full bg-white/95 shadow-2xl transition-transform duration-300 group-hover:scale-110">
                            <span class="absolute inset-0 rounded-full bg-[#F68C0F]/20 animate-ping-pause"></span>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#F68C0F" class="w-9 h-9 md:w-10 md:h-10 ml-1" aria-hidden="true">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </span>
                    </button>

                    <!-- Video real: el src solo se asigna al primer clic -->
                    <video
                        ref="videoEl"
                        v-show="hasVideoStarted"
                        controls
                        playsinline
                        preload="none"
                        class="w-full h-full object-cover bg-black"
                    ></video>
                </div>
            </section>

            <!-- SECCIÓN 2: TIPOS DE NEGOCIO (AJUSTADA PARA MÓVIL) -->
            <section id="features" class="py-24 px-6 md:px-12 max-w-[1450px] mx-auto bg-gray-50/50">
                <div class="text-center mb-10 md:mb-16 space-y-4" data-aos="fade-up">
                    <h2 class="text-3xl md:text-5xl font-bold text-gray-900 tracking-tight">Diseñado para tu giro</h2>
                    <p class="text-lg text-gray-500 max-w-3xl mx-auto">Selecciona tu tipo de negocio y descubre por qué Ezy Ventas es tu mejor aliado.</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 md:gap-6">
                    <div 
                        v-for="(biz, index) in businessTypes" 
                        :key="index" 
                        class="biz-card-bento group !flex-row md:!flex-col !items-center md:!items-stretch !p-4 md:!p-6"
                        @click="openBusinessModal(biz)"
                        data-aos="fade-up"
                        :data-aos-delay="index * 100">

                        <!-- IMAGEN (Izquierda en móvil, Arriba en desktop) -->
                        <div class="biz-img-container w-16 h-16 md:w-full md:h-[140px] md:mb-4 shrink-0 mr-4 md:mr-0">
                            <img :src="biz.image" :alt="biz.alt" loading="lazy" decoding="async" class="biz-img w-full h-full object-contain">
                        </div>
                        
                        <!-- TEXTO (Centro en móvil, Abajo en desktop) -->
                        <div class="flex-1 text-left mt-0 md:mt-auto space-y-1 md:space-y-2">
                            <h3 class="text-lg md:text-xl font-bold text-gray-900 group-hover:text-[#F68C0F] transition-colors leading-tight">{{ biz.title }}</h3>
                            <p class="text-md text-gray-500 leading-snug line-clamp-2 md:line-clamp-none">{{ biz.shortDesc }}</p>
                            
                            <!-- Flecha Desktop (Oculta en móvil) -->
                            <div class="hidden md:flex pt-4 items-center gap-2 text-sm font-semibold text-[#F68C0F] action-arrow">
                                <span>Ver beneficios</span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                            </div>
                        </div>

                         <!-- Flecha MÓVIL (Visible solo en móvil) -->
                         <button type="button" aria-label="Ver beneficios de {{ biz.title }}" class="md:hidden w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center text-[#F68C0F] shrink-0 ml-2">
                             <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                         </button>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 3: FUNCIONALIDADES -->
            <section class="py-32 px-6 md:px-12 max-w-[1400px] mx-auto overflow-hidden">
                <div class="text-center mb-24" data-aos="fade-up">
                    <h2 class="text-4xl md:text-6xl font-bold tracking-tight text-gray-900 mb-6">
                        Poderoso. Simple. <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#F68C0F] to-orange-400">Tuyo.</span>
                    </h2>
                    <p class="text-xl text-gray-500 max-w-2xl mx-auto font-light leading-relaxed">
                        Tecnología que ayuda. Diseñamos una interfaz intuitiva para que tú y tu equipo sepan usarla desde el primer día, sin capacitaciones eternas.
                    </p>
                </div>

                <div class="space-y-12">
                    <div class="feature-card-modern group relative flex flex-col md:flex-row items-center gap-12 md:gap-20 p-8 md:p-20" data-aos="fade-up">
                        <div class="w-full md:w-1/2 space-y-6 z-10">
                            <div class="inline-block bg-orange-100 text-[#F68C0F] px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider mb-2">Automatización</div>
                            <h3 class="text-4xl md:text-5xl font-bold text-gray-900 leading-tight">Tu inventario,<br/>siempre exacto.</h3>
                            <p class="text-lg text-gray-600 leading-relaxed">Deja de contar manualmente. El sistema detecta movimientos y te alerta sobre stock bajo automáticamente. Dile adiós al robo hormiga y hola a la tranquilidad.</p>
                            <ul class="space-y-3 pt-4">
                                <li class="flex items-center gap-3 text-gray-700 font-medium"><span class="w-2 h-2 rounded-full bg-green-500"></span>Alertas de stock bajo en tiempo real</li>
                                <li class="flex items-center gap-3 text-gray-700 font-medium"><span class="w-2 h-2 rounded-full bg-green-500"></span>Carga masiva desde Excel</li>
                                <li class="flex items-center gap-3 text-gray-700 font-medium"><span class="w-2 h-2 rounded-full bg-green-500"></span>Historial de movimientos por usuario</li>
                            </ul>
                        </div>
                        <div class="w-full md:w-1/2 relative flex justify-center items-center h-[400px]">
                            <!-- Grid con cartas HORIZONTALES Y COMPACTAS (Estilo Nuevo) -->
                            <div class="grid grid-cols-2 gap-4 w-full max-w-lg">
                                <div v-for="(prod, i) in inventoryMock" :key="i" 
                                     class="inventory-item-card group/card" 
                                     :class="[prod.hoverClass, i % 2 !== 0 ? 'translate-y-4' : '']">
                                    
                                    <!-- Stock Dot -->
                                    <div class="absolute top-3 right-3 w-2 h-2 rounded-full" :class="prod.dotClass"></div>

                                    <!-- Icono -->
                                    <div class="w-14 h-14 rounded-xl bg-gray-50 flex items-center justify-center text-3xl group-hover/card:scale-110 transition-transform">
                                        {{ prod.icon }}
                                    </div>
                                    
                                    <!-- Info -->
                                    <div>
                                        <p class="text-md font-bold text-gray-900 truncate leading-tight">{{ prod.name }}</p>
                                        <p class="text-normal text-gray-500 font-medium mt-0.5">{{ prod.pieces }} pzas</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="feature-card-modern flex flex-col justify-between" data-aos="fade-right" data-aos-delay="100">
                            <div class="mb-8 relative z-10">
                                <h3 class="text-3xl font-bold text-gray-900 mb-4">Tu negocio.<br/>En alta definición.</h3>
                                <p class="text-gray-600">Transforma números complejos en respuestas claras. Entiende qué vendes, quién vende y cuánto ganas, todo en tiempo real y en un solo vistazo.</p>
                            </div>
                            <!-- AQUI ESTÁ EL NUEVO COMPONENTE INTEGRADO -->
                            <div class="w-full h-full flex items-end justify-center -mb-8 md:-mb-12">
                                <DashboardGraph />
                            </div>
                        </div>
                        <div class="feature-card-modern flex flex-col justify-between" data-aos="fade-left" data-aos-delay="200">
                            <div class="mb-8">
                                <h3 class="text-3xl font-bold text-gray-900 mb-4">Tu caja.<br/>sin límites.</h3>
                                <p class="text-gray-600">Si hay mucha gente, permite que multiples vendedores cobren simultáneamente desde cualquier dispositivo, colaborando en una misma sesión de caja o gestionando cajas independientes.</p>
                            </div>
                            <div class="relative h-64 flex justify-center items-end">
                                <img src="/imagesLanding/solution-old-register.webp" alt="Múltiples usuarios en caja registradora" loading="lazy" decoding="async" class="w-3/4 object-contain drop-shadow-xl transition-transform duration-500 hover:-translate-y-4">
                            </div>
                        </div>
                    </div>
                    
                    <!-- NUEVO COMPONENTE DE CLIENTES INTEGRADO AQUÍ (Versión Light/Moderna) -->
                    <div class="feature-card-modern flex flex-col md:flex-row items-center gap-12 bg-white text-gray-900 p-10 relative overflow-hidden" data-aos="fade-up">
                        <div class="w-full md:w-1/2 space-y-10 relative z-10">
                            <h3 class="text-3xl md:text-4xl font-bold text-gray-900">Convierte ventas en relaciones.</h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Deja de venderle a desconocidos. Crea perfiles detallados, habilita líneas de crédito y reconoce a tus clientes VIP al instante. Porque un cliente que se siente especial, siempre regresa.</p>
                            <!-- <button class="bg-[#2f2f2f] text-white px-8 py-3 rounded-full font-bold hover:bg-[#191919] shadow-lg hover:shadow-xl transition-all">Ver tutorial de clientes</button> -->
                        </div>
                        <div class="w-full md:w-1/2 flex justify-center relative z-10">
                            <CustomerRelationship />
                        </div>
                        
                        <!-- Fondo decorativo sutil -->
                        <div class="absolute inset-0 bg-gradient-to-r from-gray-50 to-white z-0 pointer-events-none"></div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN: APP MÓVIL ANDROID -->
            <section id="app-movil" class="py-20 md:py-28 px-6 md:px-12 max-w-[1400px] mx-auto">
                <div class="relative rounded-[40px] bg-[#1a1a1a] overflow-hidden" data-aos="fade-up">
                    <div class="absolute -top-40 -right-24 w-[460px] h-[460px] bg-[#F68C0F] opacity-20 blur-[130px] rounded-full pointer-events-none"></div>

                    <div class="relative z-10 flex flex-col lg:flex-row items-center gap-10 lg:gap-16 px-6 py-12 md:px-16 md:py-16">
                        <div class="flex-1 space-y-5 text-center lg:text-left">
                            <span class="inline-flex items-center gap-2 bg-white/5 border border-white/10 text-white px-4 py-2 rounded-full text-[11px] font-bold uppercase tracking-widest">
                                <i class="pi pi-android !text-xs text-[#F68C0F]"></i>
                                App móvil Android
                            </span>

                            <h2 class="text-3xl md:text-5xl font-bold text-white tracking-tight leading-tight m-0">Tu punto de venta, en el bolsillo</h2>

                            <p class="text-gray-400 text-base md:text-lg leading-relaxed max-w-2xl">Instala Ezy Ventas en tu celular Android y vende, consulta tu inventario y revisa tus cortes de caja desde donde estés, siempre sincronizado con el resto de tus dispositivos.</p>

                            <ul class="space-y-3 max-w-xl mx-auto lg:mx-0 text-left list-none">
                                <li class="flex items-center gap-3 text-gray-300">
                                    <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] flex items-center justify-center shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    </span>
                                    Cobra desde el celular con lector de código de barras
                                </li>
                                <li class="flex items-center gap-3 text-gray-300">
                                    <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] flex items-center justify-center shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    </span>
                                    Consulta productos, precios y existencias al instante
                                </li>
                                <li class="flex items-center gap-3 text-gray-300">
                                    <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] flex items-center justify-center shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    </span>
                                    Revisa tus ventas y cortes de caja del día en tiempo real
                                </li>
                            </ul>

                            <div class="flex flex-col sm:flex-row items-center gap-4 justify-center lg:justify-start pt-2">
                                <Link :href="route('app.download')" class="px-8 py-4 rounded-full bg-[#F68C0F] text-white font-bold shadow-[0_15px_35px_-10px_rgba(246,140,15,0.6)] hover:bg-[#ff9e3d] hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 w-full sm:w-auto">
                                    Descargar app para Android
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v13.5m0 0 5.25-5.25M12 16.5 6.75 11.25M4.5 20.25h15" /></svg>
                                </Link>
                                <span class="text-gray-500 text-sm">Muy pronto disponible en Google Play.</span>
                            </div>
                        </div>
                        <!-- Mockup del teléfono -->
                        <div class="w-full lg:w-[340px] flex justify-center">
                            <img src="/imagesLanding/hero-phone.webp" alt="Ezy Ventas en tu celular" loading="lazy" decoding="async" class="w-full max-w-[300px] object-contain drop-shadow-2xl">
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 4: PREGUNTAS FRECUENTES -->
            <section id="faq" class="py-32 px-6 md:px-12 max-w-4xl mx-auto">
                <div class="text-center mb-16" data-aos="fade-up">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 tracking-tight mb-4">Preguntas frecuentes</h2>
                    <p class="text-lg text-gray-500">Todo lo que necesitas saber para empezar.</p>
                </div>

                <div class="space-y-4">
                    <!-- Wrapper for AOS (Separado del contenido interactivo) -->
                    <div v-for="(faq, index) in faqs" :key="index" data-aos="fade-up" :data-aos-delay="index * 100">
                        <div class="faq-card group" :class="{ 'active': faq.open }" @click="toggleFaq(index)">
                            
                            <div class="faq-header">
                                <span class="text-lg font-medium text-gray-900 group-hover:text-[#F68C0F] transition-colors">{{ faq.question }}</span>
                                <div class="icon-wrapper">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" 
                                             class="w-5 h-5 transition-transform duration-300" 
                                             :class="{ 'rotate-180': faq.open }">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                            
                            <div class="faq-grid-wrapper" :class="{ 'grid-open': faq.open }">
                                <div class="faq-inner-content">
                                    <div class="pb-6 text-gray-600 leading-relaxed border-t border-gray-100 pt-4">
                                        {{ faq.answer }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help Box Redesigned -->
                <div class="mt-20 bg-gray-50 rounded-3xl p-8 md:p-12 text-center" data-aos="fade-up">
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">¿Aún tienes dudas?</h3>
                    <p class="text-gray-500 mb-8 max-w-lg mx-auto">Nuestro equipo de soporte está listo para ayudarte en cualquier momento.</p>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="inline-flex items-center justify-center gap-2 bg-white border border-gray-200 text-gray-900 px-8 py-3 rounded-full font-semibold hover:border-[#F68C0F] hover:text-[#F68C0F] transition-all shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                        </svg>
                        Contactar Soporte
                    </a>
                </div>
            </section>
        </main>

                    <!-- FOOTER MODERNO INTEGRADO -->
        <ModernFooter />

        <!-- MODAL DE DETALLE DE NEGOCIO -->
        <Teleport to="body">
            <transition name="modal">
                <div v-if="isModalOpen && selectedBusiness" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-md" @click="closeBusinessModal"></div>
                    <transition name="modal-content" appear>
                        <div class="relative bg-white rounded-3xl w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-2xl flex flex-col md:flex-row overflow-hidden">
                            
                            <button @click="closeBusinessModal" aria-label="Cerrar modal" class="absolute top-4 right-4 z-20 bg-white/50 hover:bg-white p-2 rounded-full transition-all">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>

                            <div class="w-full h-48 sm:h-40 md:h-auto md:w-2/5 bg-gray-100 flex items-center justify-center p-4 md:p-12 relative shrink-0">
                                
                                <img 
                                    :src="selectedBusiness.image" 
                                    :alt="selectedBusiness.alt" 
                                    loading="lazy"
                                    decoding="async"
                                    class="h-full w-auto md:w-full md:h-auto object-contain drop-shadow-xl max-h-full"
                                >
                            </div>

                            <div class="w-full md:w-3/5 p-6 md:p-12 flex flex-col justify-center">
                                <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">{{ selectedBusiness.title }}</h3>
                                <p class="text-base md:text-lg text-gray-500 mb-6 font-medium">{{ selectedBusiness.fullDesc }}</p>
                                
                                <div class="space-y-4 mb-8">
                                    <h4 class="text-xs md:text-sm font-bold text-gray-400 uppercase tracking-wider">Beneficios Clave</h4>
                                    <ul class="space-y-3">
                                        <li v-for="(feature, idx) in selectedBusiness.features" :key="idx" class="flex items-start gap-3">
                                            <div class="mt-1 w-5 h-5 rounded-full bg-green-100 flex items-center justify-center text-green-600 flex-shrink-0">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                            </div>
                                            <span class="text-sm md:text-base text-gray-700">{{ feature }}</span>
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="flex flex-col sm:flex-row gap-4 mt-auto">
                                    <Link :href="route('register')" class="btn-apple-primary justify-center text-sm px-8 py-3">
                                        Comenzar gratis
                                    </Link>
                                </div>
                            </div>

                        </div>
                    </transition>
                </div>
            </transition>
        </Teleport>

    </div>
</template>