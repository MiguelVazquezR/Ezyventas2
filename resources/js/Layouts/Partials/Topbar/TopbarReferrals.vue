<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const referralNotifications = computed(() => page.props.referralNotifications);

const referralBadgeCount = computed(() => {
    if (!referralNotifications.value) return 0;
    return (referralNotifications.value.pending_rewards_count ?? 0) +
           (referralNotifications.value.unseen_referrals_count ?? 0);
});
</script>

<template>
    <button type="button" class="relative flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors" v-tooltip.bottom="'Mis referidos'" @click="$inertia.visit(route('referrals.index'))">
        <!-- Anillo de pulso sutil detrás del ícono -->
        <span v-if="referralBadgeCount > 0" class="absolute inset-0 rounded-full bg-amber-500/20 animate-ping z-0"></span>
        <i class="pi pi-users !text-xl text-gray-400 relative z-10" :class="{'!text-amber-500': referralBadgeCount > 0}"></i>
        
        <!-- Badge minimalista (Telemetría) -->
        <!-- <span v-if="referralBadgeCount > 0" class="absolute top-1 right-1 flex h-3 w-3 z-20">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500 text-[8px] font-bold text-white items-center justify-center">{{ referralBadgeCount }}</span>
        </span> -->
    </button>
</template>
