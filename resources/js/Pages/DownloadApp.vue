<script setup>
import { Head, Link } from '@inertiajs/vue3';

const whatsappUrl = 'https://api.whatsapp.com/send?phone=523321705650&text=' +
    encodeURIComponent('Hola, quiero que me avisen cuando esté disponible la app de Ezy Ventas para Android.');
</script>

<template>
    <Head title="App móvil Android" />

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
                    En desarrollo
                </span>

                <i class="pi pi-android !text-5xl text-[#F68C0F] block"></i>

                <h1 class="text-3xl md:text-5xl font-bold tracking-tight m-0">La app para Android está en camino</h1>

                <p class="text-gray-400 text-lg leading-relaxed max-w-xl mx-auto">
                    Estamos afinando los últimos detalles para que puedas descargar Ezy Ventas directamente en tu celular Android.
                </p>

                <div class="bg-white/5 border border-white/10 rounded-3xl p-6 md:p-8 text-left space-y-4 max-w-xl mx-auto">
                    <p class="text-white font-bold m-0">Mientras tanto, puedes seguir vendiendo</p>
                    <ul class="space-y-3 list-none">
                        <li class="flex items-start gap-3 text-gray-300">
                            <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] flex items-center justify-center shrink-0 mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                            </span>
                            Tu cuenta y todos tus datos ya funcionan desde el navegador de tu celular.
                        </li>
                        <li class="flex items-start gap-3 text-gray-300">
                            <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] flex items-center justify-center shrink-0 mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                            </span>
                            No necesitas instalar nada para comenzar a vender hoy mismo.
                        </li>
                        <li class="flex items-start gap-3 text-gray-300">
                            <span class="w-6 h-6 rounded-full bg-[#F68C0F]/15 text-[#F68C0F] flex items-center justify-center shrink-0 mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                            </span>
                            Te avisamos por WhatsApp en cuanto la descarga esté disponible.
                        </li>
                    </ul>
                </div>
                <!-- Acciones -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center pt-2">
                    <a :href="whatsappUrl" target="_blank" rel="noopener noreferrer" class="w-full sm:w-auto px-8 py-4 rounded-full bg-[#F68C0F] text-white font-bold shadow-[0_15px_35px_-10px_rgba(246,140,15,0.6)] hover:bg-[#ff9e3d] hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2">
                        Avisarme por WhatsApp
                        <i class="pi pi-whatsapp !text-sm"></i>
                    </a>
                    <Link :href="route('register')" class="w-full sm:w-auto px-8 py-4 rounded-full bg-white text-black font-bold hover:bg-gray-100 hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2">
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
