<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    referralCode: { type: Object, default: null },
    activeReferralsCount: { type: Number, default: 0 },
    subscriptionCost: { type: Number, default: 0 },
    referrerActiveDiscountPct: { type: Number, default: 0 },
    settings: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['copy-code', 'generate-code']);

const maxReferrals = 10;
const remainingForFree = computed(() => Math.max(maxReferrals - props.activeReferralsCount, 0));
const isFullyFree = computed(() => props.activeReferralsCount >= maxReferrals);

const progressSegments = Array.from({ length: maxReferrals }, (_, i) => ({
    filled: i < props.activeReferralsCount,
    first: i === 0,
    last: i === maxReferrals - 1,
}));

const currentDiscount = computed(() =>
    (props.subscriptionCost || 0) * (props.referrerActiveDiscountPct / 100)
);

const costAfterDiscount = computed(() =>
    (props.subscriptionCost || 0) - currentDiscount.value
);

const rewardPct = computed(() => props.settings?.referrer_reward_pct || 50);
const discountPct = computed(() => props.settings?.referrer_ongoing_discount_pct || 10);
const referredDiscountPct = computed(() => props.settings?.referred_discount_pct || 15);

// --- Estado para generacion de codigo ---
const generatingCode = ref(false);

// --- Estado para feedback de copia ---
const copyFeedback = ref(false);

function onCopy() {
    if (!props.referralCode?.code) return;
    navigator.clipboard.writeText(props.referralCode.code);
    copyFeedback.value = true;
    setTimeout(() => { copyFeedback.value = false; }, 2000);
    emit('copy-code');
}

async function onGenerate() {
    if (generatingCode.value) return;
    generatingCode.value = true;
    try {
        const res = await fetch(route('referrals.code'));
        const data = await res.json();
        if (data.code) {
            // Recargar la pagina para que Inertia actualice las props
            window.location.reload();
        }
    } catch {
        generatingCode.value = false;
    }
}
</script>

<template>
    <div
        class="relative overflow-hidden rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6 sm:p-8 md:p-10"
        style="background: linear-gradient(135deg, #1a1a1a 0%, #1e0730 25%, #0f1729 50%, #1a1a1a 100%);"
    >
        <!-- Particulas decorativas -->
        <div class="absolute inset-0 pointer-events-none select-none" aria-hidden="true">
            <span class="absolute top-[10%] left-[5%] text-amber-500/20 !text-6xl font-bold">$</span>
            <span class="absolute top-[20%] left-[15%] text-violet-500/20 !text-5xl font-bold">★</span>
            <span class="absolute bottom-[25%] left-[20%] text-amber-500/15 !text-7xl font-bold">$</span>
            <span class="absolute bottom-[10%] left-[8%] text-violet-500/25 !text-5xl font-bold">★</span>
            <span class="absolute top-[60%] left-[40%] text-amber-500/12 text-4xl font-bold">$</span>
            <!-- Icono de regalo decorativo con rotacion -->
            <span class="absolute top-[2%] right-[35%] md:right-[28%] font-bold" style="transform: rotate(-12deg);">
                <i class="pi pi-gift text-violet-500/8 !text-7xl md:!text-8xl"></i>
            </span>
        </div>

        <div class="relative z-10 flex flex-col lg:flex-row gap-8 items-start">
            <!-- LADO IZQUIERDO: TEXTO -->
            <div class="flex-1 min-w-0 space-y-4">
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                    Gana con cada referido
                </p>

                <h2 class="text-3xl sm:text-4xl md:text-6xl font-light tracking-tight text-white m-0 leading-tight">
                    <template v-if="isFullyFree">
                        Tu plan ya es
                        <span class="text-violet-400" style="text-shadow: 0 0 40px rgba(168,85,247,0.4);">GRATIS</span>
                        !
                    </template>
                    <template v-else>
                        Refiere y tu plan
                        <br class="hidden sm:block" />
                        puede salir
                        <span class="text-violet-400" style="text-shadow: 0 0 40px rgba(168,85,247,0.4);">GRATIS</span>
                    </template>
                </h2>

                <p class="text-sm sm:text-base text-gray-300 leading-relaxed m-0 max-w-lg">
                    Cada referido activo te da
                    <strong class="text-violet-400">{{ discountPct }}% de descuento</strong>
                    permanente en tu suscripcion mensual,
                    <strong class="text-white">sin tope</strong>.
                    <template v-if="!isFullyFree">
                        Con <strong class="text-amber-400">{{ remainingForFree }}</strong> mas,
                        tu plan sale gratis!
                    </template>
                    Ademas, recibe
                    <strong class="text-amber-400">el {{ rewardPct }}% de la primera mensualidad</strong>
                    de cada amigo como bonificacion en efectivo.
                </p>

                <!-- Pasos: como funciona -->
                <div class="flex flex-wrap gap-4 md:gap-6">
                    <div class="flex items-start gap-2.5">
                        <div class="w-7 h-7 rounded-full bg-blue-500/15 flex items-center justify-center flex-shrink-0 border border-blue-500/20">
                            <span class="text-[11px] font-bold text-blue-400">1</span>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-200 m-0">Comparte tu codigo</p>
                            <p class="text-[11px] text-gray-400 m-0 mt-0.5 leading-relaxed">Envialo a otros negocios que quieran usar EzyVentas.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <div class="w-7 h-7 rounded-full bg-green-500/15 flex items-center justify-center flex-shrink-0 border border-green-500/20">
                            <span class="text-[11px] font-bold text-green-400">2</span>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-200 m-0">Beneficio para tu amigo</p>
                            <p class="text-[11px] text-gray-400 m-0 mt-0.5 leading-relaxed">Obtiene {{ referredDiscountPct }}% de descuento en su primer pago.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <div class="w-7 h-7 rounded-full bg-amber-500/15 flex items-center justify-center flex-shrink-0 border border-amber-500/20">
                            <span class="text-[11px] font-bold text-amber-400">3</span>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-200 m-0">Tu premio</p>
                            <p class="text-[11px] text-gray-400 m-0 mt-0.5 leading-relaxed">Recibes {{ rewardPct }}% de una mensualidad + {{ discountPct }}% descuento continuo.</p>
                        </div>
                    </div>
                </div>

                <!-- Barra de progreso -->
                <div class="max-w-md space-y-2">
                    <div class="flex items-center justify-between text-[10px]">
                        <span class="uppercase tracking-widest font-bold text-gray-400">
                            {{ isFullyFree ? 'Plan gratis alcanzado!' : 'Progreso hacia plan gratis' }}
                        </span>
                        <span class="font-mono font-bold" :class="isFullyFree ? 'text-violet-400' : 'text-gray-400'">
                            {{ activeReferralsCount }}/{{ maxReferrals }}
                        </span>
                    </div>
                    <div class="flex gap-1">
                        <div
                            v-for="(seg, i) in progressSegments"
                            :key="i"
                            class="h-2 flex-1 transition-colors duration-500"
                            :class="[
                                seg.filled ? 'bg-violet-500' : 'bg-[#3a3a3a]',
                                seg.first ? 'rounded-l-full' : '',
                                seg.last ? 'rounded-r-full' : '',
                            ]"
                        />
                    </div>
                    <p v-if="!isFullyFree" class="text-[10px] text-gray-500 m-0 mt-1">
                        Te faltan <strong class="text-amber-400">{{ remainingForFree }}</strong> referidos activos para el plan gratis
                    </p>
                </div>
            </div>

            <!-- LADO DERECHO: CARDS CTA -->
            <div class="w-full lg:w-80 flex-shrink-0 space-y-4">
                <!-- Card: premio en efectivo -->
                <div class="bg-[#232323] rounded-2xl border border-[#3a3a3a] p-5 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75" />
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500" />
                        </span>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Bonificacion en efectivo</p>
                    </div>

                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl font-light tracking-tight text-amber-400">{{ rewardPct }}%</span>
                        <span class="text-sm text-gray-400">de una mensualidad</span>
                    </div>

                    <p class="text-xs text-gray-500 m-0 leading-relaxed">
                        Transferimos a tu cuenta bancaria tan pronto se valide y apruebe el pago de tu referido.
                    </p>

                    <div class="flex items-center gap-2 text-xs text-gray-400 pt-1">
                        <i class="pi pi-wallet !text-xs"></i>
                        <span class="m-0">Directo a tu banco</span>
                    </div>
                </div>

                <!-- Card: descuento continuo -->
                <div class="bg-[#232323] rounded-2xl border border-[#3a3a3a] p-5 space-y-3">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Descuento en tu plan</p>

                    <div class="flex items-baseline gap-1">
                        <span class="text-4xl font-light tracking-tight text-violet-400">{{ discountPct }}%</span>
                        <span class="text-sm text-gray-400">por referido activo</span>
                    </div>

                    <div v-if="subscriptionCost > 0" class="bg-[#1a1a1a] rounded-xl p-3 space-y-1">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-400">Costo mensual</span>
                            <span class="text-gray-300 font-mono">${{ subscriptionCost.toFixed(2) }}</span>
                        </div>
                        <div v-if="referrerActiveDiscountPct > 0" class="flex justify-between text-xs">
                            <span class="text-violet-400">Descuento ({{ referrerActiveDiscountPct }}%)</span>
                            <span class="text-violet-400 font-mono">- ${{ currentDiscount.toFixed(2) }}</span>
                        </div>
                        <Divider class="!my-1 !border-[#2a2a2a]" />
                        <div class="flex justify-between text-sm">
                            <span class="text-white font-medium">Total</span>
                            <span class="text-white font-mono font-bold">${{ costAfterDiscount.toFixed(2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Boton de codigo -->
                <div v-if="referralCode" class="space-y-2">
                    <div class="relative">
                        <Button
                            @click="onCopy"
                            icon="pi pi-copy"
                            :label="copyFeedback ? 'Copiado!' : 'Copiar mi codigo'"
                            :class="[
                                'w-full !rounded-full !text-white !font-semibold transition-all duration-300',
                                copyFeedback
                                    ? '!bg-green-600 !border-green-600'
                                    : '!bg-violet-600 !border-violet-600 hover:!bg-violet-500'
                            ]"
                        />
                        <!-- Feedback flotante que aparece y desaparece -->
                        <transition name="fade">
                            <div v-if="copyFeedback"
                                class="absolute -top-10 left-1/2 -translate-x-1/2 bg-green-600 text-white text-xs rounded-xl px-3 py-1.5 whitespace-nowrap shadow-lg z-20">
                                <i class="pi pi-check mr-1 !text-xs"></i>
                                Codigo copiado al portapapeles
                            </div>
                        </transition>
                    </div>
                    <p class="text-center text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        {{ referralCode.code }}
                    </p>
                </div>
                <Button
                    v-else
                    @click="onGenerate"
                    :loading="generatingCode"
                    icon="pi pi-ticket"
                    label="Generar mi codigo"
                    class="w-full !rounded-full !bg-violet-600 !border-violet-600 hover:!bg-violet-500 !text-white !font-semibold"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active {
    transition: opacity 0.3s ease, transform 0.3s ease;
}
.fade-enter-from, .fade-leave-to {
    opacity: 0;
    transform: translateX(-50%) translateY(8px);
}
.fade-enter-to, .fade-leave-from {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
</style>
