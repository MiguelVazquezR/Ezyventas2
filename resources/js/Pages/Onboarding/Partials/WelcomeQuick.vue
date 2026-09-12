<script setup>
import { useForm } from '@inertiajs/vue3';

defineProps({
    subscription: Object,
    userName: String,
});

const emit = defineEmits(['start-wizard']);

// --- Sin campos: entra directo con los valores creados en el registro ---
const form = useForm({});

const submit = () => {
    form.post(route('onboarding.skip'), {
        preserveScroll: true,
    });
};

// --- Módulos incluidos por defecto en el plan esencial ---
const readyFeatures = [
    { icon: 'pi pi-shopping-cart', title: 'Punto de venta', text: 'Cobra en efectivo, tarjeta o a crédito' },
    { icon: 'pi pi-history', title: 'Historial de ventas', text: 'Todas tus ventas guardadas y fáciles de consultar' },
    { icon: 'pi pi-box', title: 'Control de inventario', text: 'Productos, códigos de barras y stock por sucursal' },
    { icon: 'pi pi-money-bill', title: 'Gastos', text: 'Registra y controla los egresos del negocio' },
    { icon: 'pi pi-inbox', title: 'Control de caja', text: 'Abre, cierra y concilia tu caja registradora' },
];
</script>

<template>
    <div class="w-full max-w-3xl bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] shadow-2xl overflow-hidden">

        <!-- Header -->
        <div class="relative px-6 pt-6 pb-5 text-center bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] overflow-hidden">
            <!-- Decoración sutil de fondo -->
            <div class="pointer-events-none absolute -top-16 -left-16 w-40 h-40 rounded-full bg-[#f68c0f]/10 blur-3xl"></div>
            <div class="pointer-events-none absolute -top-16 -right-16 w-40 h-40 rounded-full bg-[#f68c0f]/10 blur-3xl"></div>

            <div class="relative flex justify-center mb-4">
                <img src="@/../../public/images/black_logo.png" alt="Logo Ezyventas"
                    class="h-14 w-auto object-contain select-none dark:hidden" />
                <img src="@/../../public/images/white_logo.png" alt="Logo Ezyventas"
                    class="h-14 w-auto object-contain select-none hidden dark:block" />
            </div>

            <div class="relative ez-pop">
                <h1 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    ¡Bienvenido, {{ userName }}!
                </h1>
                <p class="mt-1 text-[12px] text-gray-500 dark:text-gray-400 m-0">
                    Tu negocio está listo. Empieza a vender ahora y configura los detalles cuando quieras.
                </p>
            </div>
        </div>

        <div class="p-6 space-y-4">

            <!-- Banner tipo regalo: 30 días gratis (verde suave para resaltar el CTA naranja) -->
            <div class="relative overflow-hidden rounded-2xl ez-pop"
                style="background: linear-gradient(120deg, #bbf7d0 0%, #d1fae5 55%, #ecfdf5 100%);">
                <!-- Destellos decorativos -->
                <div class="absolute -top-10 -right-10 w-32 h-32 rounded-full bg-white/60 blur-2xl pointer-events-none"></div>
                <i class="pi pi-star-fill absolute top-3 right-7 !text-[10px] text-[#059669]/25 ez-twinkle pointer-events-none"></i>
                <i class="pi pi-star-fill absolute bottom-3 right-14 !text-[8px] text-[#059669]/20 ez-twinkle ez-twinkle-delay pointer-events-none"></i>

                <div class="relative flex items-center gap-3.5 p-4">
                    <div class="relative w-11 h-11 rounded-full bg-[#10b981]/15 border border-[#10b981]/25 flex items-center justify-center flex-shrink-0 ez-float">
                        <div class="absolute inset-0 rounded-full border-2 border-dashed border-[#10b981]/30 animate-spin pointer-events-none" style="animation-duration: 14s;"></div>
                        <i class="pi pi-gift !text-xl text-[#047857]"></i>
                    </div>
                    <div class="min-w-0 flex-1 text-left">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[8.5px] font-bold uppercase tracking-widest bg-white/70 text-[#065f46] mb-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#10b981] animate-pulse"></span>
                            Regalo de bienvenida
                        </span>
                        <p class="text-base font-bold tracking-tight text-[#022c22] m-0 leading-tight">
                            ¡Tienes 30 días gratis!
                        </p>
                        <p class="text-[11px] font-medium text-[#065f46]/75 m-0 mt-0.5 leading-relaxed">
                            Prueba todas las herramientas desde el primer día. Al finalizar mantienes tu plan esencial y solo eliges los módulos que necesitas.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Módulos del plan esencial -->
            <div class="flex items-center justify-center">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-bold uppercase tracking-widest bg-green-500/10 text-green-600 dark:text-green-400">
                    <i class="pi pi-check-circle !text-[10px]"></i>
                    Incluido en tu plan esencial
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div v-for="(feature, index) in readyFeatures" :key="feature.title"
                    class="flex items-center gap-3 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-xl px-3.5 py-2.5 ez-pop transition-colors hover:border-primary-500/50"
                    :class="index === 1 ? 'ez-pop-delay-1' : index === 2 ? 'ez-pop-delay-2' : index === 3 ? 'ez-pop-delay-3' : index === 4 ? 'ez-pop-delay-4' : ''">
                    <div class="w-8 h-8 rounded-lg bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i :class="[feature.icon, '!text-sm text-primary-500']"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[12px] font-semibold text-gray-900 dark:text-white m-0 leading-tight">{{ feature.title }}</p>
                        <p class="text-[11px] leading-snug text-gray-500 dark:text-gray-400 m-0 mt-0.5">{{ feature.text }}</p>
                    </div>
                    <i class="pi pi-check-circle !text-[12px] text-green-500 flex-shrink-0"></i>
                </div>
            </div>

            <!-- CTA principal con animación -->
            <div class="ez-pop">
                <Button type="button" :loading="form.processing" @click="submit"
                    class="ez-glow w-full !rounded-full !border-0 !bg-gradient-to-r !from-[#f68c0f] !via-[#ffa31a] !to-[#ffc24d] !text-[#1A1A1A] font-bold !py-3 transition-all duration-200 hover:!brightness-110">
                    <span class="flex items-center justify-center gap-2.5">
                        <span>Ir a mi negocio</span>
                        <i class="pi pi-arrow-right !text-sm ez-nudge"></i>
                    </span>
                </Button>
            </div>

            <!-- Configuración avanzada (opcional) -->
            <div class="pt-2 text-center border-t border-gray-100 dark:border-[#3a3a3a]">
                <p class="text-[12px] text-gray-500 dark:text-gray-500 m-0">
                    ¿Quieres activar más módulos, agregar sucursales u horarios?
                </p>
                <button type="button" @click="emit('start-wizard')"
                    class="mt-1.5 inline-flex items-center gap-1.5 bg-transparent border-none p-0 m-0 cursor-pointer text-[14px] font-semibold text-primary-500 hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 transition-colors underline underline-offset-4 decoration-dotted decoration-1 group">
                    Configurar mi negocio ahora
                    <i class="pi pi-chevron-right !text-xs transition-transform duration-200 group-hover:translate-x-0.5"></i>
                </button>
            </div>
        </div>
    </div>
</template>

<style>
/* Animaciones de la pantalla de bienvenida */
@keyframes ez-glow {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(246, 140, 15, 0.45), 0 12px 32px -12px rgba(246, 140, 15, 0.6);
    }
    50% {
        box-shadow: 0 0 0 9px rgba(246, 140, 15, 0), 0 16px 42px -10px rgba(246, 140, 15, 0.85);
    }
}

.ez-glow {
    animation: ez-glow 2.2s ease-in-out infinite;
}

@keyframes ez-nudge {
    0%, 100% { transform: translateX(0); }
    50% { transform: translateX(5px); }
}

.ez-nudge {
    display: inline-block;
    animation: ez-nudge 1.3s ease-in-out infinite;
}

@keyframes ez-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}

.ez-float {
    animation: ez-float 3.5s ease-in-out infinite;
}

@keyframes ez-twinkle {
    0%, 100% { opacity: 0.15; transform: scale(0.8) rotate(0deg); }
    50% { opacity: 0.9; transform: scale(1.15) rotate(20deg); }
}

.ez-twinkle {
    animation: ez-twinkle 2.6s ease-in-out infinite;
}

.ez-twinkle-delay { animation-delay: 0.6s; }
.ez-twinkle-delay-2 { animation-delay: 1.1s; }

@keyframes ez-pop {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.ez-pop {
    animation: ez-pop 0.45s ease-out both;
}

.ez-pop-delay-1 { animation-delay: 0.08s; }
.ez-pop-delay-2 { animation-delay: 0.16s; }
.ez-pop-delay-3 { animation-delay: 0.24s; }
.ez-pop-delay-4 { animation-delay: 0.32s; }
</style>
