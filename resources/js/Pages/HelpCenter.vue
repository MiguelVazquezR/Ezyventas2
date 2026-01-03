<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import { ref } from 'vue';

// Importamos íconos (opcional, si usas alguna librería, sino emojis/svg inline funcionan bien)

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

// --- DATOS DE CATEGORÍAS (Con contenido detallado para el modal) ---
const topics = [
    { 
        id: 'steps',
        title: 'Primeros Pasos', 
        icon: '🚀', 
        desc: 'Configura tu cuenta y realiza tu primera venta.',
        modalTitle: 'Comienza con el pie derecho',
        modalContent: 'steps' // Identificador para renderizado condicional
    },
    { 
        id: 'billing',
        title: 'Facturación', 
        icon: '💳', 
        desc: 'Gestiona tus pagos, facturas y suscripciones.',
        modalTitle: 'Tu facturación al día',
        modalContent: 'text',
        textBody: 'Accede a tu historial de facturas, cambia tu método de pago o actualiza tu información fiscal desde el panel de control. Todo transparente, sin letras chiquitas.'
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
        modalContent: 'graph' // Identificador para mostrar la gráfica
    },
    { 
        id: 'hardware',
        title: 'Hardware', 
        icon: '🖨️', 
        desc: 'Configuración de impresoras y lectores.',
        modalTitle: 'Conecta tu equipo',
        modalContent: 'text',
        textBody: 'Guías de configuración para impresoras térmicas (Bluetooth/USB), lectores de códigos de barras y cajones de dinero. Compatible con la mayoría de marcas estándar.'
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
    document.body.style.overflow = 'hidden'; // Evitar scroll de fondo
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

// --- DATOS MOCK PARA LA GRÁFICA (Visualización CSS) ---
const chartData = [40, 70, 45, 90, 60, 85, 100];
</script>

<template>
    <Head title="Centro de Ayuda" />

    <div class="font-sans text-gray-900 dark:text-gray-100 antialiased bg-gray-50 dark:bg-black min-h-screen flex flex-col">
        
        <!-- NAV SIMPLIFICADO -->
        <nav class="w-full py-6 px-6 md:px-12 flex justify-between items-center max-w-7xl mx-auto">
            <Link href="/" class="flex items-center gap-2 group">
                <AuthenticationCardLogo class="w-10 h-10 group-hover:scale-105 transition-transform" />
                <span class="font-bold text-xl tracking-tight hidden md:block">Ayuda</span>
            </Link>
            <div class="flex items-center gap-4">
                <Link href="/" class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    Volver al inicio
                </Link>
                <Link :href="route('login')" class="bg-gray-900 dark:bg-white text-white dark:text-black px-5 py-2 rounded-full text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-200 transition-colors">
                    Iniciar sesión
                </Link>
            </div>
        </nav>

        <!-- HEADER / BUSCADOR -->
        <div class="pt-12 pb-24 px-6 text-center max-w-4xl mx-auto w-full">
            <h1 class="text-4xl md:text-6xl font-bold tracking-tight text-gray-900 dark:text-white mb-6">
                ¿Cómo podemos ayudarte?
            </h1>
            <div class="relative max-w-2xl mx-auto group">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 text-gray-400 group-focus-within:text-blue-500 transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
                <input 
                    type="text" 
                    placeholder="Buscar temas (ej. 'restablecer contraseña')" 
                    class="w-full pl-12 pr-4 py-5 bg-white dark:bg-gray-900 border-none rounded-2xl shadow-xl shadow-gray-200/50 dark:shadow-none ring-1 ring-gray-200 dark:ring-gray-800 text-lg placeholder-gray-400 focus:ring-2 focus:ring-blue-500 transition-all"
                >
            </div>
        </div>

        <!-- GRID DE TEMAS -->
        <div class="px-6 md:px-12 max-w-7xl mx-auto w-full -mt-10 mb-24 relative z-10">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="(topic, index) in topics" :key="index" 
                     @click="openModal(topic)"
                     class="bg-white dark:bg-gray-900 p-8 rounded-3xl shadow-sm border border-gray-100 dark:border-gray-800 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 cursor-pointer group relative overflow-hidden">
                    
                    <!-- Decoración de fondo sutil -->
                    <div class="absolute top-0 right-0 w-24 h-24 bg-gray-50 dark:bg-gray-800 rounded-bl-[100px] -mr-4 -mt-4 transition-all group-hover:scale-150 group-hover:bg-blue-50 dark:group-hover:bg-blue-900/20 z-0"></div>

                    <div class="relative z-10">
                        <div class="text-4xl mb-4 group-hover:scale-110 transition-transform duration-300 inline-block">{{ topic.icon }}</div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">{{ topic.title }}</h3>
                        <p class="text-gray-500 dark:text-gray-400 leading-relaxed text-sm">{{ topic.desc }}</p>
                        
                        <div class="mt-4 flex items-center text-blue-600 font-semibold text-sm opacity-0 group-hover:opacity-100 transform translate-y-2 group-hover:translate-y-0 transition-all duration-300">
                            Ver más <span class="ml-1">&rarr;</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ SECTION -->
        <div class="bg-white dark:bg-gray-900 py-24 border-t border-gray-100 dark:border-gray-800">
            <div class="max-w-3xl mx-auto px-6">
                <h2 class="text-3xl font-bold text-center mb-12 tracking-tight">Preguntas frecuentes</h2>
                <div class="space-y-4">
                    <div v-for="(faq, index) in faqs" :key="index" 
                         class="border-b border-gray-100 dark:border-gray-800 last:border-0 pb-4">
                        <button @click="toggleFaq(index)" class="w-full flex justify-between items-center py-4 text-left group">
                            <span class="text-lg font-medium text-gray-900 dark:text-gray-200 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                {{ faq.question }}
                            </span>
                            <span class="ml-4 flex-shrink-0 w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-800 flex items-center justify-center text-gray-400 group-hover:bg-blue-50 group-hover:text-blue-600 transition-all"
                                  :class="{ 'rotate-180 bg-blue-50 text-blue-600': faq.open }">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
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
                <h2 class="text-3xl font-bold mb-4">¿Aún necesitas ayuda?</h2>
                <p class="text-gray-500 dark:text-gray-400 mb-8">Envíanos un mensaje detallado y nuestro equipo te responderá en menos de 24 horas.</p>

                <form @submit.prevent="submitSupport" class="space-y-4 text-left">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 ml-1">Nombre</label>
                            <input v-model="form.name" type="text" required class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 focus:border-blue-500 focus:ring-blue-500 transition-shadow">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 ml-1">Correo electrónico</label>
                            <input v-model="form.email" type="email" required class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 focus:border-blue-500 focus:ring-blue-500 transition-shadow">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 ml-1">Mensaje</label>
                        <textarea v-model="form.message" rows="4" required class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 focus:border-blue-500 focus:ring-blue-500 transition-shadow" placeholder="Describe tu problema..."></textarea>
                    </div>

                    <button :disabled="isSending" type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl shadow-lg shadow-blue-500/30 transition-all transform active:scale-95 disabled:opacity-70 disabled:cursor-not-allowed">
                        <span v-if="!isSending">Enviar Mensaje</span>
                        <span v-else>Enviando...</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- FOOTER SIMPLE -->
        <div class="py-8 text-center text-sm text-gray-400">
            &copy; 2024 Ezy Ventas. Todos los derechos reservados.
        </div>

        <!-- --- MODAL FLOTANTE --- -->
        <Teleport to="body">
            <Transition name="modal-fade">
                <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true">
                    
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity" @click="closeModal"></div>

                    <!-- Modal Content -->
                    <div class="relative bg-white dark:bg-gray-900 rounded-[32px] shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto transform transition-all flex flex-col">
                        
                        <!-- Header del Modal -->
                        <div class="sticky top-0 z-10 bg-white/80 dark:bg-gray-900/80 backdrop-blur-md border-b border-gray-100 dark:border-gray-800 p-6 flex justify-between items-center">
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
                                <span>{{ activeTopic?.icon }}</span>
                                {{ activeTopic?.modalTitle }}
                            </h3>
                            <button @click="closeModal" class="p-2 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </div>

                        <!-- Cuerpo del Modal -->
                        <div class="p-8">
                            
                            <!-- CONTENIDO 1: PASOS (STEPS) -->
                            <div v-if="activeTopic?.modalContent === 'steps'" class="space-y-8">
                                <p class="text-lg text-gray-600 dark:text-gray-300">Sigue estos sencillos pasos para tener tu negocio operando en minutos:</p>
                                
                                <div class="relative">
                                    <!-- Línea conectora -->
                                    <div class="absolute left-4 top-4 bottom-4 w-0.5 bg-gray-200 dark:bg-gray-700"></div>

                                    <!-- Paso 1 -->
                                    <div class="relative pl-12 mb-8 group">
                                        <div class="absolute left-0 top-1 w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold shadow-lg shadow-blue-500/30 z-10">1</div>
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Crea tu cuenta</h4>
                                        <p class="text-gray-500 dark:text-gray-400">Ingresa tus datos básicos y verifica tu correo electrónico para activar tu perfil de administrador.</p>
                                    </div>

                                    <!-- Paso 2 -->
                                    <div class="relative pl-12 mb-8 group">
                                        <div class="absolute left-0 top-1 w-8 h-8 bg-white dark:bg-gray-800 border-2 border-blue-600 text-blue-600 rounded-full flex items-center justify-center font-bold z-10">2</div>
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Configura tu Negocio</h4>
                                        <p class="text-gray-500 dark:text-gray-400">Añade el nombre de tu tienda, logo y dirección. Esto aparecerá en tus tickets de venta.</p>
                                    </div>

                                    <!-- Paso 3 -->
                                    <div class="relative pl-12 mb-8 group">
                                        <div class="absolute left-0 top-1 w-8 h-8 bg-white dark:bg-gray-800 border-2 border-gray-300 dark:border-gray-600 text-gray-500 rounded-full flex items-center justify-center font-bold z-10">3</div>
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Sube tus Productos</h4>
                                        <p class="text-gray-500 dark:text-gray-400">Usa la carga masiva de Excel o escanea los códigos de barras de tus artículos uno por uno.</p>
                                    </div>

                                    <!-- Paso 4 -->
                                    <div class="relative pl-12 group">
                                        <div class="absolute left-0 top-1 w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold shadow-lg shadow-green-500/30 z-10">✓</div>
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-1">¡Listo para vender!</h4>
                                        <p class="text-gray-500 dark:text-gray-400">Abre tu caja y comienza a registrar ventas. El sistema descontará el inventario automáticamente.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- CONTENIDO 2: GRÁFICA (GRAPH) -->
                            <div v-else-if="activeTopic?.modalContent === 'graph'" class="flex flex-col items-center">
                                <p class="text-center text-gray-600 dark:text-gray-300 mb-8 max-w-md">
                                    Visualiza tus ventas diarias, semanales o mensuales. Toma decisiones basadas en datos reales.
                                </p>

                                <!-- Contenedor de Gráfica Simulada (CSS Only) -->
                                <div class="w-full bg-gray-50 dark:bg-gray-800 rounded-2xl p-6 border border-gray-100 dark:border-gray-700 relative overflow-hidden h-64 flex items-end justify-between gap-2 md:gap-4 px-8 md:px-16">
                                    
                                    <!-- Barras animadas -->
                                    <div v-for="(val, idx) in chartData" :key="idx" 
                                         class="w-full bg-blue-500 dark:bg-blue-400 rounded-t-lg relative group transition-all duration-500 hover:bg-blue-600 cursor-pointer"
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
                                        <span class="w-3 h-3 rounded-full bg-blue-500"></span> Ventas
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full bg-gray-300 dark:bg-gray-600"></span> Meta
                                    </div>
                                </div>
                            </div>

                            <!-- CONTENIDO 3: TEXTO GENÉRICO -->
                            <div v-else class="prose dark:prose-invert max-w-none">
                                <p class="text-lg leading-relaxed text-gray-600 dark:text-gray-300">
                                    {{ activeTopic?.textBody }}
                                </p>
                                <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800 flex items-start gap-3">
                                    <span class="text-xl">💡</span>
                                    <p class="text-sm text-blue-800 dark:text-blue-200 m-0">
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