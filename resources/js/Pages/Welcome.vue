<script setup>
import { Head, Link } from '@inertiajs/vue3';
// import ApplicationMark from '@/Components/ApplicationMark.vue'; 
import SelectButton from 'primevue/selectbutton';
import AOS from 'aos';
import 'aos/dist/aos.css';
import { ref, computed, onMounted, onUnmounted } from 'vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    laravelVersion: String,
    phpVersion: String,
});

// --- NAVBAR LOGIC (VISIBILIDAD Y ESTILO) ---
const footerRef = ref(null);
const isNavVisible = ref(true);
const isScrolled = ref(false);

const handleScroll = () => {
    // Detectar si se ha bajado más de 100px para activar el modo "pegajoso"
    isScrolled.value = window.scrollY > 50;
};

const setupIntersectionObserver = () => {
    const observer = new IntersectionObserver(
        ([entry]) => {
            // Si el footer es visible, ocultamos la barra
            isNavVisible.value = !entry.isIntersecting;
        },
        {
            root: null,
            threshold: 0,
            rootMargin: "0px 0px -50px 0px" 
        }
    );

    if (footerRef.value) {
        observer.observe(footerRef.value);
    }
};

// --- FAQ LOGIC ---
const faqs = ref([
    { 
        question: '¿Necesito hardware especial?', 
        answer: 'No, funciona desde cualquier dispositivo, el sistema se encuentra en la nube.',
        open: false 
    },
    { 
        question: '¿Hay un contrato forzoso?', 
        answer: 'No, puedes cancelar tu suscripción en cualquier momento sin penalizaciones.',
        open: false 
    },
    { 
        question: '¿Ofrecen soporte técnico?', 
        answer: 'Sí, contamos con soporte técnico vía chat y correo electrónico para todos nuestros planes.',
        open: false 
    }
]);

const toggleFaq = (index) => {
    faqs.value[index].open = !faqs.value[index].open;
};

// --- ESTADO Y LÓGICA DE PRECIOS ---
const isAnnual = ref(false); 

const paymentOptions = ref([
    { name: 'Mensual', value: false },
    { name: 'Anual', value: true }
]);

const basePriceMonthly = 199;

const modules = ref([
    { id: 'reportes', name: 'Reporte financiero', price: 30, active: false },
    { id: 'clientes', name: 'Clientes', price: 40, active: false, }, 
    { id: 'servicios', name: 'Servicios', price: 45, active: false, 
      subItems: ['Catalogo de servicios', 'Órdenes de servicio'] }, 
    { id: 'cotizaciones', name: 'Cotizaciones', price: 25, active: false },
    { id: 'ecommerce', name: 'Tienda en línea', price: 99, active: false },
]);

const features = ref([
    { id: 'users', name: 'Usuarios extra', price: 5, count: 0 },
    { id: 'cajas', name: 'Cajas extra', price: 10, count: 0 },
    { id: 'products', name: 'Productos extra', price: 2, count: 0 }, 
    { id: 'branches', name: 'Sucursales extra', price: 50, count: 0 },
]);

const totalPrice = computed(() => {
    let monthlyTotal = basePriceMonthly;

    modules.value.forEach(m => {
        if (m.active) monthlyTotal += m.price;
    });

    features.value.forEach(f => {
        monthlyTotal += (f.count * f.price);
    });

    if (isAnnual.value) {
        return (monthlyTotal * 0.8).toFixed(2);
    }

    return monthlyTotal.toFixed(2);
});

const incrementFeature = (id) => {
    const feature = features.value.find(f => f.id === id);
    if (feature) feature.count++;
};

const decrementFeature = (id) => {
    const feature = features.value.find(f => f.id === id);
    if (feature && feature.count > 0) feature.count--;
};

const toggleModule = (id) => {
    const module = modules.value.find(m => m.id === id);
    if (module) module.active = !module.active;
}

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
    { title: 'Ropa y calzado', image: '/imagesLanding/biz-clothing.webp', alt: 'Artículos de ropa' },
    { title: 'Supermercados', image: '/imagesLanding/biz-supermarket.webp', alt: 'Abarrotes' },
    { title: 'Papelerías', image: '/imagesLanding/biz-stationery.webp', alt: 'Papelería' },
    { title: 'Ferreterías', image: '/imagesLanding/biz-hardware.webp', alt: 'Herramientas' },
    { title: 'Servicios', image: '/imagesLanding/biz-hardware.webp', alt: 'Servicios' },
];
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Krub:wght@200;300;400;500;600;700&display=swap');

.font-krub {
    font-family: 'Krub', sans-serif;
}

/* --- NAVBAR INTELIGENTE --- */
/* Estado base: Barra normal en el tope (Espacio en blanco) */
.navbar-container {
    width: 100%;
    background-color: white; /* Fondo blanco sólido al inicio */
    z-index: 50;
    transition: all 0.3s ease-in-out;
    position: relative; /* Ocupa espacio real en el DOM */
}

/* Estado Scrolled: Barra flotante, semitransparente */
.navbar-scrolled {
    position: fixed; /* Se pega al techo */
    top: 0;
    left: 0;
    background-color: rgba(255, 255, 255, 0.589); /* Transparencia sutil */
    backdrop-filter: blur(12px); /* Efecto vidrio */
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    padding-top: 0.5rem; /* Se hace un poco más compacta */
    padding-bottom: 0.5rem;
    animation: slideDown 0.3s ease-out; /* Animación suave al aparecer */
}

@keyframes slideDown {
    from { transform: translateY(-100%); }
    to { transform: translateY(0); }
}

/* Estado Oculto: Cuando llega al footer */
.navbar-hidden {
    transform: translateY(-100%);
    opacity: 0;
    pointer-events: none;
}

/* --- BOTÓN INICIAR SESIÓN --- */
.btn-login-nav {
    background: linear-gradient(90deg, #bb7505 0%, #FF8C00 100%);
    color: white; 
    padding: 10px 20px; 
    border-radius: 50px; 
    font-weight: 600;
    font-size:1rem;
    border: none;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
    box-shadow: 0 4px 6px rgba(255, 140, 0, 0.3);
    position: relative;
    overflow: hidden;
}

.btn-login-nav::after {
    content: "";
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(
        to right,
        rgba(255, 255, 255, 0) 0%,
        rgba(255, 255, 255, 0.4) 50%,
        rgba(255, 255, 255, 0) 100%
    );
    transform: skewX(-25deg);
    transition: none;
}

.btn-login-nav:hover::after {
    left: 100%;
    transition: left 0.7s ease-in-out;
}

.btn-login-nav:hover {
    transform: scale(1.05) translateY(-2px);
    box-shadow: 0 10px 20px rgba(255, 140, 0, 0.4);
}

.btn-login-nav:active {
    transform: scale(0.98);
    box-shadow: 0 2px 4px rgba(255, 140, 0, 0.2);
}

/* --- RESTO DE ESTILOS (TU CÓDIGO ORIGINAL) --- */
.bg-hero-combined {
    background-color: #FFC805;
    background-image: url('/imagesLanding/hero-bg-lines.webp'), url('/imagesLanding/hero-bg-orange.webp');
    background-position: 60% center, center center;
    background-size: contain, cover; 
    background-repeat: no-repeat, no-repeat;
    margin-left: 20px;
    margin-right: 20px;
    border-radius: 50px;
}

/* ... (Tus estilos previos se mantienen igual) ... */
.feature-card-pop {
    position:relative; display: flex; align-items: center; padding: 10px 18px; border-radius: 20px; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15); max-width: 300px; width: 100%; height: 80px; transition: transform 0.3s ease; margin-bottom: 0.4rem; z-index: 10;
}
.feature-card-pop:hover { transform: scale(1.05); z-index: 20; }
.card-gray { background-color: #f2f2f2; color: #373737; }
.card-blue { background-color: #E1F1FF; color: #187AE9; }
.img-pop { position: absolute; width: 80px; height:auto; object-fit: contain; filter: drop-shadow(0 8px 8px rgba(0,0,0,0.25)); transition: transform 0.3s ease; }
.feature-card-pop:hover .img-pop { transform: scale(1.1) rotate(5deg); }
.img-pop-right { right: -25px; top: 5px; }
.img-pop-left { left: -25px; top: 5px; }
.text-content-left { margin-right: 30px; text-align: left; width: 100%; }
.text-content-right { margin-left: 50px; text-align: right; width: 100%; }

/* ... (Estilos de biz-card, price-card, restaurant-banner se mantienen igual) ... */
.biz-gradient-1 { background: linear-gradient(180deg, #CA8CFF 0%, #E5C7FE 100%); }
.biz-gradient-2 { background: linear-gradient(180deg, #E5C7FE 0%, #CA8CFF 100%); }
.biz-card { border-radius: 20px; padding: 15px; height: 280px; display:flex; flex-direction: column; position:relative; overflow: hidden; transition: transform 0.3s ease; }
.biz-card:hover { transform: scale(1.02); }
.glass-title { background: rgba(238, 238, 238, 0.4); backdrop-filter: blur(10px); border-radius: 10px; padding: 8px 16px; font-weight: 900; font-size: 1.5rem; color: #2D2D2D; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: inline-block; margin-bottom: auto; z-index: 10; }

.price-card-left-container { background-color: #E1F1FF; border: 2px solid #9CBDD2; border-radius: 30px; padding:0.5rem; height: 100%; display: flex; flex-direction: column; gap: 1.5rem; }
.white-floating-panel { background-color: white; border-radius: 24px; padding: 2rem; box-shadow: 0 10px 20px rgba(15, 150, 246, 0.1); }
.price-card-builder { background-color: #E1F1FF; border: 2px solid #9CBDD2; border-radius: 30px; padding: 2rem; height: 100%; }
.module-item { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 1rem; }
.btn-toggle-module { width: 24px; height: 24px; border-radius: 50%; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; color: white; font-weight: bold; flex-shrink: 0; }
.btn-plus { background-color: #187AE9; }
.btn-minus { background-color: #5f89a3; }
.counter-control { display: flex; align-items: center; gap: 8px; background: white; padding: 2px 8px; border-radius: 8px; }
.counter-btn { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #f0f0f0; cursor: pointer; font-size: 14px; color: #555; border-radius: 50%; }
.counter-btn:hover { background: #e0e0e0; }

.restaurant-banner-container { position: relative; border-radius: 30px; overflow: hidden; background-image: url('/imagesLanding/banner-restaurant-bg.webp'); background-size: cover; background-position: center; background-repeat: no-repeat; color: white; box-shadow: 0 20px 40px -5px rgba(107, 33, 168, 0.4); transition: transform 0.3s ease; }
.restaurant-banner-container:hover { transform: scale(1.03); }
.restaurant-banner-container::before { content: ''; position:relative; top: 0; left: -100%; width: 50%; height: 100%; background: linear-gradient(to right, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0) 100%); transform: skewX(-25deg); transition: left 0.7s ease-in-out; pointer-events: none; z-index: 5; }
.restaurant-banner-container:hover::before { left: 150%; }
.food-deco { position: absolute; right: -20px; bottom: -20px; width: 250px; opacity: 0.3; pointer-events: none; mix-blend-mode: overlay; }
.input-round { border-radius: 9999px; padding: 16px 24px; border: none; outline: none; width: 100%; color: #333; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }

.faq-item { border-bottom: 1px solid #e5e7eb; transition: all 0.3s ease; }
.faq-question { cursor: pointer; padding: 1.5rem 0; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 1.1rem; color: #1f2937; }
.faq-answer { color: #4b5563; padding-bottom: 1.5rem; line-height: 1.6; }
.arrow-icon { transition: transform 0.3s ease; }
.arrow-icon.rotated { transform: rotate(180deg); }
.help-box { background-color: #F3F4F6; border-radius: 16px; padding: 1.5rem 2rem; display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-top: 3rem; }

footer { background-color: #f3f4f6; position: relative; padding-top: 4rem; padding-bottom: 0; overflow:hidden; }
.watermark-img { width: 90%; max-width: 1200px; opacity: 0.4; pointer-events: none; display: block; margin: 2rem auto 0 auto; }
.footer-link { color: #111827; font-weight: 600; font-size: 1.1rem; transition: color 0.2s; }
.footer-link:hover { color: #FF9F00; }
.legal-link { color: #6B7280; font-size: 1.1rem; text-decoration: none; }
.legal-link:hover { text-decoration: underline; }

.btn-primary { background: linear-gradient(90deg, #FF9F00 0%, #FF8C00 100%); color: white; padding: 10px 24px; border-radius: 50px; font-weight: 600; transition: transform 0.2s; box-shadow: 0 4px 6px -1px rgba(255, 140, 0, 0.3); }
.btn-primary:hover { transform: scale(1.05); }
.btn-secondary { background: white; color: #F68C0F; padding: 10px 24px; border-radius: 50px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; border: 2px solid white; }
.btn-secondary:hover { background: #fff8f0; color: #d67600; }

/* --- ESTILOS PERSONALIZADOS PARA SELECTBUTTON DE PRIMEVUE --- */

</style>

<template>
    <Head title="Ezy Ventas - Punto de Venta" />

    <!-- Contenedor Principal con la fuente Krub aplicada -->
    <div class="min-h-screen bg-white font-krub text-gray-800 overflow-x-hidden flex flex-col">
        
        <!-- NAV BAR (Comportamiento Dinámico) -->
        <nav 
            class="navbar-container py-4 px-4 md:px-8 flex justify-center"
            :class="{ 
                'navbar-scrolled bg-white/20 backdrop-blur-md shadow-md py-3': isScrolled, 
                'navbar-hidden': !isNavVisible, 
                
            }"
        >
            <div class="max-w-8xl w-full flex justify-between items-center">
                
                <!-- Logo (Pegado a la izquierda) -->
                <div class="flex items-center gap-2 -ml6"> 
                    <img src="/imagesLanding/ezy-logo-color.webp" alt="Ezy Ventas Logo" class="h-10 w-auto" />
                </div>

                <!-- Menú Central (Links Funcionales) -->
                <div class="hidden lg:flex items-center space-x-8 text-gray-600 font-medium text-lg">
                    <button @click="scrollToElement('features')" class="hover:text-[#F68C0F] transition duration-200">Funcionalidades</button>
                    <button @click="scrollToElement('prices')" class="hover:text-[#F68C0F] transition duration-200">Precios y planes</button>
                    <button @click="scrollToElement('faq')" class="hover:text-[#F68C0F] transition duration-200">Preguntas frecuentes</button>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="hover:text-[#F68C0F] transition duration-200">Contáctanos</a>
                </div>

                <!-- Botón Iniciar Sesión (Mejorado) -->
                <div class="flex items-center -mr-2">
                    <Link :href="$page.props.auth?.user ? route('dashboard') : route('login')">
                        <button class="btn-login-nav">Iniciar sesión</button>
                    </Link>
                </div>
            </div>
        </nav>

        <!-- HERO SECTION (Sin padding extra porque el nav no es fixed al inicio) -->
        <header class="bg-hero-combined relative pt-12 pb-24 px-6 md:px-12 lg:rounded-b-[50px] overflow-visible">
            <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8 items-center relative z-10">
                
                <!-- IZQUIERDA -->
                <div class="lg:col-span-5 flex flex-col text-center items-center space-y-12 order-1 lg:order-1">
                    <h1 class="text-4xl md:text-5xl font-extrabold text-[#2F2F2F] leading-tight uppercase drop-shadow-[0_4px_3px_rgba(0,0,0,0.4)]">
                        El punto de venta que se adapta a tu negocio
                    </h1>
                    
                    <p class="text-2xl text-gray-800 font-medium italic">
                        Simplifica tus operaciones, entiende tus números y vende más.
                    </p>

                    <h2 class="text-3xl font-bold text-gray-900">
                        Prueba por 30 días gratis
                    </h2>

                    <div class="flex flex-col sm:flex-row gap-4 pt-4 justify-center w-full"> 
                        <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="btn-secondary justify-center shadow-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25" />
                            </svg>
                            Contactar con ventas
                        </a>

                        <Link :href="route('register')" class="btn-primary flex items-center justify-center gap-2 shadow-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25" />
                            </svg>
                            Probar gratis ahora
                        </Link>
                    </div>
                </div>

                <!-- CENTRO -->
                <div class="lg:col-span-4 flex justify-center relative order-1 lg:order-2 mb-10 lg:mb-0">
                    <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[300px] h-[300px] bg-white/30 rounded-full blur-3xl -z-10"></div>
                    <img 
                        src="/imagesLanding/hero-phone.webp" 
                        alt="Sistema Punto de Venta Ezy Ventas App Móvil" 
                        class="w-64 md:w-72 lg:w-80 object-contain drop-shadow-2xl transform hover:scale-105 transition duration-500"
                    >
                </div>

                <!-- DERECHA: POP-OUT ZIG-ZAG (Manteniendo tu último diseño) -->
                <div class="lg:col-span-3 flex flex-col space-y-10 order-3 relative pl-4 lg:pl-0 justify-center h-full">
                    <div class="feature-card-pop card-gray self-end" data-aos="fade-left" data-aos-delay="100">
                        <img src="/imagesLanding/feature-reader.webp" alt="Lector" class="img-pop img-pop-right">
                        <div class="text-content-left">
                            <div class="text-md opacity-70 font-medium">Conexión con</div>
                            <div class="font-bold text-lg leading-tight">Lector de códigos</div>
                        </div>
                    </div>
                    <div class="feature-card-pop card-blue self-start ml-20" data-aos="fade-left" data-aos-delay="300">
                        <img src="/imagesLanding/feature-printer.webp" alt="Impresora" class="img-pop img-pop-left">
                        <div class="text-content-right">
                            <div class="text-md opacity-80 font-medium">Conexión con</div>
                            <div class="font-bold text-lg leading-tight">Impresoras de tickets y etiquetas</div>
                        </div>
                    </div>
                    <div class="feature-card-pop card-gray self-end" data-aos="fade-left" data-aos-delay="500">
                        <img src="/imagesLanding/feature-branch.webp" alt="Sucursal" class="img-pop img-pop-right">
                        <div class="text-content-left">
                            <div class="text-md opacity-70 font-medium">Gestión</div>
                            <div class="font-bold text-lg leading-tight">Multi sucursal</div>
                        </div>
                    </div>
                    <div class="feature-card-pop card-blue self-start ml-20" data-aos="fade-left" data-aos-delay="700">
                        <img src="/imagesLanding/feature-devices.webp" alt="Dispositivos" class="img-pop img-pop-left">
                        <div class="text-content-right">
                            <div class="text-md opacity-80 font-medium">Compatible con</div>
                            <div class="font-bold text-lg leading-tight">Cualquier dispositivo</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <!-- SECCIÓN 2: TIPOS DE NEGOCIO -->
            <section id="features" class="py-20 px-6 md:px-12 max-w-[1450px] mx-auto">
                <div class="text-center mb-16 space-y-4" data-aos="fade-up">
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900">Creado para negocios como el tuyo</h2>
                    <p class="text-lg text-gray-500 max-w-3xl mx-auto">Desde papelerías, supermercados, tiendas de ropa y mucho más. Ezy Ventas es la herramienta perfecta.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 text-3xl">
                    <div v-for="(biz, index) in businessTypes" :key="index" class="biz-card" :class="index % 2 === 0 ? 'biz-gradient-1' : 'biz-gradient-2'" data-aos="zoom-in">
                        <div class="glass-title">{{ biz.title }}</div>
                        <div class="flex-1 flex items-center justify-center w-full mt-4">
                            <img :src="biz.image" :alt="biz.alt" class="w-full h-full object-contain drop-shadow-lg hover:scale-105 transition duration-500">
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 3: DE PROBLEMAS A SOLUCIONES -->
            <h1 class="text-3xl font-bold text-center mb-12 text-gray-900">Funcionalidades</h1>
            <section class="py-20 px-6 md:px-12 max-w-[1450px] mx-40 space-y-32">
                <div class="flex flex-col md:flex-row items-center gap-12 lg:gap-24">
                    <div class="w-full md:w-1/2 flex justify-center" data-aos="fade-right">
                        <img src="/imagesLanding/solution-old-register.webp" alt="Caja registradora moderna" class="w-full max-w-lg object-contain drop-shadow-2xl hover:scale-105 transition duration-500">
                    </div>
                    <div class="w-full md:w-1/2 space-y-6 text-left" data-aos="fade-left">
                        <h3 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight">Dile adiós a lo <span class="text-red-500">obsoleto</span></h3>
                        <div class="text-lg text-gray-600 leading-relaxed space-y-4">
                            <p>Olvídate de los sistemas que te limitan. Con nuestra caja registradora moderna, <span class="font-bold text-gray-900 block mt-2">✅ Ahora puedes conectar varios usuarios a la vez.</span></p>
                            <p>Agiliza el cobro, evita filas interminables y permite que tu equipo trabaje simultáneamente sin interrupciones.</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col md:flex-row-reverse items-center gap-12 lg:gap-24">
                    <div class="w-full md:w-1/2 flex justify-center" data-aos="fade-left">
                        <img src="/imagesLanding/solution-modern-pos.webp" alt="Sistema moderno" class="w-full max-w-2xl object-contain drop-shadow-2xl hover:scale-105 transition duration-500">
                    </div>
                    <div class="w-full md:w-1/2 space-y-6 text-left" data-aos="fade-right">
                        <h3 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight">Toma el control <span class="text-blue-600">total</span></h3>
                        <div class="text-lg text-gray-600 leading-relaxed space-y-4">
                            <p>Visualiza el pulso de tu negocio al instante. <span class="font-bold text-blue-600 block mt-2">📊 Gráficas y reportes en tiempo real.</span></p>
                            <p>Toma decisiones inteligentes basadas en datos reales, controla tu inventario desde la nube y accede a tu información desde cualquier lugar.</p>
                        </div>
                    </div>
                </div>
                 <div class="flex flex-col md:flex-row items-center gap-12 lg:gap-24">
                    <div class="w-full md:w-1/2 flex justify-center" data-aos="fade-right">
                        <img src="/imagesLanding/solution-smart-inventory.webp" alt="Control de inventario" class="w-full max-w-2xl object-contain drop-shadow-2xl hover:scale-105 transition duration-500">
                    </div>
                    <div class="w-full md:w-1/2 space-y-6 text-left" data-aos="fade-left">
                        <h3 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight">Deja de perder <span class="text-red-500">dinero</span></h3>
                        <div class="text-lg text-gray-600 leading-relaxed space-y-4">
                            <p>El robo hormiga y el desorden en el almacén son fugas silenciosas de capital. <span class="font-bold text-gray-900 block mt-2">📦 Inventario automático y sin errores.</span></p>
                            <p>Con Ezy Ventas, cada producto está rastreado. Recibe alertas de stock bajo y olvídate de los conteos manuales inexactos.</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col md:flex-row-reverse items-center gap-12 lg:gap-24">
                    <div class="w-full md:w-1/2 flex justify-center" data-aos="fade-left">
                        <img src="/imagesLanding/solution-loyal-clients.webp" alt="Gestión de clientes" class="w-full max-w-2xl object-contain drop-shadow-2xl hover:scale-105 transition duration-500">
                    </div>
                    <div class="w-full md:w-1/2 space-y-6 text-left" data-aos="fade-right">
                        <h3 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight">Fideliza y vende <span class="text-blue-600">más</span></h3>
                        <div class="text-lg text-gray-600 leading-relaxed space-y-4">
                            <p>Un cliente anónimo es una oportunidad perdida. Deja atrás las libretas de fiado. <span class="font-bold text-blue-600 block mt-2">🤝 Gestiona créditos y conoce a tus VIPs.</span></p>
                            <p>Crea perfiles de clientes, conoce su historial de compras y ofréceles promociones personalizadas para que siempre regresen.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 4: CALCULADORA DE PRECIOS -->
            <section id="prices" class="py-20 px-6 md:px-12 max-w-7xl mx-auto">
                <div class="text-center mb-12" data-aos="fade-up">
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900">Crea el plan perfecto para tu negocio</h2>
                    <p class="text-gray-500 mt-2">Comienza con lo esencial, crece sin límites. Paga solo por lo que necesitas.</p>
                    
                    <div class="mt-8 flex justify-center">
                        <npm v-model="isAnnual" :options="paymentOptions" optionLabel="name" optionValue="value" :allowEmpty="false">
                            <template #option="slotProps">
                                <span class="mr-1">{{ slotProps.option.name }}</span>
                                <span v-if="slotProps.option.value" class="text-red-600 text-normal font-bold ml-1">-20%</span>
                            </template>
                        </SelectButton>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    <!-- PLAN BASE -->
                    <div class="lg:col-span-4" data-aos="fade-right">
                        <div class="price-card-left-container">
                            <div class="white-floating-panel">
                                <h3 class="text-xl font-bold text-orange-500 mb-2">Plan Básico/Esencial</h3>
                                <div class="flex items-baseline gap-1 mb-6">
                                    <span class="text-5xl font-extrabold text-gray-900">${{ isAnnual ? (199 * 0.8).toFixed(2) : '199.00' }}</span>
                                    <span class="text-gray-500 text-md">/mes</span>
                                </div>
                                <button class="w-full btn-primary mb-8 justify-center text-xl shadow-lg">Empezar ahora</button>

                                <p class="font-bold text-gray-900 mb-4 text-xl">Módulos incluidos</p>
                                <ul class="space-y-3 text-gray-600 text-xl">
                                    <li class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-[#0F96F6]"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg><span>Inicio</span></li>
                                    <li class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-[#0F96F6]"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg><span>Punto de venta</span></li>
                                    <li class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-[#0F96F6]"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg><span>Historial de ventas</span></li>
                                    <li class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-[#0F96F6]"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg><span>Productos</span></li>
                                    <li class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-[#0F96F6]"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg><span>Gastos</span></li>
                                    <li class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-[#0F96F6]"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg><span>Cajas</span></li>
                                </ul>
                            </div>
                            <div class="px-4">
                                <p class="font-bold text-gray-900 mb-4 tex-xl">Incluye:</p>
                                <ul class="space-y-3 text-gray-600 text-xl">
                                    <li class="flex items-center gap-3"><div class="w-6 h-6 rounded-full bg-[#D7E4F6] flex items-center justify-center text-[#0F96F6] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div><span>Hasta 3 usuarios</span></li>
                                    <li class="flex items-center gap-3"><div class="w-6 h-6 rounded-full bg-[#D7E4F6] flex items-center justify-center text-[#0F96F6] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div><span>1 Caja registradora</span></li>
                                    <li class="flex items-center gap-3"><div class="w-6 h-6 rounded-full bg-[#D7E4F6] flex items-center justify-center text-[#0F96F6] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div><span>500 productos</span></li>
                                    <li class="flex items-center gap-3"><div class="w-6 h-6 rounded-full bg-[#D7E4F6] flex items-center justify-center text-[#0F96F6] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div><span>1 sucursal</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- CONFIGURADOR -->
                    <div class="lg:col-span-8" data-aos="fade-left">
                        <div class="price-card-builder">
                            <h3 class="text-lg font-bold text-gray-900 mb-6">Adquiere otros módulos más para tu negocio</h3>
                            <div class="space-y-4 mb-8">
                                <div v-for="module in modules" :key="module.id" class="module-item">
                                    <button @click="toggleModule(module.id)" class="btn-toggle-module" :class="module.active ? 'btn-minus' : 'btn-plus'">{{ module.active ? '-' : '+' }}</button>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-center">
                                            <span class="text-lg transition-colors" :class="module.active ? 'text-blue-600 font-medium' : 'text-gray-600'">{{ module.name }}</span>
                                            <span v-if="module.price > 0" class="font-bold text-gray-900">${{ module.price.toFixed(2) }} <span class="text-xs text-gray-500 font-normal">/mes</span></span>
                                            <span v-else class="text-xs text-green-600 font-bold bg-green-100 px-2 py-1 rounded">Incluido</span>
                                        </div>
                                        <div v-if="module.subItems && module.subItems.length > 0" class="mt-2 ml-2 pl-4 border-l-2 border-dashed border-blue-300 space-y-1">
                                            <p v-for="sub in module.subItems" :key="sub" class="text-sm text-gray-500">-- {{ sub }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-4 mt-8">O funcionalidades adicionales:</h3>
                            <div class="space-y-4">
                                <div v-for="feat in features" :key="feat.id" class="module-item items-center">
                                    <button @click="feat.count > 0 ? feat.count = 0 : feat.count = 1" class="btn-toggle-module" :class="feat.count > 0 ? 'btn-minus' : 'btn-plus'">{{ feat.count > 0 ? '-' : '+' }}</button>
                                    <div class="flex-1 flex items-center justify-between">
                                        <span class="text-lg transition-colors" :class="feat.count > 0 ? 'text-blue-600 font-medium' : 'text-gray-600'">{{ feat.name }}</span>
                                        <div v-if="feat.count > 0" class="counter-control">
                                            <button @click="decrementFeature(feat.id)" class="counter-btn">-</button>
                                            <span class="text-sm font-bold w-4 text-center">{{ feat.count }}</span>
                                            <button @click="incrementFeature(feat.id)" class="counter-btn">+</button>
                                        </div>
                                        <span class="font-bold text-gray-900">${{ feat.price.toFixed(2) }} <span class="text-xs text-gray-500 font-normal">/mes</span></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-10 bg-white rounded-3xl p-6 text-center shadow-sm border border-blue-100">
                                <div class="flex items-center justify-center gap-2 mb-4"><span class="text-5xl font-extrabold text-gray-900">${{ totalPrice }}</span><span class="text-gray-500 text-xl">/mes</span></div>
                                <button class="px-12 py-3 btn-primary text-lg shadow-xl">Empezar ahora</button>
                                <p class="text-xs text-gray-400 mt-4">Incluye el plan básico /esencial + módulos y funcionalidades agregadas</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 5: PREGUNTAS FRECUENTES -->
            <section id="faq" class="py-20 px-6 md:px-12 max-w-5xl mx-auto">
                <h2 class="text-3xl font-bold text-center mb-12 text-gray-900">Preguntas frecuentes</h2>
                <div class="space-y-0 border-t border-gray-200">
                    <div v-for="(faq, index) in faqs" :key="index" class="faq-item">
                        <div class="faq-question" @click="toggleFaq(index)">
                            <span>{{ faq.question }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 arrow-icon" :class="{ 'rotated': faq.open }"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                        <div v-show="faq.open" class="faq-answer">{{ faq.answer }}</div>
                    </div>
                </div>
                <div class="help-box mt-12 flex flex-col md:flex-row items-center justify-between bg-gray-100 rounded-2xl p-6">
                    <div class="flex items-center gap-4">
                        <img src="/imagesLanding/faq-info-icon.webp" alt="Info" class="w-12 h-12 object-contain" />
                        <div>
                            <h4 class="font-bold text-lg text-gray-900">¿Tienes alguna pregunta?</h4>
                            <p class="text-gray-600 text-normal">Sí no encontraste la respuesta, no dudes en contactárnos.</p>
                        </div>
                    </div>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="mt-4 md:mt-0 px-6 py-2 border border-gray-300 rounded-full text-gray-700 font-semibold hover:bg-white transition-colors">Contáctanos</a>
                </div>
            </section>

            <!-- NUEVA SECCIÓN: BANNER EZY RESTAURANT -->
            <section class="py-20 px-6 md:px-12 max-w-7xl mx-auto">
                <div class="restaurant-banner-container text-center py-20 px-8 md:px-24" data-aos="zoom-in">
                    <img src="/imagesLanding/banner-food-deco.webp" alt="Ingredientes gourmet" class="food-deco" />
                    <div class="relative z-10 max-w-4xl mx-auto">
                        <p class="text-xl md:text-2xl font-medium mb-2 opacity-90 tracking-wide">Y esto es solo el comienzo.</p>
                        <h2 class="text-4xl md:text-6xl font-bold mb-6">
                            Próximamente: <span class="text-[#FF9F00]">Ezy restaurant</span>
                        </h2>
                        <p class="text-lg md:text-xl opacity-80 mb-10 max-w-2xl mx-auto">Agrega órdenes, gestiona mesas, pedidos, turnos de tu personal en un solo lugar.</p>
                        <div class="flex flex-col sm:flex-row justify-center gap-4 max-w-lg mx-auto">
                            <input type="email" placeholder="Tu correo electrónico" class="input-round" />
                            <button class="px-8 py-4 rounded-full text-lg font-bold shadow-lg bg-[#F68C0F] hover:bg-[#e6810e] text-white transition-colors whitespace-nowrap">Notifíquenme</button>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- FOOTER -->
        <footer ref="footerRef" class="relative bg-[#f3f4f6] pt-16 overflow-hidden flex flex-col">
            <!-- Contenido del Footer -->
            <div class="relative z-10 max-w-8xl mx-auto px-6 md:px-12 w-full">
                <div class="flex flex-col md:flex-row justify-between items-center gap-8 mb-8">
                    <div>
                        <!-- LOGO COLOR EN FOOTER -->
                        <img src="/imagesLanding/ezy-logo-color.webp" alt="Ezy Ventas" class="h-16 w-auto" />
                    </div>
                    <div class="flex flex-wrap justify-center gap-6 md:gap-8">
                        <button @click="scrollToElement('features')" class="footer-link">Funcionalidades</button>
                        <button @click="scrollToElement('prices')" class="footer-link">Precios y planes</button>
                        <button @click="scrollToElement('faq')" class="footer-link">Preguntas frecuentes</button>
                        <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="footer-link">Contáctanos</a>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-gray-500 font-medium text-md">By</span>
                        <!-- Logo DTW SIN OPACIDAD -->
                        <img src="/imagesLanding/dtw-logo.webp" alt="DTW Logo" class="h-14 w-auto object-contain" />
                    </div>
                </div>
                <div class="flex justify-center gap-6 mb-6 border-b border-gray-200 pb-6">
                    <a :href="route('policy.show')" target="_blank" class="legal-link">Política de privacidad</a>
                    <a :href="route('terms.show')" target="_blank" class="legal-link">Términos y condiciones</a>
                </div>
                <div class="text-center text-gray-400 text-lg mb-12">
                    Copyright © 2026 | Todos los derechos reservados por Ezy Ventas
                </div>
            </div>

            <!-- Marca de agua al final del todo -->
            <div class="w-full flex justify-center">
                <img src="/imagesLanding/ezy-watermark.webp" alt="Ezy Ventas Watermark" class="w-full max-w-[1400px] opacity-40 block" />
            </div>
        </footer>

    </div>
</template>