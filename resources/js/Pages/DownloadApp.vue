<script setup>
import { Head, Link } from '@inertiajs/vue3';

// The release APK lives in `public/downloads` and is served as a static file.
// Keep these values in sync with the build that was copied to that folder.
const apkUrl = '/downloads/ezyventas-android.apk';
const apkDownloadName = 'EzyVentas-Android-0.1.0.apk';
const appVersion = '0.1.0';
const appSize = '78 MB';
const minAndroid = 'Android 6.0 o superior';

const whatsappUrl = 'https://api.whatsapp.com/send?phone=523321705650&text=' +
    encodeURIComponent('Hola, necesito ayuda para instalar la app de Ezy Ventas en Android.');

const steps = [
    {
        title: 'Descarga el archivo APK',
        description: 'Toca el botón de descarga y espera a que termine. El archivo ocupa ' + appSize + '.',
    },
    {
        title: 'Permite la instalación',
        description: 'Android mostrará un aviso. Elige "Configuración" y activa "Permitir desde esta fuente".',
    },
    {
        title: 'Abre la app e inicia sesión',
        description: 'Usa el mismo correo y contraseña de tu cuenta de Ezy Ventas.',
    },
];
</script>

<template>
    <Head title="Descargar app para Android" />

    <div class="font-krub min-h-screen bg-[#050505] text-white flex flex-col selection:bg-[#F68C0F] selection:text-white">
        <!-- Encabezado con logo -->
        <header class="w-full px-6 py-6 md:px-12 md:py-8">
            <a href="/" class="inline-flex items-center">
                <img src="/images/white_logo.png" alt="Ezy Ventas" class="h-10 md:h-12 w-auto object-contain">
            </a>
        </header>

        <main class="flex-1 relative overflow-hidden px-6 py-10 md:px-12 md:py-16 flex items-center">
            <div class="absolute -top-40 -left-32 w-[420px] h-[420px] bg-[#F68C0F] opacity-[0.12] blur-[130px] rounded-full pointer-events-none"></div>
            <div class="absolute -bottom-48 -right-24 w-[460px] h-[460px] bg-[#F68C0F] opacity-[0.08] blur-[140px] rounded-full pointer-events-none"></div>

            <div class="relative z-10 w-full max-w-3xl mx-auto text-center space-y-6">
                <span class="inline-flex items-center gap-2 bg-white/5 border border-white/10 px-4 py-2 rounded-full text-[11px] font-bold uppercase tracking-widest">
                    <span class="w-2 h-2 rounded-full bg-[#F68C0F] animate-pulse"></span>
                    Disponible para Android
                </span>

                <i class="pi pi-android !text-5xl text-[#F68C0F] block"></i>

                <h1 class="text-3xl md:text-5xl font-bold tracking-tight m-0">Descarga Ezy Ventas para Android</h1>

                <p class="text-gray-400 text-lg leading-relaxed max-w-xl mx-auto">
                    Lleva tu punto de venta al celular: cobra, consulta existencias y revisa tus cortes de caja donde estés.
                </p>

                <!-- Botón de descarga del APK -->
                <div class="flex flex-col items-center gap-3 pt-2">
                    <a :href="apkUrl" :download="apkDownloadName"
                        class="w-full sm:w-auto px-10 py-4 rounded-full bg-[#F68C0F] text-white font-bold text-lg shadow-[0_15px_35px_-10px_rgba(246,140,15,0.6)] hover:bg-[#ff9e3d] hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-3">
                        Descargar APK
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v13.5m0 0 5.25-5.25M12 16.5 6.75 11.25M4.5 20.25h15" />
                        </svg>
                    </a>

                    <p class="text-xs text-gray-500 m-0">
                        Versión {{ appVersion }} &middot; {{ appSize }} &middot; {{ minAndroid }}
                    </p>
                </div>

                <!-- Pasos de instalación -->
                <div class="bg-white/5 border border-white/10 rounded-3xl p-6 md:p-8 text-left space-y-5 max-w-xl mx-auto">
                    <p class="text-white font-bold m-0">Cómo instalarla</p>
                    <ul class="space-y-4 list-none">
                        <li v-for="(step, index) in steps" :key="step.title" class="flex items-start gap-3">
                            <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] text-xs font-bold flex items-center justify-center shrink-0 mt-0.5">
                                {{ index + 1 }}
                            </span>
                            <div>
                                <p class="text-white text-sm font-semibold m-0">{{ step.title }}</p>
                                <p class="text-gray-400 text-sm m-0">{{ step.description }}</p>
                            </div>
                        </li>
                    </ul>
                    <p class="text-[11px] text-gray-500 m-0 pt-3 border-t border-white/10">
                        La app se instala directo desde el APK, sin pasar por Google Play. Muy pronto también estará en la tienda.
                    </p>
                </div>

                <!-- Acciones secundarias -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center pt-2">
                    <a :href="whatsappUrl" target="_blank" rel="noopener noreferrer" class="w-full sm:w-auto px-8 py-4 rounded-full bg-white text-black font-bold hover:bg-gray-100 hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2">
                        Ayuda para instalar
                        <i class="pi pi-whatsapp !text-sm"></i>
                    </a>
                    <Link :href="route('register')" class="w-full sm:w-auto px-8 py-4 rounded-full bg-white/5 border border-white/10 text-white font-bold hover:bg-white/10 hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2">
                        Comenzar gratis
                    </Link>
                </div>

                <a href="/" class="inline-block text-sm text-gray-500 hover:text-white transition-colors pt-2">&larr; Volver al inicio</a>
            </div>
        </main>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Krub:wght@200;300;400;500;600;700&display=swap');
.font-krub { font-family: 'Krub', sans-serif; }
</style>
