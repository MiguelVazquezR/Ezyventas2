<script setup>
import { Head, Link } from '@inertiajs/vue3';
// Importamos los componentes personalizados
import DashboardGraph from '@/Components/DashboardGraph.vue';
import CustomerRelationship from '@/Components/CustomerRelationship.vue'; // NUEVO COMPONENTE
import AOS from 'aos';
import 'aos/dist/aos.css';
import { ref, computed, onMounted, onUnmounted } from 'vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    laravelVersion: String,
    phpVersion: String,
});

// --- NAVBAR LOGIC ---
const footerRef = ref(null);
const isNavVisible = ref(true);
const isScrolled = ref(false);

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
    if (footerRef.value) observer.observe(footerRef.value);
};

// --- FAQ LOGIC ---
const faqs = ref([
    { question: '¿Necesito hardware especial?', answer: 'No, funciona desde cualquier dispositivo, el sistema se encuentra en la nube. Puedes usar tu computadora, tablet o celular actual.', open: false },
    { question: '¿Hay un contrato forzoso?', answer: 'No, puedes cancelar tu suscripción en cualquier momento sin penalizaciones. Creemos en ganarnos tu confianza mes a mes.', open: false },
    { question: '¿Ofrecen soporte técnico?', answer: 'Sí, contamos con soporte técnico prioritario vía chat y correo electrónico para todos nuestros planes, sin costo extra.', open: false }
]);

const toggleFaq = (index) => {
    faqs.value[index].open = !faqs.value[index].open;
};

// --- DATOS MOCK PARA INVENTARIO INTERACTIVO (STOCK REAL AGREGADO) ---
// Agregamos clases de hover específicas para tailwind
const inventoryMock = [
    { name: 'Nike Air Max', pieces: 124, icon: '👟', hoverClass: 'hover:shadow-green-200 hover:border-green-300', dotClass: 'bg-green-500' },
    { name: 'Camiseta Basic', pieces: 42, icon: '👕', hoverClass: 'hover:shadow-yellow-200 hover:border-yellow-300', dotClass: 'bg-yellow-500' },
    { name: 'Gorra NY', pieces: 8, icon: '🧢', hoverClass: 'hover:shadow-red-200 hover:border-red-300', dotClass: 'bg-red-500' },
    { name: 'Jeans Slim', pieces: 85, icon: '👖', hoverClass: 'hover:shadow-green-200 hover:border-green-300', dotClass: 'bg-green-500' },
    { name: 'Calcetines', pieces: 200, icon: '🧦', hoverClass: 'hover:shadow-green-200 hover:border-green-300', dotClass: 'bg-green-500' },
    { name: 'Bufanda', pieces: 0, icon: '🧣', hoverClass: 'hover:shadow-red-200 hover:border-red-300', dotClass: 'bg-red-500' },
];

// --- PRECIOS Y MÓDULOS ---
const isAnnual = ref(true); // Default true para presumir descuento
const basePriceMonthly = 199;

const modules = ref([
    { id: 'reportes', name: 'Reporte financiero', price: 30, active: false, description: 'Estado de resultados y balances.' },
    { id: 'clientes', name: 'Clientes y Créditos', price: 40, active: false, description: 'Cuentas por cobrar y fidelización.' }, 
    { id: 'servicios', name: 'Servicios', price: 45, active: false, description: 'Órdenes de servicio y reparaciones.', subItems: ['Catálogo', 'Órdenes'] }, 
    { id: 'cotizaciones', name: 'Cotizaciones', price: 25, active: false, description: 'Envía propuestas profesionales en PDF.' },
    { id: 'ecommerce', name: 'Tienda en línea', price: 99, active: false, description: 'Vende tus productos en internet sincronizado.' },
]);

const features = ref([
    { id: 'users', name: 'Usuarios extra', price: 5, count: 0 },
    { id: 'cajas', name: 'Cajas extra', price: 10, count: 0 },
    { id: 'products', name: 'Productos extra', price: 2, count: 0 }, 
    { id: 'branches', name: 'Sucursales extra', price: 50, count: 0 },
]);

// Cálculo de totales separados para mostrar el desglose
const rawMonthlyTotal = computed(() => {
    let total = basePriceMonthly;
    modules.value.forEach(m => { if (m.active) total += m.price; });
    features.value.forEach(f => { total += (f.count * f.price); });
    return total;
});

const finalPrice = computed(() => {
    if (isAnnual.value) {
        return (rawMonthlyTotal.value * 0.8).toFixed(2);
    }
    return rawMonthlyTotal.value.toFixed(2);
});

// Helpers para interactividad
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
    { title: 'Ropa y Boutiques', image: '/imagesLanding/biz-clothing.webp', alt: 'Artículos de ropa', shortDesc: 'Gestiona tallas, colores y temporadas.', fullDesc: 'La solución perfecta para boutiques. Controla tu inventario con variantes complejas sin perder la cabeza.', features: ['Matriz de Tallas y Colores', 'Impresión de etiquetas de código de barras', 'Gestión de cambios y devoluciones', 'Reportes de prendas más vendidas'] },
    { title: 'Supermercados', image: '/imagesLanding/biz-supermarket.webp', alt: 'Abarrotes', shortDesc: 'Venta rápida y control de caducidad.', fullDesc: 'Agilidad en la caja es clave. Escanea códigos rápidamente y gestiona miles de productos sin demoras.', features: ['Venta ultra rápida con scanner', 'Control de inventario mínimo y máximo', 'Venta a granel (bascula)', 'Múltiples cajeros simultáneos'] },
    { title: 'Papelerías', image: '/imagesLanding/biz-stationery.webp', alt: 'Papelería', shortDesc: 'Miles de artículos pequeños bajo control.', fullDesc: 'Desde un lápiz hasta paquetes escolares. Organiza la inmensa variedad de artículos pequeños fácilmente.', features: ['Venta unitaria y por paquete', 'Kits escolares pre-armados', 'Búsqueda rápida de productos', 'Control de merma y robo hormiga'] },
    { title: 'Ferreterías', image: '/imagesLanding/biz-hardware.webp', alt: 'Herramientas', shortDesc: 'Inventario pesado y venta a granel.', fullDesc: 'Administra inventarios complejos, ventas por metro, kilo o pieza y mantén el control de tu almacén.', features: ['Venta fraccionada (metros, kilos)', 'Control de lotes', 'Catálogo con fotos para mostrador', 'Facturación instantánea'] },
    { title: 'Servicios', image: '/imagesLanding/biz-services.webp', alt: 'Servicios', shortDesc: 'Reparaciones, citas y mano de obra.', fullDesc: 'No solo productos, vende tu tiempo y experiencia. Gestiona órdenes de servicio y seguimiento.', features: ['Órdenes de servicio', 'Control de estatus', 'Mano de obra', 'Notificaciones WhatsApp'] },
];

const selectedBusiness = ref(null);
const isModalOpen = ref(false);
const openBusinessModal = (biz) => { selectedBusiness.value = biz; isModalOpen.value = true; document.body.style.overflow = 'hidden'; };
const closeBusinessModal = () => { isModalOpen.value = false; setTimeout(() => { selectedBusiness.value = null; }, 300); document.body.style.overflow = ''; };
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Krub:wght@200;300;400;500;600;700&display=swap');

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
.counter-control { display: flex; align-items: center; gap: 8px; background: white; padding: 2px 8px; border-radius: 8px; }
.counter-btn { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #f0f0f0; cursor: pointer; font-size: 14px; color: #555; border-radius: 50%; }
.counter-btn:hover { background: #e0e0e0; }
footer { background-color: #f9fafb; position: relative; padding-top: 4rem; padding-bottom: 0; overflow:hidden; }
.footer-link { color: #111827; font-weight: 600; font-size: 1.1rem; transition: color 0.2s; }
.footer-link:hover { color: #F68C0F; }
.legal-link { color: #6B7280; font-size: 1.1rem; text-decoration: none; }
.legal-link:hover { text-decoration: underline; }

/* --- ESTILOS PRECIOS (CUSTOM SWITCH & BUILDER) --- */
.custom-switch {
    position: relative;
    display: inline-flex;
    background-color: #F3F4F6;
    border-radius: 999px;
    padding: 4px;
    cursor: pointer;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
}
.switch-slider {
    position: absolute;
    top: 4px;
    bottom: 4px;
    left: 4px;
    width: calc(50% - 4px);
    background-color: white;
    border-radius: 999px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}
.switch-slider.active-right {
    transform: translateX(100%);
}
.switch-label {
    position: relative;
    z-index: 10;
    padding: 8px 24px;
    font-size: 0.95rem;
    font-weight: 500;
    color: #6B7280;
    transition: color 0.3s;
    user-select: none;
    width: 50%;
    text-align: center;
}
.switch-label.active {
    color: #111827;
    font-weight: 700;
}

/* Modulo de Precios - Estilo Restaurado (Más grande y legible) */
.module-row {
    display: flex;
    align-items: center;
    padding: 16px; /* Padding restaurado */
    background: white;
    border-radius: 16px;
    margin-bottom: 12px; /* Margen restaurado */
    border: 1px solid transparent;
    transition: all 0.2s ease;
}
.module-row.active {
    border-color: #F68C0F;
    background: #fffbf5;
    box-shadow: 0 4px 12px rgba(246, 140, 15, 0.08);
}
.checkbox-circle {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid #D1D5DB;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
}
.module-row.active .checkbox-circle {
    background-color: #F68C0F;
    border-color: #F68C0F;
    color: white;
}

/* Summary Bar */
.pricing-summary-bar {
    background: white;
    border-radius: 20px;
    box-shadow: 0 20px 50px -10px rgba(0,0,0,0.1);
    border: 1px solid rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    md:flex-row;
    align-items: center;
    justify-content: space-between;
    padding: 24px 40px;
    margin-top: 32px;
}
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
                    <img src="/imagesLanding/ezy-logo-color.webp" alt="Ezy Ventas Logo" class="h-9 w-auto" />
                </div>
                <div class="hidden lg:flex items-center space-x-10 text-gray-500 font-medium text-[0.95rem] tracking-wide">
                    <button @click="scrollToElement('features')" class="hover:text-[#F68C0F] transition duration-200">Funcionalidades</button>
                    <button @click="scrollToElement('prices')" class="hover:text-[#F68C0F] transition duration-200">Precios</button>
                    <button @click="scrollToElement('faq')" class="hover:text-[#F68C0F] transition duration-200">Preguntas frecuentes</button>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="hover:text-[#F68C0F] transition duration-200">Contacto</a>
                </div>
                <div class="flex items-center">
                    <Link :href="$page.props.auth?.user ? route('dashboard') : route('login')">
                        <button class="btn-login-nav">Entrar</button>
                    </Link>
                </div>
            </div>
        </nav>

        <!-- HERO SECTION -->
        <header 
            ref="heroContainer"
            @mousemove="handleMouseMove" 
            class="hero-wrapper relative min-h-screen pt-24 pb-0"
        >
            <div class="relative z-30 text-center px-4 mb-4" data-aos="fade-down">
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold leading-tight">
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
                                <img src="/imagesLanding/feature-reader.webp" alt="Scanner" class="w-10 h-10 object-contain">
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
                    <p class="text-gray-700 font-medium text-lg">Prueba gratuita de 30 días.</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-4 w-full md:w-auto">
                    <Link :href="route('register')" class="btn-apple-primary w-full sm:w-auto gap-2">
                        Empezar prueba gratis
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </Link>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="btn-apple-secondary w-full sm:w-auto gap-2">Ver Demo</a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <!-- SECCIÓN 2: TIPOS DE NEGOCIO -->
            <section id="features" class="py-24 px-6 md:px-12 max-w-[1450px] mx-auto bg-gray-50/50">
                <div class="text-center mb-16 space-y-4" data-aos="fade-up">
                    <h2 class="text-3xl md:text-5xl font-bold text-gray-900 tracking-tight">Diseñado para tu giro</h2>
                    <p class="text-lg text-gray-500 max-w-3xl mx-auto">Selecciona tu tipo de negocio y descubre por qué Ezy Ventas es tu mejor aliado.</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6">
                    <div 
                        v-for="(biz, index) in businessTypes" 
                        :key="index" 
                        class="biz-card-bento group"
                        @click="openBusinessModal(biz)"
                        data-aos="fade-up"
                        :data-aos-delay="index * 100"
                    >
                        <div class="biz-img-container">
                            <img :src="biz.image" :alt="biz.alt" class="biz-img">
                        </div>
                        <div class="mt-auto space-y-2">
                            <h3 class="text-xl font-bold text-gray-900 group-hover:text-[#F68C0F] transition-colors">{{ biz.title }}</h3>
                            <p class="text-sm text-gray-500 leading-snug">{{ biz.shortDesc }}</p>
                            <div class="pt-4 flex items-center gap-2 text-sm font-semibold text-[#F68C0F] action-arrow">
                                <span>Ver beneficios</span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                            </div>
                        </div>
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
                        Olvídate de las interfaces complejas. Cada herramienta ha sido diseñada para eliminar la fricción de tu día a día.
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
                                <h3 class="text-3xl font-bold text-gray-900 mb-4">Toma decisiones,<br/>no adivinanzas.</h3>
                                <p class="text-gray-600">Visualiza el pulso de tu negocio al instante. Gráficas de ventas, productos estrella y rendimiento de personal en un solo lugar.</p>
                            </div>
                            <!-- AQUI ESTÁ EL NUEVO COMPONENTE INTEGRADO -->
                            <div class="w-full h-full flex items-end justify-center -mb-8 md:-mb-12">
                                <DashboardGraph />
                            </div>
                        </div>
                        <div class="feature-card-modern flex flex-col justify-between" data-aos="fade-left" data-aos-delay="200">
                            <div class="mb-8">
                                <h3 class="text-3xl font-bold text-gray-900 mb-4">Venta ágil<br/>y sin filas.</h3>
                                <p class="text-gray-600">Olvídate de la caja única. Conecta múltiples dispositivos simultáneamente. Tu equipo vende desde tabletas, celulares o computadoras al mismo tiempo.</p>
                            </div>
                            <div class="relative h-64 flex justify-center items-end">
                                <img src="/imagesLanding/solution-old-register.webp" alt="Multi-usuario" class="w-3/4 object-contain drop-shadow-xl transition-transform duration-500 hover:-translate-y-4">
                            </div>
                        </div>
                    </div>
                    
                    <!-- NUEVO COMPONENTE DE CLIENTES INTEGRADO AQUÍ (Versión Light/Moderna) -->
                    <div class="feature-card-modern flex flex-col md:flex-row items-center gap-12 bg-white text-gray-900 p-12 relative overflow-hidden" data-aos="fade-up">
                        <div class="w-full md:w-1/2 space-y-6 relative z-10">
                            <h3 class="text-3xl md:text-4xl font-bold text-gray-900">Fideliza y vende más.</h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Un cliente anónimo es una oportunidad perdida. Crea perfiles, otorga créditos y conoce quiénes son tus clientes VIP para ofrecerles promociones personalizadas.</p>
                            <button class="bg-[#F68C0F] text-white px-8 py-3 rounded-full font-bold hover:bg-[#e57f00] shadow-lg hover:shadow-xl transition-all">Ver herramientas de Marketing</button>
                        </div>
                        <div class="w-full md:w-1/2 flex justify-center relative z-10">
                            <CustomerRelationship />
                        </div>
                        
                        <!-- Fondo decorativo sutil -->
                        <div class="absolute inset-0 bg-gradient-to-r from-gray-50 to-white z-0 pointer-events-none"></div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 4: PRECIOS RENOVADA (SMART BUILDER - ESTILO ORIGINAL) -->
            <section id="prices" class="py-32 px-6 md:px-12 max-w-7xl mx-auto">
                <div class="text-center mb-16" data-aos="fade-up">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 tracking-tight">Arma tu plan ideal</h2>
                    <p class="text-gray-500 mt-4 text-lg">Todo comienza con nuestro plan esencial. Agrega solo lo que necesitas.</p>
                    
                    <!-- CUSTOM SWITCH -->
                    <div class="mt-10 flex justify-center">
                        <div class="custom-switch w-64" @click="isAnnual = !isAnnual">
                            <div class="switch-slider" :class="{ 'active-right': isAnnual }"></div>
                            <div class="switch-label" :class="{ 'active': !isAnnual }">Mensual</div>
                            <div class="switch-label" :class="{ 'active': isAnnual }">Anual <span class="text-[10px] text-red-500 font-bold ml-1">-20%</span></div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col lg:flex-row gap-8 items-start">
                    
                    <!-- PLAN BASE (Columna Izquierda) -->
                    <div class="w-full lg:w-1/3" data-aos="fade-right">
                        <div class="bg-white rounded-3xl p-8 border border-gray-100 shadow-xl relative overflow-hidden h-full">
                            <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-[#F68C0F] to-orange-400"></div>
                            
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">Plan Esencial</h3>
                            <p class="text-gray-600 text-md mb-6">Todo lo que necesitas para operar.</p>
                            
                            <div class="flex items-baseline gap-1 mb-8">
                                <span class="text-5xl font-extrabold text-gray-900">${{ isAnnual ? (199 * 0.8).toFixed(0) : '199' }}</span>
                                <span class="text-gray-500 text-lg">/mes</span>
                            </div>

                            <p class="font-bold text-gray-900 mb-4 text-normal uppercase tracking-wide">Incluye siempre:</p>
                            <ul class="space-y-4 text-gray-800 text-lg">
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>Inicio</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>Punto de venta (POS)</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>Historal de ventas</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>Control de inventario</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>Gastos</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>Hasta 3 usuarios</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>1 Caja</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>1 Sucursal</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center text-[#F68C0F] shrink-0"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg></div>
                                    <span>500 productos</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- BUILDER (Columna Derecha) -->
                    <div class="w-full lg:w-2/3" data-aos="fade-left">
                        <div class="bg-gray-50 rounded-3xl p-8 h-full border border-gray-100">
                            <h3 class="text-xl font-bold text-gray-900 mb-6">Personaliza tu experiencia</h3>
                            
                            <!-- Módulos (Lista Vertical con estilo premium) -->
                            <div class="space-y-3 mb-8">
                                <div v-for="module in modules" :key="module.id" 
                                     class="module-row cursor-pointer" 
                                     :class="{ 'active': module.active }"
                                     @click="toggleModule(module.id)">
                                    
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-1">
                                            <span class="font-bold text-gray-900">{{ module.name }}</span>
                                            <span v-if="module.active" class="bg-orange-100 text-[#F68C0F] text-[10px] font-bold px-2 py-0.5 rounded-full">AGREGADO</span>
                                        </div>
                                        <p class="text-md text-gray-500">{{ module.description }}</p>
                                    </div>
                                    
                                    <div class="text-right flex flex-col items-end gap-2 pl-4">
                                        <span class="font-bold text-gray-900">+${{ module.price }}</span>
                                        <!-- Checkbox visual -->
                                        <div class="checkbox-circle">
                                            <svg v-if="module.active" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Extras Counter (Grid) -->
                            <h4 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Extras opcionales</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div v-for="feat in features" :key="feat.id" class="bg-white p-4 rounded-xl flex items-center justify-between border border-gray-100">
                                    <div>
                                        <p class="font-medium text-gray-900">{{ feat.name }}</p>
                                        <p class="text-md text-gray-500">+${{ feat.price }} / c.u.</p>
                                    </div>
                                    <div class="counter-control">
                                        <button @click="decrementFeature(feat.id)" class="counter-btn">-</button>
                                        <span class="text-sm font-bold w-6 text-center">{{ feat.count }}</span>
                                        <button @click="incrementFeature(feat.id)" class="counter-btn">+</button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- BARRA DE RESUMEN INTELIGENTE -->
                <div class="pricing-summary-bar flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex flex-col md:flex-row items-center gap-6">
                        <div class="text-center md:text-left">
                            <p class="text-gray-600 text-md mb-1">Total estimado {{ isAnnual ? 'anual (mes)' : 'mensual' }}</p>
                            <div class="flex items-center gap-2 justify-center md:justify-start">
                                <span class="text-4xl font-extrabold text-gray-900">${{ finalPrice }}</span>
                                <span class="text-gray-600">MXN</span>
                            </div>
                        </div>
                        <div class="hidden md:block h-10 w-px bg-gray-200"></div>
                        <div class="text-md text-gray-600 max-w-xs text-center md:text-left">
                            Incluye <strong>Plan Esencial</strong> <span v-if="rawMonthlyTotal > 199">+ Módulos seleccionados</span>.
                            <br>Sin cargos ocultos.
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-2">
                        <Link :href="route('register')" class="btn-apple-primary px-10 py-4 text-lg shadow-xl hover:shadow-2xl">
                            Comenzar prueba gratis
                        </Link>
                        <span class="text-md text-gray-600 font-medium">30 días sin costo</span>
                    </div>
                </div>

            </section>

            <!-- SECCIÓN 5: PREGUNTAS FRECUENTES -->
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
                    <h4 class="text-2xl font-bold text-gray-900 mb-4">¿Aún tienes dudas?</h4>
                    <p class="text-gray-500 mb-8 max-w-lg mx-auto">Nuestro equipo de soporte está listo para ayudarte en cualquier momento.</p>
                    <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="inline-flex items-center justify-center gap-2 bg-white border border-gray-200 text-gray-900 px-8 py-3 rounded-full font-semibold hover:border-[#F68C0F] hover:text-[#F68C0F] transition-all shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                        </svg>
                        Contactar Soporte
                    </a>
                </div>
            </section>

            <!-- NUEVA SECCIÓN: BANNER EZY RESTAURANT -->
            <section class="py-32 px-6 md:px-12 max-w-7xl mx-auto">
                <div class="coming-soon-wrapper relative py-28 px-8 md:px-20 text-center flex flex-col items-center justify-center min-h-[500px]" data-aos="zoom-in">
                    <div class="coming-soon-glow"></div>
                    <div class="relative z-10 max-w-3xl mx-auto space-y-8">
                        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border border-gray-700 bg-gray-900/50 backdrop-blur-sm">
                            <span class="relative flex h-2 w-2">
                              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#F68C0F] opacity-75"></span>
                              <span class="relative inline-flex rounded-full h-2 w-2 bg-[#F68C0F]"></span>
                            </span>
                            <span class="text-xs font-bold text-gray-300 tracking-widest uppercase">En Desarrollo</span>
                        </div>
                        <div>
                            <p class="text-xl md:text-2xl text-gray-400 font-light mb-2 tracking-wide">Algo delicioso se está cocinando.</p>
                            <h2 class="text-5xl md:text-7xl font-bold tracking-tighter text-white drop-shadow-2xl">
                                Ezy <span class="text-transparent bg-clip-text bg-gradient-to-r from-gray-200 via-white to-gray-400">Restaurant</span>
                            </h2>
                        </div>
                        <p class="text-lg md:text-xl text-gray-400 max-w-xl mx-auto leading-relaxed">
                            La gestión de mesas, comandas y cocina reinventada. <br class="hidden md:block">Únete a la lista de espera y sé el primero en probarlo.
                        </p>
                        <div class="flex justify-center w-full pt-4">
                            <div class="glass-input-container w-full max-w-md p-1.5">
                                <input type="email" placeholder="Tu correo electrónico" class="glass-input" />
                                <button class="btn-glow">Notifíquenme</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <footer ref="footerRef" class="relative bg-[#f9fafb] pt-16 overflow-hidden flex flex-col">
            <div class="relative z-10 max-w-8xl mx-auto px-6 md:px-12 w-full">
                <div class="flex flex-col md:flex-row justify-between items-center gap-8 mb-8">
                    <div>
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
            <div class="w-full flex justify-center">
                <img src="/imagesLanding/ezy-watermark.webp" alt="Ezy Ventas Watermark" class="w-full max-w-[1400px] opacity-40 block" />
            </div>
        </footer>

        <!-- MODAL DE DETALLE DE NEGOCIO -->
        <Teleport to="body">
            <transition name="modal">
                <div v-if="isModalOpen && selectedBusiness" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-md" @click="closeBusinessModal"></div>
                    <transition name="modal-content" appear>
                        <div class="relative bg-white rounded-3xl w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-2xl flex flex-col md:flex-row overflow-hidden">
                            <button @click="closeBusinessModal" class="absolute top-4 right-4 z-20 bg-white/50 hover:bg-white p-2 rounded-full transition-all">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>
                            <div class="w-full md:w-2/5 bg-gray-100 flex items-center justify-center p-8 md:p-12">
                                <img :src="selectedBusiness.image" :alt="selectedBusiness.alt" class="w-full h-auto object-contain drop-shadow-xl max-h-[300px]">
                            </div>
                            <div class="w-full md:w-3/5 p-8 md:p-12 flex flex-col justify-center">
                                <h3 class="text-3xl font-bold text-gray-900 mb-2">{{ selectedBusiness.title }}</h3>
                                <p class="text-lg text-gray-500 mb-6 font-medium">{{ selectedBusiness.fullDesc }}</p>
                                <div class="space-y-4 mb-8">
                                    <h4 class="text-sm font-bold text-gray-400 uppercase tracking-wider">Beneficios Clave</h4>
                                    <ul class="space-y-3">
                                        <li v-for="(feature, idx) in selectedBusiness.features" :key="idx" class="flex items-start gap-3">
                                            <div class="mt-1 w-5 h-5 rounded-full bg-green-100 flex items-center justify-center text-green-600 flex-shrink-0">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                            </div>
                                            <span class="text-gray-700">{{ feature }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-4 mt-auto">
                                    <Link :href="route('register')" class="btn-apple-primary justify-center text-sm px-8 py-3">
                                        Probar gratis para mi negocio
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