<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import { ref } from 'vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

// --- DATOS DE CATEGORÍAS ---
const topics = [
    { 
        id: 'steps',
        title: 'Primeros Pasos', 
        icon: '🚀', 
        desc: 'Configura tu cuenta y realiza tu primera venta.',
        modalTitle: 'Comienza con el pie derecho',
        modalContent: 'steps'
    },
    { 
        id: 'billing',
        title: 'Facturación', 
        icon: '💳', 
        desc: 'Gestiona tus pagos, facturas y suscripciones.',
        modalTitle: 'Tu facturación clara y simple',
        modalContent: 'billing'
    },
    { 
        id: 'account',
        title: 'Mi Cuenta', 
        icon: '👤', 
        desc: 'Actualiza tu perfil, seguridad y preferencias.',
        modalTitle: 'Gestiona tu perfil',
        modalContent: 'text',
        textBody: 'Mantén tu cuenta segura. Aquí puedes cambiar tu contraseña, habilitar la autenticación de dos factores y personalizar tus preferencias de notificación.'
    },
    { 
        id: 'inventory',
        title: 'Inventario', 
        icon: '📦', 
        desc: 'Dudas sobre carga masiva, stock y alertas.',
        modalTitle: 'Control total de tu stock',
        modalContent: 'text',
        textBody: 'Aprende a realizar cargas masivas desde Excel, configurar alertas de stock bajo y gestionar múltiples almacenes para que nunca te quedes sin productos.'
    },
    { 
        id: 'reports',
        title: 'Reportes', 
        icon: '📊', 
        desc: 'Entiende tus métricas y exporta datos.',
        modalTitle: 'Tus números, claros',
        modalContent: 'graph'
    },
    { 
        id: 'hardware',
        title: 'Hardware', 
        icon: '🖨️', 
        desc: 'Configuración de impresoras y lectores.',
        modalTitle: 'Conecta tu equipo',
        modalContent: 'hardware' // CONTENIDO ACTUALIZADO
    },
];

// --- DATOS FAQ ---
const faqs = ref([
    { question: '¿Cómo restablezco mi contraseña?', answer: 'Ve a la página de inicio de sesión y haz clic en "¿Olvidaste tu contraseña?". Te enviaremos un enlace seguro a tu correo electrónico para crear una nueva.', open: false },
    { question: '¿Puedo cambiar mi plan en cualquier momento?', answer: 'Sí. Puedes actualizar o degradar tu plan desde la sección de Facturación en tu Dashboard. Los cambios se aplicarán en el siguiente ciclo de cobro.', open: false },
    { question: '¿Cómo contacto a soporte técnico directo?', answer: 'Si esta guía no resuelve tu duda, puedes usar el formulario de abajo o escribirnos directamente a nuestro WhatsApp de soporte prioritario.', open: false },
    { question: '¿Mis datos están seguros?', answer: 'Absolutamente. Utilizamos encriptación de grado bancario para todas las transacciones y respaldos diarios de tu base de datos.', open: false },
]);

const toggleFaq = (index) => {
    faqs.value.forEach((f, i) => {
        if (i !== index) f.open = false;
    });
    faqs.value[index].open = !faqs.value[index].open;
};

// --- LOGICA DEL MODAL ---
const activeTopic = ref(null);
const isModalOpen = ref(false);

const openModal = (topic) => {
    activeTopic.value = topic;
    isModalOpen.value = true;
    document.body.style.overflow = 'hidden'; 
};

const closeModal = () => {
    isModalOpen.value = false;
    setTimeout(() => {
        activeTopic.value = null;
    }, 300);
    document.body.style.overflow = '';
};

// --- FORMULARIO DE CONTACTO ---
const form = ref({
    name: '',
    email: '',
    topic: 'General',
    message: ''
});

const isSending = ref(false);

const submitSupport = () => {
    isSending.value = true;
    setTimeout(() => {
        alert("¡Mensaje enviado! Nuestro equipo te contactará pronto.");
        isSending.value = false;
        form.value.message = '';
    }, 1500);
};

// --- DATOS MOCK PARA LA GRÁFICA ---
const chartData = [40, 70, 45, 90, 60, 85, 100];
</script>

<template>
    <Head title="Centro de Ayuda" />

    <div class="font-sans text-gray-900 dark:text-gray-100 antialiased bg-gray-50 dark:bg-black min-h-screen flex flex-col">
        
        <!-- NAV MEJORADO (Alineación y Estilos) -->
        <nav class="w-full py-6 px-6 md:px-12 flex justify-between items-center max-w-7xl mx-auto">
            <!-- Izquierda: Logo Solamente (Más grande) -->
            <Link href="/" class="flex items-center group">
                <AuthenticationCardLogo class="w-20 h-20 group-hover:scale-105 transition-transform" />
            </Link>

            <!-- Derecha: Links Alineados -->
            <div class="flex items-center gap-6">
                <Link href="/" class="text-sm font-medium text-gray-500 hover:text-[#F68C0F] dark:hover:text-[#F68C0F] transition-colors whitespace-nowrap">
                    Volver al inicio
                </Link>
                <Link :href="route('login')" class="bg-[#F68C0F] text-white px-6 py-2.5 rounded-full text-sm font-bold hover:bg-orange-600 transition-all shadow-md hover:shadow-lg transform active:scale-95 whitespace-nowrap">
                    Iniciar sesión
                </Link>
            </div>
        </nav>

        <!-- HEADER / BUSCADOR -->
        <div class="pt-8 pb-20 px-6 text-center max-w-4xl mx-auto w-full">
            <h1 class="text-4xl md:text-6xl font-bold tracking-tight text-gray-900 dark:text-white mb-6">
                ¿Cómo podemos ayudarte?
            </h1>
            <div class="relative max-w-2xl mx-auto group">
                <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 text-gray-400 group-focus-within:text-[#F68C0F] transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
                <input 
                    type="text" 
                    placeholder="Buscar temas (ej. 'restablecer contraseña')" 
                    class="w-full pl-14 pr-6 py-5 bg-white dark:bg-gray-900 border-none rounded-2xl shadow-xl shadow-gray-200/50 dark:shadow-none ring-1 ring-gray-200 dark:ring-gray-800 text-lg placeholder-gray-400 focus:ring-2 focus:ring-[#F68C0F] transition-all"
                >
            </div>
        </div>

        <!-- GRID DE TEMAS -->
        <div class="px-6 md:px-12 max-w-7xl mx-auto w-full -mt-8 mb-24 relative z-10">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="(topic, index) in topics" :key="index" 
                     @click="openModal(topic)"
                     class="bg-white dark:bg-gray-900 p-8 rounded-3xl shadow-sm border border-gray-100 dark:border-gray-800 hover:shadow-2xl hover:border-[#F68C0F]/30 hover:-translate-y-1 transition-all duration-300 cursor-pointer group relative overflow-hidden">
                    
                    <!-- Decoración de fondo sutil -->
                    <div class="absolute top-0 right-0 w-24 h-24 bg-gray-50 dark:bg-gray-800 rounded-bl-[100px] -mr-4 -mt-4 transition-all group-hover:scale-150 group-hover:bg-orange-50 dark:group-hover:bg-orange-900/20 z-0"></div>

                    <div class="relative z-10">
                        <div class="text-4xl mb-4 group-hover:scale-110 transition-transform duration-300 inline-block filter drop-shadow-md">{{ topic.icon }}</div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">{{ topic.title }}</h3>
                        <p class="text-gray-500 dark:text-gray-400 leading-relaxed text-sm">{{ topic.desc }}</p>
                        
                        <div class="mt-4 flex items-center text-[#F68C0F] font-semibold text-sm opacity-0 group-hover:opacity-100 transform translate-y-2 group-hover:translate-y-0 transition-all duration-300">
                            Ver más <span class="ml-1">&rarr;</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ SECTION -->
        <div class="bg-white dark:bg-gray-900 py-24 border-t border-gray-100 dark:border-gray-800">
            <div class="max-w-3xl mx-auto px-6">
                <h2 class="text-3xl font-bold text-center mb-12 tracking-tight text-gray-900 dark:text-white">Preguntas frecuentes</h2>
                <div class="space-y-4">
                    <div v-for="(faq, index) in faqs" :key="index" 
                         class="border-b border-gray-100 dark:border-gray-800 last:border-0 pb-4">
                        <button @click="toggleFaq(index)" class="w-full flex justify-between items-center py-4 text-left group">
                            <span class="text-lg font-medium text-gray-900 dark:text-gray-200 group-hover:text-[#F68C0F] transition-colors">
                                {{ faq.question }}
                            </span>
                            <span class="ml-4 flex-shrink-0 w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-800 flex items-center justify-center text-gray-400 group-hover:bg-orange-50 group-hover:text-[#F68C0F] transition-all"
                                  :class="{ 'rotate-180 bg-orange-50 text-[#F68C0F]': faq.open }">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </span>
                        </button>
                        <div v-show="faq.open" class="text-gray-600 dark:text-gray-400 leading-relaxed pb-4 animate-fadeIn">
                            {{ faq.answer }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTACT FORM -->
        <div class="py-24 px-6">
            <div class="max-w-2xl mx-auto bg-gray-100 dark:bg-gray-800 rounded-[40px] p-8 md:p-12 text-center">
                <h2 class="text-3xl font-bold mb-4 text-gray-900 dark:text-white">¿Aún necesitas ayuda?</h2>
                <p class="text-gray-500 dark:text-gray-400 mb-8">Envíanos un mensaje detallado y nuestro equipo te responderá en menos de 24 horas.</p>

                <form @submit.prevent="submitSupport" class="space-y-4 text-left">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 ml-1">Nombre</label>
                            <input v-model="form.name" type="text" required class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 focus:border-[#F68C0F] focus:ring-[#F68C0F] transition-shadow">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 ml-1">Correo electrónico</label>
                            <input v-model="form.email" type="email" required class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 focus:border-[#F68C0F] focus:ring-[#F68C0F] transition-shadow">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 ml-1">Mensaje</label>
                        <textarea v-model="form.message" rows="4" required class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 focus:border-[#F68C0F] focus:ring-[#F68C0F] transition-shadow" placeholder="Describe tu problema..."></textarea>
                    </div>

                    <button :disabled="isSending" type="submit" class="w-full bg-[#F68C0F] hover:bg-orange-600 text-white font-bold py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all transform active:scale-95 disabled:opacity-70 disabled:cursor-not-allowed">
                        <span v-if="!isSending">Enviar Mensaje</span>
                        <span v-else>Enviando...</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- FOOTER SIMPLE -->
        <div class="py-8 text-center text-sm text-gray-400">
            &copy; {{ new Date().getFullYear() }} Ezy Ventas. Todos los derechos reservados.
        </div>

        <!-- --- MODAL FLOTANTE --- -->
        <Teleport to="body">
            <Transition name="modal-fade">
                <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true">
                    
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity" @click="closeModal"></div>

                    <!-- Modal Content -->
                    <div class="relative bg-white dark:bg-gray-900 rounded-[32px] shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto transform transition-all flex flex-col border border-gray-100 dark:border-gray-800">
                        
                        <!-- Header del Modal -->
                        <div class="sticky top-0 z-10 bg-white/90 dark:bg-gray-900/90 backdrop-blur-md border-b border-gray-100 dark:border-gray-800 p-6 flex justify-between items-center">
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
                                <span class="text-3xl">{{ activeTopic?.icon }}</span>
                                {{ activeTopic?.modalTitle }}
                            </h3>
                            <button @click="closeModal" class="p-2 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors group">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-500 group-hover:text-red-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Cuerpo del Modal -->
                        <div class="p-8">
                            
                            <!-- CONTENIDO 1: PASOS (STEPS) -->
                            <div v-if="activeTopic?.modalContent === 'steps'" class="space-y-8">
                                <p class="text-lg text-gray-600 dark:text-gray-300">Sigue estos sencillos pasos para tener tu negocio operando en minutos:</p>
                                
                                <div class="relative pl-4">
                                    <!-- Línea conectora -->
                                    <div class="absolute left-[27px] top-4 bottom-4 w-0.5 bg-gray-200 dark:bg-gray-700"></div>

                                    <!-- Paso 1 -->
                                    <div class="relative pl-16 mb-8 group">
                                        <div class="absolute left-0 top-0 w-14 h-14 bg-white dark:bg-gray-800 border-2 border-[#F68C0F] text-[#F68C0F] rounded-2xl flex items-center justify-center font-bold text-xl shadow-lg z-10 group-hover:bg-[#F68C0F] group-hover:text-white transition-colors duration-300">1</div>
                                        <h4 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Crea tu cuenta</h4>
                                        <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Ingresa tus datos básicos y verifica tu correo electrónico para activar tu perfil de administrador.</p>
                                    </div>

                                    <!-- Paso 2 -->
                                    <div class="relative pl-16 mb-8 group">
                                        <div class="absolute left-0 top-0 w-14 h-14 bg-white dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 text-gray-400 rounded-2xl flex items-center justify-center font-bold text-xl shadow-sm z-10 group-hover:border-[#F68C0F] group-hover:text-[#F68C0F] transition-all duration-300">2</div>
                                        <h4 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Configura tu Negocio</h4>
                                        <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Añade el nombre de tu tienda, logo y dirección. Esto aparecerá en tus tickets de venta.</p>
                                    </div>

                                    <!-- Paso 3 -->
                                    <div class="relative pl-16 mb-8 group">
                                        <div class="absolute left-0 top-0 w-14 h-14 bg-white dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 text-gray-400 rounded-2xl flex items-center justify-center font-bold text-xl shadow-sm z-10 group-hover:border-[#F68C0F] group-hover:text-[#F68C0F] transition-all duration-300">3</div>
                                        <h4 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Sube tus Productos</h4>
                                        <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Usa la carga masiva de Excel o escanea los códigos de barras de tus artículos uno por uno.</p>
                                    </div>

                                    <!-- Paso 4 -->
                                    <div class="relative pl-16 group">
                                        <div class="absolute left-0 top-0 w-14 h-14 bg-green-500 text-white rounded-2xl flex items-center justify-center font-bold text-xl shadow-lg shadow-green-500/30 z-10">✓</div>
                                        <h4 class="text-xl font-bold text-gray-900 dark:text-white mb-1">¡Listo para vender!</h4>
                                        <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Abre tu caja y comienza a registrar ventas. El sistema descontará el inventario automáticamente.</p>
                                    </div>
                                </div>
                            </div>

                             <!-- CONTENIDO 2: FACTURACIÓN (BILLING) -->
                            <div v-else-if="activeTopic?.modalContent === 'billing'" class="space-y-8">
                                <p class="text-lg text-gray-600 dark:text-gray-300 mb-6">Administra tus pagos y facturas en 4 sencillos pasos:</p>

                                <div class="grid grid-cols-1 gap-4">
                                    <!-- Paso 1 -->
                                    <div class="bg-gray-50 dark:bg-gray-800 p-5 rounded-2xl border border-gray-100 dark:border-gray-700 flex items-start gap-4 hover:border-[#F68C0F]/50 transition-colors">
                                        <div class="w-10 h-10 rounded-full bg-orange-100 text-[#F68C0F] flex items-center justify-center shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-900 dark:text-white mb-1">1. Ve a "Suscripción"</h4>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">En tu menú lateral, encontrarás la sección general con los detalles de tu plan actual.</p>
                                        </div>
                                    </div>

                                    <!-- Paso 2 -->
                                    <div class="bg-gray-50 dark:bg-gray-800 p-5 rounded-2xl border border-gray-100 dark:border-gray-700 flex items-start gap-4 hover:border-[#F68C0F]/50 transition-colors">
                                        <div class="w-10 h-10 rounded-full bg-orange-100 text-[#F68C0F] flex items-center justify-center shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-900 dark:text-white mb-1">2. Información Fiscal</h4>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Es necesario subir tu <strong>Constancia de Situación Fiscal</strong> actualizada (PDF) para que podamos emitir tus facturas correctamente.</p>
                                        </div>
                                    </div>

                                    <!-- Paso 3 -->
                                    <div class="bg-gray-50 dark:bg-gray-800 p-5 rounded-2xl border border-gray-100 dark:border-gray-700 flex items-start gap-4 hover:border-[#F68C0F]/50 transition-colors">
                                        <div class="w-10 h-10 rounded-full bg-orange-100 text-[#F68C0F] flex items-center justify-center shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-900 dark:text-white mb-1">3. Historial de Pagos</h4>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">En "Historial de versiones y pagos" verás una tabla con el desglose exacto: método de pago, monto y estado (pagado/pendiente).</p>
                                        </div>
                                    </div>

                                    <!-- Paso 4 -->
                                    <div class="bg-gray-50 dark:bg-gray-800 p-5 rounded-2xl border border-gray-100 dark:border-gray-700 flex items-start gap-4 hover:border-[#F68C0F]/50 transition-colors">
                                        <div class="w-10 h-10 rounded-full bg-orange-100 text-[#F68C0F] flex items-center justify-center shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.876L5.999 12zm0 0h7.5" /></svg>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-900 dark:text-white mb-1">4. Solicitar Factura</h4>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Haz clic en el botón <strong>"Solicitar Factura"</strong> junto a tu último pago. Nosotros la generaremos y te la haremos llegar por correo electrónico.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                             <!-- CONTENIDO 3: HARDWARE (NUEVO DISEÑO) -->
                            <div v-else-if="activeTopic?.modalContent === 'hardware'" class="space-y-6">
                                <p class="text-center text-gray-500 dark:text-gray-400 mb-2">
                                    Conectar tu equipo es más fácil de lo que crees. Elige tu dispositivo:
                                </p>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Tarjeta Impresora -->
                                    <div class="bg-gray-50 dark:bg-gray-800 rounded-3xl p-6 border border-gray-100 dark:border-gray-700 flex flex-col items-center text-center hover:shadow-lg transition-shadow">
                                        <div class="w-16 h-16 rounded-2xl bg-white dark:bg-gray-700 flex items-center justify-center text-3xl shadow-sm mb-4">
                                            🖨️
                                        </div>
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Impresoras Térmicas</h4>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
                                            Requiere descargar e instalar un <strong>plugin especializado</strong> en tu computadora.
                                        </p>
                                        <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="mt-auto inline-flex items-center gap-2 text-sm font-bold text-[#F68C0F] hover:text-orange-600 bg-orange-50 dark:bg-orange-900/20 px-4 py-2 rounded-full transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                                            Solicitar Ayuda Remota
                                        </a>
                                    </div>

                                    <!-- Tarjeta Lector -->
                                    <div class="bg-gray-50 dark:bg-gray-800 rounded-3xl p-6 border border-gray-100 dark:border-gray-700 flex flex-col items-center text-center hover:shadow-lg transition-shadow">
                                        <div class="w-16 h-16 rounded-2xl bg-white dark:bg-gray-700 flex items-center justify-center text-3xl shadow-sm mb-4">
                                            🔫
                                        </div>
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Lectores de Código</h4>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
                                            ¡Plug & Play! Solo conéctalo al puerto USB de tu computadora y el sistema lo detectará <strong>automáticamente</strong>.
                                        </p>
                                        <div class="mt-auto inline-flex items-center gap-2 text-sm font-bold text-green-600 bg-green-50 dark:bg-green-900/20 px-4 py-2 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                            Listo para usar
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800 flex items-start gap-3 mt-4">
                                    <span class="text-xl">🛠️</span>
                                    <p class="text-sm text-blue-800 dark:text-blue-200 m-0 leading-relaxed">
                                        <strong>¿Necesitas instalación asistida?</strong><br/>
                                        Nuestro equipo puede conectarse remotamente a tu equipo (vía AnyDesk o TeamViewer) para configurar tu impresora en minutos. <a href="https://api.whatsapp.com/send?phone=523321705650" target="_blank" class="underline font-bold">Contáctanos aquí</a>.
                                    </p>
                                </div>
                            </div>

                            <!-- CONTENIDO 4: GRÁFICA (GRAPH) -->
                            <div v-else-if="activeTopic?.modalContent === 'graph'" class="flex flex-col items-center">
                                <p class="text-center text-gray-600 dark:text-gray-300 mb-8 max-w-md">
                                    Visualiza tus ventas diarias, semanales o mensuales. Toma decisiones basadas en datos reales.
                                </p>

                                <!-- Contenedor de Gráfica Simulada (CSS Only) -->
                                <div class="w-full bg-gray-50 dark:bg-gray-800 rounded-2xl p-6 border border-gray-100 dark:border-gray-700 relative overflow-hidden h-64 flex items-end justify-between gap-2 md:gap-4 px-8 md:px-16">
                                    
                                    <!-- Barras animadas -->
                                    <div v-for="(val, idx) in chartData" :key="idx" 
                                         class="w-full bg-[#F68C0F] dark:bg-orange-500 rounded-t-lg relative group transition-all duration-500 hover:bg-orange-600 cursor-pointer"
                                         :style="{ height: val + '%' }">
                                        
                                        <!-- Tooltip al hover -->
                                        <div class="absolute -top-10 left-1/2 -translate-x-1/2 bg-black text-white text-xs py-1 px-2 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
                                            ${{ val * 100 }}
                                        </div>
                                    </div>

                                    <!-- Línea base -->
                                    <div class="absolute bottom-0 left-0 w-full h-px bg-gray-300 dark:bg-gray-600"></div>
                                </div>
                                
                                <div class="mt-6 flex gap-4 text-sm text-gray-500">
                                    <div class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full bg-[#F68C0F]"></span> Ventas
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full bg-gray-300 dark:bg-gray-600"></span> Meta
                                    </div>
                                </div>
                            </div>

                            <!-- CONTENIDO 5: TEXTO GENÉRICO -->
                            <div v-else class="prose dark:prose-invert max-w-none">
                                <p class="text-lg leading-relaxed text-gray-600 dark:text-gray-300">
                                    {{ activeTopic?.textBody }}
                                </p>
                                <div class="mt-6 bg-orange-50 dark:bg-orange-900/20 p-4 rounded-xl border border-orange-100 dark:border-orange-800 flex items-start gap-3">
                                    <span class="text-xl">💡</span>
                                    <p class="text-sm text-orange-800 dark:text-orange-200 m-0">
                                        ¿Necesitas ayuda personalizada con este tema? Contacta a soporte mencionando el código: <strong>{{ activeTopic?.id.toUpperCase() }}-HELP</strong>.
                                    </p>
                                </div>
                            </div>

                        </div>

                        <!-- Footer del Modal -->
                        <div class="p-6 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/50 rounded-b-[32px] flex justify-end">
                            <button @click="closeModal" class="px-6 py-2.5 bg-gray-900 dark:bg-white text-white dark:text-black font-semibold rounded-full hover:shadow-lg transition-all active:scale-95">
                                Entendido
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

    </div>
</template>

<style scoped>
/* Animaciones de entrada/salida para el modal */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.3s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.modal-fade-enter-active .transform,
.modal-fade-leave-active .transform {
  transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.modal-fade-enter-from .transform,
.modal-fade-leave-to .transform {
  transform: scale(0.95) translateY(10px);
}

/* Animación simple para el acordeón */
.animate-fadeIn {
    animation: fadeIn 0.3s ease-out forwards;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>